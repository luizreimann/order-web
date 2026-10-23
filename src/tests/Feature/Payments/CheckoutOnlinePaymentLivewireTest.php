<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Tenant\Checkout;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\TestCase;

/**
 * Checkout do storefront com Mercado Pago (reformulado): UMA escolha (os radios), UM botão de
 * pagar, e-mail do formulário como única fonte, sem segunda lista de meios.
 *
 * Lado servidor (Livewire): validação do formulário da esquerda, eventos para o script, texto
 * do botão, retomada da tela de espera e "tentar novamente". O que o navegador faz com o SDK,
 * o QR e o polling NÃO é provado aqui (ver relatório; `CheckoutDomSafetyTest` cobre o fonte).
 */
class CheckoutOnlinePaymentLivewireTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = $this->mpTenant();
        $settings = $this->tenant->settings;
        $settings['payment']['methods'] = [
            ['id' => 'mp_pix', 'provider' => 'mercadopago', 'label' => 'PIX (Mercado Pago)', 'enabled' => true],
            ['id' => 'mp_credit_card', 'provider' => 'mercadopago', 'label' => 'Cartão de crédito (Mercado Pago)', 'enabled' => true],
            ['id' => 'mp_debit_card', 'provider' => 'mercadopago', 'label' => 'Cartão de débito (Mercado Pago)', 'enabled' => true],
            ['id' => 'cash_on_delivery', 'provider' => 'manual', 'label' => 'Dinheiro (na entrega)', 'enabled' => true],
        ];
        $this->tenant->update(['settings' => $settings]);
        $this->tenant = $this->tenant->fresh();

        $this->product = $this->product($this->tenant, 2500, stock: 10);
        app(CartService::class)->add($this->tenant, $this->product->id, 2);
    }

    private function checkout(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Checkout::class, ['tenant' => $this->tenant]);
    }

    private function filled(string $method, string $email = 'maria@example.com'): \Livewire\Features\SupportTesting\Testable
    {
        return $this->checkout()
            ->set('customer_name', 'Maria')
            ->set('customer_phone', '(11) 99999-9999')
            ->set('customer_email', $email)
            ->set('payment_method', $method);
    }

    // ------------------------------------------------------------------ e-mail obrigatório só nos online

    public function test_the_pix_button_requires_a_valid_email_with_the_clear_message(): void
    {
        foreach (['', 'nao-e-email', 'maria @example.com'] as $bad) {
            $this->filled('mp_pix', $bad)
                ->call('payOnline')
                ->assertHasErrors(['customer_email'])
                ->assertSee('Informe um e-mail válido para receber o comprovante do Pix.')
                ->assertNotDispatched('checkout-mp-pay');
        }
    }

    public function test_the_card_email_message_does_not_talk_about_pix(): void
    {
        $this->filled('mp_credit_card', '')
            ->call('payOnline')
            ->assertHasErrors(['customer_email'])
            ->assertSee('Informe um e-mail válido para receber o comprovante do pagamento.')
            ->assertDontSee('comprovante do Pix');
    }

    public function test_the_pay_button_also_validates_name_and_phone_of_the_left_form(): void
    {
        $this->filled('mp_pix')
            ->set('customer_name', '')
            ->set('customer_phone', 'abc')
            ->call('payOnline')
            ->assertHasErrors(['customer_name', 'customer_phone'])
            ->assertNotDispatched('checkout-mp-pay');
    }

    public function test_a_valid_form_hands_the_validated_customer_to_the_script_and_creates_nothing_yet(): void
    {
        Http::fake();

        $this->filled('mp_pix', ' maria@example.com ')
            ->call('payOnline')
            ->assertHasNoErrors()
            ->assertDispatched('checkout-mp-pay', fn (string $event, array $params) => $params['method'] === 'mp_pix'
                && $params['customer'] === ['name' => 'Maria', 'phone' => '(11) 99999-9999', 'email' => 'maria@example.com']);

        // O pedido nasce no `process_payment` (PaymentService::begin), não aqui.
        $this->assertSame(0, Order::withoutTenantScope()->count());
        Http::assertNothingSent();
    }

    public function test_manual_methods_keep_their_flow_and_do_not_use_the_online_button(): void
    {
        $component = $this->checkout()->set('payment_method', 'cash_on_delivery');

        $component->assertSee('Enviar pedido')->assertDontSee('Pagar com');
        $component->call('payOnline')->assertHasErrors(['payment_method'])->assertNotDispatched('checkout-mp-pay');

        $component->set('customer_name', 'Maria')->set('customer_phone', '')->set('customer_email', 'maria@example.com')
            ->call('placeOrderManual');

        $order = Order::withoutTenantScope()->sole();
        $this->assertSame('cash_on_delivery', $order->payment_method);
        $this->assertSame(0, Payment::withoutTenantScope()->count(), 'método manual não cria cobrança');
    }

    // ------------------------------------------------------------------ UM botão, texto do método

    public function test_there_is_a_single_action_button_and_its_text_follows_the_selected_method(): void
    {
        $expected = [
            'mp_pix' => 'Pagar com PIX',
            'mp_credit_card' => 'Pagar com cartão de crédito',
            'mp_debit_card' => 'Pagar com cartão de débito',
        ];

        foreach ($expected as $method => $label) {
            $html = $this->checkout()->set('payment_method', $method)->html();

            $this->assertSame(1, substr_count($html, 'wire:click="payOnline"'), "$method: mais de um botão de pagar");
            $this->assertStringContainsString($label, $html);
            $this->assertStringNotContainsString('wire:click="placeOrderManual"', $html);
        }
    }

    public function test_the_page_has_no_second_payment_method_list_or_second_email_field(): void
    {
        $html = $this->checkout()->set('payment_method', 'mp_pix')->html();

        // Só os radios da esquerda escolhem o método.
        $this->assertSame(4, substr_count($html, 'name="payment_method_choice"'));
        $this->assertStringNotContainsString('id="mpPaymentCard"', $html);
        // Um único campo de e-mail na página (o do Brick, se houver, vem do SDK e é escondido).
        $this->assertSame(1, preg_match_all('/type="email"/', $html));
        $this->assertSame(1, preg_match_all('/id="customerEmailInput"/', $html));
    }

    public function test_the_email_label_is_marked_required_only_for_online_methods(): void
    {
        $online = $this->checkout()->set('payment_method', 'mp_pix')->html();
        $manual = $this->checkout()->set('payment_method', 'cash_on_delivery')->html();

        $labelOf = function (string $html): string {
            preg_match('#<label for="customerEmailInput"[^>]*>(.*?)</label>#s', $html, $m);

            return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<!--.*?-->/s', '', $m[1] ?? '')));
        };

        $this->assertSame('E-mail *', $labelOf($online));
        $this->assertSame('E-mail', $labelOf($manual));
    }

    // ------------------------------------------------------------------ eventos para o script

    public function test_choosing_pix_asks_for_the_direct_flow_without_any_form_of_the_sdk(): void
    {
        // (PIX já é o método inicial: a troca precisa vir de outro método para haver evento.)
        $this->checkout()->set('payment_method', 'cash_on_delivery')->set('payment_method', 'mp_pix')
            ->assertDispatched('checkout-mp-init', fn (string $event, array $params) => $params[0]['flow'] === 'direct'
                && $params[0]['mpMethod'] === 'mp_pix'
                && $params[0]['excludedTypes'] === []);
    }

    public function test_choosing_a_card_asks_for_the_card_form_restricted_to_the_chosen_type_with_the_form_email(): void
    {
        $this->filled('mp_pix')->set('payment_method', 'mp_credit_card')
            ->assertDispatched('checkout-mp-init', fn (string $event, array $params) => $params[0]['flow'] === 'card'
                && $params[0]['excludedTypes'] === ['debit_card']
                && $params[0]['payerEmail'] === 'maria@example.com'
                && $params[0]['amount'] == 50);

        $this->filled('mp_pix')->set('payment_method', 'mp_debit_card')
            ->assertDispatched('checkout-mp-init', fn (string $event, array $params) => $params[0]['excludedTypes'] === ['credit_card', 'prepaid_card']);
    }

    public function test_the_card_form_never_receives_an_invented_or_invalid_email(): void
    {
        foreach (['', 'nao-e-email'] as $bad) {
            $this->checkout()->set('customer_email', $bad)->set('payment_method', 'mp_credit_card')
                ->assertDispatched('checkout-mp-init', fn (string $event, array $params) => $params[0]['payerEmail'] === null);
        }
    }

    public function test_leaving_the_email_field_reinitializes_the_card_form_with_the_email(): void
    {
        $this->checkout()->set('payment_method', 'mp_credit_card')
            ->set('customer_email', 'maria@example.com')
            ->assertDispatched('checkout-mp-init', fn (string $event, array $params) => $params[0]['payerEmail'] === 'maria@example.com');
    }

    public function test_typing_the_email_with_a_manual_method_selected_dispatches_nothing_for_the_gateway(): void
    {
        $this->checkout()->set('payment_method', 'cash_on_delivery')
            ->set('customer_email', 'maria@example.com')
            ->assertNotDispatched('checkout-mp-init');
    }

    public function test_going_back_to_a_manual_method_tears_the_card_form_down(): void
    {
        $this->checkout()->set('payment_method', 'mp_credit_card')
            ->set('payment_method', 'cash_on_delivery')
            ->assertDispatched('checkout-mp-reset');
    }

    // ------------------------------------------------------------------ tela de espera após recarregar

    private function createdPixOrder(): Order
    {
        $this->fakeMpOrderCreation('ORDTEST777001', 'pending', ['transactions' => ['payments' => [['payment_method' => [
            'qr_code' => 'COPIA-E-COLA-777', 'qr_code_base64' => 'iVBORw0KGgo=', 'ticket_url' => 'https://www.mercadopago.com.br/t/1', 'extra' => 'nao-vai',
        ]]]]]);

        $this->postJson('/mp-loja/process_payment', $this->processPaymentBody(['formData' => []]))->assertOk();

        return Order::withoutTenantScope()->sole();
    }

    public function test_after_a_reload_the_pix_screen_is_restored_from_the_stored_payment_when_the_cart_is_empty(): void
    {
        $order = $this->createdPixOrder();

        $component = $this->checkout();
        $awaiting = $component->get('awaitingPayment');

        $this->assertSame($order->number, $awaiting['order_number']);
        $this->assertSame(50.0, $awaiting['amount']);
        $this->assertEqualsCanonicalizing(
            ['qr_code' => 'COPIA-E-COLA-777', 'qr_code_base64' => 'iVBORw0KGgo=', 'ticket_url' => 'https://www.mercadopago.com.br/t/1'],
            $awaiting['transaction_data'],
            'só os 3 campos do PIX voltam à página',
        );
        $this->assertStringContainsString('id="mpResult"', $component->html());

        // A página inteira (o script fica na pilha do layout) embute os dados para o JS validar e renderizar.
        $page = $this->get('/mp-loja/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('COPIA-E-COLA-777', $page);
        $this->assertStringContainsString('const AWAITING = ', $page);
        $this->assertStringNotContainsString('const AWAITING = null', $page);
        $this->assertStringNotContainsString('nao-vai', $page);
    }

    public function test_the_reloaded_page_carries_the_pix_flag_the_expiration_and_the_device_id_script(): void
    {
        $this->createdPixOrder();

        $awaiting = $this->checkout()->get('awaitingPayment');
        $this->assertTrue($awaiting['is_pix']);

        $page = $this->get('/mp-loja/checkout')->assertOk()->getContent();
        // Device ID: o script do MP só na página de checkout e o valor lido ao pagar.
        $this->assertStringContainsString('<script src="https://www.mercadopago.com/v2/security.js" view="checkout"></script>', $page);
        $this->assertStringContainsString('window.MP_DEVICE_SESSION_ID', $page);
        // Janela de pagamento do Pix (config, 5 min) embutida para o texto e o limite do polling; o
        // prazo de 30 min do MP NÃO é o que o cliente vê. Pix sem QR ainda.
        $this->assertStringContainsString('const PIX_WINDOW_SECONDS = 300;', $page);
        $this->assertStringNotContainsString('PIX_EXPIRATION_SECONDS', $page);
        $this->assertStringContainsString('Gerando o seu Pix', $page);
        $this->assertMatchesRegularExpression('/is_pix.{0,12}true/', $page);

        // O prazo do MP não altera o que o cliente vê; só a janela (e com teto de 300 s).
        config(['services.mercadopago.pix_expiration' => 'PT2H']);
        $this->assertStringContainsString('const PIX_WINDOW_SECONDS = 300;', $this->get('/mp-loja/checkout')->getContent());
        config(['services.mercadopago.pix_window' => 120]);
        $this->assertStringContainsString('const PIX_WINDOW_SECONDS = 120;', $this->get('/mp-loja/checkout')->getContent());
    }

    public function test_a_decided_order_is_only_restored_inside_the_reload_window_and_an_open_pix_until_it_expires(): void
    {
        $order = $this->createdPixOrder();

        // PIX ainda a pagar: vale até o fim da janela de pagamento (5 min) + 10 min de folga para o
        // servidor cancelar e avisar.
        $this->travel(14)->minutes();
        $this->assertNotNull($this->checkout()->get('awaitingPayment'));
        $this->travel(2)->minutes();
        $this->assertNull($this->checkout()->get('awaitingPayment'), 'PIX vencido não volta');
        $this->travelBack();

        // Pix de ANTES da janela (sem `pix_expires_at` gravado): o prazo antigo (PT30M + 5 min).
        $payment = \App\Models\Payment::withoutTenantScope()->where('order_id', $order->id)->sole();
        $meta = $payment->meta;
        unset($meta['pix_expires_at']);
        $payment->forceFill(['meta' => $meta])->save();
        $this->travel(34)->minutes();
        $this->assertNotNull($this->checkout()->get('awaitingPayment'));
        $this->travel(10)->minutes();
        $this->assertNull($this->checkout()->get('awaitingPayment'));
        $this->travelBack();

        // Pago: só 30 min (depois, "carrinho vazio" de novo; nada de confirmação antiga).
        $order->forceFill(['payment_status' => PaymentStatus::PAID])->save();
        $this->travel(20)->minutes();
        $this->assertNotNull($this->checkout()->get('awaitingPayment'));
        $this->travel(15)->minutes();
        $this->assertNull($this->checkout()->get('awaitingPayment'));
        $this->travelBack();
    }

    public function test_the_pix_screen_is_not_restored_over_a_cart_that_has_items_again(): void
    {
        $this->createdPixOrder();
        app(CartService::class)->add($this->tenant, $this->product->id, 1);

        $this->assertNull($this->checkout()->get('awaitingPayment'));
    }

    public function test_the_pix_screen_is_never_restored_from_an_order_of_another_tenant(): void
    {
        $order = $this->createdPixOrder();
        $other = $this->mpTenant('outra-loja');
        session()->forget('checkout.order.'.$this->tenant->id);
        session()->put('checkout.order.'.$other->id, $order->id);

        $component = Livewire::test(Checkout::class, ['tenant' => $other->fresh()]);

        $this->assertNull($component->get('awaitingPayment'));
        $this->assertStringNotContainsString('COPIA-E-COLA-777', $component->html());
    }

    // ------------------------------------------------------------------ tentar novamente

    public function test_try_again_restores_the_cart_and_opens_a_fresh_checkout_only_for_a_cancelled_order(): void
    {
        $order = $this->createdPixOrder();
        $carts = app(CartService::class);
        $this->assertSame([], $carts->items($this->tenant), 'a cobrança aceita limpou o carrinho');

        // Pedido ainda pendente: nada acontece.
        $this->checkout()->call('retryAfterCancellation')->assertNoRedirect();
        $this->assertSame([], $carts->items($this->tenant));

        // O PIX expirou: o webhook cancelou o pedido.
        $order->forceFill(['status' => OrderStatus::CANCELLED])->save();

        $this->checkout()->call('retryAfterCancellation')->assertRedirectToRoute('tenant.checkout', ['tenant' => 'mp-loja']);

        /** @var list<array{product_id: string, qty: int}> $items */
        $items = $carts->items($this->tenant);
        $this->assertCount(1, $items);
        $this->assertSame([$this->product->id, 2], [$items[0]['product_id'], $items[0]['qty']], 'o carrinho de antes da cobrança, com as mesmas quantidades');
        $this->assertNull(session('checkout.order.'.$this->tenant->id));
        $this->assertNull(session('checkout.retry_cart.'.$this->tenant->id));
    }

    public function test_try_again_is_refused_when_the_money_was_received(): void
    {
        $order = $this->createdPixOrder();
        $order->forceFill(['status' => OrderStatus::CANCELLED, 'payment_status' => PaymentStatus::PAID])->save();

        $this->checkout()->call('retryAfterCancellation')->assertNoRedirect();

        $this->assertSame([], app(CartService::class)->items($this->tenant), 'refazer o pedido faria o cliente pagar duas vezes');
    }

    public function test_try_again_without_an_order_in_this_session_does_nothing(): void
    {
        session()->put('checkout.retry_cart.'.$this->tenant->id, [['product_id' => $this->product->id, 'qty' => 9]]);

        $this->checkout()->call('retryAfterCancellation')->assertNoRedirect();
    }
}
