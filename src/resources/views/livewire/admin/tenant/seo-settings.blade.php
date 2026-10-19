<div class="vstack gap-3">
    <x-page-header title="SEO" subtitle="Otimização para mecanismos de busca" />

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <x-card>
                <form wire:submit.prevent="save" class="row g-3">
                    <div class="col-12">
                        <x-form-field name="meta_title" label="Título da página (meta title)"
                                      help="Máximo 60 caracteres. Aparece na aba do navegador e nos resultados do Google."
                                      wire:model="meta_title" maxlength="60" :placeholder="$tenant->name" />
                    </div>

                    <div class="col-12">
                        <x-form-field type="textarea" name="meta_description" label="Descrição (meta description)"
                                      help="Máximo 160 caracteres. Aparece nos resultados de busca."
                                      wire:model="meta_description" rows="3" maxlength="160"
                                      placeholder="Descrição da sua loja para o Google..." />
                    </div>

                    <div class="col-12">
                        <x-form-field name="google_analytics_id" label="Google Analytics ID"
                                      help="ID de medição do Google Analytics 4 (opcional)."
                                      wire:model="google_analytics_id" placeholder="G-XXXXXXXXXX" />
                    </div>

                    <div class="col-12">
                        <x-form-field type="file" name="og_image" label="Imagem de compartilhamento (Open Graph)"
                                      help="Imagem exibida ao compartilhar sua loja em redes sociais. Recomendado: 1200x630px."
                                      wire:model="og_image" accept="image/*" />
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <x-btn type="submit" loading="save">Salvar</x-btn>

                        @if($this->ogImageUrl)
                            <x-btn variant="danger" outline wire:click="removeOgImage">
                                Remover imagem OG
                            </x-btn>
                        @endif
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-5">
            <x-card>
                <div class="vstack gap-3">
                    <x-section-title sub>Preview do Google</x-section-title>

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
                        <div class="vstack gap-2">
                            <x-section-title sub>Imagem de compartilhamento</x-section-title>
                            <img src="{{ $this->ogImageUrl }}" alt="OG Image" class="img-fluid rounded border og-preview">
                        </div>
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>
