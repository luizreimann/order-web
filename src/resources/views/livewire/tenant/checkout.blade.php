<div class="container py-4">
    <div class="vstack gap-3">
        <x-page-header title="Checkout" subtitle="Finalize sua encomenda">
            <x-slot:actions>
                <x-btn variant="secondary" outline :href="route('tenant.cart', ['tenant' => $tenant->slug])">
                    Voltar ao carrinho
                </x-btn>
            </x-slot:actions>
        </x-page-header>

        @if (session('status'))
            <div class="alert alert-success mb-0">
                {{ session('status') }}
            </div>
        @endif

        @error('cart')
            <div class="alert alert-warning mb-0">{{ $message }}</div>
        @enderror

        @php
            $isMp = $this->isOnlinePayment;
            // `flow` vem do gateway: `card` = formulário do cartão no navegador (tokenização);
            // qualquer outro (PIX) = o servidor cria o pagamento e a tela mostra o QR direto.
            $isCard = $isMp && ($gatewayPayload['flow'] ?? null) === 'card';
            $awaiting = $this->awaitingPayment;
        @endphp

        {{-- Aviso compacto do Pix: aparece quando o cliente FECHA o modal (o contador e a consulta do
             pagamento continuam). O texto é uma região de status; o contador fica de fora dela para não
             ser anunciado a cada segundo. Só o JS o preenche, por `textContent`. --}}
        @if ($gatewayPublicKey || $awaiting)
            <div id="pixNotice" class="d-none" wire:ignore>
                <div id="pixNoticeBox" class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <i class="fa-solid fa-qrcode" aria-hidden="true"></i>
                        <span id="pixNoticeText" role="status">Pix aguardando pagamento</span>
                        <span id="pixNoticeTimerWrap" class="d-none">
                            <span aria-hidden="true">·</span>
                            <span class="fw-semibold" data-pix-timer role="timer" aria-live="off">--:--</span>
                        </span>
                    </div>
                    <div id="pixNoticeActions" class="d-flex flex-wrap gap-2"></div>
                </div>
            </div>
        @endif

        {{-- Resultado do pagamento online que NÃO usa o modal (cartão: confirmação, análise, erro). O Pix
             vai para o modal `#pixModal` (abaixo). Montado só pelo JS, com dados do gateway tratados
             como NÃO confiáveis (ver o script abaixo). --}}
        @if ($gatewayPublicKey || $awaiting)
            <div id="mpResult" class="d-none" wire:ignore>
                <x-card>
                    <div id="mpResultBody" class="vstack gap-3" tabindex="-1"></div>
                </x-card>
            </div>
        @endif

        <div id="checkoutFormArea" class="{{ $awaiting !== null ? 'd-none' : '' }}" wire:ignore.self>
        @if (count($this->lines) === 0)
            <x-empty-state message="Seu carrinho está vazio." />
        @else
            <div class="row g-3">
                <div class="col-lg-6">
                    <x-card>
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
                                <x-form-field name="customer_name" id="customerNameInput" label="Nome"
                                              wire:model.defer="customer_name" />
                            </div>

                            <div class="col-12">
                                <x-form-field name="customer_phone" id="customerPhoneInput" label="WhatsApp"
                                              placeholder="(xx) xxxxx-xxxx"
                                              wire:model.defer="customer_phone" />
                            </div>

                            <div class="col-12">
                                {{-- Obrigatório nos métodos online: é o e-mail do comprovante e o `payer.email`
                                     do gateway. Ao sair do campo o servidor reavisa o formulário de cartão. --}}
                                <x-form-field name="customer_email" id="customerEmailInput" label="E-mail"
                                              type="email" :required="$isMp"
                                              placeholder="seuemail@exemplo.com"
                                              autocomplete="email" inputmode="email"
                                              wire:model.blur="customer_email" />
                            </div>
                        </div>

                        <hr>

                        <div class="vstack gap-2">
                            <x-section-title>Método de pagamento</x-section-title>

                            <div class="d-flex flex-column gap-2">
                                @foreach($payment_methods as $m)
                                    <label class="border rounded p-2 d-flex align-items-center justify-content-between">
                                        <div>
                                            <div class="fw-semibold">{{ ($m['label'] ?? '') ?: (\App\Enums\PaymentMethod::tryFrom((string) ($m['id'] ?? ''))?->label() ?? '') }}</div>
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
                        </div>

                        @if($this->paymentInstructions)
                            <div class="alert alert-info mt-3 pre-wrap">{{ $this->paymentInstructions }}</div>
                        @endif

                        {{-- Formulário do cartão (Card Payment Brick), consequência da escolha acima: só o
                             tipo escolhido, sem lista de meios e sem pedir o e-mail de novo. O container
                             é sempre renderizado (o Livewire só alterna a classe) e fica sob wire:ignore. --}}
                        @if($gatewayPublicKey)
                            <div class="{{ $isCard ? 'mt-3' : 'd-none' }}">
                                <div wire:ignore>
                                    <div id="cardBrickHint" class="alert alert-info mb-0 d-none">
                                        Informe seu e-mail acima para exibir o formulário do cartão.
                                    </div>
                                    <div id="cardBrickLoading" class="text-muted small d-none" role="status">
                                        Carregando formulário do cartão...
                                    </div>
                                    <div id="paymentBrick_container"></div>
                                </div>
                            </div>
                        @endif

                        @if(!$isMp)
                            <div class="d-grid mt-3">
                                <x-btn wire:click="placeOrderManual" loading="placeOrderManual">
                                    Enviar pedido
                                </x-btn>
                            </div>
                        @else
                            @if(!$isCard)
                                <div class="alert alert-info mt-3 mb-0">
                                    Ao confirmar, o QR Code do Pix aparece aqui mesmo, pronto para escanear ou copiar.
                                    O comprovante vai para o e-mail informado acima.
                                </div>
                            @endif

                            <div class="d-grid mt-3">
                                <x-btn id="mpPayButton" wire:click="payOnline" loading="payOnline">
                                    {{ $this->payActionLabel }}
                                </x-btn>
                            </div>
                        @endif

                        @if($gatewayPublicKey)
                            <div wire:ignore>
                                <div id="mpPayBusy" class="d-none" role="status">
                                    <div class="d-flex align-items-center gap-2 mt-3 text-muted">
                                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                        <span>Processando o pagamento...</span>
                                    </div>
                                </div>
                                <div id="mpPayError" class="alert alert-danger d-none mt-3 mb-0" role="alert"></div>
                            </div>
                        @endif
                    </x-card>
                </div>

                <div class="col-lg-6">
                    <div class="vstack gap-3">
                        <x-card>
                            <div class="vstack gap-2">
                                <x-section-title>Resumo</x-section-title>

                                <div class="d-flex flex-column gap-2">
                                    @foreach($this->lines as $line)
                                        <div class="d-flex align-items-start justify-content-between">
                                            <div>
                                                <div class="fw-semibold">{{ $line['product']->name }}</div>
                                                <div class="text-muted small">{{ $line['qty'] }}x</div>
                                            </div>
                                            <div class="text-end">
                                                R$ {{ number_format($line['total_cents'] / 100, 2, ',', '.') }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <hr>

                            <div class="d-flex align-items-center justify-content-between">
                                <div class="text-muted">Total</div>
                                <div class="h5 mb-0">R$ {{ number_format($this->totalCents / 100, 2, ',', '.') }}</div>
                            </div>
                        </x-card>
                    </div>
                </div>
            </div>
        @endif
        </div>
    </div>

    {{-- Modal do Pix: fica por cima do checkout (sem trocar de página). Fundo preto semitransparente
         (`.modal-backdrop` do Bootstrap) e `static`: um clique fora ou o ESC não descartam o QR; só
         "Fechar" o esconde (o aviso compacto da página assume). Markup estático: o JS só preenche
         título, corpo e rodapé, por `textContent`/nós DOM. --}}
    @if ($gatewayPublicKey || $awaiting)
        <div wire:ignore>
            <div class="modal fade" id="pixModal" tabindex="-1" role="dialog" aria-modal="true"
                 aria-labelledby="pixModalTitle" aria-hidden="true"
                 data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="pixModalTitle">Pague com Pix</h2>
                            <button type="button" class="btn-close" id="pixModalClose" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body vstack gap-3" id="pixModalBody" tabindex="-1"></div>
                        <div class="modal-footer d-none" id="pixModalFooter"></div>
                        <span class="visually-hidden" id="pixModalAnnounce" role="status" aria-live="polite"></span>
                    </div>
                </div>
            </div>
        </div>
    @endif

@if ($gatewayPublicKey || $awaiting)
@push('scripts')
<script src="https://sdk.mercadopago.com/js/v2"></script>
{{-- Device ID (antifraude do MP): `security.js` expõe `window.MP_DEVICE_SESSION_ID`, que `pay()`
     envia ao servidor. SÓ nesta página de checkout. --}}
<script src="https://www.mercadopago.com/v2/security.js" view="checkout"></script>
<script>
(function () {
    const PROCESS_URL = @js(route('tenant.process_payment', ['tenant' => $tenant->slug]));
    const STATUS_URL = @js(route('tenant.checkout.status', ['tenant' => $tenant->slug]));
    const HOME_URL = @js(route('tenant.home', ['tenant' => $tenant->slug]));

    // Estado inicial montado no servidor pelo gateway do método escolhido.
    const INITIAL_PAYLOAD = @js($isMp ? $gatewayPayload : null);
    // Pedido online desta sessão ainda sem desfecho (página recarregada depois de cobrar).
    const AWAITING = @js($awaiting);

    // Janela em que o cliente pode pagar o Pix (s): regra do SERVIDOR (MERCADOPAGO_PIX_WINDOW, teto
    // 300). O contador sai do prazo ABSOLUTO que o servidor envia (`expires_at` + `server_now`); este
    // valor só alimenta o texto e o limite do polling. O prazo de 30 min do MP não aparece na tela.
    const PIX_WINDOW_SECONDS = @js(\App\Support\PixWindow::seconds());

    // Polling do estado do pedido. Cartão: ~4 s, com backoff até 12 s, 10 min no máximo. Pix: ~3 s com
    // leve backoff até 5 s e até a janela + folga (para receber a expiração decidida pelo servidor).
    const POLL_BASE_MS = 4000;
    const POLL_MAX_DELAY_MS = 12000;
    const POLL_LIMIT_MS = 10 * 60 * 1000;
    const PIX_POLL_BASE_MS = 3000;
    const PIX_POLL_MAX_DELAY_MS = 5000;
    const PIX_POLL_GRACE_MS = 90 * 1000;   // depois de 00:00 o servidor ainda pode estar cancelando
    const PIX_RECHECK_MS = 60 * 1000;      // "Continuar verificando" / página recarregada já vencida

    // <safe-gateway-values>
    // Dados do gateway são NÃO confiáveis: só viram href/src depois destas checagens.
    // (testadas por tests/Feature/Security/CheckoutDomSafetyTest)
    function safeHttpsUrl(value) {
        if (typeof value !== 'string' || value === '') return null;
        try {
            const url = new URL(value); // sem base: relativo/`//host` lançam e caem em null
            return url.protocol === 'https:' ? url.href : null;
        } catch (e) {
            return null;
        }
    }

    function safeQrBase64(value) {
        return (typeof value === 'string' && /^[A-Za-z0-9+\/]+={0,2}$/.test(value)) ? value : '';
    }
    // </safe-gateway-values>

    // <pix-countdown>
    // Contador regressivo do Pix. Funções PURAS (testadas em Node por tests/Feature/Security/
    // CheckoutDomSafetyTest): o prazo é o instante ABSOLUTO do servidor; o relógio do cliente só entra
    // pela diferença (`offset`) medida contra o `server_now` da resposta.
    function formatCountdown(seconds) {
        const total = Math.max(0, Math.ceil(Number(seconds)));
        const safe = Number.isFinite(total) ? total : 0;
        const pad = (n) => (n < 10 ? '0' : '') + n;

        return pad(Math.floor(safe / 60)) + ':' + pad(safe % 60);
    }

    // server_now - Date.now(): quanto o relógio do servidor está à frente (+) ou atrás (-) do cliente.
    function clockOffsetMs(serverNowIso, clientNowMs) {
        const server = typeof serverNowIso === 'string' ? Date.parse(serverNowIso) : NaN;

        return Number.isFinite(server) ? server - clientNowMs : null;
    }

    // Milissegundos que faltam (nunca negativo), na hora do SERVIDOR; null se o prazo é inválido.
    function remainingMs(expiresAtIso, offsetMs, clientNowMs) {
        const expires = typeof expiresAtIso === 'string' ? Date.parse(expiresAtIso) : NaN;
        if (!Number.isFinite(expires)) return null;

        return Math.max(0, expires - (clientNowMs + (Number.isFinite(offsetMs) ? offsetMs : 0)));
    }
    // </pix-countdown>

    let activePayload = null;   // payload do método escolhido (vem do servidor)
    let cardBrick = null;       // controller do Card Payment Brick
    let brickKey = null;        // método para o qual o Brick está (ou está sendo) montado
    let mountTicket = 0;        // invalida montagens assíncronas superadas
    let paying = false;
    // `modal`: o resultado do Pix aparece no modal (`#pixModal`); cartão e o resto usam o painel da página.
    // `expiresAt`/`offsetMs`: prazo absoluto do servidor e a diferença de relógio (ver <pix-countdown>).
    let panel = { orderNumber: null, amount: null, td: {}, isPix: false, modal: false, modalOpen: false, expiresAt: null, offsetMs: 0, verifying: false };
    let panelState = null;      // último estado desenhado (awaiting, paid, expired...)
    let pixModal = null;        // instância do Modal do Bootstrap
    let poll = { timer: null, running: false, deadline: 0, attempt: 0, inflight: false };
    let countdown = { timer: null, zero: false };

    function getCsrf() {
        const el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.getAttribute('content') : '';
    }

    function unwrap(p) {
        return Array.isArray(p) ? p[0] : p;
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function toggle(id, show) {
        const node = byId(id);
        if (node) node.classList.toggle('d-none', !show);
    }

    function formatBrl(value) {
        const n = Number(value);
        return Number.isFinite(n)
            ? new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(n)
            : '';
    }

    // ------------------------------------------------------------------ feedback do botão

    function showError(message) {
        const box = byId('mpPayError');
        if (!box) return;
        box.textContent = message;
        box.classList.remove('d-none');
    }

    function clearError() {
        const box = byId('mpPayError');
        if (!box) return;
        box.textContent = '';
        box.classList.add('d-none');
    }

    // ------------------------------------------------------------------ Card Payment Brick

    function unmountBrick() {
        mountTicket++;
        try {
            if (cardBrick && cardBrick.unmount) cardBrick.unmount();
        } catch (e) {}
        cardBrick = null;
        brickKey = null;
        const container = byId('paymentBrick_container');
        if (container) container.replaceChildren();
        toggle('cardBrickLoading', false);
    }

    async function mountCardBrick(payload) {
        unmountBrick();
        const ticket = mountTicket;
        brickKey = payload.mpMethod;

        if (!window.MercadoPago) {
            console.error('MercadoPago SDK not loaded');
            brickKey = null;
            showError('Não foi possível carregar o formulário do cartão. Recarregue a página.');
            return;
        }

        toggle('cardBrickLoading', true);

        try {
            const mp = new MercadoPago(payload.publicKey, { locale: 'pt-BR' });
            const settings = {
                initialization: {
                    amount: payload.amount,
                    // E-mail já informado no formulário: com um e-mail válido o Brick não o pede de novo.
                    payer: { email: payload.payerEmail },
                },
                customization: {
                    visual: {
                        // O botão único é o da página; o Brick só entrega os dados via getFormData().
                        hidePaymentButton: true,
                    },
                    paymentMethods: {
                        minInstallments: 1,
                        maxInstallments: 1,
                        // Só o tipo escolhido à esquerda (crédito OU débito): sem reescolha.
                        types: { excluded: Array.isArray(payload.excludedTypes) ? payload.excludedTypes : [] },
                    },
                },
                callbacks: {
                    onReady: () => toggle('cardBrickLoading', false),
                    // Obrigatório pelo SDK; com o botão escondido nunca é chamado.
                    onSubmit: () => Promise.resolve(),
                    onError: (error) => {
                        console.error('Brick error:', error && error.type ? error.type : 'unknown');
                    },
                },
            };

            const controller = await mp.bricks().create('cardPayment', 'paymentBrick_container', settings);

            if (ticket !== mountTicket) {
                // o cliente já trocou de método enquanto o SDK montava
                try { controller.unmount(); } catch (e) {}
                return;
            }

            cardBrick = controller;
        } catch (e) {
            console.error('Failed to create brick:', e);
            if (ticket === mountTicket) {
                brickKey = null;
                toggle('cardBrickLoading', false);
                showError('Não foi possível carregar o formulário do cartão. Recarregue a página.');
            }
        }
    }

    // <card-form-data>
    // Aceita as duas grafias que o SDK usa (camelCase e snake_case) e descarta o resto.
    // NUNCA registrar este objeto: contém o token do cartão.
    function normalizeCardFormData(raw) {
        if (!raw || typeof raw !== 'object') return null;

        const pick = (snake, camel) => (raw[snake] !== undefined ? raw[snake] : raw[camel]);
        const token = pick('token', 'token');
        if (typeof token !== 'string' || token === '') return null;

        const payer = raw.payer && typeof raw.payer === 'object' ? raw.payer : {};
        const ident = payer.identification && typeof payer.identification === 'object' ? payer.identification : {};
        const idType = ident.type !== undefined ? ident.type : raw.identificationType;
        const idNumber = ident.number !== undefined ? ident.number : raw.identificationNumber;

        const out = {
            token: token,
            payment_method_id: pick('payment_method_id', 'paymentMethodId'),
            issuer_id: pick('issuer_id', 'issuerId'),
            installments: 1,
        };

        if (idType && idNumber) {
            out.payer = { identification: { type: String(idType), number: String(idNumber) } };
        }

        return out;
    }
    // </card-form-data>

    function handleInit(payload) {
        if (!payload || !payload.publicKey) {
            handleReset();
            return;
        }

        activePayload = payload;
        toggle('cardBrickHint', false);

        if (payload.flow !== 'card') {
            // PIX: nada a montar, o servidor cria o pagamento ao confirmar.
            unmountBrick();
            return;
        }

        if (!payload.payerEmail) {
            // Sem e-mail válido o Brick pediria o e-mail de novo: espera o campo da esquerda.
            // (Um Brick já montado fica como está: o e-mail de cobrança é o do formulário.)
            if (brickKey !== payload.mpMethod) {
                unmountBrick();
                toggle('cardBrickHint', true);
            }
            return;
        }

        if (brickKey === payload.mpMethod) return; // mesmo tipo já montado: não perde o que foi digitado

        mountCardBrick(payload);
    }

    function handleReset() {
        activePayload = null;
        unmountBrick();
        toggle('cardBrickHint', false);
        clearError();
    }

    // ------------------------------------------------------------------ pagamento

    async function pay(detail) {
        if (paying || !detail || typeof detail.method !== 'string') return;

        paying = true;
        clearError();
        toggle('mpPayBusy', true);

        try {
            let formData = {};

            if (activePayload && activePayload.mpMethod === detail.method && activePayload.flow === 'card') {
                if (!cardBrick) {
                    showError('Preencha os dados do cartão para continuar.');
                    return;
                }

                let raw = null;
                try {
                    raw = await cardBrick.getFormData();
                } catch (e) {
                    raw = null; // campos inválidos: o próprio Brick mostra o que falta
                }

                formData = normalizeCardFormData(raw);
                if (!formData) {
                    showError('Confira os dados do cartão e tente novamente.');
                    return;
                }
            }

            const response = await fetch(PROCESS_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrf(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    mp_method: detail.method,
                    customer: detail.customer,
                    formData: formData,
                    // Device ID (antifraude). O servidor valida e descarta o que não for um
                    // identificador; sem ele o pagamento segue normalmente.
                    device_session_id: typeof window.MP_DEVICE_SESSION_ID === 'string' ? window.MP_DEVICE_SESSION_ID : null,
                }),
            });

            let json = null;
            try { json = await response.json(); } catch (e) {}

            if (!response.ok) {
                showError(response.status === 419
                    ? 'Sua sessão expirou. Recarregue a página e tente novamente.'
                    : (json && typeof json.message === 'string' && json.message !== ''
                        ? json.message
                        : 'Não foi possível concluir o pagamento. Tente novamente.'));
                return;
            }

            accepted(json || {});
        } catch (e) {
            console.error('Payment error');
            showError('Não foi possível concluir o pagamento. Verifique sua conexão e tente novamente.');
        } finally {
            paying = false;
            toggle('mpPayBusy', false);
        }
    }

    // Cobrança aceita pelo servidor: pedido criado, estoque baixado, carrinho virou pedido.
    function accepted(json) {
        const poi = json.point_of_interaction && typeof json.point_of_interaction === 'object' ? json.point_of_interaction : {};

        panel = {
            orderNumber: json.order_number !== undefined ? json.order_number : null,
            amount: activePayload ? activePayload.amount : null,
            td: poi.transaction_data && typeof poi.transaction_data === 'object' ? poi.transaction_data : {},
            isPix: !!activePayload && activePayload.flow === 'direct',
            modal: false,
            modalOpen: false,
            expiresAt: null,
            offsetMs: 0,
            verifying: false,
        };
        // O Pix aparece no modal; sem o Modal do Bootstrap (script não carregou) cai no painel da página.
        panel.modal = panel.isPix && getPixModal() !== null;
        applyTiming(json);

        unmountBrick(); // some com os campos do cartão
        showResult();

        if (json.status === 'approved') {
            renderPanel('paid');
            return;
        }

        renderPanel('awaiting');
        startCountdown();
        startPolling(panel.isPix ? PIX_POLL_BASE_MS : POLL_BASE_MS);
    }

    function windowLabel() {
        const minutes = Math.max(1, Math.round(PIX_WINDOW_SECONDS / 60));
        return minutes + (minutes === 1 ? ' minuto' : ' minutos');
    }

    function showResult() {
        if (panel.modal) {
            openPixModal();
            return;
        }

        const area = byId('checkoutFormArea');
        if (area) area.classList.add('d-none');
        toggle('mpResult', true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ------------------------------------------------------------------ modal do Pix

    function getPixModal() {
        if (pixModal) return pixModal;

        const node = byId('pixModal');
        const ui = window.StorefrontUI;
        if (!node || !ui || typeof ui.Modal !== 'function') return null;

        pixModal = ui.Modal.getOrCreateInstance(node);
        return pixModal;
    }

    function openPixModal() {
        const modal = getPixModal();
        if (!modal) return;

        // O checkout continua visível por baixo do fundo escuro, mas sem receber foco nem cliques.
        const area = byId('checkoutFormArea');
        if (area && !area.classList.contains('d-none')) area.setAttribute('inert', '');

        modal.show();
    }

    function bindModalEvents() {
        const node = byId('pixModal');
        if (!node) return;

        node.addEventListener('show.bs.modal', () => {
            panel.modalOpen = true;
            renderNotice();
        });

        // "Fechar" só ESCONDE o modal: o contador e o polling seguem e o aviso compacto assume.
        node.addEventListener('hidden.bs.modal', () => {
            panel.modalOpen = false;

            const area = byId('checkoutFormArea');
            if (area) {
                area.classList.add('d-none');
                area.removeAttribute('inert');
            }

            renderNotice();

            // O Bootstrap só devolve o foco em modais abertos pelo data-api: aqui devolvemos ao aviso.
            const target = byId('pixNoticeActions');
            const focusable = target ? target.querySelector('button, a[href]') : null;
            if (focusable) focusable.focus();
        });
    }

    function reopenPixModal() {
        const modal = getPixModal();
        if (modal) modal.show();
    }

    // Aviso compacto da página enquanto o modal está fechado.
    function renderNotice() {
        const wrap = byId('pixNotice');
        const box = byId('pixNoticeBox');
        const text = byId('pixNoticeText');
        const actions = byId('pixNoticeActions');
        if (!wrap || !box || !text || !actions) return;

        const show = panel.modal && !panel.modalOpen && panelState !== null;
        wrap.classList.toggle('d-none', !show);
        if (!show) return;

        actions.replaceChildren();

        let variant = 'info';
        let message = 'Pix aguardando pagamento';
        const act = [];
        const timer = panelState === 'awaiting' && !!panel.expiresAt && !panel.verifying;

        if (panelState === 'awaiting') {
            if (panel.verifying) message = 'Verificando o pagamento…';
            const reopen = el('button', 'btn btn-sm btn-primary', 'Reabrir QR Code');
            reopen.type = 'button';
            reopen.addEventListener('click', reopenPixModal);
            act.push(reopen);
        } else if (panelState === 'paid') {
            variant = 'success';
            message = 'Pagamento confirmado! ' + orderLabel() + ' recebido.';
            act.push(backToStoreLink(false, true));
        } else if (panelState === 'expired') {
            variant = 'warning';
            message = 'O tempo para pagar o Pix acabou. O pedido foi cancelado.';
            act.push(retryButton(true));
        } else if (panelState === 'cancelled') {
            variant = 'warning';
            message = 'O Pix não foi concluído e o pedido foi cancelado.';
            act.push(retryButton(true));
        } else if (panelState === 'review') {
            variant = 'warning';
            message = 'Pagamento recebido: o pedido precisa de conferência da loja.';
            act.push(backToStoreLink(false, true));
        } else {
            message = 'Ainda verificando o pagamento do Pix.';
            const reopen = el('button', 'btn btn-sm btn-primary', 'Ver detalhes');
            reopen.type = 'button';
            reopen.addEventListener('click', reopenPixModal);
            act.push(reopen);
        }

        box.className = 'alert alert-' + variant + ' d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0';
        text.textContent = message;
        toggle('pixNoticeTimerWrap', timer);
        act.forEach((node) => actions.appendChild(node));
    }

    // ------------------------------------------------------------------ contador

    function applyTiming(source) {
        if (!source || typeof source !== 'object') return;

        if (typeof source.expires_at === 'string' && source.expires_at !== '') panel.expiresAt = source.expires_at;

        if (typeof source.server_now === 'string') {
            const offset = clockOffsetMs(source.server_now, Date.now());
            if (offset !== null) panel.offsetMs = offset;
        }
    }

    function paintTimer(text) {
        document.querySelectorAll('[data-pix-timer]').forEach((node) => {
            node.textContent = text;
        });
    }

    // Anúncio para leitor de tela só nos marcos (o contador visível não é uma região viva).
    function announce(seconds) {
        const live = byId('pixModalAnnounce');
        if (!live) return;

        if (seconds > 0 && seconds % 60 === 0 && seconds < PIX_WINDOW_SECONDS) {
            const m = seconds / 60;
            live.textContent = 'Restam ' + m + (m === 1 ? ' minuto' : ' minutos') + ' para pagar o Pix.';
        } else if (seconds === 30 || seconds === 10) {
            live.textContent = 'Restam ' + seconds + ' segundos para pagar o Pix.';
        }
    }

    function stopCountdown() {
        if (countdown.timer) clearInterval(countdown.timer);
        countdown.timer = null;
    }

    function startCountdown() {
        stopCountdown();
        countdown.zero = false;
        if (!panel.isPix || !panel.expiresAt) return;

        countdown.timer = setInterval(tickCountdown, 500);
        tickCountdown();
    }

    function tickCountdown() {
        if (!panel.expiresAt) return;

        const ms = remainingMs(panel.expiresAt, panel.offsetMs, Date.now());
        if (ms === null) return;

        const seconds = Math.ceil(ms / 1000);
        paintTimer(formatCountdown(seconds));
        announce(seconds);

        if (ms <= 0 && !countdown.zero) {
            countdown.zero = true;
            stopCountdown();
            onCountdownZero();
        }
    }

    // 00:00: NÃO declara "expirado" (o Pix pode ter sido pago no último segundo, e o servidor ainda dá
    // uma pequena tolerância): mostra "Verificando o pagamento…" e consulta o status JÁ. Só o
    // servidor (`cancelled`/`paid`) decide o que a tela mostra depois.
    function onCountdownZero() {
        panel.verifying = true;
        if (panelState === 'awaiting') renderPanel('awaiting');

        if (poll.running) {
            poll.attempt = 0;
            schedule(0);
        } else {
            startPolling(0);
        }
    }

    // ------------------------------------------------------------------ tela de resultado

    function backToStoreLink(primary, small) {
        const a = el('a', 'btn ' + (small ? 'btn-sm ' : '') + (primary ? 'btn-primary' : 'btn-outline-secondary'), 'Voltar à loja');
        a.href = HOME_URL;
        return a;
    }

    function retryButton(small) {
        const retry = el('button', 'btn ' + (small ? 'btn-sm ' : '') + 'btn-primary', 'Tentar novamente');
        retry.type = 'button';
        retry.addEventListener('click', () => {
            retry.disabled = true;
            if (window.Livewire && window.Livewire.dispatch) window.Livewire.dispatch('checkout-retry');
        });
        return retry;
    }

    function orderLabel() {
        return panel.orderNumber !== null && panel.orderNumber !== undefined ? 'Pedido #' + String(panel.orderNumber) : 'Pedido';
    }

    function renderPanel(state) {
        panelState = state;

        const inModal = panel.modal;
        const box = byId(inModal ? 'pixModalBody' : 'mpResultBody');
        if (!box) return;
        box.replaceChildren();

        const actions = [];   // botões/links do fim: rodapé do modal; no painel da página, depois do texto
        let title = '';

        const td = panel.td || {};
        const qr = typeof td.qr_code === 'string' ? td.qr_code : '';
        const qrBase64 = safeQrBase64(td.qr_code_base64);
        const safeTicket = safeHttpsUrl(td.ticket_url);

        if (state === 'awaiting') {
            const hasPix = qr !== '' || qrBase64 !== '';
            // Pix sem QR ainda: a order do MP é assíncrona; o polling traz o QR assim que existir.
            const generatingPix = !hasPix && panel.isPix;

            title = hasPix ? 'Pague com Pix' : (generatingPix ? 'Gerando o seu Pix…' : 'Pagamento em análise');
            if (!inModal) box.appendChild(el('h2', 'h5 mb-0', title));

            const amount = formatBrl(panel.amount);
            box.appendChild(el('div', 'text-muted', amount !== '' ? orderLabel() + ' · ' + amount : orderLabel()));

            if (panel.isPix && panel.expiresAt) {
                const clock = el('div', 'text-center');
                clock.appendChild(el('div', 'text-muted small', 'Tempo restante para pagar'));
                const timer = el('div', 'fs-2 fw-semibold', '--:--');
                timer.setAttribute('data-pix-timer', '');
                timer.setAttribute('role', 'timer');
                timer.setAttribute('aria-live', 'off');
                clock.appendChild(timer);
                box.appendChild(clock);
            }

            if (hasPix) {
                box.appendChild(el('p', 'mb-0', 'Abra o app do seu banco, escolha pagar com Pix e escaneie o QR Code ou cole o código copiado.'));
                box.appendChild(el('p', 'mb-0 text-muted small', 'Este Pix expira em ' + windowLabel() + '. Depois disso o pedido é cancelado e você pode tentar novamente.'));
            } else if (generatingPix) {
                box.appendChild(el('p', 'mb-0', 'Estamos gerando o QR Code do seu Pix. Ele aparece aqui em instantes, sem precisar recarregar a página.'));
            }

            if (qrBase64) {
                const img = document.createElement('img');
                img.src = 'data:image/png;base64,' + qrBase64;
                img.alt = 'QR Code PIX';
                img.width = 240;
                img.height = 240;
                img.className = 'd-block mx-auto img-fluid rounded border bg-white p-2';
                box.appendChild(img);
            }

            if (qr) {
                const wrapper = el('div', 'd-grid gap-2');
                wrapper.appendChild(el('div', 'fw-semibold', 'Pix copia e cola'));

                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control';
                input.readOnly = true;
                input.setAttribute('aria-label', 'Código Pix copia e cola');
                input.value = qr;
                wrapper.appendChild(input);

                const btn = el('button', 'btn btn-primary', 'Copiar código');
                btn.type = 'button';
                btn.addEventListener('click', async () => {
                    try {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            await navigator.clipboard.writeText(qr);
                        } else {
                            input.focus();
                            input.select();
                            document.execCommand('copy');
                        }

                        btn.textContent = 'Código copiado!';
                    } catch (e) {
                        input.focus();
                        input.select();
                        btn.textContent = 'Selecione e copie';
                    }
                    setTimeout(() => (btn.textContent = 'Copiar código'), 2000);
                });
                wrapper.appendChild(btn);

                box.appendChild(wrapper);
            }

            if (safeTicket) {
                const a = el('a', 'link-primary', 'Abrir link de pagamento');
                a.href = safeTicket;
                a.target = '_blank';
                a.rel = 'noopener';
                box.appendChild(a);
            }

            const waiting = el('div', 'd-flex align-items-center gap-2 text-muted');
            waiting.setAttribute('role', 'status');
            const spinner = el('span', 'spinner-border spinner-border-sm');
            spinner.setAttribute('aria-hidden', 'true');
            waiting.appendChild(spinner);
            waiting.appendChild(el('span', '', panel.verifying
                ? 'Verificando o pagamento…'
                : (generatingPix
                    ? 'Gerando o seu Pix. Esta tela atualiza sozinha.'
                    : 'Aguardando a confirmação do pagamento. Esta tela atualiza sozinha.')));
            box.appendChild(waiting);
        } else {
            if (inModal) box.appendChild(el('div', 'text-muted', orderLabel()));

            if (state === 'paid') {
                title = 'Pagamento confirmado!';
                const alert = el('div', 'alert alert-success mb-0', 'Pagamento confirmado! ' + orderLabel() + ' recebido. Obrigado pela compra.');
                alert.setAttribute('role', 'status');
                box.appendChild(alert);
                actions.push(backToStoreLink(inModal));
            } else if (state === 'review') {
                title = 'Pedido em conferência';
                box.appendChild(el('div', 'alert alert-warning mb-0', 'Recebemos o seu pagamento, mas o ' + orderLabel() + ' precisa de uma conferência da loja. Entre em contato com a loja informando o número do pedido. Não pague novamente.'));
                actions.push(backToStoreLink(false));
            } else if (state === 'expired') {
                title = 'Pix expirado';
                box.appendChild(el('div', 'alert alert-warning mb-0', 'O tempo para pagar o Pix acabou. O pedido foi cancelado. Tente novamente.'));
                actions.push(retryButton(false), backToStoreLink(false));
            } else if (state === 'cancelled') {
                title = 'Pagamento não concluído';
                box.appendChild(el('div', 'alert alert-warning mb-0', panel.isPix
                    ? 'O Pix não foi concluído (foi recusado ou cancelado) e o ' + orderLabel() + ' foi cancelado. Você pode tentar novamente.'
                    : 'O pagamento não foi concluído (expirou ou foi recusado) e o ' + orderLabel() + ' foi cancelado. Você pode tentar novamente.'));
                actions.push(retryButton(false), backToStoreLink(false));
            } else if (state === 'timeout') {
                title = 'Ainda verificando';
                box.appendChild(el('div', 'alert alert-info mb-0', 'Ainda não recebemos a confirmação do ' + orderLabel() + '. Se você já pagou, ele será confirmado assim que o pagamento for compensado.'));
                const again = el('button', 'btn btn-primary', 'Continuar verificando');
                again.type = 'button';
                again.addEventListener('click', () => {
                    renderPanel('awaiting');
                    startPolling(0);
                });
                actions.push(again, backToStoreLink(false));
            } else {
                title = 'Não foi possível acompanhar';
                box.appendChild(el('div', 'alert alert-warning mb-0', 'Não foi possível acompanhar o pagamento neste navegador. Se você já pagou, a loja confirmará o ' + orderLabel() + '.'));
                actions.push(backToStoreLink(false));
            }
        }

        if (inModal) {
            const heading = byId('pixModalTitle');
            if (heading) heading.textContent = title;

            const footer = byId('pixModalFooter');
            if (footer) {
                footer.replaceChildren(...actions);
                footer.classList.toggle('d-none', actions.length === 0);
            }
        } else {
            actions.forEach((node) => box.appendChild(node));
        }

        renderNotice();
        tickCountdown();

        // Foco no resultado: no painel sempre; no modal só ao decidir (o foco já está preso nele).
        if (!inModal || state !== 'awaiting') box.focus({ preventScroll: true });
    }

    // ------------------------------------------------------------------ polling

    function stopPolling() {
        if (poll.timer) clearTimeout(poll.timer);
        poll.timer = null;
        poll.running = false;
    }

    function nextDelay() {
        return panel.isPix
            ? Math.min(PIX_POLL_BASE_MS * Math.pow(1.1, poll.attempt), PIX_POLL_MAX_DELAY_MS)
            : Math.min(POLL_BASE_MS * Math.pow(1.15, poll.attempt), POLL_MAX_DELAY_MS);
    }

    // Pix: até a janela + folga, medidas pelo prazo do servidor (e nunca menos que 1 min: página
    // recarregada já vencida, "Continuar verificando").
    function pixPollBudgetMs() {
        const left = panel.expiresAt ? remainingMs(panel.expiresAt, panel.offsetMs, Date.now()) : null;
        const base = left !== null ? left : PIX_WINDOW_SECONDS * 1000;

        return Math.max(base + PIX_POLL_GRACE_MS, PIX_RECHECK_MS);
    }

    function startPolling(firstDelay) {
        stopPolling();
        poll.running = true;
        poll.attempt = 0;
        poll.deadline = Date.now() + (panel.isPix ? pixPollBudgetMs() : POLL_LIMIT_MS);
        schedule(firstDelay);
    }

    function schedule(delay) {
        if (!poll.running) return;
        if (poll.timer) clearTimeout(poll.timer);
        poll.timer = setTimeout(tick, delay);
    }

    async function tick() {
        poll.timer = null;
        if (!poll.running) return;

        // Uma consulta de cada vez: o "zero do contador" e a volta à aba pedem consulta imediata.
        if (poll.inflight) {
            poll.timer = setTimeout(tick, 250);
            return;
        }

        if (Date.now() > poll.deadline) {
            stopPolling();
            renderPanel('timeout');
            return;
        }

        let delay = nextDelay();
        poll.attempt++;
        poll.inflight = true;

        try {
            const response = await fetch(STATUS_URL, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
            });

            if (!poll.running) return;

            if (response.status === 429) {
                const retryAfter = Number(response.headers.get('Retry-After'));
                delay = Math.max(delay * 2, Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter * 1000 : 15000);
            } else if (response.status === 404 || response.status === 403 || response.status === 419) {
                stopPolling();
                stopCountdown();
                renderPanel('unavailable');
                return;
            } else if (response.ok) {
                const json = await response.json();
                const status = json && typeof json.status === 'string' ? json.status : 'pending';

                // Cada resposta re-sincroniza o relógio com o do servidor (`server_now`).
                const hadTiming = !!panel.expiresAt;
                applyTiming(json);

                // O QR do Pix pode chegar depois da cobrança (order assíncrona): mostra assim que vier.
                // Só os três campos conhecidos; renderPanel os valida de novo (safeQrBase64/safeHttpsUrl).
                if (status === 'pending' && json && json.pix && typeof json.pix === 'object') {
                    const has = (td) => !!td && ((typeof td.qr_code === 'string' && td.qr_code !== '') || safeQrBase64(td.qr_code_base64) !== '');
                    const known = has(panel.td);
                    const incoming = has(json.pix);

                    if (!known && incoming) {
                        panel.td = {
                            qr_code: json.pix.qr_code,
                            qr_code_base64: json.pix.qr_code_base64,
                            ticket_url: json.pix.ticket_url,
                        };
                        renderPanel('awaiting');
                    }
                }

                if (status === 'pending' && !hadTiming && panel.expiresAt && panel.isPix) {
                    renderPanel('awaiting');
                    startCountdown();
                }

                if (status === 'paid' || status === 'review' || status === 'cancelled') {
                    stopPolling();
                    stopCountdown();
                    // `reason: expired` = o servidor encerrou o Pix por vencimento da janela.
                    renderPanel(status === 'cancelled' && panel.isPix && json.reason === 'expired' ? 'expired' : status);
                    return;
                }
            }
        } catch (e) {
            delay = Math.max(delay * 2, 8000); // rede instável: espera mais, não desiste
        } finally {
            poll.inflight = false;
        }

        schedule(delay);
    }

    // Voltou do app do banco: confere já, sem esperar o próximo ciclo.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        tickCountdown();
        if (poll.running) schedule(0);
    });

    // ------------------------------------------------------------------ ligação com o Livewire

    document.addEventListener('livewire:init', () => {
        Livewire.on('checkout-mp-init', (payload) => handleInit(unwrap(payload)));
        Livewire.on('checkout-mp-reset', () => handleReset());
        Livewire.on('checkout-mp-pay', (detail) => pay(unwrap(detail)));
    });

    document.addEventListener('DOMContentLoaded', () => {
        bindModalEvents();

        if (AWAITING) {
            panel = {
                orderNumber: AWAITING.order_number,
                amount: AWAITING.amount,
                td: AWAITING.transaction_data || {},
                isPix: !!AWAITING.is_pix,
                modal: false,
                modalOpen: false,
                expiresAt: null,
                offsetMs: 0,
                verifying: false,
            };
            panel.modal = panel.isPix && getPixModal() !== null;
            // Prazo gravado no servidor: o contador não "volta" ao recarregar a página.
            applyTiming(AWAITING);
            showResult();
            renderPanel('awaiting');
            startCountdown();
            startPolling(0);
        } else if (INITIAL_PAYLOAD) {
            handleInit(INITIAL_PAYLOAD);
        }
    });
})();
</script>
@endpush
@endif
</div>
