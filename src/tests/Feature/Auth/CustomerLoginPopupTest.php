<?php

namespace Tests\Feature\Auth;

use App\Livewire\Tenant\Auth\LoginPopup;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use App\Services\CustomerLoginCodeService;
use App\Services\ZaptriaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * O popup pede o código no segundo passo. Os seis dígitos não saem na HTML:
 * o teste lê o corpo gravado por Http::fake. Fechar no primeiro passo não entra.
 */
class CustomerLoginPopupTest extends TestCase
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

        $this->app->forgetInstance(ZaptriaClient::class);
        $this->app->forgetInstance(CustomerLoginCodeService::class);

        Mail::fake();

        $this->tenant = Tenant::factory()->create([
            'name' => 'Carol Doces',
            'slug' => 'carol',
            'is_active' => true,
        ]);
    }

    public function test_the_rendered_popup_does_not_contain_the_code(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $component = $this->popup()
            ->set('phone', '(11) 99999-8888')
            ->call('login')
            ->assertHasNoErrors()
            ->assertSet('awaitingCode', true)
            ->assertSet('show', true)
            ->assertSee('Código');

        $digits = $this->sentDigits();
        $html = $component->html();

        $this->assertSame('auto', $this->sentBody()['sender']);
        $this->assertSame('otp', $this->sentBody()['purpose']);
        $this->assertSame('Carol Doces: seu código é '.$digits, $this->sentBody()['text']);
        $this->assertStringNotContainsString($digits, $html);
        $this->assertStringNotContainsString('wire:model', $html);
        $this->assertStringContainsString('confirm($event.target.code.value)', $html);
        $this->assertFalse((new \ReflectionClass(LoginPopup::class))->hasProperty('code'));
        $this->assertSame(0, Customer::query()->count());
        $this->assertNull($this->sessionCookie());
        Mail::assertNothingSent();
    }

    public function test_the_right_code_opens_todays_session_and_closes_the_popup(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $component = $this->popup()
            ->set('phone', '(11) 99999-8888')
            ->call('login');

        $digits = $this->sentDigits();

        $component->call('confirm', $digits)
            ->assertHasNoErrors()
            ->assertSet('show', false)
            ->assertSet('awaitingCode', false);

        $this->assertSame(1, Customer::query()->count());
        $customer = Customer::query()->where('phone', '11999998888')->first();
        $this->assertNotNull($customer);

        $cookie = $this->sessionCookie();
        $this->assertNotNull($cookie);
        $this->assertSame($customer->session_token, $cookie->getValue());
        $this->assertNotNull(
            app(CustomerAuthService::class)->validateSessionToken($this->tenant, $cookie->getValue())
        );
        $this->assertStringNotContainsString($digits, $component->html());
    }

    public function test_a_wrong_code_does_not_enter(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $component = $this->popup()
            ->set('phone', '11999998888')
            ->call('login');

        $digits = $this->sentDigits();
        $wrong = $digits === '000000' ? '000001' : '000000';

        $component->call('confirm', $wrong)
            ->assertSet('show', true)
            ->assertSet('awaitingCode', true)
            ->assertSee('Esse código não confere.');

        $this->assertSame(0, Customer::query()->count());
        $this->assertNull($this->sessionCookie());
        $this->assertStringNotContainsString($digits, $component->html());
    }

    public function test_dismiss_on_the_first_step_does_not_call_http_or_enter(): void
    {
        Http::fake();

        $this->popup()
            ->assertSet('show', true)
            ->assertSet('awaitingCode', false)
            ->call('dismiss')
            ->assertSet('show', false);

        Http::assertNothingSent();
        $this->assertSame(0, Customer::query()->count());
        $this->assertNull($this->sessionCookie());
    }

    public function test_missing_sender_queues_the_cookie_without_the_code_step(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'instance_not_connected']], 409),
        ]);

        $component = $this->popup()
            ->set('phone', '(11) 99999-8888')
            ->call('login')
            ->assertHasNoErrors()
            ->assertSet('show', false)
            ->assertSet('awaitingCode', false);

        $this->assertStringNotContainsString('name="code"', $component->html());
        $this->assertSame(1, Customer::query()->count());
        $this->assertNotNull($this->sessionCookie());
        Mail::assertNothingSent();
    }

    public function test_missing_credential_enters_without_http_or_the_code_step(): void
    {
        config(['services.zaptria.key' => '']);
        $this->app->forgetInstance(ZaptriaClient::class);
        $this->app->forgetInstance(CustomerLoginCodeService::class);

        Http::fake();

        $this->popup()
            ->set('phone', '11999998888')
            ->call('login')
            ->assertHasNoErrors()
            ->assertSet('show', false)
            ->assertSet('awaitingCode', false);

        Http::assertNothingSent();
        $this->assertSame(1, Customer::query()->count());
        $this->assertNotNull($this->sessionCookie());
    }

    public function test_a_temporary_failure_stays_logged_out_and_asks_to_wait(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['code' => 'rate_limited']], 429),
        ]);

        $this->popup()
            ->set('phone', '(11) 99999-8888')
            ->call('login')
            ->assertSet('show', true)
            ->assertSet('awaitingCode', false)
            ->assertSee('Espere um pouco ou peça de novo.');

        $this->assertSame(0, Customer::query()->count());
        $this->assertNull($this->sessionCookie());
        Http::assertSentCount(1);
        Mail::assertNothingSent();
    }

    private function popup(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(LoginPopup::class, ['tenant' => $this->tenant]);
    }

    private function sessionCookie(): ?Cookie
    {
        $name = app(CustomerAuthService::class)->getCookieName($this->tenant);

        foreach (cookie()->getQueuedCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        /** @var Request $request */
        $request = Http::recorded()->first()[0];
        $body = json_decode($request->body(), true);
        $this->assertIsArray($body);

        return $body;
    }

    private function sentDigits(): string
    {
        $this->assertSame(1, preg_match('/(\d{6})$/', (string) ($this->sentBody()['text'] ?? ''), $match));

        return $match[1];
    }
}
