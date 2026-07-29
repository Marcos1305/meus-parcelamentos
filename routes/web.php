<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

if (app()->environment(['local', 'testing'])) {
    require __DIR__.'/dev.php';
}

require __DIR__.'/parcelamentos.php';
require __DIR__.'/planejamento.php';
require __DIR__.'/gastos.php';
require __DIR__.'/settings.php';
