<div class="container py-4">
    <div class="row">
        <div class="col-12 col-md-3 mb-4">
            <x-card>
                <div class="vstack gap-2">
                    <x-section-title>Minha Conta</x-section-title>
                    <div class="list-group list-group-flush">
                        <a href="{{ route('tenant.account', ['tenant' => $tenant->slug]) }}" class="list-group-item list-group-item-action">
                            <i class="fa-solid fa-home me-2"></i>
                            Início
                        </a>
                        <a href="{{ route('tenant.account.profile', ['tenant' => $tenant->slug]) }}" class="list-group-item list-group-item-action active">
                            <i class="fa-solid fa-user me-2"></i>
                            Meus Dados
                        </a>
                    </div>
                </div>
            </x-card>
        </div>

        <div class="col-12 col-md-9">
            <x-card>
                <x-slot:header>
                    <x-section-title>Meus Dados</x-section-title>
                </x-slot:header>

                @if (session()->has('status'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('status') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                    </div>
                @endif

                <form wire:submit="save" class="vstack gap-3">
                    <div>
                        <x-form-field name="name" label="Nome Completo" wire:model="name" />
                    </div>

                    <div>
                        <x-form-field type="email" name="email" label="Email" wire:model="email" />
                    </div>

                    <div>
                        <x-form-field type="tel" name="phone" label="Telefone" wire:model="phone" />
                    </div>

                    <div>
                        <x-btn type="submit" loading="save">Salvar Alterações</x-btn>
                    </div>
                </form>
            </x-card>
        </div>
    </div>
</div>
