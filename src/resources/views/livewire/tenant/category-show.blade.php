<div class="container py-4">
    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
        <div>
            <a href="{{ route('tenant.home', ['tenant' => $tenant->slug]) }}" class="text-decoration-none text-muted small">
                <i class="fa-solid fa-arrow-left me-1"></i> Voltar
            </a>
            <h1 class="h4 mb-0 mt-1">{{ $category->name }}</h1>
            @if($category->description)
                <div class="text-muted small">{{ $category->description }}</div>
            @endif
        </div>

        <x-btn outline :href="route('tenant.cart', ['tenant' => $tenant->slug])">
            Carrinho
        </x-btn>
    </div>

    @if (count($products ?? []) === 0)
        <x-empty-state message="Nenhum produto disponível nesta categoria." />
    @else
        <div class="row g-3">
            @foreach($products as $p)
                <div class="col-12 col-md-6 col-lg-4">
                    <a class="text-decoration-none" href="{{ route('tenant.product.show', ['tenant' => $tenant->slug, 'productSlug' => $p['slug']]) }}">
                        <x-card flush full>
                            @if($p['image_url'])
                                <img src="{{ $p['image_url'] }}" class="card-img-top h-180 object-fit-cover" alt="{{ $p['name'] }}">
                            @endif

                            <div class="card-body">
                                <div class="fw-semibold">{{ $p['name'] }}</div>
                                <div class="text-muted">R$ {{ number_format($p['price_cents'] / 100, 2, ',', '.') }}</div>
                            </div>
                        </x-card>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>
