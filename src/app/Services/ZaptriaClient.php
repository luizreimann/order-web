<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Único HTTP ao Zaptria. Sem key, secret ou base_url não abre request.
 * A credencial é da plataforma (`services.zaptria`) e não entra em `tenants.settings`.
 */
class ZaptriaClient
{
    private const TIMEOUT_SECONDS = 15;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    private string $baseUrl;

    private string $key;

    private string $secret;

    public function __construct(?string $baseUrl = null, ?string $key = null, ?string $secret = null)
    {
        $this->baseUrl = trim($baseUrl ?? (string) config('services.zaptria.base_url', ''));
        $this->key = trim($key ?? (string) config('services.zaptria.key', ''));
        $this->secret = trim($secret ?? (string) config('services.zaptria.secret', ''));
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): ?Response
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $method = strtoupper($method);
        $raw = $this->encodeBody($body);
        $timestamp = (string) time();
        $url = $this->url($path);
        $signedPath = $this->signedPath($url);

        $canonical = implode("\n", [$timestamp, $method, $signedPath, $idempotencyKey ?? '', $raw]);
        $signature = 'sha256='.hash_hmac('sha256', $canonical, $this->secret);

        $headers = [
            'X-Zaptria-Timestamp' => $timestamp,
            'X-Zaptria-Signature' => $signature,
            'Accept' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $pending = Http::withToken($this->key)
            ->withHeaders($headers)
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS);

        // `Http::post($url, $array)` serializa de novo. O corpo tem de ser o byte assinado,
        // inclusive vazio: o default `asJson` mandaria `null`.
        $pending = $pending->withBody($raw, 'application/json');

        return $pending->send($method, $url);
    }

    /**
     * `POST /messages` sempre manda `Idempotency-Key`. Sem chave, gera uma.
     *
     * @param  array<string, mixed>  $body
     */
    public function sendMessage(string $externalId, array $body, ?string $idempotencyKey = null): ?Response
    {
        $idempotencyKey = trim((string) $idempotencyKey);

        if ($idempotencyKey === '') {
            $idempotencyKey = (string) Str::uuid();
        }

        return $this->request('POST', '/tenants/'.$externalId.'/messages', $body, $idempotencyKey);
    }

    private function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->key !== '' && $this->secret !== '';
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function encodeBody(?array $body): string
    {
        if ($body === null) {
            return '';
        }

        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new RuntimeException('Zaptria request body could not be encoded.');
        }

        return $encoded;
    }

    private function url(string $path): string
    {
        if ($path === '' || ($path[0] !== '/' && $path[0] !== '?')) {
            $path = '/'.$path;
        }

        return rtrim($this->baseUrl, '/').$path;
    }

    /**
     * Path absoluto da request, como o Zaptria assina: a partir de `/api/…`, com a query se houver.
     */
    private function signedPath(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Zaptria base URL is invalid.');
        }

        $path = $parts['path'] ?? '';

        if ($path === '') {
            $path = '/';
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?'.$parts['query'];
        }

        return $path;
    }
}
