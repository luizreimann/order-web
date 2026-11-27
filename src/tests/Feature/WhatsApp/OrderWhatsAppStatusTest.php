<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aviso quando o status do pedido muda para confirmado, concluído ou cancelado.
 */
class OrderWhatsAppStatusTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '(11) 98888-7766';

    private const CANONICAL = '11988887766';

    private Tenant $tenant;

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zaptria.base_url' => 'https://app.zaptria.com.br/api/v1/partner',
            'services.zaptria.key' => 'zpt_live_test_key',
            'services.zaptria.secret' => 'zpt_whsec_test_signing_secret',
        ]);

        $this->tenant = Tenant::factory()->create([
            'name' => 'Carol Doces',
            'settings' => [
                'contact' => ['phone' => '1133334444'],
            ],
        ]);
        $this->orders = new OrderService;
    }

    public function test_a_new_pending_order_does_not_send(): void
    {
        Http::fake();
        $this->connect();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price_cents' => 1000,
        ]);

        $order = $this->orders->createFromCart($this->tenant, [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'phone' => self::PHONE,
        ], [
            ['product_id' => $product->id, 'qty' => 1],
        ], 'cash_on_delivery');

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }

    public function test_the_switch_off_changes_status_without_http(): void
    {
        Http::fake();
        $this->connect(orderUpdates: false);
        $order = $this->order();

        $saved = $this->update($order, OrderStatus::CONFIRMED, PaymentStatus::PAID, 'pix');

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::CONFIRMED, $saved->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, $saved->fresh()->payment_status);
        $this->assertSame('pix', $saved->fresh()->payment_method);
    }

    public function test_an_empty_template_skips_that_status_and_the_status_still_changes(): void
    {
        Http::fake();
        $this->connect(confirmed: '');
        $order = $this->order();

        $this->update($order, OrderStatus::CONFIRMED);

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);

        $this->update($order->fresh(), OrderStatus::DONE);

        $bodies = $this->bodies();
        $this->assertCount(1, $bodies);
        $this->assertSame('Carol Doces concluiu '.$order->number.'.', $bodies[0]['text']);
        $this->assertSame('tenant', $bodies[0]['sender']);
        $this->assertSame(OrderStatus::DONE, $order->fresh()->status);
    }

    public function test_returning_to_pending_does_not_send(): void
    {
        Http::fake();
        $this->connect();
        $order = $this->order(['status' => OrderStatus::CONFIRMED]);

        $saved = $this->update($order, OrderStatus::PENDING);

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::PENDING, $saved->fresh()->status);
    }

    public function test_saving_the_same_status_does_not_send(): void
    {
        Http::fake();
        $this->connect();
        $order = $this->order(['status' => OrderStatus::CONFIRMED]);

        $saved = $this->update($order, OrderStatus::CONFIRMED, PaymentStatus::PAID, 'pix');

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::CONFIRMED, $saved->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, $saved->fresh()->payment_status);
    }

    #[DataProvider('invalidPhones')]
    public function test_an_invalid_phone_changes_status_without_http(?string $phone): void
    {
        Http::fake();
        $this->connect();
        $order = $this->order(['customer_phone' => $phone]);

        $saved = $this->update($order, OrderStatus::CONFIRMED);

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::CONFIRMED, $saved->fresh()->status);
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
    public function test_a_store_that_is_not_connected_changes_status_without_http(string $step): void
    {
        Http::fake();
        $this->connect(status: $step);
        $order = $this->order();

        $saved = $this->update($order, OrderStatus::CONFIRMED);

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::CONFIRMED, $saved->fresh()->status);
        $this->assertSame($step, $this->tenant->fresh()->settings['whatsapp']['status']);
    }

    /** @return array<string, array{0: string}> */
    public static function disconnectedSteps(): array
    {
        return [
            'caiu' => ['disconnected'],
            'sem número' => ['inactive'],
            'falhou' => ['error'],
        ];
    }

    public function test_confirmed_done_and_cancelled_send_the_store_text_once(): void
    {
        Http::fake();
        $this->connect(
            confirmed: '{loja}: pedido {pedido} de {cliente} confirmado.',
            done: '{loja} concluiu {pedido} para {cliente}.',
            cancelled: '{loja} cancelou {pedido}.',
        );
        $order = $this->order(['customer_name' => 'Ana']);
        $number = (string) $order->number;

        $this->update($order, OrderStatus::CONFIRMED);
        $this->update($order->fresh(), OrderStatus::DONE);
        $this->update($order->fresh(), OrderStatus::CANCELLED);
        $this->update($order->fresh(), OrderStatus::CONFIRMED);

        $bodies = $this->bodies();
        $this->assertCount(4, $bodies);
        $this->assertSame([
            'Carol Doces: pedido '.$number.' de Ana confirmado.',
            'Carol Doces concluiu '.$number.' para Ana.',
            'Carol Doces cancelou '.$number.'.',
            'Carol Doces: pedido '.$number.' de Ana confirmado.',
        ], array_column($bodies, 'text'));

        foreach ($bodies as $body) {
            $this->assertSame('tenant', $body['sender']);
            $this->assertNotSame('auto', $body['sender']);
            $this->assertSame('order_update', $body['purpose']);
            $this->assertSame(self::CANONICAL, $body['to']);
        }

        foreach (Http::recorded() as $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $this->assertStringContainsString('/tenants/'.$this->tenant->id.'/messages', $request->url());
        }

        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
        $settings = $this->tenant->fresh()->settings;
        $this->assertSame('connected', $settings['whatsapp']['status']);
        $this->assertSame('1133334444', $settings['contact']['phone']);
    }

    public function test_an_empty_customer_name_is_left_blank_in_the_store_text(): void
    {
        Http::fake();
        $this->connect(confirmed: 'Olá {cliente}!');
        $order = $this->order(['customer_name' => '']);

        $this->update($order, OrderStatus::CONFIRMED);

        $bodies = $this->bodies();
        $this->assertCount(1, $bodies);
        $this->assertSame('Olá !', $bodies[0]['text']);
        $this->assertSame('tenant', $bodies[0]['sender']);
        $this->assertNotSame('auto', $bodies[0]['sender']);
    }

    public function test_text_outside_one_to_five_hundred_characters_after_substitution_does_not_send(): void
    {
        Http::fake();
        $this->connect(
            confirmed: str_repeat('á', 500),
            done: str_repeat('b', 501),
            cancelled: '{cliente}',
        );
        $order = $this->order(['customer_name' => '']);

        $this->update($order, OrderStatus::CONFIRMED);
        $this->assertSame([str_repeat('á', 500)], array_column($this->bodies(), 'text'));

        $this->update($order->fresh(), OrderStatus::DONE);
        $this->update($order->fresh(), OrderStatus::CANCELLED);

        $this->assertCount(1, $this->bodies());
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_409_instance_not_connected_marks_disconnected_and_does_not_call_again(): void
    {
        $templates = [
            'confirmed' => 'confirmado {pedido}',
            'done' => 'concluido {pedido}',
            'cancelled' => 'cancelado {pedido}',
        ];
        $this->connect(...$templates);
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'instance_not_connected']], 409),
        ]);
        $order = $this->order();

        $this->update($order, OrderStatus::CONFIRMED);

        $bodies = $this->bodies();
        $this->assertCount(1, $bodies);
        $this->assertSame('tenant', $bodies[0]['sender']);
        $this->assertNotSame('auto', $bodies[0]['sender']);
        $this->assertSame('order_update', $bodies[0]['purpose']);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);

        $settings = $this->tenant->fresh()->settings;
        $this->assertSame('disconnected', $settings['whatsapp']['status']);
        $this->assertTrue($settings['whatsapp']['order_updates']);
        $this->assertSame('11977776666', $settings['whatsapp']['number']);
        $this->assertSame($templates['confirmed'], $settings['whatsapp']['templates']['confirmed']);
        $this->assertSame($templates['done'], $settings['whatsapp']['templates']['done']);
        $this->assertSame($templates['cancelled'], $settings['whatsapp']['templates']['cancelled']);
        $this->assertCount(3, $settings['whatsapp']['templates']);
        $this->assertSame('1133334444', $settings['contact']['phone']);

        $this->update($order->fresh(), OrderStatus::DONE);

        $this->assertCount(1, $this->bodies());
        $this->assertSame(OrderStatus::DONE, $order->fresh()->status);
    }

    public function test_another_409_does_not_disconnect_or_retry_or_use_the_platform(): void
    {
        $this->connect();
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'instance_busy']], 409),
        ]);
        $order = $this->order();

        $this->update($order, OrderStatus::CONFIRMED);

        $bodies = $this->bodies();
        $this->assertCount(1, $bodies);
        $this->assertSame('tenant', $bodies[0]['sender']);
        $this->assertNotSame('auto', $bodies[0]['sender']);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
        $this->assertSame('connected', $this->tenant->fresh()->settings['whatsapp']['status']);
    }

    public function test_a_failed_response_does_not_retry_or_undo_the_status(): void
    {
        $this->connect();
        Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);
        $order = $this->order();

        $saved = $this->update($order, OrderStatus::CONFIRMED);

        $this->assertCount(1, $this->bodies());
        $this->assertSame('tenant', $this->bodies()[0]['sender']);
        $this->assertSame(OrderStatus::CONFIRMED, $saved->fresh()->status);
        $this->assertSame('connected', $this->tenant->fresh()->settings['whatsapp']['status']);
        $this->assertTrue($this->tenant->fresh()->settings['whatsapp']['order_updates']);
    }

    public function test_an_http_exception_keeps_the_new_status(): void
    {
        $this->connect();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('timed out');
        });
        $order = $this->order();

        $saved = $this->update($order, OrderStatus::CONFIRMED);

        $this->assertSame(1, $attempts);
        $this->assertSame(OrderStatus::CONFIRMED, $saved->fresh()->status);
        $this->assertSame('connected', $this->tenant->fresh()->settings['whatsapp']['status']);
        $this->assertTrue($this->tenant->fresh()->settings['whatsapp']['order_updates']);
    }

    private function connect(
        bool $orderUpdates = true,
        string $status = 'connected',
        string $confirmed = 'confirmado {pedido}',
        string $done = '{loja} concluiu {pedido}.',
        string $cancelled = 'cancelado {pedido}',
    ): void {
        $settings = $this->tenant->settings ?? [];
        $settings['whatsapp'] = [
            'status' => $status,
            'number' => '11977776666',
            'order_updates' => $orderUpdates,
            'templates' => [
                'confirmed' => $confirmed,
                'done' => $done,
                'cancelled' => $cancelled,
            ],
        ];
        $this->tenant->settings = $settings;
        $this->tenant->save();
    }

    /** @param  array<string, mixed>  $overrides */
    private function order(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => 'cash_on_delivery',
            'customer_name' => 'Ana',
            'customer_phone' => self::PHONE,
        ], $overrides));
    }

    private function update(Order $order, OrderStatus $status, PaymentStatus $payment = PaymentStatus::UNPAID, ?string $method = 'cash_on_delivery'): Order
    {
        return $this->orders->updateOrder($order, $status, $payment, $method);
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
