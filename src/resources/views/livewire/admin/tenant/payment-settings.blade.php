<div class="vstack gap-3">
    <x-page-header title="Configurações de Pagamento" subtitle="Gerencie métodos de pagamento e integrações" />

    @push('scripts')
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

    <ul class="nav nav-tabs">
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'methods' ? 'active' : '' }}"
                    wire:click="setTab('methods')" type="button">
                <i class="fa-solid fa-credit-card me-1"></i> Métodos de Pagamento
            </button>
        </li>
        @can('manageIntegrations', $tenant)
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'integrations' ? 'active' : '' }}"
                    wire:click="setTab('integrations')" type="button">
                <i class="fa-solid fa-plug me-1"></i> Integrações
            </button>
        </li>
        @endcan
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'history' ? 'active' : '' }}"
                    wire:click="setTab('history')" type="button">
                <i class="fa-solid fa-clock-rotate-left me-1"></i> Histórico
            </button>
        </li>
    </ul>

    @if($activeTab === 'methods')
        <x-card>
            @if (session('status_methods'))
                <div class="alert alert-success">
                    {{ session('status_methods') }}
                </div>
            @endif

            <form wire:submit.prevent="saveMethods" class="vstack gap-3">
                {{-- As duas tabelas ficam à mão: o contêiner do SortableJS é `tbody[data-sortable]` e
                     <x-data-table> não aceita atributos no <tbody>. --}}
                <div class="vstack gap-3">
                    <x-section-title>Métodos Manuais</x-section-title>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="w-100px">Ordem</th>
                                    <th>Método</th>
                                    <th class="w-100px text-center">Ativo</th>
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
                    <div class="vstack gap-3">
                        <div class="d-flex align-items-center gap-2">
                            <x-section-title>Mercado Pago</x-section-title>
                            <span class="badge text-bg-success">
                                <i class="fa-solid fa-check me-1"></i> Integrado
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="w-100px">Ordem</th>
                                        <th>Método</th>
                                        <th class="w-100px text-center">Ativo</th>
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
                    <div class="alert alert-info mb-0">
                        <i class="fa-solid fa-info-circle me-2"></i>
                        <strong>Mercado Pago não integrado.</strong>
                        Configure as credenciais na aba <a href="#" wire:click.prevent="setTab('integrations')" class="alert-link">Integrações</a> para habilitar os métodos de pagamento do Mercado Pago.
                    </div>
                @endif

                <x-card muted>
                    <div class="vstack gap-3">
                        <x-section-title>Preview do Checkout</x-section-title>
                        <div class="bg-white rounded p-3 border">
                            @forelse($this->enabledMethods as $method)
                                <div class="mb-2">
                                    <x-form-check type="radio" name="preview_method_{{ $loop->index }}" :label="$method['label']" :validates="false" disabled />
                                </div>
                            @empty
                                <div class="text-muted small">Nenhum método habilitado</div>
                            @endforelse
                        </div>
                    </div>
                </x-card>

                <div>
                    <x-btn type="submit" loading="saveMethods">
                        <x-slot:icon><i class="fa-solid fa-save"></i></x-slot:icon>
                        Salvar Métodos
                    </x-btn>
                </div>
            </form>
        </x-card>
    @endif

    @if($activeTab === 'integrations')
        <x-card>
            @if (session('status_integrations'))
                <div class="alert alert-success">
                    {{ session('status_integrations') }}
                </div>
            @endif

            {{-- ARC-006: segredos write-only. Os valores digitados ficam só no estado local do Alpine e
                 viajam como PARÂMETRO da action (nunca em propriedade pública/snapshot do Livewire).
                 Os inputs de segredo usam `x-model` (atributo comum que o <x-form-field> repassa ao <input>);
                 NÃO usar `wire:model` neles. --}}
            <form x-data="{ mpAccessToken: '', mpWebhookSecret: '' }"
                  x-on:submit.prevent="$wire.saveIntegrations(mpAccessToken, mpWebhookSecret).then(() => { mpAccessToken = ''; mpWebhookSecret = ''; })"
                  class="vstack gap-3">
                {{-- Badges com ícone e `danger` (falha de conexão) não cabem em <x-status-badge>. --}}
                <div class="d-flex align-items-center gap-2">
                    <x-section-title>Mercado Pago</x-section-title>
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

                <div class="row g-3">
                    <div class="col-lg-6">
                        <x-form-field name="mp_public_key" label="Public Key" wire:model="mp_public_key" placeholder="APP_USR-..." />
                    </div>

                    <div class="col-lg-6">
                        <x-form-field type="password" name="mp_access_token" label="Access Token"
                                      x-model="mpAccessToken" autocomplete="new-password"
                                      :placeholder="$this->mpAccessTokenMask ? 'Deixe em branco para manter o atual' : 'APP_USR-...'">
                            <x-slot:after>
                                @if($this->mpAccessTokenMask)
                                    <div class="form-text d-flex align-items-center gap-2">
                                        <span>Configurado: <code>{{ $this->mpAccessTokenMask }}</code></span>
                                        <x-btn variant="link" size="sm" class="text-danger p-0"
                                               wire:click="clearAccessToken"
                                               wire:confirm="Remover o Access Token salvo? Os pagamentos via Mercado Pago deixarão de funcionar.">
                                            Remover
                                        </x-btn>
                                    </div>
                                @endif
                            </x-slot:after>
                        </x-form-field>
                    </div>

                    <div class="col-lg-6">
                        {{-- Label com <span> estilizado: <x-form-field label> só aceita texto, então o label fica à mão. --}}
                        <label class="form-label" for="mp_webhook_secret">Webhook Secret <span class="text-muted">(opcional)</span></label>
                        <x-form-field type="password" name="mp_webhook_secret" id="mp_webhook_secret"
                                      help="Usado para validar a assinatura dos webhooks do Mercado Pago."
                                      x-model="mpWebhookSecret" autocomplete="new-password"
                                      :placeholder="$this->mpWebhookSecretMask ? 'Deixe em branco para manter o atual' : 'Chave secreta do webhook'">
                            <x-slot:after>
                                @if($this->mpWebhookSecretMask)
                                    <div class="form-text d-flex align-items-center gap-2">
                                        <span>Configurado: <code>{{ $this->mpWebhookSecretMask }}</code></span>
                                        <x-btn variant="link" size="sm" class="text-danger p-0"
                                               wire:click="clearWebhookSecret"
                                               wire:confirm="Remover o Webhook Secret salvo?">
                                            Remover
                                        </x-btn>
                                    </div>
                                @endif
                            </x-slot:after>
                        </x-form-field>
                    </div>
                </div>

                @if($mpConnectionStatus === 'missing_credentials')
                    <div class="alert alert-warning mb-0">
                        <i class="fa-solid fa-exclamation-triangle me-2"></i>
                        Preencha a Public Key e o Access Token (ou use o já salvo) para testar a conexão.
                    </div>
                @endif

                <div class="d-flex gap-2">
                    <x-btn type="submit" loading="saveIntegrations">
                        <x-slot:icon><i class="fa-solid fa-save"></i></x-slot:icon>
                        Salvar Credenciais
                    </x-btn>
                    <x-btn variant="secondary" outline x-on:click="$wire.testMercadoPagoConnection(mpAccessToken)">
                        <x-slot:icon><i class="fa-solid fa-plug"></i></x-slot:icon>
                        Testar Conexão
                    </x-btn>
                </div>
            </form>
        </x-card>

        <x-card>
            <div class="vstack gap-3">
                <div class="d-flex align-items-center gap-2">
                    <x-section-title>Stripe</x-section-title>
                    <span class="badge text-bg-secondary">Em breve</span>
                </div>
                <p class="text-muted mb-0">Integração com Stripe estará disponível em uma próxima atualização.</p>
            </div>
        </x-card>

        <x-card>
            <div class="vstack gap-3">
                <div class="d-flex align-items-center gap-2">
                    <x-section-title>PagSeguro</x-section-title>
                    <span class="badge text-bg-secondary">Em breve</span>
                </div>
                <p class="text-muted mb-0">Integração com PagSeguro estará disponível em uma próxima atualização.</p>
            </div>
        </x-card>
    @endif

    @if($activeTab === 'history')
        <x-card>
            <div class="vstack gap-3">
                <x-section-title>Últimos Pagamentos</x-section-title>

                @if(count($this->recentPayments) > 0)
                    <x-data-table :columns="5">
                        <x-slot:head>
                            <th>Data</th>
                            <th>Pedido</th>
                            <th>Gateway</th>
                            <th>Valor</th>
                            <th>Status</th>
                        </x-slot:head>

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
                                    {{-- Tag de provedor (não é status de domínio): `primary` não é aceito por <x-status-badge>. --}}
                                    <span class="badge text-bg-primary">{{ ucfirst($payment['gateway']) }}</span>
                                </td>
                                <td>
                                    R$ {{ number_format($payment['amount_cents'] / 100, 2, ',', '.') }}
                                </td>
                                <td>
                                    @php
                                        // status BRUTO do gateway; valor desconhecido cai no fallback neutro.
                                        $gatewayStatus = \App\Enums\GatewayPaymentStatus::tryFrom((string) $payment['status']);
                                    @endphp
                                    @if($gatewayStatus)
                                        <x-status-badge :status="$gatewayStatus" />
                                    @else
                                        <span class="badge text-bg-secondary">{{ $payment['status'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-data-table>
                @else
                    <x-empty-state message="Nenhum pagamento registrado ainda." />
                @endif
            </div>
        </x-card>
    @endif
</div>
