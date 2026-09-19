<?php

namespace App\Http\Controllers;

use App\Gateways\MercadoPagoGateway;
use App\Models\Tenant;
use App\Services\OrderService;
use App\Support\EncryptionHelper;
use Illuminate\Http\Request;

class TenantPaymentController extends Controller
{
    public function __construct(
        protected MercadoPagoGateway $gateway,
        protected OrderService $orderService,
    ) {}

    public function processPayment(Request $request, Tenant $tenant)
    {
        $payload = $request->json()->all();

        $mpMethod = data_get($payload, 'mp_method');
        if (!is_string($mpMethod) || $mpMethod === '') {
            return response()->json(['message' => 'Método Mercado Pago inválido.'], 422);
        }

        $enabledMethods = collect((array) data_get($tenant->settings, 'payment.methods', []))
            ->filter(fn ($m) => is_array($m) && ($m['enabled'] ?? false))
            ->pluck('id')
            ->values()
            ->all();

        if (!in_array($mpMethod, $enabledMethods, true)) {
            return response()->json(['message' => 'Método Mercado Pago não habilitado para este tenant.'], 422);
        }

        $mpSettings = (array) data_get($tenant->settings, 'payment.mercadopago', []);
        $publicKey = data_get($mpSettings, 'public_key');
        $accessToken = EncryptionHelper::decryptIfNeeded(data_get($mpSettings, 'access_token'));

        if (!$publicKey || !$accessToken) {
            return response()->json(['message' => 'Credenciais do Mercado Pago não configuradas.'], 422);
        }

        $formData = (array) data_get($payload, 'formData', $payload);

        $customer = (array) data_get($payload, 'customer', []);
        $customerName = trim((string) ($customer['name'] ?? ''));
        $customerPhone = trim((string) ($customer['phone'] ?? ''));
        $customerEmail = trim((string) ($customer['email'] ?? ''));

        if ($customerName === '' || $customerEmail === '') {
            return response()->json(['message' => 'Nome e e-mail são obrigatórios para pagamento via Mercado Pago.'], 422);
        }

        $cartKey = 'cart.' . $tenant->id;
        $cart = $request->session()->get($cartKey, []);

        if (!is_array($cart) || count($cart) === 0) {
            return response()->json(['message' => 'Carrinho vazio.'], 422);
        }

        $lines = $this->orderService->resolveCartLines($tenant, $cart);
        $subtotalCents = $this->orderService->calculateSubtotal($lines);

        if ($subtotalCents <= 0) {
            return response()->json(['message' => 'Total inválido.'], 422);
        }

        $order = $this->orderService->createFromCart(
            $tenant,
            [
                'name' => $customerName,
                'email' => $customerEmail,
                'phone' => $customerPhone,
            ],
            $cart,
            $mpMethod,
            ['provider' => 'mercadopago']
        );

        $result = $this->gateway->createPayment($tenant, $order, [
            'formData' => $formData,
            'customer_email' => $customerEmail,
        ]);

        if (!$result->success) {
            $order->meta = array_merge($order->meta ?? [], [
                'mercadopago' => [
                    'error' => $result->meta['error'] ?? $result->errorMessage,
                    'status_code' => $result->errorCode,
                ],
            ]);
            $order->save();

            return response()->json([
                'message' => $result->errorMessage ?? 'Falha ao processar pagamento.',
                'error' => $result->meta['error'] ?? null,
            ], 422);
        }

        $order->meta = array_merge($order->meta ?? [], [
            'mercadopago' => $result->meta,
        ]);

        if ($result->isApproved()) {
            $order->payment_status = 'paid';
        }

        $order->save();

        $request->session()->forget($cartKey);

        return response()->json([
            'ok' => true,
            'order_id' => $order->id,
            'order_number' => $order->number,
            'status' => $result->status,
            'status_detail' => $result->statusDetail,
            'point_of_interaction' => data_get($result->meta, 'point_of_interaction'),
        ]);
    }
}
