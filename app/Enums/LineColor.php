<?php

namespace App\Enums;

/**
 * A paleta das linhas do planejamento. Os tons são de saturação média de
 * propósito: a cor aparece como uma faixa fina ao lado do nome e precisa ser
 * visível tanto sobre o fundo claro quanto sobre o escuro, sem que nenhuma
 * delas dispute atenção com o vermelho de saldo negativo.
 *
 * Esta é a única definição da paleta. A tela recebe a lista pronta do servidor
 * em vez de manter uma cópia própria, que sairia de sincronia em silêncio.
 */
enum LineColor: string
{
    case Rosa = 'rosa';
    case Vermelho = 'vermelho';
    case Laranja = 'laranja';
    case Ambar = 'ambar';
    case Lima = 'lima';
    case Verde = 'verde';
    case Turquesa = 'turquesa';
    case Ciano = 'ciano';
    case Azul = 'azul';
    case Indigo = 'indigo';
    case Violeta = 'violeta';
    case Magenta = 'magenta';

    public function hex(): string
    {
        return match ($this) {
            self::Rosa => '#f43f5e',
            self::Vermelho => '#ef4444',
            self::Laranja => '#f97316',
            self::Ambar => '#f59e0b',
            self::Lima => '#84cc16',
            self::Verde => '#22c55e',
            self::Turquesa => '#14b8a6',
            self::Ciano => '#06b6d4',
            self::Azul => '#3b82f6',
            self::Indigo => '#6366f1',
            self::Violeta => '#8b5cf6',
            self::Magenta => '#ec4899',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Rosa => 'Rosa',
            self::Vermelho => 'Vermelho',
            self::Laranja => 'Laranja',
            self::Ambar => 'Âmbar',
            self::Lima => 'Lima',
            self::Verde => 'Verde',
            self::Turquesa => 'Turquesa',
            self::Ciano => 'Ciano',
            self::Azul => 'Azul',
            self::Indigo => 'Índigo',
            self::Violeta => 'Violeta',
            self::Magenta => 'Magenta',
        };
    }

    /**
     * @return array<int, array{value: string, hex: string, label: string}>
     */
    public static function palette(): array
    {
        return array_map(
            fn (self $color): array => [
                'value' => $color->value,
                'hex' => $color->hex(),
                'label' => $color->label(),
            ],
            self::cases(),
        );
    }
}
