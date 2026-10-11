<?php

namespace App\Livewire\Tenant;

use App\Exceptions\CartStockLimitException;
use App\Models\Tenant;
use App\Services\CartService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela do carrinho. Sem lógica de carrinho aqui: leitura, limites de estoque, totais e
 * escrita são do {@see CartService}. As linhas e o subtotal são propriedades computadas
 * (lidas da sessão/banco a cada request), nunca estado público editável pelo cliente.
 *
 * @property-read list<array{product_id: string, product: \App\Models\Product, qty: int, unit_cents: int, total_cents: int, available: int|null}> $lines
 * @property-read int $subtotalCents
 */
#[Layout('layouts.app')]
class Cart extends Component
{
    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    #[Computed]
    public function lines(): array
    {
        return app(CartService::class)->lines($this->tenant);
    }

    #[Computed]
    public function subtotalCents(): int
    {
        return app(CartService::class)->subtotalCents($this->lines);
    }

    public function increment(string $productId): void
    {
        $this->resetErrorBag();

        try {
            app(CartService::class)->increment($this->tenant, $productId);
        } catch (CartStockLimitException $e) {
            $this->addError('stock', $e->getMessage());
        }

        $this->refreshCart();
    }

    public function decrement(string $productId): void
    {
        app(CartService::class)->decrement($this->tenant, $productId);

        $this->refreshCart();
    }

    public function remove(string $productId): void
    {
        app(CartService::class)->remove($this->tenant, $productId);

        $this->refreshCart();
    }

    public function clear(): void
    {
        app(CartService::class)->clear($this->tenant);

        $this->refreshCart();
    }

    /** Descarta o cache por request das computadas depois de mudar o carrinho. */
    private function refreshCart(): void
    {
        unset($this->lines, $this->subtotalCents);
    }

    public function render()
    {
        return view('livewire.tenant.cart');
    }
}
