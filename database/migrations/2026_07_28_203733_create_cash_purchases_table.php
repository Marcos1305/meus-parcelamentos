<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cash_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('amount_cents');
            $table->date('purchased_at');
            $table->date('billed_at');
            $table->string('category')->nullable();
            $table->boolean('category_is_manual')->default(false);
            $table->timestamps();

            $table->index(['card_id', 'billed_at']);

            /**
             * Reimportar a mesma fatura duplicaria o gasto e o grafico passaria
             * a mentir sem avisar. O unico no banco e a garantia que codigo
             * esquecido nao desfaz.
             *
             * O custo aceito: dois gastos identicos, no mesmo dia, no mesmo
             * estabelecimento e no mesmo valor viram um so.
             */
            $table->unique(['card_id', 'purchased_at', 'amount_cents', 'description'], 'cash_purchases_fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_purchases');
    }
};
