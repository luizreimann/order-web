<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Pedido #{{ $order->number }}</h1>
            <div class="text-body-secondary small">
                Criado em {{ $order->created_at->format('d/m/Y H:i') }}
            </div>
        </div>

        <a class="btn btn-outline-secondary"
           href="{{ route('tenant.admin.orders.index', ['tenant' => $tenant->slug]) }}">
            Voltar
        </a>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Itens</h2>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="border-bottom">
                            <tr>
                                <th>Produto</th>
                                <th class="text-end">Qtd</th>
                                <th class="text-end">Unit.</th>
                                <th class="text-end">Total</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($order->items as $it)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $it->name }}</div>
                                        @if($it->notes)
                                            <div class="text-body-secondary small">{{ $it->notes }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $it->quantity }}</td>
                                    <td class="text-end">
                                        R$ {{ number_format($it->unit_price_cents / 100, 2, ',', '.') }}
                                    </td>
                                    <td class="text-end fw-semibold">
                                        R$ {{ number_format($it->total_cents / 100, 2, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-end">
                        <div style="min-width: 280px;">
                            <div class="d-flex justify-content-between">
                                <div class="text-body-secondary">Subtotal</div>
                                <div>R$ {{ number_format($order->subtotal_cents / 100, 2, ',', '.') }}</div>
                            </div>
                            <div class="d-flex justify-content-between">
                                <div class="text-body-secondary">Desconto</div>
                                <div>R$ {{ number_format($order->discount_cents / 100, 2, ',', '.') }}</div>
                            </div>
                            <div class="d-flex justify-content-between">
                                <div class="text-body-secondary">Entrega</div>
                                <div>R$ {{ number_format($order->delivery_fee_cents / 100, 2, ',', '.') }}</div>
                            </div>
                            <div class="d-flex justify-content-between mt-2">
                                <div class="fw-semibold">Total</div>
                                <div class="fw-semibold">R$ {{ number_format($order->total_cents / 100, 2, ',', '.') }}</div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">Cliente</h2>
                    <div class="fw-semibold">{{ $order->customer_name }}</div>
                    <div class="text-body-secondary small">{{ $order->customer_phone ?: '—' }}</div>

                    <hr>

                    <h2 class="h6">Entrega / Retirada</h2>
                    <div class="text-body-secondary">
                        {{ $order->fulfillment_type === 'delivery' ? 'Entrega' : 'Retirada' }}
                        @if($order->scheduled_for)
                            • {{ $order->scheduled_for->format('d/m/Y H:i') }}
                        @endif
                    </div>

                    @if($order->fulfillment_type === 'delivery')
                        <div class="mt-2 text-body-secondary small">
                            {{ $order->address_line1 }}<br>
                            {{ $order->address_line2 }}<br>
                            {{ $order->address_city }} - {{ $order->address_state }}<br>
                            {{ $order->address_postal_code }}
                        </div>
                    @endif

                    @if($order->notes)
                        <div class="mt-3">
                            <div class="text-body-secondary small">Observações</div>
                            <div>{{ $order->notes }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Status</h2>

                    <form wire:submit.prevent="updateStatus" class="d-grid gap-2">
                        <div>
                            <label class="form-label">Pedido</label>
                            <select class="form-select" wire:model.defer="status">
                                <option value="pending">Pendente</option>
                                <option value="confirmed">Confirmado</option>
                                <option value="done">Concluído</option>
                                <option value="cancelled">Cancelado</option>
                            </select>
                            @error('status') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="form-label">Pagamento</label>
                            <select class="form-select" wire:model.defer="payment_status">
                                <option value="unpaid">Não pago</option>
                                <option value="paid">Pago</option>
                                <option value="refunded">Reembolsado</option>
                            </select>
                            @error('payment_status') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="form-label">Método</label>
                            <input class="form-control" wire:model.defer="payment_method" placeholder="pix / dinheiro / cartão">
                            @error('payment_method') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <button class="btn btn-primary" type="submit">Atualizar</button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>