<?php

namespace App\Providers;

use App\Invoices\ExtractsInvoiceText;
use App\Invoices\PopplerTextExtractor;
use App\Invoices\ResolveInvoiceParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ExtractsInvoiceText::class,
            PopplerTextExtractor::class,
        );

        /**
         * Nenhum parser de emissor esta registrado ainda. Enquanto a lista
         * estiver vazia, ResolveInvoiceParser::hasParsers() e falso e a
         * importacao de fatura se esconde da interface por conta propria.
         */
        $this->app->bind(
            ResolveInvoiceParser::class,
            fn (): ResolveInvoiceParser => new ResolveInvoiceParser([]),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
