<div class="vstack gap-3">
    <x-page-header title="Dashboard" subtitle="Visão geral do tenant" />

    <div class="row g-3">
        <div class="col-md-4">
            <x-card>
                <div class="text-muted small">Tenant</div>
                <div class="fw-semibold">{{ $tenant->name }}</div>
                <div class="text-muted small">/{{ $tenant->slug }}</div>
            </x-card>
        </div>

        <div class="col-md-4">
            <x-card>
                <div class="text-muted small">Catálogo</div>
                <div class="fw-semibold">Produtos</div>
                <div class="text-muted small">Em seguida: contadores reais</div>
            </x-card>
        </div>

        <div class="col-md-4">
            <x-card>
                <div class="text-muted small">Pedidos</div>
                <div class="fw-semibold">Em breve</div>
                <div class="text-muted small">Lista + status</div>
            </x-card>
        </div>
    </div>
</div>
