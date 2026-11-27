<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Tenant;
use App\Support\Phone;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * WhatsApp da loja. O aviso de status exige o interruptor e `sender=tenant`.
 * A mensagem da ficha sai daqui, `purpose=generic`, sem o interruptor.
 * Falha não desfaz o pedido. Não usa o número da plataforma.
 */
class WhatsAppNotifier
{
    public const MANUAL_SENT = 'sent';

    public const MANUAL_FAILED = 'failed';

    public const MANUAL_NOT_CONNECTED = 'not_connected';

    public const MANUAL_SKIPPED = 'skipped';

    public const MANUAL_BUSY = 'busy';

    /** Folga acima do timeout de 15s. O `release` no `finally` solta antes. */
    private const MANUAL_LOCK_SECONDS = 20;

    public function __construct(private ZaptriaClient $zaptria) {}

    public static function manualLockKey(string $orderId): string
    {
        return 'whatsapp.manual.'.$orderId;
    }

    public function notifyStatus(Order $order, OrderStatus $status): void
    {
        try {
            $this->send($order, $status);
        } catch (\Throwable) {
            // O status novo já está no banco. Falha de HTTP não volta o pedido.
        }
    }

    private function send(Order $order, OrderStatus $status): void
    {
        if (! in_array($status, [OrderStatus::CONFIRMED, OrderStatus::DONE, OrderStatus::CANCELLED], true)) {
            return;
        }

        $fresh = Order::query()->forTenant($order->tenant_id)->whereKey($order->id)->first();

        if ($fresh === null) {
            return;
        }

        $tenant = Tenant::query()->find($fresh->tenant_id);

        if ($tenant === null) {
            return;
        }

        $block = data_get($tenant->settings, 'whatsapp');

        if (! is_array($block)) {
            return;
        }

        if (($block['order_updates'] ?? false) !== true || ($block['status'] ?? null) !== 'connected') {
            return;
        }

        if (! Phone::isValid($fresh->customer_phone)) {
            return;
        }

        $text = $this->text($block, $status, $fresh, $tenant);

        if ($text === null) {
            return;
        }

        $response = $this->zaptria->sendMessage((string) $tenant->id, [
            'to' => Phone::normalize($fresh->customer_phone),
            'text' => $text,
            'sender' => 'tenant',
            'purpose' => 'order_update',
        ]);

        if ($this->notConnected($response)) {
            $this->markDisconnected($tenant);
        }
    }

    /**
     * Texto digitado na ficha. Não exige o interruptor e não marca a loja como desconectada.
     */
    public function sendManual(Order $order, string $text): string
    {
        $length = mb_strlen($text, 'UTF-8');

        if ($length < 1 || $length > 500) {
            return self::MANUAL_SKIPPED;
        }

        $fresh = Order::query()->forTenant($order->tenant_id)->whereKey($order->id)->first();

        if ($fresh === null || ! Phone::isValid($fresh->customer_phone)) {
            return self::MANUAL_SKIPPED;
        }

        $tenant = Tenant::query()->find($fresh->tenant_id);

        if ($tenant === null) {
            return self::MANUAL_SKIPPED;
        }

        if (data_get($tenant->settings, 'whatsapp.status') !== 'connected') {
            return self::MANUAL_NOT_CONNECTED;
        }

        // Lock não bloqueante, só enquanto o HTTP deste clique corre.
        $lock = Cache::lock(self::manualLockKey((string) $fresh->id), self::MANUAL_LOCK_SECONDS);

        if (! $lock->get()) {
            return self::MANUAL_BUSY;
        }

        try {
            $response = $this->zaptria->sendMessage((string) $tenant->id, [
                'to' => Phone::normalize($fresh->customer_phone),
                'text' => $text,
                'sender' => 'tenant',
                'purpose' => 'generic',
            ]);

            if ($response === null || ! $response->successful()) {
                return self::MANUAL_FAILED;
            }

            return self::MANUAL_SENT;
        } catch (\Throwable) {
            return self::MANUAL_FAILED;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function text(array $block, OrderStatus $status, Order $order, Tenant $tenant): ?string
    {
        $templates = $block['templates'] ?? null;

        if (! is_array($templates)) {
            return null;
        }

        $template = $templates[$status->value] ?? null;

        if (! is_string($template)) {
            return null;
        }

        $text = strtr($template, [
            '{cliente}' => (string) ($order->customer_name ?? ''),
            '{pedido}' => (string) $order->number,
            '{loja}' => (string) $tenant->name,
        ]);

        $length = mb_strlen($text, 'UTF-8');

        if ($length < 1 || $length > 500) {
            return null;
        }

        return $text;
    }

    private function notConnected(?Response $response): bool
    {
        if ($response === null || $response->status() !== 409) {
            return false;
        }

        $code = $response->json('error.code');

        return $code === 'instance_not_connected';
    }

    private function markDisconnected(Tenant $tenant): void
    {
        $fresh = Tenant::query()->find($tenant->id);

        if ($fresh === null) {
            return;
        }

        $settings = $fresh->settings;

        if (! is_array($settings)) {
            $settings = [];
        }

        $whatsapp = $settings['whatsapp'] ?? [];

        if (! is_array($whatsapp)) {
            $whatsapp = [];
        }

        $whatsapp['status'] = 'disconnected';
        data_set($settings, 'whatsapp', $whatsapp);

        $fresh->settings = $settings;
        $fresh->save();
    }
}
