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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

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
     * Um modelo local classificando 40 descricoes leva bem mais que o padrao
     * de 60 segundos.
     */
    public int $timeout = 600;

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    /**
     * O prazo existe para o bloqueio se curar sozinho se o worker morrer
     * (OOM, deploy, etc.) antes de liberar o lock: sem prazo, o cache trata
     * zero como "nunca expira", e a categorizacao daquele usuario ficaria
     * travada para sempre, sem erro nem log. 600 do timeout mais folga.
     *
     * Precisa ser metodo, nao propriedade: o framework le o bloqueio via
     * `method_exists($job, 'uniqueFor')`, e uma propriedade publica do mesmo
     * nome e ignorada em silencio, mantendo o prazo em zero.
     */
    public function uniqueFor(): int
    {
        return 660;
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
     * @param  Collection<int, Model>  $rows
     */
    private function categorize(Collection $rows): void
    {
        $indexed = $rows->values();

        $list = $indexed
            ->map(fn (Model $row, int $index): string => ($index + 1).'. '.$row->description)
            ->implode("\n");

        $response = new CategorizeExpenses()->prompt($list);

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
