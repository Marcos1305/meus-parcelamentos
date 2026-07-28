<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

#[Signature('dev:login-link {email? : E-mail do usuário; o padrão é a conta de demonstração}')]
#[Description('Gera um link assinado de login para conferir a interface no navegador, sem digitar senha')]
class DevLoginLink extends Command
{
    /**
     * Validade do link. Curta porque a URL passa pelo terminal e fica no
     * histórico do shell depois de usada.
     */
    private const EXPIRES_IN_MINUTES = 10;

    private const DEMO_EMAIL = 'demo@exemplo.test';

    public function handle(): int
    {
        if (! $this->getLaravel()->environment(['local', 'testing'])) {
            $this->components->error('Este comando só funciona em ambiente local.');

            return self::FAILURE;
        }

        $email = $this->argument('email') ?? self::DEMO_EMAIL;

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("Nenhum usuário com o e-mail {$email}.");

            return self::FAILURE;
        }

        $url = URL::temporarySignedRoute(
            'dev.login',
            CarbonImmutable::now()->addMinutes(self::EXPIRES_IN_MINUTES),
            ['user' => $user->id],
        );

        $this->components->info(
            "Link de {$user->email}, válido por ".self::EXPIRES_IN_MINUTES.' minutos:',
        );
        $this->line($url);

        return self::SUCCESS;
    }
}
