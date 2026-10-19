{{--
    Rodapé do menu admin (E4.3): nome do usuário, alternar tema e "Sair". Sidebar e offcanvas.

    <x-admin-account />                     ids: themeToggleBtn / themeToggleIcon
    <x-admin-account mode="offcanvas" />    ids: themeToggleBtnMobile / themeToggleIconMobile

    O JS (resources/js/admin/theme.js) liga o clique em #themeToggleBtn[Mobile] e troca o ícone
    #themeToggleIcon[Mobile] (fa-moon <-> fa-sun).
--}}
@props(['mode' => 'sidebar'])
@php($suffix = $mode === 'offcanvas' ? 'Mobile' : '')
<div class="mt-auto pt-3 border-top">
    <div class="text-muted small mb-2">{{ $tenantAdmin->name ?? '' }}</div>

    <div class="d-flex gap-2">
        <button type="button"
                class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center size-38"
                data-bs-toggle="tooltip"
                data-bs-placement="top"
                title="Alternar tema claro/escuro"
                id="themeToggleBtn{{ $suffix }}"
                aria-label="Alternar tema">
            <i class="fa-solid fa-moon" id="themeToggleIcon{{ $suffix }}"></i>
        </button>

        <form method="POST" action="{{ route('tenant.admin.logout', ['tenant' => $currentTenant->slug]) }}" class="flex-grow-1">
            @csrf
            <button class="btn btn-outline-secondary btn-sm w-100 h-38">
                Sair
            </button>
        </form>
    </div>
</div>
