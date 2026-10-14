<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Categorias</h1>
            <div class="text-body-secondary small">Organize seus produtos</div>
        </div>

        <a class="btn btn-primary"
           href="{{ route('tenant.admin.categories.create', ['tenant' => $tenant->slug]) }}">
            Nova categoria
        </a>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <input class="form-control" placeholder="Buscar por nome ou slug..."
                           wire:model.live="search">
                </div>

                <div class="col-md-3">
                    <select class="form-select" wire:model.live="status">
                        <option value="all">Todas</option>
                        <option value="active">Ativas</option>
                        <option value="inactive">Inativas</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <select class="form-select" wire:model.live="sort">
                        <option value="order">Ordem</option>
                        <option value="name">Nome</option>
                        <option value="newest">Mais recentes</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="border-bottom">
                <tr>
                    <th>Nome</th>
                    <th>Slug</th>
                    <th>Produtos</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($categories as $cat)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $cat->name }}</div>
                            <div class="text-body-secondary small">Ordem: {{ $cat->sort_order }}</div>
                        </td>

                        <td class="text-body-secondary">
                            {{ $cat->slug ?: '—' }}
                        </td>

                        <td>
                            <a href="{{ route('tenant.admin.products.index', ['tenant' => $tenant->slug, 'category' => $cat->id]) }}" 
                               class="badge bg-primary text-decoration-none fs-0-9">
                                <i class="fa-solid fa-box me-1"></i> {{ $cat->products_count }}
                            </a>
                        </td>

                        <td>
                            @if($cat->is_active)
                                <span class="badge text-bg-success">Ativa</span>
                            @else
                                <span class="badge text-bg-secondary">Inativa</span>
                            @endif
                        </td>

                        <td class="text-end">
                            <div class="btn-group">
                                <a class="btn btn-outline-primary btn-sm"
                                   href="{{ route('tenant.admin.categories.edit', ['tenant' => $tenant->slug, 'categoryId' => $cat->id]) }}">
                                    Editar
                                </a>

                                @can('update', $cat)
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        wire:click="toggleActive('{{ $cat->id }}')">
                                    {{ $cat->is_active ? 'Desativar' : 'Ativar' }}
                                </button>
                                @endcan

                                @can('delete', $cat)
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        wire:click="delete('{{ $cat->id }}')"
                                        wire:confirm="Excluir esta categoria?">
                                    Excluir
                                </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center py-5 text-body-secondary">
                            Nenhuma categoria encontrada.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>