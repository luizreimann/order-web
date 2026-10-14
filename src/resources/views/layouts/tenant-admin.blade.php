<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin - {{ $currentTenant->name ?? 'Tenant' }}</title>

    @vite(['resources/css/app.css', 'resources/js/admin.js'])
    @include('partials.tenant-theme')
    @livewireStyles

    <style>
        .admin-shell { min-height: 100vh; }
        .admin-sidebar { min-width: 250px; }

        /* agrupamento e estilo do menu */
        .admin-nav-section-title {
            font-size: .75rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--bs-secondary-color);
        }
        .admin-nav-divider {
            margin: .85rem 0;
            border-top: 1px solid var(--bs-border-color);
        }
        .admin-nav .nav-link {
            color: var(--bs-body-color);
            border-radius: .5rem;
            padding: .5rem .75rem;
        }
        .admin-nav .nav-link:hover {
            background: var(--bs-tertiary-bg);
        }
        .admin-nav .nav-link.active {
            background: var(--bs-primary-bg-subtle);
            color: var(--bs-primary-text-emphasis);
            font-weight: 600;
        }
        .admin-nav .nav-link i {
            width: 1.25rem;
            text-align: center;
        }

        /* Accordion menu styles */
        .admin-nav-accordion .accordion-button {
            background: transparent;
            color: var(--bs-body-color);
            border-radius: .5rem;
            padding: .5rem .75rem;
            font-size: inherit;
            box-shadow: none;
        }
        .admin-nav-accordion .accordion-button:not(.collapsed) {
            background: transparent;
            color: var(--bs-body-color);
            box-shadow: none;
        }
        .admin-nav-accordion .accordion-button:hover {
            background: var(--bs-tertiary-bg);
        }
        .admin-nav-accordion .accordion-button::after {
            width: .875rem;
            height: .875rem;
            background-size: .875rem;
        }
        .admin-nav-accordion .accordion-button i {
            width: 1.25rem;
            text-align: center;
        }
        .admin-nav-accordion .accordion-item {
            background: transparent;
            border: none;
        }
        .admin-nav-accordion .accordion-body {
            padding: 0 0 0 1rem;
        }
        .admin-nav-accordion .accordion-body .nav-link {
            padding: .35rem .75rem;
            font-size: .9rem;
        }
        .admin-nav-accordion .accordion-button.has-active {
            color: var(--bs-primary-text-emphasis);
            font-weight: 600;
        }
    </style>
</head>

<body class="bg-body text-body">
<div class="admin-shell d-flex">

    {{-- Sidebar (desktop) --}}
    <aside class="admin-sidebar bg-body-tertiary border-end d-none d-lg-flex flex-column p-3">
        <div class="mb-3 d-flex align-items-start justify-content-between gap-2">
            <div>
                <div class="fw-semibold">{{ $currentTenant->name }}</div>
            </div>

            <div class="d-flex gap-2">
                @php
                    $isPaused = data_get($currentTenant->settings, 'business_hours.paused', false);
                @endphp
                @can('toggleStoreStatus', $currentTenant)
                <button type="button"
                        class="btn btn-outline-{{ $isPaused ? 'danger' : 'success' }} btn-sm d-flex align-items-center justify-content-center"
                        data-bs-toggle="tooltip"
                        data-bs-placement="bottom"
                        title="{{ $isPaused ? 'Loja fechada - Clique para abrir' : 'Loja aberta - Clique para fechar' }}"
                        onclick="toggleStoreStatus()"
                        id="storeStatusBtn">
                    <i class="fa-solid fa-{{ $isPaused ? 'lock' : 'lock-open' }}" id="storeStatusIcon"></i>
                </button>
                @endcan

                <a href="/{{ $currentTenant->slug }}"
                   target="_blank"
                   class="btn btn-outline-primary btn-sm d-flex align-items-center justify-content-center"
                   data-bs-toggle="tooltip"
                   data-bs-placement="bottom"
                   title="Ir para a área do cliente">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                </a>
            </div>
        </div>

        @php
            $isConfigActive = request()->routeIs('tenant.admin.store-info') ||
                              request()->routeIs('tenant.admin.customization') ||
                              request()->routeIs('tenant.admin.contact') ||
                              request()->routeIs('tenant.admin.address') ||
                              request()->routeIs('tenant.admin.business-hours') ||
                              request()->routeIs('tenant.admin.social-links') ||
                              request()->routeIs('tenant.admin.delivery') ||
                              request()->routeIs('tenant.admin.seo') ||
                              request()->routeIs('tenant.admin.settings') ||
                              request()->routeIs('tenant.admin.payment-settings');
        @endphp

        <nav class="nav flex-column gap-1 admin-nav">
            {{-- Dashboard --}}
            <a class="nav-link {{ request()->routeIs('tenant.admin.dashboard') ? 'active' : '' }}"
               href="{{ route('tenant.admin.dashboard', ['tenant' => $currentTenant->slug]) }}">
                <i class="fa-solid fa-gauge me-2"></i> Dashboard
            </a>

            <div class="admin-nav-divider"></div>

            {{-- Operação --}}
            <div class="accordion admin-nav-accordion" id="menuOperacao">
                <div class="accordion-item">
                    <button class="accordion-button {{ request()->routeIs('tenant.admin.orders.*') || request()->routeIs('tenant.admin.customers.*') ? 'has-active' : '' }} {{ request()->routeIs('tenant.admin.orders.*') || request()->routeIs('tenant.admin.customers.*') ? '' : 'collapsed' }}"
                            type="button" data-bs-toggle="collapse" data-bs-target="#collapseOperacao">
                        <i class="fa-solid fa-store me-2"></i> Operação
                    </button>
                    <div id="collapseOperacao" class="accordion-collapse collapse {{ request()->routeIs('tenant.admin.orders.*') || request()->routeIs('tenant.admin.customers.*') ? 'show' : '' }}" data-bs-parent="#menuOperacao">
                        <div class="accordion-body nav flex-column gap-1">
                            <a class="nav-link {{ request()->routeIs('tenant.admin.orders.*') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.orders.index', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-receipt me-2"></i> Pedidos
                            </a>
                            <a class="nav-link {{ request()->routeIs('tenant.admin.customers.*') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.customers.index', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-users me-2"></i> Clientes
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Catálogo --}}
            <div class="accordion admin-nav-accordion" id="menuCatalogo">
                <div class="accordion-item">
                    <button class="accordion-button {{ request()->routeIs('tenant.admin.products.*') || request()->routeIs('tenant.admin.categories.*') || request()->routeIs('tenant.admin.showcases.*') ? 'has-active' : '' }} {{ request()->routeIs('tenant.admin.products.*') || request()->routeIs('tenant.admin.categories.*') || request()->routeIs('tenant.admin.showcases.*') ? '' : 'collapsed' }}"
                            type="button" data-bs-toggle="collapse" data-bs-target="#collapseCatalogo">
                        <i class="fa-solid fa-boxes-stacked me-2"></i> Catálogo
                    </button>
                    <div id="collapseCatalogo" class="accordion-collapse collapse {{ request()->routeIs('tenant.admin.products.*') || request()->routeIs('tenant.admin.categories.*') || request()->routeIs('tenant.admin.showcases.*') ? 'show' : '' }}" data-bs-parent="#menuCatalogo">
                        <div class="accordion-body nav flex-column gap-1">
                            <a class="nav-link {{ request()->routeIs('tenant.admin.products.*') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.products.index', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-box me-2"></i> Produtos
                            </a>
                            <a class="nav-link {{ request()->routeIs('tenant.admin.categories.*') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.categories.index', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-tags me-2"></i> Categorias
                            </a>
                            <a class="nav-link {{ request()->routeIs('tenant.admin.showcases.*') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.showcases.index', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-window-restore me-2"></i> Vitrines
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Configurações --}}
            <div class="accordion admin-nav-accordion" id="menuConfig">
                <div class="accordion-item">
                    <button class="accordion-button {{ $isConfigActive ? 'has-active' : '' }} {{ $isConfigActive ? '' : 'collapsed' }}"
                            type="button" data-bs-toggle="collapse" data-bs-target="#collapseConfig">
                        <i class="fa-solid fa-gear me-2"></i> Configurações
                    </button>
                    <div id="collapseConfig" class="accordion-collapse collapse {{ $isConfigActive ? 'show' : '' }}" data-bs-parent="#menuConfig">
                        <div class="accordion-body nav flex-column gap-1">
                            <a class="nav-link {{ request()->routeIs('tenant.admin.store-info') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.store-info', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-circle-info me-2"></i> Informações
                            </a>
                            <a class="nav-link {{ request()->routeIs('tenant.admin.customization') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.customization', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-palette me-2"></i> Aparência
                            </a>
                            <a class="nav-link {{ request()->routeIs('tenant.admin.contact') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.contact', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-phone me-2"></i> Contato
                            </a>
                            <a class="nav-link {{ request()->routeIs('tenant.admin.address') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.address', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-location-dot me-2"></i> Endereço
                            </a>
                            <a class="nav-link {{ request()->routeIs('tenant.admin.business-hours') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.business-hours', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-clock me-2"></i> Horários
                            </a>
                            <a class="nav-link {{ request()->routeIs('tenant.admin.social-links') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.social-links', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-share-nodes me-2"></i> Redes Sociais
                            </a>
                            <a class="nav-link {{ request()->routeIs('tenant.admin.delivery') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.delivery', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-truck me-2"></i> Entrega
                            </a>
                            @can('managePaymentMethods', $currentTenant)
                            <a class="nav-link {{ request()->routeIs('tenant.admin.payment-settings') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.payment-settings', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-credit-card me-2"></i> Pagamentos
                            </a>
                            @endcan
                            <a class="nav-link {{ request()->routeIs('tenant.admin.seo') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.seo', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-magnifying-glass me-2"></i> SEO
                            </a>
                            @can('updateSlug', $currentTenant)
                            <a class="nav-link {{ request()->routeIs('tenant.admin.settings') ? 'active' : '' }}"
                               href="{{ route('tenant.admin.settings', ['tenant' => $currentTenant->slug]) }}">
                                <i class="fa-solid fa-link me-2"></i> URL/Slug
                            </a>
                            @endcan
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <div class="mt-auto pt-3 border-top">
            <div class="text-muted small mb-2">
                {{ $tenantAdmin->name ?? '' }}
            </div>

            <div class="d-flex gap-2">
                <button type="button"
                        class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center size-38"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="Alternar tema claro/escuro"
                        id="themeToggleBtn"
                        aria-label="Alternar tema">
                    <i class="fa-solid fa-moon" id="themeToggleIcon"></i>
                </button>

                <form method="POST" action="{{ route('tenant.admin.logout', ['tenant' => $currentTenant->slug]) }}" class="flex-grow-1">
                    @csrf
                    <button class="btn btn-outline-secondary btn-sm w-100 h-38">
                        Sair
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Main --}}
    <div class="flex-grow-1 d-flex flex-column">

        {{-- Topbar (mobile) --}}
        <nav class="navbar bg-body-tertiary border-bottom d-lg-none px-3">
            <div class="container-fluid px-0">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center size-38" type="button"
                            data-bs-toggle="offcanvas" data-bs-target="#adminSidebarOffcanvas"
                            aria-controls="adminSidebarOffcanvas" aria-label="Abrir menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>

                    <div>
                        <div class="fw-semibold">{{ $currentTenant->name }}</div>
                        <div class="text-muted small">/{{ $currentTenant->slug }}</div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small">
                        Olá, {{ $tenantAdmin->name ?? '' }}!
                    </span>
                </div>
            </div>
        </nav>

        {{-- Sidebar (mobile offcanvas) --}}
        <div class="offcanvas offcanvas-start d-lg-none" tabindex="-1" id="adminSidebarOffcanvas" aria-labelledby="adminSidebarOffcanvasLabel">
            <div class="offcanvas-header justify-content-between">
                <div>
                    <div class="fw-semibold" id="adminSidebarOffcanvasLabel">{{ $currentTenant->name }}</div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    @can('toggleStoreStatus', $currentTenant)
                    <button type="button"
                            class="btn btn-outline-{{ $isPaused ? 'danger' : 'success' }} btn-sm d-flex align-items-center justify-content-center"
                            data-bs-toggle="tooltip"
                            data-bs-placement="bottom"
                            title="{{ $isPaused ? 'Loja fechada - Clique para abrir' : 'Loja aberta - Clique para fechar' }}"
                            onclick="toggleStoreStatus()"
                            id="storeStatusBtnMobile">
                        <i class="fa-solid fa-{{ $isPaused ? 'lock' : 'lock-open' }}" id="storeStatusIconMobile"></i>
                    </button>
                    @endcan

                    <a href="/{{ $currentTenant->slug }}"
                       target="_blank"
                       class="btn btn-outline-primary btn-sm d-flex align-items-center justify-content-center"
                       data-bs-toggle="tooltip"
                       data-bs-placement="bottom"
                       title="Ir para a área do cliente">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>

                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
                </div>
            </div>

            <div class="offcanvas-body d-flex flex-column">
                <nav class="nav flex-column gap-1 admin-nav">
                    {{-- Dashboard --}}
                    <a class="nav-link {{ request()->routeIs('tenant.admin.dashboard') ? 'active' : '' }}"
                       href="{{ route('tenant.admin.dashboard', ['tenant' => $currentTenant->slug]) }}"
                       data-bs-dismiss="offcanvas">
                        <i class="fa-solid fa-gauge me-2"></i> Dashboard
                    </a>

                    <div class="admin-nav-divider"></div>

                    {{-- Operação --}}
                    <div class="accordion admin-nav-accordion" id="menuOperacaoMobile">
                        <div class="accordion-item">
                            <button class="accordion-button {{ request()->routeIs('tenant.admin.orders.*') || request()->routeIs('tenant.admin.customers.*') ? 'has-active' : '' }} {{ request()->routeIs('tenant.admin.orders.*') || request()->routeIs('tenant.admin.customers.*') ? '' : 'collapsed' }}"
                                    type="button" data-bs-toggle="collapse" data-bs-target="#collapseOperacaoMobile">
                                <i class="fa-solid fa-store me-2"></i> Operação
                            </button>
                            <div id="collapseOperacaoMobile" class="accordion-collapse collapse {{ request()->routeIs('tenant.admin.orders.*') || request()->routeIs('tenant.admin.customers.*') ? 'show' : '' }}" data-bs-parent="#menuOperacaoMobile">
                                <div class="accordion-body nav flex-column gap-1">
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.orders.*') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.orders.index', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-receipt me-2"></i> Pedidos
                                    </a>
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.customers.*') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.customers.index', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-users me-2"></i> Clientes
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Catálogo --}}
                    <div class="accordion admin-nav-accordion" id="menuCatalogoMobile">
                        <div class="accordion-item">
                            <button class="accordion-button {{ request()->routeIs('tenant.admin.products.*') || request()->routeIs('tenant.admin.categories.*') || request()->routeIs('tenant.admin.showcases.*') ? 'has-active' : '' }} {{ request()->routeIs('tenant.admin.products.*') || request()->routeIs('tenant.admin.categories.*') || request()->routeIs('tenant.admin.showcases.*') ? '' : 'collapsed' }}"
                                    type="button" data-bs-toggle="collapse" data-bs-target="#collapseCatalogoMobile">
                                <i class="fa-solid fa-boxes-stacked me-2"></i> Catálogo
                            </button>
                            <div id="collapseCatalogoMobile" class="accordion-collapse collapse {{ request()->routeIs('tenant.admin.products.*') || request()->routeIs('tenant.admin.categories.*') || request()->routeIs('tenant.admin.showcases.*') ? 'show' : '' }}" data-bs-parent="#menuCatalogoMobile">
                                <div class="accordion-body nav flex-column gap-1">
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.products.*') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.products.index', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-box me-2"></i> Produtos
                                    </a>
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.categories.*') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.categories.index', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-tags me-2"></i> Categorias
                                    </a>
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.showcases.*') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.showcases.index', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-window-restore me-2"></i> Vitrines
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Configurações --}}
                    <div class="accordion admin-nav-accordion" id="menuConfigMobile">
                        <div class="accordion-item">
                            <button class="accordion-button {{ $isConfigActive ? 'has-active' : '' }} {{ $isConfigActive ? '' : 'collapsed' }}"
                                    type="button" data-bs-toggle="collapse" data-bs-target="#collapseConfigMobile">
                                <i class="fa-solid fa-gear me-2"></i> Configurações
                            </button>
                            <div id="collapseConfigMobile" class="accordion-collapse collapse {{ $isConfigActive ? 'show' : '' }}" data-bs-parent="#menuConfigMobile">
                                <div class="accordion-body nav flex-column gap-1">
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.store-info') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.store-info', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-circle-info me-2"></i> Informações
                                    </a>
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.customization') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.customization', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-palette me-2"></i> Aparência
                                    </a>
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.contact') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.contact', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-phone me-2"></i> Contato
                                    </a>
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.address') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.address', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-location-dot me-2"></i> Endereço
                                    </a>
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.business-hours') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.business-hours', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-clock me-2"></i> Horários
                                    </a>
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.social-links') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.social-links', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-share-nodes me-2"></i> Redes Sociais
                                    </a>
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.delivery') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.delivery', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-truck me-2"></i> Entrega
                                    </a>
                                    @can('managePaymentMethods', $currentTenant)
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.payment-settings') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.payment-settings', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-credit-card me-2"></i> Pagamentos
                                    </a>
                                    @endcan
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.seo') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.seo', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-magnifying-glass me-2"></i> SEO
                                    </a>
                                    @can('updateSlug', $currentTenant)
                                    <a class="nav-link {{ request()->routeIs('tenant.admin.settings') ? 'active' : '' }}"
                                       href="{{ route('tenant.admin.settings', ['tenant' => $currentTenant->slug]) }}"
                                       data-bs-dismiss="offcanvas">
                                        <i class="fa-solid fa-link me-2"></i> URL/Slug
                                    </a>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </div>
                </nav>

                <div class="mt-auto pt-3 border-top">
                    <div class="text-muted small mb-2">{{ $tenantAdmin->name ?? '' }}</div>

                    <div class="d-flex gap-2">
                        <button type="button"
                                class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center size-38"
                                data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                title="Alternar tema claro/escuro"
                                id="themeToggleBtnMobile"
                                aria-label="Alternar tema">
                            <i class="fa-solid fa-moon" id="themeToggleIconMobile"></i>
                        </button>

                        <form method="POST" action="{{ route('tenant.admin.logout', ['tenant' => $currentTenant->slug]) }}" class="flex-grow-1">
                            @csrf
                            <button class="btn btn-outline-secondary btn-sm w-100 h-38">
                                Sair
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <main class="container py-4">
            {{ $slot }}
        </main>

    </div>
</div>

<script>
(function () {
    function getPreferredTheme() {
        const stored = localStorage.getItem('theme');
        if (stored === 'light' || stored === 'dark') return stored;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function setTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        localStorage.setItem('theme', theme);

        const isDark = theme === 'dark';

        const ico = document.getElementById('themeToggleIcon');
        const icoM = document.getElementById('themeToggleIconMobile');

        if (ico) {
            ico.classList.toggle('fa-moon', !isDark);
            ico.classList.toggle('fa-sun', isDark);
        }
        if (icoM) {
            icoM.classList.toggle('fa-moon', !isDark);
            icoM.classList.toggle('fa-sun', isDark);
        }
    }

    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-bs-theme') || 'light';
        setTheme(current === 'dark' ? 'light' : 'dark');
    }

    // init
    setTheme(getPreferredTheme());

    // bind buttons (if present)
    const btn = document.getElementById('themeToggleBtn');
    const btnM = document.getElementById('themeToggleBtnMobile');
    if (btn) btn.addEventListener('click', toggleTheme);
    if (btnM) btnM.addEventListener('click', toggleTheme);
})();

// Toggle store status (open/closed)
function toggleStoreStatus() {
    const tenantSlug = '{{ $currentTenant->slug }}';
    
    fetch(`/${tenantSlug}/admin/toggle-store-status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const isPaused = data.paused;
            
            // Update desktop button
            const btn = document.getElementById('storeStatusBtn');
            const icon = document.getElementById('storeStatusIcon');
            if (btn && icon) {
                btn.className = `btn btn-outline-${isPaused ? 'danger' : 'success'} btn-sm d-flex align-items-center justify-content-center`;
                btn.setAttribute('title', isPaused ? 'Loja fechada - Clique para abrir' : 'Loja aberta - Clique para fechar');
                icon.className = `fa-solid fa-${isPaused ? 'lock' : 'lock-open'}`;
            }
            
            // Update mobile button
            const btnM = document.getElementById('storeStatusBtnMobile');
            const iconM = document.getElementById('storeStatusIconMobile');
            if (btnM && iconM) {
                btnM.className = `btn btn-outline-${isPaused ? 'danger' : 'success'} btn-sm d-flex align-items-center justify-content-center`;
                btnM.setAttribute('title', isPaused ? 'Loja fechada - Clique para abrir' : 'Loja aberta - Clique para fechar');
                iconM.className = `fa-solid fa-${isPaused ? 'lock' : 'lock-open'}`;
            }
            
            // Show toast notification
            const message = isPaused ? 'Loja fechada! Pedidos pausados.' : 'Loja aberta! Aceitando pedidos.';
            const toastType = isPaused ? 'warning' : 'success';
            showToast(message, toastType);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Erro ao alterar status da loja', 'danger');
    });
}

// Toast helper function - versão robusta
window.showToast = function(message, type = 'success') {
    console.log('[Toast] Chamado:', message, type);
    console.log('[Toast] Bootstrap disponível?', typeof bootstrap !== 'undefined');
    
    // Cria ou obtém o container de toasts
    let toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        console.log('[Toast] Criando container...');
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        toastContainer.style.zIndex = '9999';
        document.body.appendChild(toastContainer);
    }
    
    // Define classes baseadas no tipo
    const typeClasses = {
        'success': 'text-bg-success',
        'danger': 'text-bg-danger',
        'warning': 'text-bg-warning',
        'info': 'text-bg-info',
        'primary': 'text-bg-primary'
    };
    
    const toastClass = typeClasses[type] || typeClasses['success'];
    
    // Cria um novo toast
    const toastId = 'toast-' + Date.now();
    const toastHTML = `
        <div id="${toastId}" class="toast align-items-center border-0 ${toastClass}" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    
    toastContainer.insertAdjacentHTML('beforeend', toastHTML);
    const toastEl = document.getElementById(toastId);
    
    console.log('[Toast] Element criado:', toastEl);
    
    // Aguarda o Bootstrap estar disponível
    if (typeof bootstrap === 'undefined') {
        console.warn('[Toast] Bootstrap não disponível, usando fallback...');
        // Fallback: mostra o toast sem animação
        toastEl.classList.add('show');
        setTimeout(() => {
            toastEl.classList.remove('show');
            setTimeout(() => toastEl.remove(), 300);
        }, 3000);
        return;
    }
    
    // Usa Bootstrap Toast
    console.log('[Toast] Usando Bootstrap Toast...');
    const bsToast = new bootstrap.Toast(toastEl, {
        autohide: true,
        delay: 3000
    });
    
    // Remove o elemento após esconder
    toastEl.addEventListener('hidden.bs.toast', () => {
        toastEl.remove();
    });
    
    bsToast.show();
    console.log('[Toast] Toast.show() executado');
};

// Initialize Bootstrap tooltips
function initTooltips() {
    // Verifica se Bootstrap está disponível
    if (typeof bootstrap === 'undefined') {
        console.warn('Bootstrap não carregado, aguardando...');
        setTimeout(initTooltips, 100);
        return;
    }
    
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTooltips);
} else {
    initTooltips();
}

// Re-initialize after Livewire navigation
document.addEventListener('livewire:navigated', initTooltips);
</script>
@livewireScripts

<!-- Toast Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3 z-toast">
    <div id="liveToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage">
                Mensagem do toast
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

@stack('scripts')
</body>
</html>