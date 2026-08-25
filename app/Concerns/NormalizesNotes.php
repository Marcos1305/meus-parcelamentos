<?php

namespace App\Concerns;

trait NormalizesNotes
{
    /**
     * "Sem observacao" precisa de uma representacao so no banco. Sem isso a
     * mesma ausencia chegaria ora como null, ora como string vazia, e o
     * indicador de "tem nota" da linha do historico teria que conhecer as
     * duas formas para acertar.
     */
    protected function normalizeNotes(): void
    {
        if (! $this->has('notes')) {
            return;
        }

        $notes = trim((string) $this->input('notes'));

        $this->merge(['notes' => $notes === '' ? null : $notes]);
    }
}
