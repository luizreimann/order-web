<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Configurações</h1>
            <div class="text-muted small">Ajustes gerais do tenant</div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save" class="row g-3">
                <div class="col-12">
                    <label class="form-label">Slug da loja</label>

                    <div class="input-group">
                        <span class="input-group-text">/</span>
                        <input type="text" class="form-control" wire:model.live="slug" placeholder="caroldoces">
                    </div>

                    @error('slug')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror

                    <div class="form-text">
                        Este slug define a URL pública e do admin. Ex.: <span class="fw-semibold">{{ $slugPreview }}</span>
                    </div>

                    <div class="alert alert-warning mt-3 mb-0">
                        <strong>Atenção:</strong> ao mudar o slug, todos os links antigos do tenant param de funcionar.
                    </div>
                </div>

                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Salvar alterações</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="fw-semibold">Pagamentos</div>
                    <div class="text-muted small">Configure métodos de pagamento e integrações</div>
                </div>
                <a href="{{ route('tenant.admin.payment-settings', ['tenant' => $tenant->slug]) }}" class="btn btn-outline-primary">
                    <i class="fa-solid fa-credit-card me-1"></i> Configurar Pagamentos
                </a>
            </div>
        </div>
    </div>
</div>