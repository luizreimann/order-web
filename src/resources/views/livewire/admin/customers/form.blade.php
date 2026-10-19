<div class="vstack gap-3">
    <x-page-header :title="$customerModel ? 'Editar cliente' : 'Novo cliente'" :subtitle="$tenant->name">
        <x-slot:actions>
            <x-btn variant="secondary" outline
                   :href="route('tenant.admin.customers.index', ['tenant' => $tenant->slug])">
                Voltar
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <form wire:submit.prevent="save" class="row g-3">
            <div class="col-12">
                <x-form-field name="name" label="Nome" required wire:model.defer="name" />
            </div>

            <div class="col-md-6">
                <x-form-field name="phone" label="Telefone (WhatsApp)" placeholder="(11) 99999-9999" wire:model.defer="phone" />
            </div>

            <div class="col-md-6">
                <x-form-field type="email" name="email" label="E-mail" placeholder="cliente@email.com" wire:model.defer="email" />
            </div>

            <div class="col-12">
                <x-form-check name="is_active" label="Ativo" wire:model.defer="is_active" />
            </div>

            <hr class="my-2">

            <div class="col-12">
                <x-form-check name="has_address" label="Cadastrar endereço principal" wire:model.live="has_address" />
            </div>

            @if($has_address)
                <div class="col-md-4">
                    <x-form-field name="address_label" label="Rótulo" placeholder="Casa" wire:model.defer="address_label" />
                </div>

                <div class="col-12">
                    <x-form-field name="address_line1" label="Endereço" required placeholder="Rua, número, bairro" wire:model.defer="address_line1" />
                </div>

                <div class="col-12">
                    <x-form-field name="address_line2" label="Complemento" placeholder="Apto, bloco..." wire:model.defer="address_line2" />
                </div>

                <div class="col-md-5">
                    <x-form-field name="address_city" label="Cidade" wire:model.defer="address_city" />
                </div>

                <div class="col-md-3">
                    <x-form-field name="address_state" label="Estado" placeholder="SP" wire:model.defer="address_state" />
                </div>

                <div class="col-md-4">
                    <x-form-field name="address_postal_code" label="CEP" wire:model.defer="address_postal_code" />
                </div>
            @endif

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar</x-btn>
            </div>
        </form>
    </x-card>
</div>
