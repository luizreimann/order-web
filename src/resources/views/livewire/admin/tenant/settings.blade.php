<div class="vstack gap-3">
    <x-page-header title="Configurações" subtitle="Ajustes gerais do tenant" />

    @if (session('status'))
        <div class="alert alert-success mb-0">
            {{ session('status') }}
        </div>
    @endif

    <x-card>
        <form wire:submit.prevent="save" class="row g-3">
            <div class="col-12">
                {{-- `input-group` (prefixo "/") e ajuda com HTML não cabem em <x-form-field>:
                     label e ajuda ficam à mão; o controle sem label recebe o `id` e o erro
                     (is-invalid + invalid-feedback) continua vindo do componente, dentro do
                     `.input-group.has-validation`. --}}
                <label class="form-label" for="slug">Slug da loja</label>

                <div class="input-group has-validation">
                    <span class="input-group-text">/</span>
                    <x-form-field name="slug" id="slug" wire:model.live="slug" placeholder="minha-loja" />
                </div>

                <div class="form-text">
                    Este slug define a URL pública e do admin. Ex.: <span class="fw-semibold">{{ $slugPreview }}</span>
                </div>

                <div class="alert alert-warning mt-3 mb-0">
                    <strong>Atenção:</strong> ao mudar o slug, todos os links antigos do tenant param de funcionar.
                </div>
            </div>

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar alterações</x-btn>
            </div>
        </form>
    </x-card>

    <x-card>
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <x-section-title>Pagamentos</x-section-title>
                <div class="text-muted small">Configure métodos de pagamento e integrações</div>
            </div>
            <x-btn variant="primary" outline :href="route('tenant.admin.payment-settings', ['tenant' => $tenant->slug])">
                <x-slot:icon><i class="fa-solid fa-credit-card"></i></x-slot:icon>
                Configurar Pagamentos
            </x-btn>
        </div>
    </x-card>
</div>
