<?php

namespace App\Services;

use App\Contracts\PaymentResult;

/**
 * Leitura de uma cobrança que não vingou: o QUE registrar no log e O QUE dizer ao cliente.
 *
 * Duas famílias, decididas de forma conservadora (na dúvida: `customer`):
 *  - `customer`: dado do cliente (cartão recusado, e-mail inválido, token ausente...). O
 *    cliente pode corrigir; mantém a mensagem "confira os dados e tente novamente";
 *  - `service`: configuração ou indisponibilidade que o cliente NÃO resolve: HTTP 5xx,
 *    401/403 (credencial/permissão), 429 (cota), timeout/rede/exceção e o que o próprio
 *    gateway marcou como `configuration` (ex.: Mercado Pago `payment_method_not_allowed_for_collector`).
 *
 * O gateway sinaliza com `meta['reason']` (`configuration` | `unreachable`); além disso, o
 * formato de erro da API de Orders do Mercado Pago (`errors[].code|message|details`) é lido
 * aqui, e os códigos de {@see self::CONFIGURATION_CODES} (credencial, permissão, integração
 * mal formada, cota) contam como falha de CONFIGURAÇÃO mesmo sem o gateway ter sinalizado.
 * Códigos de validação de campo (`property_value`, `property_type`...) ficam como `customer`:
 * na dúvida o cliente tenta de novo.
 *
 * Nada de segredo vai ao log: só status HTTP, o primeiro código/mensagem e `errors[]`
 * (código + descrição) com e-mails, tokens e números longos mascarados e truncados.
 */
final class PaymentFailure
{
    public const CUSTOMER_MESSAGE = 'Não foi possível concluir o pagamento. O pedido foi cancelado; confira os dados e tente novamente.';

    public const UNAVAILABLE_MESSAGE = 'O pagamento online está indisponível no momento. Escolha outra forma de pagamento ou fale com a loja.';

    public const REASON_CONFIGURATION = 'configuration';

    public const REASON_UNREACHABLE = 'unreachable';

    /**
     * Códigos de `errors[].code` da API de Orders que o CLIENTE não resolve: credencial/permissão
     * (`invalid_credentials`, `401`, `forbidden`, `PA_UNAUTHORIZED_RESULT_FROM_POLICIES`,
     * `payment_method_not_allowed_for_collector`), requisição que o NOSSO código montou mal
     * (`unsupported_properties`, `required_properties`, `invalid_header_value`,
     * `empty_required_header`, `invalid_idempotency_key_length`, `invalid_order_type`,
     * `order_invalid_sponsor_id`, `invalid_total_amount`, `json_syntax_error`) e limites/estados
     * do serviço (`usage_quota_exceeded`, `resource_locked`, `idempotency_key_already_used`,
     * `idempotency_validation_failed`, `internal_error`).
     */
    public const CONFIGURATION_CODES = [
        'invalid_credentials', '401', 'forbidden', 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES',
        'payment_method_not_allowed_for_collector',
        'unsupported_properties', 'required_properties', 'invalid_header_value', 'empty_required_header',
        'invalid_idempotency_key_length', 'invalid_order_type', 'order_invalid_sponsor_id',
        'invalid_total_amount', 'json_syntax_error',
        'usage_quota_exceeded', 'resource_locked', 'idempotency_key_already_used',
        'idempotency_validation_failed', 'internal_error',
    ];

    private const MAX_ERRORS = 5;

    private const MAX_TEXT = 300;

    /**
     * @param  list<array{code: string|null, description: string|null}>  $causes  `errors[]` do gateway
     */
    private function __construct(
        public readonly ?int $httpStatus,
        public readonly ?string $reason,
        public readonly ?string $gatewayError,
        public readonly ?string $gatewayMessage,
        public readonly array $causes,
        public readonly ?string $gatewayStatus,
        public readonly ?string $statusDetail,
        public readonly ?string $exception,
    ) {}

    public static function fromResult(PaymentResult $result): self
    {
        $error = $result->meta['error'] ?? null;
        $body = is_array($error) ? $error : [];

        $causes = [];
        foreach (array_slice(self::errorList($body), 0, self::MAX_ERRORS) as $item) {
            $details = $item['details'] ?? null;

            $causes[] = [
                'code' => isset($item['code']) && is_scalar($item['code']) ? (string) $item['code'] : null,
                // `details` (lista de textos) é mais específico que `message`.
                'description' => self::scrub(is_array($details) ? implode('; ', array_filter($details, 'is_string')) : ($item['message'] ?? null)),
            ];
        }

        $reason = $result->meta['reason'] ?? null;
        $reason = is_string($reason) ? $reason : null;

        if ($reason === null && self::hasConfigurationCode($body)) {
            $reason = self::REASON_CONFIGURATION;
        }

        $first = self::errorList($body)[0] ?? [];

        return new self(
            httpStatus: $result->errorCode,
            reason: $reason,
            // Código curto do gateway (`invalid_credentials`); string solta (`missing_card_token`) também.
            gatewayError: self::scrub($first['code'] ?? ($body['error'] ?? (is_string($error) ? $error : null))),
            gatewayMessage: self::scrub($first['message'] ?? ($body['message'] ?? null)),
            causes: $causes,
            gatewayStatus: $result->success ? $result->status : null,
            statusDetail: $result->success ? $result->statusDetail : null,
            exception: is_string($result->meta['exception'] ?? null) ? $result->meta['exception'] : null,
        );
    }

    /**
     * Algum `errors[].code` é de CONFIGURAÇÃO/integração (ver {@see self::CONFIGURATION_CODES})?
     *
     * @param  array<string, mixed>  $body
     */
    public static function hasConfigurationCode(array $body): bool
    {
        foreach (self::errorList($body) as $item) {
            if (isset($item['code']) && is_scalar($item['code']) && in_array((string) $item['code'], self::CONFIGURATION_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `errors[]` do corpo de erro da API de Orders (`{"errors":[{"code","message","details":[]}]}`).
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private static function errorList(array $body): array
    {
        return array_values(array_filter((array) ($body['errors'] ?? []), 'is_array'));
    }

    /** Falha que o cliente não resolve (configuração, credencial, indisponibilidade). */
    public function isServiceSide(): bool
    {
        if ($this->reason === self::REASON_CONFIGURATION || $this->reason === self::REASON_UNREACHABLE) {
            return true;
        }

        return $this->httpStatus !== null
            && ($this->httpStatus >= 500 || in_array($this->httpStatus, [401, 403, 429], true));
    }

    public function userMessage(): string
    {
        return $this->isServiceSide() ? self::UNAVAILABLE_MESSAGE : self::CUSTOMER_MESSAGE;
    }

    /** `error` para 5xx/timeout/exceção (alguém precisa olhar); `warning` para o resto. */
    public function logLevel(): string
    {
        $serverSide = $this->httpStatus !== null && $this->httpStatus >= 500;

        return $serverSide || $this->reason === self::REASON_UNREACHABLE ? 'error' : 'warning';
    }

    /**
     * Campos do log estruturado (sem segredo, sem e-mail, sem corpo da requisição).
     *
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return array_filter([
            'http_status' => $this->httpStatus,
            'kind' => $this->isServiceSide() ? 'service' : 'customer',
            'reason' => $this->reason,
            'gateway_error' => $this->gatewayError,
            'gateway_message' => $this->gatewayMessage,
            'causes' => $this->causes !== [] ? $this->causes : null,
            'gateway_status' => $this->gatewayStatus,
            'status_detail' => $this->statusDetail,
            'exception' => $this->exception,
        ], fn ($v) => $v !== null);
    }

    /**
     * Texto vindo do gateway pode ecoar dado do cliente ("payer.email maria@x.com ...") ou
     * credencial: mascara antes de ir ao log e limita o tamanho.
     */
    private static function scrub(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $value = preg_replace([
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',      // e-mail
            '/\b(?:APP_USR|TEST|TG|PK)-[A-Za-z0-9\-_]+/',              // access token / public key do MP
            '/\b(?=[A-Za-z_\-]*\d)[A-Za-z0-9_\-]{32,}\b/',             // token longo (card token, bearer); exige um dígito: códigos de erro (`payment_method_not_allowed_for_collector`) não são tokens
            '/\b\d[\d .\-]{11,}\d\b/',                                 // número de cartão/documento
        ], ['[email]', '[token]', '[token]', '[number]'], $value) ?? '';

        return mb_substr($value, 0, self::MAX_TEXT);
    }
}
