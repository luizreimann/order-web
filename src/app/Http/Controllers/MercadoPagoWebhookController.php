<?php

namespace App\Http\Controllers;

use App\Gateways\MercadoPagoGateway;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Http\Request;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        protected MercadoPagoGateway $gateway,
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->all();
        $headers = $request->headers->all();

        $flatHeaders = [];
        foreach ($headers as $key => $values) {
            $flatHeaders[$key] = is_array($values) ? ($values[0] ?? '') : $values;
        }

        $dataId = (string) ($payload['data']['id'] ?? $payload['id'] ?? '');

        if ($dataId !== '') {
            // CROSS-TENANT DELIBERADO: a rota do webhook fica fora do prefixo
            // `/{tenant:slug}`, então não há tenant no contexto e o pedido só
            // pode ser localizado pelo id do pagamento no gateway. Opt-out
            // explícito do global scope de tenant (E1.1).
            $order = Order::withoutTenantScope()
                ->where('meta->mercadopago->payment_id', $dataId)
                ->first();

            if ($order) {
                $tenant = Tenant::query()->where('id', $order->tenant_id)->first();

                if ($tenant) {
                    $secretKey = $this->gateway->getWebhookSecret($tenant);

                    if (! $this->gateway->validateWebhookSignature($dataId, $flatHeaders, $secretKey)) {
                        return response()->json(['error' => 'Invalid signature'], 401);
                    }
                }
            }
        }

        $result = $this->gateway->handleWebhook($payload, $flatHeaders);

        return response()->json(['ok' => $result->processed]);
    }
}
