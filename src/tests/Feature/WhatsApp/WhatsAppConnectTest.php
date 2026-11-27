<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\Role;
use App\Livewire\Admin\Tenant\WhatsAppSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Conectar, consultar e desconectar contra o fake. Nenhum teste abre rede.
 */
class WhatsAppConnectTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'zpt_live_SHOULD_NOT_APPEAR_IN_HTML_8c1e';

    private const SECRET = 'zpt_whsec_SHOULD_NOT_APPEAR_IN_HTML_4b7d';

    private const BASE = 'https://app.zaptria.com.br/api/v1/partner';

    private const NUMBER = '11988887777';

    private const E164 = '5511988887777';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'services.zaptria.key' => self::KEY,
            'services.zaptria.secret' => self::SECRET,
            'services.zaptria.base_url' => self::BASE,
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

    /** @return array<string, string> */
    private function templates(): array
    {
        return [
            'confirmed' => 'texto confirmado',
            'done' => 'texto concluido',
            'cancelled' => 'texto cancelado',
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

    private function endpoint(string $suffix): string
    {
        return self::BASE.'/tenants/'.$this->tenant->id.$suffix;
    }

    /**
     * @param  list<array<string, mixed>>  $gets
     * @param  array<string, array{0: array<string, mixed>, 1: int}>  $posts
     * @param  array<string, mixed>|null  $empty
     */
    private function fakeZaptria(array $gets, array $posts = [], ?array $empty = null): void
    {
        $sequence = Http::sequence();

        foreach ($gets as $body) {
            $sequence->push($body, 200);
        }

        $sequence->whenEmpty(Http::response($empty ?? [
            'status' => 'inactive',
            'number' => null,
            'qr' => null,
        ], 200));

        $stubs = [
            $this->endpoint('/whatsapp') => $sequence,
        ];

        foreach ($posts as $suffix => $spec) {
            $stubs[$this->endpoint($suffix)] = Http::response($spec[0], $spec[1]);
        }

        Http::fake($stubs);
    }

    /** @param  array<string, mixed>  $body */
    private function statusBody(string $status, ?string $number = null, ?string $qr = null): array
    {
        return [
            'status' => $status,
            'number' => $number,
            'qr' => $qr,
        ];
    }

    private function screen(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(WhatsAppSettings::class, ['tenant' => $this->tenant]);
    }

    /** @return list<Request> */
    private function requestsTo(string $suffix): array
    {
        $found = [];

        foreach (Http::recorded() as $pair) {
            $request = $pair[0];

            if (! $request instanceof Request) {
                continue;
            }

            $path = parse_url($request->url(), PHP_URL_PATH);

            if (is_string($path) && str_ends_with($path, $suffix)) {
                $found[] = $request;
            }
        }

        return $found;
    }

    public function test_connect_shows_the_wait_then_the_qr_or_the_code_then_the_number(): void
    {
        // mount, o poll, o set() do telefone e o render do pairing consomem um GET cada.
        $this->fakeZaptria([
            $this->statusBody('inactive'),
            $this->statusBody('qr_ready', null, 'qr-ready-one'),
            $this->statusBody('qr_ready', null, 'qr-ready-one'),
            $this->statusBody('qr_ready', null, 'qr-ready-one'),
            $this->statusBody('connected', self::E164),
        ], [
            '/whatsapp/connect' => [$this->statusBody('starting'), 202],
            '/whatsapp/pairing-code' => [['code' => '4829-1057'], 200],
        ]);

        $screen = $this->screen()
            ->assertSee('Sem número')
            ->assertSee('wire:click="connect"', false)
            ->call('connect')
            ->assertSee('Subindo')
            ->assertSee('wire:poll.3s="refreshConnection"', false)
            ->assertDontSee('wire:click="connect"', false);

        $connects = $this->requestsTo('/whatsapp/connect');
        $this->assertCount(1, $connects);
        $this->assertSame('POST', $connects[0]->method());
        $this->assertSame('{"tenant":{"name":"Carol Doces"}}', $connects[0]->body());

        $screen->call('refreshConnection')
            ->assertSee('Pronto para ler')
            ->assertSee('data-whatsapp-qr="qr-ready-one"', false)
            ->assertSee('wire:model="pairing_phone"', false)
            ->assertSee('data-phone-mask', false)
            ->assertDontSee('wire:click="connect"', false)
            ->set('pairing_phone', '(11) 99999-8888')
            ->call('requestPairingCode')
            ->assertSee('Código para digitar no WhatsApp')
            ->assertSee('4829-1057')
            ->assertSee('data-whatsapp-qr="qr-ready-one"', false);

        $pairing = $this->requestsTo('/whatsapp/pairing-code');
        $this->assertCount(1, $pairing);
        $this->assertSame('{"phone":"11999998888"}', $pairing[0]->body());

        $screen->call('refreshConnection')
            ->assertSee('Conectado')
            ->assertSee(self::NUMBER)
            ->assertSee('Desconectar')
            ->assertDontSee('wire:poll', false)
            ->assertDontSee('wire:click="connect"', false)
            ->assertDontSee('4829-1057')
            ->assertDontSee(self::KEY)
            ->assertDontSee(self::SECRET);

        $this->assertCount(1, $this->requestsTo('/whatsapp/connect'));
        $this->assertSame([], $this->requestsTo('/whatsapp/logout'));

        $whatsapp = $this->tenant->refresh()->settings['whatsapp'];
        $this->assertSame('connected', $whatsapp['status']);
        $this->assertSame(self::NUMBER, $whatsapp['number']);
        $this->assertArrayNotHasKey('qr', $whatsapp);
        $this->assertArrayNotHasKey('pairing_code', $whatsapp);
        $this->assertEquals($this->contact(), $this->tenant->settings['contact']);
        $this->assertStringNotContainsString('qr-ready-one', (string) json_encode($this->tenant->settings));
        $this->assertStringNotContainsString('4829-1057', (string) json_encode($this->tenant->settings));
    }

    /** @return array<string, array{string}> */
    public static function inProgress(): array
    {
        return [
            'starting' => ['starting'],
            'qr_ready' => ['qr_ready'],
            'connected' => ['connected'],
            'disconnecting' => ['disconnecting'],
        ];
    }

    #[DataProvider('inProgress')]
    public function test_connect_is_not_offered_and_does_not_post_again_midway(string $status): void
    {
        $this->putWhatsapp([
            'status' => $status,
            'number' => $status === 'connected' ? self::NUMBER : null,
            'order_updates' => false,
            'templates' => $this->templates(),
        ]);
        $body = $this->statusBody(
            $status,
            $status === 'connected' ? self::E164 : null,
            $status === 'qr_ready' ? 'qr-mid' : null,
        );
        $this->fakeZaptria([$body], [
            '/whatsapp/connect' => [$this->statusBody('starting'), 202],
        ], $body);

        $screen = $this->screen()
            ->assertDontSee('wire:click="connect"', false);

        if (in_array($status, ['starting', 'qr_ready', 'disconnecting'], true)) {
            $screen->assertSee('wire:poll.3s="refreshConnection"', false);
        } else {
            $screen->assertDontSee('wire:poll', false);
        }

        $screen->call('connect');

        $this->assertSame([], $this->requestsTo('/whatsapp/connect'));
        $screen->assertDontSee('wire:click="connect"', false);
        $this->assertSame($status, $this->tenant->refresh()->settings['whatsapp']['status']);
    }

    /** @return array<string, array{string, string}> */
    public static function reconnectable(): array
    {
        return [
            'inactive' => ['inactive', 'Conectar'],
            'disconnected' => ['disconnected', 'Reconectar'],
            'error' => ['error', 'Tentar de novo'],
        ];
    }

    #[DataProvider('reconnectable')]
    public function test_connect_from_no_number_dropped_or_failed_posts_the_store_name(string $status, string $label): void
    {
        $this->putWhatsapp([
            'status' => $status,
            'number' => null,
            'order_updates' => true,
            'templates' => $this->templates(),
        ]);
        $this->fakeZaptria([
            $this->statusBody($status),
        ], [
            '/whatsapp/connect' => [$this->statusBody('starting'), 202],
        ]);

        $this->screen()
            ->assertSee($label)
            ->call('connect')
            ->assertSee('Subindo')
            ->assertDontSee('wire:click="connect"', false);

        $connects = $this->requestsTo('/whatsapp/connect');
        $this->assertCount(1, $connects);
        $this->assertSame('{"tenant":{"name":"Carol Doces"}}', $connects[0]->body());

        $whatsapp = $this->tenant->refresh()->settings['whatsapp'];
        $this->assertSame('starting', $whatsapp['status']);
        $this->assertTrue($whatsapp['order_updates']);
        $this->assertEquals($this->templates(), $whatsapp['templates']);
        $this->assertEquals($this->contact(), $this->tenant->settings['contact']);
    }

    public function test_disconnect_returns_to_no_number(): void
    {
        $this->putWhatsapp([
            'status' => 'connected',
            'number' => self::NUMBER,
            'order_updates' => true,
            'templates' => $this->templates(),
        ]);
        $this->fakeZaptria([
            $this->statusBody('connected', self::E164),
            $this->statusBody('inactive'),
        ], [
            '/whatsapp/logout' => [$this->statusBody('disconnecting'), 202],
        ]);

        $this->screen()
            ->assertSee('Conectado')
            ->assertSee(self::NUMBER)
            ->assertSee('Desconectar')
            ->call('disconnect')
            ->assertSee('Desconectando')
            ->assertDontSee('Desconectar')
            ->assertDontSee(self::NUMBER)
            ->assertDontSee('wire:click="connect"', false)
            ->assertSee('wire:poll.3s="refreshConnection"', false)
            ->call('refreshConnection')
            ->assertSee('Sem número')
            ->assertSee('Conectar')
            ->assertDontSee(self::NUMBER)
            ->assertDontSee('wire:poll', false);

        $logouts = $this->requestsTo('/whatsapp/logout');
        $this->assertCount(1, $logouts);
        $this->assertSame('POST', $logouts[0]->method());
        $this->assertSame('', $logouts[0]->body());

        $whatsapp = $this->tenant->refresh()->settings['whatsapp'];
        $this->assertSame('inactive', $whatsapp['status']);
        $this->assertNull($whatsapp['number']);
        $this->assertTrue($whatsapp['order_updates']);
        $this->assertEquals($this->templates(), $whatsapp['templates']);
        $this->assertEquals($this->contact(), $this->tenant->settings['contact']);
    }

    /** @return array<string, array{string}> */
    public static function withoutSession(): array
    {
        return [
            'inactive' => ['inactive'],
            'starting' => ['starting'],
            'qr_ready' => ['qr_ready'],
            'disconnected' => ['disconnected'],
            'error' => ['error'],
            'disconnecting' => ['disconnecting'],
        ];
    }

    #[DataProvider('withoutSession')]
    public function test_disconnect_without_a_session_does_not_call_logout(string $status): void
    {
        $this->putWhatsapp([
            'status' => $status,
            'number' => null,
            'order_updates' => false,
            'templates' => $this->templates(),
        ]);
        $body = $this->statusBody($status, null, $status === 'qr_ready' ? 'qr-stay' : null);
        $this->fakeZaptria([$body], [
            '/whatsapp/logout' => [$this->statusBody('inactive'), 200],
        ], $body);

        $screen = $this->screen();
        $before = Http::recorded()->count();

        $screen->call('disconnect');

        $this->assertSame([], $this->requestsTo('/whatsapp/logout'));
        $this->assertSame($status, $this->tenant->refresh()->settings['whatsapp']['status']);

        if ($status !== 'qr_ready') {
            $this->assertCount($before, Http::recorded());
        }
    }

    public function test_a_new_qr_replaces_the_previous_one(): void
    {
        $this->putWhatsapp([
            'status' => 'qr_ready',
            'number' => null,
            'order_updates' => true,
            'templates' => $this->templates(),
        ]);
        $this->fakeZaptria([
            $this->statusBody('qr_ready', null, 'qr-old-aaaa'),
            $this->statusBody('qr_ready', null, 'qr-new-bbbb'),
        ]);

        $screen = $this->screen()
            ->assertSee('data-whatsapp-qr="qr-old-aaaa"', false)
            ->call('refreshConnection')
            ->assertSee('data-whatsapp-qr="qr-new-bbbb"', false)
            ->assertDontSee('qr-old-aaaa', false);

        $snapshot = json_encode($screen->snapshot);
        $this->assertIsString($snapshot);
        $this->assertStringNotContainsString('qr-new-bbbb', $snapshot);
        $this->assertStringNotContainsString('qr-old-aaaa', $snapshot);

        $whatsapp = $this->tenant->refresh()->settings['whatsapp'];
        $this->assertArrayNotHasKey('qr', $whatsapp);
        $this->assertStringNotContainsString('qr-new-bbbb', (string) json_encode($whatsapp));
        $this->assertTrue($whatsapp['order_updates']);
        $this->assertEquals($this->templates(), $whatsapp['templates']);
    }

    public function test_a_fresh_mount_without_poll_shows_the_step_the_webhook_would_store(): void
    {
        $this->putWhatsapp([
            'status' => 'connected',
            'number' => self::NUMBER,
            'order_updates' => true,
            'templates' => $this->templates(),
        ]);
        $this->fakeZaptria([
            $this->statusBody('connected', self::E164, 'qr-should-not-stick'),
        ]);

        $screen = $this->screen()
            ->assertSee('Conectado')
            ->assertSee(self::NUMBER)
            ->assertSet('confirmed', 'texto confirmado')
            ->assertSet('order_updates', true);

        $html = $screen->html();
        $this->assertStringNotContainsString('wire:poll', $html);
        $this->assertStringNotContainsString('qr-should-not-stick', $html);
        $this->assertNotEmpty($this->requestsTo('/whatsapp'));

        $whatsapp = $this->tenant->refresh()->settings['whatsapp'];
        $this->assertSame('connected', $whatsapp['status']);
        $this->assertSame(self::NUMBER, $whatsapp['number']);
        $this->assertArrayNotHasKey('qr', $whatsapp);
        $this->assertTrue($whatsapp['order_updates']);
        $this->assertEquals($this->templates(), $whatsapp['templates']);
        $this->assertEquals($this->contact(), $this->tenant->settings['contact']);
    }

    public function test_opening_with_credentials_updates_status_and_number_without_wiping_texts(): void
    {
        $this->putWhatsapp([
            'status' => 'starting',
            'number' => '11911112222',
            'order_updates' => true,
            'templates' => $this->templates(),
            'extra' => 'fica',
        ]);
        $this->fakeZaptria([
            $this->statusBody('connected', self::E164, 'qr-not-stored'),
        ]);

        $this->screen()
            ->assertSee('Conectado')
            ->assertSee(self::NUMBER)
            ->assertDontSee('11911112222')
            ->assertDontSee('Subindo')
            ->assertDontSee('wire:poll', false)
            ->assertSet('confirmed', 'texto confirmado')
            ->assertSet('done', 'texto concluido')
            ->assertSet('cancelled', 'texto cancelado')
            ->assertSet('order_updates', true);

        $whatsapp = $this->tenant->refresh()->settings['whatsapp'];
        $this->assertSame('connected', $whatsapp['status']);
        $this->assertSame(self::NUMBER, $whatsapp['number']);
        $this->assertTrue($whatsapp['order_updates']);
        $this->assertEquals($this->templates(), $whatsapp['templates']);
        $this->assertSame('fica', $whatsapp['extra']);
        $this->assertArrayNotHasKey('qr', $whatsapp);
        $this->assertEquals($this->contact(), $this->tenant->settings['contact']);
    }

    public function test_opening_without_credentials_does_not_call_http(): void
    {
        config([
            'services.zaptria.key' => '',
            'services.zaptria.secret' => '',
            'services.zaptria.base_url' => '',
        ]);
        Http::fake();

        $this->putWhatsapp([
            'status' => 'connected',
            'number' => self::NUMBER,
            'order_updates' => true,
            'templates' => $this->templates(),
        ]);

        $this->screen()
            ->assertSee('Conectado')
            ->assertSee(self::NUMBER)
            ->call('connect')
            ->call('disconnect')
            ->call('refreshConnection')
            ->call('requestPairingCode');

        Http::assertNothingSent();
        $whatsapp = $this->tenant->refresh()->settings['whatsapp'];
        $this->assertSame('connected', $whatsapp['status']);
        $this->assertSame(self::NUMBER, $whatsapp['number']);
        $this->assertTrue($whatsapp['order_updates']);
        $this->assertEquals($this->templates(), $whatsapp['templates']);
    }

    /** @return array<string, array{string, bool}> */
    public static function pollSteps(): array
    {
        return [
            'inactive' => ['inactive', false],
            'starting' => ['starting', true],
            'qr_ready' => ['qr_ready', true],
            'connected' => ['connected', false],
            'disconnected' => ['disconnected', false],
            'error' => ['error', false],
            'disconnecting' => ['disconnecting', true],
        ];
    }

    #[DataProvider('pollSteps')]
    public function test_poll_exists_only_while_starting_reading_or_disconnecting(string $status, bool $polls): void
    {
        $this->putWhatsapp([
            'status' => $status,
            'number' => $status === 'connected' ? self::NUMBER : null,
            'order_updates' => false,
            'templates' => $this->templates(),
        ]);
        $this->fakeZaptria([
            $this->statusBody(
                $status,
                $status === 'connected' ? self::E164 : null,
                $status === 'qr_ready' ? 'qr-poll' : null,
            ),
        ]);

        $html = $this->screen()->html();

        if ($polls) {
            $this->assertStringContainsString('wire:poll.3s="refreshConnection"', $html);
        } else {
            $this->assertStringNotContainsString('wire:poll', $html);
        }

        if ($status === 'qr_ready') {
            $this->assertStringContainsString('data-whatsapp-qr="qr-poll"', $html);
            $this->assertStringContainsString('wire:model="pairing_phone"', $html);
            $this->assertStringContainsString('data-phone-mask', $html);
        }
    }

    public function test_an_invalid_pairing_phone_does_not_call_the_api(): void
    {
        $this->putWhatsapp([
            'status' => 'qr_ready',
            'number' => null,
            'order_updates' => false,
            'templates' => $this->templates(),
        ]);
        $this->fakeZaptria([
            $this->statusBody('qr_ready', null, 'qr-pair'),
            $this->statusBody('qr_ready', null, 'qr-pair'),
        ], [
            '/whatsapp/pairing-code' => [['code' => '4829-1057'], 200],
        ]);

        $this->screen()
            ->set('pairing_phone', 'abc')
            ->call('requestPairingCode')
            ->assertHasErrors('pairing_phone')
            ->assertDontSee('4829-1057');

        $this->assertSame([], $this->requestsTo('/whatsapp/pairing-code'));
    }

    public function test_pairing_outside_qr_ready_does_not_call_the_api(): void
    {
        $this->putWhatsapp([
            'status' => 'connected',
            'number' => self::NUMBER,
            'order_updates' => false,
            'templates' => $this->templates(),
        ]);
        $this->fakeZaptria([
            $this->statusBody('connected', self::E164),
        ], [
            '/whatsapp/pairing-code' => [['code' => '4829-1057'], 200],
        ]);

        $this->screen()
            ->set('pairing_phone', '11999998888')
            ->call('requestPairingCode')
            ->assertDontSee('4829-1057');

        $this->assertSame([], $this->requestsTo('/whatsapp/pairing-code'));
    }
}
