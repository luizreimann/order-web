<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Grupo (acordeão) do menu do painel admin (E4.3). Ver {@see AdminNavigation}.
 */
final readonly class AdminNavSection
{
    /**
     * @param  string  $key  identificador estável; compõe os ids `menu{Key}` / `collapse{Key}`
     * @param  string  $label  texto exibido no cabeçalho do grupo
     * @param  string  $icon  classes Font Awesome do cabeçalho
     * @param  list<AdminNavItem>  $items
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public array $items,
    ) {}

    /** O grupo fica aberto/destacado quando algum item dele é a rota atual. */
    public function isActive(Request $request): bool
    {
        foreach ($this->items as $item) {
            if ($item->isActive($request)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<AdminNavItem>  $items */
    public function withItems(array $items): self
    {
        return new self($this->key, $this->label, $this->icon, $items);
    }
}
