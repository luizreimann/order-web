<div>
    @if($show)
    <div class="modal fade show d-block modal-scrim" tabindex="-1" x-data="{ visible: false }" x-show="visible" x-init="setTimeout(() => visible = true, 3000)">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Bem-vindo à {{ $tenant->name }}!</h5>
                    <button type="button" class="btn-close" wire:click="dismiss" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Informe seu telefone para:</p>
                    <ul class="text-muted mb-4">
                        <li>Acompanhar seus pedidos</li>
                        <li>Salvar seus favoritos</li>
                        <li>Comprar mais rápido</li>
                    </ul>

                    <form wire:submit="login">
                        <div class="mb-3">
                            <label for="phone" class="form-label">Telefone</label>
                            <input 
                                type="tel" 
                                class="form-control @error('phone') is-invalid @enderror" 
                                id="phone" 
                                wire:model="phone"
                                placeholder="(11) 99999-9999"
                                autofocus
                            >
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled">
                            <span wire:loading.remove>Continuar</span>
                            <span wire:loading>
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Entrando...
                            </span>
                        </button>
                    </form>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button type="button" class="btn btn-link text-muted" wire:click="dismiss">Agora não</button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
