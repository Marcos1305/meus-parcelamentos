<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O que ja foi pago em cada mes. A presenca da linha e a marca: sem linha,
     * nao foi pago.
     *
     * Sao tabelas proprias e nao uma coluna em budget_line_amounts ou em
     * card_month_amounts porque aquelas linhas so existem quando ha valor
     * digitado. Marcar como paga criaria a linha e fixaria o valor do mes, que
     * ate entao seguia o padrao ou o calculo das parcelas.
     */
    public function up(): void
    {
        Schema::create('budget_line_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->timestamps();

            $table->unique(['budget_line_id', 'month']);
        });

        Schema::create('card_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->timestamps();

            $table->unique(['card_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_line_payments');
        Schema::dropIfExists('card_payments');
    }
};
