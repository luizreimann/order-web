<?php

namespace App\Livewire\Tenant;

use App\Models\Product;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProductShow extends Component
{
    public Tenant $tenant;

    public Product $product;

    public int $qty = 1;

    public function mount(Tenant $tenant, string $productSlug): void
    {
        $this->tenant = $tenant;

        $this->product = Product::query()
            ->forTenant($tenant->id)
            ->active()
            ->where('slug', $productSlug)
            ->firstOrFail();
    }

    public function addToCart(): void
    {
        $this->resetErrorBag();

        $qty = max(1, (int) $this->qty);

        $key = 'cart.'.$this->tenant->id;
        $cart = session()->get($key, []);
        if (! is_array($cart)) {
            $cart = [];
        }

        // Estoque controlado (E2.7): considera o que já está no carrinho. É checagem de UX;
        // a decisão definitiva (com lock) é do OrderService no fechamento do pedido.
        $available = $this->product->availableQuantity();
        if ($available !== null) {
            $inCart = (int) collect($cart)
                ->where('product_id', $this->product->id)
                ->sum(fn ($l) => (int) ($l['qty'] ?? 0));

            if ($available <= 0) {
                $this->addError('qty', 'Produto esgotado.');

                return;
            }

            if ($inCart + $qty > $available) {
                $remaining = max(0, $available - $inCart);
                $this->addError('qty', $remaining === 0
                    ? "Você já tem todas as {$available} unidade(s) disponíveis no carrinho."
                    : "Só é possível adicionar mais {$remaining} unidade(s) deste produto.");

                return;
            }
        }

        $found = false;
        foreach ($cart as &$line) {
            if (($line['product_id'] ?? null) === $this->product->id) {
                $line['qty'] = (int) ($line['qty'] ?? 0) + $qty;
                $found = true;
                break;
            }
        }
        unset($line);

        if (! $found) {
            $cart[] = [
                'product_id' => $this->product->id,
                'qty' => $qty,
            ];
        }

        session()->put($key, $cart);
        session()->flash('status', 'Adicionado ao carrinho!');

        $this->redirectRoute('tenant.cart', ['tenant' => $this->tenant->slug]);
    }

    public function render()
    {
        return view('livewire.tenant.product-show');
    }
}
