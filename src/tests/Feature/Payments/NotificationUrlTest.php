<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentMethod;
use App\Gateways\MercadoPagoGateway;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\TestCase;

/**
 * Na API de PAYMENTS a `notification_url` ia no corpo da cobrança e, com `APP_URL=http://localhost`,
 * o MP recusava tudo (HTTP 400, código 4020). Na API de ORDERS o campo NÃO EXISTE: a sandbox
 * responde 400 `unsupported_properties` a `metadata` e a documentação ("Como migrar da Payments
 * API para a Orders API", item 16) diz que a URL de notificação passa a ser configurada SÓ no
 * painel do MP ("Suas integrações > Webhooks", tópico Order). Então o corpo da order nunca leva
 * `notification_url` (nem `metadata`), seja qual for o `APP_URL` e o que o chamador tente
 * embutir; a URL a cadastrar no painel sai de {@see \App\Support\WebhookUrl} na tela de Pagamentos.
 */
class NotificationUrlTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private Tenant $tenant;

    private MercadoPagoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->mpTenant();
        $this->gateway = new MercadoPagoGateway;
        $this->fakeMpOrderCreation('ORDTEST000123', 'pending');
    }

    /** @return array<string, array{string}> */
    public static function appUrls(): array
    {
        return [
            'o do bug (localhost)' => ['http://localhost'],
            'localhost com porta' => ['http://localhost:8080'],
            'rede privada' => ['https://192.168.0.10'],
            'tld de teste' => ['https://loja.test'],
            'http público' => ['http://app.quitanda.app'],
            'vazio' => [''],
            'público https' => ['https://app.quitanda.app'],
            'túnel' => ['https://x.ngrok-free.app/'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sent(): array
    {
        $bodies = [];
        Http::assertSent(function (Request $request) use (&$bodies) {
            if ($request->method() === 'POST') {
                $bodies[] = $request->data();
            }

            return true;
        });

        return $bodies;
    }

    /** @param  array<string, mixed>  $extra */
    private function charge(PaymentMethod $method, array $extra = []): void
    {
        $order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'total_cents' => 1000]);

        $this->gateway->createPayment($this->tenant, $order, array_merge([
            'payment_method' => $method->value,
            'customer_email' => 'maria@example.com',
            'formData' => ['token' => 'card-token', 'payment_method_id' => 'visa'],
            'idempotency_key' => 'k-'.$order->id,
        ], $extra));
    }

    #[DataProvider('appUrls')]
    public function test_the_pix_order_never_carries_notification_url_or_metadata(string $appUrl): void
    {
        config(['app.url' => $appUrl]);

        $this->charge(PaymentMethod::MP_PIX);

        $bodies = $this->sent();
        $this->assertCount(1, $bodies);
        $this->assertArrayNotHasKey('notification_url', $bodies[0]);
        $this->assertArrayNotHasKey('metadata', $bodies[0]);
        $this->assertSame('pix', $bodies[0]['transactions']['payments'][0]['payment_method']['id']);
        $this->assertSame('maria@example.com', $bodies[0]['payer']['email']);
    }

    #[DataProvider('appUrls')]
    public function test_the_card_order_never_carries_notification_url_or_metadata(string $appUrl): void
    {
        config(['app.url' => $appUrl]);

        $this->charge(PaymentMethod::MP_CREDIT_CARD);

        $bodies = $this->sent();
        $this->assertCount(1, $bodies);
        $this->assertArrayNotHasKey('notification_url', $bodies[0]);
        $this->assertArrayNotHasKey('metadata', $bodies[0]);
        $this->assertSame('card-token', $bodies[0]['transactions']['payments'][0]['payment_method']['token']);
    }

    public function test_a_notification_url_or_metadata_smuggled_by_the_caller_never_reaches_the_gateway(): void
    {
        config(['app.url' => 'https://app.quitanda.app']);

        $this->charge(PaymentMethod::MP_PIX, [
            'notification_url' => 'https://evil.example.org/hook',
            'metadata' => ['order_id' => 'forjado'],
            'formData' => ['notification_url' => 'https://evil.example.org/hook', 'metadata' => ['x' => 'y']],
        ]);

        $body = (string) json_encode($this->sent()[0]);
        $this->assertStringNotContainsString('evil.example.org', $body);
        $this->assertStringNotContainsString('forjado', $body);
        $this->assertArrayNotHasKey('notification_url', $this->sent()[0]);
    }

    public function test_the_gateway_no_longer_logs_a_disabled_webhook_notice_per_charge(): void
    {
        config(['app.url' => 'http://localhost']);
        $logs = $this->captureLogs();

        $this->charge(PaymentMethod::MP_PIX);
        $this->charge(PaymentMethod::MP_CREDIT_CARD);

        $this->assertSame([], array_filter($logs->getArrayCopy(), fn ($e) => str_contains($e['message'], 'webhook desativado')));
    }
}
