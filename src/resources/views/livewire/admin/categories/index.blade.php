<div class="vstack gap-3">
    <x-page-header title="Categorias" subtitle="Organize seus produtos">
        <x-slot:actions>
            <x-btn :href="route('tenant.admin.categories.create', ['tenant' => $tenant->slug])">
                Nova categoria
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <div class="row g-2">
            <div class="col-md-6">
                <x-form-field name="search"
                              placeholder="Buscar por nome ou slug..."
                              wire:model.live="search" />
            </div>

            <div class="col-md-3">
                <x-form-select name="status" wire:model.live="status">
                    <option value="all">Todas</option>
                    <option value="active">Ativas</option>
                    <option value="inactive">Inativas</option>
                </x-form-select>
            </div>

            <div class="col-md-3">
                <x-form-select name="sort" wire:model.live="sort">
                    <option value="order">Ordem</option>
                    <option value="name">Nome</option>
                    <option value="newest">Mais recentes</option>
                </x-form-select>
            </div>
        </div>
    </x-card>

    <x-card flush>
        <x-data-table :empty="$categories->isEmpty()" :columns="5" empty-message="Nenhuma categoria encontrada.">
            <x-slot:head>
                <th>Nome</th>
                <th>Slug</th>
                <th>Produtos</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
            </x-slot:head>

            @foreach ($categories as $cat)
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
                        <x-status-badge :active="$cat->is_active" :label="$cat->is_active ? 'Ativa' : 'Inativa'" />
                    </td>

                    <td class="text-end">
                        <div class="btn-group">
                            <x-btn outline size="sm"
                                   :href="route('tenant.admin.categories.edit', ['tenant' => $tenant->slug, 'categoryId' => $cat->id])">
                                Editar
                            </x-btn>

                            @can('update', $cat)
                            <x-btn variant="secondary" outline size="sm"
                                   wire:click="toggleActive('{{ $cat->id }}')">
                                {{ $cat->is_active ? 'Desativar' : 'Ativar' }}
                            </x-btn>
                            @endcan

                            @can('delete', $cat)
                            <x-btn variant="danger" outline size="sm"
                                   wire:click="delete('{{ $cat->id }}')"
                                   wire:confirm="Excluir esta categoria?">
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
