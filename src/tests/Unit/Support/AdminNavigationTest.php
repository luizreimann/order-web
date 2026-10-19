<?php

namespace Tests\Unit\Support;

use App\Models\Tenant;
use App\Policies\TenantPolicy;
use App\Support\AdminNavigation;
use App\Support\AdminNavItem;
use App\Support\AdminNavSection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * E4.3 — o menu do admin é definido em UM lugar (`AdminNavigation::definition()`).
 */
class AdminNavigationTest extends TestCase
{
    private function tenant(): Tenant
    {
        return (new Tenant)->forceFill(['id' => 1, 'slug' => 'loja-x']);
    }

    /** @return list<array{string, string, string, string|null}> [label, route, icon, can] na ordem do menu */
    private function flatten(array $entries): array
    {
        $flat = [];
        foreach ($entries as $entry) {
            foreach ($entry instanceof AdminNavSection ? $entry->items : [$entry] as $item) {
                $flat[] = [$item->label, $item->route, $item->icon, $item->can];
            }
        }

        return $flat;
    }

    /** @param  list<string>  $denied */
    private function denyAbilities(array $denied): void
    {
        Gate::before(fn ($user = null, string $ability = '') => in_array($ability, $denied, true) ? false : true);
    }

    public function test_default_definition_keeps_the_original_menu_order_labels_routes_and_icons(): void
    {
        $this->assertSame([
            ['Dashboard', 'tenant.admin.dashboard', 'fa-solid fa-gauge', null],
            ['Pedidos', 'tenant.admin.orders.index', 'fa-solid fa-receipt', null],
            ['Clientes', 'tenant.admin.customers.index', 'fa-solid fa-users', null],
            ['Produtos', 'tenant.admin.products.index', 'fa-solid fa-box', null],
            ['Categorias', 'tenant.admin.categories.index', 'fa-solid fa-tags', null],
            ['Vitrines', 'tenant.admin.showcases.index', 'fa-solid fa-window-restore', null],
            ['Informações', 'tenant.admin.store-info', 'fa-solid fa-circle-info', null],
            ['Aparência', 'tenant.admin.customization', 'fa-solid fa-palette', null],
            ['Contato', 'tenant.admin.contact', 'fa-solid fa-phone', null],
            ['Endereço', 'tenant.admin.address', 'fa-solid fa-location-dot', null],
            ['Horários', 'tenant.admin.business-hours', 'fa-solid fa-clock', null],
            ['Redes Sociais', 'tenant.admin.social-links', 'fa-solid fa-share-nodes', null],
            ['Entrega', 'tenant.admin.delivery', 'fa-solid fa-truck', null],
            ['Pagamentos', 'tenant.admin.payment-settings', 'fa-solid fa-credit-card', 'managePaymentMethods'],
            ['SEO', 'tenant.admin.seo', 'fa-solid fa-magnifying-glass', null],
            ['URL/Slug', 'tenant.admin.settings', 'fa-solid fa-link', 'updateSlug'],
        ], $this->flatten(AdminNavigation::definition()));

        $this->assertSame(
            ['Dashboard', 'Operação', 'Catálogo', 'Configurações'],
            array_map(fn ($e) => $e->label, AdminNavigation::definition()),
        );
        $this->assertSame(
            ['Operacao', 'Catalogo', 'Config'],
            array_values(array_map(fn ($e) => $e->key, array_filter(AdminNavigation::definition(), fn ($e) => $e instanceof AdminNavSection))),
        );
    }

    public function test_every_route_and_ability_in_the_definition_exists(): void
    {
        foreach ($this->flatten(AdminNavigation::definition()) as [$label, $route, , $can]) {
            $this->assertTrue(Route::has($route), "{$label}: rota [{$route}] não existe");
            if ($can !== null) {
                $this->assertTrue(method_exists(TenantPolicy::class, $can), "{$label}: ability [{$can}] não existe em TenantPolicy");
            }
        }
    }

    public function test_labels_routes_and_section_keys_are_unique(): void
    {
        $flat = $this->flatten(AdminNavigation::definition());

        foreach ([0, 1] as $col) {
            $values = array_column($flat, $col);
            $this->assertSame($values, array_values(array_unique($values)), "coluna {$col} com repetição");
        }
    }

    public function test_dashboard_is_the_only_item_followed_by_a_divider(): void
    {
        $withDivider = array_filter(AdminNavigation::definition(), fn ($e) => $e instanceof AdminNavItem && $e->dividerAfter);

        $this->assertSame(['Dashboard'], array_values(array_map(fn ($e) => $e->label, $withDivider)));
    }

    public function test_item_url_uses_the_tenant_slug(): void
    {
        $item = new AdminNavItem('Pedidos', 'tenant.admin.orders.index', 'fa-solid fa-receipt');

        $this->assertSame('/loja-x/admin/pedidos', parse_url($item->url($this->tenant()), PHP_URL_PATH));
    }

    public function test_active_patterns_default_to_the_route_itself_and_can_be_overridden(): void
    {
        $own = new AdminNavItem('SEO', 'tenant.admin.seo', 'fa-solid fa-x');
        $wild = new AdminNavItem('Pedidos', 'tenant.admin.orders.index', 'fa-solid fa-x', active: ['tenant.admin.orders.*']);

        $this->assertSame(['tenant.admin.seo'], $own->activePatterns());
        $this->assertSame(['tenant.admin.orders.*'], $wild->activePatterns());

        $request = Request::create('/loja-x/admin/pedidos/9');
        $request->setRouteResolver(fn () => Route::getRoutes()->getByName('tenant.admin.orders.show'));

        $this->assertTrue($wild->isActive($request), 'orders.show deve destacar "Pedidos"');
        $this->assertFalse($own->isActive($request));
    }

    public function test_section_is_active_when_any_of_its_items_is(): void
    {
        $section = collect(AdminNavigation::definition())->first(fn ($e) => $e instanceof AdminNavSection && $e->key === 'Config');

        $on = Request::create('/loja-x/admin/seo');
        $on->setRouteResolver(fn () => Route::getRoutes()->getByName('tenant.admin.seo'));
        $off = Request::create('/loja-x/admin/pedidos');
        $off->setRouteResolver(fn () => Route::getRoutes()->getByName('tenant.admin.orders.index'));

        $this->assertTrue($section->isActive($on));
        $this->assertFalse($section->isActive($off));
    }

    public function test_visible_for_hides_items_the_user_cannot_see_and_keeps_the_rest(): void
    {
        $this->denyAbilities(['updateSlug', 'managePaymentMethods']);

        $labels = array_column($this->flatten((new AdminNavigation)->visibleFor($this->tenant())), 0);

        $this->assertNotContains('Pagamentos', $labels);
        $this->assertNotContains('URL/Slug', $labels);
        $this->assertContains('SEO', $labels);
        $this->assertCount(14, $labels);
    }

    public function test_visible_for_shows_everything_when_allowed(): void
    {
        $this->denyAbilities([]);

        $this->assertCount(16, $this->flatten((new AdminNavigation)->visibleFor($this->tenant())));
    }

    public function test_a_section_left_without_visible_items_is_dropped(): void
    {
        $nav = new AdminNavigation([
            new AdminNavItem('Home', 'tenant.admin.dashboard', 'fa-solid fa-gauge'),
            new AdminNavSection('Fin', 'Financeiro', 'fa-solid fa-coins', [
                new AdminNavItem('Repasses', 'tenant.admin.seo', 'fa-solid fa-coins', can: 'manageFinance'),
            ]),
        ]);
        $this->denyAbilities(['manageFinance']);

        $visible = $nav->visibleFor($this->tenant());

        $this->assertCount(1, $visible);
        $this->assertSame('Home', $visible[0]->label);
    }

    public function test_registering_a_new_item_is_a_single_edit_in_the_entries(): void
    {
        $extra = new AdminNavItem('Cupons', 'tenant.admin.seo', 'fa-solid fa-ticket', can: 'manageCoupons');
        $nav = new AdminNavigation([...AdminNavigation::definition(), $extra]);
        $this->denyAbilities([]);

        $labels = array_column($this->flatten($nav->visibleFor($this->tenant())), 0);

        $this->assertSame('Cupons', end($labels));
        $this->assertCount(17, $labels);
    }
}
