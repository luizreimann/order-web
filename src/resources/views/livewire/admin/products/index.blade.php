<div class="vstack gap-3">
    <x-page-header title="Produtos" subtitle="Gerencie o catálogo do tenant.">
        <x-slot:actions>
            <x-btn :href="route('tenant.admin.products.create', ['tenant' => $tenant->slug])">
                Novo produto
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <div class="row g-2">
            <div class="col-md-4">
                <x-form-field name="search"
                              placeholder="Buscar por nome ou SKU..."
                              wire:model.live="search" />
            </div>

            <div class="col-md-3">
                <x-form-select name="category" wire:model.live="category">
                    <option value="">Todas as categorias</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </x-form-select>
            </div>

            <div class="col-md-2">
                <x-form-select name="status" wire:model.live="status">
                    <option value="all">Todos</option>
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                </x-form-select>
            </div>

            <div class="col-md-3">
                <x-form-select name="sort" wire:model.live="sort">
                    <option value="newest">Mais recentes</option>
                    <option value="name">Nome</option>
                    <option value="price">Preço</option>
                </x-form-select>
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
    </x-card>

    <x-card flush>
        <x-data-table :empty="$products->isEmpty()" :columns="5" empty-message="Nenhum produto encontrado.">
            <x-slot:head>
                <th>Nome</th>
                <th class="text-nowrap">Preço</th>
                <th>Categorias</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
            </x-slot:head>

            @foreach ($products as $product)
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
                        <x-status-badge :active="$product->is_active" />
                    </td>

                    <td class="text-end">
                        <div class="btn-group">
                            <x-btn outline size="sm"
                                   :href="route('tenant.admin.products.edit', ['tenant' => $tenant->slug, 'productId' => $product->id])">
                                Editar
                            </x-btn>

                            @can('update', $product)
                            <x-btn variant="secondary" outline size="sm"
                                   wire:click="toggleActive('{{ $product->id }}')">
                                {{ $product->is_active ? 'Desativar' : 'Ativar' }}
                            </x-btn>
                            @endcan

                            @can('delete', $product)
                            <x-btn variant="danger" outline size="sm"
                                   wire:click="delete('{{ $product->id }}')"
                                   wire:confirm="Tem certeza que deseja excluir este produto?">
                                Excluir
                            </x-btn>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-card>
</div>
