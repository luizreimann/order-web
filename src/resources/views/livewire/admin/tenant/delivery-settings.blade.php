<div class="vstack gap-3">
    <x-page-header title="Retirada e Entrega" subtitle="Como o cliente recebe, taxas, bairros e pedido mínimo" />

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <form wire:submit.prevent="save" class="vstack gap-3">
        <x-card>
            <div class="vstack gap-3">
                <x-section-title>Pedido mínimo</x-section-title>

                <x-form-field type="number" name="minimum_order" label="Pedido mínimo (R$)"
                              help="Vale para retirada e entrega, sobre o valor dos produtos (sem taxas). Deixe 0 para não ter mínimo."
                              wire:model="minimum_order" step="0.01" min="0" placeholder="0.00" />
            </div>
        </x-card>

        <x-card>
            <div class="vstack gap-3">
                <x-section-title>Retirada na loja</x-section-title>

                <x-form-check name="pickup_enabled" switch label="Oferecer retirada" wire:model.live="pickup_enabled" />

                @if($pickup_enabled)
                    <x-form-field type="number" name="pickup_fee" label="Taxa de retirada (R$)"
                                  help="Cobrança adicional para retirar. Deixe 0 para não cobrar."
                                  wire:model="pickup_fee" step="0.01" min="0" placeholder="0.00" />
                @endif
            </div>
        </x-card>

        <x-card>
            <div class="vstack gap-3">
                <x-section-title>Entrega</x-section-title>

                <x-form-check name="delivery_enabled" switch label="Oferecer entrega" wire:model.live="delivery_enabled"
                              help="Desligada, o checkout só oferece retirada e não pede endereço." />

                @if($delivery_enabled)
                    <div class="row g-3">
                        <div class="col-md-6">
                            <x-form-field type="number" name="default_fee" label="Taxa de entrega padrão (R$)"
                                          help="Vale quando não há bairros ativos cadastrados abaixo."
                                          wire:model="default_fee" step="0.01" min="0" placeholder="0.00" />
                        </div>

                        <div class="col-md-6">
                            <x-form-field type="number" name="free_delivery_above" label="Entrega grátis acima de (R$)"
                                          help="Pedidos a partir deste valor têm entrega grátis. Deixe 0 para desabilitar."
                                          wire:model="free_delivery_above" step="0.01" min="0" placeholder="0.00" />
                        </div>

                        <div class="col-md-6">
                            <x-form-field type="number" name="estimated_time_minutes" label="Tempo estimado de entrega (minutos)"
                                          help="Tempo médio de entrega em minutos."
                                          wire:model="estimated_time_minutes" min="1" max="480" placeholder="45" />
                        </div>
                    </div>

                    <div class="vstack gap-2">
                        <x-section-title>Bairros e taxas</x-section-title>
                        <div class="text-body-secondary small">
                            Com bairros ativos, o cliente escolhe o bairro e paga a taxa dele. Sem nenhum bairro ativo, vale a taxa padrão.
                        </div>

                        @error('regions')
                            <div class="alert alert-danger mb-0" role="alert">{{ $message }}</div>
                        @enderror

                        @forelse($regions as $i => $region)
                            <div class="row g-2 align-items-start" wire:key="region-{{ $region['id'] }}">
                                <div class="col-sm-5">
                                    <x-form-field name="regions.{{ $i }}.name" id="region-name-{{ $i }}" label="Bairro"
                                                  wire:model="regions.{{ $i }}.name" maxlength="80" />
                                </div>
                                <div class="col-sm-3">
                                    <x-form-field type="number" name="regions.{{ $i }}.fee" id="region-fee-{{ $i }}" label="Taxa (R$)"
                                                  wire:model="regions.{{ $i }}.fee" step="0.01" min="0" />
                                </div>
                                <div class="col-sm-2 pt-sm-4">
                                    <x-form-check name="regions.{{ $i }}.active" id="region-active-{{ $i }}" switch label="Ativo"
                                                  :validates="false" wire:model="regions.{{ $i }}.active" />
                                </div>
                                <div class="col-sm-2 pt-sm-4 text-sm-end">
                                    <x-btn variant="danger" outline size="sm" wire:click="removeRegion({{ $i }})"
                                           aria-label="Remover o bairro {{ $region['name'] }}">
                                        Remover
                                    </x-btn>
                                </div>
                            </div>
                        @empty
                            <div class="text-body-secondary">Nenhum bairro cadastrado.</div>
                        @endforelse

                        <div>
                            <x-btn variant="secondary" outline size="sm" wire:click="addRegion" loading="addRegion">
                                Adicionar bairro
                            </x-btn>
                        </div>
                    </div>
                @endif
            </div>
        </x-card>

        @error('pickup_enabled')
            <div class="alert alert-danger mb-0" role="alert">{{ $message }}</div>
        @enderror

        <div>
            <x-btn type="submit" loading="save">Salvar</x-btn>
        </div>
    </form>
</div>
