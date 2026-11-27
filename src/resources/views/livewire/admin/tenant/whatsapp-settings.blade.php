<div class="vstack gap-3">
    <x-page-header title="WhatsApp" subtitle="Avisos de pedido pelo número da loja" />

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <x-card>
        @if ($polls)
            <div wire:poll.3s="refreshConnection">
        @else
            <div>
        @endif
            <div class="mb-3">
                <div class="text-muted small">Situação</div>
                <div class="d-flex align-items-center gap-2">
                    <div>{{ $stepLabel }}</div>
                    @if ($status === 'starting' || $status === 'disconnecting')
                        <div class="spinner-border spinner-border-sm" role="status">
                            <span class="visually-hidden">Aguarde</span>
                        </div>
                    @endif
                </div>
                @if ($connectedNumber)
                    <div>{{ $connectedNumber }}</div>
                @endif
            </div>

            @if ($connectLabel || $status === 'connected')
                <div class="d-flex flex-wrap gap-2 mb-3">
                    @if ($connectLabel)
                        <x-btn wire:click="connect" loading="connect">{{ $connectLabel }}</x-btn>
                    @endif
                    @if ($status === 'connected')
                        <x-btn variant="danger" outline wire:click="disconnect" loading="disconnect">Desconectar</x-btn>
                    @endif
                </div>
            @endif

            @if ($status === 'qr_ready')
                @if (is_string($qr) && $qr !== '')
                    <div class="mb-3" data-whatsapp-qr="{{ $qr }}"></div>
                @endif

                <form wire:submit.prevent="requestPairingCode" class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <x-form-field name="pairing_phone" label="Telefone" wire:model="pairing_phone"
                                      help="Para receber um código e digitar no WhatsApp." data-phone-mask />
                    </div>
                    <div class="col-12">
                        <x-btn type="submit" loading="requestPairingCode">Receber código</x-btn>
                    </div>
                </form>

                @if ($pairing_code !== '')
                    <div class="mb-3">
                        <div class="text-muted small">Código para digitar no WhatsApp</div>
                        <div>{{ $pairing_code }}</div>
                    </div>
                @endif
            @endif

            <form wire:submit.prevent="save" class="row g-3">
                <div class="col-12">
                    <x-form-check switch name="order_updates" label="Avisar o cliente quando o pedido mudar" wire:model="order_updates" />
                </div>

                <div class="col-12">
                    <x-form-field type="textarea" name="confirmed" label="Confirmado" rows="3"
                                  help="Até 500 caracteres. Vazio não envia esse status."
                                  wire:model="confirmed" />
                </div>

                <div class="col-12">
                    <x-form-field type="textarea" name="done" label="Concluído" rows="3"
                                  help="Até 500 caracteres. Vazio não envia esse status."
                                  wire:model="done" />
                </div>

                <div class="col-12">
                    <x-form-field type="textarea" name="cancelled" label="Cancelado" rows="3"
                                  help="Até 500 caracteres. Vazio não envia esse status."
                                  wire:model="cancelled" />
                </div>

                <div class="col-12">
                    <x-btn type="submit" loading="save">Salvar</x-btn>
                </div>
            </form>
        </div>
    </x-card>

    <div class="d-flex align-items-center gap-2 text-muted small">
        <span>Fornecido por</span>
        <a href="https://zaptria.com.br" target="_blank" rel="noopener noreferrer" class="d-inline-flex text-decoration-none">
            <img class="zaptria-credit-light" src="{{ asset('images/zaptria.webp') }}" alt="Zaptria" width="72" height="24">
            <img class="zaptria-credit-dark" src="{{ asset('images/zaptria-dark.webp') }}" alt="Zaptria" width="72" height="24">
        </a>
    </div>

    {{-- Só esta tela. O painel troca o tema em data-bs-theme; display:none tira a outra marca do leitor. --}}
    <style>
        .zaptria-credit-dark { display: none; }
        [data-bs-theme="dark"] .zaptria-credit-light { display: none; }
        [data-bs-theme="dark"] .zaptria-credit-dark { display: inline; }
    </style>
</div>
