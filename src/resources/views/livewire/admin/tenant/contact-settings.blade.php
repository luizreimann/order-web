<div class="vstack gap-3">
    <x-page-header title="Contato" subtitle="Informações de contato da loja" />

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <x-card>
        <form wire:submit.prevent="save" class="row g-3">
            <div class="col-md-6">
                <x-form-field name="phone" label="Telefone" wire:model="phone" placeholder="(11) 99999-9999" />
            </div>

            <div class="col-md-6">
                <x-form-field name="whatsapp" label="WhatsApp"
                              help="Formato: 55 + DDD + número (sem espaços ou símbolos)"
                              wire:model="whatsapp" placeholder="5511999999999" />
            </div>

            <div class="col-md-6">
                <x-form-field type="email" name="email" label="Email" wire:model="email" placeholder="contato@loja.com" />
            </div>

            <div class="col-md-6">
                <x-form-field name="whatsapp_message" label="Mensagem padrão do WhatsApp"
                              help="Mensagem pré-preenchida ao clicar no botão do WhatsApp"
                              wire:model="whatsapp_message" placeholder="Olá! Vim pelo site..." />
            </div>

            <div class="col-12">
                <x-form-check switch name="show_whatsapp_button" label="Exibir botão flutuante do WhatsApp na loja" wire:model="show_whatsapp_button" />
            </div>

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar</x-btn>
            </div>
        </form>
    </x-card>
</div>
