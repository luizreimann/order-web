<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLoginCode;
use App\Models\Tenant;
use App\Support\Phone;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Pede e confere o código de login no WhatsApp.
 *
 * Pedir não cria cliente nem sessão e não grava cookie, exceto quando nenhum
 * remetente pode enviar: aí {@see CustomerAuthService::login()} entra só com
 * o telefone e, se houver `owner_email`, avisa o responsável. O e-mail não
 * leva o código. Falha temporária não entra. 500 e timeout repetem uma vez,
 * com a mesma chave e os mesmos seis dígitos. O código em claro não vai para
 * o banco: fica o HMAC-SHA256 com a APP_KEY. O HTTP corre fora da transação.
 */
class CustomerLoginCodeService
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ZaptriaClient $zaptria,
        private readonly CustomerAuthService $auth,
    ) {}

    /**
     * Código enviado: `true`. Sem remetente: a sessão de `login()`, sem cookie.
     * Falha temporária ou telefone inválido: `false`.
     *
     * @return array{customer: Customer, session_token: string}|bool
     */
    public function requestCode(Tenant $tenant, string $phone): array|bool
    {
        $phone = $this->canonicalPhone($phone);

        if ($phone === null) {
            return false;
        }

        $code = DB::transaction(function () use ($tenant, $phone): CustomerLoginCode {
            $this->consumeOpen($tenant, $phone);

            $idempotencyKey = (string) Str::uuid();

            return CustomerLoginCode::query()->create([
                'tenant_id' => $tenant->id,
                'phone' => $phone,
                'code_hash' => $this->hashCode($this->digitsFor($idempotencyKey)),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'attempts' => 0,
                'idempotency_key' => $idempotencyKey,
            ]);
        });

        return $this->send($tenant, $code);
    }

    /**
     * @return array{customer: Customer, session_token: string}|bool
     */
    public function resend(Tenant $tenant, CustomerLoginCode $code): array|bool
    {
        $row = CustomerLoginCode::query()
            ->forTenant($tenant->id)
            ->whereKey($code->getKey())
            ->first();

        if ($row === null || $row->consumed_at !== null || $this->expired($row)) {
            return false;
        }

        return $this->send($tenant, $row);
    }

    /**
     * @return array{customer: Customer, session_token: string}|null
     */
    public function verifyCode(Tenant $tenant, string $phone, string $code): ?array
    {
        $phone = $this->canonicalPhone($phone);

        if ($phone === null) {
            return null;
        }

        return DB::transaction(function () use ($tenant, $phone, $code) {
            $row = $this->openCode($tenant, $phone, true);

            if ($row === null || $this->expired($row)) {
                return null;
            }

            if (! hash_equals((string) $row->code_hash, $this->hashCode(trim($code)))) {
                // O quinto erro consome a linha. Menos de cinco deixa o acerto seguir.
                $row->attempts = (int) $row->attempts + 1;

                if ($row->attempts >= self::MAX_ATTEMPTS) {
                    $row->consumed_at = now();
                }

                $row->save();

                return null;
            }

            $session = $this->auth->login($tenant, $phone);

            $row->consumed_at = now();
            $row->save();

            return $session;
        });
    }

    private function consumeOpen(Tenant $tenant, string $phone): void
    {
        CustomerLoginCode::query()
            ->forTenant($tenant->id)
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function openCode(Tenant $tenant, string $phone, bool $lock): ?CustomerLoginCode
    {
        $query = CustomerLoginCode::query()
            ->forTenant($tenant->id)
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->latest();

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return array{customer: Customer, session_token: string}|bool
     */
    private function send(Tenant $tenant, CustomerLoginCode $code): array|bool
    {
        $result = $this->attempt($tenant, $code);

        if ($this->retries($result)) {
            $result = $this->attempt($tenant, $code);
        }

        $response = $result['response'];

        if ($response !== null && $response->successful()) {
            return true;
        }

        if (! $result['failed'] && $this->withoutSender($response)) {
            return $this->enterWithoutCode($tenant, (string) $code->phone);
        }

        return false;
    }

    /**
     * @return array{response: Response|null, failed: bool}
     */
    private function attempt(Tenant $tenant, CustomerLoginCode $code): array
    {
        try {
            return [
                'response' => $this->zaptria->sendMessage((string) $tenant->id, [
                    'to' => (string) $code->phone,
                    'text' => $tenant->name.': seu código é '.$this->digitsFor((string) $code->idempotency_key),
                    'sender' => 'auto',
                    'purpose' => 'otp',
                ], (string) $code->idempotency_key),
                'failed' => false,
            ];
        } catch (HttpClientException) {
            return [
                'response' => null,
                'failed' => true,
            ];
        }
    }

    /**
     * 500 e timeout repetem uma vez. 429, 502 e `instance_busy` não.
     *
     * @param  array{response: Response|null, failed: bool}  $result
     */
    private function retries(array $result): bool
    {
        if ($result['failed']) {
            return true;
        }

        return $result['response'] !== null && $result['response']->status() === 500;
    }

    private function withoutSender(?Response $response): bool
    {
        if ($response === null) {
            return true;
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return true;
        }

        return $response->status() === 409 && $this->errorCode($response) === 'instance_not_connected';
    }

    private function errorCode(Response $response): ?string
    {
        $code = $response->json('error.code');

        return is_string($code) ? $code : null;
    }

    /**
     * @return array{customer: Customer, session_token: string}
     */
    private function enterWithoutCode(Tenant $tenant, string $phone): array
    {
        $session = $this->auth->login($tenant, $phone);

        $this->notifyOwner($tenant);

        return $session;
    }

    private function notifyOwner(Tenant $tenant): void
    {
        $owner = trim((string) config('services.zaptria.owner_email'));

        if ($owner === '') {
            return;
        }

        $notice = $tenant->name.': a entrada foi sem código porque nenhum WhatsApp pôde enviar.';

        Mail::to($owner)->send(new class($notice) extends Mailable
        {
            public function __construct(string $notice)
            {
                $this->subject($notice)->html(e($notice));
            }
        });
    }

    private function expired(CustomerLoginCode $code): bool
    {
        return $code->expires_at->isPast();
    }

    private function canonicalPhone(string $phone): ?string
    {
        if (! Phone::isValid($phone)) {
            return null;
        }

        $canonical = Phone::normalize($phone);

        return $canonical === '' ? null : $canonical;
    }

    /**
     * Os seis dígitos saem da chave de idempotência. Reenviar a mesma linha
     * repete o código sem gravar os dígitos.
     */
    private function digitsFor(string $idempotencyKey): string
    {
        $digest = hash_hmac('sha256', $idempotencyKey, $this->appKey());
        $number = (int) hexdec(substr($digest, 0, 8)) % 1000000;

        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->appKey());
    }

    private function appKey(): string
    {
        return (string) config('app.key');
    }
}
