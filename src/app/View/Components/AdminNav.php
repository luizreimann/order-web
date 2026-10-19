<?php

namespace App\View\Components;

use App\Models\Tenant;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-admin-nav mode="sidebar|offcanvas" />` — o menu do painel admin (E4.3), montado a partir
 * de {@see AdminNavigation}. Dois modos, uma só definição:
 *
 *  - `sidebar` (padrão): desktop; ids `menu{Key}` / `collapse{Key}`.
 *  - `offcanvas`: mobile; ids com sufixo `Mobile`. Os links NÃO levam `data-bs-dismiss="offcanvas"`:
 *    o Bootstrap chama `preventDefault()` em `<a data-bs-dismiss>`, então o menu fechava e a navegação
 *    nunca acontecia. O link navega e o offcanvas some com o recarregamento da página (o admin não usa
 *    `wire:navigate`). O único `data-bs-dismiss` do offcanvas é o botão "Fechar" do layout.
 *
 * O tenant vem de `view()->share('currentTenant')` (ResolveTenantFromPath), salvo se passado
 * em `:tenant`.
 */
class AdminNav extends Component
{
    public const MODES = ['sidebar', 'offcanvas'];

    public Tenant $tenant;

    public function __construct(public string $mode = 'sidebar', ?Tenant $tenant = null)
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("<x-admin-nav> mode inválido [{$mode}]; use: ".implode(', ', self::MODES));
        }

        $this->tenant = $tenant ?? view()->shared('currentTenant');
    }

    public function render(): View
    {
        return view('components.admin-nav', [
            'entries' => app(AdminNavigation::class)->visibleFor($this->tenant),
            'idSuffix' => $this->mode === 'offcanvas' ? 'Mobile' : '',
        ]);
    }
}
