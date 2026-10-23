<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\Support\MercadoPagoOrders;
use Tests\TestCase;

/**
 * Fluxo reformulado do Mercado Pago no checkout:
 *  - PIX: o SERVIDOR cria o pagamento (`payment_method_id: pix`) com o e-mail do formulário
 *    do checkout e devolve o QR; o cliente nunca digita o e-mail duas vezes;
 *  - cartão: o navegador só manda o que o Card Payment Brick tokenizou; o servidor repassa uma
 *    LISTA BRANCA e o e-mail do formulário.
 *
 * Tudo pelo endpoint real `POST /{tenant}/process_payment`, com `Http::fake` no lugar do MP.
 * Pedido/estoque/idempotência continuam sendo de `PaymentService` (provados também em
 * `PaymentConsistencyTest`; aqui só o que o fluxo novo muda).
 */
class MercadoPagoDirectFlowTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->mpTenant();
    }

    /** Order de Pix criada e já com o QR (formato de Orders: `transactions.payments[0].payment_method`). */
    private function fakePix(string $orderId = 'ORDTEST777001'): void
    {
        $this->fakeMpOrderCreation($orderId, 'pending', ['transactions' => ['payments' => [['payment_method' => [
            'qr_code' => '00020126580014br.gov.bcb.pix-COPIA-E-COLA',
            'qr_code_base64' => 'iVBORw0KGgoAAAANSUhEUg==',
            'ticket_url' => 'https://www.mercadopago.com.br/payments/777001/ticket',
        ]]]]]);
    }

    private function pay(Product $product, array $body = [], int $qty = 1): TestResponse
    {
        return $this
            ->withSession($this->checkoutSession($this->tenant, $product, $qty))
            ->postJson('/mp-loja/process_payment', $this->processPaymentBody($body));
    }

    private function stockOf(Product $p): int
    {
        return (int) DB::table('products')->where('id', $p->id)->value('stock_quantity');
    }

    /** @return array<string, mixed> corpo enviado a `POST /v1/orders` */
    private function sentToMercadoPago(): array
    {
        $sent = [];
        Http::assertSent(function (Request $request) use (&$sent) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v1/orders')) {
                $sent = $request->data();
            }

            return true;
        });

        return $sent;
    }

    // ------------------------------------------------------------------ PIX direto

    public function test_pix_is_created_directly_with_the_form_email_and_the_qr_is_returned(): void
    {
        $product = $this->product($this->tenant, 2500, stock: 5);
        $this->fakePix();

        // O navegador NÃO manda formData de Brick: só método e dados do formulário da esquerda.
        $response = $this->pay($product, [
            'customer' => ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => '11999990000'],
            'formData' => [],
        ], qty: 2)->assertOk();

        $sent = $this->sentToMercadoPago();
        $payment = $sent['transactions']['payments'][0];
        $this->assertSame('online', $sent['type']);
        $this->assertSame('automatic', $sent['processing_mode']);
        $this->assertSame('50.00', $sent['total_amount'], 'valor como STRING com 2 casas');
        $this->assertSame('50.00', $payment['amount']);
        $this->assertSame(['id' => 'pix', 'type' => 'bank_transfer'], $payment['payment_method']);
        $this->assertSame('PT30M', $payment['expiration_time']);
        $this->assertSame(['email' => 'maria@example.com', 'first_name' => 'Maria'], $sent['payer'], 'o payer.email é o do formulário do checkout; nome de uma palavra só vira first_name');
        $this->assertSame(Order::withoutTenantScope()->sole()->id, $sent['external_reference']);

        // O QR e o copia e cola chegam à tela já na resposta (e ficam no Payment para a retomada).
        $transaction = $response->json('point_of_interaction.transaction_data');
        $this->assertSame('00020126580014br.gov.bcb.pix-COPIA-E-COLA', $transaction['qr_code']);
        $this->assertSame('iVBORw0KGgoAAAANSUhEUg==', $transaction['qr_code_base64']);
        $this->assertSame('pending', $response->json('status'));

        $payment = Payment::withoutTenantScope()->sole();
        $this->assertSame('ORDTEST777001', $payment->gateway_payment_id, 'o id guardado é o da ORDER');
        $this->assertSame('PAYTEST777001', data_get($payment->meta, 'transaction_id'), 'o id da transação vai em meta');
        $this->assertSame('waiting_transfer', $payment->status_detail);
        $this->assertSame('action_required', data_get($payment->meta, 'gateway_details.order_status'), 'o status bruto do MP é guardado');
        $this->assertSame('00020126580014br.gov.bcb.pix-COPIA-E-COLA', data_get($payment->meta, 'point_of_interaction.transaction_data.qr_code'));
        $this->assertSame(3, $this->stockOf($product));
    }

    public function test_the_email_is_never_invented_the_pix_charge_carries_exactly_what_the_customer_typed(): void
    {
        $product = $this->product($this->tenant);
        $this->fakePix();

        $this->pay($product, ['customer' => ['name' => 'Joao', 'email' => '  joao@example.com  ', 'phone' => '']])->assertOk();

        $this->assertSame('joao@example.com', $this->sentToMercadoPago()['payer']['email']);
        $this->assertSame('joao@example.com', \App\Models\Customer::withoutTenantScope()->sole()->email);
    }

    public function test_the_browser_cannot_change_what_is_sent_to_mercado_pago_for_pix(): void
    {
        config(['app.url' => 'https://app.quitanda.app']);
        $product = $this->product($this->tenant, 1000);
        $this->fakePix();

        $this->pay($product, ['formData' => [
            'payment_method_id' => 'visa',
            'token' => 'card-token-injetado',
            'transaction_amount' => 0.01,
            'payer' => ['email' => 'outro@example.com'],
            'metadata' => ['order_id' => 'forjado', 'tenant_id' => 'forjado'],
            'notification_url' => 'https://evil.example/hook',
            'external_reference' => 'forjado',
        ]])->assertOk();

        $sent = $this->sentToMercadoPago();
        $order = Order::withoutTenantScope()->sole();
        $payment = $sent['transactions']['payments'][0];

        $this->assertSame(['id' => 'pix', 'type' => 'bank_transfer'], $payment['payment_method'], 'nada do formData vira meio de pagamento');
        $this->assertArrayNotHasKey('token', $payment['payment_method']);
        $this->assertSame('maria@example.com', $sent['payer']['email']);
        $this->assertSame('10.00', $sent['total_amount']);
        $this->assertSame($order->id, $sent['external_reference']);
        // Orders não aceita `metadata` nem `notification_url`: nada disso vai (nem o forjado).
        $this->assertArrayNotHasKey('metadata', $sent);
        $this->assertArrayNotHasKey('notification_url', $sent);
        $this->assertStringNotContainsString('evil.example', (string) json_encode($sent));
    }

    /** @return array<string, array{string}> */
    public static function unusableEmails(): array
    {
        return [
            'vazio' => [''],
            'só espaços' => ['   '],
            'sem arroba' => ['nao-e-email'],
            'sem domínio' => ['maria@'],
            'com espaço no meio' => ['maria @example.com'],
        ];
    }

    #[DataProvider('unusableEmails')]
    public function test_a_missing_or_invalid_email_blocks_the_online_payment_before_anything_is_created(string $email): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        Http::fake();

        $response = $this->pay($product, ['customer' => ['name' => 'Maria', 'email' => $email, 'phone' => '']])->assertStatus(422);

        $this->assertNotSame('', (string) $response->json('message'));
        $this->assertSame(0, Order::withoutTenantScope()->count());
        $this->assertSame(0, Payment::withoutTenantScope()->count());
        $this->assertSame(5, $this->stockOf($product), 'estoque baixado sem cobrança');
        Http::assertNothingSent();
    }

    public function test_the_pix_email_message_is_clear_and_keyed_to_the_field(): void
    {
        $product = $this->product($this->tenant);
        Http::fake();

        $this->pay($product, ['customer' => ['name' => 'Maria', 'email' => 'nao-e-email', 'phone' => '']])
            ->assertStatus(422)
            ->assertJsonPath('errors.customer_email.0', 'Informe um e-mail válido para receber o comprovante do Pix.');
    }

    // ------------------------------------------------------------------ PIX: idempotência/estoque

    public function test_double_submit_of_pix_creates_one_order_one_charge_and_replays_the_same_qr(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        $this->fakePix();

        $first = $this->pay($product, ['formData' => []], qty: 2)->assertOk();
        $second = $this->pay($product, ['formData' => []], qty: 2)->assertOk();

        $this->assertSame($first->json('order_id'), $second->json('order_id'));
        $this->assertSame(
            $first->json('point_of_interaction.transaction_data.qr_code'),
            $second->json('point_of_interaction.transaction_data.qr_code'),
        );
        $this->assertSame(1, Order::withoutTenantScope()->count());
        $this->assertSame(1, Payment::withoutTenantScope()->count());
        $this->assertSame(3, $this->stockOf($product));
        Http::assertSentCount(1);
    }

    public function test_a_refused_pix_cancels_the_order_and_returns_the_stock_exactly_once(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        Http::fake(['api.mercadopago.com/v1/orders' => Http::response(MercadoPagoOrders::errorBody('property_value', 'Invalid value for property', ["'$.payer.email' - does not match pattern"]), 400)]);

        $this->pay($product, ['formData' => []], qty: 2)->assertStatus(422);

        $order = Order::withoutTenantScope()->sole();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame('failed', Payment::withoutTenantScope()->sole()->status);
        $this->assertSame(5, $this->stockOf($product), 'estoque devolvido uma vez (nem a mais, nem a menos)');
        $this->assertNull(session('checkout.order.'.$this->tenant->id), 'cobrança recusada não gera tela de espera');
    }

    // ------------------------------------------------------------------ cartão: lista branca

    public function test_card_payload_is_a_whitelist_and_carries_the_form_email(): void
    {
        $product = $this->product($this->tenant, 2000, stock: 5);
        $this->fakeMpOrderCreation('ORDTEST888001', 'approved');

        $this->pay($product, [
            'mp_method' => 'mp_credit_card',
            'customer' => ['name' => 'Maria da Silva', 'email' => 'maria@example.com', 'phone' => '11999990000'],
            'formData' => [
                'token' => 'tok_abc123',
                'payment_method_id' => 'visa',
                'payment_type_id' => 'debit_card',
                'issuer_id' => '24',
                'installments' => 12,
                'payer' => ['email' => 'cartao@example.com', 'identification' => ['type' => 'CPF', 'number' => '19119119100'], 'first_name' => 'X'],
                'transaction_amount' => 0.01,
                'metadata' => ['order_id' => 'forjado'],
                'additional_info' => ['items' => []],
                'capture' => false,
            ],
        ])->assertOk()->assertJsonPath('status', 'approved');

        $sent = $this->sentToMercadoPago();
        $method = $sent['transactions']['payments'][0]['payment_method'];

        // `id` (bandeira) vem do Brick; `type` vem do MÉTODO escolhido (crédito), nunca do navegador.
        $this->assertSame(['id' => 'visa', 'type' => 'credit_card', 'token' => 'tok_abc123', 'installments' => 1], $method, 'lista branca; a loja cobra à vista');
        $this->assertSame('20.00', $sent['total_amount']);
        $this->assertSame('20.00', $sent['transactions']['payments'][0]['amount']);
        $this->assertSame(
            ['email' => 'maria@example.com', 'first_name' => 'Maria', 'last_name' => 'da Silva', 'identification' => ['type' => 'CPF', 'number' => '19119119100']],
            $sent['payer'],
            'e-mail e nome do formulário do checkout (não os do Brick); só a identificação vem do Brick'
        );
        foreach (['additional_info', 'capture', 'metadata', 'issuer_id', 'transaction_amount', 'token'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $sent);
        }

        $payment = Payment::withoutTenantScope()->sole();
        $this->assertSame('ORDTEST888001', $payment->gateway_payment_id);
        $this->assertSame('approved', $payment->status);
        $this->assertSame('accredited', $payment->status_detail);
        $this->assertSame(PaymentStatus::PAID, Order::withoutTenantScope()->sole()->payment_status);
    }

    public function test_a_debit_card_is_sent_as_debit_card(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        $this->fakeMpOrderCreation('ORDTEST888003', 'approved');

        $this->pay($product, ['mp_method' => 'mp_debit_card', 'formData' => ['token' => 'tok_deb', 'payment_method_id' => 'debelo']])->assertOk();

        $method = $this->sentToMercadoPago()['transactions']['payments'][0]['payment_method'];
        $this->assertSame('debelo', $method['id']);
        $this->assertSame('debit_card', $method['type']);
    }

    public function test_a_hostile_brand_id_never_reaches_the_gateway(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        Http::fake();

        // `payment_method_id` com lixo (aspas, espaço, maiúsculas...) = dado insuficiente: nada é cobrado.
        $this->pay($product, ['mp_method' => 'mp_credit_card', 'formData' => ['token' => 'tok', 'payment_method_id' => 'visa","x":"y']])->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::CANCELLED, Order::withoutTenantScope()->sole()->status);
        $this->assertSame(5, $this->stockOf($product));
    }

    // ------------------------------------------------------------------ Device ID (antifraude)

    /** Device ID real tem a forma `armor.<hex>.<hex>` (~250 caracteres). */
    private const DEVICE_ID = 'armor.017d80a3b45471b5b9eecdbcddf55d126d241d9e90d3a3addb94810ff230720207081f92c26a21345ebe7f4316609c916a48861bc2b513fc660fcdce49703083b0e1c04e2f7288d45703aef6ba3cb4d9617ffc40c802660c29cf78ef16ee3a75.f9e8332c25040a69dce3ed322340fd7a';

    public function test_a_valid_device_id_is_sent_in_the_meli_session_header(): void
    {
        $product = $this->product($this->tenant);
        $this->fakePix();

        $this->pay($product, ['device_session_id' => self::DEVICE_ID])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('X-meli-session-id', self::DEVICE_ID));
    }

    public function test_without_a_device_id_the_payment_goes_through_without_the_header(): void
    {
        $product = $this->product($this->tenant);
        $this->fakePix();

        $this->pay($product)->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! $r->hasHeader('X-meli-session-id'));
    }

    /** @return array<string, array{mixed}> */
    public static function hostileDeviceIds(): array
    {
        return [
            'CRLF (injeção de cabeçalho)' => ["abcdefgh12345\r\nX-Evil: 1"],
            'espaços' => ['abcdefgh 12345'],
            '10 KB' => [str_repeat('a', 10240)],
            'curto demais' => ['abc'],
            'aspas e símbolos' => ['abcdefgh"<script>'],
            'array' => [['abcdefgh12345']],
            'inteiro' => [123456789012],
        ];
    }

    #[DataProvider('hostileDeviceIds')]
    public function test_a_hostile_device_id_is_dropped_silently_and_never_blocks_the_payment(mixed $value): void
    {
        $product = $this->product($this->tenant);
        $this->fakePix();

        $this->pay($product, ['device_session_id' => $value])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! $r->hasHeader('X-meli-session-id'));
    }

    public function test_a_card_payment_without_token_is_refused_without_calling_the_gateway(): void
    {
        $product = $this->product($this->tenant, 1000, stock: 5);
        Http::fake();

        $this->pay($product, ['mp_method' => 'mp_credit_card', 'formData' => ['payment_method_id' => 'pix']], qty: 2)->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(OrderStatus::CANCELLED, Order::withoutTenantScope()->sole()->status);
        $this->assertSame(5, $this->stockOf($product));
    }
}
