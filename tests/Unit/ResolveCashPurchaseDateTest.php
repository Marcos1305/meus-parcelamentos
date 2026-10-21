<?php

use App\Actions\ResolveCashPurchaseDate;
use Carbon\CarbonImmutable;

it('usa o ano do vencimento quando a compra o antecede', function (): void {
    $date = (new ResolveCashPurchaseDate)->handle(2, 6, CarbonImmutable::parse('2026-07-09'));

    expect($date->toDateString())->toBe('2026-06-02');
});

it('recua um ano quando a compra cairia depois do vencimento', function (): void {
    $date = (new ResolveCashPurchaseDate)->handle(28, 12, CarbonImmutable::parse('2026-01-10'));

    expect($date->toDateString())->toBe('2025-12-28');
});

it('encaixa o dia no ultimo dia do mes quando ele nao existe', function (): void {
    $date = (new ResolveCashPurchaseDate)->handle(31, 2, CarbonImmutable::parse('2026-04-10'));

    expect($date->toDateString())->toBe('2026-02-28');
});
