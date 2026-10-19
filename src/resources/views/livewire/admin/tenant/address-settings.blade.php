<div class="vstack gap-3">
    <x-page-header title="Endereço" subtitle="Localização do seu estabelecimento" />

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <x-card>
        <form wire:submit.prevent="save" class="row g-3">
            <div class="col-md-8">
                <x-form-field name="street" label="Rua/Avenida" wire:model="street" placeholder="Rua Exemplo" />
            </div>

            <div class="col-md-4">
                <x-form-field name="number" label="Número" wire:model="number" placeholder="123" />
            </div>

            <div class="col-md-6">
                <x-form-field name="complement" label="Complemento" wire:model="complement" placeholder="Sala 1, Bloco A..." />
            </div>

            <div class="col-md-6">
                <x-form-field name="neighborhood" label="Bairro" wire:model="neighborhood" placeholder="Centro" />
            </div>

            <div class="col-md-5">
                <x-form-field name="city" label="Cidade" wire:model="city" placeholder="São Paulo" />
            </div>

            <div class="col-md-3">
                <x-form-field name="state" label="Estado" wire:model="state" placeholder="SP" maxlength="2" />
            </div>

            <div class="col-md-4">
                <x-form-field name="zip_code" label="CEP" wire:model="zip_code" placeholder="01234-567" />
            </div>

            <div class="col-12">
                <x-form-field type="url" name="google_maps_url" label="Link do Google Maps"
                              help="Cole o link de compartilhamento do Google Maps"
                              wire:model="google_maps_url" placeholder="https://maps.google.com/..." />
            </div>

            <div class="col-12">
                <x-form-check switch name="show_on_storefront" label="Exibir endereço na loja" wire:model="show_on_storefront" />
            </div>

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar</x-btn>
            </div>
        </form>
    </x-card>
</div>
