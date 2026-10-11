<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Clientes</h1>
            <div class="text-body-secondary small">Cadastre e consulte histórico</div>
        </div>

        <a class="btn btn-primary"
           href="{{ route('tenant.admin.customers.create', ['tenant' => $tenant->slug]) }}">
            <i class="fa-solid fa-user-plus me-2"></i>Novo cliente
        </a>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <input class="form-control"
                           placeholder="Buscar por nome, telefone ou email..."
                           wire:model.live="search">
                </div>

                <div class="col-md-3">
                    <select class="form-select" wire:model.live="status">
                        <option value="active">Ativos</option>
                        <option value="inactive">Inativos</option>
                        <option value="all">Todos</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <select class="form-select" wire:model.live="sort">
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
                    <th>Cliente</th>
                    <th>Contato</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                @forelse($customers as $c)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $c->name }}</div>
                            <div class="text-body-secondary small">{{ $c->email ?: '—' }}</div>
                        </td>

                        <td class="text-body-secondary">
                            {{ $c->phone ?: '—' }}
                        </td>

                        <td>
                            @if($c->is_active)
                                <span class="badge text-bg-success">Ativo</span>
                            @else
                                <span class="badge text-bg-secondary">Inativo</span>
                            @endif
                        </td>

                        <td class="text-end">
                            <div class="btn-group">
                                <a class="btn btn-outline-primary btn-sm"
                                   href="{{ route('tenant.admin.customers.edit', ['tenant' => $tenant->slug, 'customerId' => $c->id]) }}">
                                    <i class="fa-solid fa-pen-to-square me-1"></i>Editar
                                </a>

                                @can('update', $c)
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        wire:click="toggleActive('{{ $c->id }}')">
                                    {{ $c->is_active ? 'Desativar' : 'Ativar' }}
                                </button>
                                @endcan

                                @can('delete', $c)
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        wire:click="delete('{{ $c->id }}')"
                                        wire:confirm="Excluir este cliente?">
                                    Excluir
                                </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center py-5 text-body-secondary">
                            Nenhum cliente encontrado.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>