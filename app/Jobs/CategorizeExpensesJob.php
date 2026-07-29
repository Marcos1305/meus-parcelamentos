<?php

namespace App\Jobs;

use App\Ai\Agents\CategorizeExpenses;
use App\Enums\ExpenseCategory;
use App\Models\CashPurchase;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;

/**
 * O job varre tudo que esta sem categoria em vez de receber a lista de ids da
 * importacao. Assim a importacao e o botao de reprocessar despacham o mesmo
 * job, e uma classificacao que falhou numa fatura e retomada na proxima em vez
 * de ficar orfa.
 */
class CategorizeExpensesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Lote grande o bastante para nao pagar a latencia do modelo por item, e
     * pequeno o bastante para caber com folga na janela de contexto de um
     * modelo local de 8B.
     */
    private const BATCH = 40;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * Tempo maximo de um lote de prompt() ao provedor, em segundos. Medido
     * contra o Ollama real: 10 itens com o modelo frio (subindo os 9,6GB na
     * memoria) levaram 90,8s; extrapolando o custo por item dos lotes quentes
     * (20 itens = 23,7s, 40 itens = 28,0s, ou seja ~19s fixos + ~0,22s/item),
     * 10 itens quentes ficariam perto de 21,5s — o que isola o custo de
     * carregar o modelo em ~69s. Pior caso de um lote cheio (BATCH=40) frio:
     * ~69s de carga + ~28s de geracao ~= 97s. Dobrado como margem de
     * seguranca contra variacao de hardware/rede: ~194s, arredondado para
     * 200s. Sem isso o cliente HTTP do gateway Ollama cai no padrao de 60s e
     * falha em qualquer partida a frio, que e a normalidade (o Ollama
     * descarrega o modelo depois de ocioso, e importar fatura e mensal).
     */
    private const PROMPT_TIMEOUT_SECONDS = 200;

    /**
     * Teto do job inteiro (todos os lotes de handle(), nao so um prompt()).
     * No caminho normal so o primeiro lote de uma varredura fica frio (os
     * demais rodam em sequencia com o modelo ja quente), entao o total fica
     * bem abaixo disto. Mas o pior caso pessimista — o keep_alive do Ollama
     * expirando entre lotes e cada um deles caindo frio de novo — cobre ate
     * sete lotes (154 compras a vista / 40 = 4, mais 83 parceladas / 40 = 3)
     * a ~100s cada (arredondando o pior caso de PROMPT_TIMEOUT_SECONDS acima
     * pra baixo do teto de seguranca) = ~700s, que ja estourava o teto antigo
     * de 600s. 900s da folga sobre esse pior caso pessimista.
     */
    public int $timeout = 900;

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    /**
     * O prazo existe para o bloqueio se curar sozinho se o worker morrer
     * (OOM, deploy, etc.) antes de liberar o lock: sem prazo, o cache trata
     * zero como "nunca expira", e a categorizacao daquele usuario ficaria
     * travada para sempre, sem erro nem log. 900 do timeout do job mais
     * folga: precisa ser maior que $timeout, senao o bloqueio expiraria
     * antes do proprio job, permitindo um segundo despacho enquanto o
     * primeiro ainda roda.
     *
     * Precisa ser metodo, nao propriedade: o framework le o bloqueio via
     * `method_exists($job, 'uniqueFor')`, e uma propriedade publica do mesmo
     * nome e ignorada em silencio, mantendo o prazo em zero.
     */
    public function uniqueFor(): int
    {
        return 960;
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        CashPurchase::query()
            ->forUser($user)
            ->whereNull('category')
            ->chunkById(self::BATCH, $this->categorize(...));

        Purchase::query()
            ->forUser($user)
            ->whereNull('category')
            ->chunkById(self::BATCH, $this->categorize(...));
    }

    /**
     * O identificador enviado ao modelo e a posicao no lote, nao o id do banco:
     * numero pequeno gasta menos token e nao vaza chave interna no prompt.
     *
     * O metodo e generico porque atende as duas varreduras de handle() com o
     * mesmo callback. `Collection<int, Model>` nao serviria: o generico do
     * Eloquent e invariante (chunkById() de um Builder<CashPurchase> exige
     * exatamente Collection<int, CashPurchase>, nao um supertipo como Model),
     * e Model tambem nao garante `description` nem `category_is_manual`, as
     * duas propriedades que o metodo le. O @template amarra TModel ao tipo
     * concreto de cada ponto de chamada, que e sempre um dos dois.
     *
     * @template TModel of CashPurchase|Purchase
     *
     * @param  Collection<int, TModel>  $rows
     */
    private function categorize(Collection $rows): void
    {
        $indexed = $rows->values();

        $list = $indexed
            ->map(fn (CashPurchase|Purchase $row, int $index): string => ($index + 1).'. '.$row->description)
            ->implode("\n");

        $response = new CategorizeExpenses()->prompt($list, timeout: self::PROMPT_TIMEOUT_SECONDS);

        /**
         * `prompt()` declara devolver AgentResponse, que nao e acessivel como
         * array. Na pratica, um agente com HasStructuredOutput sempre volta
         * StructuredAgentResponse (ver GeneratesText::prompt() no pacote),
         * que estende AgentResponse e implementa ArrayAccess — mas isso nao
         * esta no tipo declarado. A checagem aqui e o que torna o acesso por
         * indice seguro de verdade, nao um jeito de calar o phpstan.
         *
         * Chegar aqui significa que CategorizeExpenses parou de implementar
         * HasStructuredOutput (configuracao/codigo trocado), nao que o modelo
         * respondeu errado — o schema estruturado e quem garante a forma da
         * resposta, entao um retorno nao-estruturado nunca vem do provedor,
         * so de uma mudanca no agente. Lanca em vez de sair em silencio: o
         * lote inteiro ja seria abandonado de um jeito ou de outro (nao ha
         * item para reconciliar sem a lista de items), entao nao ha escrita
         * parcial para proteger com um retorno mudo. O job tem tries/backoff
         * e cai em failed_jobs se as tentativas se esgotarem — um `return`
         * aqui faria a fila registrar sucesso a cada rodada enquanto o lote
         * nunca ganha categoria, sem log nem sintoma.
         */
        if (! $response instanceof StructuredAgentResponse) {
            throw new LogicException(sprintf(
                'CategorizeExpenses deveria devolver StructuredAgentResponse (agente com HasStructuredOutput), mas devolveu %s para o usuario %d.',
                $response::class,
                $this->userId,
            ));
        }

        foreach ($response['items'] ?? [] as $item) {
            $row = $indexed->get(((int) ($item['id'] ?? 0)) - 1);
            $category = ExpenseCategory::tryFrom((string) ($item['category'] ?? ''));

            if ($row === null || $category === null || $row->category_is_manual) {
                continue;
            }

            $row->update(['category' => $category]);
        }
    }
}
