<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Produtos</h1>
            <div class="text-muted small">Gerencie o catálogo do tenant.</div>
        </div>

        <a class="btn btn-primary"
           href="{{ route('tenant.admin.products.create', ['tenant' => $tenant->slug]) }}">
            Novo produto
        </a>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-4">
                    <input type="text" class="form-control"
                           placeholder="Buscar por nome ou SKU..."
                           wire:model.live="search">
                </div>

                <div class="col-md-3">
                    <select class="form-select" wire:model.live="category">
                        <option value="">Todas as categorias</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <select class="form-select" wire:model.live="status">
                        <option value="all">Todos</option>
                        <option value="active">Ativos</option>
                        <option value="inactive">Inativos</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <select class="form-select" wire:model.live="sort">
                        <option value="newest">Mais recentes</option>
                        <option value="name">Nome</option>
                        <option value="price">Preço</option>
                    </select>
                </div>
            </div>
            
            @if($category)
                <div class="mt-2">
                    <span class="badge bg-primary">
                        <i class="fa-solid fa-filter me-1"></i>
                        Filtrando por categoria
                        <button type="button" class="btn-close btn-close-white ms-2 fs-0-6"
                                wire:click="$set('category', null)"
                                aria-label="Remover filtro"></button>
                    </span>
                </div>
            @endif
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Nome</th>
                    <th class="text-nowrap">Preço</th>
                    <th>Categorias</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $product->name }}</div>
                            <div class="text-muted small">
                                SKU: {{ $product->sku ?: '—' }}
                            </div>
                        </td>

                        <td class="text-nowrap">
                            R$ {{ number_format($product->price, 2, ',', '.') }}
                            @if($product->compare_at)
                                <div class="text-muted small text-decoration-line-through">
                                    R$ {{ number_format($product->compare_at, 2, ',', '.') }}
                                </div>
                            @endif
                        </td>

                        <td>
                            @if($product->categories->isNotEmpty())
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach($product->categories as $cat)
                                        <span class="badge text-bg-secondary">{{ $cat->name }}</span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-body-secondary small">—</span>
                            @endif
                        </td>

                        <td>
                            @if($product->is_active)
                                <span class="badge text-bg-success">Ativo</span>
                            @else
                                <span class="badge text-bg-secondary">Inativo</span>
                            @endif
                        </td>

                        <td class="text-end">
                            <div class="btn-group">
                                <a class="btn btn-outline-primary btn-sm"
                                   href="{{ route('tenant.admin.products.edit', ['tenant' => $tenant->slug, 'productId' => $product->id]) }}">
                                    Editar
                                </a>

                                @can('update', $product)
                                <button type="button"
                                        class="btn btn-outline-secondary btn-sm"
                                        wire:click="toggleActive('{{ $product->id }}')">
                                    {{ $product->is_active ? 'Desativar' : 'Ativar' }}
                                </button>
                                @endcan

                                @can('delete', $product)
                                <button type="button"
                                        class="btn btn-outline-danger btn-sm"
                                        wire:click="delete('{{ $product->id }}')"
                                        wire:confirm="Tem certeza que deseja excluir este produto?">
                                    Excluir
                                </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            Nenhum produto encontrado.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>