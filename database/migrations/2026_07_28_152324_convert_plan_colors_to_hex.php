<?php

use App\Enums\LineColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A cor da linha deixa de ser um apelido da paleta e passa a ser o proprio
     * hexadecimal, para aceitar qualquer tom escolhido no seletor. Os valores
     * ja gravados eram apelidos e sao convertidos aqui.
     */
    public function up(): void
    {
        foreach (LineColor::cases() as $color) {
            $this->rename('budget_lines', $color->value, $color->hex());
            $this->rename('cards', $color->value, $color->hex());
        }
    }

    /**
     * Volta ao apelido quando o hexadecimal e um dos da paleta. Uma cor
     * escolhida a mao nao tem apelido correspondente e some, porque o formato
     * antigo nao sabia representa-la.
     */
    public function down(): void
    {
        foreach (LineColor::cases() as $color) {
            $this->rename('budget_lines', $color->hex(), $color->value);
            $this->rename('cards', $color->hex(), $color->value);
        }

        DB::table('budget_lines')->where('color', 'like', '#%')->update(['color' => null]);
        DB::table('cards')->where('color', 'like', '#%')->update(['color' => null]);
    }

    private function rename(string $table, string $from, string $to): void
    {
        DB::table($table)->where('color', $from)->update(['color' => $to]);
    }
};
