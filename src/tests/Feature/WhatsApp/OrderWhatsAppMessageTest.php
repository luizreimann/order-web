<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Livewire\Admin\Orders\Show;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsAppNotifier;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mensagem escrita na ficha. Sai do número da loja, sem o interruptor de status.
 */
class OrderWhatsAppMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '(11) 98888-7766';

    private const CANONICAL = '11988887766';

    private const FIRST = 'MSG-FICHA-1 {pedido}';

    private const SECOND = 'MSG-FICHA-2 pode retirar';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake();
        config([
            'services.zaptria.base_url' => 'https://app.zaptria.com.br/api/v1/partner',
            'services.zaptria.key' => 'zpt_live_test_key',
            'services.zaptria.secret' => 'zpt_whsec_test_signing_secret',
        ]);

        $this->tenant = Tenant::factory()->create([
            'name' => 'Carol Doces',
            'slug' => 'loja-a',
            'settings' => [
                'contact' => ['phone' => '1133334444'],
            ],
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]);
        $this->actingAs($admin, 'tenant_admin');
        app(TenantContext::class)->set($this->tenant);
        view()->share('currentTenant', $this->tenant);
        view()->share('tenantAdmin', $admin);
    }

    public function test_the_order_screen_shows_the_whatsapp_field(): void
    {
        $order = $this->order();

        $this->screen($order)
            ->assertSee('Enviar mensagem via WhatsApp')
            ->assertSet('whatsapp_message', '');

        Http::assertNothingSent();
    }

    public function test_with_the_switch_off_each_confirmation_sends_once_and_clears_the_field(): void
    {
        $this->connect(orderUpdates: false);
        $order = $this->order();
        $before = $this->columns($order);
        $settings = $this->tenant->fresh()->settings;

        $screen = $this->screen($order)
            ->set('whatsapp_message', self::FIRST)
            ->call('sendWhatsAppMessage')
            ->assertSet('whatsapp_message', '')
            ->assertSee('Mensagem enviada.')
            ->assertDontSee('Pedido atualizado!')
            ->assertDontSee(self::FIRST);

        $screen->set('whatsapp_message', self::SECOND)
            ->call('sendWhatsAppMessage')
            ->assertSet('whatsapp_message', '')
            ->assertSee('Mensagem enviada.')
            ->assertDontSee(self::FIRST)
            ->assertDontSee(self::SECOND);

        $bodies = $this->bodies();
        $this->assertCount(2, $bodies);
        $this->assertSame([self::FIRST, self::SECOND], array_column($bodies, 'text'));

        foreach ($bodies as $body) {
            $this->assertSame('tenant', $body['sender']);
            $this->assertNotSame('auto', $body['sender']);
            $this->assertSame('generic', $body['purpose']);
            $this->assertSame(self::CANONICAL, $body['to']);
        }

        foreach (Http::recorded() as $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $this->assertStringContainsString('/tenants/'.$this->tenant->id.'/messages', $request->url());
        }

        $this->assertSame($before, $this->columns($order));
        $this->assertSame($settings, $this->tenant->fresh()->settings);
        $this->assertFalse($this->tenant->settings['whatsapp']['order_updates']);
        $this->assertSame('connected', $this->tenant->settings['whatsapp']['status']);
    }

    public function test_five_hundred_characters_send_and_five_hundred_one_do_not(): void
    {
        $this->connect(orderUpdates: false);
        $order = $this->order();
        $screen = $this->screen($order);
        $tooLong = str_repeat('á', 501);

        $screen->set('whatsapp_message', $tooLong)
            ->call('sendWhatsAppMessage')
            ->assertSet('whatsapp_message', $tooLong)
            ->assertDontSee('Mensagem enviada.');

        Http::assertNothingSent();

        $exact = str_repeat('á', 500);
        $screen->set('whatsapp_message', $exact)
            ->call('sendWhatsAppMessage')
            ->assertSet('whatsapp_message', '')
            ->assertSee('Mensagem enviada.');

        $bodies = $this->bodies();
        $this->assertCount(1, $bodies);
        $this->assertSame($exact, $bodies[0]['text']);
        $this->assertSame(500, mb_strlen($bodies[0]['text'], 'UTF-8'));
        $this->assertSame('tenant', $bodies[0]['sender']);
        $this->assertNotSame('auto', $bodies[0]['sender']);
        $this->assertSame('generic', $bodies[0]['purpose']);
    }

    public function test_empty_text_does_not_send(): void
    {
        $this->connect();
        $order = $this->order();
        $before = $this->columns($order);

        $this->screen($order)
            ->set('whatsapp_message', '')
            ->call('sendWhatsAppMessage')
            ->assertSet('whatsapp_message', '')
            ->assertDontSee('Mensagem enviada.')
            ->assertDontSee('não está conectado')
            ->assertDontSee('não saiu');

        Http::assertNothingSent();
        $this->assertSame($before, $this->columns($order));
    }

    #[DataProvider('invalidPhones')]
    public function test_an_invalid_phone_does_not_send(?string $phone): void
    {
        $this->connect(orderUpdates: false);
        $order = $this->order(['customer_phone' => $phone]);
        $before = $this->columns($order);

        $this->screen($order)
            ->set('whatsapp_message', 'MSG-SEM-TELEFONE')
            ->call('sendWhatsAppMessage')
            ->assertSet('whatsapp_message', 'MSG-SEM-TELEFONE')
            ->assertDontSee('Mensagem enviada.');

        Http::assertNothingSent();
        $this->assertSame($before, $this->columns($order));
    }

    /** @return array<string, array{0: ?string}> */
    public static function invalidPhones(): array
    {
        return [
            'nulo' => [null],
            'curto' => ['123'],
            'texto' => ['recepção'],
        ];
    }

    #[DataProvider('disconnectedSteps')]
    public function test_without_a_connected_number_the_screen_says_so_and_the_order_stays(?string $step): void
    {
        if ($step !== null) {
            $this->connect(status: $step);
        }

        $order = $this->order();
        $before = $this->columns($order);
        $settings = $this->tenant->fresh()->settings;

        $this->screen($order)
            ->set('whatsapp_message', 'MSG-DESCONECTADO')
            ->call('sendWhatsAppMessage')
            ->assertSee('não está conectado')
            ->assertSet('whatsapp_message', 'MSG-DESCONECTADO')
            ->assertDontSee('Mensagem enviada.');

        Http::assertNothingSent();
        $this->assertSame($before, $this->columns($order));
        $this->assertSame($settings, $this->tenant->fresh()->settings);
    }

    /** @return array<string, array{0: ?string}> */
    public static function disconnectedSteps(): array
    {
        return [
            'caiu' => ['disconnected'],
            'sem número' => ['inactive'],
            'falhou' => ['error'],
            'subindo' => ['starting'],
            'bloco ausente' => [null],
        ];
    }

    #[DataProvider('sendFailures')]
    public function test_a_failed_send_keeps_the_text_and_says_it_did_not_go_out(string $mode): void
    {
        $this->connect(orderUpdates: false);
        $order = $this->order();
        $before = $this->columns($order);
        $settings = $this->tenant->fresh()->settings;
        $attempts = 0;

        if ($mode === 'null') {
            config(['services.zaptria.key' => '']);
            $this->fakeHttp();
        } elseif ($mode === 'exception') {
            $this->fakeHttp(function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('timed out');
            });
        } elseif ($mode === '409') {
            $this->fakeHttp([
                '*' => Http::response(['error' => ['code' => 'instance_not_connected']], 409),
            ]);
        } else {
            $this->fakeHttp(['*' => Http::response(['error' => 'nope'], 500)]);
        }

        $this->screen($order)
            ->set('whatsapp_message', 'MSG-FALHOU')
            ->call('sendWhatsAppMessage')
            ->assertSet('whatsapp_message', 'MSG-FALHOU')
            ->assertSee('não saiu')
            ->assertDontSee('Mensagem enviada.')
            ->assertDontSee('Pedido atualizado!');

        $this->assertSame($before, $this->columns($order));
        $this->assertSame($settings, $this->tenant->fresh()->settings);
        $this->assertSame('connected', $this->tenant->fresh()->settings['whatsapp']['status']);

        if ($mode === 'null') {
            Http::assertNothingSent();
        } elseif ($mode === 'exception') {
            $this->assertSame(1, $attempts);
        } else {
            $bodies = $this->bodies();
            $this->assertCount(1, $bodies);
            $this->assertSame('tenant', $bodies[0]['sender']);
            $this->assertNotSame('auto', $bodies[0]['sender']);
            $this->assertSame('generic', $bodies[0]['purpose']);
            $this->assertSame(self::CANONICAL, $bodies[0]['to']);
            $this->assertSame('MSG-FALHOU', $bodies[0]['text']);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function sendFailures(): array
    {
        return [
            'erro' => ['500'],
            'exceção' => ['exception'],
            'sem resposta' => ['null'],
            'não conectado no serviço' => ['409'],
        ];
    }

    public function test_an_order_from_another_store_is_not_found(): void
    {
        $this->connect();
        $other = Tenant::factory()->create(['slug' => 'loja-b']);
        $context = app(TenantContext::class);
        $context->set($other);
        $foreign = Order::factory()->create([
            'tenant_id' => $other->id,
            'customer_name' => 'Bia',
            'customer_phone' => self::PHONE,
            'notes' => 'Outra loja',
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
        ]);
        $context->set($this->tenant);
        view()->share('currentTenant', $this->tenant);

        $this->get('/loja-a/admin/pedidos/'.$foreign->id)->assertNotFound();

        Http::assertNothingSent();
        $saved = Order::withoutTenantScope()->findOrFail($foreign->id);
        $this->assertSame('Outra loja', $saved->notes);
        $this->assertSame('Bia', $saved->customer_name);
        $this->assertSame(self::PHONE, $saved->customer_phone);
        $this->assertSame(OrderStatus::PENDING, $saved->status);
    }

    public function test_a_held_lock_blocks_the_second_http_and_the_next_click_sends(): void
    {
        $this->connect(orderUpdates: false);
        $order = $this->order();
        $before = $this->columns($order);
        $text = 'MSG-LOCK-7f3a';
        $lock = Cache::lock(WhatsAppNotifier::manualLockKey((string) $order->id), 20);
        $this->assertTrue($lock->get());

        $screen = $this->screen($order)
            ->set('whatsapp_message', $text)
            ->call('sendWhatsAppMessage')
            ->assertSet('whatsapp_message', $text)
            ->assertDontSee('Mensagem enviada.')
            ->assertDontSee('não saiu');

        Http::assertNothingSent();
        $this->assertSame($before, $this->columns($order));

        $this->assertTrue($lock->release());

        $screen->call('sendWhatsAppMessage')
            ->assertSet('whatsapp_message', '')
            ->assertSee('Mensagem enviada.')
            ->assertDontSee($text);

        $bodies = $this->bodies();
        $this->assertCount(1, $bodies);
        $this->assertSame($text, $bodies[0]['text']);
        $this->assertSame('tenant', $bodies[0]['sender']);
        $this->assertNotSame('auto', $bodies[0]['sender']);
        $this->assertSame('generic', $bodies[0]['purpose']);
        $this->assertSame(self::CANONICAL, $bodies[0]['to']);
        $this->assertSame($before, $this->columns($order));
    }

    private function fakeHttp(mixed $callback = null): void
    {
        // `Http::fake()` acumula stubs. O catch-all do setUp responderia 200 antes do erro.
        Http::swap(new Factory);
        Http::fake($callback);
    }

    private function connect(bool $orderUpdates = true, string $status = 'connected'): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['whatsapp'] = [
            'status' => $status,
            'number' => '11977776666',
            'order_updates' => $orderUpdates,
            'templates' => [
                'confirmed' => 'confirmado {pedido}',
                'done' => 'concluido {pedido}',
                'cancelled' => 'cancelado {pedido}',
            ],
        ];
        $this->tenant->settings = $settings;
        $this->tenant->save();
        $this->tenant->refresh();
    }

    /** @param  array<string, mixed>  $overrides */
    private function order(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => 'dinheiro',
            'customer_name' => 'Ana',
            'customer_phone' => self::PHONE,
            'notes' => 'Sem velas',
        ], $overrides));
    }

    private function screen(Order $order): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Show::class, [
            'tenant' => $this->tenant,
            'orderId' => $order->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function columns(Order $order): array
    {
        $fresh = $order->fresh();
        $this->assertInstanceOf(Order::class, $fresh);

        return [
            'status' => $fresh->status->value,
            'payment_status' => $fresh->payment_status->value,
            'payment_method' => $fresh->payment_method,
            'notes' => $fresh->notes,
            'customer_name' => $fresh->customer_name,
            'customer_phone' => $fresh->customer_phone,
            'customer_id' => $fresh->customer_id,
            'updated_at' => $fresh->updated_at?->toJSON(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function bodies(): array
    {
        $bodies = [];

        foreach (Http::recorded() as $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $body = json_decode($request->body(), true);
            $this->assertIsArray($body);
            $bodies[] = $body;
        }

        return $bodies;
    }
}
