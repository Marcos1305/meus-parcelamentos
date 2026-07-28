<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Atalho de login para conferir a interface no navegador sem digitar senha.
 *
 * Três travas, porque um caminho de autenticação que escapa para produção é um
 * acidente clássico: este arquivo só é carregado fora de produção (ver
 * routes/web.php), a rota exige assinatura válida — que precisa da APP_KEY para
 * ser forjada — e o próprio manipulador confere o ambiente de novo.
 */
Route::get('dev/entrar/{user}', function (User $user) {
    abort_unless(app()->environment(['local', 'testing']), 404);

    Auth::login($user);

    return redirect()->route('dashboard');
})->middleware('signed')->name('dev.login');
