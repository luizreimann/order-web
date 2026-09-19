# Desenvolvimento - Sprint 02: Payment Improvements

**Data:** 2026-01-09  
**Baseado em:** 02-sprint.md

---

## Resumo da Implementação

Todas as tarefas necessárias (N1-N5) e opcionais (O1-O3) foram implementadas com sucesso.

---

## Tarefas Implementadas

### N3. Timeout nas Chamadas HTTP ✅

**Arquivo modificado:** `app/Gateways/MercadoPagoGateway.php`

**Mudanças:**
- Adicionado `->timeout(30)` para timeout total de 30 segundos
- Adicionado `->connectTimeout(10)` para timeout de conexão de 10 segundos
- Adicionado tratamento de `ConnectionException` retornando `null`

```php
$request = Http::withToken($accessToken)
    ->withHeaders($headers)
    ->timeout(30)
    ->connectTimeout(10);

try {
    return match (strtoupper($method)) {
        // ...
    };
} catch (\Illuminate\Http\Client\ConnectionException $e) {
    return null;
}
```

---

### N2. Validação de Assinatura do Webhook ✅

**Arquivos modificados:**
- `app/Gateways/MercadoPagoGateway.php`
- `app/Http/Controllers/MercadoPagoWebhookController.php`

**Novos métodos no Gateway:**
- `validateWebhookSignature(string $dataId, array $headers, ?string $secretKey): bool`
- `getWebhookSecret(Tenant $tenant): ?string`

**Lógica de validação:**
1. Em ambiente local/dev/testing, aceita webhooks sem secret configurado
2. Parseia header `x-signature` no formato `ts=timestamp,v1=hash`
3. Calcula hash esperado: `HMAC-SHA256("id:{dataId};request-id:{requestId};ts:{ts};", secretKey)`
4. Compara com `hash_equals()` para evitar timing attacks

**Controller atualizado:**
- Extrai headers da requisição
- Busca order pelo payment_id
- Valida assinatura antes de processar
- Retorna 401 se assinatura inválida

---

### N1. Integrar Modelo Payment no Fluxo ✅

**Arquivo modificado:** `app/Gateways/MercadoPagoGateway.php`

**Mudanças em `createPayment()`:**
- Após sucesso na API do MP, cria registro em `payments`:

```php
Payment::create([
    'tenant_id' => $tenant->id,
    'order_id' => $order->id,
    'gateway' => 'mercadopago',
    'gateway_payment_id' => $gatewayPaymentId,
    'amount_cents' => $order->total_cents,
    'status' => $status,
    'status_detail' => $statusDetail,
    'meta' => [...],
]);
```

**Mudanças em `handleWebhook()`:**
- Após atualizar order, busca e atualiza Payment correspondente:

```php
$payment = Payment::query()
    ->where('gateway', 'mercadopago')
    ->where('gateway_payment_id', $paymentId)
    ->first();

if ($payment) {
    $payment->update([
        'status' => $paymentResult->status,
        'status_detail' => $paymentResult->statusDetail,
        'meta' => array_merge($payment->meta ?? [], [...]),
    ]);
}
```

**Compatibilidade mantida:** `order.meta.mercadopago` continua sendo populado.

---

### N4. Nova Página de Configurações de Pagamento ✅

**Novos arquivos:**
- `app/Livewire/Admin/Tenant/PaymentSettings.php`
- `resources/views/livewire/admin/tenant/payment-settings.blade.php`
- `resources/views/livewire/admin/tenant/partials/payment-method-row.blade.php`

**Arquivos modificados:**
- `routes/web.php` - Nova rota `/admin/pagamentos`
- `app/Livewire/Admin/Tenant/Settings.php` - Removida seção de pagamentos
- `resources/views/livewire/admin/tenant/settings.blade.php` - Adicionado link para nova página

**Estrutura da página:**

1. **Aba "Métodos de Pagamento"**
   - Seção "Métodos Manuais" (sempre visível)
   - Seção "Mercado Pago" (só aparece se integrado)
   - Preview do checkout em tempo real
   - Botão "Salvar Métodos"

2. **Aba "Integrações"**
   - Card Mercado Pago com credenciais
   - Cards Stripe e PagSeguro (em breve)
   - Histórico de pagamentos recentes

**Funcionalidades:**
- Toggle de ativação por método
- Edição de label e instruções
- Configuração de chave PIX para método manual
- Reordenação com botões up/down
- Drag-and-drop com SortableJS

---

### N5. Testes Unitários ✅

**Novos arquivos de teste:**

1. **`tests/Unit/Support/EncryptionHelperTest.php`**
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

2. **`tests/Unit/Enums/PaymentMethodTest.php`**
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

3. **`tests/Unit/Services/OrderServiceTest.php`**
   - `test_create_from_cart_creates_customer_order_and_items`
   - `test_resolve_customer_updates_existing_customer`
   - `test_resolve_customer_creates_new_customer_if_not_found`
   - `test_resolve_cart_lines_ignores_nonexistent_products`
   - `test_resolve_cart_lines_ignores_zero_quantity`
   - `test_calculate_subtotal_sums_correctly`
   - `test_calculate_subtotal_returns_zero_for_empty_lines`
   - `test_create_from_cart_with_payment_meta`

4. **`tests/Unit/Gateways/MercadoPagoGatewayTest.php`**
   - `test_create_payment_returns_failure_without_credentials`
   - `test_create_payment_returns_success_on_api_success`
   - `test_create_payment_returns_failure_on_api_error`
   - `test_get_payment_returns_payment_result_on_success`
   - `test_get_payment_returns_null_on_api_error`
   - `test_handle_webhook_ignores_non_payment_type`
   - `test_handle_webhook_fails_without_payment_id`
   - `test_handle_webhook_ignores_unknown_payment`
   - `test_handle_webhook_updates_order_and_payment`
   - `test_validate_webhook_signature_returns_true_in_dev_without_secret`
   - `test_validate_webhook_signature_returns_false_without_header`
   - `test_validate_webhook_signature_validates_correct_signature`
   - `test_validate_webhook_signature_rejects_invalid_signature`
   - `test_test_connection_returns_true_on_success`
   - `test_test_connection_returns_false_on_failure`
   - `test_test_connection_returns_false_without_credentials`

---

### O1. Indicador de Status de Conexão MP ✅

**Arquivo modificado:** `app/Gateways/MercadoPagoGateway.php`

**Novo método:**
```php
public function testConnection(Tenant $tenant): bool
{
    $accessToken = $this->getAccessToken($tenant);
    if (!$accessToken) {
        return false;
    }

    $response = $this->makeRequest($accessToken, 'GET', '/payment_methods');

    return $response instanceof Response && $response->successful();
}
```

**Na UI:**
- Badge de status: "Conectado", "Falha na conexão", "Não verificado", "Não configurado"
- Botão "Testar Conexão" na aba Integrações

---

### O2. Histórico de Pagamentos na Página ✅

**Arquivo modificado:** `app/Livewire/Admin/Tenant/PaymentSettings.php`

**Nova propriedade computada:**
```php
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
```

**Na UI (aba Integrações):**
- Tabela com últimos 10 pagamentos
- Colunas: Data, Pedido (link), Gateway, Valor, Status
- Badges coloridos por status

---

### O3. Drag-and-Drop para Reordenar Métodos ✅

**Arquivos modificados:**
- `resources/views/livewire/admin/tenant/payment-settings.blade.php`
- `resources/views/livewire/admin/tenant/partials/payment-method-row.blade.php`
- `app/Livewire/Admin/Tenant/PaymentSettings.php`

**Implementação:**
- Integração com SortableJS via CDN
- Handle de drag (ícone grip-vertical)
- Atributo `data-sortable` nas tabelas
- Atributo `data-method-id` nas linhas

**Novo método no componente:**
```php
public function reorderMethod(string $methodId, int $oldIndex, int $newIndex): void
{
    if ($oldIndex === $newIndex) return;
    if (!isset($this->payment_methods[$oldIndex])) return;

    $method = $this->payment_methods[$oldIndex];
    array_splice($this->payment_methods, $oldIndex, 1);
    array_splice($this->payment_methods, $newIndex, 0, [$method]);
    $this->payment_methods = array_values($this->payment_methods);
}
```

---

## Arquivos Criados/Modificados

### Novos Arquivos

| Arquivo | Descrição |
|---------|-----------|
| `app/Livewire/Admin/Tenant/PaymentSettings.php` | Componente Livewire da nova página |
| `resources/views/livewire/admin/tenant/payment-settings.blade.php` | View da nova página |
| `resources/views/livewire/admin/tenant/partials/payment-method-row.blade.php` | Partial para linha de método |
| `tests/Unit/Support/EncryptionHelperTest.php` | Testes do EncryptionHelper |
| `tests/Unit/Enums/PaymentMethodTest.php` | Testes do PaymentMethod enum |
| `tests/Unit/Services/OrderServiceTest.php` | Testes do OrderService |
| `tests/Unit/Gateways/MercadoPagoGatewayTest.php` | Testes do MercadoPagoGateway |

### Arquivos Modificados

| Arquivo | Mudança |
|---------|---------|
| `app/Gateways/MercadoPagoGateway.php` | Timeout, validação webhook, integração Payment, testConnection |
| `app/Http/Controllers/MercadoPagoWebhookController.php` | Validação de assinatura |
| `app/Livewire/Admin/Tenant/Settings.php` | Removida seção de pagamentos |
| `resources/views/livewire/admin/tenant/settings.blade.php` | Link para nova página |
| `routes/web.php` | Nova rota `/admin/pagamentos` |

---

## Configuração Necessária

### Webhook Secret do Mercado Pago

Para habilitar validação de assinatura em produção, configure o webhook secret:

1. Acesse o painel do Mercado Pago
2. Vá em Integrações > Webhooks
3. Copie a chave secreta do webhook
4. Cole na aba "Integrações" da página de pagamentos

**Nota:** Em ambiente local/dev/testing, webhooks são aceitos sem validação de assinatura.

---

## Comandos para Testar

```bash
# Executar todos os testes
docker compose exec app php artisan test

# Executar apenas testes unitários
docker compose exec app php artisan test --testsuite=Unit

# Executar testes específicos
docker compose exec app php artisan test tests/Unit/Support/EncryptionHelperTest.php
docker compose exec app php artisan test tests/Unit/Enums/PaymentMethodTest.php
docker compose exec app php artisan test tests/Unit/Services/OrderServiceTest.php
docker compose exec app php artisan test tests/Unit/Gateways/MercadoPagoGatewayTest.php
```

---

## Checklist de Conclusão

- [x] N1: Modelo Payment integrado no fluxo
- [x] N2: Validação de assinatura do webhook implementada
- [x] N3: Timeout configurado nas chamadas HTTP
- [x] N4: Nova página de configurações de pagamento
- [x] N5: Testes unitários criados
- [x] O1: Indicador de status de conexão
- [x] O2: Histórico de pagamentos
- [x] O3: Drag-and-drop para reordenar

---

## Próximos Passos

1. Executar testes: `docker compose exec app php artisan test`
2. Testar fluxo de checkout manual
3. Testar fluxo de checkout Mercado Pago
4. Testar nova página de configurações
5. Configurar webhook secret em produção
