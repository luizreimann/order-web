<?php

namespace App\Livewire\Admin\Tenant;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Tenant;
use App\Services\ZaptriaClient;
use App\Support\Phone;
use Illuminate\Http\Client\Response;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class WhatsAppSettings extends Component
{
    use AuthorizesOnHydrate;

    private const DEFAULT_CONFIRMED = '{loja}: seu pedido #{pedido} foi confirmado.';

    private const DEFAULT_DONE = '{loja}: seu pedido #{pedido} foi concluído.';

    private const DEFAULT_CANCELLED = '{loja}: seu pedido #{pedido} foi cancelado.';

    /** @var array<string, string> */
    private const STEP_LABELS = [
        'inactive' => 'Sem número',
        'starting' => 'Subindo',
        'qr_ready' => 'Pronto para ler',
        'connected' => 'Conectado',
        'disconnected' => 'Caiu',
        'error' => 'Falhou',
        'disconnecting' => 'Desconectando',
    ];

    /** @var list<string> */
    private const POLLING = ['starting', 'qr_ready', 'disconnecting'];

    /** @var list<string> */
    private const CONNECTABLE = ['inactive', 'disconnected', 'error'];

    /** @var array<string, string> */
    private const CONNECT_LABELS = [
        'inactive' => 'Conectar',
        'disconnected' => 'Reconectar',
        'error' => 'Tentar de novo',
    ];

    public Tenant $tenant;

    public bool $order_updates = false;

    public string $confirmed = '';

    public string $done = '';

    public string $cancelled = '';

    public string $pairing_phone = '';

    /** Código mostrado na tela. Não entra no bloco `whatsapp`. */
    public string $pairing_code = '';

    /** String do QR deste request. Não entra no snapshot nem no JSON. */
    private ?string $qr = null;

    private bool $fetched = false;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();

        $this->refreshFromService();
        $this->loadForm();
    }

    protected function rules(): array
    {
        return [
            'order_updates' => ['boolean'],
            'confirmed' => ['string', 'max:500'],
            'done' => ['string', 'max:500'],
            'cancelled' => ['string', 'max:500'],
        ];
    }

    protected function messages(): array
    {
        return [
            'confirmed.max' => 'O texto de confirmado não pode ter mais de 500 caracteres.',
            'done.max' => 'O texto de concluído não pode ter mais de 500 caracteres.',
            'cancelled.max' => 'O texto de cancelado não pode ter mais de 500 caracteres.',
        ];
    }

    public function save(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->validate();

        $settings = $this->tenant->settings ?? [];
        $current = $this->block();

        data_set($settings, 'whatsapp', [
            'status' => $this->storedStatus($current['status'] ?? null),
            'number' => $this->storedNumber($current['number'] ?? null),
            'order_updates' => $this->order_updates,
            'templates' => [
                'confirmed' => $this->confirmed,
                'done' => $this->done,
                'cancelled' => $this->cancelled,
            ],
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Configurações de WhatsApp salvas com sucesso!');
    }

    public function refreshConnection(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->refreshFromService();
    }

    public function connect(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        if (! in_array($this->currentStatus(), self::CONNECTABLE, true)) {
            return;
        }

        $this->send('POST', '/whatsapp/connect', [
            'tenant' => ['name' => (string) $this->tenant->name],
        ]);
    }

    public function disconnect(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        if ($this->currentStatus() !== 'connected') {
            return;
        }

        $this->send('POST', '/whatsapp/logout');
    }

    public function requestPairingCode(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        if ($this->currentStatus() !== 'qr_ready') {
            return;
        }

        if (! Phone::isValid($this->pairing_phone)) {
            $this->addError('pairing_phone', 'Informe um telefone válido com DDD (ex.: (11) 99999-9999).');

            return;
        }

        try {
            $response = app(ZaptriaClient::class)->request('POST', $this->path('/whatsapp/pairing-code'), [
                'phone' => Phone::normalize($this->pairing_phone),
            ]);
        } catch (\Throwable) {
            return;
        }

        if (! $response instanceof Response || ! $response->successful()) {
            return;
        }

        $code = $response->json('code');

        if (is_string($code) && $code !== '') {
            $this->pairing_code = $code;
        }
    }

    protected function authorizeView(): void
    {
        $this->authorize('manageSettings', $this->tenant);
    }

    public function render()
    {
        if ($this->qr === null && ! $this->fetched && $this->currentStatus() === 'qr_ready') {
            $this->refreshFromService();
        }

        $block = $this->block();
        $status = $this->storedStatus($block['status'] ?? null);

        return view('livewire.admin.tenant.whatsapp-settings', [
            'status' => $status,
            'stepLabel' => self::STEP_LABELS[$status],
            'connectedNumber' => $status === 'connected' ? $this->storedNumber($block['number'] ?? null) : null,
            'polls' => in_array($status, self::POLLING, true),
            'qr' => $status === 'qr_ready' ? $this->qr : null,
            'connectLabel' => self::CONNECT_LABELS[$status] ?? null,
        ]);
    }

    private function loadForm(): void
    {
        $block = $this->block();

        $this->order_updates = ($block['order_updates'] ?? false) === true;
        $this->confirmed = $this->text($block, 'confirmed', self::DEFAULT_CONFIRMED);
        $this->done = $this->text($block, 'done', self::DEFAULT_DONE);
        $this->cancelled = $this->text($block, 'cancelled', self::DEFAULT_CANCELLED);
    }

    private function refreshFromService(): void
    {
        if ($this->fetched) {
            return;
        }

        $this->fetched = true;
        $this->send('GET', '/whatsapp');
    }

    /** @param  array<string, mixed>|null  $body */
    private function send(string $method, string $suffix, ?array $body = null): void
    {
        try {
            $response = app(ZaptriaClient::class)->request($method, $this->path($suffix), $body);
        } catch (\Throwable) {
            return;
        }

        $this->applyResponse($response);
    }

    private function applyResponse(mixed $response): void
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return;
        }

        $status = $payload['status'] ?? null;

        if (! is_string($status) || ! array_key_exists($status, self::STEP_LABELS)) {
            return;
        }

        $settings = $this->tenant->settings;

        if (! is_array($settings)) {
            $settings = [];
        }

        $whatsapp = $settings['whatsapp'] ?? [];

        if (! is_array($whatsapp)) {
            $whatsapp = [];
        }

        $whatsapp['status'] = $status;
        $whatsapp['number'] = $this->numberFrom($payload['number'] ?? null);
        unset($whatsapp['qr']);

        data_set($settings, 'whatsapp', $whatsapp);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        $qr = $payload['qr'] ?? null;
        $this->qr = is_string($qr) && $qr !== '' ? $qr : null;

        if ($status !== 'qr_ready') {
            $this->pairing_code = '';
        }
    }

    private function path(string $suffix): string
    {
        return '/tenants/'.(string) $this->tenant->getKey().$suffix;
    }

    private function currentStatus(): string
    {
        return $this->storedStatus($this->block()['status'] ?? null);
    }

    /** @return array<string, mixed> */
    private function block(): array
    {
        $whatsapp = data_get($this->tenant->settings, 'whatsapp');

        return is_array($whatsapp) ? $whatsapp : [];
    }

    /** @param  array<string, mixed>  $block */
    private function text(array $block, string $key, string $default): string
    {
        $templates = $block['templates'] ?? null;

        if (! is_array($templates) || ! array_key_exists($key, $templates) || ! is_string($templates[$key])) {
            return $default;
        }

        return $templates[$key];
    }

    private function storedStatus(mixed $status): string
    {
        if (! is_string($status) || ! array_key_exists($status, self::STEP_LABELS)) {
            return 'inactive';
        }

        return $status;
    }

    private function storedNumber(mixed $number): ?string
    {
        if (is_int($number)) {
            $number = (string) $number;
        }

        if (! is_string($number) || $number === '') {
            return null;
        }

        return $number;
    }

    private function numberFrom(mixed $number): ?string
    {
        if (is_int($number)) {
            $number = (string) $number;
        }

        if (! is_string($number)) {
            return null;
        }

        return Phone::normalizeOrNull($number);
    }
}
