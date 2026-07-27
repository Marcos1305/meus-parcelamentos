<?php

namespace App\Providers;

use App\Invoices\ExtractsInvoiceText;
use App\Invoices\Parsers\ItauInvoiceParser;
use App\Invoices\Parsers\MercadoPagoInvoiceParser;
use App\Invoices\Parsers\NubankInvoiceParser;
use App\Invoices\Parsers\RennerInvoiceParser;
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
         * A lista de emissores suportados. Ela e a unica fonte do gate:
         * ResolveInvoiceParser::hasParsers() deriva dela, e e isso que decide
         * se a importacao de fatura aparece na interface. As assinaturas dos
         * quatro sao mutuamente exclusivas, entao a ordem aqui nao altera qual
         * parser atende cada fatura.
         */
        $this->app->bind(
            ResolveInvoiceParser::class,
            fn (): ResolveInvoiceParser => new ResolveInvoiceParser([
                new NubankInvoiceParser,
                new MercadoPagoInvoiceParser,
                new ItauInvoiceParser,
                new RennerInvoiceParser,
            ]),
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
