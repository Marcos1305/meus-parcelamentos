<?php

namespace App\Enums;

/**
 * A taxonomia de gastos. Esta e a unica definicao: o schema do agente entra
 * no JSON Schema a partir daqui, o que faz o proprio runtime recusar rotulo
 * fora da lista, e a tela recebe rotulo e cor prontos do servidor em vez de
 * manter uma copia que sairia de sincronia em silencio.
 *
 * A dica de cada caso nao e documentacao: ela vai literalmente para dentro das
 * instrucoes do agente. Mexer nela muda a classificacao.
 *
 * As cores vieram da skill dataviz: treze tons OKLCH escolhidos por busca
 * (hue, lightness e chroma otimizados) para maximizar a distancia perceptual
 * entre vizinhos, simulada para protanopia e deuteranopia, com L restrita a
 * interseccao das faixas clara e escura porque um unico hexadecimal e usado
 * nos dois temas sem variante (ver row-color-picker.tsx). A checagem valida
 * pares adjacentes, que e o par que realmente se toca no grafico empilhado
 * (category-trend-chart.tsx) e na barra ordenada por valor
 * (category-breakdown-chart.tsx) desta tela; nao e o par-a-par completo que
 * a skill exige para dispersao/mapa, onde qualquer par pode ficar vizinho.
 */
enum ExpenseCategory: string
{
    case Mercado = 'mercado';
    case RestaurantesELanches = 'restaurantes-e-lanches';
    case Combustivel = 'combustivel';
    case Transporte = 'transporte';
    case Saude = 'saude';
    case Casa = 'casa';
    case Eletronicos = 'eletronicos';
    case Marketplace = 'marketplace';
    case Vestuario = 'vestuario';
    case Assinaturas = 'assinaturas';
    case Lazer = 'lazer';
    case Viagem = 'viagem';
    case Outros = 'outros';

    public function label(): string
    {
        return match ($this) {
            self::Mercado => 'Mercado',
            self::RestaurantesELanches => 'Restaurantes e lanches',
            self::Combustivel => 'Combustível',
            self::Transporte => 'Transporte',
            self::Saude => 'Saúde',
            self::Casa => 'Casa',
            self::Eletronicos => 'Eletrônicos',
            self::Marketplace => 'Marketplace',
            self::Vestuario => 'Vestuário',
            self::Assinaturas => 'Assinaturas',
            self::Lazer => 'Lazer',
            self::Viagem => 'Viagem',
            self::Outros => 'Outros',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Mercado => 'supermercado, hortifruti, açougue, padaria',
            self::RestaurantesELanches => 'restaurante, bar, lanchonete, delivery',
            self::Combustivel => 'posto de combustível, abastecimento',
            self::Transporte => 'aplicativo de corrida, estacionamento, pedágio, ônibus',
            self::Saude => 'farmácia, consulta, exame, plano de saúde',
            self::Casa => 'móveis, utilidades domésticas, material de construção, contas da casa',
            self::Eletronicos => 'celular, computador, periférico, eletrônico em geral',
            self::Marketplace => 'Mercado Livre, Amazon, Shopee, AliExpress, Magalu, Shein e qualquer marketplace online, quando a descrição identificar a plataforma mas não o produto comprado',
            self::Vestuario => 'roupa, calçado, acessório',
            self::Assinaturas => 'streaming, armazenamento em nuvem, software por assinatura',
            self::Lazer => 'cinema, show, jogo, academia',
            self::Viagem => 'passagem aérea, hotel, hospedagem',
            self::Outros => 'qualquer gasto que não caiba nas demais, e tudo que a descrição não permitir identificar',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Mercado => '#0095ea',
            self::RestaurantesELanches => '#ae4100',
            self::Combustivel => '#00a0b3',
            self::Transporte => '#b61444',
            self::Saude => '#8275f9',
            self::Casa => '#e35e32',
            self::Eletronicos => '#286fe3',
            self::Marketplace => '#984260',
            self::Vestuario => '#918b00',
            self::Assinaturas => '#9c3282',
            self::Lazer => '#00a467',
            self::Viagem => '#9851bf',
            self::Outros => '#317700',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $category): string => $category->value,
            self::cases(),
        );
    }
}
