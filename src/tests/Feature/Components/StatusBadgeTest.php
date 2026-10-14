<?php

namespace Tests\Feature\Components;

use App\Enums\GatewayPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\TestCase;

/**
 * E4.2 / D8-9.3 — `<x-status-badge>`: o mapeamento vive no enum; nunca `primary`/`danger`.
 */
class StatusBadgeTest extends TestCase
{
    use RendersComponents;

    /** @return array<string, array{OrderStatus|PaymentStatus|GatewayPaymentStatus}> */
    public static function allStatuses(): array
    {
        $cases = [];
        foreach ([OrderStatus::class, PaymentStatus::class, GatewayPaymentStatus::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $cases[class_basename($enum).'::'.$case->name] = [$case];
            }
        }

        return $cases;
    }

    #[DataProvider('allStatuses')]
    public function test_renders_label_and_variant_of_the_enum_and_never_primary_or_danger(OrderStatus|PaymentStatus|GatewayPaymentStatus $status): void
    {
        $html = $this->render('<x-status-badge :status="$status" />', ['status' => $status]);

        $badge = $this->first($html, '/html/body/span');
        $this->assertEqualsCanonicalizing(['badge', 'text-bg-'.$status->variant()], $this->classes($badge));
        $this->assertSame($status->label(), trim($badge->textContent));
        $this->assertDoesNotMatchRegularExpression('/text-bg-(primary|danger)\b|bg-(primary|danger)\b/', $html);
    }

    public function test_decision_d8_mapping_is_rendered(): void
    {
        $this->assertStringContainsString('text-bg-warning', $this->render('<x-status-badge :status="$s" />', ['s' => OrderStatus::PENDING]));
        $this->assertStringContainsString('text-bg-info', $this->render('<x-status-badge :status="$s" />', ['s' => OrderStatus::CONFIRMED]));
        $this->assertStringContainsString('text-bg-success', $this->render('<x-status-badge :status="$s" />', ['s' => OrderStatus::DONE]));
        $this->assertStringContainsString('text-bg-secondary', $this->render('<x-status-badge :status="$s" />', ['s' => OrderStatus::CANCELLED]));
        $this->assertStringContainsString('text-bg-success', $this->render('<x-status-badge :status="$s" />', ['s' => PaymentStatus::PAID]));
    }

    public function test_active_flag_renders_ativo_inativo(): void
    {
        $on = $this->render('<x-status-badge :active="true" />');
        $off = $this->render('<x-status-badge :active="false" />');

        $this->assertSame('badge text-bg-success', $this->first($on, '//span')->getAttribute('class'));
        $this->assertSame('Ativo', trim($this->first($on, '//span')->textContent));
        $this->assertSame('badge text-bg-secondary', $this->first($off, '//span')->getAttribute('class'));
        $this->assertSame('Inativo', trim($this->first($off, '//span')->textContent));
    }

    public function test_label_override_and_null_status(): void
    {
        $html = $this->render('<x-status-badge :status="$s" label="Aguardando" />', ['s' => OrderStatus::PENDING]);
        $this->assertSame('Aguardando', trim($this->first($html, '//span')->textContent));

        $this->assertSame('', trim($this->render('<x-status-badge :status="null" />')));
    }

    public function test_a_forbidden_variant_from_a_custom_enum_falls_back_to_secondary(): void
    {
        $html = $this->render('<x-status-badge :status="$s" />', ['s' => RogueStatus::Bad]);

        $this->assertStringContainsString('text-bg-secondary', $html);
        $this->assertStringNotContainsString('text-bg-primary', $html);
    }

    public function test_any_enum_with_label_and_variant_is_accepted_and_others_are_rejected(): void
    {
        $this->assertStringContainsString('text-bg-light', $this->render('<x-status-badge :status="$s" />', ['s' => RogueStatus::Good]));

        $this->expectException(\Throwable::class);
        $this->render('<x-status-badge :status="$s" />', ['s' => 'pending']);
    }
}

enum RogueStatus: string
{
    case Bad = 'bad';
    case Good = 'good';

    public function label(): string
    {
        return $this->name;
    }

    public function variant(): string
    {
        return match ($this) {
            self::Bad => 'primary',
            self::Good => 'light',
        };
    }
}
