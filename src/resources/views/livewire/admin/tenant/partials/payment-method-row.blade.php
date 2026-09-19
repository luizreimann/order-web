<tr wire:key="method-{{ $method['id'] }}" data-method-id="{{ $method['id'] }}">
    <td>
        <div class="d-flex align-items-center gap-2">
            <span class="sortable-handle text-muted" style="cursor: grab;" title="Arraste para reordenar">
                <i class="fa-solid fa-grip-vertical"></i>
            </span>
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        wire:click="moveMethodUp('{{ $method['id'] }}')"
                        @if($index === 0) disabled @endif>
                    <i class="fa-solid fa-arrow-up"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        wire:click="moveMethodDown('{{ $method['id'] }}')"
                        @if($index === count($payment_methods) - 1) disabled @endif>
                    <i class="fa-solid fa-arrow-down"></i>
                </button>
            </div>
        </div>
    </td>

    <td>
        <div class="d-flex align-items-start gap-3">
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="fw-semibold">{{ $method['label'] }}</span>
                    @if(($method['provider'] ?? '') === 'mercadopago')
                        <span class="badge text-bg-primary">Mercado Pago</span>
                    @else
                        <span class="badge text-bg-secondary">Manual</span>
                    @endif
                </div>

                <div class="row g-2">
                    <div class="col-lg-5">
                        <label class="form-label small mb-1">Título</label>
                        <input class="form-control form-control-sm"
                               wire:model.blur="payment_methods.{{ $index }}.label"
                               placeholder="Ex.: PIX">
                        @error('payment_methods.' . $index . '.label')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-lg-7">
                        <label class="form-label small mb-1">Instruções</label>
                        <textarea class="form-control form-control-sm" rows="2"
                                  wire:model.blur="payment_methods.{{ $index }}.instructions"
                                  placeholder="Texto mostrado no checkout"></textarea>
                        @error('payment_methods.' . $index . '.instructions')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    @if(($method['id'] ?? '') === 'pix_manual')
                        <div class="col-12">
                            <label class="form-label small mb-1">Chave PIX</label>
                            <input class="form-control form-control-sm"
                                   wire:model.blur="payment_methods.{{ $index }}.config.pix_key"
                                   placeholder="CPF/CNPJ/telefone/e-mail/chave aleatória">
                            @error('payment_methods.' . $index . '.config.pix_key')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </td>

    <td class="text-center">
        <div class="form-check form-switch d-inline-flex justify-content-center">
            <input class="form-check-input" type="checkbox" role="switch"
                   wire:model.live="payment_methods.{{ $index }}.enabled">
        </div>
    </td>
</tr>
