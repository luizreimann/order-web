<?php

namespace Tests\Unit\Services;

use App\Services\ZaptriaClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZaptriaClientTest extends TestCase
{
    private string $secret = 'zpt_whsec_test_signing_secret';

    private string $key = 'zpt_live_test_key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zaptria.base_url' => 'https://app.zaptria.com.br/api/v1/partner',
            'services.zaptria.key' => $this->key,
            'services.zaptria.secret' => $this->secret,
            'services.zaptria.owner_email' => '',
        ]);
    }

    public function test_message_signature_covers_timestamp_method_path_idempotency_key_and_raw_body(): void
    {
        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $body = [
            'to' => '11999998888',
            'text' => 'Carol Doces: seu código é 482910 / pedido',
            'sender' => 'auto',
            'purpose' => 'otp',
        ];
        $idempotencyKey = '6f1d3c1a-5b2e-4f0a-9a51-0c0d7e1f2a3b';

        $response = (new ZaptriaClient)->sendMessage('9f2c1d1e', $body, $idempotencyKey);

        $this->assertNotNull($response);
        $this->assertCount(1, Http::recorded());

        $request = $this->recordedRequest();
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame('POST', $request->method());
        $this->assertSame($raw, $request->body());
        $this->assertStringNotContainsString('\\u00', (string) $raw);
        $this->assertStringNotContainsString('\\/', (string) $raw);
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.$this->key));
        $this->assertTrue($request->hasHeader('Idempotency-Key', $idempotencyKey));
        $this->assertTrue($request->hasHeader('X-Zaptria-Timestamp'));
        $this->assertEqualsWithDelta(time(), (int) $request->header('X-Zaptria-Timestamp')[0], 2);
        $this->assertStringContainsString(
            '/api/v1/partner/tenants/9f2c1d1e/messages',
            $request->url(),
        );
        $this->assertSignatureMatchesRecordedRequest($request);
    }

    public function test_post_message_sends_an_idempotency_key_when_the_caller_omits_one(): void
    {
        Http::fake();

        (new ZaptriaClient)->sendMessage('ext', ['text' => 'oi']);

        $request = $this->recordedRequest();

        $this->assertTrue($request->hasHeader('Idempotency-Key'));
        $this->assertNotSame('', $request->header('Idempotency-Key')[0]);
        $this->assertSignatureMatchesRecordedRequest($request);
    }

    public function test_request_without_idempotency_key_signs_an_empty_key_and_an_empty_body(): void
    {
        Http::fake();

        (new ZaptriaClient)->request('get', '/tenants/abc/whatsapp?since=1');

        $request = $this->recordedRequest();

        $this->assertSame('GET', $request->method());
        $this->assertSame('', $request->body());
        $this->assertFalse($request->hasHeader('Idempotency-Key'));
        $this->assertStringContainsString('/api/v1/partner/tenants/abc/whatsapp?since=1', $request->url());
        $this->assertSignatureMatchesRecordedRequest($request);
    }

    public function test_trailing_slash_on_the_base_url_does_not_double_the_path(): void
    {
        config(['services.zaptria.base_url' => 'https://app.zaptria.com.br/api/v1/partner/']);
        Http::fake();

        (new ZaptriaClient)->request('GET', '/tenants/abc');

        $request = $this->recordedRequest();

        $this->assertStringContainsString('/api/v1/partner/tenants/abc', $request->url());
        $this->assertStringNotContainsString('partner//tenants', $request->url());
        $this->assertSignatureMatchesRecordedRequest($request);
    }

    public function test_timeout_is_fifteen_seconds_and_connect_timeout_is_ten(): void
    {
        $options = null;

        Http::fake(function ($request, array $seen) use (&$options) {
            $options = $seen;

            return Http::response(['ok' => true], 201);
        });

        (new ZaptriaClient)->sendMessage('ext', ['text' => 'oi'], 'idem-1');

        $this->assertIsArray($options);
        $this->assertSame(15, $options['timeout']);
        $this->assertSame(10, $options['connect_timeout']);
        $this->assertCount(1, Http::recorded());
    }

    #[DataProvider('missingCredentials')]
    public function test_missing_credential_does_not_call_http(string $name, ?string $value): void
    {
        config(['services.zaptria.'.$name => $value]);
        Http::fake();

        $this->assertNull((new ZaptriaClient)->sendMessage('ext', ['text' => 'oi']));
        $this->assertNull((new ZaptriaClient)->request('GET', '/tenants/ext'));

        $this->assertCount(0, Http::recorded());
    }

    /** @return array<string, array{string, string|null}> */
    public static function missingCredentials(): array
    {
        return [
            'sem base_url' => ['base_url', ''],
            'sem key' => ['key', ''],
            'sem secret' => ['secret', ''],
            'base_url null' => ['base_url', null],
            'key só com espaços' => ['key', '   '],
            'secret null' => ['secret', null],
        ];
    }

    private function recordedRequest(): Request
    {
        $pair = Http::recorded()->first();
        $this->assertNotNull($pair);

        return $pair[0];
    }

    /**
     * Remonta a string canônica (timestamp, método, path com query, Idempotency-Key, corpo cru)
     * a partir do que foi enviado e compara com o header.
     */
    private function assertSignatureMatchesRecordedRequest(Request $request): void
    {
        $parts = parse_url($request->url());
        $this->assertIsArray($parts);

        $path = $parts['path'] ?? '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?'.$parts['query'];
        }

        $idempotencyKey = $request->hasHeader('Idempotency-Key')
            ? $request->header('Idempotency-Key')[0]
            : '';

        $canonical = implode("\n", [
            $request->header('X-Zaptria-Timestamp')[0],
            $request->method(),
            $path,
            $idempotencyKey,
            $request->body(),
        ]);

        $this->assertSame(
            'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            $request->header('X-Zaptria-Signature')[0],
        );
    }
}
