# Análise dos Testes - Sprint 02: Payment Improvements

**Data:** 2026-01-09  
**Comando executado:** `docker compose exec app php artisan test`

---

## Resumo

| Status | Quantidade |
|--------|------------|
| ✅ Passou | 26 |
| ❌ Falhou | 25 |
| **Total** | 51 |

---

## Categorias de Falhas

### 1. Factories Ausentes (24 testes)

**Erro:** `Call to undefined method App\Models\Tenant::factory()`

**Arquivos afetados:**
- `tests/Unit/Services/OrderServiceTest.php` (8 testes)
- `tests/Unit/Gateways/MercadoPagoGatewayTest.php` (16 testes)

**Causa raiz:**
Os models utilizados nos testes não possuem factories definidas. O Laravel requer que cada model tenha uma factory correspondente para usar o método `Model::factory()`.

**Models que precisam de factory:**
- `App\Models\Tenant`
- `App\Models\Order`
- `App\Models\Product`
- `App\Models\Customer`
- `App\Models\Payment`

**Solução:**

1. Criar as factories em `database/factories/`:

```php
// database/factories/TenantFactory.php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'settings' => [],
        ];
    }
}
```

```php
// database/factories/OrderFactory.php
<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => null,
            'total_cents' => fake()->numberBetween(1000, 100000),
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'meta' => [],
        ];
    }
}
```

```php
// database/factories/ProductFactory.php
<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(3, true),
            'price_cents' => fake()->numberBetween(100, 10000),
            'active' => true,
            'meta' => [],
        ];
    }
}
```

```php
// database/factories/CustomerFactory.php
<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
        ];
    }
}
```

```php
// database/factories/PaymentFactory.php
<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'order_id' => Order::factory(),
            'gateway' => 'mercadopago',
            'gateway_payment_id' => (string) fake()->unique()->randomNumber(9),
            'amount_cents' => fake()->numberBetween(1000, 100000),
            'status' => 'pending',
            'status_detail' => null,
            'meta' => [],
        ];
    }
}
```

2. Adicionar trait `HasFactory` nos models (se ainda não tiver):

```php
// Em cada model (Tenant, Order, Product, Customer, Payment)
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasFactory;
    // ...
}
```

---

### 2. Teste de Feature Padrão (1 teste)

**Erro:** `Expected response status code [200] but received 302`

**Arquivo afetado:**
- `tests/Feature/ExampleTest.php`

**Causa raiz:**
O teste padrão do Laravel espera que a rota `/` retorne status 200, mas a aplicação está redirecionando (302). Isso é comportamento esperado se a rota `/` redireciona para login ou para um tenant específico.

**Solução:**

Opção A - Atualizar o teste para refletir o comportamento real:

```php
// tests/Feature/ExampleTest.php
public function test_the_application_returns_a_successful_response(): void
{
    $response = $this->get('/');
    
    // A rota raiz redireciona para login ou tenant
    $response->assertStatus(302);
}
```

Opção B - Remover o teste se não for relevante:

```bash
rm tests/Feature/ExampleTest.php
```

Opção C - Testar uma rota que realmente retorna 200:

```php
public function test_the_application_returns_a_successful_response(): void
{
    $tenant = Tenant::factory()->create();
    
    $response = $this->get("/{$tenant->slug}");
    
    $response->assertStatus(200);
}
```

---

## Testes que Passaram (26)

Os seguintes testes estão funcionando corretamente:

### Unit/Support/EncryptionHelperTest (10 testes) ✅
- `test_encrypt_if_needed_returns_null_for_null`
- `test_encrypt_if_needed_returns_null_for_empty_string`
- `test_encrypt_if_needed_returns_same_value_if_already_encrypted`
- `test_encrypt_if_needed_encrypts_new_value`
- `test_decrypt_if_needed_returns_null_for_null`
- `test_decrypt_if_needed_returns_null_for_empty_string`
- `test_decrypt_if_needed_returns_same_value_if_not_encrypted`
- `test_decrypt_if_needed_decrypts_encrypted_value`
- `test_decrypt_if_needed_returns_null_for_invalid_encrypted_value`
- `test_encrypt_and_decrypt_roundtrip`

### Unit/Enums/PaymentMethodTest (15 testes) ✅
- `test_is_mercado_pago_returns_true_for_mp_methods`
- `test_is_mercado_pago_returns_false_for_manual_methods`
- `test_is_manual_returns_true_for_manual_methods`
- `test_is_manual_returns_false_for_mp_methods`
- `test_provider_returns_correct_value`
- `test_label_returns_correct_value`
- `test_all_ids_returns_all_method_ids`
- `test_manual_ids_returns_only_manual_method_ids`
- `test_mercado_pago_ids_returns_only_mp_method_ids`
- `test_default_config_returns_correct_structure`
- `test_default_config_has_manual_methods_enabled_by_default`
- `test_default_config_pix_manual_has_pix_key_config`
- `test_try_from_id_returns_correct_enum`
- `test_try_from_id_returns_null_for_invalid_id`
- `test_is_mercado_pago_id_returns_correct_value`

### Unit/ExampleTest (1 teste) ✅
- `test_that_true_is_true`

---

## Prioridade de Correção

| Prioridade | Ação | Impacto |
|------------|------|---------|
| **Alta** | Criar factories para models | Corrige 24 testes |
| **Baixa** | Atualizar/remover ExampleTest | Corrige 1 teste |

---

## Comandos para Verificar Após Correções

```bash
# Rodar todos os testes
docker compose exec app php artisan test

# Rodar apenas testes unitários
docker compose exec app php artisan test --testsuite=Unit

# Rodar testes específicos
docker compose exec app php artisan test tests/Unit/Services/OrderServiceTest.php
docker compose exec app php artisan test tests/Unit/Gateways/MercadoPagoGatewayTest.php

# Rodar com verbose para mais detalhes
docker compose exec app php artisan test --verbose
```

---

## Checklist de Correção

- [x] Criar `database/factories/TenantFactory.php`
- [x] Criar `database/factories/OrderFactory.php`
- [x] Criar `database/factories/ProductFactory.php`
- [x] Criar `database/factories/CustomerFactory.php`
- [x] Criar `database/factories/PaymentFactory.php`
- [x] Adicionar trait `HasFactory` nos models (se necessário)
- [x] Atualizar ou remover `tests/Feature/ExampleTest.php`
- [x] Rodar testes novamente para confirmar correções

---

## Resultado Final

**Data da correção:** 2026-01-09

```
Tests:    51 passed (159 assertions)
Duration: 0.73s
```

✅ **Todos os testes passaram com sucesso!**
