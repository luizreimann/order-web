<?php

namespace App\Livewire\Tenant;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Cart extends Component
{
    public Tenant $tenant;

    public array $lines = [];

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->loadCart();
    }

    private function loadCart(): void
    {
        $key = 'cart.'.$this->tenant->id;
        $cart = session()->get($key, []);
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
                    'slug' => $product->slug,
                    'price_cents' => (int) $product->price_cents,
                    'image_url' => $imagePath ? Storage::url($imagePath) : null,
                    // null = sem controle de estoque (sob encomenda)
                    'available' => $product->availableQuantity(),
                ],
                'qty' => $qty,
                'total_cents' => (int) $product->price_cents * $qty,
            ];
        }

        $this->lines = $lines;
    }

    private function persist(): void
    {
        $key = 'cart.'.$this->tenant->id;

        $cart = collect($this->lines)
            ->map(fn ($l) => [
                'product_id' => $l['product_id'],
                'qty' => max(1, (int) ($l['qty'] ?? 1)),
            ])
            ->values()
            ->all();

        session()->put($key, $cart);
        $this->loadCart();
    }

    public function increment(string $productId): void
    {
        $this->resetErrorBag();

        // Lido do banco, não de $this->lines (estado público do Livewire, editável pelo cliente).
        $available = Product::query()
            ->forTenant($this->tenant->id)
            ->whereKey($productId)
            ->first()
            ?->availableQuantity();

        foreach ($this->lines as &$line) {
            if ($line['product_id'] === $productId) {
                $next = (int) ($line['qty'] ?? 1) + 1;

                if ($available !== null && $next > $available) {
                    $this->addError('stock', "Só temos {$available} unidade(s) de \"{$line['product']['name']}\" disponíveis.");
                    break;
                }

                $line['qty'] = $next;
                break;
            }
        }
        unset($line);

        $this->persist();
    }

    public function decrement(string $productId): void
    {
        foreach ($this->lines as $idx => &$line) {
            if ($line['product_id'] === $productId) {
                $line['qty'] = max(1, (int) ($line['qty'] ?? 1) - 1);
                break;
            }
        }
        unset($line);

        $this->persist();
    }

    public function remove(string $productId): void
    {
        $this->lines = array_values(array_filter($this->lines, fn ($l) => $l['product_id'] !== $productId));
        $this->persist();
    }

    public function clear(): void
    {
        session()->forget('cart.'.$this->tenant->id);
        $this->lines = [];
    }

    public function getSubtotalCentsProperty(): int
    {
        return (int) collect($this->lines)->sum('total_cents');
    }

    public function render()
    {
        return view('livewire.tenant.cart');
    }
}
