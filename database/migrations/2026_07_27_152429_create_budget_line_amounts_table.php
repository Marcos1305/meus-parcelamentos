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
        Schema::create('budget_line_amounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->unsignedInteger('amount_cents');
            $table->timestamps();

            $table->unique(['budget_line_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_line_amounts');
    }
};
