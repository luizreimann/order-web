<?php

namespace Tests\Unit\Services;

use App\Enums\FulfillmentType;
use App\Exceptions\FulfillmentException;
use App\Models\Tenant;
use App\Services\FulfillmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regras de retirada/entrega de um tenant (puras, sem banco): quem está disponível, quanto custa,
 * o pedido mínimo e o frete grátis. Dinheiro sempre em centavos inteiros.
 */
class FulfillmentServiceTest extends TestCase
{
    private FulfillmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FulfillmentService;
    }

    private function tenant(array $settings = []): Tenant
    {
        return new Tenant(['settings' => $settings]);
    }

    private function region(string $id, string $name, int $fee, bool $active = true): array
    {
        return ['id' => $id, 'name' => $name, 'fee_cents' => $fee, 'active' => $active];
    }

    public function test_a_tenant_without_settings_behaves_like_before_pickup_only_and_free(): void
    {
        $tenant = $this->tenant();

        $this->assertSame([FulfillmentType::Pickup], $this->service->availableTypes($tenant));
        $quote = $this->service->quote($tenant, 5000, null, null);

        $this->assertSame(FulfillmentType::Pickup, $quote->type);
        $this->assertSame(0, $quote->feeCents);
        $this->assertTrue($quote->meetsMinimum());
        $this->assertSame(5000, $quote->totalCents(5000));
    }

    public function test_pickup_can_carry_an_extra_fee(): void
    {
        $quote = $this->service->quote($this->tenant(['pickup' => ['enabled' => true, 'fee_cents' => 250]]), 5000, 'pickup', null);

        $this->assertSame(250, $quote->feeCents);
        $this->assertSame('pickup', $quote->feeKind);
        $this->assertSame(5250, $quote->totalCents(5000));
    }

    public function test_available_types_follow_the_two_switches(): void
    {
        $both = $this->tenant(['pickup' => ['enabled' => true], 'delivery' => ['enabled' => true]]);
        $onlyDelivery = $this->tenant(['pickup' => ['enabled' => false], 'delivery' => ['enabled' => true]]);

        $this->assertSame([FulfillmentType::Pickup, FulfillmentType::Delivery], $this->service->availableTypes($both));
        $this->assertSame([FulfillmentType::Delivery], $this->service->availableTypes($onlyDelivery));
        $this->assertSame(FulfillmentType::Delivery, $this->service->defaultType($onlyDelivery));
    }

    public function test_both_off_is_a_misconfiguration_and_falls_back_to_pickup_instead_of_blocking_the_store(): void
    {
        $tenant = $this->tenant(['pickup' => ['enabled' => false], 'delivery' => ['enabled' => false]]);

        $this->assertSame([FulfillmentType::Pickup], $this->service->availableTypes($tenant));
    }

    public function test_a_type_the_store_does_not_offer_is_refused(): void
    {
        $this->expectException(FulfillmentException::class);

        $this->service->quote($this->tenant(), 5000, 'delivery', null);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->expectException(FulfillmentException::class);

        $this->service->quote($this->tenant(), 5000, 'drone', null);
    }

    public function test_delivery_uses_the_flat_fee_when_there_are_no_active_regions(): void
    {
        $tenant = $this->tenant(['delivery' => ['enabled' => true, 'default_fee_cents' => 700, 'regions' => [$this->region('r1', 'Centro', 300, false)]]]);

        $quote = $this->service->quote($tenant, 5000, 'delivery', null);

        $this->assertSame(700, $quote->feeCents);
        $this->assertSame('default', $quote->feeKind);
        $this->assertNull($quote->regionId);
    }

    public function test_delivery_with_active_regions_requires_one_and_charges_its_fee(): void
    {
        $tenant = $this->tenant(['delivery' => ['enabled' => true, 'default_fee_cents' => 700, 'regions' => [
            $this->region('r1', 'Centro', 500),
            $this->region('r2', 'Bairro Alto', 900),
            $this->region('r3', 'Desativado', 100, false),
        ]]]);

        $quote = $this->service->quote($tenant, 5000, 'delivery', 'r2');

        $this->assertSame(900, $quote->feeCents);
        $this->assertSame('region', $quote->feeKind);
        $this->assertSame('r2', $quote->regionId);
        $this->assertSame('Bairro Alto', $quote->regionName);
        $this->assertSame(5900, $quote->totalCents(5000));
    }

    public function test_delivery_with_active_regions_and_no_choice_is_refused_on_the_region_field(): void
    {
        $tenant = $this->tenant(['delivery' => ['enabled' => true, 'regions' => [$this->region('r1', 'Centro', 500)]]]);

        try {
            $this->service->quote($tenant, 5000, 'delivery', null);
            $this->fail('deveria exigir o bairro');
        } catch (FulfillmentException $e) {
            $this->assertArrayHasKey('region_id', $e->errors);
        }
    }

    public function test_an_inactive_or_unknown_region_is_refused(): void
    {
        $tenant = $this->tenant(['delivery' => ['enabled' => true, 'regions' => [
            $this->region('r1', 'Centro', 500),
            $this->region('r3', 'Desativado', 100, false),
        ]]]);

        foreach (['r3', 'nao-existe', ''] as $bad) {
            try {
                $this->service->quote($tenant, 5000, 'delivery', $bad);
                $this->fail("a região [{$bad}] não pode ser aceita");
            } catch (FulfillmentException $e) {
                $this->assertArrayHasKey('region_id', $e->errors);
            }
        }
    }

    /** @return array<string, array{0: int, 1: int, 2: int}> */
    public static function freeDeliveryCases(): array
    {
        return [
            'abaixo do limite cobra' => [4999, 5000, 700],
            'no limite exato é grátis' => [5000, 5000, 0],
            'acima do limite é grátis' => [9000, 5000, 0],
            'limite zero desliga o frete grátis' => [999999, 0, 700],
        ];
    }

    #[DataProvider('freeDeliveryCases')]
    public function test_free_delivery_above_a_threshold(int $subtotal, int $threshold, int $expectedFee): void
    {
        $tenant = $this->tenant(['delivery' => ['enabled' => true, 'default_fee_cents' => 700, 'free_delivery_above_cents' => $threshold]]);

        $quote = $this->service->quote($tenant, $subtotal, 'delivery', null);

        $this->assertSame($expectedFee, $quote->feeCents);
        $this->assertSame($expectedFee === 0 ? 'free' : 'default', $quote->feeKind);
    }

    public function test_free_delivery_zeroes_a_regions_fee_too_but_never_the_pickup_fee(): void
    {
        $tenant = $this->tenant([
            'pickup' => ['enabled' => true, 'fee_cents' => 300],
            'delivery' => ['enabled' => true, 'free_delivery_above_cents' => 5000, 'regions' => [$this->region('r1', 'Centro', 800)]],
        ]);

        $this->assertSame(0, $this->service->quote($tenant, 6000, 'delivery', 'r1')->feeCents);
        $this->assertSame(300, $this->service->quote($tenant, 6000, 'pickup', null)->feeCents);
    }

    public function test_the_minimum_order_counts_the_subtotal_before_any_fee_and_applies_to_every_type(): void
    {
        $tenant = $this->tenant([
            'pickup' => ['enabled' => true, 'fee_cents' => 500],
            'delivery' => ['enabled' => true, 'minimum_order_cents' => 3000, 'default_fee_cents' => 500],
        ]);

        foreach (['pickup', 'delivery'] as $type) {
            $short = $this->service->quote($tenant, 2500, $type, null);
            $this->assertFalse($short->meetsMinimum(), "{$type}: 25,00 + taxa não pode compensar o mínimo");
            $this->assertSame(500, $short->shortfallCents);

            $ok = $this->service->quote($tenant, 3000, $type, null);
            $this->assertTrue($ok->meetsMinimum());
            $this->assertSame(0, $ok->shortfallCents);
        }
    }

    public function test_delivery_exposes_the_estimated_time_and_pickup_does_not(): void
    {
        $tenant = $this->tenant(['delivery' => ['enabled' => true, 'estimated_time_minutes' => 45]]);

        $this->assertSame(45, $this->service->quote($tenant, 1000, 'delivery', null)->estimatedMinutes);
        $this->assertNull($this->service->quote($tenant, 1000, 'pickup', null)->estimatedMinutes);
    }

    public function test_the_address_is_normalised_and_the_required_parts_are_enforced(): void
    {
        $address = $this->service->validateAddress([
            'line1' => '  Rua das Flores, 120 ',
            'line2' => 'Apto 3',
            'city' => 'Curitiba',
            'state' => 'pr',
            'postal_code' => '80.010-000',
        ]);

        $this->assertSame([
            'address_line1' => 'Rua das Flores, 120',
            'address_line2' => 'Apto 3',
            'address_city' => 'Curitiba',
            'address_state' => 'PR',
            'address_postal_code' => '80010-000',
        ], $address);
    }

    public function test_the_address_reports_every_missing_or_invalid_field_at_once(): void
    {
        try {
            $this->service->validateAddress(['line1' => 'R', 'city' => '', 'state' => 'Parana', 'postal_code' => '123']);
            $this->fail('endereço inválido');
        } catch (FulfillmentException $e) {
            $this->assertEqualsCanonicalizing(['address_line1', 'address_city', 'address_state', 'address_postal_code'], array_keys($e->errors));
        }
    }

    public function test_the_postal_code_and_complement_are_optional(): void
    {
        $address = $this->service->validateAddress(['line1' => 'Rua A, 1', 'city' => 'Curitiba', 'state' => 'PR']);

        $this->assertSame('', $address['address_postal_code']);
        $this->assertSame('', $address['address_line2']);
    }
}
