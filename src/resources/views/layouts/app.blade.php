<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Order Web') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.tenant-theme')
    @include('partials.tenant-meta')

    @livewireStyles
</head>
<body class="bg-light">
    @php
        // Busca o tenant da rota ou do Livewire
        $tenant = $tenant ?? request()->route('tenant') ?? null;
        $cartCount = 0;
        $customer = null;
        
        if ($tenant) {
            $cartCount = app(\App\Services\CartService::class)->count($tenant);
            $customer = auth('customer')->user();
        }
    @endphp

    @if($tenant)
        <x-tenant-header :tenant="$tenant" :cartCount="$cartCount" :customer="$customer" />
    @endif

    {{ $slot }}

    @include('partials.tenant-footer')
    @include('components.whatsapp-button')

    @if($tenant)
        <x-contact-modal :tenant="$tenant" />
        @livewire('tenant.auth.login-popup', ['tenant' => $tenant])
    @endif

    @livewireScripts
    @stack('scripts')
</body>
</html>