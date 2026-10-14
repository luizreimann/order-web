<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Web — Plataforma para lojas de encomendas</title>
    <meta name="description" content="Plataforma para lojas de encomendas: catálogo, pedidos e pagamentos com a identidade visual da sua marca.">

    @vite(['resources/css/app.css'])
</head>
<body class="bg-light d-flex flex-column min-vh-100">
    <header class="border-bottom bg-white">
        <div class="container py-3">
            <span class="fw-bold fs-5">Order Web</span>
        </div>
    </header>

    <main class="flex-grow-1 d-flex align-items-center">
        <div class="container py-5">
            <div class="row">
                <div class="col-lg-8">
                    <h1 class="display-5 fw-bold mb-3">Sua loja de encomendas, com a sua marca.</h1>
                    <p class="lead text-body-secondary mb-0">
                        O Order Web ajuda lojas que trabalham sob encomenda a organizar catálogo,
                        pedidos e pagamentos em um só lugar, com loja online própria e painel de gestão.
                    </p>
                </div>
            </div>
        </div>
    </main>

    <footer class="border-top bg-white">
        <div class="container py-3 small text-body-secondary">
            &copy; {{ date('Y') }} Order Web
        </div>
    </footer>
</body>
</html>
