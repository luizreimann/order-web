<tr wire:key="method-{{ $method['id'] }}" data-method-id="{{ $method['id'] }}">
    <td>
        <div class="d-flex align-items-center gap-2">
            <span class="sortable-handle text-muted cursor-grab" title="Arraste para reordenar">
                <i class="fa-solid fa-grip-vertical"></i>
            </span>
            <div class="btn-group" role="group">
                <x-btn variant="secondary" outline size="sm"
                       wire:click="moveMethodUp('{{ $method['id'] }}')"
                       :disabled="$index === 0">
                    <i class="fa-solid fa-arrow-up"></i>
                </x-btn>
                <x-btn variant="secondary" outline size="sm"
                       wire:click="moveMethodDown('{{ $method['id'] }}')"
                       :disabled="$index === count($payment_methods) - 1">
                    <i class="fa-solid fa-arrow-down"></i>
                </x-btn>
            </div>
        </div>
    </td>

    <td>
        <div class="d-flex align-items-start gap-3">
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="fw-semibold">{{ $method['label'] }}</span>
                    {{-- Tag de provedor (não é status de domínio): `primary` não é aceito por <x-status-badge>. --}}
                    @if(($method['provider'] ?? '') === 'mercadopago')
                        <span class="badge text-bg-primary">Mercado Pago</span>
                    @else
                        <span class="badge text-bg-secondary">Manual</span>
                    @endif
                </div>

                <div class="row g-2">
                    <div class="col-lg-5">
                        <x-form-field name="payment_methods.{{ $index }}.label" label="Título"
                                      class="form-control-sm"
                                      wire:model.blur="payment_methods.{{ $index }}.label"
                                      placeholder="Ex.: PIX" />
                    </div>

                    <div class="col-lg-7">
                        <x-form-field type="textarea" name="payment_methods.{{ $index }}.instructions" label="Instruções"
                                      class="form-control-sm" rows="2"
                                      wire:model.blur="payment_methods.{{ $index }}.instructions"
                                      placeholder="Texto mostrado no checkout" />
                    </div>

                    @if(($method['id'] ?? '') === 'pix_manual')
                        <div class="col-12">
                            <x-form-field name="payment_methods.{{ $index }}.config.pix_key" label="Chave PIX"
                                          class="form-control-sm"
                                          wire:model.blur="payment_methods.{{ $index }}.config.pix_key"
                                          placeholder="CPF/CNPJ/telefone/e-mail/chave aleatória" />
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </td>

    <td class="text-center">
        {{-- Switch sem label e centralizado (`d-inline-flex justify-content-center`): <x-form-check>
             sempre emite <label> e não aceita classe no wrapper, então fica à mão. --}}
        <div class="form-check form-switch d-inline-flex justify-content-center">
            <input class="form-check-input" type="checkbox" role="switch"
                   wire:model.live="payment_methods.{{ $index }}.enabled">
        </div>
    </td>
</tr>
