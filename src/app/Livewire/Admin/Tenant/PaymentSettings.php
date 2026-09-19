<?php

namespace App\Livewire\Admin\Tenant;

use App\Enums\PaymentMethod;
use App\Gateways\MercadoPagoGateway;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\EncryptionHelper;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class PaymentSettings extends Component
{
    public Tenant $tenant;

    public string $activeTab = 'methods';

    public array $payment_methods = [];

    public ?string $mp_public_key = null;
    public ?string $mp_access_token = null;
    public ?string $mp_webhook_secret = null;

    public ?string $mpConnectionStatus = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->loadPaymentMethods();
        $this->loadIntegrations();
    }

    public function setTab(string $tab): void
    {
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

        $this->mp_public_key = data_get($mpSettings, 'public_key');
        $this->mp_access_token = EncryptionHelper::decryptIfNeeded(data_get($mpSettings, 'access_token'));
        $this->mp_webhook_secret = EncryptionHelper::decryptIfNeeded(data_get($mpSettings, 'webhook_secret'));
    }

    private function normalizePaymentMethods(array $methods): array
    {
        $seen = [];
        $normalized = [];

        foreach ($methods as $method) {
            if (!is_array($method)) continue;
            $id = (string) ($method['id'] ?? '');
            if (!$id) continue;
            if (!in_array($id, PaymentMethod::allIds(), true)) continue;
            if (isset($seen[$id])) continue;
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
            if (!isset($seen[$id])) {
                $normalized[] = $default;
            }
        }

        return array_values($normalized);
    }

    public function isMercadoPagoIntegrated(): bool
    {
        return $this->mp_public_key && $this->mp_access_token;
    }

    public function getManualMethodsProperty(): array
    {
        return array_values(array_filter($this->payment_methods, fn ($m) => !PaymentMethod::isMercadoPagoId($m['id'])));
    }

    public function getMercadoPagoMethodsProperty(): array
    {
        return array_values(array_filter($this->payment_methods, fn ($m) => PaymentMethod::isMercadoPagoId($m['id'])));
    }

    public function getEnabledMethodsProperty(): array
    {
        $methods = array_filter($this->payment_methods, function ($m) {
            if (!($m['enabled'] ?? false)) return false;
            if (PaymentMethod::isMercadoPagoId($m['id']) && !$this->isMercadoPagoIntegrated()) return false;
            return true;
        });

        return array_values($methods);
    }

    public function toggleMethod(string $methodId): void
    {
        foreach ($this->payment_methods as $index => $method) {
            if ($method['id'] === $methodId) {
                $this->payment_methods[$index]['enabled'] = !$method['enabled'];
                break;
            }
        }
    }

    public function moveMethodUp(string $methodId): void
    {
        $index = $this->findMethodIndex($methodId);
        if ($index === null || $index <= 0) return;

        $temp = $this->payment_methods[$index - 1];
        $this->payment_methods[$index - 1] = $this->payment_methods[$index];
        $this->payment_methods[$index] = $temp;
        $this->payment_methods = array_values($this->payment_methods);
    }

    public function moveMethodDown(string $methodId): void
    {
        $index = $this->findMethodIndex($methodId);
        if ($index === null || $index >= count($this->payment_methods) - 1) return;

        $temp = $this->payment_methods[$index + 1];
        $this->payment_methods[$index + 1] = $this->payment_methods[$index];
        $this->payment_methods[$index] = $temp;
        $this->payment_methods = array_values($this->payment_methods);
    }

    public function reorderMethod(string $methodId, int $oldIndex, int $newIndex): void
    {
        if ($oldIndex === $newIndex) return;
        if (!isset($this->payment_methods[$oldIndex])) return;

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
        $index = $this->findMethodIndex($methodId);
        if ($index !== null) {
            $this->payment_methods[$index]['label'] = $label;
        }
    }

    public function updateMethodInstructions(string $methodId, ?string $instructions): void
    {
        $index = $this->findMethodIndex($methodId);
        if ($index !== null) {
            $this->payment_methods[$index]['instructions'] = $instructions;
        }
    }

    public function updatePixKey(string $pixKey): void
    {
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
            'mp_access_token' => ['nullable', 'string', 'max:255'],
            'mp_webhook_secret' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function saveMethods(): void
    {
        $this->validate($this->methodsRules());

        $this->payment_methods = $this->normalizePaymentMethods($this->payment_methods);

        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'payment.methods', array_values($this->payment_methods));

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status_methods', 'Métodos de pagamento salvos!');
    }

    public function saveIntegrations(): void
    {
        $this->validate($this->integrationsRules());

        $settings = $this->tenant->settings ?? [];

        $mp = (array) data_get($settings, 'payment.mercadopago', []);
        $mp['public_key'] = $this->mp_public_key ?: null;
        $mp['access_token'] = EncryptionHelper::encryptIfNeeded($this->mp_access_token);
        $mp['webhook_secret'] = EncryptionHelper::encryptIfNeeded($this->mp_webhook_secret);
        data_set($settings, 'payment.mercadopago', $mp);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        $this->mpConnectionStatus = null;

        session()->flash('status_integrations', 'Integrações salvas!');
    }

    public function testMercadoPagoConnection(): void
    {
        if (!$this->mp_public_key || !$this->mp_access_token) {
            $this->mpConnectionStatus = 'missing_credentials';
            return;
        }

        $gateway = app(MercadoPagoGateway::class);

        $tempTenant = clone $this->tenant;
        $tempSettings = $tempTenant->settings ?? [];
        data_set($tempSettings, 'payment.mercadopago.public_key', $this->mp_public_key);
        data_set($tempSettings, 'payment.mercadopago.access_token', EncryptionHelper::encryptIfNeeded($this->mp_access_token));
        $tempTenant->settings = $tempSettings;

        $result = $gateway->testConnection($tempTenant);

        $this->mpConnectionStatus = $result ? 'connected' : 'failed';
    }

    public function getRecentPaymentsProperty(): array
    {
        return Payment::query()
            ->forTenant($this->tenant->id)
            ->with('order')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->toArray();
    }

    public function render()
    {
        return view('livewire.admin.tenant.payment-settings');
    }
}
