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

                    <form wire:submit="login" class="vstack gap-3">
                        <div>
                            <x-form-field type="tel" name="phone" label="Telefone"
                                          placeholder="(11) 99999-9999" autofocus
                                          wire:model="phone" />
                        </div>

                        {{-- Texto do estado de carregamento trocado à mão ("Entrando..."): x-btn só tem spinner. --}}
                        <x-btn type="submit" class="w-100" wire:loading.attr="disabled">
                            <span wire:loading.remove>Continuar</span>
                            <span wire:loading>
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Entrando...
                            </span>
                        </x-btn>
                    </form>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <x-btn variant="link" class="text-muted" wire:click="dismiss">Agora não</x-btn>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
