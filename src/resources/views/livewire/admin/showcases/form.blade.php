<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">{{ $showcaseModel ? 'Editar vitrine' : 'Nova vitrine' }}</h1>
            <div class="text-body-secondary small">{{ $tenant->name }}</div>
        </div>

        <a class="btn btn-outline-secondary"
           href="{{ route('tenant.admin.showcases.index', ['tenant' => $tenant->slug]) }}">
            Voltar
        </a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save" class="row g-3">

                <div class="col-md-8">
                    <label class="form-label">Nome *</label>
                    <input class="form-control" wire:model.defer="name" placeholder="Ex: Destaques, Bolos Especiais">
                    @error('name') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Slug (opcional)</label>
                    <input class="form-control" wire:model.defer="slug" placeholder="ex: destaques">
                    @error('slug') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tipo *</label>
                    <select class="form-select" wire:model.live="type">
                        @foreach($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('type') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">Modo de Exibição *</label>
                    <select class="form-select" wire:model.defer="display_mode">
                        <option value="list">Por Lista</option>
                        <option value="carousel">Por Slides (Carrossel)</option>
                    </select>
                    @error('display_mode') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Lista exibe em grade, Carrossel exibe em slides deslizantes.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Máx. itens</label>
                    <input type="number" class="form-control" wire:model.defer="max_items" min="1" max="50"
                           @if($type === 'all') disabled @endif>
                    @error('max_items') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    @if($type === 'all')
                        <div class="form-text">Tipo "Todos" exibe até 100 produtos.</div>
                    @endif
                </div>

                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" wire:model.defer="is_active" id="is_active_showcase">
                        <label class="form-check-label" for="is_active_showcase">Ativa</label>
                    </div>
                </div>

                @if($type === 'category')
                    <div class="col-12">
                        <label class="form-label">Categoria *</label>
                        <select class="form-select" wire:model.defer="category_id">
                            <option value="">Selecione uma categoria</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                        @error('category_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                @endif

                @if($type === 'custom')
                    <div class="col-12">
                        <label class="form-label">Produtos</label>
                        <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                            @forelse($products as $product)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           wire:model.defer="selectedProducts"
                                           value="{{ $product->id }}"
                                           id="product_{{ $product->id }}">
                                    <label class="form-check-label" for="product_{{ $product->id }}">
                                        {{ $product->name }}
                                        <span class="text-body-secondary small">
                                            — R$ {{ number_format($product->price, 2, ',', '.') }}
                                        </span>
                                    </label>
                                </div>
                            @empty
                                <div class="text-body-secondary">Nenhum produto ativo encontrado.</div>
                            @endforelse
                        </div>
                        @error('selectedProducts') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        <div class="form-text">Selecione os produtos que aparecerão nesta vitrine.</div>
                    </div>
                @endif

                <div class="col-12 border-top pt-3 mt-2">
                    <div class="text-body-secondary small mb-3">
                        <strong>Tipos de vitrine:</strong>
                        <ul class="mb-0 mt-1">
                            <li><strong>Todos os Produtos</strong> — Exibe todos os produtos ativos</li>
                            <li><strong>Destaques</strong> — Produtos marcados como destaque</li>
                            <li><strong>Mais Vendidos</strong> — Produtos com mais vendas</li>
                            <li><strong>Novidades</strong> — Produtos mais recentes</li>
                            <li><strong>Por Categoria</strong> — Produtos de uma categoria específica</li>
                            <li><strong>Personalizada</strong> — Seleção manual de produtos</li>
                        </ul>
                    </div>
                </div>

                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Salvar</button>
                </div>

            </form>
        </div>
    </div>
</div>
