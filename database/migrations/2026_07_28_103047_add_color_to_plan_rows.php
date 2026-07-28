<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cor das linhas do planejamento. Nula significa sem cor, que é o estado
     * de toda linha e todo cartão já existentes.
     */
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->string('color')->nullable()->after('name');
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->string('color')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropColumn('color');
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
