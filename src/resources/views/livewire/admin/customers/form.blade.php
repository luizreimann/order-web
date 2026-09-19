<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">{{ $customerModel ? 'Editar cliente' : 'Novo cliente' }}</h1>
            <div class="text-body-secondary small">{{ $tenant->name }}</div>
        </div>

        <a class="btn btn-outline-secondary"
           href="{{ route('tenant.admin.customers.index', ['tenant' => $tenant->slug]) }}">
            Voltar
        </a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save" class="row g-3">
                <div class="col-12">
                    <label class="form-label">Nome *</label>
                    <input class="form-control" wire:model.defer="name">
                    @error('name') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">Telefone (WhatsApp)</label>
                    <input class="form-control" wire:model.defer="phone" placeholder="(11) 99999-9999">
                    @error('phone') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">E-mail</label>
                    <input type="email" class="form-control" wire:model.defer="email" placeholder="cliente@email.com">
                    @error('email') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="cust_active" wire:model.defer="is_active">
                        <label class="form-check-label" for="cust_active">Ativo</label>
                    </div>
                </div>

                <hr class="my-2">

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="has_addr" wire:model.live="has_address">
                        <label class="form-check-label" for="has_addr">Cadastrar endereço principal</label>
                    </div>
                </div>

                @if($has_address)
                    <div class="col-md-4">
                        <label class="form-label">Rótulo</label>
                        <input class="form-control" wire:model.defer="address_label" placeholder="Casa">
                        @error('address_label') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label">Endereço *</label>
                        <input class="form-control" wire:model.defer="address_line1" placeholder="Rua, número, bairro">
                        @error('address_line1') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label">Complemento</label>
                        <input class="form-control" wire:model.defer="address_line2" placeholder="Apto, bloco...">
                        @error('address_line2') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-5">
                        <label class="form-label">Cidade</label>
                        <input class="form-control" wire:model.defer="address_city">
                        @error('address_city') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Estado</label>
                        <input class="form-control" wire:model.defer="address_state" placeholder="SP">
                        @error('address_state') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">CEP</label>
                        <input class="form-control" wire:model.defer="address_postal_code">
                        @error('address_postal_code') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                @endif

                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>