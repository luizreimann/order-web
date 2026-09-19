# Documentação de Desenvolvimento - Sprint 01-Payment

**Data:** 2026-01-09  
**Desenvolvedor:** Cascade (AI Assistant)  
**Baseado em:** 02-sprint.md

---

## Resumo

Implementação completa do **Mínimo Viável** da sprint de refatoração da arquitetura de pagamentos. Todas as 6 tarefas necessárias (N1-N6) foram concluídas.

---

## Arquivos Criados

### N1: Helper de Criptografia

| Arquivo | Descrição |
|---------|-----------|
| `app/Support/EncryptionHelper.php` | Classe com métodos estáticos `encryptIfNeeded()` e `decryptIfNeeded()` para criptografia de credenciais sensíveis |

### N2: Enum PaymentMethod

| Arquivo | Descrição |
|---------|-----------|
| `app/Enums/PaymentMethod.php` | Enum com todos os métodos de pagamento suportados |

**Casos do enum:**
- `CASH_ON_DELIVERY` - Dinheiro (na entrega)
- `CARD_ON_DELIVERY` - Cartão (na entrega)
- `PIX_MANUAL` - PIX (Chave)
- `MP_PIX` - PIX (Mercado Pago)
- `MP_CREDIT_CARD` - Cartão de crédito (Mercado Pago)
- `MP_DEBIT_CARD` - Cartão de débito (Mercado Pago)

**Métodos úteis:**
- `isMercadoPago(): bool`
- `isManual(): bool`
- `provider(): string`
- `label(): string`
- `allIds(): array`
- `manualIds(): array`
- `mercadoPagoIds(): array`
- `defaultConfig(): array`

### N3: OrderService

| Arquivo | Descrição |
|---------|-----------|
| `app/Services/OrderService.php` | Service para criação de pedidos com Customer, Order e OrderItems |

**Métodos:**
- `createFromCart(Tenant, array $customerData, array $cart, string $paymentMethod, array $paymentMeta): Order`
- `resolveCustomer(Tenant, array $customerData): Customer`
- `resolveCartLines(Tenant, array $cart): array`
- `calculateSubtotal(array $lines): int`

### N4: Interface PaymentGatewayInterface

| Arquivo | Descrição |
|---------|-----------|
| `app/Contracts/PaymentGatewayInterface.php` | Interface para gateways de pagamento |
| `app/Contracts/PaymentResult.php` | DTO para resultado de criação/consulta de pagamento |
| `app/Contracts/WebhookResult.php` | DTO para resultado de processamento de webhook |

**Interface:**
```php
interface PaymentGatewayInterface
{
    public function createPayment(Tenant $tenant, Order $order, array $data): PaymentResult;
    public function getPayment(Tenant $tenant, string $paymentId): ?PaymentResult;
    public function handleWebhook(array $payload): WebhookResult;
}
```

### N5: MercadoPagoGateway

| Arquivo | Descrição |
|---------|-----------|
| `app/Gateways/MercadoPagoGateway.php` | Implementação do gateway Mercado Pago |

**Funcionalidades:**
- Criação de pagamentos via API do Mercado Pago
- Consulta de pagamentos
- Processamento de webhooks
- Mapeamento de status MP → status interno
- Suporte a Integrator ID global

### N6: Modelo Payment

| Arquivo | Descrição |
|---------|-----------|
| `app/Models/Payment.php` | Model Eloquent para pagamentos |
| `database/migrations/2026_01_09_024650_create_payments_table.php` | Migration da tabela payments |

**Estrutura da tabela `payments`:**
```
id              uuid (PK)
tenant_id       uuid (FK → tenants)
order_id        uuid (FK → orders)
gateway         varchar(50)
gateway_payment_id  varchar (nullable)
amount_cents    integer
status          varchar(30) default 'pending'
status_detail   varchar (nullable)
meta            jsonb (nullable)
created_at      timestamp
updated_at      timestamp
```

---

## Arquivos Modificados

### Livewire/Admin/Tenant/Settings.php

**Mudanças:**
- Removido import de `Illuminate\Support\Facades\Crypt`
- Adicionado import de `App\Enums\PaymentMethod`
- Adicionado import de `App\Support\EncryptionHelper`
- Removidos métodos privados `encryptIfNeeded()` e `decryptIfNeeded()`
- Removido array `$allowedPaymentMethodIds` hardcoded
- Removido método `defaultPaymentMethods()` com array hardcoded
- Removido método `defaultLabelForMethod()`
- Refatorado para usar `PaymentMethod::allIds()`, `PaymentMethod::defaultConfig()`, etc.
- Refatorado para usar `EncryptionHelper::encryptIfNeeded()` e `EncryptionHelper::decryptIfNeeded()`

### Livewire/Tenant/Checkout.php

**Mudanças:**
- Removidos imports de `Customer`, `Order`, `OrderItem`
- Adicionado import de `App\Enums\PaymentMethod`
- Adicionado import de `App\Services\OrderService`
- Refatorado `updatedPaymentMethod()` para usar `PaymentMethod::mercadoPagoIds()`
- Refatorado `mpInitPayload()` para usar constantes do enum
- Refatorado `getPaymentInstructionsProperty()` para usar `PaymentMethod::PIX_MANUAL->value`
- Refatorado `placeOrderManual()` para usar `OrderService` via injeção de dependência
- Removida lógica duplicada de criação de Customer/Order/OrderItems

### Http/Controllers/TenantPaymentController.php

**Mudanças:**
- Removidos imports de `Customer`, `OrderItem`, `Product`, `Http`, `Str`, `Response`
- Adicionado import de `App\Gateways\MercadoPagoGateway`
- Adicionado import de `App\Services\OrderService`
- Adicionado construtor com injeção de `MercadoPagoGateway` e `OrderService`
- Removido método privado `mpCreatePayment()`
- Removido método privado `decryptIfNeeded()`
- Refatorado `processPayment()` para usar `OrderService` e `MercadoPagoGateway`
- Lógica de chamada HTTP movida para `MercadoPagoGateway`

### Http/Controllers/MercadoPagoWebhookController.php

**Mudanças:**
- Removidos imports de `Order`, `Tenant`, `Response`, `Http`, `EncryptionHelper`
- Adicionado import de `App\Gateways\MercadoPagoGateway`
- Adicionado construtor com injeção de `MercadoPagoGateway`
- Removido método privado `decryptIfNeeded()`
- Refatorado `handle()` para delegar toda lógica ao `MercadoPagoGateway::handleWebhook()`
- Controller reduzido de ~120 linhas para ~20 linhas

### Models/Order.php

**Mudanças:**
- Adicionada relação `payments(): HasMany`

---

## Comandos Pendentes

A migration da tabela `payments` precisa ser executada **dentro do container Docker**:

```bash
docker compose exec app php artisan migrate
```

---

## Observações para QA

### Testes Funcionais Recomendados

#### 1. Checkout Manual (Métodos Offline)

- [ ] Acessar storefront de um tenant
- [ ] Adicionar produtos ao carrinho
- [ ] Ir para checkout
- [ ] Selecionar método "Dinheiro (na entrega)"
- [ ] Preencher nome, e-mail e telefone
- [ ] Clicar em "Enviar pedido"
- [ ] Verificar se pedido foi criado com `payment_method = cash_on_delivery`
- [ ] Repetir para "Cartão (na entrega)" e "PIX (Chave)"

#### 2. Checkout Mercado Pago (PIX)

- [ ] Habilitar método "PIX (Mercado Pago)" nas configurações do tenant
- [ ] Configurar credenciais do Mercado Pago (public_key e access_token)
- [ ] Acessar checkout e selecionar "PIX (Mercado Pago)"
- [ ] Preencher dados do cliente
- [ ] Completar formulário do Brick
- [ ] Verificar se QR Code PIX é exibido
- [ ] Verificar se pedido foi criado com `payment_method = mp_pix`
- [ ] Verificar se `order.meta.mercadopago` contém dados do pagamento

#### 3. Checkout Mercado Pago (Cartão de Crédito)

- [ ] Habilitar método "Cartão de crédito (Mercado Pago)"
- [ ] Acessar checkout e selecionar o método
- [ ] Usar cartão de teste do Mercado Pago
- [ ] Verificar se pagamento é processado
- [ ] Verificar se `payment_status` é atualizado corretamente

#### 4. Webhook do Mercado Pago

- [ ] Criar pagamento PIX
- [ ] Simular webhook de pagamento aprovado (usar ngrok ou similar)
- [ ] Verificar se `order.payment_status` é atualizado para `paid`
- [ ] Verificar se `order.meta.mercadopago.last_webhook_at` é atualizado

#### 5. Configurações de Pagamento (Admin)

- [ ] Acessar configurações do tenant
- [ ] Habilitar/desabilitar métodos de pagamento
- [ ] Reordenar métodos de pagamento
- [ ] Salvar e verificar se ordem é mantida no checkout
- [ ] Configurar credenciais do Mercado Pago
- [ ] Verificar se credenciais são criptografadas no banco

### Pontos de Atenção

1. **Criptografia de credenciais**: O `access_token` do Mercado Pago deve ser armazenado com prefixo `enc:` no banco. Verificar em `tenant.settings.payment.mercadopago.access_token`.

2. **Integrator ID**: Todas as chamadas à API do Mercado Pago devem incluir o header `X-Integrator-Id`. Verificar nos logs ou via proxy de debug.

3. **Idempotency Key**: Cada pagamento deve ter uma chave de idempotência única. Verificar em `order.meta.mercadopago.idempotency_key`.

4. **Metadata**: Os pagamentos devem incluir metadata com `tenant_id`, `tenant_slug` e `order_id`. Verificar no painel do Mercado Pago.

5. **Webhook URL**: A URL de notificação deve ser `{APP_URL}/webhooks/mercadopago`. Verificar se está configurada corretamente.

### Regressões Potenciais

- [ ] Verificar se checkout manual continua funcionando após refatoração
- [ ] Verificar se configurações de pagamento são salvas corretamente
- [ ] Verificar se métodos desabilitados não aparecem no checkout
- [ ] Verificar se instruções de pagamento são exibidas corretamente
- [ ] Verificar se chave PIX manual é concatenada nas instruções

### Arquivos para Revisão de Código

| Arquivo | Prioridade | Motivo |
|---------|------------|--------|
| `app/Gateways/MercadoPagoGateway.php` | Alta | Lógica crítica de pagamento |
| `app/Services/OrderService.php` | Alta | Criação de pedidos |
| `app/Contracts/PaymentGatewayInterface.php` | Média | Contrato para novos gateways |
| `app/Enums/PaymentMethod.php` | Média | Definição de métodos |
| `database/migrations/*_create_payments_table.php` | Média | Estrutura do banco |

---

## Próximos Passos (Pós-QA)

1. Testar fluxos de pagamento
2. Considerar implementação das tarefas opcionais (O1-O6)
3. Adicionar testes automatizados para `MercadoPagoGateway` e `OrderService`
