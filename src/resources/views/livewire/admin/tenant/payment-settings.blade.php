<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Configurações de Pagamento</h1>
            <div class="text-muted small">Gerencie métodos de pagamento e integrações</div>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script>
        document.addEventListener('livewire:navigated', initSortable);
        document.addEventListener('DOMContentLoaded', initSortable);

        function initSortable() {
            document.querySelectorAll('[data-sortable]').forEach(el => {
                if (el._sortable) return;

                el._sortable = new Sortable(el, {
                    handle: '.sortable-handle',
                    animation: 150,
                    ghostClass: 'bg-light',
                    onEnd: function(evt) {
                        const methodId = evt.item.dataset.methodId;
                        const newIndex = evt.newIndex;
                        const oldIndex = evt.oldIndex;

                        if (newIndex !== oldIndex) {
                            @this.reorderMethod(methodId, oldIndex, newIndex);
                        }
                    }
                });
            });
        }
    </script>
    @endpush

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'methods' ? 'active' : '' }}"
                    wire:click="setTab('methods')" type="button">
                <i class="fa-solid fa-credit-card me-1"></i> Métodos de Pagamento
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'integrations' ? 'active' : '' }}"
                    wire:click="setTab('integrations')" type="button">
                <i class="fa-solid fa-plug me-1"></i> Integrações
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'history' ? 'active' : '' }}"
                    wire:click="setTab('history')" type="button">
                <i class="fa-solid fa-clock-rotate-left me-1"></i> Histórico
            </button>
        </li>
    </ul>

    @if($activeTab === 'methods')
        <div class="card shadow-sm">
            <div class="card-body">
                @if (session('status_methods'))
                    <div class="alert alert-success">
                        {{ session('status_methods') }}
                    </div>
                @endif

                <form wire:submit.prevent="saveMethods">
                    <div class="mb-4">
                        <h5 class="mb-3">Métodos Manuais</h5>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Ordem</th>
                                        <th>Método</th>
                                        <th style="width: 100px;" class="text-center">Ativo</th>
                                    </tr>
                                </thead>
                                <tbody data-sortable>
                                    @foreach($payment_methods as $i => $m)
                                        @if(!App\Enums\PaymentMethod::isMercadoPagoId($m['id']))
                                            @include('livewire.admin.tenant.partials.payment-method-row', ['method' => $m, 'index' => $i])
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    @if($this->isMercadoPagoIntegrated())
                        <div class="mb-4">
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <h5 class="mb-0">Mercado Pago</h5>
                                <span class="badge text-bg-success">
                                    <i class="fa-solid fa-check me-1"></i> Integrado
                                </span>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 100px;">Ordem</th>
                                            <th>Método</th>
                                            <th style="width: 100px;" class="text-center">Ativo</th>
                                        </tr>
                                    </thead>
                                    <tbody data-sortable>
                                        @foreach($payment_methods as $i => $m)
                                            @if(App\Enums\PaymentMethod::isMercadoPagoId($m['id']))
                                                @include('livewire.admin.tenant.partials.payment-method-row', ['method' => $m, 'index' => $i])
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @else
                        <div class="alert alert-info mb-4">
                            <i class="fa-solid fa-info-circle me-2"></i>
                            <strong>Mercado Pago não integrado.</strong>
                            Configure as credenciais na aba <a href="#" wire:click.prevent="setTab('integrations')" class="alert-link">Integrações</a> para habilitar os métodos de pagamento do Mercado Pago.
                        </div>
                    @endif

                    <div class="card bg-light mb-4">
                        <div class="card-body">
                            <h6 class="mb-3">Preview do Checkout</h6>
                            <div class="bg-white rounded p-3 border">
                                @forelse($this->enabledMethods as $method)
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" disabled>
                                        <label class="form-check-label">
                                            {{ $method['label'] }}
                                        </label>
                                    </div>
                                @empty
                                    <div class="text-muted small">Nenhum método habilitado</div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <button class="btn btn-primary" type="submit">
                        <i class="fa-solid fa-save me-1"></i> Salvar Métodos
                    </button>
                </form>
            </div>
        </div>
    @endif

    @if($activeTab === 'integrations')
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                @if (session('status_integrations'))
                    <div class="alert alert-success">
                        {{ session('status_integrations') }}
                    </div>
                @endif

                <form wire:submit.prevent="saveIntegrations">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <h5 class="mb-0">Mercado Pago</h5>
                        @if($this->isMercadoPagoIntegrated())
                            @if($mpConnectionStatus === 'connected')
                                <span class="badge text-bg-success">
                                    <i class="fa-solid fa-check me-1"></i> Conectado
                                </span>
                            @elseif($mpConnectionStatus === 'failed')
                                <span class="badge text-bg-danger">
                                    <i class="fa-solid fa-times me-1"></i> Falha na conexão
                                </span>
                            @else
                                <span class="badge text-bg-warning text-dark">
                                    <i class="fa-solid fa-question me-1"></i> Não verificado
                                </span>
                            @endif
                        @else
                            <span class="badge text-bg-secondary">
                                <i class="fa-solid fa-minus me-1"></i> Não configurado
                            </span>
                        @endif
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-lg-6">
                            <label class="form-label">Public Key</label>
                            <input class="form-control" wire:model="mp_public_key" placeholder="APP_USR-...">
                            @error('mp_public_key')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-lg-6">
                            <label class="form-label">Access Token</label>
                            <input class="form-control" type="password" wire:model="mp_access_token" placeholder="APP_USR-...">
                            @error('mp_access_token')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-lg-6">
                            <label class="form-label">Webhook Secret <span class="text-muted">(opcional)</span></label>
                            <input class="form-control" type="password" wire:model="mp_webhook_secret" placeholder="Chave secreta do webhook">
                            @error('mp_webhook_secret')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Usado para validar a assinatura dos webhooks do Mercado Pago.</div>
                        </div>
                    </div>

                    @if($mpConnectionStatus === 'missing_credentials')
                        <div class="alert alert-warning">
                            <i class="fa-solid fa-exclamation-triangle me-2"></i>
                            Preencha a Public Key e o Access Token para testar a conexão.
                        </div>
                    @endif

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit">
                            <i class="fa-solid fa-save me-1"></i> Salvar Credenciais
                        </button>
                        <button class="btn btn-outline-secondary" type="button" wire:click="testMercadoPagoConnection">
                            <i class="fa-solid fa-plug me-1"></i> Testar Conexão
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <h5 class="mb-0">Stripe</h5>
                    <span class="badge text-bg-secondary">Em breve</span>
                </div>
                <p class="text-muted mb-0">Integração com Stripe estará disponível em uma próxima atualização.</p>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <h5 class="mb-0">PagSeguro</h5>
                    <span class="badge text-bg-secondary">Em breve</span>
                </div>
                <p class="text-muted mb-0">Integração com PagSeguro estará disponível em uma próxima atualização.</p>
            </div>
        </div>
    @endif

    @if($activeTab === 'history')
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="mb-3">Últimos Pagamentos</h5>

                @if(count($this->recentPayments) > 0)
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Pedido</th>
                                    <th>Gateway</th>
                                    <th>Valor</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($this->recentPayments as $payment)
                                    <tr>
                                        <td class="text-muted small">
                                            {{ \Carbon\Carbon::parse($payment['created_at'])->format('d/m/Y H:i') }}
                                        </td>
                                        <td>
                                            @if(isset($payment['order']))
                                                <a href="{{ route('tenant.admin.orders.show', ['tenant' => $tenant->slug, 'orderId' => $payment['order_id']]) }}" class="text-decoration-none">
                                                    #{{ $payment['order']['number'] ?? '-' }}
                                                </a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge text-bg-primary">{{ ucfirst($payment['gateway']) }}</span>
                                        </td>
                                        <td>
                                            R$ {{ number_format($payment['amount_cents'] / 100, 2, ',', '.') }}
                                        </td>
                                        <td>
                                            @php
                                                $statusClass = match($payment['status']) {
                                                    'approved' => 'success',
                                                    'pending', 'in_process' => 'warning',
                                                    'rejected', 'cancelled' => 'danger',
                                                    default => 'secondary',
                                                };
                                                $statusLabel = match($payment['status']) {
                                                    'approved' => 'Aprovado',
                                                    'pending' => 'Pendente',
                                                    'in_process' => 'Processando',
                                                    'rejected' => 'Rejeitado',
                                                    'cancelled' => 'Cancelado',
                                                    'refunded' => 'Reembolsado',
                                                    default => $payment['status'],
                                                };
                                            @endphp
                                            <span class="badge text-bg-{{ $statusClass }}">{{ $statusLabel }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted mb-0">Nenhum pagamento registrado ainda.</p>
                @endif
            </div>
        </div>
    @endif
</div>
