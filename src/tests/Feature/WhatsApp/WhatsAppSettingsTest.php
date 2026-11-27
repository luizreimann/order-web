<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\Role;
use App\Livewire\Admin\Tenant\WhatsAppSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * Tela do bloco `whatsapp`. Não mostra a chave. Com credencial, abrir só consulta o status.
 */
class WhatsAppSettingsTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private const KEY = 'zpt_live_SHOULD_NOT_APPEAR_IN_HTML_8c1e';

    private const SECRET = 'zpt_whsec_SHOULD_NOT_APPEAR_IN_HTML_4b7d';

    private const CONFIRMED = '{loja}: seu pedido #{pedido} foi confirmado.';

    private const DONE = '{loja}: seu pedido #{pedido} foi concluído.';

    private const CANCELLED = '{loja}: seu pedido #{pedido} foi cancelado.';

    private const COMPONENT = 'admin.tenant.whats-app-settings';

    private const NUMBER = '11988887777';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake();
        config([
            'services.zaptria.key' => self::KEY,
            'services.zaptria.secret' => self::SECRET,
            'services.zaptria.base_url' => 'https://app.zaptria.com.br/api/v1/partner',
        ]);

        $this->tenant = Tenant::factory()->create([
            'slug' => 'loja-a',
            'name' => 'Carol Doces',
            'is_active' => true,
            'settings' => ['contact' => $this->contact()],
        ]);
        $this->actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
        app(TenantContext::class)->set($this->tenant);
    }

    /** @return array<string, mixed> */
    private function contact(): array
    {
        return [
            'phone' => '1133334444',
            'whatsapp' => '11999990000',
            'email' => 'loja@example.com',
            'whatsapp_message' => 'Oi',
            'show_whatsapp_button' => true,
        ];
    }

    /** @param  array<string, mixed>  $whatsapp */
    private function putWhatsapp(array $whatsapp): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['whatsapp'] = $whatsapp;
        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();
    }

    private function screen(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(WhatsAppSettings::class, ['tenant' => $this->tenant]);
    }

    public function test_a_missing_block_starts_off_with_the_default_texts_and_hides_the_key(): void
    {
        $response = $this->get(route('tenant.admin.whatsapp', ['tenant' => 'loja-a']))
            ->assertOk()
            ->assertSee('Sem número')
            ->assertDontSee(self::KEY)
            ->assertDontSee(self::SECRET)
            ->assertSee('Fornecido por');

        $html = (string) $response->getContent();
        $state = $this->pageState($html);

        $this->assertFalse($state['order_updates']);
        $this->assertSame(self::CONFIRMED, $state['confirmed']);
        $this->assertSame(self::DONE, $state['done']);
        $this->assertSame(self::CANCELLED, $state['cancelled']);
        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsString(self::SECRET, $html);

        $screen = $this->screen()
            ->assertSet('order_updates', false)
            ->assertSet('confirmed', self::CONFIRMED)
            ->assertSet('done', self::DONE)
            ->assertSet('cancelled', self::CANCELLED)
            ->assertDontSee(self::KEY);

        $this->assertDoesNotMatchRegularExpression(
            '/<input\b(?=[^>]*wire:model="order_updates")(?=[^>]*\bchecked\b)[^>]*>/',
            $screen->html(),
        );
        $this->assertArrayNotHasKey('whatsapp', $this->tenant->refresh()->settings);
        $this->assertEquals($this->contact(), $this->tenant->settings['contact']);
        $this->assertOnlyWhatsAppStatusGets();
    }

    /** @return array<string, mixed> */
    private function pageState(string $html): array
    {
        $snapshot = json_decode($this->snapshotFor($html, self::COMPONENT), true);
        $data = is_array($snapshot) ? ($snapshot['data'] ?? []) : [];

        $state = $this->unwrap(is_array($data) ? $data : []);

        return is_array($state) ? $state : [];
    }

    /** @return array<string, array{string, string}> */
    public static function steps(): array
    {
        return [
            'inactive' => ['inactive', 'Sem número'],
            'starting' => ['starting', 'Subindo'],
            'qr_ready' => ['qr_ready', 'Pronto para ler'],
            'connected' => ['connected', 'Conectado'],
            'disconnected' => ['disconnected', 'Caiu'],
            'error' => ['error', 'Falhou'],
            'disconnecting' => ['disconnecting', 'Desconectando'],
        ];
    }

    #[DataProvider('steps')]
    public function test_who_manages_settings_sees_the_saved_step(string $status, string $label): void
    {
        $this->putWhatsapp([
            'status' => $status,
            'number' => self::NUMBER,
            'order_updates' => false,
            'templates' => [
                'confirmed' => 'texto confirmado',
                'done' => 'texto concluido',
                'cancelled' => 'texto cancelado',
            ],
        ]);

        $response = $this->get(route('tenant.admin.whatsapp', ['tenant' => 'loja-a']))
            ->assertOk()
            ->assertSee($label)
            ->assertDontSee(self::KEY);

        if ($status === 'connected') {
            $response->assertSee(self::NUMBER);
        } else {
            $response->assertDontSee(self::NUMBER);
        }

        $this->assertOnlyWhatsAppStatusGets();
    }

    public function test_an_unknown_status_counts_as_no_number_and_hides_the_number(): void
    {
        $this->putWhatsapp([
            'status' => 'nope',
            'number' => self::NUMBER,
        ]);

        $this->get(route('tenant.admin.whatsapp', ['tenant' => 'loja-a']))
            ->assertOk()
            ->assertSee('Sem número')
            ->assertDontSee(self::NUMBER)
            ->assertDontSee(self::KEY);
    }

    public function test_save_writes_only_the_whatsapp_block_and_turning_the_switch_on_keeps_the_texts(): void
    {
        $templates = [
            'confirmed' => 'um',
            'done' => 'dois',
            'cancelled' => 'tres',
        ];
        $this->putWhatsapp([
            'status' => 'connected',
            'number' => self::NUMBER,
            'order_updates' => false,
            'templates' => $templates,
            'pairing_code' => 'nao-entra',
        ]);

        $this->screen()
            ->assertSet('confirmed', 'um')
            ->assertSet('done', 'dois')
            ->assertSet('cancelled', 'tres')
            ->set('order_updates', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('confirmed', 'um')
            ->assertSet('done', 'dois')
            ->assertSet('cancelled', 'tres');

        $settings = $this->tenant->refresh()->settings;
        $whatsapp = $settings['whatsapp'];

        $this->assertEquals($this->contact(), $settings['contact']);
        $this->assertEqualsCanonicalizing(
            ['status', 'number', 'order_updates', 'templates'],
            array_keys($whatsapp),
        );
        $this->assertSame('connected', $whatsapp['status']);
        $this->assertSame(self::NUMBER, $whatsapp['number']);
        $this->assertTrue($whatsapp['order_updates']);
        $this->assertEquals($templates, $whatsapp['templates']);
        $this->assertOnlyWhatsAppStatusGets();
    }

    private function assertOnlyWhatsAppStatusGets(): void
    {
        $recorded = Http::recorded();

        $this->assertNotEmpty($recorded);

        foreach ($recorded as $pair) {
            $request = $pair[0];
            $this->assertInstanceOf(Request::class, $request);
            $this->assertSame('GET', $request->method());
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->assertIsString($path);
            $this->assertTrue(str_ends_with($path, '/whatsapp'));
            $this->assertFalse(str_contains($path, '/whatsapp/'));
        }
    }

    public function test_the_first_save_stores_the_default_texts_and_an_empty_text(): void
    {
        $this->screen()
            ->set('order_updates', true)
            ->set('cancelled', '')
            ->call('save')
            ->assertHasNoErrors();

        $settings = $this->tenant->refresh()->settings;
        $whatsapp = $settings['whatsapp'];

        $this->assertEquals($this->contact(), $settings['contact']);
        $this->assertSame('inactive', $whatsapp['status']);
        $this->assertNull($whatsapp['number']);
        $this->assertTrue($whatsapp['order_updates']);
        $this->assertSame(self::CONFIRMED, $whatsapp['templates']['confirmed']);
        $this->assertSame(self::DONE, $whatsapp['templates']['done']);
        $this->assertSame('', $whatsapp['templates']['cancelled']);

        $this->screen()
            ->assertSet('order_updates', true)
            ->assertSet('confirmed', self::CONFIRMED)
            ->assertSet('done', self::DONE)
            ->assertSet('cancelled', '');
    }

    public function test_a_text_of_500_characters_is_stored(): void
    {
        $text = str_repeat('a', 500);

        $this->screen()
            ->set('confirmed', $text)
            ->call('save')
            ->assertHasNoErrors();

        $settings = $this->tenant->refresh()->settings;

        $this->assertSame($text, $settings['whatsapp']['templates']['confirmed']);
        $this->assertEquals($this->contact(), $settings['contact']);
    }

    /** @return array<string, array{string}> */
    public static function templateFields(): array
    {
        return [
            'confirmed' => ['confirmed'],
            'done' => ['done'],
            'cancelled' => ['cancelled'],
        ];
    }

    #[DataProvider('templateFields')]
    public function test_a_text_longer_than_500_does_not_save_the_block(string $field): void
    {
        $this->putWhatsapp([
            'status' => 'starting',
            'number' => null,
            'order_updates' => false,
            'templates' => [
                'confirmed' => 'curto',
                'done' => 'curto',
                'cancelled' => 'curto',
            ],
            'extra' => 'ainda-esta',
        ]);
        $before = $this->tenant->settings;

        $this->screen()
            ->set($field, str_repeat('b', 501))
            ->call('save')
            ->assertHasErrors($field);

        $this->assertEquals($before, $this->tenant->refresh()->settings);
        $this->assertEquals($this->contact(), $this->tenant->settings['contact']);
    }

    public function test_who_cannot_manage_settings_does_not_open_and_a_post_does_not_save(): void
    {
        $page = $this->get(route('tenant.admin.whatsapp', ['tenant' => 'loja-a']))->assertOk();
        $snapshot = $this->snapshotFor((string) $page->getContent(), self::COMPONENT);
        $before = $this->tenant->refresh()->settings;

        Gate::before(
            fn ($user = null, string $ability = '') => $ability === 'manageSettings' ? false : null,
        );

        $this->get(route('tenant.admin.whatsapp', ['tenant' => 'loja-a']))->assertForbidden();
        $this->callLivewire($snapshot, 'save', [], [
            'order_updates' => true,
            'confirmed' => 'hack',
        ])->assertForbidden();

        $this->assertEquals($before, $this->tenant->refresh()->settings);
        $this->assertArrayNotHasKey('whatsapp', $this->tenant->settings);
    }

    public function test_platform_admin_does_not_open_and_a_post_does_not_save(): void
    {
        $page = $this->get(route('tenant.admin.whatsapp', ['tenant' => 'loja-a']))->assertOk();
        $snapshot = $this->snapshotFor((string) $page->getContent(), self::COMPONENT);
        $before = $this->tenant->refresh()->settings;

        $this->actingAs(User::factory()->create([
            'tenant_id' => null,
            'role' => Role::PlatformAdmin,
        ]), 'tenant_admin');

        $this->get(route('tenant.admin.whatsapp', ['tenant' => 'loja-a']))
            ->assertRedirect(route('tenant.admin.login', ['tenant' => 'loja-a']));
        $this->callLivewire($snapshot, 'save', [], [
            'order_updates' => true,
            'confirmed' => 'hack',
        ])->assertForbidden();

        $this->assertEquals($before, $this->tenant->refresh()->settings);
        $this->assertArrayNotHasKey('whatsapp', $this->tenant->settings);
    }
}
