@php
    $primary = $currentTenant->primary_color ?: '#0d6efd';
    $secondary = $currentTenant->secondary_color ?: '#6c757d';

    // Stored as a CSS font-family string.
    $fontFamily = data_get($currentTenant->settings, 'theme.font_family')
        ?: 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';

    // Try to detect a Google Font from the first family token.
    // Examples:
    // "Inter, system-ui, ..." => Inter
    // "\"Poppins\", system-ui, ..." => Poppins
    $firstToken = trim(explode(',', $fontFamily)[0] ?? '');
    $firstToken = trim($firstToken, " \t\n\r\0\x0B\"'");

    // Map supported fonts to Google Fonts CSS URLs (extend as needed)
    $googleFonts = [
        'Inter' => 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
        'Poppins' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap',
        'Montserrat' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap',
        'Roboto' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap',
    ];

    $googleFontUrl = $googleFonts[$firstToken] ?? null;
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
        --tenant-secondary: {{ $secondary }};
        --tenant-font-family: {{ $fontFamily }};

        /* Core typography */
        --bs-body-font-family: var(--tenant-font-family);

        /* Brand colors (Bootstrap core hooks) */
        --bs-primary: var(--tenant-primary);
        --bs-secondary: var(--tenant-secondary);

        /* Links */
        --bs-link-color: var(--tenant-primary);
        --bs-link-hover-color: color-mix(in srgb, var(--tenant-primary) 85%, black);

        /* Focus ring */
        --bs-focus-ring-color: color-mix(in srgb, var(--tenant-primary) 35%, transparent);

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
        --bs-link-hover-color: color-mix(in srgb, var(--tenant-primary) 70%, white);

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
        --bs-btn-focus-shadow-rgb: 13,110,253; /* fallback; focus ring var above handles most cases */
    }

    .btn-outline-primary {
        --bs-btn-color: var(--tenant-primary);
        --bs-btn-border-color: var(--tenant-primary);
        --bs-btn-hover-bg: var(--tenant-primary);
        --bs-btn-hover-border-color: var(--tenant-primary);
        --bs-btn-hover-color: #fff;
        --bs-btn-active-bg: color-mix(in srgb, var(--tenant-primary) 85%, black);
        --bs-btn-active-border-color: color-mix(in srgb, var(--tenant-primary) 85%, black);
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
        border-color: color-mix(in srgb, var(--tenant-primary) 55%, white);
        box-shadow: 0 0 0 .25rem var(--bs-focus-ring-color);
    }

    .form-check-input:checked {
        background-color: var(--tenant-primary);
        border-color: var(--tenant-primary);
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

    .badge.bg-secondary,
    .badge.text-bg-secondary {
        --bs-badge-color: #fff;
        background-color: var(--tenant-secondary) !important;
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

    [data-bs-theme="dark"] .badge.bg-secondary,
    [data-bs-theme="dark"] .badge.text-bg-secondary {
        background-color: color-mix(in srgb, var(--tenant-secondary) 85%, white) !important;
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