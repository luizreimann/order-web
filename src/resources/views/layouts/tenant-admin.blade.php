<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin - {{ $currentTenant->name ?? 'Tenant' }}</title>

    {{-- Único script inline (E4.3): aplica o tema salvo ANTES do primeiro paint. O módulo do Vite é
         deferido (roda depois do render), então aplicar lá causaria flash do tema claro em quem
         usa o escuro. Mesma regra de resources/js/admin/theme.js (localStorage `theme`, senão
         prefers-color-scheme). Todo o resto do JS está em resources/js/admin/. --}}
    <script>
        (function () {
            var t;
            try { t = localStorage.getItem('theme'); } catch (e) {}
            if (t !== 'light' && t !== 'dark') {
                t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/admin.js'])
    @include('partials.tenant-theme')
    @livewireStyles
</head>

<body class="bg-body text-body">
<div class="admin-shell d-flex min-vh-100">

    {{-- Sidebar (desktop) --}}
    <aside class="admin-sidebar bg-body-tertiary border-end d-none d-lg-flex flex-column p-3">
        <div class="mb-3 d-flex align-items-start justify-content-between gap-2">
            <div>
                <div class="fw-semibold">{{ $currentTenant->name }}</div>
            </div>

            <div class="d-flex gap-2">
                <x-admin-store-actions />
            </div>
        </div>

        <x-admin-nav />

        <x-admin-account />
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
                    <x-admin-store-actions mode="offcanvas" />

                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
                </div>
            </div>

            <div class="offcanvas-body d-flex flex-column">
                <x-admin-nav mode="offcanvas" />

                <x-admin-account mode="offcanvas" />
            </div>
        </div>

        <main class="container py-4">
            {{ $slot }}
        </main>

    </div>
</div>

@livewireScripts

<x-toast-container />

@stack('scripts')
</body>
</html>
