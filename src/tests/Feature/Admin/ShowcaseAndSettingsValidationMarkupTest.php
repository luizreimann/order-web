<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EncryptionHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E4.2 (Vitrines + Configurações do tenant) — `<x-form-field>` marca o campo inválido.
 *
 * Antes da migração, `@error` mostrava a mensagem (`text-danger`) mas o controle NÃO recebia
 * `is-invalid` (e `BusinessHours` nem exibia o erro). Tudo pelo endpoint HTTP real
 * (`POST /livewire/update`): o HTML devolvido tem o controle `is-invalid` + `aria-invalid` e o
 * `invalid-feedback` com a mensagem como IRMÃO POSTERIOR do controle (exigência do CSS do Bootstrap).
 *
 * Também trava o que a migração NÃO pode ter mexido: contêineres/ids/`data-*` do SortableJS,
 * `@can` das ações e os inputs de segredo do Mercado Pago (Alpine `x-model`, nunca `wire:model`).
 */
class ShowcaseAndSettingsValidationMarkupTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase, RendersComponents;

    private Tenant $tenant;

    private Showcase $showcase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = Tenant::factory()->create([
            'slug' => 'tenant-a',
            'is_active' => true,
            'settings' => ['payment' => ['mercadopago' => [
                'public_key' => 'APP_USR-public',
                'access_token' => EncryptionHelper::encryptIfNeeded('APP_USR-1234567890-SECRET-9876'),
                'webhook_secret' => EncryptionHelper::encryptIfNeeded('whsec_ABCDEFGHIJ-4321'),
            ]]],
        ]);
        Category::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->showcase = Showcase::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'custom']);

        $this->actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  list<mixed>  $params
     */
    private function callAndRender(string $url, string $component, array $updates, string $method = 'save', array $params = []): string
    {
        $page = $this->get($url)->assertOk();

        $response = $this->callLivewire($this->snapshotFor($page->getContent(), $component), $method, $params, $updates);

        return $this->html($response);
    }

    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }

    private function assertMarked(string $html, string $id): void
    {
        $control = $this->first($html, "//*[@id='{$id}']");
        $this->assertContains('is-invalid', $this->classes($control), "campo [{$id}] sem is-invalid");
        $this->assertSame('true', $control->getAttribute('aria-invalid'), "campo [{$id}] sem aria-invalid");

        $feedback = $this->first($html, "//*[@id='{$id}']/following-sibling::div[contains(@class,'invalid-feedback')]");
        $this->assertNotSame('', trim($feedback->textContent), "campo [{$id}] sem mensagem");
    }

    // ------------------------------------------------------------------
    // Vitrines
    // ------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function showcaseInvalidInputs(): array
    {
        return [
            'name vazio' => [['name' => ''], ['name']],
            'slug fora do padrão' => [['slug' => 'Slug Inválido!'], ['slug']],
            'max_items acima do máximo (D5)' => [['max_items' => 99], ['max_items']],
            'max_items zero' => [['max_items' => 0], ['max_items']],
            'display_mode inexistente' => [['display_mode' => 'x'], ['display_mode']],
            'categoria obrigatória no tipo category' => [['type' => 'category', 'category_id' => null], ['category_id']],
            'vários campos de uma vez' => [['name' => '', 'slug' => 'Slug Inválido!', 'max_items' => 99], ['name', 'slug', 'max_items']],
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  list<string>  $ids
     */
    #[DataProvider('showcaseInvalidInputs')]
    public function test_showcase_form_marks_each_invalid_field(array $updates, array $ids): void
    {
        $html = $this->callAndRender("/tenant-a/admin/vitrines/{$this->showcase->id}/editar", 'admin.showcases.form', $updates);

        foreach ($ids as $id) {
            $this->assertMarked($html, $id);
        }
        $this->assertSame(count($ids), $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
        $this->assertSame(count($ids), $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertStringNotContainsString('text-danger', $html);
    }

    public function test_showcase_custom_products_group_shows_visible_feedback_for_a_nested_error(): void
    {
        // `selectedProducts.0` (e não `selectedProducts`): o `@error` antigo não exibia isto.
        $html = $this->callAndRender("/tenant-a/admin/vitrines/{$this->showcase->id}/editar", 'admin.showcases.form', ['selectedProducts' => ['nao-uuid']]);

        $feedback = $this->first($html, '//div[contains(@class,"invalid-feedback")]');
        $this->assertContains('d-block', $this->classes($feedback));
        $this->assertStringContainsString('selectedProducts.0', $feedback->textContent);
        $this->assertSame(0, $this->countNodes($html, '//input[@type="checkbox"][contains(@class,"is-invalid")]'));
    }

    public function test_showcase_valid_save_has_no_invalid_markup(): void
    {
        $html = $this->callAndRender("/tenant-a/admin/vitrines/{$this->showcase->id}/editar", 'admin.showcases.form', ['name' => 'Nova vitrine']);

        $this->assertStringNotContainsString('is-invalid', $html);
        $this->assertStringNotContainsString('invalid-feedback', $html);
    }

    public function test_showcase_form_keeps_conditional_fields_and_the_all_type_rules(): void
    {
        $all = (string) $this->get('/tenant-a/admin/vitrines/nova')->assertOk()->getContent();
        $this->assertSame(1, $this->countNodes($all, "//input[@id='max_items'][@disabled]"));
        $this->assertStringContainsString('Tipo "Todos" exibe até 100 produtos.', html_entity_decode($all));
        $this->assertSame(0, $this->countNodes($all, "//*[@id='category_id']"));
        $this->assertSame(0, $this->countNodes($all, '//input[starts-with(@id,"product_")]'));

        $category = $this->callAndRender('/tenant-a/admin/vitrines/nova', 'admin.showcases.form', ['type' => 'category', 'name' => '']);
        $this->assertSame(1, $this->countNodes($category, "//select[@id='category_id']"));
        $this->assertSame(0, $this->countNodes($category, "//input[@id='max_items'][@disabled]"));
        $this->assertSame(0, $this->countNodes($category, '//input[starts-with(@id,"product_")]'));

        Product::factory()->count(2)->create(['tenant_id' => $this->tenant->id]);
        $custom = $this->callAndRender('/tenant-a/admin/vitrines/nova', 'admin.showcases.form', ['type' => 'custom', 'name' => '']);
        $this->assertSame(2, $this->countNodes($custom, '//input[@type="checkbox"][starts-with(@id,"product_")][@*[name()="wire:model.defer"]="selectedProducts"]'));
        $this->assertSame(0, $this->countNodes($custom, "//*[@id='category_id']"));
    }

    public function test_showcase_index_keeps_the_sortable_container_and_authorization(): void
    {
        Showcase::factory()->create(['tenant_id' => $this->tenant->id]);

        $html = (string) $this->get('/tenant-a/admin/vitrines')->assertOk()->getContent();

        $this->assertSame(1, $this->countNodes($html, "//div[@id='showcaseList']"));
        $this->assertSame(2, $this->countNodes($html, "//div[@id='showcaseList']/div[@data-id]/div[contains(@class,'drag-handle')]"));
        $this->assertSame(2, $this->countNodes($html, "//div[@id='showcaseList']//button[@*[name()='wire:confirm']]"));
        $script = html_entity_decode($html, ENT_QUOTES);
        $this->assertStringContainsString('Sortable.create(el', $script);
        $this->assertStringContainsString("\$wire.call('updateOrder', ids)", $script);

        Gate::before(fn ($user, $ability) => in_array($ability, ['update', 'delete'], true) ? false : null);

        $denied = (string) $this->get('/tenant-a/admin/vitrines')->assertOk()->getContent();
        $this->assertSame(0, $this->countNodes($denied, "//div[@id='showcaseList']//button"));
        $this->assertSame(2, $this->countNodes($denied, "//div[@id='showcaseList']//a[contains(@class,'btn')]"));
    }

    // ------------------------------------------------------------------
    // Configurações do tenant
    // ------------------------------------------------------------------

    /** @return array<string, array{string, string, array<string, mixed>, list<string>}> */
    public static function settingsInvalidInputs(): array
    {
        $long = fn (int $n): string => str_repeat('a', $n);

        return [
            'settings: slug fora do padrão' => ['configuracoes', 'admin.tenant.settings', ['slug' => 'Slug Inválido!'], ['slug']],
            'settings: slug reservado' => ['configuracoes', 'admin.tenant.settings', ['slug' => 'admin'], ['slug']],
            'settings: slug curto demais' => ['configuracoes', 'admin.tenant.settings', ['slug' => 'ab'], ['slug']],
            'seo: meta_title longo' => ['seo', 'admin.tenant.seo-settings', ['meta_title' => $long(61)], ['meta_title']],
            'seo: meta_description longa' => ['seo', 'admin.tenant.seo-settings', ['meta_description' => $long(161)], ['meta_description']],
            'seo: GA id inválido' => ['seo', 'admin.tenant.seo-settings', ['google_analytics_id' => 'UA-123'], ['google_analytics_id']],
            'seo: três de uma vez' => ['seo', 'admin.tenant.seo-settings', ['meta_title' => $long(61), 'meta_description' => $long(161), 'google_analytics_id' => 'x'], ['meta_title', 'meta_description', 'google_analytics_id']],
            'store-info: textos longos' => ['informacoes', 'admin.tenant.store-info', ['short_description' => $long(161), 'full_description' => $long(2001)], ['short_description', 'full_description']],
            'contact: whatsapp e email' => ['contato', 'admin.tenant.contact-settings', ['whatsapp' => '123', 'email' => 'x'], ['whatsapp', 'email']],
            'address: url e estado' => ['endereco', 'admin.tenant.address-settings', ['state' => 'SPX', 'google_maps_url' => 'nao-url'], ['state', 'google_maps_url']],
            'delivery: negativos' => ['entrega', 'admin.tenant.delivery-settings', ['minimum_order' => -1, 'estimated_time_minutes' => 999], ['minimum_order', 'estimated_time_minutes']],
            'social: urls' => ['redes-sociais', 'admin.tenant.social-links', ['instagram' => 'x', 'youtube' => 'y'], ['instagram', 'youtube']],
            'customization: nome vazio e fonte' => ['personalizacao', 'admin.tenant.customization', ['name' => '', 'font_family' => 'Evil; }'], ['name', 'font_family']],
            'business-hours: horário inválido (antes o erro nem aparecia)' => ['horarios', 'admin.tenant.business-hours', ['enabled' => true, 'schedule.monday.open' => 'xx', 'schedule.sunday.close' => '99:99'], ['schedule_monday_open', 'schedule_sunday_close']],
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  list<string>  $ids
     */
    #[DataProvider('settingsInvalidInputs')]
    public function test_settings_screens_mark_each_invalid_field(string $path, string $component, array $updates, array $ids): void
    {
        $html = $this->callAndRender("/tenant-a/admin/{$path}", $component, $updates);

        foreach ($ids as $id) {
            $this->assertMarked($html, $id);
        }
        $this->assertSame(count($ids), $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
        $this->assertSame(count($ids), $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertStringNotContainsString('text-danger small', $html);
    }

    public function test_settings_slug_feedback_stays_inside_the_input_group_and_is_visible(): void
    {
        $html = $this->callAndRender('/tenant-a/admin/configuracoes', 'admin.tenant.settings', ['slug' => 'admin']);

        $this->first($html, "//div[contains(@class,'input-group')][contains(@class,'has-validation')]/input[@id='slug'][contains(@class,'is-invalid')]");
        $this->first($html, "//label[@for='slug']");
        $this->assertSame(
            'Este slug é reservado.',
            trim($this->first($html, "//*[@id='slug']/following-sibling::div[contains(@class,'invalid-feedback')]")->textContent),
        );
    }

    public function test_customization_color_fields_mark_the_text_input_and_show_group_feedback(): void
    {
        $html = $this->callAndRender('/tenant-a/admin/personalizacao', 'admin.tenant.customization', ['primary_color' => 'red', 'secondary_color' => 'blue']);

        foreach (['primary_color', 'secondary_color'] as $id) {
            $this->assertContains('is-invalid', $this->classes($this->first($html, "//input[@id='{$id}']")), $id);
            // o seletor de cor (type=color) não é marcado
            $this->assertSame(0, $this->countNodes($html, "//input[@type='color'][contains(@class,'is-invalid')]"));
        }
        $this->assertSame(2, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")][contains(@class,"d-block")]'));
    }

    public function test_customization_keeps_one_dynamic_theme_style_and_the_upload_previews(): void
    {
        $this->tenant->update(['logo_path' => 'tenants/x/logo.png', 'settings' => ['theme' => ['banner_path' => 'tenants/x/b.png', 'favicon_path' => 'tenants/x/f.png']]]);

        $html = (string) $this->get('/tenant-a/admin/personalizacao')->assertOk()->getContent();

        // Só o container do preview define o tema (font-family e as duas custom
        // properties, já sanitizadas por TenantTheme); botões e link do preview
        // leem essas variáveis por classe, sem repetir style= inline.
        $this->assertSame(1, $this->countNodes($html, '//main//*[@style]'));
        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"tenant-preview-btn-primary")]'));
        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"tenant-preview-btn-secondary")]'));
        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"tenant-preview-link")]'));
        $this->assertStringContainsString('--tenant-primary:', $html);
        foreach (['removeLogo', 'removeFavicon', 'removeBanner'] as $action) {
            $this->assertSame(1, $this->countNodes($html, "//button[@*[name()='wire:click']='{$action}']"), $action);
        }
        $this->assertSame(3, $this->countNodes($html, '//input[@type="file"][@*[name()="wire:model"]]'));
    }

    public function test_valid_settings_save_has_no_invalid_markup(): void
    {
        $html = $this->callAndRender('/tenant-a/admin/seo', 'admin.tenant.seo-settings', ['meta_title' => 'Minha loja', 'google_analytics_id' => 'G-ABCD1234']);

        $this->assertStringNotContainsString('is-invalid', $html);
        $this->assertStringNotContainsString('invalid-feedback', $html);
    }

    public function test_business_hours_grid_and_pause_toggle_are_preserved(): void
    {
        $this->tenant->update(['settings' => ['business_hours' => ['enabled' => true, 'auto_close' => true, 'paused' => false]]]);

        $html = (string) $this->get('/tenant-a/admin/horarios')->assertOk()->getContent();

        $this->assertSame(7, $this->countNodes($html, '//input[@type="time"][contains(@class,"form-control-sm")][starts-with(@id,"schedule_")][contains(@id,"_open")]'));
        $this->assertSame(7, $this->countNodes($html, '//input[@type="checkbox"][starts-with(@id,"closed_")]'));
        $this->assertSame(1, $this->countNodes($html, "//button[@*[name()='wire:click']='togglePause'][contains(@class,'btn-warning')]"));
        $this->assertSame(1, $this->countNodes($html, "//input[@id='auto_close'][@type='checkbox']"));
    }

    // ------------------------------------------------------------------
    // Pagamentos (E3.6: segredos write-only)
    // ------------------------------------------------------------------

    public function test_payment_method_fields_are_marked_invalid(): void
    {
        $html = $this->callAndRender('/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', [
            'payment_methods.0.label' => '',
            'payment_methods.0.instructions' => str_repeat('i', 1501),
        ], 'saveMethods');

        $this->assertMarked($html, 'payment_methods_0_label');
        $this->assertMarked($html, 'payment_methods_0_instructions');
    }

    public function test_integration_public_key_is_marked_invalid(): void
    {
        $html = $this->callAndRender('/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', [
            'activeTab' => 'integrations',
            'mp_public_key' => str_repeat('k', 256),
        ], 'saveIntegrations', ['', '']);

        $this->assertMarked($html, 'mp_public_key');
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
        $this->assertSecretInputsAreAlpineOnly($html);
    }

    public function test_integration_secret_errors_are_marked_invalid_and_secrets_stay_out_of_wire_model(): void
    {
        // os segredos chegam como PARÂMETRO da action; a falha de validação deles é adicionada à mão
        $html = $this->callAndRender('/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', [
            'activeTab' => 'integrations',
        ], 'saveIntegrations', [str_repeat('a', 300), str_repeat('b', 300)]);

        foreach (['mp_access_token', 'mp_webhook_secret'] as $id) {
            $this->assertMarked($html, $id);
        }
        $this->assertStringNotContainsString(str_repeat('a', 300), $html);
        $this->assertStringNotContainsString(str_repeat('b', 300), $html);
        $this->assertSecretInputsAreAlpineOnly($html);
    }

    public function test_secret_inputs_are_alpine_only_password_fields_in_the_initial_render(): void
    {
        $tab = $this->callAndRender('/tenant-a/admin/pagamentos', 'admin.tenant.payment-settings', ['activeTab' => 'integrations'], 'setTab', ['integrations']);

        $this->assertSecretInputsAreAlpineOnly($tab);

        // a máscara e o "Remover" (com confirmação) continuam
        $this->assertSame(2, $this->countNodes($tab, "//button[@*[name()='wire:confirm']][contains(@class,'btn-link')]"));
        $this->assertSame(1, $this->countNodes($tab, "//button[@*[name()='wire:click']='clearAccessToken']"));
        $this->assertSame(1, $this->countNodes($tab, "//button[@*[name()='wire:click']='clearWebhookSecret']"));
        $this->assertSame(1, $this->countNodes($tab, "//button[@*[name()='x-on:click']='\$wire.testMercadoPagoConnection(mpAccessToken)']"));
        $this->assertStringContainsString('$wire.saveIntegrations(mpAccessToken, mpWebhookSecret)', html_entity_decode($tab));
    }

    private function assertSecretInputsAreAlpineOnly(string $html): void
    {
        foreach (['mp_access_token' => 'mpAccessToken', 'mp_webhook_secret' => 'mpWebhookSecret'] as $id => $model) {
            $input = $this->first($html, "//input[@id='{$id}']");
            $this->assertSame('password', $input->getAttribute('type'), "{$id} deixou de ser password");
            $this->assertSame($model, $input->getAttribute('x-model'), "{$id} perdeu o x-model");
            $this->assertSame('new-password', $input->getAttribute('autocomplete'));
            $this->assertFalse($input->hasAttribute('name'), "{$id} ganhou name");
            foreach ($input->attributes as $attribute) {
                $this->assertStringStartsNotWith('wire:model', $attribute->nodeName, "{$id} ganhou {$attribute->nodeName}: o segredo iria para o estado do Livewire");
            }
        }
    }

    public function test_payment_methods_keep_the_sortable_containers_and_row_ids(): void
    {
        $html = (string) $this->get('/tenant-a/admin/pagamentos')->assertOk()->getContent();

        $this->assertSame(2, $this->countNodes($html, '//tbody[@data-sortable]'));
        $this->assertGreaterThan(0, $this->countNodes($html, "//tbody[@data-sortable]/tr[@data-method-id][@*[name()='wire:key']]"));
        $this->assertSame(
            $this->countNodes($html, '//tbody[@data-sortable]/tr'),
            $this->countNodes($html, '//tbody[@data-sortable]/tr//*[contains(@class,"sortable-handle")]'),
        );
        $this->assertStringContainsString('@this', file_get_contents(resource_path('views/livewire/admin/tenant/payment-settings.blade.php')));
        $this->assertStringContainsString('reorderMethod(methodId, oldIndex, newIndex)', $html);
        // o preview é só leitura: nenhum campo de segredo na aba de métodos
        $this->assertSame(0, $this->countNodes($html, "//input[@type='password']"));
    }
}
