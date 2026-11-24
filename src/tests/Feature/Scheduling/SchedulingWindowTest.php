<?php

namespace Tests\Feature\Scheduling;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A conta da janela, sem checkout. O relógio está em 2026-11-09 15:00 UTC, segunda-feira
 * 12:00 em America/Sao_Paulo. Hoje (2026-11-09) nunca é candidata.
 */
class SchedulingWindowTest extends TestCase
{
    use RefreshDatabase;

    private SchedulingService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        $this->service = new SchedulingService;
        $this->tenant = Tenant::factory()->create();
    }

    public function test_notice_zero_starts_tomorrow_and_today_is_not_a_candidate(): void
    {
        $this->configure(0, 30);

        $this->assertSame([
            'notice_days' => 0,
            'candidate' => '2026-11-10',
            'last' => '2026-12-09',
            'has_day' => true,
        ], $this->window());
    }

    public function test_notice_one_starts_the_day_after_tomorrow(): void
    {
        $this->configure(1, 30);

        $window = $this->window();

        $this->assertSame(1, $window['notice_days']);
        $this->assertSame('2026-11-11', $window['candidate']);
        $this->assertTrue($window['has_day']);
    }

    public function test_max_zero_offers_no_day(): void
    {
        $this->configure(0, 0);

        $this->assertSame([
            'notice_days' => 0,
            'candidate' => null,
            'last' => null,
            'has_day' => false,
        ], $this->window());
    }

    public function test_max_seven_cuts_the_window_seven_days_out(): void
    {
        $this->configure(0, 7);

        $this->assertSame([
            'notice_days' => 0,
            'candidate' => '2026-11-10',
            'last' => '2026-11-16',
            'has_day' => true,
        ], $this->window());
    }

    public function test_min_one_and_max_one_offer_no_day(): void
    {
        $this->configure(1, 1);

        $this->assertSame([
            'notice_days' => 1,
            'candidate' => null,
            'last' => null,
            'has_day' => false,
        ], $this->window());
    }

    public function test_a_blank_product_keeps_the_store_notice_and_the_cart_takes_the_longest(): void
    {
        $this->configure(2, 30);

        $window = $this->window([
            $this->product(0),
            $this->product(null),
        ]);

        $this->assertSame(2, $window['notice_days']);
        $this->assertSame('2026-11-12', $window['candidate']);
        $this->assertSame('2026-12-09', $window['last']);
        $this->assertTrue($window['has_day']);
    }

    public function test_zero_on_every_product_replaces_the_store_notice(): void
    {
        $this->configure(2, 30);

        $window = $this->window([
            $this->product(0),
            $this->product(0),
        ]);

        $this->assertSame(0, $window['notice_days']);
        $this->assertSame('2026-11-10', $window['candidate']);
        $this->assertTrue($window['has_day']);
    }

    public function test_disabled_business_hours_do_not_change_the_window(): void
    {
        $this->configure(0, 30, [
            'enabled' => false,
            'timezone' => 'America/Sao_Paulo',
        ]);

        $this->assertSame([
            'notice_days' => 0,
            'candidate' => '2026-11-10',
            'last' => '2026-12-09',
            'has_day' => true,
        ], $this->window());
    }

    public function test_an_invalid_timezone_falls_back_to_sao_paulo(): void
    {
        $this->configure(0, 30, [
            'enabled' => true,
            'timezone' => 'Not/AZone',
        ]);

        $this->assertSame([
            'notice_days' => 0,
            'candidate' => '2026-11-10',
            'last' => '2026-12-09',
            'has_day' => true,
        ], $this->window());
    }

    public function test_a_valid_store_timezone_moves_today(): void
    {
        $this->configure(0, 30, [
            'enabled' => false,
            'timezone' => 'Pacific/Kiritimati',
        ]);

        $this->assertSame([
            'notice_days' => 0,
            'candidate' => '2026-11-11',
            'last' => '2026-12-10',
            'has_day' => true,
        ], $this->window());
    }

    private function configure(int $minDays, int $maxDays, array $businessHours = []): void
    {
        $settings = [
            'scheduling' => [
                'enabled' => true,
                'min_days' => $minDays,
                'max_days' => $maxDays,
            ],
        ];

        if ($businessHours !== []) {
            $settings['business_hours'] = $businessHours;
        }

        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();
    }

    private function product(?int $notice): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'min_days_notice' => $notice,
        ]);
    }

    /**
     * @param  list<Product>  $products
     * @return array{notice_days: int, candidate: ?string, last: ?string, has_day: bool}
     */
    private function window(array $products = []): array
    {
        return $this->service->window($this->tenant, $products);
    }
}
