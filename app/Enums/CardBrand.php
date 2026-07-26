<?php

namespace App\Enums;

enum CardBrand: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case Elo = 'elo';
    case Amex = 'amex';
    case Hipercard = 'hipercard';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'Mastercard',
            self::Elo => 'Elo',
            self::Amex => 'American Express',
            self::Hipercard => 'Hipercard',
            self::Outro => 'Outra',
        };
    }
}
