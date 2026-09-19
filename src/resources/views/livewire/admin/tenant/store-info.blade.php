<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Informações da Loja</h1>
            <div class="text-muted small">Descrição e informações sobre seu negócio</div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save" class="row g-3">
                <div class="col-12">
                    <label class="form-label">Descrição curta</label>
                    <input type="text" class="form-control" wire:model="short_description" maxlength="160" placeholder="Descrição breve para SEO e redes sociais">
                    @error('short_description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Máximo 160 caracteres. Usada em resultados de busca e compartilhamentos.</div>
                </div>

                <div class="col-12">
                    <label class="form-label">Sobre a loja</label>
                    <textarea class="form-control" wire:model="full_description" rows="6" maxlength="2000" placeholder="Conte a história do seu negócio, diferenciais, especialidades..."></textarea>
                    @error('full_description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Máximo 2000 caracteres.</div>
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" wire:model="show_about" id="show_about">
                        <label class="form-check-label" for="show_about">
                            Exibir seção "Sobre" na loja
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
