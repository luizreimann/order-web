<div class="container py-4">
    <div class="row">
        <div class="col-12 col-md-3 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Minha Conta</h5>
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
            </div>
        </div>

        <div class="col-12 col-md-9">
            <div class="card mb-4">
                <div class="card-body">
                    <h4 class="card-title mb-3">Olá, {{ $customer->name ?: 'Cliente' }}!</h4>
                    <p class="text-muted">Bem-vindo à sua área do cliente.</p>
                    
                    <div class="row g-3 mt-3">
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">Telefone</h6>
                                    <p class="mb-0">{{ $customer->phone }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="text-muted mb-2">Email</h6>
                                    <p class="mb-0">{{ $customer->email ?: 'Não informado' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if(count($recentOrders) > 0)
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Pedidos Recentes</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Status</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentOrders as $order)
                                        <tr>
                                            <td>{{ $order->created_at->format('d/m/Y H:i') }}</td>
                                            <td>
                                                <span class="badge bg-{{ $order->status === 'completed' ? 'success' : 'warning' }}">
                                                    {{ ucfirst($order->status) }}
                                                </span>
                                            </td>
                                            <td>R$ {{ number_format($order->total_cents / 100, 2, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @else
                <div class="alert alert-info">
                    Você ainda não fez nenhum pedido.
                </div>
            @endif
        </div>
    </div>
</div>
{{-- To attain knowledge, add things every day; To attain wisdom, subtract things every day. --}}
