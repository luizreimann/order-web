<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\Support\MercadoPagoOrders;
use Tests\TestCase;

/**
 * Cancelamento pelo pagamento usa o texto de cancelado, depois do commit de fora.
 */
class OrderWhatsAppPaymentTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private const PHONE = '(11) 98888-7766';

    private const CANONICAL = '11988887766';

    private const CHARGE_PHONE = '11999990000';

    private Tenant $tenant;

    private ?int $zaptriaLevel = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zaptria.base_url' => 'https://app.zaptria.com.br/api/v1/partner',
            'services.zaptria.key' => 'zpt_live_test_key',
            'services.zaptria.secret' => 'zpt_whsec_test_signing_secret',
        ]);

        $this->tenant = $this->mpTenant();
        $this->connect();
    }

    public function test_webhook_cancellation_sends_the_store_cancelled_text_once_after_commit(): void
    {
        $paymentId = 'ORDWPP000000001';
        $order = $this->pendingOrder($paymentId);
        $this->fakeRejected($paymentId, $order);

        $this->postMpWebhook($this->tenant, $paymentId, $this->mpSignedHeaders($paymentId))->assertOk();

        // A transação do teste fica no nível 1. Acima disso, a transação da aplicação ainda está aberta.
        $this->assertSame(1, $this->zaptriaLevel);
        $this->assertOneCancelled($order, 'Ana', self::CANONICAL);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);

        $this->postMpWebhook($this->tenant, $paymentId, $this->mpSignedHeaders($paymentId))->assertOk();

        $this->assertCount(1, $this->zaptriaBodies());
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_a_failed_charge_sends_the_store_cancelled_text_once_after_commit(): void
    {
        $product = $this->product($this->tenant);
        $this->fakeDeclinedCharge();

        $this->pay($product)->assertStatus(422);

        $order = Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame(1, $this->zaptriaLevel);
        $this->assertOneCancelled($order, 'Maria', self::CHARGE_PHONE);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_rolling_back_the_outer_webhook_transaction_sends_nothing(): void
    {
        $paymentId = 'ORDWPP000000002';
        $order = $this->pendingOrder($paymentId);
        $this->fakeRejected($paymentId, $order);

        try {
            DB::transaction(function () use ($order, $paymentId) {
                $this->postMpWebhook($this->tenant, $paymentId, $this->mpSignedHeaders($paymentId))->assertOk();
                $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
                $this->assertSame([], $this->zaptriaBodies());

                throw new RuntimeException('desfaz');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('desfaz', $e->getMessage());
        }

        $this->assertNull($this->zaptriaLevel);
        $this->assertSame([], $this->zaptriaBodies());
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }

    public function test_rolling_back_the_outer_charge_transaction_sends_nothing(): void
    {
        $product = $this->product($this->tenant);
        $this->fakeDeclinedCharge();

        try {
            DB::transaction(function () use ($product) {
                $this->pay($product)->assertStatus(422);
                $this->assertSame(OrderStatus::CANCELLED, Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole()->status);
                $this->assertSame([], $this->zaptriaBodies());

                throw new RuntimeException('desfaz');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('desfaz', $e->getMessage());
        }

        $this->assertNull($this->zaptriaLevel);
        $this->assertSame([], $this->zaptriaBodies());
        $this->assertSame(0, Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_an_http_exception_on_webhook_cancellation_keeps_the_order_cancelled(): void
    {
        $paymentId = 'ORDWPP000000003';
        $order = $this->pendingOrder($paymentId);
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts, $paymentId, $order) {
            if (str_contains($request->url(), 'zaptria.com.br')) {
                $attempts++;

                throw new ConnectionException('timed out');
            }

            return Http::response(MercadoPagoOrders::body($paymentId, 'rejected', $order), 200);
        });

        $this->postMpWebhook($this->tenant, $paymentId, $this->mpSignedHeaders($paymentId))->assertOk();

        $this->assertSame(1, $attempts);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_a_failed_response_on_charge_cancellation_keeps_the_order_cancelled(): void
    {
        $product = $this->product($this->tenant);
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            if (str_contains($request->url(), 'zaptria.com.br')) {
                $attempts++;

                return Http::response(['error' => 'nope'], 500);
            }

            return Http::response(MercadoPagoOrders::errorBody('property_value', 'Invalid value for property', ["'$.payer.email' - does not match pattern"]), 400);
        });

        $this->pay($product)->assertStatus(422);

        $this->assertSame(1, $attempts);
        $this->assertSame(OrderStatus::CANCELLED, Order::withoutTenantScope()->where('tenant_id', $this->tenant->id)->sole()->status);
    }

    private function connect(): void
    {
        $this->tenant->name = 'Carol Doces';
        $settings = $this->tenant->settings ?? [];
        $settings['whatsapp'] = [
            'status' => 'connected',
            'number' => '11977776666',
            'order_updates' => true,
            'templates' => [
                'confirmed' => 'confirmado {pedido}',
                'done' => 'concluido {pedido}',
                'cancelled' => '{loja} cancelou {pedido} de {cliente}.',
            ],
        ];
        $this->tenant->settings = $settings;
        $this->tenant->save();
    }

    private function pendingOrder(string $paymentId): Order
    {
        [$order] = $this->orderWithMpPayment($this->tenant, $paymentId);
        $order->customer_name = 'Ana';
        $order->customer_phone = self::PHONE;
        $order->save();

        return $order->fresh();
    }

    private function fakeRejected(string $paymentId, Order $order): void
    {
        $this->zaptriaLevel = null;
        Http::fake(function (Request $request) use ($paymentId, $order) {
            if (str_contains($request->url(), 'zaptria.com.br')) {
                $this->zaptriaLevel = DB::transactionLevel();

                return Http::response(['ok' => true], 200);
            }

            return Http::response(MercadoPagoOrders::body($paymentId, 'rejected', $order), 200);
        });
    }

    private function fakeDeclinedCharge(): void
    {
        $this->zaptriaLevel = null;
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'zaptria.com.br')) {
                $this->zaptriaLevel = DB::transactionLevel();

                return Http::response(['ok' => true], 200);
            }

            return Http::response(MercadoPagoOrders::errorBody('property_value', 'Invalid value for property', ["'$.payer.email' - does not match pattern"]), 400);
        });
    }

    private function pay(Product $product, string $nonce = 'nonce-fixo'): TestResponse
    {
        return $this
            ->withSession($this->checkoutSession($this->tenant, $product, 1, $nonce))
            ->postJson('/'.$this->tenant->slug.'/process_payment', $this->processPaymentBody([
                'customer' => ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => self::CHARGE_PHONE],
            ]));
    }

    private function assertOneCancelled(Order $order, string $customer, string $to): void
    {
        $bodies = $this->zaptriaBodies();
        $this->assertCount(1, $bodies);
        $this->assertSame('Carol Doces cancelou '.$order->number.' de '.$customer.'.', $bodies[0]['text']);
        $this->assertSame('tenant', $bodies[0]['sender']);
        $this->assertNotSame('auto', $bodies[0]['sender']);
        $this->assertSame('order_update', $bodies[0]['purpose']);
        $this->assertSame($to, $bodies[0]['to']);

        foreach ($this->zaptriaRequests() as $request) {
            $this->assertStringContainsString('/tenants/'.$this->tenant->id.'/messages', $request->url());
        }
    }

    /** @return list<Request> */
    private function zaptriaRequests(): array
    {
        $requests = [];

        foreach (Http::recorded() as $pair) {
            /** @var Request $request */
            $request = $pair[0];
            if (! str_contains($request->url(), 'zaptria.com.br')) {
                continue;
            }
            $requests[] = $request;
        }

        return $requests;
    }

    /** @return list<array<string, mixed>> */
    private function zaptriaBodies(): array
    {
        $bodies = [];

        foreach ($this->zaptriaRequests() as $request) {
            $body = json_decode($request->body(), true);
            $this->assertIsArray($body);
            $bodies[] = $body;
        }

        return $bodies;
    }
}
