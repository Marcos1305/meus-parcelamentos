<?php

namespace App\Console\Commands;

use App\Actions\GenerateInstallments;
use App\Models\Purchase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Signature('installments:rebuild {--user= : Limita o rebuild às compras deste usuário}')]
#[Description('Regera todas as parcelas a partir das compras e da configuração atual dos cartões')]
class RebuildInstallments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GenerateInstallments $generateInstallments): int
    {
        $userId = $this->option('user');

        $query = Purchase::query()->with('card');

        if ($userId !== null) {
            $query->whereHas('card', fn (Builder $cards) => $cards->where('user_id', $userId));
        }

        $processed = 0;

        $query->chunkById(100, function (Collection $purchases) use ($generateInstallments, &$processed): void {
            foreach ($purchases as $purchase) {
                $generateInstallments->handle($purchase);
                $processed++;
            }
        });

        $this->info("Parcelas regeradas para {$processed} compras.");

        return self::SUCCESS;
    }
}
