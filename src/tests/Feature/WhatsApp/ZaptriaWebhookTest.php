<?php

namespace Tests\Feature\WhatsApp;

use App\Http\Middleware\ResolveTenantFromPath;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * `POST /webhooks/zaptria`: assinatura `{timestamp}.{corpo}` no corpo cru.
 * 401 não muda o passo. A mesma entrega não reaplica.
 */
class ZaptriaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'zpt_whsec_test_signing_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zaptria.secret' => self::SECRET]);
    }

    public function test_an_invalid_signature_responds_401_and_does_not_change_the_step(): void
    {
        $tenant = $this->makeTenant();
        $body = $this->encode($this->statusPayload($tenant->id, 'connected', '5511999998888'));

        $this->postRaw($body, '01JDINVALID', signature: 'sha256='.str_repeat('ab', 32))
            ->assertUnauthorized();

        $tenant->refresh();
        $this->assertSame('inactive', data_get($tenant->settings, 'whatsapp.status'));
        $this->assertNull(data_get($tenant->settings, 'whatsapp.number'));
        $this->assertTrue(data_get($tenant->settings, 'whatsapp.order_updates'));
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_an_invalid_signature_does_not_consume_the_delivery(): void
    {
        $tenant = $this->makeTenant();
        $body = $this->encode($this->statusPayload($tenant->id, 'connected', '5511999998888'));
        $delivery = '01JDRETRY';

        $this->postRaw($body, $delivery, signature: 'sha256=dead')->assertUnauthorized();
        $this->postRaw($body, $delivery)->assertNoContent();

        $this->assertSame('connected', data_get($tenant->refresh()->settings, 'whatsapp.status'));
        $this->assertSame('11999998888', data_get($tenant->settings, 'whatsapp.number'));
    }

    public function test_the_inbound_request_canonical_string_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $body = $this->encode($this->statusPayload($tenant->id, 'connected', '5511999998888'));
        $timestamp = (string) time();
        $wrong = 'sha256='.hash_hmac(
            'sha256',
            implode("\n", [$timestamp, 'POST', '/webhooks/zaptria', '', $body]),
            self::SECRET,
        );

        $this->postRaw($body, '01JDWRONGCANON', $timestamp, $wrong)->assertUnauthorized();

        $this->assertSame('inactive', data_get($tenant->refresh()->settings, 'whatsapp.status'));
    }

    public function test_an_empty_secret_rejects_the_webhook(): void
    {
        config(['services.zaptria.secret' => '']);

        $tenant = $this->makeTenant();
        $body = $this->encode($this->statusPayload($tenant->id, 'connected', '5511999998888'));
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, '');

        $this->postRaw($body, '01JDEMPTY', $timestamp, $signature)->assertUnauthorized();

        $this->assertSame('inactive', data_get($tenant->refresh()->settings, 'whatsapp.status'));
    }

    public function test_a_timestamp_outside_three_hundred_seconds_responds_401_and_does_not_change_the_step(): void
    {
        $tenant = $this->makeTenant();
        $connect = $this->encode($this->statusPayload($tenant->id, 'connected', '5511999998888'));

        $this->postRaw($connect, '01JDCLOCKOK', (string) (time() + 300))->assertNoContent();
        $this->assertSame('connected', data_get($tenant->refresh()->settings, 'whatsapp.status'));

        $drop = $this->encode($this->statusPayload($tenant->id, 'disconnected'));
        $this->postRaw($drop, '01JDCLOCKLATE', (string) (time() - 301))->assertUnauthorized();

        $tenant->refresh();
        $this->assertSame('connected', data_get($tenant->settings, 'whatsapp.status'));
        $this->assertSame('11999998888', data_get($tenant->settings, 'whatsapp.number'));
    }

    public function test_a_repeated_delivery_does_not_undo_a_change_made_in_between(): void
    {
        $tenant = $this->makeTenant();
        $body = $this->encode($this->statusPayload($tenant->id, 'connected', '5511999998888'));
        $delivery = '01JDREPLAY';
        $timestamp = (string) time();

        $this->postRaw($body, $delivery, $timestamp)->assertNoContent();
        $this->assertSame('connected', data_get($tenant->refresh()->settings, 'whatsapp.status'));

        $settings = $tenant->settings;
        $this->assertIsArray($settings);
        data_set($settings, 'whatsapp.status', 'error');
        data_set($settings, 'whatsapp.number', '1188887777');
        $tenant->settings = $settings;
        $tenant->save();

        $this->postRaw($body, $delivery, $timestamp)->assertNoContent();

        $tenant->refresh();
        $this->assertSame('error', data_get($tenant->settings, 'whatsapp.status'));
        $this->assertSame('1188887777', data_get($tenant->settings, 'whatsapp.number'));
        $this->assertTrue(data_get($tenant->settings, 'whatsapp.order_updates'));
        $this->assertSame(
            '{loja}: seu pedido #{pedido} foi confirmado.',
            data_get($tenant->settings, 'whatsapp.templates.confirmed'),
        );

        $next = $this->encode($this->statusPayload($tenant->id, 'disconnected', null));
        $this->postRaw($next, '01JDREPLAYNEXT')->assertNoContent();

        $tenant->refresh();
        $this->assertSame('disconnected', data_get($tenant->settings, 'whatsapp.status'));
        $this->assertNull(data_get($tenant->settings, 'whatsapp.number'));
        $this->assertArrayNotHasKey('qr', data_get($tenant->settings, 'whatsapp'));
    }

    public function test_status_changed_updates_the_step_and_the_number_and_keeps_the_switch_and_texts(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $body = $this->encode($this->statusPayload($tenant->id, 'connected', '5511999998888', extra: [
            'qr' => '2@AbCshould-not-be-stored',
            'reason' => 'logout',
        ]));

        $this->postRaw($body, '01JDSTATUS')->assertNoContent();

        $whatsapp = data_get($tenant->refresh()->settings, 'whatsapp');
        $this->assertIsArray($whatsapp);
        $this->assertEqualsCanonicalizing(
            ['status', 'number', 'order_updates', 'templates'],
            array_keys($whatsapp),
        );
        $this->assertSame('connected', $whatsapp['status']);
        $this->assertSame('11999998888', $whatsapp['number']);
        $this->assertTrue($whatsapp['order_updates']);
        $this->assertSame('{loja}: seu pedido #{pedido} foi confirmado.', $whatsapp['templates']['confirmed']);
        $this->assertSame('{loja}: seu pedido #{pedido} foi concluído.', $whatsapp['templates']['done']);
        $this->assertSame('{loja}: seu pedido #{pedido} foi cancelado.', $whatsapp['templates']['cancelled']);
        $this->assertSame('1133334444', data_get($tenant->settings, 'contact.phone'));
        $this->assertSame('11988887777', data_get($tenant->settings, 'contact.whatsapp'));

        $this->assertSame('inactive', data_get($other->refresh()->settings, 'whatsapp.status'));
        $this->assertNull(data_get($other->settings, 'whatsapp.number'));
    }

    public function test_a_missing_number_is_stored_as_null_and_the_qr_is_not_saved(): void
    {
        $tenant = $this->makeTenant(['number' => '11999998888', 'status' => 'connected']);
        $body = $this->encode($this->statusPayload($tenant->id, 'inactive', withNumber: false, extra: [
            'qr' => '2@AbCshould-not-be-stored',
        ]));

        $this->postRaw($body, '01JDNONUMBER')->assertNoContent();

        $whatsapp = data_get($tenant->refresh()->settings, 'whatsapp');
        $this->assertIsArray($whatsapp);
        $this->assertSame('inactive', $whatsapp['status']);
        $this->assertArrayHasKey('number', $whatsapp);
        $this->assertNull($whatsapp['number']);
        $this->assertArrayNotHasKey('qr', $whatsapp);
        $this->assertTrue($whatsapp['order_updates']);
    }

    public function test_status_changed_on_a_store_without_the_block_writes_only_the_step_and_the_number(): void
    {
        $tenant = Tenant::factory()->create([
            'settings' => [
                'contact' => ['phone' => '1133334444'],
            ],
        ]);
        $body = $this->encode($this->statusPayload($tenant->id, 'qr_ready', null));

        $this->postRaw($body, '01JDNEWBLOCK')->assertNoContent();

        $whatsapp = data_get($tenant->refresh()->settings, 'whatsapp');
        $this->assertIsArray($whatsapp);
        $this->assertSame('qr_ready', $whatsapp['status']);
        $this->assertNull($whatsapp['number']);
        $this->assertArrayNotHasKey('order_updates', $whatsapp);
        $this->assertArrayNotHasKey('templates', $whatsapp);
        $this->assertArrayNotHasKey('qr', $whatsapp);
        $this->assertSame('1133334444', data_get($tenant->settings, 'contact.phone'));
    }

    public function test_message_received_does_not_create_a_customer_or_a_login_code(): void
    {
        $tenant = $this->makeTenant();
        $body = $this->encode([
            'event' => 'message.received',
            'id' => '01JDMSG',
            'occurred_at' => '2026-11-24T10:05:00-03:00',
            'tenant' => ['external_id' => $tenant->id],
            'channel' => 'tenant',
            'from' => '5511999998888',
            'from_type' => 'phone',
            'push_name' => 'Maria',
            'text' => 'mensagem-recebida-nao-gravar',
            'wa_message_id' => null,
        ]);

        $this->postRaw($body, '01JDMSGDELIVERY')->assertNoContent();

        $this->assertSame(0, DB::table('customers')->count());
        $this->assertSame(0, DB::table('customer_login_codes')->count());
        $this->assertSame('inactive', data_get($tenant->refresh()->settings, 'whatsapp.status'));
        $this->assertNull(data_get($tenant->settings, 'whatsapp.number'));
        $this->assertStringNotContainsString('mensagem-recebida-nao-gravar', (string) json_encode($tenant->settings));
    }

    public function test_a_lid_sender_is_not_stored_as_a_phone(): void
    {
        $tenant = $this->makeTenant();
        $lid = '123456789012345';
        $body = $this->encode([
            'event' => 'message.received',
            'id' => '01JDLID',
            'occurred_at' => '2026-11-24T10:05:00-03:00',
            'tenant' => ['external_id' => $tenant->id],
            'channel' => 'tenant',
            'from' => $lid,
            'from_type' => 'lid',
            'push_name' => 'Maria',
            'text' => 'oi pelo lid',
            'wa_message_id' => null,
        ]);

        $this->postRaw($body, '01JDLIDDELIVERY')->assertNoContent();

        $this->assertSame(0, DB::table('customers')->count());
        $this->assertSame(0, DB::table('customer_login_codes')->count());
        $this->assertSame(0, DB::table('customers')->where('phone', $lid)->count());
        $this->assertNull(data_get($tenant->refresh()->settings, 'whatsapp.number'));
        $this->assertStringNotContainsString($lid, (string) json_encode($tenant->settings));
    }

    public function test_an_unknown_tenant_responds_204_without_creating_a_tenant(): void
    {
        $before = Tenant::query()->count();
        $missing = (string) Str::uuid();
        $body = $this->encode($this->statusPayload($missing, 'connected', '5511999998888'));

        $this->postRaw($body, '01JDUNKNOWN')->assertNoContent();

        $this->assertSame($before, Tenant::query()->count());
        $this->assertNull(Tenant::query()->find($missing));
    }

    public function test_a_non_uuid_external_id_responds_204_without_changing_a_store(): void
    {
        $tenant = $this->makeTenant();
        $body = $this->encode($this->statusPayload($tenant->slug, 'connected', '5511999998888'));

        $this->postRaw($body, '01JDSLUG')->assertNoContent();

        $this->assertSame('inactive', data_get($tenant->refresh()->settings, 'whatsapp.status'));
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_an_unknown_event_responds_204_without_effect(): void
    {
        $tenant = $this->makeTenant();
        $body = $this->encode([
            'event' => 'instance.qr_updated',
            'tenant' => ['external_id' => $tenant->id],
            'status' => 'qr_ready',
            'number' => '5511999998888',
            'qr' => '2@AbCshould-not-be-stored',
        ]);

        $this->postRaw($body, '01JDUNKNOWNEVENT')->assertNoContent();

        $whatsapp = data_get($tenant->refresh()->settings, 'whatsapp');
        $this->assertIsArray($whatsapp);
        $this->assertSame('inactive', $whatsapp['status']);
        $this->assertNull($whatsapp['number']);
        $this->assertArrayNotHasKey('qr', $whatsapp);
        $this->assertSame(0, DB::table('customers')->count());
    }

    public function test_the_route_is_outside_the_tenant_group(): void
    {
        $route = app('router')->getRoutes()->getByName('webhooks.zaptria');

        $this->assertNotNull($route);
        $this->assertSame('webhooks/zaptria', $route->uri());
        $this->assertContains('POST', $route->methods());

        $middleware = app('router')->gatherRouteMiddleware($route);
        $this->assertNotContains(ResolveTenantFromPath::class, $middleware);
        $this->assertNotContains('tenant', $middleware);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTenant(array $overrides = []): Tenant
    {
        return Tenant::factory()->create([
            'settings' => [
                'contact' => [
                    'phone' => '1133334444',
                    'whatsapp' => '11988887777',
                ],
                'whatsapp' => array_merge([
                    'status' => 'inactive',
                    'number' => null,
                    'order_updates' => true,
                    'templates' => [
                        'confirmed' => '{loja}: seu pedido #{pedido} foi confirmado.',
                        'done' => '{loja}: seu pedido #{pedido} foi concluído.',
                        'cancelled' => '{loja}: seu pedido #{pedido} foi cancelado.',
                    ],
                ], $overrides),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function statusPayload(
        string $tenantId,
        string $status,
        mixed $number = null,
        bool $withNumber = true,
        array $extra = [],
    ): array {
        $payload = array_merge([
            'event' => 'instance.status_changed',
            'id' => '01JDEVENT',
            'occurred_at' => '2026-11-24T10:05:00-03:00',
            'tenant' => ['external_id' => $tenantId],
            'channel' => 'tenant',
            'status' => $status,
            'previous_status' => 'inactive',
        ], $extra);

        if ($withNumber) {
            $payload['number'] = $number;
        } else {
            unset($payload['number']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new RuntimeException('payload');
        }

        return $encoded;
    }

    private function postRaw(
        string $body,
        string $delivery,
        ?string $timestamp = null,
        ?string $signature = null,
    ): TestResponse {
        $timestamp ??= (string) time();
        $signature ??= 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return $this->call(
            'POST',
            '/webhooks/zaptria',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Zaptria-Timestamp' => $timestamp,
                'X-Zaptria-Signature' => $signature,
                'X-Zaptria-Delivery' => $delivery,
            ]),
            $body,
        );
    }
}
