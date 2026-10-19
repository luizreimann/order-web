<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EncryptionHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E3.6 (ARC-006) — credenciais do Mercado Pago são WRITE-ONLY no admin.
 *
 * Propriedades públicas do Livewire vão no snapshot (HTML e payload de cada update).
 * Estes testes batem no ENDPOINT HTTP REAL (`POST /livewire/update`) e provam que o
 * token/segredo em claro nunca aparece no HTML, no snapshot, nas respostas nem nos logs.
 */
class PaymentSettingsWriteOnlyCredentialsTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private const TOKEN = 'APP_USR-1234567890-SECRETTOKEN-9876';

    private const WEBHOOK = 'whsec_ABCDEFGHIJKLMNOP-4321';

    private const URL = '/tenant-a/admin/pagamentos';

    private const COMPONENT = 'admin.tenant.payment-settings';

    private Tenant $tenant;

    private User $admin;

    private int $logOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'slug' => 'tenant-a',
            'is_active' => true,
            'settings' => ['payment' => ['mercadopago' => [
                'public_key' => 'APP_USR-public-key-visible',
                'access_token' => EncryptionHelper::encryptIfNeeded(self::TOKEN),
                'webhook_secret' => EncryptionHelper::encryptIfNeeded(self::WEBHOOK),
            ]]],
        ]);

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($this->admin, 'tenant_admin');

        $this->logOffset = $this->logSize();
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function logPath(): string
    {
        return storage_path('logs/laravel.log');
    }

    private function logSize(): int
    {
        return is_file($this->logPath()) ? (int) filesize($this->logPath()) : 0;
    }

    private function newLogContents(): string
    {
        if (! is_file($this->logPath())) {
            return '';
        }

        return (string) file_get_contents($this->logPath(), false, null, $this->logOffset);
    }

    /** @return array{0: string, 1: string} [snapshot, html] da página já na aba de integrações */
    private function openIntegrationsTab(): array
    {
        $page = $this->get(self::URL)->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $page, self::COMPONENT);

        $response = $this->callLivewire($snapshot, 'setTab', ['integrations'])->assertOk();

        return [(string) $response->json('components.0.snapshot'), (string) $response->json('components.0.effects.html')];
    }

    private function assertNoSecretLeak(string $haystack, string $where): void
    {
        foreach ([self::TOKEN, self::WEBHOOK, base64_encode(self::TOKEN), base64_encode(self::WEBHOOK)] as $secret) {
            $this->assertStringNotContainsString($secret, $haystack, "Segredo em claro vazou em: {$where}");
        }
    }

    private function assertResponseHasNoSecret(TestResponse $response, string $where): void
    {
        $this->assertNoSecretLeak((string) $response->getContent(), $where);
    }

    /** @return array<string, mixed> */
    private function storedMercadoPago(): array
    {
        return (array) data_get(Tenant::query()->findOrFail($this->tenant->id)->settings, 'payment.mercadopago', []);
    }

    private function storedToken(): ?string
    {
        return EncryptionHelper::decryptIfNeeded($this->storedMercadoPago()['access_token'] ?? null);
    }

    private function storedWebhook(): ?string
    {
        return EncryptionHelper::decryptIfNeeded($this->storedMercadoPago()['webhook_secret'] ?? null);
    }

    // ------------------------------------------------------------------
    // 1) segredo fora de HTML / snapshot, antes e depois de update
    // ------------------------------------------------------------------

    public function test_secrets_are_not_in_page_html_nor_snapshot_before_and_after_updates(): void
    {
        $pageResponse = $this->get(self::URL)->assertOk();
        $this->assertResponseHasNoSecret($pageResponse, 'HTML inicial da página (inclui wire:snapshot)');

        $snapshot = $this->snapshotFor((string) $pageResponse->getContent(), self::COMPONENT);
        $this->assertNoSecretLeak($snapshot, 'snapshot inicial');

        $tabResponse = $this->callLivewire($snapshot, 'setTab', ['integrations'])->assertOk();
        $this->assertResponseHasNoSecret($tabResponse, 'resposta do update (setTab integrations)');
        $this->assertNoSecretLeak((string) $tabResponse->json('components.0.snapshot'), 'snapshot do update');
        $this->assertNoSecretLeak((string) $tabResponse->json('components.0.effects.html'), 'HTML renderizado do update');

        $afterSnapshot = (string) $tabResponse->json('components.0.snapshot');
        $data = $this->unwrap($this->returnedData($tabResponse));
        foreach (['mp_access_token', 'mp_webhook_secret'] as $property) {
            $this->assertArrayNotHasKey($property, $data, "Propriedade pública [{$property}] não pode existir no estado.");
        }

        $refresh = $this->refreshLivewire($afterSnapshot)->assertOk();
        $this->assertResponseHasNoSecret($refresh, 'resposta de um re-render puro');

        // A public key NÃO é segredo: continua editável e visível.
        $this->assertStringContainsString('APP_USR-public-key-visible', (string) $tabResponse->json('components.0.snapshot'));
    }

    public function test_typed_secret_is_never_echoed_back_in_state_or_html(): void
    {
        [$snapshot] = $this->openIntegrationsTab();

        $typed = 'APP_USR-DIGITADO-NOVO-TOKEN-5555';
        $response = $this->callLivewire($snapshot, 'saveIntegrations', [$typed, 'whsec_DIGITADO-7777'])->assertOk();

        foreach ([$typed, 'whsec_DIGITADO-7777'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
        }
    }

    public function test_mask_shows_at_most_the_last_four_characters(): void
    {
        [, $html] = $this->openIntegrationsTab();

        $this->assertStringContainsString('••••9876', $html);
        $this->assertStringContainsString('••••4321', $html);

        // Nada além dos 4 últimos: nem 5 caracteres finais, nem o prefixo.
        $this->assertStringNotContainsString('N-9876', $html);
        $this->assertStringNotContainsString('P-4321', $html);
        $this->assertStringNotContainsString('APP_USR-1234', $html);
        $this->assertStringNotContainsString('whsec_', $html);
    }

    public function test_short_secrets_are_fully_masked(): void
    {
        $this->tenant->settings = ['payment' => ['mercadopago' => [
            'public_key' => 'PUB',
            'access_token' => EncryptionHelper::encryptIfNeeded('abc123'),
        ]]];
        $this->tenant->save();

        [, $html] = $this->openIntegrationsTab();

        $this->assertStringContainsString('••••', $html);
        $this->assertStringNotContainsString('abc123', $html);
        $this->assertStringNotContainsString('c123', $html);
    }

    // ------------------------------------------------------------------
    // 2) salvar preserva / substitui / limpa
    // ------------------------------------------------------------------

    public function test_saving_with_empty_secret_fields_preserves_stored_credentials(): void
    {
        [$snapshot] = $this->openIntegrationsTab();
        $rawBefore = $this->storedMercadoPago();

        $this->callLivewire($snapshot, 'saveIntegrations', ['', ''], ['mp_public_key' => 'APP_USR-nova-public'])->assertOk();

        $after = $this->storedMercadoPago();
        $this->assertSame('APP_USR-nova-public', $after['public_key']);
        $this->assertSame(self::TOKEN, $this->storedToken());
        $this->assertSame(self::WEBHOOK, $this->storedWebhook());
        $this->assertSame($rawBefore['access_token'], $after['access_token']);
        $this->assertSame($rawBefore['webhook_secret'], $after['webhook_secret']);
    }

    public function test_saving_without_params_also_preserves_stored_credentials(): void
    {
        [$snapshot] = $this->openIntegrationsTab();

        $this->callLivewire($snapshot, 'saveIntegrations')->assertOk();

        $this->assertSame(self::TOKEN, $this->storedToken());
        $this->assertSame(self::WEBHOOK, $this->storedWebhook());
    }

    public function test_saving_a_new_value_replaces_and_stores_it_encrypted(): void
    {
        [$snapshot] = $this->openIntegrationsTab();

        $this->callLivewire($snapshot, 'saveIntegrations', ['APP_USR-NOVO-TOKEN-0001', 'whsec_NOVO-0002'])->assertOk();

        $stored = $this->storedMercadoPago();
        $this->assertStringStartsWith('enc:', (string) $stored['access_token']);
        $this->assertStringStartsWith('enc:', (string) $stored['webhook_secret']);
        $this->assertStringNotContainsString('NOVO-TOKEN-0001', (string) $stored['access_token']);
        $this->assertStringNotContainsString('NOVO-0002', (string) $stored['webhook_secret']);
        $this->assertSame('APP_USR-NOVO-TOKEN-0001', $this->storedToken());
        $this->assertSame('whsec_NOVO-0002', $this->storedWebhook());
    }

    public function test_replacing_only_the_token_keeps_the_webhook_secret(): void
    {
        [$snapshot] = $this->openIntegrationsTab();

        $this->callLivewire($snapshot, 'saveIntegrations', ['APP_USR-SO-TOKEN-0003', ''])->assertOk();

        $this->assertSame('APP_USR-SO-TOKEN-0003', $this->storedToken());
        $this->assertSame(self::WEBHOOK, $this->storedWebhook());
    }

    public function test_explicit_clear_removes_only_the_chosen_credential(): void
    {
        [$snapshot] = $this->openIntegrationsTab();

        $this->callLivewire($snapshot, 'clearWebhookSecret')->assertOk();

        $this->assertNull($this->storedMercadoPago()['webhook_secret'] ?? null);
        $this->assertSame(self::TOKEN, $this->storedToken());

        $this->callLivewire($snapshot, 'clearAccessToken')->assertOk();

        $this->assertNull($this->storedMercadoPago()['access_token'] ?? null);
        $this->assertSame('APP_USR-public-key-visible', $this->storedMercadoPago()['public_key']);
    }

    public function test_oversized_secret_is_rejected_and_changes_nothing(): void
    {
        [$snapshot] = $this->openIntegrationsTab();

        $response = $this->callLivewire($snapshot, 'saveIntegrations', [str_repeat('x', 300), ''])->assertOk();

        $this->assertSame(self::TOKEN, $this->storedToken());
        $this->assertStringNotContainsString(str_repeat('x', 300), (string) $response->getContent());
        // O erro de validação é renderizado (sem ecoar o valor), no padrão <x-form-field>
        // (E4.2): `invalid-feedback` irmão do input, que também recebe `is-invalid`.
        $html = (string) $response->json('components.0.effects.html');
        $this->assertStringContainsString('invalid-feedback', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="mp_access_token"[^>]*class="[^"]*\bis-invalid\b/', $html);
    }

    // ------------------------------------------------------------------
    // 3) testMercadoPagoConnection
    // ------------------------------------------------------------------

    public function test_connection_test_uses_the_stored_token_when_the_field_is_empty(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        [$snapshot] = $this->openIntegrationsTab();

        $response = $this->callLivewire($snapshot, 'testMercadoPagoConnection', [''])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        $this->assertSame('connected', $this->unwrap($this->returnedData($response))['mpConnectionStatus'] ?? null);
        $this->assertResponseHasNoSecret($response, 'resposta do teste de conexão (token salvo)');
    }

    public function test_connection_test_uses_the_typed_token_when_filled(): void
    {
        Http::fake(['*' => Http::response([], 401)]);
        [$snapshot] = $this->openIntegrationsTab();
        $typed = 'APP_USR-DIGITADO-TESTE-4242';

        $response = $this->callLivewire($snapshot, 'testMercadoPagoConnection', [$typed])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.$typed));
        $this->assertSame('failed', $this->unwrap($this->returnedData($response))['mpConnectionStatus'] ?? null);
        $this->assertStringNotContainsString($typed, (string) $response->getContent());
        // O teste NÃO persiste a credencial digitada.
        $this->assertSame(self::TOKEN, $this->storedToken());
    }

    public function test_connection_test_exception_does_not_leak_the_token(): void
    {
        Http::fake(function (Request $request) {
            throw new \RuntimeException('boom '.$request->header('Authorization')[0]);
        });
        [$snapshot] = $this->openIntegrationsTab();

        $response = $this->callLivewire($snapshot, 'testMercadoPagoConnection', [''])->assertOk();

        $this->assertSame('failed', $this->unwrap($this->returnedData($response))['mpConnectionStatus'] ?? null);
        $this->assertResponseHasNoSecret($response, 'resposta com exceção no teste de conexão');
        $this->assertNoSecretLeak($this->newLogContents(), 'storage/logs durante exceção');
    }

    public function test_connection_test_without_any_token_reports_missing_credentials(): void
    {
        Http::fake();
        $this->tenant->settings = ['payment' => ['mercadopago' => ['public_key' => 'PUB']]];
        $this->tenant->save();
        [$snapshot] = $this->openIntegrationsTab();

        $response = $this->callLivewire($snapshot, 'testMercadoPagoConnection', [''])->assertOk();

        Http::assertNothingSent();
        $this->assertSame('missing_credentials', $this->unwrap($this->returnedData($response))['mpConnectionStatus'] ?? null);
    }

    // ------------------------------------------------------------------
    // 4) logs
    // ------------------------------------------------------------------

    public function test_logs_never_contain_the_secrets_during_the_whole_flow(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        [$snapshot] = $this->openIntegrationsTab();

        $this->callLivewire($snapshot, 'testMercadoPagoConnection', [''])->assertOk();
        $this->callLivewire($snapshot, 'saveIntegrations', ['APP_USR-LOGCHECK-1111', ''])->assertOk();

        $log = $this->newLogContents();
        $this->assertNoSecretLeak($log, 'storage/logs');
        $this->assertStringNotContainsString('LOGCHECK-1111', $log);
    }

    // ------------------------------------------------------------------
    // 5) autorização (manageIntegrations)
    // ------------------------------------------------------------------

    public function test_user_without_manage_integrations_neither_loads_nor_changes_credentials(): void
    {
        // Pode gerir métodos de pagamento (abre a página), mas NÃO integrações.
        Gate::before(fn ($user, string $ability) => $ability === 'manageIntegrations' ? false : null);
        Http::fake();

        $pageResponse = $this->get(self::URL)->assertOk();
        $this->assertResponseHasNoSecret($pageResponse, 'HTML sem permissão');
        $this->assertStringNotContainsString('APP_USR-public-key-visible', (string) $pageResponse->getContent());

        $snapshot = $this->snapshotFor((string) $pageResponse->getContent(), self::COMPONENT);
        $before = $this->storedMercadoPago();

        $this->callLivewire($snapshot, 'saveIntegrations', ['APP_USR-HACK-0000', 'whsec_HACK'])->assertForbidden();
        $this->callLivewire($snapshot, 'clearAccessToken')->assertForbidden();
        $this->callLivewire($snapshot, 'clearWebhookSecret')->assertForbidden();
        $this->callLivewire($snapshot, 'testMercadoPagoConnection', ['APP_USR-HACK-0000'])->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame($before, $this->storedMercadoPago());
        $this->assertSame(self::TOKEN, $this->storedToken());
    }

    public function test_user_without_manage_integrations_does_not_see_the_mask_after_a_refresh(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'manageIntegrations' ? false : null);

        $snapshot = $this->snapshotFor((string) $this->get(self::URL)->assertOk()->getContent(), self::COMPONENT);
        $response = $this->refreshLivewire($snapshot)->assertOk();

        $this->assertResponseHasNoSecret($response, 'refresh sem permissão');
        $this->assertStringNotContainsString('••••', (string) $response->getContent());
    }
}
