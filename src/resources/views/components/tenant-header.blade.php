@props(['tenant', 'cartCount' => 0, 'customer' => null])

@php
    $isOpen = \App\Support\TenantHelper::isOpen($tenant);
    $deliveryInfo = \App\Support\TenantHelper::getDeliveryInfo($tenant);
    $logoUrl = $tenant->logo_path ? \Illuminate\Support\Facades\Storage::url($tenant->logo_path) : null;
@endphp

<header class="bg-white shadow-sm sticky-top">
    <div class="container py-3">
        <div class="d-flex align-items-start justify-content-between gap-3">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <a href="{{ route('tenant.home', ['tenant' => $tenant->slug]) }}" class="text-decoration-none">
                        @if($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ $tenant->name }}" class="logo-header">
                        @else
                            <h1 class="h4 mb-0 text-dark">{{ $tenant->name }}</h1>
                        @endif
                    </a>
                    @if($isOpen)
                        <span class="badge bg-success">Aberto</span>
                    @else
                        <span class="badge bg-secondary">Fechado</span>
                    @endif
                </div>
                <div class="text-muted small mt-1">
                    @if($deliveryInfo['estimated_time'])
                        <i class="fa-solid fa-clock me-1"></i> {{ $deliveryInfo['estimated_time'] }} min
                    @endif
                    @if($deliveryInfo['fee'] > 0)
                        <span class="mx-1">•</span>
                        <i class="fa-solid fa-truck me-1"></i> R$ {{ number_format($deliveryInfo['fee'], 2, ',', '.') }}
                    @endif
                    @if($deliveryInfo['minimum_order'] > 0)
                        <span class="mx-1">•</span>
                        Pedido mín. R$ {{ number_format($deliveryInfo['minimum_order'], 2, ',', '.') }}
                    @endif
                </div>
            </div>

            <div class="d-flex gap-2 align-items-center">
                <button 
                    type="button" 
                    class="btn btn-outline-secondary" 
                    data-bs-toggle="modal" 
                    data-bs-target="#contactModal"
                    title="Horário e Contato"
                >
                    <i class="fa-solid fa-clock"></i>
                    <span class="d-none d-md-inline ms-1">Horário</span>
                </button>

                <a 
                    href="{{ route('tenant.cart', ['tenant' => $tenant->slug]) }}" 
                    class="btn btn-outline-primary position-relative"
                    title="Carrinho"
                >
                    <i class="fa-solid fa-shopping-cart"></i>
                    @if($cartCount > 0)
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            {{ $cartCount }}
                        </span>
                    @endif
                    <span class="d-none d-md-inline ms-1">Carrinho</span>
                </a>

                <a 
                    href="{{ route('tenant.account', ['tenant' => $tenant->slug]) }}" 
                    class="btn btn-outline-secondary"
                    title="Minha Conta"
                >
                    <i class="fa-solid fa-user"></i>
                    <span class="d-none d-md-inline ms-1">Conta</span>
                </a>
            </div>
        </div>
    </div>
</header>
