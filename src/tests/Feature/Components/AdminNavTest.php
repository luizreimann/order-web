<?php

namespace Tests\Feature\Components;

use App\Models\Tenant;
use App\Support\AdminNavigation;
use App\Support\AdminNavItem;
use App\Support\AdminNavSection;
use App\View\Components\AdminNav;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\TestCase;

/**
 * E4.3 — `<x-admin-nav>` (sidebar | offcanvas), `<x-admin-store-actions>` e `<x-admin-account>`.
 */
class AdminNavTest extends TestCase
{
    use RendersComponents;

    /** @var list<string> */
    private array $denied = [];

    protected function setUp(): void
    {
        parent::setUp();

        // um único Gate::before (os registrados se acumulam e o primeiro a responder vence)
        Gate::before(fn ($user = null, string $ability = '') => in_array($ability, $this->denied, true) ? false : true);
    }

    private function tenant(): Tenant
    {
        return (new Tenant)->forceFill(['id' => 1, 'slug' => 'loja-x', 'name' => 'Loja X', 'settings' => []]);
    }

    private function nav(string $mode): string
    {
        view()->share('currentTenant', $this->tenant());

        return $this->render('<x-admin-nav mode="'.$mode.'" />');
    }

    /** @param  list<string>  $denied */
    private function deny(array $denied): void
    {
        $this->denied = $denied;
    }

    /** @return list<string> textos dos links, na ordem */
    private function linkTexts(string $html): array
    {
        $texts = [];
        foreach ($this->xpath($html)->query('//a[contains(@class,"nav-link")]') as $a) {
            $texts[] = trim($a->textContent);
        }

        return $texts;
    }

    public function test_sidebar_renders_sections_links_and_accordion_ids(): void
    {
        $this->deny([]);
        $html = $this->nav('sidebar');

        $this->assertSame([
            'Dashboard', 'Pedidos', 'Clientes', 'Produtos', 'Categorias', 'Vitrines', 'Informações', 'Aparência',
            'Contato', 'Endereço', 'Horários', 'Redes Sociais', 'Entrega', 'Pagamentos', 'SEO', 'URL/Slug',
        ], $this->linkTexts($html));

        foreach (['Operacao', 'Catalogo', 'Config'] as $key) {
            $this->assertSame(1, $this->countNodes($html, "//div[@id='menu{$key}' and ".$this->hasClass('admin-nav-accordion').']'));
            $this->assertSame(1, $this->countNodes($html, "//button[@data-bs-target='#collapse{$key}']"));
            $this->assertSame(1, $this->countNodes($html, "//div[@id='collapse{$key}' and @data-bs-parent='#menu{$key}']"));
        }
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"admin-nav-divider")]'));
        $this->assertSame(0, $this->countNodes($html, '//*[@data-bs-dismiss]'), 'sidebar não fecha offcanvas');
        $this->assertSame(0, $this->countNodes($html, '//*[contains(@id,"Mobile")]'));
    }

    public function test_offcanvas_has_mobile_ids_and_links_navigate_without_data_bs_dismiss(): void
    {
        $this->deny([]);
        $html = $this->nav('offcanvas');

        // O Bootstrap faz preventDefault() em <a data-bs-dismiss>: o menu fechava e a navegação nunca
        // acontecia (bug do menu mobile). Os links só navegam; o offcanvas some porque a página recarrega
        // (o admin não usa wire:navigate). O `data-bs-dismiss` fica apenas no botão de fechar do layout.
        $this->assertSame(16, $this->countNodes($html, '//a[contains(@class,"nav-link") and @href]'));
        $this->assertSame(0, $this->countNodes($html, '//a[@data-bs-dismiss]'));
        $this->assertSame(0, $this->countNodes($html, '//*[@data-bs-dismiss]'));
        $this->assertSame(0, $this->countNodes($html, '//a[contains(@class,"nav-link")][@data-bs-toggle or @data-bs-target]'));
        foreach (['Operacao', 'Catalogo', 'Config'] as $key) {
            $this->assertSame(1, $this->countNodes($html, "//div[@id='menu{$key}Mobile']"));
            $this->assertSame(1, $this->countNodes($html, "//button[@data-bs-target='#collapse{$key}Mobile']"));
            $this->assertSame(1, $this->countNodes($html, "//div[@id='collapse{$key}Mobile' and @data-bs-parent='#menu{$key}Mobile']"));
        }
    }

    public function test_both_modes_render_the_same_items_in_the_same_order(): void
    {
        $this->deny([]);

        $this->assertSame($this->linkTexts($this->nav('sidebar')), $this->linkTexts($this->nav('offcanvas')));
    }

    public function test_ability_protected_items_are_hidden_in_both_modes(): void
    {
        $this->deny(['managePaymentMethods', 'updateSlug']);

        foreach (['sidebar', 'offcanvas'] as $mode) {
            $texts = $this->linkTexts($this->nav($mode));

            $this->assertNotContains('Pagamentos', $texts, $mode);
            $this->assertNotContains('URL/Slug', $texts, $mode);
            $this->assertContains('SEO', $texts, $mode);
            $this->assertCount(14, $texts, $mode);
        }
    }

    public function test_a_new_registered_item_shows_up_in_both_modes_and_the_protected_one_only_when_allowed(): void
    {
        $registered = new AdminNavigation([
            ...AdminNavigation::definition(),
            new AdminNavSection('Mkt', 'Marketing', 'fa-solid fa-bullhorn', [
                new AdminNavItem('Cupons', 'tenant.admin.seo', 'fa-solid fa-ticket'),
                new AdminNavItem('Campanhas', 'tenant.admin.seo', 'fa-solid fa-flag', can: 'manageCampaigns'),
            ]),
        ]);
        $this->app->instance(AdminNavigation::class, $registered);

        $this->deny(['manageCampaigns']);
        foreach (['sidebar', 'offcanvas'] as $mode) {
            $html = $this->nav($mode);
            $this->assertContains('Cupons', $this->linkTexts($html), "{$mode}: item novo");
            $this->assertNotContains('Campanhas', $this->linkTexts($html), "{$mode}: item protegido");
            $this->assertSame(1, $this->countNodes($html, '//i[contains(@class,"fa-ticket")]'), $mode);
            $this->assertStringContainsString('Marketing', $html);
        }

        $this->deny([]);
        foreach (['sidebar', 'offcanvas'] as $mode) {
            $this->assertContains('Campanhas', $this->linkTexts($this->nav($mode)), "{$mode}: com permissão");
        }
    }

    public function test_links_point_at_the_tenant_routes(): void
    {
        $this->deny([]);
        $html = $this->nav('sidebar');

        $hrefs = [];
        foreach ($this->xpath($html)->query('//a[contains(@class,"nav-link")]') as $a) {
            assert($a instanceof \DOMElement);
            $hrefs[] = parse_url($a->getAttribute('href'), PHP_URL_PATH);
        }

        $this->assertSame('/loja-x/admin', $hrefs[0]);
        $this->assertContains('/loja-x/admin/pagamentos', $hrefs);
        $this->assertContains('/loja-x/admin/configuracoes', $hrefs);
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AdminNav('drawer');
    }

    public function test_markup_has_no_livewire_marker_inside_tags(): void
    {
        $this->deny([]);
        view()->share('currentTenant', $this->tenant());

        foreach (['sidebar', 'offcanvas'] as $mode) {
            $html = $this->renderInsideLivewire('<x-admin-nav mode="'.$mode.'" />');
            $this->assertNoMarkerInsideTags($html);
        }
    }

    public function test_store_actions_render_ids_per_mode_and_respect_the_ability(): void
    {
        view()->share('currentTenant', $this->tenant());
        $this->deny([]);

        $desktop = $this->render('<x-admin-store-actions />');
        $mobile = $this->render('<x-admin-store-actions mode="offcanvas" />');

        $this->assertSame(1, $this->countNodes($desktop, '//button[@id="storeStatusBtn" and @data-store-status-toggle]/i[@id="storeStatusIcon"]'));
        $this->assertSame(1, $this->countNodes($mobile, '//button[@id="storeStatusBtnMobile" and @data-store-status-toggle]/i[@id="storeStatusIconMobile"]'));
        $this->assertSame('/loja-x/admin/toggle-store-status', $this->first($desktop, '//button[@id="storeStatusBtn"]')->getAttribute('data-store-status-url'));
        $this->assertSame('Loja aberta - Clique para fechar', $this->first($desktop, '//button[@id="storeStatusBtn"]')->getAttribute('title'));
        $this->assertSame(1, $this->countNodes($desktop, '//a[@target="_blank" and @href="/loja-x"]'));

        $this->deny(['toggleStoreStatus']);
        $denied = $this->render('<x-admin-store-actions />');
        $this->assertSame(0, $this->countNodes($denied, '//button'));
        $this->assertSame(1, $this->countNodes($denied, '//a[@href="/loja-x"]'), 'link da loja não depende da ability');
    }

    public function test_store_actions_reflect_a_paused_store(): void
    {
        view()->share('currentTenant', $this->tenant()->forceFill(['settings' => ['business_hours' => ['paused' => true]]]));
        $this->deny([]);

        $btn = $this->first($this->render('<x-admin-store-actions />'), '//button[@id="storeStatusBtn"]');

        $this->assertContains('btn-outline-danger', $this->classes($btn));
        $this->assertSame('Loja fechada - Clique para abrir', $btn->getAttribute('title'));
        $this->assertSame(1, substr_count($this->render('<x-admin-store-actions />'), 'fa-lock"'));
    }

    public function test_account_footer_has_theme_toggle_ids_per_mode_and_logout(): void
    {
        view()->share('currentTenant', $this->tenant());

        $desktop = $this->render('<x-admin-account />');
        $mobile = $this->render('<x-admin-account mode="offcanvas" />');

        $this->assertSame(1, $this->countNodes($desktop, '//button[@id="themeToggleBtn" and @aria-label="Alternar tema"]/i[@id="themeToggleIcon" and contains(@class,"fa-moon")]'));
        $this->assertSame(1, $this->countNodes($mobile, '//button[@id="themeToggleBtnMobile"]/i[@id="themeToggleIconMobile"]'));
        $this->assertSame(1, $this->countNodes($desktop, '//form[@method="POST" and substring(@action, string-length(@action) - 19) = "/loja-x/admin/logout"]//input[@name="_token"]'));
        $this->assertSame('Sair', trim($this->first($desktop, '//form//button')->textContent));
    }
}
