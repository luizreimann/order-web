<?php

namespace App\Livewire\Tenant;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\BelowMinimumOrderException;
use App\Exceptions\EmptyCartException;
use App\Exceptions\FulfillmentException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Gateways\PaymentGatewayManager;
use App\Http\Controllers\TenantPaymentController;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Services\CartService;
use App\Services\FulfillmentService;
use App\Services\OrderService;
use App\Support\FulfillmentQuote;
use App\Support\PixExpiration;
use App\Support\PixWindow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
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
 * @property-read int $subtotalCents
 * @property-read FulfillmentQuote|null $quote
 * @property-read int $shortfallCents
 * @property-read list<FulfillmentType> $fulfillmentTypes
 * @property-read list<array{id: string, name: string, fee_cents: int, active: bool}> $regions
 * @property-read bool $isDelivery
 * @property-read int|null $estimatedMinutes
 * @property-read bool $isOnlinePayment
 * @property-read string $payActionLabel
 * @property-read array{order_number: int|string|null, amount: float, is_pix: bool, transaction_data: array<string, mixed>, expires_at?: string, server_now?: string}|null $awaitingPayment
 */
#[Layout('layouts.app')]
#[Title('Checkout')]
class Checkout extends Component
{
    public Tenant $tenant;

    public string $customer_name = '';

    public string $customer_phone = '';

    public string $customer_email = '';

    public ?string $payment_method = null;

    public array $payment_methods = [];

    /** Retirada ou entrega (`FulfillmentType`); o servidor refaz taxa e total ao gravar o pedido. */
    public string $fulfillment_type = '';

    public ?string $region_id = null;

    public string $address_line1 = '';

    public string $address_line2 = '';

    public string $address_city = '';

    public string $address_state = '';

    public string $address_postal_code = '';

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

        $this->fulfillment_type = app(FulfillmentService::class)->defaultType($tenant)->value;
        $this->address_city = (string) data_get($tenant->settings, 'address.city', '');
        $this->address_state = strtoupper((string) data_get($tenant->settings, 'address.state', ''));

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
            // Método manual: o formulário do gateway (se havia) é desmontado.
            $this->dispatch('checkout-mp-reset');

            return;
        }

        // Nome histórico do evento, escutado pelo script do formulário de pagamento.
        $this->dispatch('checkout-mp-init', $payload);
    }

    /** A taxa muda com o tipo e o bairro; o formulário do gateway (valor) é refeito. */
    public function updatedFulfillmentType(): void
    {
        $this->refreshComputed();
        $this->refreshGatewayForm();
    }

    public function updatedRegionId(): void
    {
        $this->refreshComputed();
        $this->refreshGatewayForm();
    }

    private function refreshComputed(): void
    {
        unset($this->quote, $this->totalCents);
    }

    private function refreshGatewayForm(): void
    {
        $payload = $this->gatewayCheckoutPayload($this->selectedOnlineMethod());

        if ($payload !== null) {
            $this->dispatch('checkout-mp-init', $payload);
        }
    }

    /**
     * O e-mail é a fonte do `payer.email` do gateway. O formulário de cartão só dispensa pedir
     * o e-mail de novo se já o recebe preenchido; ao sair do campo, o script é reavisado.
     */
    public function updatedCustomerEmail(): void
    {
        $payload = $this->gatewayCheckoutPayload($this->selectedOnlineMethod());

        if ($payload !== null) {
            $this->dispatch('checkout-mp-init', $payload);
        }
    }

    /**
     * Botão único de pagamento online. Valida o formulário da esquerda (nome, WhatsApp e
     * e-mail, este OBRIGATÓRIO aqui) e só então entrega ao script o método e os dados do
     * cliente já validados no servidor; o script cria o pagamento em `process_payment`
     * (PIX: QR direto; cartão: com o token gerado no navegador).
     */
    public function payOnline(): void
    {
        $method = $this->selectedOnlineMethod();

        if ($method === null) {
            $this->addError('payment_method', 'Selecione um método de pagamento online.');

            return;
        }

        // Teclados de celular costumam deixar espaço sobrando no fim: não é motivo de erro.
        $this->customer_name = trim($this->customer_name);
        $this->customer_phone = trim($this->customer_phone);
        $this->customer_email = trim($this->customer_email);

        $this->validate([
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:40', new PhoneNumber],
            'customer_email' => ['required', ...PaymentMethod::EMAIL_RULES],
        ], [
            'customer_email.required' => $method->emailRequirementMessage(),
            'customer_email.email' => $method->emailRequirementMessage(),
            'customer_email.max' => $method->emailRequirementMessage(),
        ]);

        if ($this->subtotalCents <= 0) {
            $this->addError('cart', 'Carrinho vazio.');

            return;
        }

        if (! $this->fulfillmentIsValid()) {
            return;
        }

        $this->dispatch(
            'checkout-mp-pay',
            method: $method->value,
            customer: [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
            ],
            fulfillment: $this->fulfillmentInput(),
        );
    }

    /**
     * "Tentar novamente" depois de um pagamento online cancelado/expirado: devolve ao
     * carrinho o que estava nele (o estoque do pedido cancelado já voltou) e abre um checkout
     * novo. Só vale para o pedido DESTA sessão e só se ele realmente foi cancelado.
     */
    #[On('checkout-retry')]
    public function retryAfterCancellation(): void
    {
        $orderId = session()->get(TenantPaymentController::orderKey($this->tenant));
        $order = is_string($orderId) && $orderId !== ''
            ? Order::query()->forTenant($this->tenant->id)->whereKey($orderId)->first()
            : null;

        if ($order === null || $order->status !== OrderStatus::CANCELLED
            || $order->payment_status === PaymentStatus::PAID) {
            return;
        }

        $carts = app(CartService::class);

        foreach ((array) session()->get(TenantPaymentController::retryCartKey($this->tenant), []) as $item) {
            try {
                $carts->add($this->tenant, (string) ($item['product_id'] ?? ''), (int) ($item['qty'] ?? 1));
            } catch (\Throwable) {
                // produto esgotado/inativo agora: o cliente ajusta no carrinho
            }
        }

        session()->forget([
            TenantPaymentController::orderKey($this->tenant),
            TenantPaymentController::retryCartKey($this->tenant),
        ]);

        $this->redirectRoute('tenant.checkout', ['tenant' => $this->tenant->slug]);
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

        $payload = $gateway->checkoutPayload(
            $this->tenant,
            $method,
            $this->totalCents,
            route('tenant.process_payment', ['tenant' => $this->tenant->slug])
        );

        // E-mail do formulário (só se válido) para pré-preencher o formulário do gateway: o
        // cliente não deve digitá-lo duas vezes. Nada é inventado quando falta.
        $payload['payerEmail'] = $this->hasValidEmail() ? trim($this->customer_email) : null;

        return $payload;
    }

    private function hasValidEmail(): bool
    {
        $email = trim($this->customer_email);

        return $email !== ''
            && Validator::make(['email' => $email], ['email' => PaymentMethod::EMAIL_RULES])->passes();
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

    /** Texto do botão único de ação (coerente com o método escolhido). */
    #[Computed]
    public function payActionLabel(): string
    {
        return ($this->selectedOnlineMethod() ?? PaymentMethod::CASH_ON_DELIVERY)->payActionLabel();
    }

    /**
     * Pedido online desta sessão que ainda merece a tela de espera (QR do PIX, status): só com
     * o carrinho vazio, ou seja, depois que a cobrança foi aceita e a página foi recarregada.
     * Dados vêm do `Payment` já gravado; o navegador os valida de novo antes de renderizar.
     *
     * @return array{order_number: int|string|null, amount: float, is_pix: bool, transaction_data: array<string, mixed>, expires_at?: string, server_now?: string}|null
     */
    #[Computed]
    public function awaitingPayment(): ?array
    {
        if (count($this->lines) > 0) {
            return null;
        }

        $orderId = session()->get(TenantPaymentController::orderKey($this->tenant));
        $order = is_string($orderId) && $orderId !== ''
            ? Order::query()->forTenant($this->tenant->id)->whereKey($orderId)->first()
            : null;

        if ($order === null) {
            return null;
        }

        $payment = Payment::query()->forTenant($this->tenant->id)->where('order_id', $order->id)->latest()->first();
        $isPix = PaymentMethod::tryFrom((string) $order->payment_method)?->isOnlinePix() ?? false;
        $timing = $isPix ? PixWindow::timing($payment?->meta) : [];

        // Pedido em aberto (PIX a pagar): vale até a janela de pagamento do Pix (gravada no
        // pagamento) mais uma folga para o servidor cancelar e avisar; sem janela gravada (Pix de
        // antes desta regra, cartão em análise), até o prazo do Pix no MP (config) mais 5 min. Já
        // decidido (pago/cancelado): só a "janela de recarga" (30 min); depois disso o checkout
        // vazio volta a ser só "carrinho vazio", não uma confirmação de ontem.
        $open = $order->payment_status === PaymentStatus::UNPAID && $order->status !== OrderStatus::CANCELLED;
        $expiresAt = PixWindow::expiresAt($payment?->meta);

        if ($open && $isPix && $expiresAt !== null) {
            $stale = now()->greaterThan($expiresAt->addMinutes(10));
        } elseif ($open) {
            $stale = $order->created_at === null || $order->created_at->lt(now()->subSeconds(PixExpiration::seconds() + 300));
        } else {
            $stale = $order->created_at === null || $order->created_at->lt(now()->subMinutes(30));
        }

        if ($stale) {
            return null;
        }

        $data = (array) data_get($payment?->meta, 'point_of_interaction.transaction_data', []);

        return [
            'order_number' => $order->number,
            'amount' => round($order->total_cents / 100, 2),
            'is_pix' => $isPix,
            'transaction_data' => array_intersect_key($data, array_flip(['qr_code', 'qr_code_base64', 'ticket_url'])),
        ] + $timing;
    }

    #[Computed]
    public function lines(): array
    {
        return app(CartService::class)->lines($this->tenant);
    }

    /** Soma das linhas, antes de qualquer taxa (base do pedido mínimo e do frete grátis). */
    #[Computed]
    public function subtotalCents(): int
    {
        return app(CartService::class)->totalCents($this->lines);
    }

    /**
     * O que a loja cobra pelo recebimento escolhido; null enquanto a escolha está incompleta (ex.:
     * entrega com bairros e nenhum selecionado): aí só se mostra o subtotal.
     */
    #[Computed]
    public function quote(): ?FulfillmentQuote
    {
        try {
            return app(FulfillmentService::class)->quote($this->tenant, $this->subtotalCents, $this->fulfillment_type, $this->region_id);
        } catch (FulfillmentException) {
            return null;
        }
    }

    /** Total EXIBIDO (e passado ao formulário do gateway): subtotal + taxa. O servidor o refaz. */
    #[Computed]
    public function totalCents(): int
    {
        return $this->subtotalCents + ($this->quote->feeCents ?? 0);
    }

    #[Computed]
    public function shortfallCents(): int
    {
        return $this->quote->shortfallCents
            ?? max(0, app(FulfillmentService::class)->minimumCents($this->tenant) - $this->subtotalCents);
    }

    #[Computed]
    public function estimatedMinutes(): ?int
    {
        return app(FulfillmentService::class)->estimatedMinutes($this->tenant);
    }

    /** @return list<FulfillmentType> */
    #[Computed]
    public function fulfillmentTypes(): array
    {
        return app(FulfillmentService::class)->availableTypes($this->tenant);
    }

    /** @return list<array{id: string, name: string, fee_cents: int, active: bool}> */
    #[Computed]
    public function regions(): array
    {
        return app(FulfillmentService::class)->regions($this->tenant);
    }

    #[Computed]
    public function isDelivery(): bool
    {
        return $this->fulfillment_type === FulfillmentType::Delivery->value;
    }

    /** @return array{type: string, region_id: string|null, address: array<string, string>} */
    private function fulfillmentInput(): array
    {
        return [
            'type' => $this->fulfillment_type,
            'region_id' => $this->region_id,
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->address_city,
                'state' => $this->address_state,
                'postal_code' => $this->address_postal_code,
            ],
        ];
    }

    /** Mínimo, tipo, bairro e endereço: cada problema aparece no campo certo, antes de gravar. */
    private function fulfillmentIsValid(): bool
    {
        $service = app(FulfillmentService::class);
        $input = $this->fulfillmentInput();
        $this->resetErrorBag(['cart', 'fulfillment_type', 'region_id', 'address_line1', 'address_line2', 'address_city', 'address_state', 'address_postal_code']);

        try {
            $quote = $service->quote($this->tenant, $this->subtotalCents, $input['type'], $input['region_id']);

            if (! $quote->meetsMinimum()) {
                $this->addError('cart', (new BelowMinimumOrderException($quote->minimumCents, $quote->shortfallCents))->getMessage());

                return false;
            }

            if ($quote->type->isDelivery()) {
                $service->validateAddress($input['address']);
            }
        } catch (FulfillmentException $e) {
            $this->addFulfillmentErrors($e);

            // Entrega: o endereço também é conferido agora, para o cliente ver tudo de uma vez.
            if ($input['type'] === FulfillmentType::Delivery->value) {
                try {
                    $service->validateAddress($input['address']);
                } catch (FulfillmentException $addressErrors) {
                    $this->addFulfillmentErrors($addressErrors);
                }
            }

            return false;
        }

        return true;
    }

    private function addFulfillmentErrors(FulfillmentException $e): void
    {
        foreach ($e->errors ?: ['fulfillment_type' => $e->getMessage()] as $field => $message) {
            $this->addError($field, $message);
        }
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

        if ($this->subtotalCents <= 0) {
            $this->addError('payment_method', 'Carrinho vazio.');

            return;
        }

        if (! $this->fulfillmentIsValid()) {
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
                ],
                $this->fulfillmentInput(),
            );
        } catch (EmptyCartException|BelowMinimumOrderException $e) {
            // O carrinho ou a regra da loja mudou entre a tela e o envio: nada foi gravado.
            $this->addError('cart', $e->getMessage());

            return;
        } catch (FulfillmentException $e) {
            $this->addFulfillmentErrors($e);

            return;
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
