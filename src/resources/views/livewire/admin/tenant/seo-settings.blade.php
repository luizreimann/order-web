<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">SEO</h1>
            <div class="text-muted small">Otimização para mecanismos de busca</div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <form wire:submit.prevent="save" class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Título da página (meta title)</label>
                            <input type="text" class="form-control" wire:model="meta_title" maxlength="60" placeholder="{{ $tenant->name }}">
                            @error('meta_title') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Máximo 60 caracteres. Aparece na aba do navegador e nos resultados do Google.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Descrição (meta description)</label>
                            <textarea class="form-control" wire:model="meta_description" rows="3" maxlength="160" placeholder="Descrição da sua loja para o Google..."></textarea>
                            @error('meta_description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Máximo 160 caracteres. Aparece nos resultados de busca.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Google Analytics ID</label>
                            <input type="text" class="form-control" wire:model="google_analytics_id" placeholder="G-XXXXXXXXXX">
                            @error('google_analytics_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">ID de medição do Google Analytics 4 (opcional).</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Imagem de compartilhamento (Open Graph)</label>
                            <input type="file" class="form-control" wire:model="og_image" accept="image/*">
                            @error('og_image') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Imagem exibida ao compartilhar sua loja em redes sociais. Recomendado: 1200x630px.</div>
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Salvar</button>

                            @if($this->ogImageUrl)
                                <button type="button" class="btn btn-outline-danger" wire:click="removeOgImage">
                                    Remover imagem OG
                                </button>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small mb-2">Preview do Google</div>

                    <div class="border rounded p-3 bg-light">
                        <div class="text-primary small text-truncate">
                            {{ url('/' . $tenant->slug) }}
                        </div>
                        <div class="fw-semibold text-primary fs-1-1">
                            {{ $meta_title ?: $tenant->name }}
                        </div>
                        <div class="text-muted small">
                            {{ $meta_description ?: 'Adicione uma descrição para aparecer nos resultados de busca.' }}
                        </div>
                    </div>

                    @if($this->ogImageUrl)
                        <div class="mt-3">
                            <div class="text-muted small mb-2">Imagem de compartilhamento</div>
                            <img src="{{ $this->ogImageUrl }}" alt="OG Image" class="img-fluid rounded border og-preview">
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
