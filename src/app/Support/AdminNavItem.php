<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Um link do menu do painel admin (E4.3). Ver {@see AdminNavigation}.
 */
final readonly class AdminNavItem
{
    /**
     * @param  string  $label  texto exibido
     * @param  string  $route  nome da rota (recebe `['tenant' => slug]`)
     * @param  string  $icon  classes Font Awesome, ex.: `fa-solid fa-box`
     * @param  list<string>  $active  padrões de `routeIs()` que destacam o item; vazio = o próprio `$route`
     * @param  string|null  $can  ability do Gate checada contra o `Tenant`; `null` = sempre visível
     * @param  bool  $dividerAfter  desenha o separador logo depois do item
     */
    public function __construct(
        public string $label,
        public string $route,
        public string $icon,
        public array $active = [],
        public ?string $can = null,
        public bool $dividerAfter = false,
    ) {}

    /** @return list<string> */
    public function activePatterns(): array
    {
        return $this->active === [] ? [$this->route] : $this->active;
    }

    public function isActive(Request $request): bool
    {
        return $request->routeIs(...$this->activePatterns());
    }

    public function url(Tenant $tenant): string
    {
        return route($this->route, ['tenant' => $tenant->slug]);
    }
}
