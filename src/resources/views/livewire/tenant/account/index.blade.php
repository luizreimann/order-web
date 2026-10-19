<div class="container py-4">
    <div class="row">
        <div class="col-12 col-md-3 mb-4">
            <x-card>
                <div class="vstack gap-2">
                    <x-section-title>Minha Conta</x-section-title>
                    <div class="list-group list-group-flush">
                        <a href="{{ route('tenant.account', ['tenant' => $tenant->slug]) }}" class="list-group-item list-group-item-action active">
                            <i class="fa-solid fa-home me-2"></i>
                            Início
                        </a>
                        <a href="{{ route('tenant.account.profile', ['tenant' => $tenant->slug]) }}" class="list-group-item list-group-item-action">
                            <i class="fa-solid fa-user me-2"></i>
                            Meus Dados
                        </a>
                    </div>
                </div>
            </x-card>
        </div>

        <div class="col-12 col-md-9">
            <div class="vstack gap-4">
                <x-card>
                    <div class="vstack gap-3">
                        <x-page-header :title="'Olá, '.($customer->name ?: 'Cliente').'!'" subtitle="Bem-vindo à sua área do cliente." />

                        <div class="row g-3">
                            <div class="col-md-6">
                                <x-card muted>
                                    <div class="vstack gap-2">
                                        <x-section-title sub>Telefone</x-section-title>
                                        <p class="mb-0">{{ $customer->phone }}</p>
                                    </div>
                                </x-card>
                            </div>
                            <div class="col-md-6">
                                <x-card muted>
                                    <div class="vstack gap-2">
                                        <x-section-title sub>Email</x-section-title>
                                        <p class="mb-0">{{ $customer->email ?: 'Não informado' }}</p>
                                    </div>
                                </x-card>
                            </div>
                        </div>
                    </div>
                </x-card>

                @if(count($recentOrders) > 0)
                    <x-card>
                        <x-slot:header>
                            <x-section-title>Pedidos Recentes</x-section-title>
                        </x-slot:header>

                        <x-data-table>
                            <x-slot:head>
                                <th>Data</th>
                                <th>Status</th>
                                <th>Total</th>
                            </x-slot:head>

                            @foreach($recentOrders as $order)
                                <tr>
                                    <td>{{ $order->created_at->format('d/m/Y H:i') }}</td>
                                    <td>
                                        <x-status-badge :status="$order->status" />
                                    </td>
                                    <td>R$ {{ number_format($order->total_cents / 100, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </x-data-table>
                    </x-card>
                @else
                    <x-empty-state message="Você ainda não fez nenhum pedido." />
                @endif
            </div>
        </div>
    </div>
</div>
{{-- To attain knowledge, add things every day; To attain wisdom, subtract things every day. --}}
