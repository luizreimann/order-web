@php
    // E5.1 (UI-007): nada que veio do banco é interpolado cru em CSS. Tudo passa pela
    // allow-list de App\Support\TenantTheme (cores hex válidas; fonte só da lista conhecida),
    // com fallback para o padrão. Não assume que a validação de escrita foi respeitada.
    $primary = \App\Support\TenantTheme::primary($currentTenant->primary_color);
    // Mesma cor, em tripla `r, g, b` (só inteiros): alimenta `--bs-primary-rgb`, que o Bootstrap usa em
    // `.text-primary`, `.bg-primary`, `.border-primary` e nos anéis de foco.
    $primaryRgb = \App\Support\TenantTheme::primaryRgb($currentTenant->primary_color);
    $secondary = \App\Support\TenantTheme::secondary($currentTenant->secondary_color);
    $fontFamily = \App\Support\TenantTheme::fontStack(data_get($currentTenant->settings, 'theme.font_family'));

    // URL do Google Fonts vem da MESMA allow-list (nunca do valor cru).
    $googleFontUrl = \App\Support\TenantTheme::googleFontUrl($fontFamily);
@endphp

@if($googleFontUrl)
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="{{ $googleFontUrl }}" rel="stylesheet">
@endif

<style>
    /*
      Tenant theme overrides
      - Uses Bootstrap 5.3 CSS variables so components update automatically.
      - Applies in both light and dark modes (data-bs-theme).
    */

    :root {
        /* Tenant tokens */
        --tenant-primary: {{ $primary }};
        --tenant-primary-rgb: {{ $primaryRgb }};
        --tenant-secondary: {{ $secondary }};
        --tenant-font-family: {{ $fontFamily }};
    }

    /*
      `[data-bs-theme="light"]` junto de `:root`: o Bootstrap redefine TODAS as variáveis `--bs-*` em qualquer
      contêiner com data-bs-theme (ex.: o preview da loja em Personalização, que fica claro mesmo no admin
      escuro). Sem este seletor o tema do tenant sumiria dentro dele.
    */
    :root,
    [data-bs-theme="light"] {
        /* Core typography */
        --bs-body-font-family: var(--tenant-font-family);

        /* Brand colors (Bootstrap core hooks) */
        --bs-primary: var(--tenant-primary);
        --bs-secondary: var(--tenant-secondary);

        /*
          Tripla RGB: o Bootstrap compõe `rgba(var(--bs-primary-rgb), a)` em .text-primary/.bg-primary/
          .border-primary etc. Só a primária: `--bs-secondary-rgb` continua neutro de propósito, porque
          badges/status "secondary" (Cancelado, Inativo, Esgotado...) não podem mudar de cor por loja (D8).
        */
        --bs-primary-rgb: var(--tenant-primary-rgb);

        /* Links */
        --bs-link-color: var(--tenant-primary);
        --bs-link-color-rgb: var(--tenant-primary-rgb);
        --bs-link-hover-color: color-mix(in srgb, var(--tenant-primary) 85%, black);
        --bs-link-hover-color-rgb: var(--tenant-primary-rgb);

        /* Focus ring */
        --bs-focus-ring-color: color-mix(in srgb, var(--tenant-primary) 35%, transparent);
        --tenant-focus-border: color-mix(in srgb, var(--tenant-primary) 55%, white);

        /* These "emphasis/subtle" vars are used by alerts, backgrounds, etc */
        --bs-primary-text-emphasis: color-mix(in srgb, var(--tenant-primary) 75%, black);
        --bs-primary-bg-subtle: color-mix(in srgb, var(--tenant-primary) 12%, white);
        --bs-primary-border-subtle: color-mix(in srgb, var(--tenant-primary) 28%, white);

        --bs-secondary-text-emphasis: color-mix(in srgb, var(--tenant-secondary) 75%, black);
        --bs-secondary-bg-subtle: color-mix(in srgb, var(--tenant-secondary) 12%, white);
        --bs-secondary-border-subtle: color-mix(in srgb, var(--tenant-secondary) 28%, white);
    }

    /* Dark mode tuning */
    [data-bs-theme="dark"] {
        --bs-link-color: color-mix(in srgb, var(--tenant-primary) 80%, white);
        --bs-link-color-rgb: var(--tenant-primary-rgb);
        --bs-link-hover-color: color-mix(in srgb, var(--tenant-primary) 70%, white);
        --bs-link-hover-color-rgb: var(--tenant-primary-rgb);

        --bs-focus-ring-color: color-mix(in srgb, var(--tenant-primary) 45%, transparent);

        --bs-primary-text-emphasis: color-mix(in srgb, var(--tenant-primary) 70%, white);
        --bs-primary-bg-subtle: color-mix(in srgb, var(--tenant-primary) 18%, #000);
        --bs-primary-border-subtle: color-mix(in srgb, var(--tenant-primary) 35%, #000);

        --bs-secondary-text-emphasis: color-mix(in srgb, var(--tenant-secondary) 70%, white);
        --bs-secondary-bg-subtle: color-mix(in srgb, var(--tenant-secondary) 18%, #000);
        --bs-secondary-border-subtle: color-mix(in srgb, var(--tenant-secondary) 35%, #000);
    }

    /*
      Component-level overrides
      These ensure Bootstrap components consistently pick up the tenant brand.
    */

    /* Buttons */
    .btn-primary {
        --bs-btn-bg: var(--tenant-primary);
        --bs-btn-border-color: var(--tenant-primary);
        --bs-btn-hover-bg: color-mix(in srgb, var(--tenant-primary) 90%, black);
        --bs-btn-hover-border-color: color-mix(in srgb, var(--tenant-primary) 90%, black);
        --bs-btn-active-bg: color-mix(in srgb, var(--tenant-primary) 80%, black);
        --bs-btn-active-border-color: color-mix(in srgb, var(--tenant-primary) 80%, black);
        --bs-btn-focus-shadow-rgb: var(--tenant-primary-rgb);
        /* o Bootstrap fixa o desabilitado em #0d6efd */
        --bs-btn-disabled-bg: var(--tenant-primary);
        --bs-btn-disabled-border-color: var(--tenant-primary);
    }

    .btn-outline-primary {
        --bs-btn-color: var(--tenant-primary);
        --bs-btn-border-color: var(--tenant-primary);
        --bs-btn-hover-bg: var(--tenant-primary);
        --bs-btn-hover-border-color: var(--tenant-primary);
        --bs-btn-hover-color: #fff;
        --bs-btn-active-bg: color-mix(in srgb, var(--tenant-primary) 85%, black);
        --bs-btn-active-border-color: color-mix(in srgb, var(--tenant-primary) 85%, black);
        --bs-btn-focus-shadow-rgb: var(--tenant-primary-rgb);
        --bs-btn-disabled-color: var(--tenant-primary);
        --bs-btn-disabled-border-color: var(--tenant-primary);
    }

    .btn-link {
        --bs-btn-focus-shadow-rgb: var(--tenant-primary-rgb);
    }

    .btn-secondary {
        --bs-btn-bg: var(--tenant-secondary);
        --bs-btn-border-color: var(--tenant-secondary);
        --bs-btn-hover-bg: color-mix(in srgb, var(--tenant-secondary) 90%, black);
        --bs-btn-hover-border-color: color-mix(in srgb, var(--tenant-secondary) 90%, black);
        --bs-btn-active-bg: color-mix(in srgb, var(--tenant-secondary) 80%, black);
        --bs-btn-active-border-color: color-mix(in srgb, var(--tenant-secondary) 80%, black);
    }

    .btn-outline-secondary {
        --bs-btn-color: var(--tenant-secondary);
        --bs-btn-border-color: var(--tenant-secondary);
        --bs-btn-hover-bg: var(--tenant-secondary);
        --bs-btn-hover-border-color: var(--tenant-secondary);
        --bs-btn-hover-color: #fff;
    }

    /* Nav pills */
    .nav-pills {
        --bs-nav-pills-link-active-bg: var(--tenant-primary);
    }

    /* Pagination */
    .pagination {
        --bs-pagination-active-bg: var(--tenant-primary);
        --bs-pagination-active-border-color: var(--tenant-primary);
    }

    /* Form controls: focus + checked */
    .form-control:focus,
    .form-select:focus,
    .form-check-input:focus {
        border-color: var(--tenant-focus-border);
        box-shadow: 0 0 0 .25rem var(--bs-focus-ring-color);
    }

    .form-check-input:checked,
    .form-check-input[type="checkbox"]:indeterminate {
        background-color: var(--tenant-primary);
        border-color: var(--tenant-primary);
    }

    /*
      Foco que o CSS compilado do Bootstrap fixa em azul (#86b7fe / rgba(13,110,253,.25)): cada um
      passa a ler o anel de foco do tenant. Sem isto, ao navegar por teclado o azul padrão aparece.
    */
    .nav-link:focus-visible {
        box-shadow: 0 0 0 .25rem var(--bs-focus-ring-color);
    }

    .btn-close {
        --bs-btn-close-focus-shadow: 0 0 0 .25rem var(--bs-focus-ring-color);
    }

    .pagination {
        --bs-pagination-focus-box-shadow: 0 0 0 .25rem var(--bs-focus-ring-color);
    }

    .accordion {
        --bs-accordion-btn-focus-box-shadow: 0 0 0 .25rem var(--bs-focus-ring-color);
    }

    .form-range::-webkit-slider-thumb {
        background-color: var(--tenant-primary);
    }
    .form-range::-webkit-slider-thumb:active {
        background-color: var(--tenant-focus-border);
    }
    .form-range::-moz-range-thumb {
        background-color: var(--tenant-primary);
    }
    .form-range::-moz-range-thumb:active {
        background-color: var(--tenant-focus-border);
    }
    .form-range:focus::-webkit-slider-thumb {
        box-shadow: 0 0 0 1px var(--bs-body-bg), 0 0 0 .25rem var(--bs-focus-ring-color);
    }
    .form-range:focus::-moz-range-thumb {
        box-shadow: 0 0 0 1px var(--bs-body-bg), 0 0 0 .25rem var(--bs-focus-ring-color);
    }

    /*
      Bolinha do switch com foco: no Bootstrap é um SVG com fill #86b7fe (azul fixo). Aqui vira um gradiente
      radial na cor de foco do tenant (mesmo diâmetro: 6/8 do quadrado). Só no estado "desmarcado + foco":
      marcado continua com a bolinha branca do Bootstrap.
    */
    .form-switch .form-check-input:focus:not(:checked) {
        --bs-form-switch-bg: radial-gradient(circle closest-side, var(--tenant-focus-border) 72%, transparent 78%);
        background-size: calc(1em - 2 * var(--bs-border-width)) calc(1em - 2 * var(--bs-border-width));
    }

    /* Dropdown active */
    .dropdown-menu {
        --bs-dropdown-link-active-bg: var(--tenant-primary);
        --bs-dropdown-link-active-color: #fff;
    }

    /* Progress */
    .progress {
        --bs-progress-bar-bg: var(--tenant-primary);
    }

    /* Simple link fallback (covers elements not using vars) */
    a {
        color: var(--bs-link-color);
    }
    a:hover {
        color: var(--bs-link-hover-color);
    }

    /* Badges com cores do tenant */
    .badge.bg-primary,
    .badge.text-bg-primary {
        --bs-badge-color: #fff;
        background-color: var(--tenant-primary) !important;
    }

    /*
      NÃO sobrescrever `.badge.bg-secondary`/`.text-bg-secondary` com a cor do tenant: é o "neutro" dos
      status (Cancelado, Fechado, Esgotado, Inativo...) e deles dependem o significado e o contraste (D8).
    */

    /*
      Tema escuro: o texto na cor crua do tenant pode ficar ilegível sobre fundo escuro (a cor é do lojista:
      um tom escuro some). Texto/contorno usam a variante "clareada" que o próprio tema já calcula
      (--bs-link-color / --bs-secondary-text-emphasis); preenchimentos continuam na cor do tenant.
    */
    [data-bs-theme="dark"] .btn-outline-primary {
        --bs-btn-color: var(--bs-link-color);
    }

    [data-bs-theme="dark"] .btn-outline-secondary {
        --bs-btn-color: var(--bs-secondary-text-emphasis);
    }

    [data-bs-theme="dark"] .text-primary,
    [data-bs-theme="dark"] .text-tenant-primary {
        color: var(--bs-link-color) !important;
    }

    /* Badge outline variants para contraste */
    .badge.badge-outline-primary {
        color: var(--tenant-primary);
        background-color: color-mix(in srgb, var(--tenant-primary) 10%, transparent);
        border: 1px solid var(--tenant-primary);
    }

    .badge.badge-outline-secondary {
        color: var(--tenant-secondary);
        background-color: color-mix(in srgb, var(--tenant-secondary) 10%, transparent);
        border: 1px solid var(--tenant-secondary);
    }

    /* Dark mode adjustments for badges */
    [data-bs-theme="dark"] .badge.bg-primary,
    [data-bs-theme="dark"] .badge.text-bg-primary {
        background-color: color-mix(in srgb, var(--tenant-primary) 85%, white) !important;
        color: #000;
    }

    [data-bs-theme="dark"] .badge.badge-outline-primary {
        color: color-mix(in srgb, var(--tenant-primary) 80%, white);
        background-color: color-mix(in srgb, var(--tenant-primary) 15%, transparent);
        border-color: color-mix(in srgb, var(--tenant-primary) 60%, white);
    }

    [data-bs-theme="dark"] .badge.badge-outline-secondary {
        color: color-mix(in srgb, var(--tenant-secondary) 80%, white);
        background-color: color-mix(in srgb, var(--tenant-secondary) 15%, transparent);
        border-color: color-mix(in srgb, var(--tenant-secondary) 60%, white);
    }

    /* Alerts com cores do tenant */
    .alert-primary {
        --bs-alert-bg: color-mix(in srgb, var(--tenant-primary) 12%, white);
        --bs-alert-border-color: color-mix(in srgb, var(--tenant-primary) 28%, white);
        --bs-alert-color: color-mix(in srgb, var(--tenant-primary) 75%, black);
    }

    .alert-secondary {
        --bs-alert-bg: color-mix(in srgb, var(--tenant-secondary) 12%, white);
        --bs-alert-border-color: color-mix(in srgb, var(--tenant-secondary) 28%, white);
        --bs-alert-color: color-mix(in srgb, var(--tenant-secondary) 75%, black);
    }

    [data-bs-theme="dark"] .alert-primary {
        --bs-alert-bg: color-mix(in srgb, var(--tenant-primary) 18%, #000);
        --bs-alert-border-color: color-mix(in srgb, var(--tenant-primary) 35%, #000);
        --bs-alert-color: color-mix(in srgb, var(--tenant-primary) 70%, white);
    }

    [data-bs-theme="dark"] .alert-secondary {
        --bs-alert-bg: color-mix(in srgb, var(--tenant-secondary) 18%, #000);
        --bs-alert-border-color: color-mix(in srgb, var(--tenant-secondary) 35%, #000);
        --bs-alert-color: color-mix(in srgb, var(--tenant-secondary) 70%, white);
    }

    /* Nav tabs active state */
    .nav-tabs {
        --bs-nav-tabs-link-active-color: var(--tenant-primary);
        --bs-nav-tabs-link-active-border-color: var(--bs-border-color) var(--bs-border-color) var(--bs-body-bg);
    }

    .nav-tabs .nav-link.active {
        color: var(--tenant-primary);
        font-weight: 500;
    }

    /* List group active */
    .list-group {
        --bs-list-group-active-bg: var(--tenant-primary);
        --bs-list-group-active-border-color: var(--tenant-primary);
    }

    .list-group-item.active {
        background-color: var(--tenant-primary);
        border-color: var(--tenant-primary);
    }

    /* Spinners */
    .spinner-border.text-primary,
    .spinner-grow.text-primary {
        color: var(--tenant-primary) !important;
    }

    /* Toast customization */
    .toast.text-bg-primary {
        background-color: var(--tenant-primary) !important;
        color: #fff;
    }

    .toast.text-bg-secondary {
        background-color: var(--tenant-secondary) !important;
        color: #fff;
    }

    [data-bs-theme="dark"] .toast.text-bg-primary {
        background-color: color-mix(in srgb, var(--tenant-primary) 90%, white) !important;
    }

    [data-bs-theme="dark"] .toast.text-bg-secondary {
        background-color: color-mix(in srgb, var(--tenant-secondary) 90%, white) !important;
    }
</style>