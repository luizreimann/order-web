<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\TestCase;

/**
 * E4.3 — `layouts/tenant-admin` renderizado por um endpoint real (autenticado), com e sem
 * permissão. O "golden" abaixo foi capturado do layout ANTES da refatoração (HTML da sidebar do
 * dashboard): se alguém mudar ordem, rótulo, rota, ícone ou id, este teste falha.
 */
class AdminLayoutTest extends TestCase
{
    use RefreshDatabase, RendersComponents;

    /** [rótulo, caminho, ícone] — ordem do menu antes da refatoração */
    private const GOLDEN_LINKS = [
        ['Dashboard', '/tenant-a/admin', 'fa-solid fa-gauge'],
        ['Pedidos', '/tenant-a/admin/pedidos', 'fa-solid fa-receipt'],
        ['Clientes', '/tenant-a/admin/clientes', 'fa-solid fa-users'],
        ['Produtos', '/tenant-a/admin/produtos', 'fa-solid fa-box'],
        ['Categorias', '/tenant-a/admin/categorias', 'fa-solid fa-tags'],
        ['Vitrines', '/tenant-a/admin/vitrines', 'fa-solid fa-window-restore'],
        ['Informações', '/tenant-a/admin/informacoes', 'fa-solid fa-circle-info'],
        ['Aparência', '/tenant-a/admin/personalizacao', 'fa-solid fa-palette'],
        ['Contato', '/tenant-a/admin/contato', 'fa-solid fa-phone'],
        ['Endereço', '/tenant-a/admin/endereco', 'fa-solid fa-location-dot'],
        ['Horários', '/tenant-a/admin/horarios', 'fa-solid fa-clock'],
        ['Redes Sociais', '/tenant-a/admin/redes-sociais', 'fa-solid fa-share-nodes'],
        ['Entrega', '/tenant-a/admin/entrega', 'fa-solid fa-truck'],
        ['Pagamentos', '/tenant-a/admin/pagamentos', 'fa-solid fa-credit-card'],
        ['SEO', '/tenant-a/admin/seo', 'fa-solid fa-magnifying-glass'],
        ['URL/Slug', '/tenant-a/admin/configuracoes', 'fa-solid fa-link'],
    ];

    /** Ids e seletores que resources/js/admin/*.js procura no markup. */
    private const JS_CONTRACT_IDS = [
        'themeToggleBtn', 'themeToggleIcon', 'themeToggleBtnMobile', 'themeToggleIconMobile',
        'storeStatusBtn', 'storeStatusIcon', 'storeStatusBtnMobile', 'storeStatusIconMobile',
        'adminSidebarOffcanvas', 'liveToast', 'toastMessage',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::factory()->create(['slug' => 'tenant-a', 'name' => 'Loja A']);
    }

    private function loginAdmin(): void
    {
        $this->actingAs(User::factory()->create([
            'tenant_id' => Tenant::query()->where('slug', 'tenant-a')->value('id'),
            'role' => Role::TenantAdmin,
            'name' => 'Admin Fulano',
        ]), 'tenant_admin');
    }

    private function page(string $path = '/tenant-a/admin'): string
    {
        return (string) $this->get($path)->assertOk()->getContent();
    }

    /** @return list<array{string, string, string}> */
    private function links(string $html, string $container): array
    {
        $links = [];
        foreach ($this->xpath($html)->query("{$container}//a[contains(@class,'nav-link')]") as $a) {
            assert($a instanceof \DOMElement);
            $icon = $a->getElementsByTagName('i')->item(0);
            assert($icon instanceof \DOMElement);
            $links[] = [
                trim($a->textContent),
                (string) parse_url($a->getAttribute('href'), PHP_URL_PATH),
                trim(str_replace('me-2', '', $icon->getAttribute('class'))),
            ];
        }

        return $links;
    }

    public function test_authenticated_layout_renders_both_menus_identical_to_the_original(): void
    {
        $this->loginAdmin();
        $html = $this->page();

        $this->assertSame(self::GOLDEN_LINKS, $this->links($html, '//aside'));
        $this->assertSame(self::GOLDEN_LINKS, $this->links($html, '//div[@id="adminSidebarOffcanvas"]'));
        $this->assertSame(1, $this->countNodes($html, '//aside[contains(@class,"admin-sidebar")]'));
        $this->assertSame(16, $this->countNodes($html, '//div[@id="adminSidebarOffcanvas"]//a[@data-bs-dismiss="offcanvas"]'));
        $this->assertSame(0, $this->countNodes($html, '//aside//*[@data-bs-dismiss]'));
    }

    public function test_layout_keeps_header_footer_and_shared_data(): void
    {
        $this->loginAdmin();
        $html = $this->page();

        $this->assertStringContainsString('<title>Admin - Loja A</title>', $html);
        $this->assertSame('Admin Fulano', trim($this->first($html, '//aside//div[contains(@class,"border-top")]/div[contains(@class,"small")]')->textContent));
        $this->assertStringContainsString('Olá, Admin Fulano!', $html);
        $this->assertStringContainsString('/tenant-a', $this->first($html, '//aside//a[@target="_blank"]')->getAttribute('href'));
        $this->assertSame(2, $this->countNodes($html, '//form[contains(@action,"/tenant-a/admin/logout")]'));
        $this->assertSame(1, $this->countNodes($html, '//nav[contains(@class,"navbar")]//button[@data-bs-target="#adminSidebarOffcanvas"][@aria-controls="adminSidebarOffcanvas"]'));
        $this->assertSame(2, $this->countNodes($html, '//main | //div[@id="liveToast"]'), 'main + container de toast');
    }

    public function test_every_id_and_selector_used_by_the_js_is_in_the_markup(): void
    {
        $this->loginAdmin();
        $html = $this->page();

        foreach (self::JS_CONTRACT_IDS as $id) {
            $this->assertSame(1, $this->countNodes($html, "//*[@id='{$id}']"), "#{$id} deve existir exatamente uma vez");
        }
        // o clique do toggle de status é delegado em [data-store-status-toggle] e usa data-store-status-url
        $this->assertSame(2, $this->countNodes($html, '//button[@data-store-status-toggle][@data-store-status-url][@data-label-paused][@data-label-open]'));
        $this->assertSame('/tenant-a/admin/toggle-store-status', $this->first($html, '//button[@id="storeStatusBtn"]')->getAttribute('data-store-status-url'));
        $this->assertSame(6, $this->countNodes($html, '//*[@data-bs-toggle="tooltip"]'));
        $this->assertSame(1, $this->countNodes($html, '//meta[@name="csrf-token"]'));
        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"toast-container")]'));
        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"admin-shell")]'));
    }

    public function test_js_modules_still_reference_the_ids_and_selectors_the_markup_provides(): void
    {
        $js = '';
        foreach (glob(dirname(__DIR__, 3).'/resources/js/admin/*.js') as $file) {
            $js .= file_get_contents($file);
        }

        foreach (array_diff(self::JS_CONTRACT_IDS, ['adminSidebarOffcanvas', 'liveToast', 'toastMessage']) as $id) {
            $this->assertStringContainsString("'{$id}'", $js, "o JS não procura mais #{$id}: atualize o contrato");
        }
        foreach (['[data-store-status-toggle]', 'storeStatusUrl', 'labelPaused', 'labelOpen', '[data-bs-toggle="tooltip"]', 'livewire:navigated', '.toast-container', '.admin-shell', 'data-bs-theme', "'theme'", 'prefers-color-scheme'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
    }

    public function test_active_route_highlights_the_link_and_opens_only_its_group(): void
    {
        $this->loginAdmin();
        $html = $this->page('/tenant-a/admin/pedidos');

        foreach (['//aside', '//div[@id="adminSidebarOffcanvas"]'] as $container) {
            $active = [];
            foreach ($this->xpath($html)->query("{$container}//a[{$this->hasClass('active')}]") as $a) {
                $active[] = trim($a->textContent);
            }
            $this->assertSame(['Pedidos'], $active, $container);
        }

        $suffixes = ['', 'Mobile'];
        foreach ($suffixes as $s) {
            $this->assertSame(1, $this->countNodes($html, "//div[@id='collapseOperacao{$s}' and {$this->hasClass('show')}]"));
            $this->assertSame(1, $this->countNodes($html, "//button[@data-bs-target='#collapseOperacao{$s}' and {$this->hasClass('has-active')} and not({$this->hasClass('collapsed')})]"));
            foreach (['Catalogo', 'Config'] as $closed) {
                $this->assertSame(0, $this->countNodes($html, "//div[@id='collapse{$closed}{$s}' and {$this->hasClass('show')}]"));
                $this->assertSame(1, $this->countNodes($html, "//button[@data-bs-target='#collapse{$closed}{$s}' and {$this->hasClass('collapsed')}]"));
            }
        }
    }

    public function test_nested_route_keeps_the_parent_item_active(): void
    {
        $this->loginAdmin();
        $html = $this->page('/tenant-a/admin/produtos/novo');

        $active = [];
        foreach ($this->xpath($html)->query("//aside//a[{$this->hasClass('active')}]") as $a) {
            $active[] = trim($a->textContent);
        }
        $this->assertSame(['Produtos'], $active);
        $this->assertSame(1, $this->countNodes($html, "//div[@id='collapseCatalogo' and {$this->hasClass('show')}]"));
    }

    public function test_dashboard_is_active_on_its_own_and_every_group_starts_collapsed(): void
    {
        $this->loginAdmin();
        $html = $this->page();

        $this->assertSame(['Dashboard'], array_map(fn ($a) => trim($a->textContent), iterator_to_array($this->xpath($html)->query("//aside//a[{$this->hasClass('active')}]"))));
        $this->assertSame(0, $this->countNodes($html, '//aside//div[contains(@class,"accordion-collapse")][contains(@class,"show")]'));
    }

    public function test_config_group_opens_for_a_settings_page(): void
    {
        $this->loginAdmin();
        $html = $this->page('/tenant-a/admin/seo');

        $this->assertSame(1, $this->countNodes($html, "//div[@id='collapseConfig' and {$this->hasClass('show')}]"));
        $this->assertSame(1, $this->countNodes($html, "//aside//a[{$this->hasClass('active')}]"));
    }

    public function test_without_permission_protected_items_and_store_status_are_not_rendered(): void
    {
        $this->loginAdmin();
        Gate::before(fn ($user = null, string $ability = '') => in_array($ability, ['updateSlug', 'managePaymentMethods', 'toggleStoreStatus'], true) ? false : null);

        $html = $this->page();
        $golden = array_values(array_filter(self::GOLDEN_LINKS, fn ($l) => ! in_array($l[0], ['Pagamentos', 'URL/Slug'], true)));

        $this->assertSame($golden, $this->links($html, '//aside'));
        $this->assertSame($golden, $this->links($html, '//div[@id="adminSidebarOffcanvas"]'));
        $this->assertStringNotContainsString('/tenant-a/admin/pagamentos', $html);
        $this->assertStringNotContainsString('/tenant-a/admin/configuracoes', $html);
        $this->assertSame(0, $this->countNodes($html, '//*[@id="storeStatusBtn" or @id="storeStatusBtnMobile" or @data-store-status-toggle]'));
        // o link da loja e o resto do rodapé continuam
        $this->assertSame(2, $this->countNodes($html, '//a[@target="_blank"][@href="/tenant-a"]'));
        $this->assertSame(1, $this->countNodes($html, '//*[@id="themeToggleBtn"]'));
    }

    public function test_paused_store_renders_the_closed_state(): void
    {
        $this->loginAdmin();
        Tenant::query()->where('slug', 'tenant-a')->update(['settings' => ['business_hours' => ['paused' => true]]]);

        $html = $this->page();

        foreach (['storeStatusBtn', 'storeStatusBtnMobile'] as $id) {
            $btn = $this->first($html, "//button[@id='{$id}']");
            $this->assertContains('btn-outline-danger', $this->classes($btn));
            $this->assertSame('Loja fechada - Clique para abrir', $btn->getAttribute('title'));
        }
        $this->assertSame(2, $this->countNodes($html, '//i[contains(@class,"fa-lock") and not(contains(@class,"fa-lock-open"))]'));
    }

    public function test_the_only_inline_script_is_the_early_theme_one_and_no_inline_css_remains(): void
    {
        $this->loginAdmin();
        $html = $this->page();

        [$head] = explode('</head>', $html);
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $head, $inline);

        $this->assertCount(1, $inline[1], 'só o script de tema no <head>');
        $this->assertStringContainsString("localStorage.getItem('theme')", $inline[1][0]);
        $this->assertStringContainsString('data-bs-theme', $inline[1][0]);
        $this->assertStringNotContainsString('admin-nav', $head, 'CSS do menu mora em resources/css/admin.css');
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('toggleStoreStatus', $html);
        $this->assertStringNotContainsString('window.showToast', $html);
    }

    public function test_layout_source_is_small_and_has_no_menu_js_or_css(): void
    {
        $source = file_get_contents(resource_path('views/layouts/tenant-admin.blade.php'));

        $this->assertLessThan(150, substr_count($source, "\n") + 1);
        $this->assertStringNotContainsString('<style', $source);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=\s*["\']/', $source);
        $this->assertSame(1, substr_count($source, '<script'), 'só o script do tema');
        $this->assertStringNotContainsString("route('tenant.admin.", str_replace("route('tenant.admin.logout'", '', $source), 'o layout não lista rotas de menu');
        $this->assertSame(2, substr_count($source, '<x-admin-nav'));
    }

    public function test_the_toggle_endpoint_contract_the_js_depends_on(): void
    {
        $this->loginAdmin();

        $this->postJson('/tenant-a/admin/toggle-store-status')
            ->assertOk()
            ->assertExactJson(['success' => true, 'paused' => true]);

        $this->assertStringContainsString('btn-outline-danger', $this->first($this->page(), '//button[@id="storeStatusBtn"]')->getAttribute('class'));
    }
}
