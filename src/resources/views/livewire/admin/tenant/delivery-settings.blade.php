<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Configurações de Entrega</h1>
            <div class="text-muted small">Taxa, pedido mínimo e tempo estimado</div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Pedido mínimo (R$)</label>
                    <input type="number" step="0.01" min="0" class="form-control" wire:model="minimum_order" placeholder="0.00">
                    @error('minimum_order') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Valor mínimo para aceitar pedidos. Deixe 0 para não ter mínimo.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Taxa de entrega (R$)</label>
                    <input type="number" step="0.01" min="0" class="form-control" wire:model="default_fee" placeholder="0.00">
                    @error('default_fee') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Taxa padrão cobrada por entrega.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tempo estimado de entrega (minutos)</label>
                    <input type="number" min="1" max="480" class="form-control" wire:model="estimated_time_minutes" placeholder="45">
                    @error('estimated_time_minutes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Tempo médio de entrega em minutos.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Entrega grátis acima de (R$)</label>
                    <input type="number" step="0.01" min="0" class="form-control" wire:model="free_delivery_above" placeholder="0.00">
                    @error('free_delivery_above') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Pedidos acima deste valor têm entrega grátis. Deixe 0 para desabilitar.</div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
