# Guia de Desenvolvimento: Personalização do Tenant

**Data:** 2026-01-12  
**Sprint:** 04-tenant-customization

---

## 1. Arquitetura

### 1.1 Estrutura de Arquivos a Criar

```
src/app/
├── Livewire/Admin/Tenant/
│   ├── Customization.php          # (existente - expandir)
│   ├── Settings.php               # (existente)
│   ├── PaymentSettings.php        # (existente)
│   ├── StoreInfo.php              # NOVO - descrição/sobre
│   ├── ContactSettings.php        # NOVO - telefone/whatsapp/email
│   ├── AddressSettings.php        # NOVO - endereço
│   ├── BusinessHours.php          # NOVO - horários
│   ├── SocialLinks.php            # NOVO - redes sociais
│   ├── DeliverySettings.php       # NOVO - taxa/mínimo/tempo
│   └── SeoSettings.php            # NOVO - meta tags
├── Support/
│   └── TenantHelper.php           # NOVO - helpers (isOpen, etc)
└── View/Components/
    └── WhatsAppButton.php         # NOVO - botão flutuante

src/resources/views/
├── livewire/admin/tenant/
│   ├── customization.blade.php    # (existente - expandir)
│   ├── store-info.blade.php       # NOVO
│   ├── contact-settings.blade.php # NOVO
│   ├── address-settings.blade.php # NOVO
│   ├── business-hours.blade.php   # NOVO
│   ├── social-links.blade.php     # NOVO
│   ├── delivery-settings.blade.php# NOVO
│   └── seo-settings.blade.php     # NOVO
├── livewire/tenant/
│   └── home.blade.php             # (existente - expandir)
├── components/
│   └── whatsapp-button.blade.php  # NOVO
└── partials/
    ├── tenant-theme.blade.php     # (existente)
    ├── tenant-footer.blade.php    # NOVO
    └── tenant-meta.blade.php      # NOVO
```

### 1.2 Estrutura do `settings` JSONB

```php
// Estrutura completa proposta para tenant.settings
[
    'theme' => [
        'font_family' => 'Inter, system-ui, ...',
        'banner_path' => 'tenants/{id}/banner.jpg',
        'favicon_path' => 'tenants/{id}/favicon.ico',
    ],
    'store' => [
        'short_description' => 'Descrição curta (160 chars)',
        'full_description' => 'Texto completo sobre a loja...',
        'show_about' => true,
    ],
    'contact' => [
        'phone' => '(11) 99999-9999',
        'whatsapp' => '5511999999999',
        'email' => 'contato@loja.com',
        'whatsapp_message' => 'Olá! Vim pelo site...',
        'show_whatsapp_button' => true,
    ],
    'address' => [
        'street' => 'Rua Exemplo',
        'number' => '123',
        'complement' => 'Sala 1',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'zip_code' => '01234-567',
        'google_maps_url' => 'https://maps.google.com/...',
        'show_on_storefront' => true,
    ],
    'business_hours' => [
        'enabled' => true,
        'timezone' => 'America/Sao_Paulo',
        'auto_close' => true,
        'paused' => false,
        'schedule' => [
            'monday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
            'tuesday' => ['open' => '08:00', 'close' => '18:00', 'closed' => false],
            // ...
        ],
    ],
    'social' => [
        'instagram' => 'https://instagram.com/loja',
        'facebook' => 'https://facebook.com/loja',
        'tiktok' => null,
        'youtube' => null,
    ],
    'delivery' => [
        'minimum_order_cents' => 2000,
        'default_fee_cents' => 500,
        'estimated_time_minutes' => 45,
        'free_delivery_above_cents' => 10000,
    ],
    'seo' => [
        'meta_title' => 'Loja | Encomendas',
        'meta_description' => 'Descrição para Google...',
        'og_image_path' => 'tenants/{id}/og-image.jpg',
        'google_analytics_id' => 'G-XXXXXXXXXX',
    ],
    'payment' => [
        // (estrutura existente)
    ],
]
```

---

## 2. Implementação por Componente

### 2.1 TenantHelper (Support)

```php
<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\Carbon;

class TenantHelper
{
    public static function isOpen(Tenant $tenant): bool
    {
        $hours = data_get($tenant->settings, 'business_hours');
        
        if (!data_get($hours, 'enabled', false)) {
            return true; // Se não configurado, sempre aberto
        }
        
        if (data_get($hours, 'paused', false)) {
            return false; // Pausado manualmente
        }
        
        if (!data_get($hours, 'auto_close', true)) {
            return true; // Auto-close desabilitado
        }
        
        $timezone = data_get($hours, 'timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        $dayOfWeek = strtolower($now->englishDayOfWeek);
        
        $daySchedule = data_get($hours, "schedule.{$dayOfWeek}");
        
        if (!$daySchedule || data_get($daySchedule, 'closed', false)) {
            return false;
        }
        
        $open = data_get($daySchedule, 'open');
        $close = data_get($daySchedule, 'close');
        
        if (!$open || !$close) {
            return true;
        }
        
        $openTime = Carbon::parse($open, $timezone);
        $closeTime = Carbon::parse($close, $timezone);
        
        return $now->between($openTime, $closeTime);
    }
    
    public static function getFormattedAddress(Tenant $tenant): ?string
    {
        $addr = data_get($tenant->settings, 'address');
        
        if (!$addr || !data_get($addr, 'show_on_storefront', false)) {
            return null;
        }
        
        $parts = array_filter([
            data_get($addr, 'street'),
            data_get($addr, 'number'),
            data_get($addr, 'complement'),
        ]);
        
        $line1 = implode(', ', $parts);
        
        $line2Parts = array_filter([
            data_get($addr, 'neighborhood'),
            data_get($addr, 'city'),
            data_get($addr, 'state'),
        ]);
        
        $line2 = implode(' - ', $line2Parts);
        
        $zip = data_get($addr, 'zip_code');
        
        return trim("{$line1}\n{$line2}" . ($zip ? " - CEP: {$zip}" : ''));
    }
    
    public static function getWhatsAppUrl(Tenant $tenant): ?string
    {
        $whatsapp = data_get($tenant->settings, 'contact.whatsapp');
        
        if (!$whatsapp) {
            return null;
        }
        
        $message = data_get($tenant->settings, 'contact.whatsapp_message', '');
        $encoded = urlencode($message);
        
        return "https://wa.me/{$whatsapp}" . ($encoded ? "?text={$encoded}" : '');
    }
    
    public static function getDeliveryInfo(Tenant $tenant): array
    {
        $delivery = data_get($tenant->settings, 'delivery', []);
        
        return [
            'minimum_order' => data_get($delivery, 'minimum_order_cents', 0) / 100,
            'fee' => data_get($delivery, 'default_fee_cents', 0) / 100,
            'estimated_time' => data_get($delivery, 'estimated_time_minutes'),
            'free_above' => data_get($delivery, 'free_delivery_above_cents', 0) / 100,
        ];
    }
}
```

### 2.2 Componente BusinessHours (Exemplo Complexo)

```php
<?php

namespace App\Livewire\Admin\Tenant;

use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class BusinessHours extends Component
{
    public Tenant $tenant;
    
    public bool $enabled = false;
    public string $timezone = 'America/Sao_Paulo';
    public bool $auto_close = true;
    public bool $paused = false;
    
    public array $schedule = [];
    
    protected array $days = [
        'monday' => 'Segunda-feira',
        'tuesday' => 'Terça-feira',
        'wednesday' => 'Quarta-feira',
        'thursday' => 'Quinta-feira',
        'friday' => 'Sexta-feira',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];
    
    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        
        $hours = data_get($tenant->settings, 'business_hours', []);
        
        $this->enabled = data_get($hours, 'enabled', false);
        $this->timezone = data_get($hours, 'timezone', 'America/Sao_Paulo');
        $this->auto_close = data_get($hours, 'auto_close', true);
        $this->paused = data_get($hours, 'paused', false);
        
        foreach ($this->days as $day => $label) {
            $this->schedule[$day] = data_get($hours, "schedule.{$day}", [
                'open' => '08:00',
                'close' => '18:00',
                'closed' => false,
            ]);
        }
    }
    
    protected function rules(): array
    {
        $rules = [
            'enabled' => ['boolean'],
            'timezone' => ['required', 'string', 'timezone'],
            'auto_close' => ['boolean'],
            'paused' => ['boolean'],
        ];
        
        foreach (array_keys($this->days) as $day) {
            $rules["schedule.{$day}.open"] = ['nullable', 'date_format:H:i'];
            $rules["schedule.{$day}.close"] = ['nullable', 'date_format:H:i'];
            $rules["schedule.{$day}.closed"] = ['boolean'];
        }
        
        return $rules;
    }
    
    public function save(): void
    {
        $this->validate();
        
        $settings = $this->tenant->settings ?? [];
        
        data_set($settings, 'business_hours', [
            'enabled' => $this->enabled,
            'timezone' => $this->timezone,
            'auto_close' => $this->auto_close,
            'paused' => $this->paused,
            'schedule' => $this->schedule,
        ]);
        
        $this->tenant->settings = $settings;
        $this->tenant->save();
        
        session()->flash('status', 'Horários salvos com sucesso!');
    }
    
    public function togglePause(): void
    {
        $this->paused = !$this->paused;
        $this->save();
    }
    
    public function getDaysProperty(): array
    {
        return $this->days;
    }
    
    public function render()
    {
        return view('livewire.admin.tenant.business-hours');
    }
}
```

### 2.3 View BusinessHours (Blade)

```blade
<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Horário de Funcionamento</h1>
            <div class="text-muted small">Configure quando sua loja está aberta</div>
        </div>
        
        @if($enabled && $auto_close)
            <button 
                type="button" 
                class="btn {{ $paused ? 'btn-success' : 'btn-warning' }}"
                wire:click="togglePause"
            >
                {{ $paused ? 'Reabrir Loja' : 'Pausar Pedidos' }}
            </button>
        @endif
    </div>
    
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    
    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save">
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" wire:model="enabled" id="enabled">
                        <label class="form-check-label" for="enabled">
                            Habilitar controle de horário
                        </label>
                    </div>
                    <div class="form-text">
                        Se desabilitado, a loja será considerada sempre aberta.
                    </div>
                </div>
                
                @if($enabled)
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Fuso horário</label>
                            <select class="form-select" wire:model="timezone">
                                <option value="America/Sao_Paulo">São Paulo (GMT-3)</option>
                                <option value="America/Manaus">Manaus (GMT-4)</option>
                                <option value="America/Cuiaba">Cuiabá (GMT-4)</option>
                                <option value="America/Fortaleza">Fortaleza (GMT-3)</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Fechamento automático</label>
                            <div class="form-check form-switch mt-2">
                                <input type="checkbox" class="form-check-input" wire:model="auto_close" id="auto_close">
                                <label class="form-check-label" for="auto_close">
                                    Bloquear pedidos fora do horário
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6 class="mb-3">Horários por dia</h6>
                    
                    @foreach($this->days as $day => $label)
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-3">
                                <strong>{{ $label }}</strong>
                            </div>
                            <div class="col-2">
                                <input 
                                    type="time" 
                                    class="form-control form-control-sm" 
                                    wire:model="schedule.{{ $day }}.open"
                                    @if($schedule[$day]['closed']) disabled @endif
                                >
                            </div>
                            <div class="col-auto text-muted">às</div>
                            <div class="col-2">
                                <input 
                                    type="time" 
                                    class="form-control form-control-sm" 
                                    wire:model="schedule.{{ $day }}.close"
                                    @if($schedule[$day]['closed']) disabled @endif
                                >
                            </div>
                            <div class="col">
                                <div class="form-check">
                                    <input 
                                        type="checkbox" 
                                        class="form-check-input" 
                                        wire:model="schedule.{{ $day }}.closed"
                                        id="closed_{{ $day }}"
                                    >
                                    <label class="form-check-label text-muted" for="closed_{{ $day }}">
                                        Fechado
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif
                
                <hr>
                
                <button type="submit" class="btn btn-primary">Salvar</button>
            </form>
        </div>
    </div>
</div>
```

---

## 3. Rotas

Adicionar em `routes/web.php` (dentro do grupo tenant admin):

```php
// Configurações do Tenant (Admin)
Route::prefix('{tenant}/admin')->middleware(['auth', 'tenant.admin'])->group(function () {
    // Existentes
    Route::get('/customization', Customization::class)->name('tenant.admin.customization');
    Route::get('/settings', Settings::class)->name('tenant.admin.settings');
    Route::get('/payment-settings', PaymentSettings::class)->name('tenant.admin.payment-settings');
    
    // Novos
    Route::get('/store-info', StoreInfo::class)->name('tenant.admin.store-info');
    Route::get('/contact', ContactSettings::class)->name('tenant.admin.contact');
    Route::get('/address', AddressSettings::class)->name('tenant.admin.address');
    Route::get('/business-hours', BusinessHours::class)->name('tenant.admin.business-hours');
    Route::get('/social-links', SocialLinks::class)->name('tenant.admin.social-links');
    Route::get('/delivery', DeliverySettings::class)->name('tenant.admin.delivery');
    Route::get('/seo', SeoSettings::class)->name('tenant.admin.seo');
});
```

---

## 4. Menu Admin

Atualizar sidebar/menu do admin para incluir novas seções:

```php
$menuItems = [
    ['route' => 'tenant.admin.dashboard', 'icon' => 'fa-home', 'label' => 'Dashboard'],
    
    // Loja
    ['divider' => true, 'label' => 'Loja'],
    ['route' => 'tenant.admin.products.index', 'icon' => 'fa-box', 'label' => 'Produtos'],
    ['route' => 'tenant.admin.categories.index', 'icon' => 'fa-tags', 'label' => 'Categorias'],
    ['route' => 'tenant.admin.showcases.index', 'icon' => 'fa-star', 'label' => 'Vitrines'],
    
    // Pedidos
    ['divider' => true, 'label' => 'Vendas'],
    ['route' => 'tenant.admin.orders.index', 'icon' => 'fa-shopping-cart', 'label' => 'Pedidos'],
    ['route' => 'tenant.admin.customers.index', 'icon' => 'fa-users', 'label' => 'Clientes'],
    
    // Configurações
    ['divider' => true, 'label' => 'Configurações'],
    ['route' => 'tenant.admin.store-info', 'icon' => 'fa-store', 'label' => 'Informações'],
    ['route' => 'tenant.admin.customization', 'icon' => 'fa-palette', 'label' => 'Aparência'],
    ['route' => 'tenant.admin.contact', 'icon' => 'fa-phone', 'label' => 'Contato'],
    ['route' => 'tenant.admin.address', 'icon' => 'fa-map-marker', 'label' => 'Endereço'],
    ['route' => 'tenant.admin.business-hours', 'icon' => 'fa-clock', 'label' => 'Horários'],
    ['route' => 'tenant.admin.social-links', 'icon' => 'fa-share-alt', 'label' => 'Redes Sociais'],
    ['route' => 'tenant.admin.delivery', 'icon' => 'fa-truck', 'label' => 'Entrega'],
    ['route' => 'tenant.admin.payment-settings', 'icon' => 'fa-credit-card', 'label' => 'Pagamentos'],
    ['route' => 'tenant.admin.seo', 'icon' => 'fa-search', 'label' => 'SEO'],
    ['route' => 'tenant.admin.settings', 'icon' => 'fa-cog', 'label' => 'URL/Slug'],
];
```

---

## 5. Validações Importantes

### 5.1 Telefone/WhatsApp

```php
// Regex para telefone brasileiro
'phone' => ['nullable', 'string', 'regex:/^\(\d{2}\)\s?\d{4,5}-?\d{4}$/'],

// WhatsApp (apenas números, com código do país)
'whatsapp' => ['nullable', 'string', 'regex:/^55\d{10,11}$/'],
```

### 5.2 URLs de Redes Sociais

```php
'instagram' => ['nullable', 'url', 'regex:/instagram\.com/i'],
'facebook' => ['nullable', 'url', 'regex:/facebook\.com/i'],
'tiktok' => ['nullable', 'url', 'regex:/tiktok\.com/i'],
'youtube' => ['nullable', 'url', 'regex:/(youtube\.com|youtu\.be)/i'],
```

### 5.3 Valores Monetários

```php
// Converter de reais para centavos ao salvar
$this->minimum_order_cents = (int) ($this->minimum_order * 100);

// Converter de centavos para reais ao carregar
$this->minimum_order = data_get($delivery, 'minimum_order_cents', 0) / 100;
```

---

## 6. Testes Sugeridos

### 6.1 Unit Tests

```php
// tests/Unit/Support/TenantHelperTest.php

public function test_is_open_returns_true_when_not_configured(): void
{
    $tenant = Tenant::factory()->make(['settings' => []]);
    $this->assertTrue(TenantHelper::isOpen($tenant));
}

public function test_is_open_returns_false_when_paused(): void
{
    $tenant = Tenant::factory()->make([
        'settings' => [
            'business_hours' => [
                'enabled' => true,
                'paused' => true,
            ],
        ],
    ]);
    $this->assertFalse(TenantHelper::isOpen($tenant));
}

public function test_is_open_respects_schedule(): void
{
    // Testar com horário dentro/fora do expediente
}
```

### 6.2 Feature Tests

```php
// tests/Feature/Livewire/Admin/Tenant/BusinessHoursTest.php

public function test_can_save_business_hours(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    
    Livewire::actingAs($user)
        ->test(BusinessHours::class, ['tenant' => $tenant])
        ->set('enabled', true)
        ->set('schedule.monday.open', '09:00')
        ->set('schedule.monday.close', '18:00')
        ->call('save')
        ->assertSessionHas('status');
    
    $tenant->refresh();
    $this->assertTrue(data_get($tenant->settings, 'business_hours.enabled'));
}
```

---

## 7. Checklist de Implementação

### Fase 1: Backend
- [ ] Criar `TenantHelper` com métodos utilitários
- [ ] Criar `StoreInfo` component
- [ ] Criar `ContactSettings` component
- [ ] Criar `AddressSettings` component
- [ ] Criar `BusinessHours` component
- [ ] Criar `SocialLinks` component
- [ ] Criar `DeliverySettings` component
- [ ] Criar `SeoSettings` component
- [ ] Expandir `Customization` (banner, favicon)
- [ ] Atualizar `TenantFactory`

### Fase 2: Frontend Admin
- [ ] Criar views Blade para cada componente
- [ ] Atualizar menu/sidebar
- [ ] Adicionar rotas

### Fase 3: Frontend Storefront
- [ ] Adicionar banner na home
- [ ] Adicionar badge aberto/fechado
- [ ] Criar rodapé com informações
- [ ] Adicionar botão WhatsApp flutuante
- [ ] Aplicar meta tags SEO
- [ ] Aplicar favicon dinâmico

### Fase 4: Checkout
- [ ] Validar pedido mínimo
- [ ] Somar taxa de entrega
- [ ] Exibir tempo estimado

### Fase 5: Testes
- [ ] Unit tests para TenantHelper
- [ ] Feature tests para componentes Livewire
- [ ] Testes manuais E2E
