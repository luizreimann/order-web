<div>
    @if($bannerUrl)
        <div class="container py-4">
            <img src="{{ $bannerUrl }}" alt="{{ $tenant->name }}" class="img-fluid rounded shadow-sm w-100 banner-home">
        </div>
    @endif

    <div class="container py-4">
        @if (count($showcases ?? []) === 0)
            <x-empty-state message="Nenhum produto disponível no momento." />
        @else
            @foreach($showcases as $showcase)
                <section class="mb-5">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h2 class="h5 mb-0">{{ $showcase['name'] }}</h2>
                        @if($showcase['type'] === 'category' && $showcase['category_slug'])
                            <x-btn variant="link" size="sm" class="text-decoration-none"
                                   :href="route('tenant.category.show', ['tenant' => $tenant->slug, 'categorySlug' => $showcase['category_slug']])">
                                Ver mais <i class="fa-solid fa-arrow-right ms-1"></i>
                            </x-btn>
                        @endif
                    </div>

                    @if(($showcase['display_mode'] ?? 'list') === 'carousel')
                        <div class="splide showcase-carousel" id="showcase-{{ $showcase['id'] }}">
                            <div class="splide__track">
                                <ul class="splide__list">
                                    @foreach($showcase['products'] as $p)
                                        <li class="splide__slide">
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
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @else
                        <div class="list-group">
                            @foreach($showcase['products'] as $p)
                                <a href="{{ route('tenant.product.show', ['tenant' => $tenant->slug, 'productSlug' => $p['slug']]) }}" 
                                   class="list-group-item list-group-item-action">
                                    <div class="row align-items-center g-3">
                                        <div class="col-3 col-md-2">
                                            @if($p['image_url'])
                                                <img src="{{ $p['image_url'] }}" 
                                                     class="img-fluid rounded w-100 h-80 object-fit-cover" 
                                                     alt="{{ $p['name'] }}">
                                            @else
                                                <div class="bg-light rounded d-flex align-items-center justify-content-center w-100 h-80">
                                                    <i class="fa-solid fa-image text-muted"></i>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="col-6 col-md-7">
                                            <div class="fw-semibold text-dark">{{ $p['name'] }}</div>
                                        </div>
                                        <div class="col-3 col-md-3 text-end">
                                            <div class="fw-bold text-tenant-primary">R$ {{ number_format($p['price_cents'] / 100, 2, ',', '.') }}</div>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        @endif
    </div>
</div>