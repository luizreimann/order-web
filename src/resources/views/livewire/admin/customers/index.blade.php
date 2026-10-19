<div class="vstack gap-3">
    <x-page-header title="Clientes" subtitle="Cadastre e consulte histórico">
        <x-slot:actions>
            <x-btn :href="route('tenant.admin.customers.create', ['tenant' => $tenant->slug])">
                <x-slot:icon><i class="fa-solid fa-user-plus"></i></x-slot:icon>
                Novo cliente
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <div class="row g-2">
            <div class="col-md-6">
                <x-form-field name="search"
                              placeholder="Buscar por nome, telefone ou email..."
                              wire:model.live="search" />
            </div>

            <div class="col-md-3">
                <x-form-select name="status" wire:model.live="status">
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                    <option value="all">Todos</option>
                </x-form-select>
            </div>

            <div class="col-md-3">
                <x-form-select name="sort" wire:model.live="sort">
                    <option value="name">Nome</option>
                    <option value="newest">Mais recentes</option>
                </x-form-select>
            </div>
        </div>
    </x-card>

    <x-card flush>
        <x-data-table :empty="$customers->isEmpty()" :columns="4" empty-message="Nenhum cliente encontrado.">
            <x-slot:head>
                <th>Cliente</th>
                <th>Contato</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
            </x-slot:head>

            @foreach ($customers as $c)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $c->name }}</div>
                        <div class="text-body-secondary small">{{ $c->email ?: '—' }}</div>
                    </td>

                    <td class="text-body-secondary">
                        {{ $c->phone ?: '—' }}
                    </td>

                    <td>
                        <x-status-badge :active="$c->is_active" />
                    </td>

                    <td class="text-end">
                        <div class="btn-group">
                            <x-btn outline size="sm"
                                   :href="route('tenant.admin.customers.edit', ['tenant' => $tenant->slug, 'customerId' => $c->id])">
                                <x-slot:icon><i class="fa-solid fa-pen-to-square"></i></x-slot:icon>
                                Editar
                            </x-btn>

                            @can('update', $c)
                            <x-btn variant="secondary" outline size="sm"
                                   wire:click="toggleActive('{{ $c->id }}')">
                                {{ $c->is_active ? 'Desativar' : 'Ativar' }}
                            </x-btn>
                            @endcan

                            @can('delete', $c)
                            <x-btn variant="danger" outline size="sm"
                                   wire:click="delete('{{ $c->id }}')"
                                   wire:confirm="Excluir este cliente?">
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
