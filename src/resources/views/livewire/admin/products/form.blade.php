<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">{{ $productModel ? 'Editar produto' : 'Novo produto' }}</h1>
            <div class="text-muted small">{{ $tenant->name }}</div>
        </div>

        <a class="btn btn-outline-secondary"
           href="{{ route('tenant.admin.products.index', ['tenant' => $tenant->slug]) }}">
            Voltar
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">

            @if (session('status'))
                <div class="alert alert-success">
                    {{ session('status') }}
                </div>
            @endif

            <form wire:submit.prevent="save" class="row g-3">

                <div class="col-12">
                    <label class="form-label">Imagem do produto</label>

                    <input type="file"
                        class="form-control"
                        wire:model="image"
                        accept="image/*">

                    @error('image')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror

                    @if($this->imageUrl)
                        <div class="mt-3 d-flex align-items-center gap-3">
                            <img src="{{ $this->imageUrl }}"
                                alt="Imagem do produto"
                                style="height: 72px; width: 72px; object-fit: cover;"
                                class="rounded border">

                            @if($productModel && data_get($productModel->meta, 'image_path'))
                                <button type="button"
                                        class="btn btn-outline-danger btn-sm"
                                        wire:click="removeImage"
                                        wire:confirm="Remover a imagem deste produto?">
                                    Remover imagem
                                </button>
                            @endif
                        </div>
                    @endif

                    <div class="form-text">PNG/JPG/WebP, até 3MB.</div>
                </div>
                
                <div class="col-12">
                    <label class="form-label">Nome *</label>
                    <input type="text" class="form-control" wire:model.defer="name">
                    @error('name') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">SKU</label>
                    <input type="text" class="form-control" wire:model.defer="sku">
                    @error('sku') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Preço (R$) *</label>
                    <input type="text" class="form-control" wire:model.defer="price" placeholder="19,90">
                    @error('price') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Preço riscado (R$)</label>
                    <input type="text" class="form-control" wire:model.defer="compare_at" placeholder="29,90">
                    @error('compare_at') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <label class="form-label">Descrição</label>
                    <textarea class="form-control" rows="5" wire:model.defer="description"></textarea>
                    @error('description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Ordenação</label>
                    <input type="number" class="form-control" wire:model.defer="sort_order">
                    @error('sort_order') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Antecedência mínima (dias)</label>
                    <input type="number" class="form-control" wire:model.defer="min_days_notice">
                    @error('min_days_notice') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" wire:model.defer="requires_scheduling" id="requires_scheduling">
                        <label class="form-check-label" for="requires_scheduling">
                            Exigir data/hora no pedido
                        </label>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" wire:model.live="track_inventory" id="track_inventory">
                        <label class="form-check-label" for="track_inventory">Controlar estoque</label>
                    </div>
                    <div class="form-text">Desligado = sob encomenda, sem limite de quantidade.</div>
                    @error('track_inventory') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                @if($track_inventory)
                    <div class="col-md-4">
                        <label class="form-label" for="stock_quantity">Quantidade em estoque</label>
                        <input type="number" min="0" class="form-control" id="stock_quantity" wire:model.defer="stock_quantity">
                        @error('stock_quantity') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                @endif

                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" wire:model.defer="is_active" id="is_active">
                        <label class="form-check-label" for="is_active">Ativo</label>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" wire:model.defer="is_featured" id="is_featured">
                        <label class="form-check-label" for="is_featured">Destaque</label>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label">Categorias</label>

                    @if($categories->isEmpty())
                        <div class="text-body-secondary small">
                            Nenhuma categoria cadastrada. Crie uma em <a href="{{ route('tenant.admin.categories.index', ['tenant' => $tenant->slug]) }}">Categorias</a>.
                        </div>
                    @else
                        <div class="row g-2">
                            @foreach($categories as $cat)
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input"
                                            type="checkbox"
                                            value="{{ $cat->id }}"
                                            id="cat_{{ $cat->id }}"
                                            wire:model.defer="category_ids">
                                        <label class="form-check-label" for="cat_{{ $cat->id }}">
                                            {{ $cat->name }}
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @error('category_ids') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    @error('category_ids.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <button class="btn btn-primary" type="submit">
                        Salvar
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>