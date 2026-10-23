<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\GatewayPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Gateways\PaymentGatewayManager;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\CartService;
use App\Services\ChargeOutcome;
use App\Services\PaymentAttempt;
use App\Services\PaymentFailure;
use App\Services\PaymentReconciler;
use App\Services\PaymentService;
use App\Support\DeviceSessionId;
use App\Support\PixWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Checkout com pagamento online (qualquer gateway de {@see PaymentGatewayManager}).
 *
 * O controller só conhece {@see PaymentGatewayInterface}; a consistência pedido+pagamento
 * (tentativa registrada antes da chamada externa, compensação em falha, idempotência de
 * duplo submit) mora em {@see PaymentService}.
 */
class TenantPaymentController extends Controller
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected PaymentService $payments,
        protected CartService $cartService,
        protected PaymentReconciler $reconciler,
    ) {}

    public function processPayment(Request $request, Tenant $tenant): JsonResponse
    {
        $payload = $request->json()->all();

        // `mp_method` é o nome histórico do campo enviado pelo front (Payment Brick).
        $methodId = data_get($payload, 'payment_method', data_get($payload, 'mp_method'));
        $method = is_string($methodId) ? PaymentMethod::tryFrom($methodId) : null;

        if ($method === null || $method->isManual()) {
            return response()->json(['message' => 'Método de pagamento online inválido.'], 422);
        }

        $enabledMethods = collect((array) data_get($tenant->settings, 'payment.methods', []))
            ->filter(fn ($m) => is_array($m) && ($m['enabled'] ?? false))
            ->pluck('id')
            ->values()
            ->all();

        if (! in_array($method->value, $enabledMethods, true)) {
            return response()->json(['message' => 'Método de pagamento não habilitado para este tenant.'], 422);
        }

        try {
            $gateway = $this->gateways->forMethod($method, $tenant);
        } catch (UnsupportedPaymentMethodException) {
            return response()->json(['message' => 'Método de pagamento indisponível.'], 422);
        }

        if (! $gateway->isConfigured($tenant)) {
            return response()->json(['message' => 'Credenciais do gateway de pagamento não configuradas.'], 422);
        }

        $formData = (array) data_get($payload, 'formData', $payload);

        $customer = (array) data_get($payload, 'customer', []);
        $customerName = trim((string) ($customer['name'] ?? ''));
        $customerPhone = trim((string) ($customer['phone'] ?? ''));
        $customerEmail = trim((string) ($customer['email'] ?? ''));

        if ($customerName === '' || $customerEmail === '') {
            return response()->json(['message' => 'Nome e e-mail são obrigatórios para pagamento online.'], 422);
        }

        // O e-mail do formulário do checkout é a ÚNICA fonte do `payer.email` (o cliente nunca
        // digita duas vezes) e não é inventado: se não for válido, não há cobrança.
        if (Validator::make(['email' => $customerEmail], ['email' => PaymentMethod::EMAIL_RULES])->fails()) {
            return response()->json([
                'message' => $method->emailRequirementMessage(),
                'errors' => ['customer_email' => [$method->emailRequirementMessage()]],
            ], 422);
        }

        // Carrinho, linhas e total vêm sempre do CartService (sessão + banco); nada do payload.
        $cart = $this->cartService->items($tenant);

        if (count($cart) === 0) {
            return response()->json(['message' => 'Carrinho vazio.'], 422);
        }

        $subtotalCents = $this->cartService->subtotalCents($this->cartService->resolveLines($tenant, $cart));

        if ($subtotalCents <= 0) {
            return response()->json(['message' => 'Total inválido.'], 422);
        }

        $checkoutKey = $this->payments->checkoutKey($tenant, $this->checkoutNonce($request, $tenant), $cart);

        try {
            $attempt = $this->payments->begin(
                $tenant,
                $gateway,
                $method,
                ['name' => $customerName, 'email' => $customerEmail, 'phone' => $customerPhone],
                $cart,
                $checkoutKey,
            );
        } catch (InsufficientStockException $e) {
            // Nada foi gravado (rollback total) e o gateway nem foi chamado: não há cobrança.
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'insufficient_stock',
                'product_id' => $e->productId,
                'available' => $e->available,
            ], 422);
        }

        if ($attempt->duplicate) {
            return $this->replay($request, $tenant, $attempt, $cart);
        }

        $outcome = $this->payments->charge($tenant, $gateway, $attempt, [
            'formData' => $formData,
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            // Device ID (antifraude) do `security.js` do MP: do navegador, então validado aqui;
            // inválido ou ausente é descartado em silêncio (nunca bloqueia o pagamento).
            'device_session_id' => DeviceSessionId::sanitize(data_get($payload, 'device_session_id')),
            // O gateway monta o corpo da cobrança por método (PIX direto, lista branca no cartão).
            'payment_method' => $method->value,
        ]);

        if ($outcome->failed()) {
            $failure = PaymentFailure::fromResult($outcome->result);

            return response()->json([
                'message' => $failure->userMessage(),
                // Falha de configuração/indisponibilidade: nada do corpo de erro do gateway
                // (URL, credencial, códigos internos) vai ao navegador.
                'error' => $failure->isServiceSide() ? 'payment_unavailable' : ($outcome->result->meta['error'] ?? null),
            ], 422);
        }

        $this->finishCheckout($request, $tenant, $outcome->order, $cart);

        if ($outcome->state === ChargeOutcome::UNCONSOLIDATED) {
            // O gateway aceitou a cobrança mas não conseguimos gravar o resultado: o cliente
            // pagou, então NÃO é erro; o incidente foi registrado e o webhook concilia.
            return response()->json([
                'ok' => true,
                'reconciling' => true,
                'order_id' => $outcome->order->id,
                'order_number' => $outcome->order->number,
                'status' => $outcome->result->status,
                'status_detail' => $outcome->result->statusDetail,
                'point_of_interaction' => data_get($outcome->result->meta, 'point_of_interaction'),
            ] + PixWindow::timing($outcome->payment->meta), 202);
        }

        return response()->json([
            'ok' => true,
            'order_id' => $outcome->order->id,
            'order_number' => $outcome->order->number,
            'status' => $outcome->result->status,
            'status_detail' => $outcome->result->statusDetail,
            'point_of_interaction' => data_get($outcome->result->meta, 'point_of_interaction'),
        ] + PixWindow::timing($outcome->payment->meta));
    }

    /**
     * Duplo submit: devolve o resultado da tentativa existente em vez de cobrar de novo.
     */
    private function replay(Request $request, Tenant $tenant, PaymentAttempt $attempt, array $cart): JsonResponse
    {
        $payment = $attempt->payment;
        $order = $attempt->order;

        if ($payment->status === GatewayPaymentStatus::CREATED->value && $payment->gateway_payment_id === null) {
            // A primeira requisição ainda espera o gateway.
            return response()->json([
                'message' => 'Seu pagamento já está sendo processado. Aguarde a confirmação.',
                'error' => 'payment_in_progress',
                'order_id' => $order->id,
            ], 409);
        }

        $this->finishCheckout($request, $tenant, $order, $cart);

        $reconciling = $payment->status === GatewayPaymentStatus::CREATED->value;

        return response()->json([
            'ok' => true,
            'reconciling' => $reconciling ?: null,
            'order_id' => $order->id,
            'order_number' => $order->number,
            'status' => $payment->status,
            'status_detail' => $payment->status_detail,
            'point_of_interaction' => data_get($payment->meta, 'point_of_interaction'),
        ] + PixWindow::timing($payment->meta), $reconciling ? 202 : 200);
    }

    /**
     * Estado do pedido recém-criado, para a tela de espera do PIX/cartão (polling).
     *
     * Só responde ao navegador que criou o pedido: o id fica na SESSÃO (por tenant) e é
     * revalidado contra o tenant do path. Sessão sem pedido, pedido de outro tenant ou
     * inexistente respondem todos o MESMO 404 (nada de oráculo). A resposta é só o estado.
     * Pedido pendente dispara a reconciliação com o gateway ({@see PaymentReconciler}).
     */
    public function status(Request $request, Tenant $tenant): JsonResponse
    {
        $noStore = ['Cache-Control' => 'no-store, private'];
        $orderId = $request->session()->get(self::orderKey($tenant));

        $order = is_string($orderId) && $orderId !== ''
            ? Order::query()->forTenant($tenant->id)->whereKey($orderId)->first()
            : null;

        if ($order === null) {
            return response()->json(['message' => 'not_found'], 404, $noStore);
        }

        // Sem webhook (APP_URL local, túnel fora do ar, notificação perdida) é ESTA consulta
        // que descobre o pagamento: pedido ainda pendente => pergunta ao gateway (no máximo
        // 1x a cada ~3 s por pagamento, só uma requisição entre as concorrentes) e aplica o
        // resultado pelo mesmo caminho idempotente do webhook. Nunca derruba o endpoint:
        // erro do gateway/rede/cache vira log e a resposta é o estado atual.
        if ($this->publicStatus($order) === 'pending') {
            $this->reconciler->reconcileOrder($tenant, $order);

            $order = Order::query()->forTenant($tenant->id)->whereKey($order->id)->first() ?? $order;
        }

        $status = $this->publicStatus($order);
        $body = ['status' => $status];
        $payment = Payment::query()->forTenant($tenant->id)->where('order_id', $order->id)->latest()->first();

        // Pix ainda sem QR no momento da cobrança (a order de Orders é assíncrona): assim que a
        // reconciliação o obtém, ele sai por aqui, só para o dono da sessão e só enquanto o
        // pedido está pendente. Só os três campos do QR; nada do corpo do gateway. Junto vão os dois
        // instantes do contador (prazo absoluto e hora do servidor), nada além disso.
        if ($status === 'pending') {
            $pix = $this->pixOf($payment);

            if ($pix !== []) {
                $body['pix'] = $pix;
            }

            if ($this->isPix($order)) {
                $body += PixWindow::timing($payment?->meta);
            }
        }

        // Pix cancelado depois que a janela de pagamento fechou: a tela mostra "o tempo acabou" em
        // vez da mensagem genérica de cancelamento.
        if ($status === 'cancelled' && $payment !== null && $this->expiredPix($order, $payment)) {
            $body['reason'] = 'expired';
        }

        return response()->json($body, 200, $noStore);
    }

    private function isPix(Order $order): bool
    {
        return PaymentMethod::tryFrom((string) $order->payment_method)?->isOnlinePix() === true;
    }

    private function expiredPix(Order $order, Payment $payment): bool
    {
        $expiresAt = PixWindow::expiresAt($payment->meta);

        return $this->isPix($order)
            && $expiresAt !== null
            && now()->greaterThan($expiresAt)
            && in_array($payment->status, [GatewayPaymentStatus::CANCELLED->value, GatewayPaymentStatus::REJECTED->value], true);
    }

    /**
     * @return array{qr_code?: string, qr_code_base64?: string, ticket_url?: string}
     */
    private function pixOf(?Payment $payment): array
    {
        $data = (array) data_get($payment?->meta, 'point_of_interaction.transaction_data', []);

        return array_filter(
            array_intersect_key($data, array_flip(['qr_code', 'qr_code_base64', 'ticket_url'])),
            fn ($v) => is_string($v) && $v !== ''
        );
    }

    /**
     * `paid` | `cancelled` | `review` | `pending`. `review`: o gateway recebeu o pagamento mas o
     * pedido já estava cancelado (incidente registrado para estorno): o cliente NÃO deve ser
     * mandado "tentar de novo" (pagaria duas vezes).
     */
    private function publicStatus(Order $order): string
    {
        $paid = $order->payment_status === PaymentStatus::PAID;
        $cancelled = $order->status === OrderStatus::CANCELLED;

        return match (true) {
            $paid && $cancelled => 'review',
            $paid => 'paid',
            $cancelled => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * Cobrança aceita: o carrinho vira pedido e a próxima compra é OUTRO checkout. O pedido
     * fica lembrado na sessão (polling e retomada após recarregar) e o carrinho é guardado
     * para "tentar novamente" caso o pagamento expire ou seja cancelado.
     *
     * @param  array<int, array<string, mixed>>  $cart
     */
    private function finishCheckout(Request $request, Tenant $tenant, Order $order, array $cart): void
    {
        $this->cartService->clear($tenant);
        $request->session()->forget($this->nonceKey($tenant));
        $request->session()->put(self::orderKey($tenant), $order->id);
        $request->session()->put(self::retryCartKey($tenant), $cart);
    }

    /**
     * "Intenção de compra" desta sessão: nasce quando o checkout abre (ou aqui, se não
     * abriu) e só é descartada quando a cobrança é aceita. Mantém o duplo clique no MESMO
     * checkout e uma compra futura idêntica em OUTRO.
     */
    private function checkoutNonce(Request $request, Tenant $tenant): string
    {
        $key = $this->nonceKey($tenant);
        $nonce = $request->session()->get($key);

        if (! is_string($nonce) || $nonce === '') {
            $nonce = (string) Str::uuid();
            $request->session()->put($key, $nonce);
        }

        return $nonce;
    }

    public static function nonceKey(Tenant|string $tenant): string
    {
        return 'checkout.nonce.'.($tenant instanceof Tenant ? $tenant->id : $tenant);
    }

    /** Pedido online recém-criado por ESTA sessão (id), por tenant. */
    public static function orderKey(Tenant|string $tenant): string
    {
        return 'checkout.order.'.($tenant instanceof Tenant ? $tenant->id : $tenant);
    }

    /** Carrinho de antes da cobrança, para refazer o pedido se o pagamento não se concluir. */
    public static function retryCartKey(Tenant|string $tenant): string
    {
        return 'checkout.retry_cart.'.($tenant instanceof Tenant ? $tenant->id : $tenant);
    }
}
