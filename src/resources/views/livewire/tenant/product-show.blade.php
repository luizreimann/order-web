<div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <a class="btn btn-outline-secondary" href="{{ route('tenant.home', ['tenant' => $tenant->slug]) }}">
            Voltar
        </a>

        <a class="btn btn-outline-primary" href="{{ route('tenant.cart', ['tenant' => $tenant->slug]) }}">
            Carrinho
        </a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                @php
                    $imagePath = data_get($product->meta, 'image_path');
                    $imageUrl = $imagePath ? \Illuminate\Support\Facades\Storage::url($imagePath) : null;
                @endphp

                @if($imageUrl)
                    <img src="{{ $imageUrl }}" class="card-img-top" alt="{{ $product->name }}">
                @endif

                <div class="card-body">
                    <h1 class="h4 mb-1">{{ $product->name }}</h1>
                    <div class="text-muted mb-3">R$ {{ number_format($product->price_cents / 100, 2, ',', '.') }}</div>

                    @if($product->isOutOfStock())
                        <span class="badge text-bg-secondary mb-3">Esgotado</span>
                    @endif

                    @if($product->description)
                        <div class="text-body-secondary" style="white-space: pre-wrap;">{{ $product->description }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Quantidade</label>
                            <input type="number" min="1" class="form-control" wire:model.live="qty" @disabled($product->isOutOfStock())>
                        </div>

                        <div class="col-md-8">
                            <button class="btn btn-primary w-100" type="button" wire:click="addToCart" @disabled($product->isOutOfStock())>
                                {{ $product->isOutOfStock() ? 'Esgotado' : 'Adicionar ao carrinho' }}
                            </button>
                        </div>
                    </div>

                    @error('qty') <div class="text-warning-emphasis small mt-2">{{ $message }}</div> @enderror

                    @if($product->availableQuantity() !== null && ! $product->isOutOfStock())
                        <div class="mt-2 text-muted small">{{ $product->availableQuantity() }} unidade(s) disponível(is).</div>
                    @endif

                    <div class="mt-3 text-muted small">
                        Você poderá revisar o carrinho e escolher o método de pagamento no checkout.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
