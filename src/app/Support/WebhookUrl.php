<?php

namespace App\Support;

/**
 * URL de webhook que o gateway pode alcançar, ou nada.
 *
 * Hoje serve à tela Admin > Pagamentos: o lojista cadastra a URL NO PAINEL do Mercado Pago (na
 * API de Orders não existe `notification_url` na cobrança; a sandbox responde 400
 * `unsupported_properties` a campos fora do contrato). Na antiga API de Payments o campo ia no
 * corpo e o MP recusava a cobrança inteira (HTTP 400, código 4020) quando a URL era local; a
 * regra de "só URL pública" ficou porque o painel também não entrega webhook a `localhost`.
 *
 * O que a documentação oficial do MP diz (Webhooks): não usar domínios locais como `localhost`
 * ou `127.0.0.1`, com ou sem porta; usar um servidor com domínio (DNS) ou um IP acessível
 * externamente; `http` em URLs de notificação é bloqueado desde 29/03/2025.
 *
 * Regras (conservadoras de propósito: mostrar uma URL que o MP não alcança dá falsa segurança,
 * enquanto omiti-la só tira o webhook, que a reconciliação substitui):
 *  - absoluta, esquema `https`, sem credenciais, query ou fragmento;
 *  - host de DNS com ao menos dois rótulos, TLD alfabético (ou punycode) fora da lista de
 *    TLDs locais/reservados, ou IP literal público (v4/v6 globais);
 *  - sem `localhost`, loopback, redes privadas, link-local, CGNAT, documentação,
 *    multicast, reservadas;
 *  - porta: só a padrão (omitida ou 443). A documentação não diz que portas não padrão
 *    são aceitas; ngrok/cloudflared/produção usam 443.
 */
final class WebhookUrl
{
    /** TLDs que nunca resolvem na internet pública (RFC 2606/6761/6762 + usos comuns de LAN). */
    private const LOCAL_TLDS = [
        'local', 'localhost', 'test', 'internal', 'example', 'invalid',
        'lan', 'home', 'localdomain', 'intranet', 'corp', 'private', 'arpa', 'onion',
    ];

    /** Domínios reservados para documentação (RFC 2606), com seus subdomínios. */
    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    /** IPv4 não públicos além de privados/loopback: [rede, bits]. */
    private const IPV4_BLOCKED = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
        ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    /**
     * URL do webhook de um tenant, ou null se `$baseUrl` (normalmente `APP_URL`) não serve.
     * O id do tenant (e não o slug, que é editável) é o que autentica o webhook (E3.3).
     */
    public static function for(mixed $baseUrl, string $gateway, string $tenantId): ?string
    {
        if (! self::isUsableBase($baseUrl)) {
            return null;
        }

        $path = '/webhooks/'.rawurlencode($gateway).'/'.rawurlencode($tenantId);

        return rtrim((string) $baseUrl, '/').$path;
    }

    public static function isUsableBase(mixed $baseUrl): bool
    {
        if (! is_string($baseUrl)) {
            return false;
        }

        // Sem trim: um valor com espaço/quebra de linha vindo do .env é configuração errada
        // e deve desativar o webhook, não ser "consertado" em silêncio.
        $url = $baseUrl;

        // Espaços/controles/não ASCII: parse_url os tolera; o MP e o DNS não.
        if ($url === '' || preg_match('/[\x00-\x20\x7f-\xff]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || str_contains($url, '#') || str_contains($url, '?')) {
            return false;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && $port !== 443) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        return $host !== '' && self::isPublicHost($host);
    }

    private static function isPublicHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return self::isPublicIpv6(substr($host, 1, -1));
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isPublicIpv4($host);
        }

        return self::isPublicDnsName($host);
    }

    private static function isPublicIpv4(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return false;
        }

        if ($ip === '255.255.255.255') {
            return false;
        }

        foreach (self::IPV4_BLOCKED as [$network, $bits]) {
            $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;

            if ((($long & $mask) & 0xFFFFFFFF) === ((ip2long($network) & $mask) & 0xFFFFFFFF)) {
                return false;
            }
        }

        return true;
    }

    private static function isPublicIpv6(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $bytes = inet_pton($ip);

        if ($bytes === false || strlen($bytes) !== 16) {
            return false;
        }

        // Só unicast global (2000::/3). Isso já exclui ::, ::1, ::ffff:0:0/96 (v4 mapeado),
        // fc00::/7, fe80::/10 e multicast; falta só a faixa de documentação 2001:db8::/32.
        if ((ord($bytes[0]) & 0xE0) !== 0x20) {
            return false;
        }

        return substr($bytes, 0, 4) !== "\x20\x01\x0d\xb8";
    }

    private static function isPublicDnsName(string $host): bool
    {
        if (strlen($host) > 253) {
            return false;
        }

        $labels = explode('.', $host);

        // `https://app` (rótulo único: nome de serviço do Docker, host de LAN) nunca é público.
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        // TLD alfabético (ou punycode): isso também derruba IPs disfarçados (`127.1`, `0x7f.0.0.1`).
        $tld = end($labels);
        if (preg_match('/^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld) !== 1 || in_array($tld, self::LOCAL_TLDS, true)) {
            return false;
        }

        foreach (self::RESERVED_DOMAINS as $reserved) {
            if ($host === $reserved || str_ends_with($host, '.'.$reserved)) {
                return false;
            }
        }

        return true;
    }
}
