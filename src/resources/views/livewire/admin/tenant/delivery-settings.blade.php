<div class="vstack gap-3">
    <x-page-header title="Configurações de Entrega" subtitle="Taxa, pedido mínimo e tempo estimado" />

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <x-card>
        <form wire:submit.prevent="save" class="row g-3">
            <div class="col-md-6">
                <x-form-field type="number" name="minimum_order" label="Pedido mínimo (R$)"
                              help="Valor mínimo para aceitar pedidos. Deixe 0 para não ter mínimo."
                              wire:model="minimum_order" step="0.01" min="0" placeholder="0.00" />
            </div>

            <div class="col-md-6">
                <x-form-field type="number" name="default_fee" label="Taxa de entrega (R$)"
                              help="Taxa padrão cobrada por entrega."
                              wire:model="default_fee" step="0.01" min="0" placeholder="0.00" />
            </div>

            <div class="col-md-6">
                <x-form-field type="number" name="estimated_time_minutes" label="Tempo estimado de entrega (minutos)"
                              help="Tempo médio de entrega em minutos."
                              wire:model="estimated_time_minutes" min="1" max="480" placeholder="45" />
            </div>

            <div class="col-md-6">
                <x-form-field type="number" name="free_delivery_above" label="Entrega grátis acima de (R$)"
                              help="Pedidos acima deste valor têm entrega grátis. Deixe 0 para desabilitar."
                              wire:model="free_delivery_above" step="0.01" min="0" placeholder="0.00" />
            </div>

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar</x-btn>
            </div>
        </form>
    </x-card>
</div>
