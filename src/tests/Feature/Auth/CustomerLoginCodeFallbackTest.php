<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\CustomerLoginCode;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use App\Services\CustomerLoginCodeService;
use App\Services\ZaptriaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sem remetente entra pelo telefone. Falha temporária não entra e não avisa.
 */
class CustomerLoginCodeFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'dono@example.com';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zaptria.base_url' => 'https://app.zaptria.com.br/api/v1/partner',
            'services.zaptria.key' => 'zpt_live_test_key',
            'services.zaptria.secret' => 'zpt_whsec_test_signing_secret',
            'services.zaptria.owner_email' => self::OWNER,
        ]);

        Mail::fake();

        $this->tenant = Tenant::factory()->create([
            'name' => 'Carol Doces',
            'slug' => 'carol',
        ]);
    }

    #[DataProvider('temporaryFailures')]
    public function test_temporary_failure_leaves_the_person_logged_out_and_sends_no_email(int $status, string $code): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => $code]], $status),
        ]);

        $customer = $this->existingCustomer();

        $this->assertFalse($this->service()->requestCode($this->tenant, '11999998888'));

        $this->assertCount(1, Http::recorded());
        $this->assertStayedOut($customer);
        Mail::assertNothingSent();
    }

    /** @return array<string, array{int, string}> */
    public static function temporaryFailures(): array
    {
        return [
            'rate limited' => [429, 'rate_limited'],
            'bot unavailable' => [502, 'bot_unavailable'],
            'instance busy' => [409, 'instance_busy'],
        ];
    }

    public function test_a_http_500_is_retried_once_with_the_same_key_and_code_then_gives_up(): void
    {
        $seen = [];

        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = $this->capture($request);

            return Http::response(['error' => ['code' => 'internal_error']], 500);
        });

        $customer = $this->existingCustomer();

        $this->assertFalse($this->service()->requestCode($this->tenant, '11999998888'));

        $this->assertSamePair($seen);
        $this->assertStayedOut($customer);
        Mail::assertNothingSent();
    }

    public function test_a_timeout_is_retried_once_with_the_same_key_and_code_then_gives_up(): void
    {
        $seen = [];

        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = $this->capture($request);

            throw new ConnectionException('timed out');
        });

        $customer = $this->existingCustomer();

        $this->assertFalse($this->service()->requestCode($this->tenant, '11999998888'));

        $this->assertSamePair($seen);
        $this->assertStayedOut($customer);
        Mail::assertNothingSent();
    }

    public function test_a_500_followed_by_success_sends_the_same_code_and_does_not_log_in(): void
    {
        $seen = [];

        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = $this->capture($request);

            if (count($seen) === 1) {
                return Http::response(['error' => ['code' => 'internal_error']], 500);
            }

            return Http::response(['id' => '01jd'], 201);
        });

        $this->assertTrue($this->service()->requestCode($this->tenant, '11999998888'));

        $this->assertSamePair($seen);
        $this->assertSame(0, Customer::query()->count());
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertCount(0, cookie()->getQueuedCookies());
        Mail::assertNothingSent();
    }

    #[DataProvider('missingSenders')]
    public function test_missing_sender_logs_in_with_the_phone_and_emails_the_owner(int $status, string $code): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => $code]], $status),
        ]);

        $result = $this->service()->requestCode($this->tenant, '(11) 99999-8888');

        $this->assertEntered($result);
        $this->assertCount(1, Http::recorded());
        $this->assertOwnerMail($this->sentDigits());
    }

    /** @return array<string, array{int, string}> */
    public static function missingSenders(): array
    {
        return [
            'unauthorized' => [401, 'unauthorized'],
            'forbidden' => [403, 'forbidden_scope'],
            'not connected' => [409, 'instance_not_connected'],
        ];
    }

    public function test_missing_credential_logs_in_without_http_and_emails_the_owner(): void
    {
        config(['services.zaptria.key' => '']);
        $this->app->forgetInstance(ZaptriaClient::class);
        $this->app->forgetInstance(CustomerLoginCodeService::class);

        Http::fake();

        $result = $this->service()->requestCode($this->tenant, '11999998888');

        $this->assertEntered($result);
        Http::assertNothingSent();
        $this->assertOwnerMail($this->digitsForRow());
    }

    public function test_missing_owner_email_still_logs_in_and_sends_nothing(): void
    {
        config(['services.zaptria.owner_email' => '']);

        Http::fake([
            '*' => Http::response(['error' => ['code' => 'unauthorized']], 401),
        ]);

        $result = $this->service()->requestCode($this->tenant, '11999998888');

        $this->assertEntered($result);
        Mail::assertNothingSent();
    }

    public function test_each_entry_without_a_code_sends_one_email(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'instance_not_connected']], 409),
        ]);

        $first = $this->service()->requestCode($this->tenant, '11999998888');
        $this->assertEntered($first);

        $second = $this->service()->requestCode($this->tenant, '(11) 99999-8888');
        $this->assertEntered($second);
        $this->assertSame(1, Customer::query()->count());
        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertNotSame($first['session_token'], $second['session_token']);
        Mail::assertSentCount(2);
        Mail::assertSent(Mailable::class, function (Mailable $mail): bool {
            return $mail->hasTo(self::OWNER) && $mail->hasSubject($this->notice());
        });
    }

    public function test_an_inactive_customer_follows_todays_login(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'unauthorized']], 401),
        ]);

        $customer = Customer::factory()->inactive()->create([
            'tenant_id' => $this->tenant->id,
            'phone' => '11999998888',
            'session_token' => 'token-antigo',
        ]);

        $result = $this->service()->requestCode($this->tenant, '11999998888');

        $this->assertIsArray($result);
        $fresh = $customer->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->is_active);
        $this->assertNotSame('token-antigo', $fresh->session_token);
        $this->assertSame($fresh->session_token, $result['session_token']);
        $this->assertSame(1, Customer::query()->count());
        $this->assertNull(
            app(CustomerAuthService::class)->validateSessionToken($this->tenant, $result['session_token'])
        );
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertCount(0, cookie()->getQueuedCookies());
        Mail::assertSentCount(1);
    }

    public function test_an_expired_code_does_not_enter(): void
    {
        $digits = $this->issuedCode();

        $this->travel(11)->minutes();

        $this->assertNull($this->service()->verifyCode($this->tenant, '11999998888', $digits));
        $this->assertSame(0, Customer::query()->count());
        $this->assertNull(CustomerLoginCode::query()->firstOrFail()->consumed_at);
        Mail::assertNothingSent();
    }

    public function test_a_used_code_does_not_enter_again(): void
    {
        $digits = $this->issuedCode();
        $service = $this->service();

        $first = $service->verifyCode($this->tenant, '11999998888', $digits);

        $this->assertNotNull($first);
        $this->assertNull($service->verifyCode($this->tenant, '11999998888', $digits));
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($first['session_token'], Customer::query()->firstOrFail()->session_token);
        Mail::assertNothingSent();
    }

    public function test_a_code_from_another_store_does_not_enter(): void
    {
        $digits = $this->issuedCode();
        $row = CustomerLoginCode::query()->firstOrFail();
        $other = Tenant::factory()->create(['name' => 'Outra Loja', 'slug' => 'outra']);

        $this->assertNull($this->service()->verifyCode($other, '11999998888', $digits));
        $this->assertSame(0, Customer::query()->count());
        $this->assertNull($row->fresh()->consumed_at);
        $this->assertSame(0, $row->fresh()->attempts);
        Mail::assertNothingSent();
    }

    public function test_five_wrong_codes_consume_the_row_and_the_sixth_does_not_enter(): void
    {
        $digits = $this->issuedCode();
        $wrong = $digits === '000000' ? '000001' : '000000';
        $service = $this->service();

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($service->verifyCode($this->tenant, '11999998888', $wrong));
        }

        $row = CustomerLoginCode::query()->firstOrFail();
        $this->assertSame(5, $row->attempts);
        $this->assertNotNull($row->consumed_at);
        $this->assertNull($service->verifyCode($this->tenant, '11999998888', $digits));
        $this->assertNull($service->verifyCode($this->tenant, '11999998888', $wrong));
        $this->assertSame(0, Customer::query()->count());
        Mail::assertNothingSent();
    }

    public function test_four_wrong_codes_still_allow_the_right_one(): void
    {
        $digits = $this->issuedCode();
        $wrong = $digits === '000000' ? '000001' : '000000';
        $service = $this->service();

        for ($i = 0; $i < 4; $i++) {
            $this->assertNull($service->verifyCode($this->tenant, '11999998888', $wrong));
        }

        $row = CustomerLoginCode::query()->firstOrFail();
        $this->assertSame(4, $row->attempts);
        $this->assertNull($row->consumed_at);

        $result = $service->verifyCode($this->tenant, '11999998888', $digits);

        $this->assertNotNull($result);
        $this->assertSame(1, Customer::query()->count());
        $this->assertNotNull($row->fresh()->consumed_at);
        Mail::assertNothingSent();
    }

    private function service(): CustomerLoginCodeService
    {
        return app(CustomerLoginCodeService::class);
    }

    private function existingCustomer(): Customer
    {
        return Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'phone' => '11999998888',
            'session_token' => 'token-antigo',
        ]);
    }

    private function assertStayedOut(Customer $customer): void
    {
        $this->assertSame('token-antigo', $customer->fresh()->session_token);
        $this->assertSame(1, Customer::query()->count());
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertCount(0, cookie()->getQueuedCookies());
        $this->assertNull(CustomerLoginCode::query()->firstOrFail()->consumed_at);
    }

    /**
     * @param  array{customer: Customer, session_token: string}|bool  $result
     */
    private function assertEntered(array|bool $result): void
    {
        $this->assertIsArray($result);
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertCount(0, cookie()->getQueuedCookies());

        $customer = Customer::query()->where('phone', '11999998888')->first();
        $this->assertNotNull($customer);
        $this->assertSame($customer->id, $result['customer']->id);
        $this->assertSame($customer->session_token, $result['session_token']);
        $this->assertNotNull(
            app(CustomerAuthService::class)->validateSessionToken($this->tenant, $result['session_token'])
        );
    }

    private function assertOwnerMail(string $digits): void
    {
        Mail::assertSent(Mailable::class, function (Mailable $mail) use ($digits): bool {
            $body = $mail->render();

            return $mail->hasTo(self::OWNER)
                && $mail->hasSubject($this->notice())
                && str_contains($body, $this->notice())
                && ! str_contains($body, $digits);
        });
        Mail::assertSentCount(1);
    }

    private function notice(): string
    {
        return 'Carol Doces: a entrada foi sem código porque nenhum WhatsApp pôde enviar.';
    }

    private function issuedCode(): string
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $this->assertTrue($this->service()->requestCode($this->tenant, '11999998888'));

        return $this->sentDigits();
    }

    private function sentDigits(): string
    {
        /** @var Request $request */
        $request = Http::recorded()->last()[0];
        $body = json_decode($request->body(), true);
        $this->assertIsArray($body);
        $this->assertSame(1, preg_match('/(\d{6})$/', (string) ($body['text'] ?? ''), $match));

        return $match[1];
    }

    private function digitsForRow(): string
    {
        $key = (string) CustomerLoginCode::query()->firstOrFail()->idempotency_key;
        $digest = hash_hmac('sha256', $key, (string) config('app.key'));
        $number = (int) hexdec(substr($digest, 0, 8)) % 1000000;

        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{digits: string, key: string, body: array<string, mixed>}
     */
    private function capture(Request $request): array
    {
        $body = json_decode($request->body(), true);
        $this->assertIsArray($body);
        $this->assertSame(1, preg_match('/(\d{6})$/', (string) ($body['text'] ?? ''), $match));
        $this->assertTrue($request->hasHeader('Idempotency-Key'));
        $key = $request->header('Idempotency-Key')[0];
        $this->assertIsString($key);

        return [
            'digits' => $match[1],
            'key' => $key,
            'body' => $body,
        ];
    }

    /**
     * @param  list<array{digits: string, key: string, body: array<string, mixed>}>  $seen
     */
    private function assertSamePair(array $seen): void
    {
        $this->assertCount(2, $seen);
        $this->assertSame($seen[0]['digits'], $seen[1]['digits']);
        $this->assertSame($seen[0]['key'], $seen[1]['key']);
        $this->assertSame($seen[0]['body'], $seen[1]['body']);
    }
}
