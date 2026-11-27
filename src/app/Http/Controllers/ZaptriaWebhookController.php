<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /webhooks/zaptria`, antes do grupo da loja.
 *
 * A assinatura de saída é `sha256=` + HMAC de `{timestamp}.{corpo_cru}` (ponto).
 * A string com método e path das requests de entrada não vale aqui. 401 não grava
 * e não consome a entrega.
 */
class ZaptriaWebhookController extends Controller
{
    private const MAX_SKEW_SECONDS = 300;

    /** @var list<string> */
    private const STATUSES = [
        'inactive',
        'starting',
        'qr_ready',
        'connected',
        'disconnected',
        'error',
        'disconnecting',
    ];

    public function __invoke(Request $request): Response
    {
        if (! $this->signatureIsValid($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $delivery = trim((string) $request->header('X-Zaptria-Delivery'));

        // Sem id não há como ignorar o repetido. Não grava a chave vazia.
        if ($delivery === '') {
            return response()->noContent();
        }

        if (! Cache::add('zaptria:delivery:'.$delivery, true, now()->addDay())) {
            return response()->noContent();
        }

        $payload = json_decode($request->getContent(), true);

        if (is_array($payload) && ($payload['event'] ?? null) === 'instance.status_changed') {
            $this->applyStatusChanged($payload);
        }

        return response()->noContent();
    }

    private function signatureIsValid(Request $request): bool
    {
        $secret = trim((string) config('services.zaptria.secret'));
        $timestamp = (string) $request->header('X-Zaptria-Timestamp');
        $provided = (string) $request->header('X-Zaptria-Signature');

        if ($secret === '' || $provided === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function applyStatusChanged(array $payload): void
    {
        $status = $payload['status'] ?? null;

        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            return;
        }

        $externalId = data_get($payload, 'tenant.external_id');

        if (! is_string($externalId) || ! Str::isUuid($externalId)) {
            return;
        }

        $tenant = Tenant::query()->find($externalId);

        if ($tenant === null) {
            return;
        }

        $settings = $tenant->settings;

        if (! is_array($settings)) {
            $settings = [];
        }

        $whatsapp = data_get($settings, 'whatsapp', []);

        if (! is_array($whatsapp)) {
            $whatsapp = [];
        }

        $whatsapp['status'] = $status;
        // E.164 sem `+` no evento. O bloco guarda o nacional, ou null.
        $whatsapp['number'] = $this->numberFrom($payload['number'] ?? null);

        data_set($settings, 'whatsapp', $whatsapp);

        $tenant->settings = $settings;
        $tenant->save();
    }

    private function numberFrom(mixed $number): ?string
    {
        if (is_int($number)) {
            $number = (string) $number;
        }

        if (! is_string($number)) {
            return null;
        }

        return Phone::normalizeOrNull($number);
    }
}
