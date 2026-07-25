<?php

namespace App\Actions;

class SplitIntoInstallments
{
    /**
     * Rateia um total em centavos entre N parcelas, com o resto na primeira.
     *
     * @return array<int, int>
     */
    public function handle(int $totalCents, int $count): array
    {
        $base = intdiv($totalCents, $count);
        $remainder = $totalCents % $count;

        return array_map(
            fn (int $index): int => $index === 0 ? $base + $remainder : $base,
            range(0, $count - 1),
        );
    }
}
