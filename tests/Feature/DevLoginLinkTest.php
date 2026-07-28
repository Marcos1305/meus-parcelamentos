<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;

function devLoginUrl(User $user, int $minutes = 10): string
{
    return URL::temporarySignedRoute(
        'dev.login',
        CarbonImmutable::now()->addMinutes($minutes),
        ['user' => $user->id],
    );
}

it('autentica pelo link assinado', function (): void {
    $user = User::factory()->create();

    $this->get(devLoginUrl($user))->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

it('recusa link sem assinatura', function (): void {
    $user = User::factory()->create();

    $this->get(route('dev.login', $user->id))->assertForbidden();

    expect(auth()->check())->toBeFalse();
});

it('recusa link com assinatura adulterada', function (): void {
    $user = User::factory()->create();

    $this->get(devLoginUrl($user).'x')->assertForbidden();

    expect(auth()->check())->toBeFalse();
});

it('recusa link expirado', function (): void {
    $user = User::factory()->create();
    $url = devLoginUrl($user, 10);

    $this->travel(11)->minutes();

    $this->get($url)->assertForbidden();

    expect(auth()->check())->toBeFalse();
});

it('nao autentica fora do ambiente local', function (): void {
    $user = User::factory()->create();
    $url = devLoginUrl($user);

    app()->detectEnvironment(fn (): string => 'production');

    $this->get($url)->assertNotFound();

    expect(auth()->check())->toBeFalse();
});

it('o link impresso pelo comando autentica de verdade', function (): void {
    $user = User::factory()->create(['email' => 'alguem@exemplo.test']);

    expect(Artisan::call('dev:login-link', ['email' => 'alguem@exemplo.test']))->toBe(0);

    $url = trim(collect(explode("\n", Artisan::output()))
        ->first(fn (string $line): bool => str_contains($line, '/dev/entrar/')) ?? '');

    expect($url)->toContain("/dev/entrar/{$user->id}")
        ->and($url)->toContain('signature=');

    $this->get($url)->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

it('o comando usa a conta de demonstracao quando nenhum e-mail e passado', function (): void {
    $demo = User::factory()->create(['email' => 'demo@exemplo.test']);

    expect(Artisan::call('dev:login-link'))->toBe(0)
        ->and(Artisan::output())->toContain("/dev/entrar/{$demo->id}");
});

it('o comando recusa e-mail desconhecido', function (): void {
    $this->artisan('dev:login-link', ['email' => 'ninguem@exemplo.test'])
        ->assertFailed();
});

it('o comando recusa rodar fora do ambiente local', function (): void {
    User::factory()->create(['email' => 'alguem@exemplo.test']);

    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('dev:login-link', ['email' => 'alguem@exemplo.test'])
        ->assertFailed();
});
