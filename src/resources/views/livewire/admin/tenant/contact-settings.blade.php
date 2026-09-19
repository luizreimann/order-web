<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Contato</h1>
            <div class="text-muted small">Informações de contato da loja</div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Telefone</label>
                    <input type="text" class="form-control" wire:model="phone" placeholder="(11) 99999-9999">
                    @error('phone') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">WhatsApp</label>
                    <input type="text" class="form-control" wire:model="whatsapp" placeholder="5511999999999">
                    @error('whatsapp') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Formato: 55 + DDD + número (sem espaços ou símbolos)</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" wire:model="email" placeholder="contato@loja.com">
                    @error('email') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">Mensagem padrão do WhatsApp</label>
                    <input type="text" class="form-control" wire:model="whatsapp_message" placeholder="Olá! Vim pelo site...">
                    @error('whatsapp_message') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Mensagem pré-preenchida ao clicar no botão do WhatsApp</div>
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" wire:model="show_whatsapp_button" id="show_whatsapp_button">
                        <label class="form-check-label" for="show_whatsapp_button">
                            Exibir botão flutuante do WhatsApp na loja
                        </label>
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
