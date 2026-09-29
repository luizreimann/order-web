<?php

namespace App\Livewire\Tenant;

use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Propriedades computadas no formato legado do Livewire (getXxxProperty), ainda
 * suportado na v3 via SupportLegacyComputedPropertySyntax. Declaradas aqui para
 * que a análise estática reconheça os acessos via __get.
 *
 * @property-read int $totalCents
 * @property-read string|null $paymentInstructions
 */
#[Layout('layouts.app')]
class Checkout extends Component
{
    public Tenant $tenant;

    public array $lines = [];

    public string $customer_name = '';

    public string $customer_phone = '';

    public string $customer_email = '';

    public ?string $payment_method = null;

    public array $payment_methods = [];

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->payment_methods = $this->enabledPaymentMethods();
        $this->payment_method = $this->payment_methods[0]['id'] ?? null;

        $this->loadCart();

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
        if (! in_array($this->payment_method, PaymentMethod::mercadoPagoIds(), true)) {
            return;
        }

        $this->dispatch('checkout-mp-init', $this->mpInitPayload());
    }

    private function mpInitPayload(): array
    {
        $allowedMp = [
            'creditCard' => null,
            'debitCard' => null,
            'bankTransfer' => null,
        ];

        if ($this->payment_method === PaymentMethod::MP_CREDIT_CARD->value) {
            $allowedMp['creditCard'] = 'all';
        }

        if ($this->payment_method === PaymentMethod::MP_DEBIT_CARD->value) {
            $allowedMp['debitCard'] = 'all';
        }

        if ($this->payment_method === PaymentMethod::MP_PIX->value) {
            $allowedMp['bankTransfer'] = ['pix'];
        }

        return [
            'publicKey' => data_get($this->tenant->settings, 'payment.mercadopago.public_key'),
            'processPaymentUrl' => route('tenant.process_payment', ['tenant' => $this->tenant->slug]),
            'amount' => round($this->totalCents / 100, 2),
            'mpMethod' => $this->payment_method,
            'allowed' => $allowedMp,
        ];
    }

    private function enabledPaymentMethods(): array
    {
        $methods = (array) data_get($this->tenant->settings, 'payment.methods', []);

        $hasMpCredentials = $this->hasMercadoPagoCredentials();

        $enabled = collect($methods)
            ->filter(function ($m) use ($hasMpCredentials) {
                if (! is_array($m) || ! ($m['enabled'] ?? false)) {
                    return false;
                }

                if (PaymentMethod::isMercadoPagoId($m['id'] ?? '') && ! $hasMpCredentials) {
                    return false;
                }

                return true;
            })
            ->values()
            ->all();

        return $enabled;
    }

    private function hasMercadoPagoCredentials(): bool
    {
        $publicKey = data_get($this->tenant->settings, 'payment.mercadopago.public_key');
        $accessToken = data_get($this->tenant->settings, 'payment.mercadopago.access_token');

        return ! empty($publicKey) && ! empty($accessToken);
    }

    private function loadCart(): void
    {
        $cart = session()->get('cart.'.$this->tenant->id, []);
        if (! is_array($cart)) {
            $cart = [];
        }

        $productIds = collect($cart)
            ->map(fn ($l) => (string) ($l['product_id'] ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $products = Product::query()
            ->forTenant($this->tenant->id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($cart as $line) {
            $productId = (string) ($line['product_id'] ?? '');
            $qty = max(1, (int) ($line['qty'] ?? 1));

            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $imagePath = data_get($product->meta, 'image_path');

            $lines[] = [
                'product_id' => $product->id,
                'product' => [
                    'name' => $product->name,
                    'price_cents' => (int) $product->price_cents,
                    'image_url' => $imagePath ? Storage::url($imagePath) : null,
                ],
                'qty' => $qty,
                'total_cents' => (int) $product->price_cents * $qty,
            ];
        }

        $this->lines = $lines;
    }

    public function getTotalCentsProperty(): int
    {
        return (int) collect($this->lines)->sum('total_cents');
    }

    public function getPaymentInstructionsProperty(): ?string
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
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'customer_email' => ['required', 'email', 'max:180'],
            'payment_method' => ['required', 'in:'.implode(',', PaymentMethod::manualIds())],
        ]);

        if ($this->totalCents <= 0) {
            $this->addError('payment_method', 'Carrinho vazio.');

            return;
        }

        $cart = session()->get('cart.'.$this->tenant->id, []);

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

        session()->forget('cart.'.$this->tenant->id);
        session()->flash('status', 'Pedido enviado!');

        $this->redirectRoute('tenant.home', ['tenant' => $this->tenant->slug]);
    }

    public function render()
    {
        return view('livewire.tenant.checkout', [
            'mpPublicKey' => data_get($this->tenant->settings, 'payment.mercadopago.public_key'),
            'mpAllowed' => $this->mpInitPayload()['allowed'],
        ]);
    }
}
