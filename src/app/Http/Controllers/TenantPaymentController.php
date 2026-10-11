<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Gateways\PaymentGatewayManager;
use App\Models\Tenant;
use App\Services\CartService;
use App\Services\ChargeOutcome;
use App\Services\PaymentAttempt;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            return $this->replay($request, $tenant, $attempt);
        }

        $outcome = $this->payments->charge($tenant, $gateway, $attempt, [
            'formData' => $formData,
            'customer_email' => $customerEmail,
        ]);

        if ($outcome->failed()) {
            return response()->json([
                'message' => 'Não foi possível concluir o pagamento. O pedido foi cancelado; confira os dados e tente novamente.',
                'error' => $outcome->result->meta['error'] ?? null,
            ], 422);
        }

        $this->finishCheckout($request, $tenant);

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
            ], 202);
        }

        return response()->json([
            'ok' => true,
            'order_id' => $outcome->order->id,
            'order_number' => $outcome->order->number,
            'status' => $outcome->result->status,
            'status_detail' => $outcome->result->statusDetail,
            'point_of_interaction' => data_get($outcome->result->meta, 'point_of_interaction'),
        ]);
    }

    /**
     * Duplo submit: devolve o resultado da tentativa existente em vez de cobrar de novo.
     */
    private function replay(Request $request, Tenant $tenant, PaymentAttempt $attempt): JsonResponse
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

        $this->finishCheckout($request, $tenant);

        $reconciling = $payment->status === GatewayPaymentStatus::CREATED->value;

        return response()->json([
            'ok' => true,
            'reconciling' => $reconciling ?: null,
            'order_id' => $order->id,
            'order_number' => $order->number,
            'status' => $payment->status,
            'status_detail' => $payment->status_detail,
            'point_of_interaction' => data_get($payment->meta, 'point_of_interaction'),
        ], $reconciling ? 202 : 200);
    }

    /**
     * Cobrança aceita: o carrinho vira pedido e a próxima compra é OUTRO checkout.
     */
    private function finishCheckout(Request $request, Tenant $tenant): void
    {
        $this->cartService->clear($tenant);
        $request->session()->forget($this->nonceKey($tenant));
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
}
