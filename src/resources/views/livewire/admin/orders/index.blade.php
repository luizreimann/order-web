<div class="vstack gap-3">
    <x-page-header title="Pedidos" subtitle="Acompanhe e gerencie os pedidos" />

    <x-card>
        <div class="row g-2">
            <div class="col-md-4">
                <x-form-field name="search"
                              placeholder="Buscar por #, nome ou telefone..."
                              wire:model.live="search" />
            </div>

            <div class="col-md-3">
                <x-form-select name="status" wire:model.live="status">
                    <option value="all">Todos os status</option>
                    @foreach(\App\Enums\OrderStatus::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-form-select>
            </div>

            <div class="col-md-3">
                <x-form-select name="payment" wire:model.live="payment">
                    <option value="all">Todos os pagamentos</option>
                    @foreach(\App\Enums\PaymentStatus::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-form-select>
            </div>

            <div class="col-md-2">
                <x-form-select name="sort" wire:model.live="sort">
                    <option value="newest">Mais recentes</option>
                    <option value="oldest">Mais antigos</option>
                    <option value="total_desc">Maior valor</option>
                </x-form-select>
            </div>
        </div>
    </x-card>

    <x-card flush>
        <x-data-table :empty="$orders->isEmpty()" :columns="7" empty-message="Nenhum pedido encontrado.">
            <x-slot:head>
                <th>#</th>
                <th>Cliente</th>
                <th>Agendamento</th>
                <th>Status</th>
                <th>Pagamento</th>
                <th class="text-end">Total</th>
                <th class="text-end">Ações</th>
            </x-slot:head>

            @foreach($orders as $o)
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
                        <x-status-badge :status="$o->status" />
                    </td>

                    <td>
                        <x-status-badge :status="$o->payment_status" />
                        <div class="text-body-secondary small">{{ $o->payment_method ?: '—' }}</div>
                    </td>

                    <td class="text-end fw-semibold">
                        R$ {{ number_format($o->total_cents / 100, 2, ',', '.') }}
                    </td>

                    <td class="text-end">
                        <x-btn outline size="sm"
                               :href="route('tenant.admin.orders.show', ['tenant' => $tenant->slug, 'orderId' => $o->id])">
                            Ver
                        </x-btn>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-card>
</div>
