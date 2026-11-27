<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\CustomerLoginCode;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use App\Services\CustomerLoginCodeService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Código de login por WhatsApp: pedir não cria cliente; acertar entra na sessão.
 */
class CustomerLoginCodeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zaptria.base_url' => 'https://app.zaptria.com.br/api/v1/partner',
            'services.zaptria.key' => 'zpt_live_test_key',
            'services.zaptria.secret' => 'zpt_whsec_test_signing_secret',
            'services.zaptria.owner_email' => '',
        ]);

        $this->tenant = Tenant::factory()->create([
            'name' => 'Carol Doces',
            'slug' => 'carol',
        ]);
    }

    public function test_request_sends_otp_for_the_store_and_does_not_create_a_customer(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $this->assertTrue($this->service()->requestCode($this->tenant, '(11) 99999-8888'));

        $this->assertSame(0, Customer::query()->count());
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertCount(0, cookie()->getQueuedCookies());
        $this->assertFalse(Schema::hasColumn('customer_login_codes', 'code'));

        $sent = $this->sentMessages();
        $this->assertCount(1, $sent);
        $this->assertSame('auto', $sent[0]['body']['sender']);
        $this->assertSame('otp', $sent[0]['body']['purpose']);
        $this->assertSame('11999998888', $sent[0]['body']['to']);
        $this->assertSame('Carol Doces: seu código é '.$sent[0]['digits'], $sent[0]['body']['text']);
        $this->assertStringContainsString('/tenants/'.$this->tenant->id.'/messages', $sent[0]['url']);

        $row = CustomerLoginCode::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('11999998888', $row->phone);
        $this->assertSame($sent[0]['key'], $row->idempotency_key);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->consumed_at);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), $row->expires_at->getTimestamp(), 5);
        $this->assertSame(hash_hmac('sha256', $sent[0]['digits'], (string) config('app.key')), $row->code_hash);
        $this->assertCodeIsNotStored($sent[0]['digits'], $row);
    }

    #[DataProvider('invalidPhones')]
    public function test_invalid_phone_does_not_create_a_row_or_a_customer(string $phone): void
    {
        Http::fake();

        $this->assertFalse($this->service()->requestCode($this->tenant, $phone));

        $this->assertSame(0, CustomerLoginCode::query()->count());
        $this->assertSame(0, Customer::query()->count());
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function invalidPhones(): array
    {
        return [
            'vazio' => [''],
            'curto' => ['123'],
            'texto' => ['recepção'],
            'ddd com zero' => ['011999998888'],
            'outro país' => ['+1 415 555 0134'],
        ];
    }

    public function test_the_matching_code_within_ten_minutes_opens_todays_session_and_creates_the_customer(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $service = $this->service();
        $this->assertTrue($service->requestCode($this->tenant, '11999998888'));
        $digits = $this->sentMessages()[0]['digits'];

        $this->travel(9)->minutes();

        $result = $service->verifyCode($this->tenant, '+55 (11) 99999-8888', $digits);

        $this->assertNotNull($result);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['session_token']);
        $this->assertSame(1, Customer::query()->count());

        $customer = Customer::query()->where('phone', '11999998888')->first();
        $this->assertNotNull($customer);
        $this->assertSame($result['customer']->id, $customer->id);
        $this->assertSame($result['session_token'], $customer->session_token);
        $this->assertTrue($customer->last_login_at->greaterThan(now()->subMinute()));
        $this->assertNotNull(
            app(CustomerAuthService::class)->validateSessionToken($this->tenant, $result['session_token'])
        );
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertCount(0, cookie()->getQueuedCookies());

        $row = CustomerLoginCode::query()->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->consumed_at);

        $this->assertNull($service->verifyCode($this->tenant, '11999998888', $digits));
        $this->assertSame($result['session_token'], $customer->fresh()->session_token);
        $this->assertSame(1, Customer::query()->count());
        $this->assertFalse($service->resend($this->tenant, $row));
        $this->assertCount(1, $this->sentMessages());
    }

    public function test_a_wrong_code_does_not_enter_and_leaves_the_row_alive(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $service = $this->service();
        $this->assertTrue($service->requestCode($this->tenant, '11999998888'));
        $digits = $this->sentMessages()[0]['digits'];
        $wrong = $digits === '000000' ? '000001' : '000000';

        $this->assertNull($service->verifyCode($this->tenant, '11999998888', $wrong));

        $this->assertSame(0, Customer::query()->count());
        $row = CustomerLoginCode::query()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->consumed_at);
        $this->assertSame(1, $row->attempts);

        $result = $service->verifyCode($this->tenant, '11999998888', $digits);
        $this->assertNotNull($result);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_a_new_request_retires_the_previous_code_and_issues_another_key(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $service = $this->service();
        $this->assertTrue($service->requestCode($this->tenant, '11988887777'));
        $this->assertTrue($service->requestCode($this->tenant, '(11) 99999-8888'));

        $first = CustomerLoginCode::query()->where('phone', '11999998888')->first();
        $this->assertNotNull($first);
        $firstDigits = $this->sentMessages()[1]['digits'];
        $firstKey = $first->idempotency_key;

        $this->assertTrue($service->requestCode($this->tenant, '11999998888'));

        $this->assertNotNull($first->fresh()->consumed_at);
        $untouched = CustomerLoginCode::query()->where('phone', '11988887777')->first();
        $this->assertNotNull($untouched);
        $this->assertNull($untouched->consumed_at);

        $second = CustomerLoginCode::query()->where('phone', '11999998888')->whereNull('consumed_at')->first();
        $this->assertNotNull($second);
        $secondDigits = $this->sentMessages()[2]['digits'];

        $this->assertNotSame($firstKey, $second->idempotency_key);
        $this->assertNotSame($firstDigits, $secondDigits);
        $this->assertSame($secondDigits, $this->sentMessages()[2]['digits']);
        $this->assertSame($second->idempotency_key, $this->sentMessages()[2]['key']);

        $this->assertNull($service->verifyCode($this->tenant, '11999998888', $firstDigits));
        $this->assertSame(0, Customer::query()->count());
        $this->assertNotNull($service->verifyCode($this->tenant, '11999998888', $secondDigits));
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_two_resends_of_the_same_row_do_not_change_the_six_digits(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $service = $this->service();
        $this->assertTrue($service->requestCode($this->tenant, '11999998888'));
        $row = CustomerLoginCode::query()->first();
        $this->assertNotNull($row);

        $this->assertTrue($service->resend($this->tenant, $row));
        $this->assertTrue($service->resend($this->tenant, $row));

        $sent = $this->sentMessages();
        $this->assertCount(3, $sent);
        $this->assertSame($sent[0]['digits'], $sent[1]['digits']);
        $this->assertSame($sent[1]['digits'], $sent[2]['digits']);
        $this->assertSame($sent[0]['key'], $sent[1]['key']);
        $this->assertSame($sent[1]['key'], $sent[2]['key']);
        $this->assertSame($row->idempotency_key, $sent[2]['key']);
        $this->assertSame('Carol Doces: seu código é '.$sent[0]['digits'], $sent[1]['body']['text']);
        $this->assertSame('Carol Doces: seu código é '.$sent[0]['digits'], $sent[2]['body']['text']);
        $this->assertSame(1, CustomerLoginCode::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_an_http_failure_is_not_sent_and_does_not_log_in(): void
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

        $this->assertFalse($this->service()->requestCode($this->tenant, '11999998888'));

        $this->assertSame(500, Http::recorded()->first()[1]->status());
        $this->assertSame(0, Customer::query()->count());
        $this->assertFalse(Auth::guard('customer')->check());
        $row = CustomerLoginCode::query()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->consumed_at);
    }

    public function test_a_network_failure_is_not_sent_and_does_not_log_in(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        $this->assertFalse($this->service()->requestCode($this->tenant, '11999998888'));

        $this->assertSame(0, Customer::query()->count());
        $this->assertFalse(Auth::guard('customer')->check());
        $row = CustomerLoginCode::query()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->consumed_at);
    }

    public function test_an_expired_code_does_not_enter(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $service = $this->service();
        $this->assertTrue($service->requestCode($this->tenant, '11999998888'));
        $digits = $this->sentMessages()[0]['digits'];
        $row = CustomerLoginCode::query()->first();
        $this->assertNotNull($row);

        $this->travel(11)->minutes();

        $this->assertNull($service->verifyCode($this->tenant, '11999998888', $digits));
        $this->assertFalse($service->resend($this->tenant, $row));
        $this->assertSame(0, Customer::query()->count());
        $this->assertNull($row->fresh()->consumed_at);
        $this->assertCount(1, $this->sentMessages());
    }

    public function test_a_code_from_another_store_does_not_enter_or_retire_the_first(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $service = $this->service();
        $this->assertTrue($service->requestCode($this->tenant, '11999998888'));
        $digits = $this->sentMessages()[0]['digits'];
        $row = CustomerLoginCode::query()->firstOrFail();

        $other = Tenant::factory()->create(['name' => 'Outra Loja', 'slug' => 'outra']);
        $this->assertTrue($service->requestCode($other, '(11) 99999-8888'));

        $this->assertNull($row->fresh()->consumed_at);
        $this->assertSame(2, CustomerLoginCode::query()->count());
        $this->assertNull($service->verifyCode($other, '11999998888', $digits));
        $this->assertSame(0, Customer::query()->count());
        $this->assertNull($row->fresh()->consumed_at);
        $this->assertSame(0, $row->fresh()->attempts);

        app(TenantContext::class)->set($other);

        $this->assertSame(1, CustomerLoginCode::query()->count());
        $this->assertSame($other->id, CustomerLoginCode::query()->value('tenant_id'));
    }

    private function service(): CustomerLoginCodeService
    {
        return app(CustomerLoginCodeService::class);
    }

    /**
     * @return list<array{digits: string, key: string, body: array<string, mixed>, url: string}>
     */
    private function sentMessages(): array
    {
        $messages = [];

        foreach (Http::recorded() as $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $body = json_decode($request->body(), true);
            $this->assertIsArray($body);
            $this->assertSame(1, preg_match('/(\d{6})$/', (string) ($body['text'] ?? ''), $match));
            $this->assertTrue($request->hasHeader('Idempotency-Key'));

            $messages[] = [
                'digits' => $match[1],
                'key' => $request->header('Idempotency-Key')[0],
                'body' => $body,
                'url' => $request->url(),
            ];
        }

        return $messages;
    }

    private function assertCodeIsNotStored(string $digits, CustomerLoginCode $row): void
    {
        foreach ($row->getAttributes() as $value) {
            $this->assertNotSame($digits, $value);
        }
    }
}
