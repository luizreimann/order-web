@php
    use App\Support\TenantHelper;
    use Illuminate\Support\Facades\Storage;

    $seo = TenantHelper::getSeoData($currentTenant);
    $faviconPath = data_get($currentTenant->settings, 'theme.favicon_path');
    $bannerPath = data_get($currentTenant->settings, 'theme.banner_path');

    $ogImage = null;
    if ($seo['og_image']) {
        $ogImage = Storage::url($seo['og_image']);
    } elseif ($bannerPath) {
        $ogImage = Storage::url($bannerPath);
    } elseif ($currentTenant->logo_path) {
        $ogImage = Storage::url($currentTenant->logo_path);
    }
@endphp

<title>{{ $seo['title'] }}</title>
<meta name="description" content="{{ $seo['description'] }}">

<meta property="og:title" content="{{ $seo['title'] }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url()->current() }}">
@if($ogImage)
    <meta property="og:image" content="{{ url($ogImage) }}">
@endif

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo['title'] }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
@if($ogImage)
    <meta name="twitter:image" content="{{ url($ogImage) }}">
@endif

@if($faviconPath)
    <link rel="icon" href="{{ Storage::url($faviconPath) }}" type="image/png">
    <link rel="shortcut icon" href="{{ Storage::url($faviconPath) }}">
@endif

@if($seo['google_analytics_id'])
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $seo['google_analytics_id'] }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ $seo['google_analytics_id'] }}');
    </script>
@endif
