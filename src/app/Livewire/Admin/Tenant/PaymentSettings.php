<?php

namespace App\Livewire\Admin\Tenant;

use App\Enums\PaymentMethod;
use App\Gateways\MercadoPagoGateway;
use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\EncryptionHelper;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class PaymentSettings extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public string $activeTab = 'methods';

    public array $payment_methods = [];

    public ?string $mp_public_key = null;

    // ARC-006: `access_token` e `webhook_secret` são WRITE-ONLY. NÃO existe propriedade pública para
    // eles: o valor digitado chega como PARÂMETRO da action (nunca entra no snapshot) e o valor salvo
    // só é lido no servidor (máscara via computed property, que também não é serializada).
    private const SECRET_MAX_LENGTH = 255;

    public ?string $mpConnectionStatus = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->authorizeView();
        $this->loadPaymentMethods();

        // Credenciais só são carregadas (e expostas ao front) para quem pode geri-las.
        if (Gate::allows('manageIntegrations', $tenant)) {
            $this->loadIntegrations();
        }
    }

    public function setTab(string $tab): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);

        $this->activeTab = $tab;
    }

    private function loadPaymentMethods(): void
    {
        $methods = data_get($this->tenant->settings, 'payment.methods');

        if (is_array($methods) && count($methods) > 0) {
            $this->payment_methods = array_values($methods);
        } else {
            $this->payment_methods = PaymentMethod::defaultConfig();
        }

        $this->payment_methods = $this->normalizePaymentMethods($this->payment_methods);
    }

    private function loadIntegrations(): void
    {
        $mpSettings = (array) data_get($this->tenant->settings, 'payment.mercadopago', []);

        // A public key não é segredo (vai ao frontend no checkout): segue editável e visível.
        $this->mp_public_key = data_get($mpSettings, 'public_key');
    }

    /** Credencial salva (decifrada) — uso estritamente local ao servidor; nunca atribuir a propriedade pública. */
    private function storedSecret(string $key): ?string
    {
        return EncryptionHelper::decryptIfNeeded(data_get($this->tenant->settings, "payment.mercadopago.{$key}"));
    }

    /**
     * Máscara para exibição: no máximo os 4 últimos caracteres. Segredos curtos (< 8) não revelam nada,
     * para que os 4 últimos não sejam a maior parte do valor.
     */
    private function maskSecret(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return mb_strlen($secret) >= 8 ? '••••'.mb_substr($secret, -4) : '••••';
    }

    public function getMpAccessTokenMaskProperty(): ?string
    {
        return Gate::allows('manageIntegrations', $this->tenant)
            ? $this->maskSecret($this->storedSecret('access_token'))
            : null;
    }

    public function getMpWebhookSecretMaskProperty(): ?string
    {
        return Gate::allows('manageIntegrations', $this->tenant)
            ? $this->maskSecret($this->storedSecret('webhook_secret'))
            : null;
    }

    private function normalizePaymentMethods(array $methods): array
    {
        $seen = [];
        $normalized = [];

        foreach ($methods as $method) {
            if (! is_array($method)) {
                continue;
            }
            $id = (string) ($method['id'] ?? '');
            if (! $id) {
                continue;
            }
            if (! in_array($id, PaymentMethod::allIds(), true)) {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $normalized[] = [
                'id' => $id,
                'provider' => (string) ($method['provider'] ?? (PaymentMethod::isMercadoPagoId($id) ? 'mercadopago' : 'manual')),
                'label' => (string) ($method['label'] ?? (PaymentMethod::tryFromId($id)?->label() ?? $id)),
                'enabled' => (bool) ($method['enabled'] ?? false),
                'instructions' => $method['instructions'] ?? null,
                'config' => is_array($method['config'] ?? null) ? $method['config'] : [],
            ];
        }

        foreach (PaymentMethod::defaultConfig() as $default) {
            $id = $default['id'];
            if (! isset($seen[$id])) {
                $normalized[] = $default;
            }
        }

        return array_values($normalized);
    }

    public function isMercadoPagoIntegrated(): bool
    {
        return Gate::allows('manageIntegrations', $this->tenant)
            && $this->mp_public_key
            && $this->storedSecret('access_token') !== null;
    }

    public function getManualMethodsProperty(): array
    {
        return array_values(array_filter($this->payment_methods, fn ($m) => ! PaymentMethod::isMercadoPagoId($m['id'])));
    }

    public function getMercadoPagoMethodsProperty(): array
    {
        return array_values(array_filter($this->payment_methods, fn ($m) => PaymentMethod::isMercadoPagoId($m['id'])));
    }

    public function getEnabledMethodsProperty(): array
    {
        $methods = array_filter($this->payment_methods, function ($m) {
            if (! ($m['enabled'] ?? false)) {
                return false;
            }
            if (PaymentMethod::isMercadoPagoId($m['id']) && ! $this->isMercadoPagoIntegrated()) {
                return false;
            }

            return true;
        });

        return array_values($methods);
    }

    public function toggleMethod(string $methodId): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);

        foreach ($this->payment_methods as $index => $method) {
            if ($method['id'] === $methodId) {
                $this->payment_methods[$index]['enabled'] = ! $method['enabled'];
                break;
            }
        }
    }

    public function moveMethodUp(string $methodId): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);

        $index = $this->findMethodIndex($methodId);
        if ($index === null || $index <= 0) {
            return;
        }

        $temp = $this->payment_methods[$index - 1];
        $this->payment_methods[$index - 1] = $this->payment_methods[$index];
        $this->payment_methods[$index] = $temp;
        $this->payment_methods = array_values($this->payment_methods);
    }

    public function moveMethodDown(string $methodId): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);

        $index = $this->findMethodIndex($methodId);
        if ($index === null || $index >= count($this->payment_methods) - 1) {
            return;
        }

        $temp = $this->payment_methods[$index + 1];
        $this->payment_methods[$index + 1] = $this->payment_methods[$index];
        $this->payment_methods[$index] = $temp;
        $this->payment_methods = array_values($this->payment_methods);
    }

    public function reorderMethod(string $methodId, int $oldIndex, int $newIndex): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);

        if ($oldIndex === $newIndex) {
            return;
        }
        if (! isset($this->payment_methods[$oldIndex])) {
            return;
        }

        $method = $this->payment_methods[$oldIndex];
        array_splice($this->payment_methods, $oldIndex, 1);
        array_splice($this->payment_methods, $newIndex, 0, [$method]);
        $this->payment_methods = array_values($this->payment_methods);
    }

    private function findMethodIndex(string $methodId): ?int
    {
        foreach ($this->payment_methods as $index => $method) {
            if ($method['id'] === $methodId) {
                return $index;
            }
        }

        return null;
    }

    public function updateMethodLabel(string $methodId, string $label): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);

        $index = $this->findMethodIndex($methodId);
        if ($index !== null) {
            $this->payment_methods[$index]['label'] = $label;
        }
    }

    public function updateMethodInstructions(string $methodId, ?string $instructions): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);

        $index = $this->findMethodIndex($methodId);
        if ($index !== null) {
            $this->payment_methods[$index]['instructions'] = $instructions;
        }
    }

    public function updatePixKey(string $pixKey): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);

        $index = $this->findMethodIndex('pix_manual');
        if ($index !== null) {
            $this->payment_methods[$index]['config']['pix_key'] = $pixKey;
        }
    }

    protected function methodsRules(): array
    {
        return [
            'payment_methods' => ['array'],
            'payment_methods.*.id' => ['required', Rule::in(PaymentMethod::allIds())],
            'payment_methods.*.label' => ['required', 'string', 'min:2', 'max:80'],
            'payment_methods.*.enabled' => ['boolean'],
            'payment_methods.*.instructions' => ['nullable', 'string', 'max:1500'],
            'payment_methods.*.config' => ['array'],
            'payment_methods.*.config.pix_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function integrationsRules(): array
    {
        return [
            'mp_public_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Valida segredos recebidos por parâmetro (fora do estado do componente). As mensagens de erro
     * do Laravel não ecoam o valor. Retorna o valor limpo ('' = não informado) ou null se inválido.
     */
    private function validatedSecret(?string $value, string $errorKey): ?string
    {
        $value = trim((string) $value);

        $validator = Validator::make([$errorKey => $value], [$errorKey => ['nullable', 'string', 'max:'.self::SECRET_MAX_LENGTH]]);

        if ($validator->fails()) {
            $this->addError($errorKey, (string) $validator->errors()->first($errorKey));

            return null;
        }

        return $value;
    }

    public function saveMethods(): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);

        $this->validate($this->methodsRules());

        $this->payment_methods = $this->normalizePaymentMethods($this->payment_methods);

        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'payment.methods', array_values($this->payment_methods));

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status_methods', 'Métodos de pagamento salvos!');
    }

    /**
     * Salva as integrações. Os segredos chegam como parâmetros (write-only): vazio = preserva a
     * credencial existente (cifrada); preenchido = substitui e cifra.
     */
    public function saveIntegrations(?string $accessToken = null, ?string $webhookSecret = null): void
    {
        $this->authorize('manageIntegrations', $this->tenant);

        $this->resetErrorBag();
        $this->validate($this->integrationsRules());

        $token = $this->validatedSecret($accessToken, 'mp_access_token');
        $secret = $this->validatedSecret($webhookSecret, 'mp_webhook_secret');

        if ($token === null || $secret === null) {
            return;
        }

        $settings = $this->tenant->settings ?? [];

        $mp = (array) data_get($settings, 'payment.mercadopago', []);
        $mp['public_key'] = $this->mp_public_key ?: null;
        if ($token !== '') {
            $mp['access_token'] = EncryptionHelper::encryptIfNeeded($token);
        }
        if ($secret !== '') {
            $mp['webhook_secret'] = EncryptionHelper::encryptIfNeeded($secret);
        }
        data_set($settings, 'payment.mercadopago', $mp);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        $this->mpConnectionStatus = null;

        session()->flash('status_integrations', 'Integrações salvas!');
    }

    public function clearAccessToken(): void
    {
        $this->authorize('manageIntegrations', $this->tenant);

        $this->clearSecret('access_token');

        session()->flash('status_integrations', 'Access Token removido.');
    }

    public function clearWebhookSecret(): void
    {
        $this->authorize('manageIntegrations', $this->tenant);

        $this->clearSecret('webhook_secret');

        session()->flash('status_integrations', 'Webhook Secret removido.');
    }

    private function clearSecret(string $key): void
    {
        $settings = $this->tenant->settings ?? [];
        data_set($settings, "payment.mercadopago.{$key}", null);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        $this->mpConnectionStatus = null;
    }

    /**
     * Testa a conexão. `$accessToken` vazio = usa a credencial salva; preenchido = usa a digitada
     * (somente em memória, nunca persistida nem colocada em propriedade pública).
     */
    public function testMercadoPagoConnection(?string $accessToken = null): void
    {
        $this->authorize('manageIntegrations', $this->tenant);

        $token = trim((string) $accessToken);
        if ($token === '') {
            $token = (string) $this->storedSecret('access_token');
        }

        if (! $this->mp_public_key || $token === '' || mb_strlen($token) > self::SECRET_MAX_LENGTH) {
            $this->mpConnectionStatus = 'missing_credentials';

            return;
        }

        try {
            $tempTenant = clone $this->tenant;
            $tempSettings = $tempTenant->settings ?? [];
            data_set($tempSettings, 'payment.mercadopago.public_key', $this->mp_public_key);
            data_set($tempSettings, 'payment.mercadopago.access_token', EncryptionHelper::encryptIfNeeded($token));
            $tempTenant->settings = $tempSettings;

            $result = app(MercadoPagoGateway::class)->testConnection($tempTenant);

            $this->mpConnectionStatus = $result ? 'connected' : 'failed';
        } catch (\Throwable) {
            // Sem log/flash da exceção: a mensagem poderia carregar o token (header Authorization).
            $this->mpConnectionStatus = 'failed';
        }
    }

    public function getRecentPaymentsProperty(): array
    {
        $this->authorize('viewAny', [Payment::class, $this->tenant]);

        return Payment::query()
            ->forTenant($this->tenant->id)
            ->with('order')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->toArray();
    }

    protected function authorizeView(): void
    {
        $this->authorize('managePaymentMethods', $this->tenant);
    }

    public function render()
    {
        return view('livewire.admin.tenant.payment-settings');
    }
}
