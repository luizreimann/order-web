<div class="container py-4">
    <div class="row">
        <div class="col-12 col-md-3 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Minha Conta</h5>
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
            </div>
        </div>

        <div class="col-12 col-md-9">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Meus Dados</h5>
                </div>
                <div class="card-body">
                    @if (session()->has('status'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('status') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                        </div>
                    @endif

                    <form wire:submit="save">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nome Completo</label>
                            <input 
                                type="text" 
                                class="form-control @error('name') is-invalid @enderror" 
                                id="name" 
                                wire:model="name"
                            >
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input 
                                type="email" 
                                class="form-control @error('email') is-invalid @enderror" 
                                id="email" 
                                wire:model="email"
                            >
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="phone" class="form-label">Telefone</label>
                            <input 
                                type="tel" 
                                class="form-control @error('phone') is-invalid @enderror" 
                                id="phone" 
                                wire:model="phone"
                            >
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                            <span wire:loading.remove>Salvar Alterações</span>
                            <span wire:loading>
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Salvando...
                            </span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
