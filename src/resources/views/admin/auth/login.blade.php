<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Admin - {{ $tenant->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @php $currentTenant = $tenant @endphp
    @include('partials.tenant-theme')

    <script>
      // Apply saved/system theme early (light/dark)
      (function () {
        try {
          const stored = localStorage.getItem('theme');
          const theme = (stored === 'light' || stored === 'dark')
            ? stored
            : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
          document.documentElement.setAttribute('data-bs-theme', theme);
        } catch (e) {}
      })();
    </script>
</head>
<body class="bg-body text-body">

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    @php
                        $logoUrl = $tenant->logo_path ? \Illuminate\Support\Facades\Storage::url($tenant->logo_path) : null;
                    @endphp

                    @if($logoUrl)
                        <div class="text-center mb-3">
                            <img src="{{ $logoUrl }}" alt="Logo" style="max-height: 56px; width: auto;" class="img-fluid">
                        </div>
                    @endif
                    <h1 class="h4 mb-1">Admin</h1>
                    <div class="text-muted mb-4">{{ $tenant->name }}</div>

                    @if ($errors->any())
                        <div class="alert alert-danger">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('tenant.admin.login.post', ['tenant' => $tenant->slug]) }}">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label">E-mail</label>
                            <input name="email" type="email" class="form-control" value="{{ old('email') }}" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Senha</label>
                            <input name="password" type="password" class="form-control" required>
                        </div>

                        <button class="btn btn-primary w-100" type="submit">Entrar</button>
                    </form>

                    <hr>
                    <div class="small text-body-secondary">
                        Acesse com seu usuário administrador deste tenant.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>