<div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Checkout</h1>
            <div class="text-muted small">Finalize sua encomenda</div>
        </div>

        <a class="btn btn-outline-secondary" href="{{ route('tenant.cart', ['tenant' => $tenant->slug]) }}">
            Voltar ao carrinho
        </a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    @if (count($lines) === 0)
        <div class="alert alert-info">
            Seu carrinho está vazio.
        </div>
    @else
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        @php
                            $authenticatedCustomer = auth('customer')->user();
                        @endphp
                        
                        @if($authenticatedCustomer)
                            <div class="alert alert-info small mb-3">
                                <i class="fa-solid fa-circle-check me-1"></i>
                                Seus dados foram preenchidos automaticamente. Você pode editá-los se necessário.
                            </div>
                        @endif
                        
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Nome</label>
                                <input id="customerNameInput" class="form-control" wire:model.defer="customer_name">
                                @error('customer_name') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label">WhatsApp</label>
                                <input id="customerPhoneInput" class="form-control" wire:model.defer="customer_phone" placeholder="(xx) xxxxx-xxxx">
                                @error('customer_phone') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label">E-mail</label>
                                <input id="customerEmailInput" class="form-control" wire:model.defer="customer_email" placeholder="seuemail@exemplo.com">
                                @error('customer_email') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <hr>

                        <div class="fw-semibold mb-2">Método de pagamento</div>

                        <div class="d-flex flex-column gap-2">
                            @foreach($payment_methods as $m)
                                <label class="border rounded p-2 d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="fw-semibold">{{ $m['label'] ?? '' }}</div>
                                        @if(!empty($m['provider']) && $m['provider'] === 'mercadopago')
                                            <div class="text-muted small">Mercado Pago</div>
                                        @endif
                                    </div>

                                    <input class="form-check-input" type="radio" name="payment_method_choice"
                                           wire:model.live="payment_method"
                                           value="{{ $m['id'] }}">
                                </label>
                            @endforeach
                        </div>

                        @if($this->paymentInstructions)
                            <div class="alert alert-info mt-3" style="white-space: pre-wrap;">{{ $this->paymentInstructions }}</div>
                        @endif

                        @php
                            $isMp = in_array($payment_method, ['mp_pix','mp_credit_card','mp_debit_card'], true);
                        @endphp

                        @if(!$isMp)
                            <div class="d-grid mt-3">
                                <button type="button" class="btn btn-primary" wire:click="placeOrderManual">
                                    Enviar pedido
                                </button>
                            </div>
                        @else
                            <div class="alert alert-warning mt-3 mb-0">
                                Para pagar, preencha os dados no formulário do Mercado Pago abaixo.
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="fw-semibold mb-2">Resumo</div>

                        <div class="d-flex flex-column gap-2">
                            @foreach($lines as $line)
                                <div class="d-flex align-items-start justify-content-between">
                                    <div>
                                        <div class="fw-semibold">{{ $line['product']['name'] }}</div>
                                        <div class="text-muted small">{{ $line['qty'] }}x</div>
                                    </div>
                                    <div class="text-end">
                                        R$ {{ number_format($line['total_cents'] / 100, 2, ',', '.') }}
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <hr>

                        <div class="d-flex align-items-center justify-content-between">
                            <div class="text-muted">Total</div>
                            <div class="h5 mb-0">R$ {{ number_format($this->totalCents / 100, 2, ',', '.') }}</div>
                        </div>
                    </div>
                </div>

                @if($mpPublicKey)
                    <div class="card shadow-sm mt-3" id="mpPaymentCard" style="{{ $isMp ? '' : 'display:none;' }}">
                        <div class="card-body">
                            <div class="fw-semibold mb-2">Pagamento (Mercado Pago)</div>

                            <div wire:ignore>
                                <div id="paymentBrick_container"></div>
                            </div>

                            <div id="mpPixInfo" class="mt-3" style="display:none;"></div>
                        </div>
                    </div>

                    @push('scripts')
                    <script src="https://sdk.mercadopago.com/js/v2"></script>
                    <script>
                        (function () {
                            const MP_PUBLIC_KEY = @json($mpPublicKey);
                            const PROCESS_URL = @json(route('tenant.process_payment', ['tenant' => $tenant->slug]));

                            function getCsrf() {
                                const el = document.querySelector('meta[name="csrf-token"]');
                                return el ? el.getAttribute('content') : '';
                            }

                            function getInputValue(id) {
                                const el = document.getElementById(id);
                                return el ? (el.value || '') : '';
                            }

                            function getSelectedPaymentMethod() {
                                const el = document.querySelector('input[name="payment_method_choice"]:checked');
                                return el ? el.value : null;
                            }

                            function buildPaymentMethodsConfig(allowed) {
                                const paymentMethods = {
                                    maxInstallments: 1,
                                    minInstallments: 1,
                                };

                                if (allowed && allowed.creditCard) paymentMethods.creditCard = allowed.creditCard;
                                if (allowed && allowed.debitCard) paymentMethods.debitCard = allowed.debitCard;
                                if (allowed && allowed.bankTransfer) paymentMethods.bankTransfer = allowed.bankTransfer;

                                return paymentMethods;
                            }

                            async function mountBrick(payload) {
                                const container = document.getElementById('paymentBrick_container');
                                const card = document.getElementById('mpPaymentCard');

                                if (!payload || !payload.publicKey || !payload.processPaymentUrl) {
                                    if (card) card.style.display = 'none';
                                    return;
                                }

                                if (!window.MercadoPago) {
                                    console.error('MercadoPago SDK not loaded');
                                    return;
                                }

                                if (card) card.style.display = '';

                                try {
                                    if (window.paymentBrickController && window.paymentBrickController.unmount) {
                                        window.paymentBrickController.unmount();
                                    }
                                } catch (e) {}

                                const mp = new MercadoPago(payload.publicKey);
                                const bricksBuilder = mp.bricks();

                                const settings = {
                                    initialization: {
                                        amount: payload.amount,
                                    },
                                    customization: {
                                        paymentMethods: buildPaymentMethodsConfig(payload.allowed),
                                    },
                                    callbacks: {
                                        onReady: () => {
                                            console.log('Payment Brick ready');
                                        },
                                        onSubmit: ({ selectedPaymentMethod, formData }) => {
                                            return new Promise((resolve, reject) => {
                                                const currentMethod = getSelectedPaymentMethod();
                                                fetch(payload.processPaymentUrl, {
                                                    method: 'POST',
                                                    headers: {
                                                        'Content-Type': 'application/json',
                                                        'X-CSRF-TOKEN': getCsrf(),
                                                        'Accept': 'application/json',
                                                    },
                                                    body: JSON.stringify({
                                                        mp_method: currentMethod,
                                                        customer: {
                                                            name: getInputValue('customerNameInput'),
                                                            phone: getInputValue('customerPhoneInput'),
                                                            email: getInputValue('customerEmailInput'),
                                                        },
                                                        selectedPaymentMethod,
                                                        formData,
                                                    }),
                                                })
                                                    .then(r => r.json().then(j => ({ ok: r.ok, json: j })))
                                                    .then(({ ok, json }) => {
                                                        if (!ok) {
                                                            console.error('Payment failed:', json);
                                                            reject();
                                                            return;
                                                        }

                                                        if (json && json.point_of_interaction && json.point_of_interaction.transaction_data) {
                                                            const td = json.point_of_interaction.transaction_data;
                                                            const box = document.getElementById('mpPixInfo');
                                                            if (box) {
                                                                const qr = td.qr_code || '';
                                                                const qrBase64 = td.qr_code_base64 || '';
                                                                const ticket = td.ticket_url || '';
                                                                box.style.display = '';
                                                                box.className = 'alert alert-info';
                                                                box.innerHTML = '';

                                                                if (ticket) {
                                                                    const a = document.createElement('a');
                                                                    a.href = ticket;
                                                                    a.target = '_blank';
                                                                    a.rel = 'noopener';
                                                                    a.textContent = 'Abrir link de pagamento';
                                                                    box.appendChild(a);
                                                                }

                                                                if (qrBase64) {
                                                                    const img = document.createElement('img');
                                                                    img.src = 'data:image/png;base64,' + qrBase64;
                                                                    img.alt = 'QR Code PIX';
                                                                    img.style.maxWidth = '240px';
                                                                    img.className = 'd-block mt-3 rounded border bg-white p-2';
                                                                    box.appendChild(img);
                                                                }

                                                                if (qr) {
                                                                    const wrapper = document.createElement('div');
                                                                    wrapper.className = 'mt-3';

                                                                    const label = document.createElement('div');
                                                                    label.className = 'fw-semibold mb-1';
                                                                    label.textContent = 'Copia e cola';
                                                                    wrapper.appendChild(label);

                                                                    const inputGroup = document.createElement('div');
                                                                    inputGroup.className = 'input-group';

                                                                    const input = document.createElement('input');
                                                                    input.type = 'text';
                                                                    input.className = 'form-control';
                                                                    input.readOnly = true;
                                                                    input.value = qr;
                                                                    inputGroup.appendChild(input);

                                                                    const btn = document.createElement('button');
                                                                    btn.type = 'button';
                                                                    btn.className = 'btn btn-outline-secondary';
                                                                    btn.textContent = 'Copiar';
                                                                    btn.addEventListener('click', async () => {
                                                                        try {
                                                                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                                                                await navigator.clipboard.writeText(qr);
                                                                            } else {
                                                                                input.focus();
                                                                                input.select();
                                                                                document.execCommand('copy');
                                                                            }

                                                                            btn.textContent = 'Copiado!';
                                                                            setTimeout(() => (btn.textContent = 'Copiar'), 1500);
                                                                        } catch (e) {
                                                                            btn.textContent = 'Falhou';
                                                                            setTimeout(() => (btn.textContent = 'Copiar'), 1500);
                                                                        }
                                                                    });
                                                                    inputGroup.appendChild(btn);

                                                                    wrapper.appendChild(inputGroup);
                                                                    box.appendChild(wrapper);
                                                                }
                                                            }
                                                        }

                                                        resolve();
                                                    })
                                                    .catch((e) => {
                                                        console.error('Payment error:', e);
                                                        reject();
                                                    });
                                            });
                                        },
                                        onError: (error) => {
                                            console.error('Brick error:', error);
                                        },
                                    },
                                };

                                try {
                                    window.paymentBrickController = await bricksBuilder.create('payment', 'paymentBrick_container', settings);
                                } catch (e) {
                                    console.error('Failed to create brick:', e);
                                }
                            }

                            function isMpMethod(method) {
                                return ['mp_pix', 'mp_credit_card', 'mp_debit_card'].includes(method);
                            }

                            function buildAllowed(method) {
                                const allowed = {
                                    creditCard: null,
                                    debitCard: null,
                                    bankTransfer: null,
                                };

                                if (method === 'mp_credit_card') allowed.creditCard = 'all';
                                if (method === 'mp_debit_card') allowed.debitCard = 'all';
                                if (method === 'mp_pix') allowed.bankTransfer = ['pix'];

                                return allowed;
                            }

                            document.addEventListener('livewire:init', () => {
                                Livewire.on('checkout-mp-init', (payload) => {
                                    if (Array.isArray(payload)) payload = payload[0];
                                    mountBrick(payload);
                                });
                            });

                            document.addEventListener('DOMContentLoaded', () => {
                                const initialMethod = @json($payment_method);
                                if (isMpMethod(initialMethod)) {
                                    setTimeout(() => {
                                        mountBrick({
                                            publicKey: MP_PUBLIC_KEY,
                                            processPaymentUrl: PROCESS_URL,
                                            amount: @json(round($this->totalCents / 100, 2)),
                                            mpMethod: initialMethod,
                                            allowed: buildAllowed(initialMethod),
                                        });
                                    }, 500);
                                }
                            });
                        })();
                    </script>
                    @endpush
                @endif
            </div>
        </div>
    @endif
</div>
