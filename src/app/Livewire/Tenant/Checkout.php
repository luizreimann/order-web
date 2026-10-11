<?php

namespace App\Livewire\Tenant;

use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Gateways\PaymentGatewayManager;
use App\Http\Controllers\TenantPaymentController;
use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Checkout manual. Linhas e total vêm do {@see CartService} (sessão + banco, a cada
 * request) como propriedades computadas: o total NUNCA é estado público editável pelo
 * cliente, e `OrderService::createFromCart` o recalcula no servidor.
 *
 * `#[Computed]` expõe os métodos como propriedades via `__get`; o docblock abaixo existe
 * para a análise estática reconhecer esses acessos.
 *
 * @property-read list<array{product_id: string, product: \App\Models\Product, qty: int, unit_cents: int, total_cents: int, available: int|null}> $lines
 * @property-read int $totalCents
 * @property-read string|null $paymentInstructions
 * @property-read bool $isOnlinePayment
 */
#[Layout('layouts.app')]
class Checkout extends Component
{
    public Tenant $tenant;

    public string $customer_name = '';

    public string $customer_phone = '';

    public string $customer_email = '';

    public ?string $payment_method = null;

    public array $payment_methods = [];

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        // "Intenção de compra" desta sessão: base da idempotência do pagamento online
        // (duplo clique = mesmo checkout). Descartada quando a cobrança é aceita.
        $nonceKey = TenantPaymentController::nonceKey($tenant);
        if (! session()->has($nonceKey)) {
            session()->put($nonceKey, (string) Str::uuid());
        }

        $this->payment_methods = $this->enabledPaymentMethods();
        $this->payment_method = $this->payment_methods[0]['id'] ?? null;

        // Preencher dados do cliente autenticado
        $customer = Auth::guard('customer')->user();
        if ($customer) {
            $this->customer_name = $customer->name ?: '';
            $this->customer_phone = $customer->phone ?: '';
            $this->customer_email = $customer->email ?: '';
        }
    }

    public function updatedPaymentMethod(): void
    {
        $payload = $this->gatewayCheckoutPayload($this->selectedOnlineMethod());

        if ($payload === null) {
            return;
        }

        // Nome histórico do evento, escutado pelo script do formulário de pagamento.
        $this->dispatch('checkout-mp-init', $payload);
    }

    /** Método escolhido, se for de pagamento online (ou seja, não manual). */
    private function selectedOnlineMethod(): ?PaymentMethod
    {
        $method = $this->payment_method === null ? null : PaymentMethod::tryFrom($this->payment_method);

        return $method !== null && ! $method->isManual() ? $method : null;
    }

    /**
     * Estado inicial do formulário do gateway do método. Quem sabe o que o front do
     * provedor precisa é o próprio gateway; aqui só se pergunta.
     *
     * @return array<string, mixed>|null null = método manual ou sem gateway disponível
     */
    private function gatewayCheckoutPayload(?PaymentMethod $method): ?array
    {
        if ($method === null) {
            return null;
        }

        try {
            $gateway = app(PaymentGatewayManager::class)->forMethod($method, $this->tenant);
        } catch (UnsupportedPaymentMethodException) {
            return null;
        }

        return $gateway->checkoutPayload(
            $this->tenant,
            $method,
            $this->totalCents,
            route('tenant.process_payment', ['tenant' => $this->tenant->slug])
        );
    }

    /**
     * Métodos habilitados do tenant. Os de pagamento online só aparecem se o gateway deles
     * estiver configurado (regra do gateway, não deste componente).
     */
    private function enabledPaymentMethods(): array
    {
        $methods = (array) data_get($this->tenant->settings, 'payment.methods', []);
        $gateways = app(PaymentGatewayManager::class);

        return collect($methods)
            ->filter(function ($m) use ($gateways) {
                if (! is_array($m) || ! ($m['enabled'] ?? false)) {
                    return false;
                }

                $method = PaymentMethod::tryFrom((string) ($m['id'] ?? ''));

                if ($method === null || $method->isManual()) {
                    return true;
                }

                try {
                    return $gateways->forMethod($method, $this->tenant)->isConfigured($this->tenant);
                } catch (UnsupportedPaymentMethodException) {
                    return false;
                }
            })
            ->values()
            ->all();
    }

    /** O método escolhido cobra online (formulário do gateway) em vez de "Enviar pedido"? */
    #[Computed]
    public function isOnlinePayment(): bool
    {
        return $this->selectedOnlineMethod() !== null;
    }

    #[Computed]
    public function lines(): array
    {
        return app(CartService::class)->lines($this->tenant);
    }

    #[Computed]
    public function totalCents(): int
    {
        return app(CartService::class)->totalCents($this->lines);
    }

    #[Computed]
    public function paymentInstructions(): ?string
    {
        if (! $this->payment_method) {
            return null;
        }

        $method = collect($this->payment_methods)->firstWhere('id', $this->payment_method);
        if (! $method) {
            return null;
        }

        $instructions = $method['instructions'] ?? null;

        if (($method['id'] ?? null) === PaymentMethod::PIX_MANUAL->value) {
            $pixKey = data_get($method, 'config.pix_key');
            if ($pixKey) {
                $instructions = trim((string) $instructions);
                $suffix = "Chave PIX: {$pixKey}";
                $instructions = $instructions ? ($instructions."\n\n".$suffix) : $suffix;
            }
        }

        return $instructions ?: null;
    }

    public function placeOrderManual(OrderService $orderService): void
    {
        $this->validate([
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:40', new PhoneNumber],
            'customer_email' => ['required', 'email', 'max:180'],
            'payment_method' => ['required', 'in:'.implode(',', PaymentMethod::manualIds())],
        ]);

        if ($this->totalCents <= 0) {
            $this->addError('payment_method', 'Carrinho vazio.');

            return;
        }

        $cart = app(CartService::class)->items($this->tenant);

        try {
            $orderService->createFromCart(
                $this->tenant,
                [
                    'name' => $this->customer_name,
                    'email' => $this->customer_email,
                    'phone' => $this->customer_phone,
                ],
                $cart,
                $this->payment_method,
                [
                    'provider' => 'manual',
                    'instructions_snapshot' => $this->paymentInstructions,
                ]
            );
        } catch (InsufficientStockException $e) {
            // Rollback total: nenhum pedido/baixa foi gravado e o carrinho é preservado
            // para o cliente ajustar a quantidade.
            $this->addError('cart', $e->getMessage().' Ajuste o carrinho e tente novamente.');

            return;
        }

        app(CartService::class)->clear($this->tenant);
        session()->flash('status', 'Pedido enviado!');

        $this->redirectRoute('tenant.home', ['tenant' => $this->tenant->slug]);
    }

    public function render()
    {
        // Formulário do gateway: o do método escolhido ou, se o escolhido é manual, o do
        // primeiro método online habilitado (a seção fica na página, só oculta).
        $online = $this->selectedOnlineMethod()
            ?? collect($this->payment_methods)
                ->map(fn ($m) => PaymentMethod::tryFrom((string) ($m['id'] ?? '')))
                ->first(fn ($m) => $m !== null && ! $m->isManual());

        $gatewayPayload = $this->gatewayCheckoutPayload($online);

        return view('livewire.tenant.checkout', [
            'gatewayPayload' => $gatewayPayload,
            'gatewayPublicKey' => $gatewayPayload['publicKey'] ?? null,
        ]);
    }
}
