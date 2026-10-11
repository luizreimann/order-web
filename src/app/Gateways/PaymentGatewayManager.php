<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\PaymentMethod;
use App\Exceptions\UnsupportedPaymentMethodException;
use App\Models\Tenant;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * Resolve o gateway de pagamento por provedor / método / tenant (D4, E3.5).
 *
 * Registrado como singleton em `AppServiceProvider`. A chave é o provedor de
 * `PaymentMethod::provider()` (`manual` | `mercadopago`); `manual` NÃO tem gateway
 * (pedido manual não cobra online) e `forMethod()` lança para ele.
 *
 * Resolução do provedor de um método para um tenant:
 *  1. `settings.payment.methods[id=<método>].provider` do tenant, se preenchido (é o
 *     ponto em que um tenant passará a escolher OUTRO gateway para o mesmo método
 *     quando houver mais de um; hoje a configuração grava o provedor padrão);
 *  2. `PaymentMethod::provider()`.
 * Um provedor que não esteja registrado aqui nunca é resolvido (UnsupportedPaymentMethodException),
 * então a configuração do tenant não consegue invocar código arbitrário.
 *
 * Adicionar um gateway = `extend('chave', fn () => new MeuGateway)` num provider. Os
 * testes usam o mesmo `extend()` para registrar o `FakeGateway` (nunca em produção).
 */
class PaymentGatewayManager
{
    /** @var array<string, Closure(): PaymentGatewayInterface> */
    private array $factories = [];

    /** @var array<string, PaymentGatewayInterface> */
    private array $resolved = [];

    public function __construct(private readonly Container $container)
    {
        $this->extend('mercadopago', fn () => $this->container->make(MercadoPagoGateway::class));
    }

    /**
     * @param  Closure(): PaymentGatewayInterface  $factory
     */
    public function extend(string $provider, Closure $factory): static
    {
        $this->factories[$provider] = $factory;
        unset($this->resolved[$provider]);

        return $this;
    }

    public function has(string $provider): bool
    {
        return isset($this->factories[$provider]);
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->factories);
    }

    /**
     * Gateway de um provedor (rota `/webhooks/{gateway}`).
     *
     * @throws UnsupportedPaymentMethodException provedor não registrado
     */
    public function forProvider(string $provider): PaymentGatewayInterface
    {
        if (! $this->has($provider)) {
            throw new UnsupportedPaymentMethodException("Gateway de pagamento desconhecido: {$provider}");
        }

        return $this->resolved[$provider] ??= ($this->factories[$provider])();
    }

    /**
     * Gateway que cobra `$method` para `$tenant`.
     *
     * @throws UnsupportedPaymentMethodException método inexistente, manual ou sem gateway registrado
     */
    public function forMethod(PaymentMethod|string $method, Tenant $tenant): PaymentGatewayInterface
    {
        $enum = $method instanceof PaymentMethod ? $method : PaymentMethod::tryFrom($method);

        if ($enum === null) {
            throw new UnsupportedPaymentMethodException('Método de pagamento inválido.');
        }

        if ($enum->isManual()) {
            throw new UnsupportedPaymentMethodException("O método {$enum->value} é manual e não usa gateway.");
        }

        return $this->forProvider($this->providerFor($enum, $tenant));
    }

    private function providerFor(PaymentMethod $method, Tenant $tenant): string
    {
        foreach ((array) data_get($tenant->settings, 'payment.methods', []) as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $method->value) {
                $configured = $entry['provider'] ?? null;

                if (is_string($configured) && $configured !== '' && $configured !== 'manual') {
                    return $configured;
                }
            }
        }

        return $method->provider();
    }
}
