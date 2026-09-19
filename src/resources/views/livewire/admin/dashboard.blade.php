<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h4 mb-0">Dashboard</h1>
            <div class="text-muted small">Visão geral do tenant</div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Tenant</div>
                    <div class="fw-semibold">{{ $tenant->name }}</div>
                    <div class="text-muted small">/{{ $tenant->slug }}</div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Catálogo</div>
                    <div class="fw-semibold">Produtos</div>
                    <div class="text-muted small">Em seguida: contadores reais</div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Pedidos</div>
                    <div class="fw-semibold">Em breve</div>
                    <div class="text-muted small">Lista + status</div>
                </div>
            </div>
        </div>
    </div>
</div>