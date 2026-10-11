<?php

namespace App\Livewire\Tenant;

use App\Exceptions\CartStockLimitException;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CartService;
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

    public function addToCart(CartService $cart): void
    {
        $this->resetErrorBag();

        // Limite de estoque (considerando o que já está no carrinho) e produto ativo são
        // regras do CartService, lidas do banco agora (não do snapshot desta página).
        try {
            $cart->add($this->tenant, $this->product->id, (int) $this->qty);
        } catch (CartStockLimitException $e) {
            $this->addError('qty', $e->getMessage());

            return;
        }

        session()->flash('status', 'Adicionado ao carrinho!');

        $this->redirectRoute('tenant.cart', ['tenant' => $this->tenant->slug]);
    }

    public function render()
    {
        return view('livewire.tenant.product-show');
    }
}
