{{--
    Botões do topo do menu admin (E4.3): abrir/fechar a loja (só com `toggleStoreStatus`) e "Ir
    para a área do cliente". Usado na sidebar e no offcanvas.

    <x-admin-store-actions />                     ids: storeStatusBtn / storeStatusIcon
    <x-admin-store-actions mode="offcanvas" />    ids: storeStatusBtnMobile / storeStatusIconMobile

    O JS (resources/js/admin/store-status.js) delega o clique em [data-store-status-toggle], faz
    POST em data-store-status-url e troca classe/título/ícone usando data-label-* (os textos
    nascem aqui, em um lugar só). Tooltips: data-bs-toggle="tooltip" (admin/tooltips.js).
--}}
@props(['mode' => 'sidebar'])
@php
    $isPaused = (bool) data_get($currentTenant->settings, 'business_hours.paused', false);
    $suffix = $mode === 'offcanvas' ? 'Mobile' : '';
    $labelPaused = 'Loja fechada - Clique para abrir';
    $labelOpen = 'Loja aberta - Clique para fechar';
@endphp
@can('toggleStoreStatus', $currentTenant)
<button type="button"
        class="btn btn-outline-{{ $isPaused ? 'danger' : 'success' }} btn-sm d-flex align-items-center justify-content-center"
        data-bs-toggle="tooltip"
        data-bs-placement="bottom"
        title="{{ $isPaused ? $labelPaused : $labelOpen }}"
        data-store-status-toggle
        data-store-status-url="{{ route('tenant.admin.toggle-store-status', ['tenant' => $currentTenant->slug], false) }}"
        data-label-paused="{{ $labelPaused }}"
        data-label-open="{{ $labelOpen }}"
        id="storeStatusBtn{{ $suffix }}">
    <i class="fa-solid fa-{{ $isPaused ? 'lock' : 'lock-open' }}" id="storeStatusIcon{{ $suffix }}"></i>
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
