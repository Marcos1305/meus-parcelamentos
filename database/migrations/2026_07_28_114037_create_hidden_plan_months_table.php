<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os meses que o usuario escolheu esconder da planilha. Guardar so os
     * escondidos mantem o padrao certo: mes sem registro aparece, entao um ano
     * novo nasce inteiro visivel sem precisar de nenhuma linha.
     */
    public function up(): void
    {
        Schema::create('hidden_plan_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->timestamps();

            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_plan_months');
    }
};
