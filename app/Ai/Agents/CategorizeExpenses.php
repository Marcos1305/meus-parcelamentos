<?php

namespace App\Ai\Agents;

use App\Enums\ExpenseCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Classifica descricoes de fatura de cartao. O agente e a costura entre o app
 * e o provedor de IA: nem o job nem a tela sabem quem classificou, e trocar
 * Ollama por Anthropic e mudanca de configuracao, nao de codigo.
 */
class CategorizeExpenses implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $taxonomy = collect(ExpenseCategory::cases())
            ->map(fn (ExpenseCategory $category): string => "- {$category->value}: {$category->hint()}")
            ->implode("\n");

        return <<<INSTRUCTIONS
        Você classifica compras de fatura de cartão de crédito brasileira.

        Receberá uma lista numerada de descrições como aparecem na fatura. Elas
        são curtas, abreviadas e frequentemente trazem prefixos de adquirente
        como "Mp *", "PG ", "Cappta *" ou "Dl *", que não fazem parte do nome do
        estabelecimento e devem ser ignorados na hora de decidir.

        Para cada item, responda com o número do item e uma destas categorias:

        {$taxonomy}

        Regras:
        - Responda um item para cada número recebido, na mesma numeração.
        - Use exatamente os valores de categoria listados acima.
        - Quando a descrição não permitir identificar o tipo de gasto com
          razoável confiança, use "outros". Não adivinhe.
        INSTRUCTIONS;
    }

    /**
     * O enum entra no JSON Schema, o que faz o proprio runtime recusar rotulo
     * fora da lista. E o que dispensa normalizar string depois e o que impede
     * o grafico de ganhar tres fatias para o mesmo supermercado.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items(
                $schema->object(fn (JsonSchema $schema): array => [
                    'id' => $schema->integer()->required(),
                    'category' => $schema->string()->enum(ExpenseCategory::values())->required(),
                ]),
            )->required(),
        ];
    }

    /**
     * O gemma4 tem modo de raciocinio. Numa tarefa de classificacao ele so
     * custa tokens, e o gateway do Ollama iça a chave "think" para o topo do
     * corpo da requisicao.
     *
     * O parametro chega como string do driver, nao como Lab: comparar contra o
     * caso do enum direto num match silenciaria a opcao sem erro nenhum.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;

        return $driver === Lab::Ollama->value ? ['think' => false] : [];
    }
}
