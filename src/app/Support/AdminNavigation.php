<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;

/**
 * Menu do painel admin definido UMA vez (E4.3). `<x-admin-nav>` o renderiza nos dois modos
 * (sidebar desktop e offcanvas mobile) e `layouts/tenant-admin` não conhece nenhum item.
 *
 * Para adicionar/mover/remover um item de menu, edite só {@see self::definition()}:
 *
 *   new AdminNavItem('Cupons', 'tenant.admin.coupons.index', 'fa-solid fa-ticket',
 *       active: ['tenant.admin.coupons.*'], can: 'manageCoupons')
 *
 * `can` é uma ability do Gate (policy) checada contra o tenant atual — o mesmo que o `@can`
 * fazia no Blade. Item sem permissão não é renderizado (e um grupo sem itens some).
 */
class AdminNavigation
{
    /** @var list<AdminNavItem|AdminNavSection> */
    private array $entries;

    /** @param  list<AdminNavItem|AdminNavSection>|null  $entries  `null` = o menu padrão do produto */
    public function __construct(?array $entries = null)
    {
        $this->entries = $entries ?? self::definition();
    }

    /**
     * O menu do produto: ordem, agrupamento, rótulos, ícones, destaque e permissões.
     *
     * @return list<AdminNavItem|AdminNavSection>
     */
    public static function definition(): array
    {
        return [
            new AdminNavItem('Dashboard', 'tenant.admin.dashboard', 'fa-solid fa-gauge', dividerAfter: true),

            new AdminNavSection('Operacao', 'Operação', 'fa-solid fa-store', [
                new AdminNavItem('Pedidos', 'tenant.admin.orders.index', 'fa-solid fa-receipt', active: ['tenant.admin.orders.*']),
                new AdminNavItem('Clientes', 'tenant.admin.customers.index', 'fa-solid fa-users', active: ['tenant.admin.customers.*']),
            ]),

            new AdminNavSection('Catalogo', 'Catálogo', 'fa-solid fa-boxes-stacked', [
                new AdminNavItem('Produtos', 'tenant.admin.products.index', 'fa-solid fa-box', active: ['tenant.admin.products.*']),
                new AdminNavItem('Categorias', 'tenant.admin.categories.index', 'fa-solid fa-tags', active: ['tenant.admin.categories.*']),
                new AdminNavItem('Vitrines', 'tenant.admin.showcases.index', 'fa-solid fa-window-restore', active: ['tenant.admin.showcases.*']),
            ]),

            new AdminNavSection('Config', 'Configurações', 'fa-solid fa-gear', [
                new AdminNavItem('Informações', 'tenant.admin.store-info', 'fa-solid fa-circle-info'),
                new AdminNavItem('Aparência', 'tenant.admin.customization', 'fa-solid fa-palette'),
                new AdminNavItem('Contato', 'tenant.admin.contact', 'fa-solid fa-phone'),
                new AdminNavItem('Endereço', 'tenant.admin.address', 'fa-solid fa-location-dot'),
                new AdminNavItem('Horários', 'tenant.admin.business-hours', 'fa-solid fa-clock'),
                new AdminNavItem('Redes Sociais', 'tenant.admin.social-links', 'fa-solid fa-share-nodes'),
                new AdminNavItem('Entrega', 'tenant.admin.delivery', 'fa-solid fa-truck'),
                new AdminNavItem('Pagamentos', 'tenant.admin.payment-settings', 'fa-solid fa-credit-card', can: 'managePaymentMethods'),
                new AdminNavItem('SEO', 'tenant.admin.seo', 'fa-solid fa-magnifying-glass'),
                new AdminNavItem('URL/Slug', 'tenant.admin.settings', 'fa-solid fa-link', can: 'updateSlug'),
            ]),
        ];
    }

    /** @return list<AdminNavItem|AdminNavSection> */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Só o que o usuário autenticado pode ver (`Gate`, como o `@can`): itens sem permissão saem
     * e grupos que ficam vazios também.
     *
     * @return list<AdminNavItem|AdminNavSection>
     */
    public function visibleFor(Tenant $tenant): array
    {
        $visible = [];

        foreach ($this->entries as $entry) {
            if ($entry instanceof AdminNavSection) {
                $items = array_values(array_filter($entry->items, fn (AdminNavItem $i) => $this->allowed($i, $tenant)));
                if ($items !== []) {
                    $visible[] = $entry->withItems($items);
                }

                continue;
            }

            if ($this->allowed($entry, $tenant)) {
                $visible[] = $entry;
            }
        }

        return $visible;
    }

    private function allowed(AdminNavItem $item, Tenant $tenant): bool
    {
        return $item->can === null || Gate::allows($item->can, $tenant);
    }
}
