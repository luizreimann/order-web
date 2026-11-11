<?php

namespace App\Services;

use App\Enums\FulfillmentType;
use App\Exceptions\FulfillmentException;
use App\Models\Tenant;
use App\Support\FulfillmentQuote;

/**
 * Retirada e entrega de um tenant: o que está disponível, quanto custa, o pedido mínimo e o
 * frete grátis. Lê `tenants.settings` (`pickup`, `delivery`); sem configuração o comportamento é
 * o de sempre: só retirada, sem taxa, sem mínimo. Dinheiro em centavos inteiros.
 *
 * Regras:
 *  - mínimo: sobre o SUBTOTAL, antes de qualquer taxa, para retirada e entrega;
 *  - retirada: pode ter taxa adicional própria;
 *  - entrega: taxa da região escolhida; sem região ativa cadastrada, a taxa padrão;
 *  - frete grátis: subtotal >= limite zera a taxa de ENTREGA (limite 0 = desligado).
 */
class FulfillmentService
{
    /** Quantas regiões a loja pode cadastrar (também limite de tamanho do JSON de settings). */
    public const MAX_REGIONS = 100;

    /** @return list<FulfillmentType> sempre ao menos uma: tudo desligado cai em retirada */
    public function availableTypes(Tenant $tenant): array
    {
        $types = [];

        if ($this->pickupEnabled($tenant)) {
            $types[] = FulfillmentType::Pickup;
        }

        if ($this->deliveryEnabled($tenant)) {
            $types[] = FulfillmentType::Delivery;
        }

        return $types === [] ? [FulfillmentType::Pickup] : $types;
    }

    public function defaultType(Tenant $tenant): FulfillmentType
    {
        return $this->availableTypes($tenant)[0];
    }

    public function pickupEnabled(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->settings, 'pickup.enabled', true);
    }

    public function deliveryEnabled(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->settings, 'delivery.enabled', false);
    }

    public function pickupFeeCents(Tenant $tenant): int
    {
        return max(0, (int) data_get($tenant->settings, 'pickup.fee_cents', 0));
    }

    public function minimumCents(Tenant $tenant): int
    {
        return max(0, (int) data_get($tenant->settings, 'delivery.minimum_order_cents', 0));
    }

    public function defaultDeliveryFeeCents(Tenant $tenant): int
    {
        return max(0, (int) data_get($tenant->settings, 'delivery.default_fee_cents', 0));
    }

    public function freeDeliveryAboveCents(Tenant $tenant): int
    {
        return max(0, (int) data_get($tenant->settings, 'delivery.free_delivery_above_cents', 0));
    }

    public function estimatedMinutes(Tenant $tenant): ?int
    {
        $minutes = data_get($tenant->settings, 'delivery.estimated_time_minutes');

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : null;
    }

    /**
     * Regiões de entrega (nome + preço).
     *
     * @return list<array{id: string, name: string, fee_cents: int, active: bool}>
     */
    public function regions(Tenant $tenant, bool $onlyActive = true): array
    {
        $regions = [];

        foreach ((array) data_get($tenant->settings, 'delivery.regions', []) as $region) {
            if (! is_array($region) || ! is_string($region['id'] ?? null) || trim((string) ($region['name'] ?? '')) === '') {
                continue;
            }

            $entry = [
                'id' => $region['id'],
                'name' => trim((string) $region['name']),
                'fee_cents' => max(0, (int) ($region['fee_cents'] ?? 0)),
                'active' => (bool) ($region['active'] ?? true),
            ];

            if (! $onlyActive || $entry['active']) {
                $regions[] = $entry;
            }
        }

        usort($regions, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $regions;
    }

    /**
     * @throws FulfillmentException tipo não oferecido ou bairro inválido
     */
    public function quote(Tenant $tenant, int $subtotalCents, ?string $type, ?string $regionId): FulfillmentQuote
    {
        $resolved = $this->resolveType($tenant, $type);
        $minimum = $this->minimumCents($tenant);
        $shortfall = max(0, $minimum - $subtotalCents);

        if (! $resolved->isDelivery()) {
            $fee = $this->pickupFeeCents($tenant);

            return new FulfillmentQuote($resolved, $fee, $fee > 0 ? 'pickup' : 'none', null, null, $minimum, $shortfall, null);
        }

        $regions = $this->regions($tenant);
        $regionName = null;
        $selectedId = null;

        if ($regions === []) {
            $fee = $this->defaultDeliveryFeeCents($tenant);
            $kind = 'default';
        } else {
            $region = collect($regions)->firstWhere('id', (string) $regionId);

            if ($region === null) {
                throw FulfillmentException::field('region_id', 'Escolha o bairro da entrega.');
            }

            $fee = $region['fee_cents'];
            $kind = 'region';
            $selectedId = $region['id'];
            $regionName = $region['name'];
        }

        $freeAbove = $this->freeDeliveryAboveCents($tenant);

        if ($freeAbove > 0 && $subtotalCents >= $freeAbove) {
            $fee = 0;
            $kind = 'free';
        }

        return new FulfillmentQuote($resolved, $fee, $kind, $selectedId, $regionName, $minimum, $shortfall, $this->estimatedMinutes($tenant));
    }

    /**
     * @param  array<string, mixed>  $address  line1, line2, city, state, postal_code
     * @return array{address_line1: string, address_line2: string, address_city: string, address_state: string, address_postal_code: string}
     *
     * @throws FulfillmentException todos os campos com problema, de uma vez
     */
    public function validateAddress(array $address): array
    {
        $line1 = trim((string) ($address['line1'] ?? ''));
        $line2 = trim((string) ($address['line2'] ?? ''));
        $city = trim((string) ($address['city'] ?? ''));
        $state = strtoupper(trim((string) ($address['state'] ?? '')));
        $digits = preg_replace('/\D/', '', (string) ($address['postal_code'] ?? '')) ?? '';

        $errors = [];

        if (mb_strlen($line1) < 3 || mb_strlen($line1) > 160) {
            $errors['address_line1'] = 'Informe a rua e o número.';
        }

        if (mb_strlen($line2) > 160) {
            $errors['address_line2'] = 'O complemento é longo demais.';
        }

        if ($city === '' || mb_strlen($city) > 100) {
            $errors['address_city'] = 'Informe a cidade.';
        }

        if (preg_match('/^[A-Z]{2}$/', $state) !== 1) {
            $errors['address_state'] = 'Informe a UF com 2 letras.';
        }

        if ($digits !== '' && strlen($digits) !== 8) {
            $errors['address_postal_code'] = 'CEP inválido.';
        }

        if ($errors !== []) {
            throw new FulfillmentException('Confira o endereço de entrega.', $errors);
        }

        return [
            'address_line1' => $line1,
            'address_line2' => $line2,
            'address_city' => $city,
            'address_state' => $state,
            'address_postal_code' => $digits === '' ? '' : substr($digits, 0, 5).'-'.substr($digits, 5),
        ];
    }

    private function resolveType(Tenant $tenant, ?string $type): FulfillmentType
    {
        if ($type === null || $type === '') {
            return $this->defaultType($tenant);
        }

        $resolved = FulfillmentType::tryFrom($type);

        if ($resolved === null || ! in_array($resolved, $this->availableTypes($tenant), true)) {
            throw FulfillmentException::field('fulfillment_type', 'Esta forma de recebimento não está disponível.');
        }

        return $resolved;
    }
}
