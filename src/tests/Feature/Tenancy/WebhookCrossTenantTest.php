<?php

namespace Tests\Feature\Tenancy;

use App\Enums\PaymentStatus;
use App\Gateways\MercadoPagoGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\EncryptionHelper;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O webhook de pagamento é o único caminho legitimamente cross-tenant hoje:
 * `POST /webhooks/{gateway}[/{tenantId}]` fica FORA do prefixo `/{tenant:slug}`, logo o
 * `ResolveTenantFromPath` não roda e não há tenant no contexto.
 *
 * Desde E3.3 o processamento roda DENTRO do contexto do tenant resolvido (rota com o id
 * do tenant, ou o dono do pagamento registrado na rota legada sem tenant), e o único
 * opt-out do scope é a descoberta do dono do pagamento
 * (`PaymentWebhookProcessor::tenantOfRecordedPayment`, comentado CROSS-TENANT DELIBERADO).
 * Estes testes provam que, mesmo sem contexto ou com o contexto de OUTRO tenant, o
 * pagamento é confirmado e só o tenant dono é afetado. A autenticação e a idempotência
 * estão em Feature/Payments/WebhookIdempotencyTest.
 */
class WebhookCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeTenant('loja-a');
        $this->tenantB = $this->makeTenant('loja-b');
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::factory()->create([
            'slug' => $slug,
            'settings' => [
                'payment' => [
                    'mercadopago' => [
                        'public_key' => 'TEST-public-key',
                        'access_token' => EncryptionHelper::encryptIfNeeded('TEST-access-token'),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function orderWithPayment(Tenant $tenant, string $paymentId): array
    {
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'payment_status' => 'unpaid',
            'meta' => ['mercadopago' => ['payment_id' => $paymentId]],
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => $paymentId,
            'status' => 'pending',
        ]);

        return [$order, $payment];
    }

    private function fakeApproved(Tenant $tenant, Order $order, string $paymentId): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payments/'.$paymentId => Http::response([
                'id' => $paymentId,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'payment_method_id' => 'pix',
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'order_id' => $order->id,
                ],
            ], 200),
        ]);
    }

    public function test_gateway_handles_webhook_without_tenant_context(): void
    {
        [$order, $payment] = $this->orderWithPayment($this->tenantB, '900000001');
        $this->fakeApproved($this->tenantB, $order, '900000001');

        // Nenhum tenant no contexto — é o estado real do webhook.
        $this->assertFalse(app(TenantContext::class)->hasTenant());

        $result = (new MercadoPagoGateway)->handleWebhook([
            'type' => 'payment',
            'data' => ['id' => '900000001'],
        ]);

        $this->assertTrue($result->processed, 'o webhook não encontrou o pedido sem contexto de tenant');
        $this->assertSame($order->id, $result->orderId);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()?->payment_status);
        $this->assertSame('approved', $payment->fresh()?->status);
    }

    /**
     * Cenário venenoso: um contexto de tenant sobrou/está definido e o webhook
     * chega para um pedido de OUTRO tenant. Com o opt-out explícito o pedido
     * continua sendo encontrado.
     */
    public function test_gateway_handles_webhook_even_with_a_foreign_tenant_in_context(): void
    {
        [$order, $payment] = $this->orderWithPayment($this->tenantB, '900000002');
        $this->fakeApproved($this->tenantB, $order, '900000002');

        app(TenantContext::class)->set($this->tenantA);

        $result = (new MercadoPagoGateway)->handleWebhook([
            'type' => 'payment',
            'data' => ['id' => '900000002'],
        ]);

        $this->assertTrue($result->processed);
        $this->assertSame(PaymentStatus::PAID, $order->fresh()?->payment_status);
        $this->assertSame('approved', $payment->fresh()?->status);
    }

    public function test_webhook_route_confirms_payment_without_tenant_context(): void
    {
        [$order, $payment] = $this->orderWithPayment($this->tenantB, '900000003');
        $this->fakeApproved($this->tenantB, $order, '900000003');

        $response = $this->postJson('/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '900000003'],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(PaymentStatus::PAID, $order->fresh()?->payment_status);
        $this->assertSame('approved', $payment->fresh()?->status);
    }

    public function test_tenant_route_confirms_payment_even_with_a_foreign_tenant_in_context(): void
    {
        [$order, $payment] = $this->orderWithPayment($this->tenantB, '900000004');
        $this->fakeApproved($this->tenantB, $order, '900000004');

        app(TenantContext::class)->set($this->tenantA);

        $this->postJson('/webhooks/mercadopago/'.$this->tenantB->id, [
            'type' => 'payment',
            'data' => ['id' => '900000004'],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(PaymentStatus::PAID, $order->fresh()?->payment_status);
        $this->assertSame('approved', $payment->fresh()?->status);
        // O contexto do request original é restaurado (o webhook não vaza tenant).
        $this->assertSame($this->tenantA->id, app(TenantContext::class)->id());
    }

    public function test_webhook_still_ignores_unknown_payment_ids(): void
    {
        $response = $this->postJson('/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '000000000'],
        ]);

        $response->assertOk()->assertJson(['ok' => false]);
    }
}
