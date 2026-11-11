<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentResult;
use App\Enums\GatewayPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\BelowMinimumOrderException;
use App\Exceptions\EmptyCartException;
use App\Exceptions\FulfillmentException;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\PixWindow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquestra pedido + pagamento online de forma consistente (E3.4 / ARC-007).
 *
 * Linha do tempo (nenhuma transação de banco fica aberta durante a chamada HTTP):
 *
 *  1. {@see self::begin()} — UMA transação curta: baixa de estoque + pedido + tentativa de
 *     `Payment` (`created`, valor do PEDIDO, `idempotency_key` persistida). Um duplo submit
 *     encontra a tentativa existente e não cria nada (nem cobra de novo).
 *  2. {@see self::charge()} — chamada externa ao gateway, fora de transação, com a chave
 *     persistida; depois, uma transação curta consolida o resultado.
 *
 * Falhas:
 *  - recusa, timeout, exceção: Payment `failed`/`rejected`, pedido `cancelled` e estoque
 *    devolvido UMA vez via {@see OrderService::updateOrder()};
 *  - cobrança aceita mas gravação falhou: incidente `critical` estruturado; pedido e estoque
 *    ficam como estão e o webhook concilia ({@see PaymentWebhookProcessor}, que adota a
 *    tentativa pelo `external_reference` da order, exposto como `metadata.order_id` no resultado).
 */
class PaymentService
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * Identifica "este checkout": mesmo tenant, mesma intenção de compra (nonce da sessão) e
     * mesmas linhas. O método e os dados do cliente ficam de fora de propósito: trocar de
     * PIX para cartão no meio do envio continua sendo o MESMO checkout.
     *
     * A escolha de recebimento (retirada/entrega, bairro, endereço) ENTRA na chave: outro tipo,
     * bairro ou endereço é outro pedido (outro total e outro destino); reaproveitar o pedido
     * anterior entregaria no endereço errado.
     *
     * @param  array<int, array<string, mixed>>  $cart
     * @param  array<string, mixed>  $fulfillment
     */
    public function checkoutKey(Tenant $tenant, string $nonce, array $cart, array $fulfillment = []): string
    {
        $lines = collect($cart)
            ->map(fn ($line) => [(string) ($line['product_id'] ?? ''), (int) ($line['qty'] ?? 0)])
            ->sortBy(fn (array $line) => $line[0])
            ->values()
            ->all();

        // 40 hex = 160 bits: sobra para unicidade e, com o sufixo `:n`, cabe folgado no limite
        // de tamanho de X-Idempotency-Key dos gateways (a chave é enviada como está).
        $parts = [$tenant->id, $nonce, $lines];

        // Sem escolha de recebimento a chave é a de sempre (retirada padrão).
        if ($fulfillment !== []) {
            $address = (array) ($fulfillment['address'] ?? []);
            ksort($address);
            $parts[] = [(string) ($fulfillment['type'] ?? ''), (string) ($fulfillment['region_id'] ?? ''), $address];
        }

        return substr(hash('sha256', (string) json_encode($parts)), 0, 40);
    }

    /**
     * @param  array<string, mixed>  $customerData
     * @param  array<int, array<string, mixed>>  $cart
     * @param  array<string, mixed>  $fulfillment  tipo, bairro e endereço (ver `OrderService::createFromCart`)
     *
     * @throws InsufficientStockException nada foi gravado
     * @throws EmptyCartException nada foi gravado
     * @throws BelowMinimumOrderException nada foi gravado
     * @throws FulfillmentException nada foi gravado
     */
    public function begin(
        Tenant $tenant,
        PaymentGatewayInterface $gateway,
        PaymentMethod $method,
        array $customerData,
        array $cart,
        string $checkoutKey,
        array $fulfillment = [],
    ): PaymentAttempt {
        $latest = $this->latestAttempt($tenant, $gateway, $checkoutKey);

        if ($latest !== null && ! $latest->isFailed()) {
            return PaymentAttempt::duplicate($this->orderOf($latest), $latest);
        }

        $sequence = $latest === null ? 1 : $this->sequenceOf($latest, $checkoutKey) + 1;
        $idempotencyKey = $sequence === 1 ? $checkoutKey : $checkoutKey.':'.$sequence;

        try {
            return DB::transaction(function () use ($tenant, $gateway, $method, $customerData, $cart, $idempotencyKey, $fulfillment) {
                $order = $this->orders->createFromCart(
                    $tenant,
                    $customerData,
                    $cart,
                    $method->value,
                    ['provider' => $gateway->key()],
                    $fulfillment,
                );

                $payment = Payment::create([
                    'tenant_id' => $tenant->id,
                    'order_id' => $order->id,
                    'gateway' => $gateway->key(),
                    // SEMPRE o total do pedido calculado no servidor, nunca algo do cliente.
                    'amount_cents' => $order->total_cents,
                    'status' => GatewayPaymentStatus::CREATED->value,
                    'idempotency_key' => $idempotencyKey,
                    'meta' => [],
                ]);

                return PaymentAttempt::fresh($order, $payment);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Corrida: outro processo registrou esta tentativa primeiro. A transação inteira
            // (inclusive a baixa de estoque deste processo) já foi revertida.
            $winner = $this->latestAttempt($tenant, $gateway, $checkoutKey);

            if ($winner === null) {
                throw $e;
            }

            return PaymentAttempt::duplicate($this->orderOf($winner), $winner);
        }
    }

    /**
     * Cobra e consolida. Nunca lança por falha do gateway.
     *
     * @param  array<string, mixed>  $data  repassado ao gateway (`formData`, `customer_email`, ...)
     */
    public function charge(Tenant $tenant, PaymentGatewayInterface $gateway, PaymentAttempt $attempt, array $data): ChargeOutcome
    {
        $order = $attempt->order;
        $payment = $attempt->payment;

        $data['idempotency_key'] = $payment->idempotency_key;

        try {
            $result = $gateway->createPayment($tenant, $order, $data);
        } catch (Throwable $e) {
            Log::error('payment.gateway_exception', [
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'gateway' => $gateway->key(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $result = PaymentResult::failure('Falha na comunicação com o gateway de pagamento.', null, [
                'exception' => $e::class,
                'reason' => PaymentFailure::REASON_UNREACHABLE,
            ]);
        }

        if (! $result->success || $result->isDeclined()) {
            $this->logChargeFailure($tenant, $gateway, $attempt, $result);

            return $this->compensate($tenant, $gateway, $attempt, $result);
        }

        try {
            $this->consolidate($tenant, $gateway, $order, $payment, $result);
        } catch (Throwable $e) {
            return $this->reportUnconsolidated($tenant, $gateway, $attempt, $result, $e);
        }

        return new ChargeOutcome(ChargeOutcome::SETTLED, $order->refresh(), $payment->refresh(), $result);
    }

    /**
     * Uma recusa/erro do gateway só aparecia em `payments.meta`; agora também no log, com o
     * que permite diagnosticar (status HTTP e `cause[]` do gateway) e nada que seja segredo
     * ou dado pessoal (ver {@see PaymentFailure}). `warning` para recusa/4xx; `error` para
     * 5xx/timeout.
     */
    private function logChargeFailure(Tenant $tenant, PaymentGatewayInterface $gateway, PaymentAttempt $attempt, PaymentResult $result): void
    {
        try {
            $failure = PaymentFailure::fromResult($result);

            Log::log($failure->logLevel(), 'payment.charge.failed', array_merge([
                'tenant_id' => $tenant->id,
                'order_id' => $attempt->order->id,
                'payment_id' => $attempt->payment->id,
                'gateway' => $gateway->key(),
            ], $failure->logContext()));
        } catch (Throwable) {
            // Observabilidade nunca impede a compensação (cancelar + devolver estoque).
        }
    }

    /**
     * Falha da cobrança: Payment marcado, pedido cancelado e estoque devolvido (uma vez).
     */
    private function compensate(Tenant $tenant, PaymentGatewayInterface $gateway, PaymentAttempt $attempt, PaymentResult $result): ChargeOutcome
    {
        $order = $attempt->order;
        $payment = $attempt->payment;
        $key = $gateway->key();

        try {
            $paidMeanwhile = DB::transaction(function () use ($tenant, $order, $payment, $result, $key) {
                $lockedOrder = Order::query()->forTenant($tenant->id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $lockedPayment = Payment::query()->forTenant($tenant->id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                // Um webhook pode ter confirmado a cobrança enquanto esperávamos (timeout
                // ambíguo): nesse caso NÃO cancelamos um pedido pago.
                if ($lockedOrder->payment_status === PaymentStatus::PAID) {
                    return true;
                }

                $lockedPayment->status = $result->success ? (string) $result->status : GatewayPaymentStatus::FAILED->value;
                $lockedPayment->status_detail = $result->success ? $result->statusDetail : null;
                if ($result->success && $result->paymentId !== null && $lockedPayment->gateway_payment_id === null) {
                    $lockedPayment->gateway_payment_id = $result->paymentId;
                }
                $lockedPayment->meta = array_merge($lockedPayment->meta ?? [], array_filter([
                    'gateway_details' => $result->success ? data_get($result->meta, 'gateway_details') : null,
                    'payment_method_id' => $result->success ? data_get($result->meta, 'payment_method_id') : null,
                ], fn ($v) => $v !== null), [
                    'failure' => array_filter([
                        'message' => $result->errorMessage,
                        'status_code' => $result->errorCode,
                        'error' => $result->meta['error'] ?? null,
                        'exception' => $result->meta['exception'] ?? null,
                        'reason' => $result->meta['reason'] ?? null,
                    ], fn ($v) => $v !== null),
                ]);
                $lockedPayment->save();

                $lockedOrder->meta = array_merge($lockedOrder->meta ?? [], [
                    $key => array_filter([
                        'error' => $result->meta['error'] ?? $result->errorMessage,
                        'status_code' => $result->errorCode,
                        'status' => $result->success ? $result->status : null,
                    ], fn ($v) => $v !== null),
                ]);
                $lockedOrder->save();

                // Cancela e devolve o estoque uma única vez (idempotente).
                $this->orders->updateOrder($lockedOrder, OrderStatus::CANCELLED, PaymentStatus::UNPAID, $lockedOrder->payment_method);

                return false;
            });
        } catch (Throwable $e) {
            Log::critical('payment.incident.compensation_failed', [
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'gateway' => $key,
                'idempotency_key' => $payment->idempotency_key,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return new ChargeOutcome(ChargeOutcome::FAILED, $order, $payment, $result, 'compensation_failed');
        }

        if ($paidMeanwhile) {
            return new ChargeOutcome(ChargeOutcome::UNCONSOLIDATED, $order->refresh(), $payment->refresh(), $result);
        }

        return new ChargeOutcome(ChargeOutcome::FAILED, $order->refresh(), $payment->refresh(), $result, $result->errorMessage);
    }

    /**
     * Grava a resposta do gateway (transação curta, sem HTTP).
     */
    private function consolidate(Tenant $tenant, PaymentGatewayInterface $gateway, Order $order, Payment $payment, PaymentResult $result): void
    {
        DB::transaction(function () use ($tenant, $gateway, $order, $payment, $result) {
            $lockedOrder = Order::query()->forTenant($tenant->id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::query()->forTenant($tenant->id)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // O webhook pode ter chegado ANTES desta gravação e já adotado/avançado a
            // tentativa: só preenchemos o status se ela ainda está em `created`.
            if ($lockedPayment->status === GatewayPaymentStatus::CREATED->value) {
                $lockedPayment->status = (string) $result->status;
                $lockedPayment->status_detail = $result->statusDetail;
            }
            $lockedPayment->gateway_payment_id ??= $result->paymentId;

            // Pix aceito e ainda por pagar: a janela de pagamento (NOSSA regra, ver PixWindow) começa
            // AGORA e é gravada como instante absoluto. É a fonte da verdade do contador do front e
            // da expiração imposta pelo servidor.
            $window = [];
            if (PaymentMethod::tryFrom((string) $lockedOrder->payment_method)?->isOnlinePix() === true
                && ! $result->isApproved()
                && PixWindow::expiresAt($lockedPayment->meta) === null) {
                $window = [PixWindow::META_KEY => PixWindow::format(PixWindow::expiresAtFromNow())];
            }

            $lockedPayment->meta = array_merge($lockedPayment->meta ?? [], $window, array_filter([
                'payment_method_id' => data_get($result->meta, 'payment_method_id'),
                'payment_method_type' => data_get($result->meta, 'payment_method_type'),
                'transaction_id' => data_get($result->meta, 'transaction_id'),
                'point_of_interaction' => data_get($result->meta, 'point_of_interaction'),
                'gateway_details' => data_get($result->meta, 'gateway_details'),
            ], fn ($v) => $v !== null));
            $lockedPayment->save();

            $lockedOrder->meta = array_merge($lockedOrder->meta ?? [], [
                $gateway->key() => array_merge((array) data_get($lockedOrder->meta, $gateway->key(), []), $result->meta),
            ]);

            if ($result->isApproved()) {
                $lockedOrder->payment_status = PaymentStatus::PAID;
            }

            $lockedOrder->save();
        });
    }

    /**
     * O gateway cobrou, mas a gravação falhou: incidente estruturado + o mínimo para o
     * webhook conciliar. Não cancela nada: o cliente pagou.
     */
    private function reportUnconsolidated(Tenant $tenant, PaymentGatewayInterface $gateway, PaymentAttempt $attempt, PaymentResult $result, Throwable $e): ChargeOutcome
    {
        Log::critical('payment.incident.charge_not_consolidated', [
            'tenant_id' => $tenant->id,
            'order_id' => $attempt->order->id,
            'payment_id' => $attempt->payment->id,
            'gateway' => $gateway->key(),
            'gateway_payment_id' => $result->paymentId,
            'gateway_status' => $result->status,
            'idempotency_key' => $attempt->payment->idempotency_key,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        // Melhor esforço: guardar só o id do gateway (sem mexer em status) para o webhook
        // achar a tentativa por id; se até isso falhar, ele a acha pelo metadata.order_id.
        try {
            Payment::query()->forTenant($tenant->id)->whereKey($attempt->payment->id)
                ->whereNull('gateway_payment_id')
                ->update(['gateway_payment_id' => $result->paymentId]);
        } catch (Throwable) {
            // já registrado acima
        }

        return new ChargeOutcome(ChargeOutcome::UNCONSOLIDATED, $attempt->order, $attempt->payment, $result);
    }

    private function latestAttempt(Tenant $tenant, PaymentGatewayInterface $gateway, string $checkoutKey): ?Payment
    {
        return Payment::query()
            ->forTenant($tenant->id)
            ->where('gateway', $gateway->key())
            ->where(fn ($q) => $q->where('idempotency_key', $checkoutKey)->orWhere('idempotency_key', 'like', $checkoutKey.':%'))
            ->get()
            ->sortByDesc(fn (Payment $p) => $this->sequenceOf($p, $checkoutKey))
            ->first();
    }

    private function sequenceOf(Payment $payment, string $checkoutKey): int
    {
        $key = (string) $payment->idempotency_key;

        return $key === $checkoutKey ? 1 : (int) substr($key, strlen($checkoutKey) + 1);
    }

    private function orderOf(Payment $payment): Order
    {
        return Order::query()->forTenant($payment->tenant_id)->whereKey($payment->order_id)->firstOrFail();
    }
}
