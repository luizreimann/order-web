<?php

namespace App\Livewire\Admin\Concerns;

/**
 * Re-autoriza a VISUALIZAÇÃO do componente a cada request subsequente do
 * Livewire (`mount()` só roda na primeira renderização). Sem isso, quem
 * perdeu a permissão continuaria recebendo o re-render (dados) do componente.
 *
 * O componente implementa {@see self::authorizeView()}.
 */
trait AuthorizesOnHydrate
{
    abstract protected function authorizeView(): void;

    /** Hook de trait do Livewire: `hydrate{NomeDaTrait}`. */
    public function hydrateAuthorizesOnHydrate(): void
    {
        $this->authorizeView();
    }
}
