<div class="vstack gap-3">
    <x-page-header :title="'Pedido #'.$order->number" :subtitle="'Criado em '.$order->created_at->format('d/m/Y H:i')">
        <x-slot:actions>
            <x-btn variant="secondary" outline
                   :href="route('tenant.admin.orders.index', ['tenant' => $tenant->slug])">
                Voltar
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    @if(session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <x-card>
                <div class="vstack gap-3">
                    <x-section-title>Itens</x-section-title>

                    <x-data-table :columns="4">
                        <x-slot:head>
                            <th>Produto</th>
                            <th class="text-end">Qtd</th>
                            <th class="text-end">Unit.</th>
                            <th class="text-end">Total</th>
                        </x-slot:head>

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
                    </x-data-table>

                    <hr class="m-0">

                    <div class="d-flex justify-content-end">
                        <div class="min-w-280">
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
            </x-card>
        </div>

        <div class="col-lg-4">
            <div class="vstack gap-3">
                <x-card>
                    <div class="vstack gap-3">
                        <div class="vstack gap-2">
                            <x-section-title>Cliente</x-section-title>
                            <div>
                                <div class="fw-semibold">{{ $order->customer_name }}</div>
                                <div class="text-body-secondary small">{{ $order->customer_phone ?: '—' }}</div>
                            </div>
                        </div>

                        <hr class="m-0">

                        <div class="vstack gap-2">
                            <x-section-title>Entrega / Retirada</x-section-title>
                            <div class="text-body-secondary">
                                {{ $order->fulfillment_type === 'delivery' ? 'Entrega' : 'Retirada' }}
                                @if($order->scheduled_for)
                                    • {{ $order->scheduled_for->format('d/m/Y H:i') }}
                                @endif
                            </div>

                            @if($order->fulfillment_type === 'delivery')
                                <div class="text-body-secondary small">
                                    {{ $order->address_line1 }}<br>
                                    {{ $order->address_line2 }}<br>
                                    {{ $order->address_city }} - {{ $order->address_state }}<br>
                                    {{ $order->address_postal_code }}
                                </div>
                            @endif

                            @if($order->notes)
                                <div>
                                    <div class="text-body-secondary small">Observações</div>
                                    <div>{{ $order->notes }}</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <div class="vstack gap-3">
                        <x-section-title>Status</x-section-title>

                        <form wire:submit.prevent="updateStatus" class="d-grid gap-2">
                            <div>
                                <x-form-select name="status" label="Pedido" wire:model.defer="status">
                                    @foreach(\App\Enums\OrderStatus::cases() as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </x-form-select>
                            </div>

                            <div>
                                <x-form-select name="payment_status" label="Pagamento" wire:model.defer="payment_status">
                                    @foreach(\App\Enums\PaymentStatus::cases() as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </x-form-select>
                            </div>

                            <div>
                                <x-form-field name="payment_method" label="Método"
                                              placeholder="pix / dinheiro / cartão"
                                              wire:model.defer="payment_method" />
                            </div>

                            <x-btn type="submit" loading="updateStatus">Atualizar</x-btn>
                        </form>
                    </div>
                </x-card>
            </div>
        </div>
    </div>
</div>
