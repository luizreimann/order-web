<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Pedidos</h1>
            <div class="text-body-secondary small">Acompanhe e gerencie os pedidos</div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-4">
                    <input class="form-control"
                           placeholder="Buscar por #, nome ou telefone..."
                           wire:model.live="search">
                </div>

                <div class="col-md-3">
                    <select class="form-select" wire:model.live="status">
                        <option value="all">Todos os status</option>
                        <option value="pending">Pendente</option>
                        <option value="confirmed">Confirmado</option>
                        <option value="done">Concluído</option>
                        <option value="cancelled">Cancelado</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <select class="form-select" wire:model.live="payment">
                        <option value="all">Todos os pagamentos</option>
                        <option value="unpaid">Não pago</option>
                        <option value="paid">Pago</option>
                        <option value="refunded">Reembolsado</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <select class="form-select" wire:model.live="sort">
                        <option value="newest">Mais recentes</option>
                        <option value="oldest">Mais antigos</option>
                        <option value="total_desc">Maior valor</option>
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
                    <th>#</th>
                    <th>Cliente</th>
                    <th>Agendamento</th>
                    <th>Status</th>
                    <th>Pagamento</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                @forelse($orders as $o)
                    <tr>
                        <td class="fw-semibold">
                            #{{ $o->number }}
                        </td>

                        <td>
                            <div class="fw-semibold">{{ $o->customer_name }}</div>
                            <div class="text-body-secondary small">{{ $o->customer_phone ?: '—' }}</div>
                        </td>

                        <td class="text-body-secondary">
                            @if($o->scheduled_for)
                                {{ $o->scheduled_for->format('d/m/Y H:i') }}
                            @else
                                —
                            @endif
                            <div class="small">
                                {{ $o->fulfillment_type === 'delivery' ? 'Entrega' : 'Retirada' }}
                            </div>
                        </td>

                        <td>
                            @php
                                $badge = match($o->status) {
                                    'pending' => 'text-bg-warning',
                                    'confirmed' => 'text-bg-primary',
                                    'done' => 'text-bg-success',
                                    'cancelled' => 'text-bg-secondary',
                                    default => 'text-bg-secondary',
                                };
                            @endphp
                            <span class="badge {{ $badge }}">{{ $o->status }}</span>
                        </td>

                        <td>
                            @php
                                $pbadge = match($o->payment_status) {
                                    'paid' => 'text-bg-success',
                                    'unpaid' => 'text-bg-warning',
                                    'refunded' => 'text-bg-secondary',
                                    default => 'text-bg-secondary',
                                };
                            @endphp
                            <span class="badge {{ $pbadge }}">{{ $o->payment_status }}</span>
                            <div class="text-body-secondary small">{{ $o->payment_method ?: '—' }}</div>
                        </td>

                        <td class="text-end fw-semibold">
                            R$ {{ number_format($o->total_cents / 100, 2, ',', '.') }}
                        </td>

                        <td class="text-end">
                            <a class="btn btn-outline-primary btn-sm"
                               href="{{ route('tenant.admin.orders.show', ['tenant' => $tenant->slug, 'orderId' => $o->id]) }}">
                                Ver
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-body-secondary">
                            Nenhum pedido encontrado.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>