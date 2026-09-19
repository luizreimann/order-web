# Análise da Arquitetura de Pagamentos

**Data:** 2026-01-08  
**Sprint:** 01-payment  
**Autor:** Cascade (AI Assistant)

---

## 1. Visão Geral

O sistema de pagamentos do Order Web é multi-tenant e atualmente suporta dois tipos de provedores:

- **Manual** (offline): `cash_on_delivery`, `card_on_delivery`, `pix_manual`
- **Mercado Pago** (online): `mp_pix`, `mp_credit_card`, `mp_debit_card`

A configuração de métodos de pagamento é feita por tenant, armazenada em `tenant.settings` (JSONB), e inclui:
- Lista ordenada de métodos com `enabled`, `label`, `instructions`
- Credenciais do Mercado Pago (`public_key`, `access_token` criptografado)

---

## 2. Componentes Atuais

### 2.1 Configuração (Admin)

| Arquivo | Responsabilidade |
|---------|------------------|
| `Livewire/Admin/Tenant/Settings.php` | Gerencia métodos de pagamento, ordenação, credenciais MP |
| `views/livewire/admin/tenant/settings.blade.php` | UI de configuração estilo WooCommerce |

**Pontos positivos:**
- Lista ordenável de métodos
- Validação condicional de credenciais MP (só exige se método MP habilitado)
- Criptografia do `access_token` com prefixo `enc:`

**Pontos de atenção:**
- Toda lógica de normalização, defaults e validação está no componente Livewire
- `decryptIfNeeded` duplicado em 3 arquivos
- Não há abstração para "provedor de pagamento"

### 2.2 Checkout (Storefront)

| Arquivo | Responsabilidade |
|---------|------------------|
| `Livewire/Tenant/Checkout.php` | Exibe métodos, processa pedidos manuais, prepara dados para MP Brick |
| `views/livewire/tenant/checkout.blade.php` | UI do checkout + JavaScript do Payment Brick |

**Pontos positivos:**
- Separação clara entre fluxo manual (`placeOrderManual`) e fluxo MP (via JS)
- Instruções de pagamento dinâmicas por método
- PIX manual concatena chave automaticamente

**Pontos de atenção:**
- Lógica de criação de `Customer`, `Order`, `OrderItem` duplicada entre `Checkout.php` e `TenantPaymentController.php`
- JavaScript do Brick inline no Blade (~190 linhas)
- Hardcoded: lista de métodos MP (`mp_pix`, `mp_credit_card`, `mp_debit_card`)

### 2.3 Processamento de Pagamento (Backend)

| Arquivo | Responsabilidade |
|---------|------------------|
| `Http/Controllers/TenantPaymentController.php` | Recebe dados do Brick, cria pedido, chama API do MP |
| `Http/Controllers/MercadoPagoWebhookController.php` | Recebe webhooks do MP, atualiza `payment_status` |

**Pontos positivos:**
- Idempotency key por transação
- Metadata com `tenant_id`, `order_id` para correlação no webhook
- Integrator ID global via `config/services.php`
- Webhook valida metadata antes de atualizar

**Pontos de atenção:**
- Controller com ~280 linhas, mistura validação, criação de entidades e chamada HTTP
- Não há retry/queue para falhas de webhook
- Não há logging estruturado de transações
- `decryptIfNeeded` duplicado

### 2.4 Modelo de Dados

| Campo | Localização | Uso |
|-------|-------------|-----|
| `payment_status` | `orders.payment_status` | `unpaid`, `paid`, `refunded` |
| `payment_method` | `orders.payment_method` | ID do método (ex: `mp_pix`) |
| `meta.mercadopago.*` | `orders.meta` (JSONB) | Dados do MP: `payment_id`, `status`, `point_of_interaction` |
| `meta.payment.provider` | `orders.meta` | `manual` ou `mercadopago` |

**Pontos positivos:**
- JSONB flexível para dados específicos do provedor
- `payment_status` normalizado independente do provedor

**Pontos de atenção:**
- Não há tabela dedicada para transações de pagamento
- Histórico de mudanças de status não é persistido
- Não há modelo `Payment` ou `Transaction`

---

## 3. Fluxos de Pagamento

### 3.1 Fluxo Manual (Offline)

```
[Checkout.php] placeOrderManual()
    → Valida dados do cliente
    → Cria/atualiza Customer
    → Cria Order (payment_status: unpaid)
    → Cria OrderItems
    → Limpa carrinho
    → Redireciona para home
```

### 3.2 Fluxo Mercado Pago (Online)

```
[checkout.blade.php] Payment Brick
    → onSubmit: POST /process_payment
    
[TenantPaymentController] processPayment()
    → Valida método habilitado
    → Valida credenciais MP
    → Cria/atualiza Customer
    → Cria Order (payment_status: unpaid)
    → Cria OrderItems
    → POST api.mercadopago.com/v1/payments
    → Atualiza order.meta.mercadopago
    → Se approved: payment_status = paid
    → Limpa carrinho
    → Retorna JSON com point_of_interaction (PIX)

[MercadoPagoWebhookController] handle()
    → Recebe notificação do MP
    → Busca Order por meta->mercadopago->payment_id
    → GET api.mercadopago.com/v1/payments/{id}
    → Valida metadata.tenant_id e metadata.order_id
    → Atualiza order.meta.mercadopago
    → Atualiza payment_status conforme status MP
```

---

## 4. Problemas Identificados

### 4.1 Duplicação de Código

| Código duplicado | Arquivos |
|------------------|----------|
| `decryptIfNeeded()` | Settings.php, TenantPaymentController.php, MercadoPagoWebhookController.php |
| Criação de Customer/Order/OrderItem | Checkout.php, TenantPaymentController.php |
| Lista de métodos MP hardcoded | Checkout.php, Settings.php |

### 4.2 Acoplamento

- `TenantPaymentController` conhece detalhes da API do Mercado Pago diretamente
- Não há interface/contrato para provedores de pagamento
- Adicionar novo provedor (ex: Stripe, PagSeguro) exigiria:
  - Novo controller
  - Novo webhook controller
  - Modificar Checkout.php
  - Modificar Settings.php
  - Modificar checkout.blade.php

### 4.3 Falta de Abstração

- Não existe conceito de "Gateway" ou "Provider"
- Não existe modelo `Payment` ou `Transaction`
- Não há eventos/listeners para mudanças de status
- Não há histórico de tentativas de pagamento

### 4.4 Resiliência

- Webhook não tem retry em caso de falha
- Não há dead letter queue
- Não há verificação periódica de pagamentos pendentes
- Não há timeout configurável para chamadas HTTP

### 4.5 Observabilidade

- Não há logging estruturado de transações
- Não há métricas de sucesso/falha por provedor
- Erros do MP são salvos em `order.meta` mas não há alertas

---

## 5. Recomendações de Melhoria

### 5.1 Curto Prazo (Quick Wins)

#### 5.1.1 Extrair `decryptIfNeeded` para Trait ou Helper

```
App\Support\EncryptionHelper::decryptIfNeeded($value)
```

#### 5.1.2 Extrair criação de Order para Service

```
App\Services\OrderService::createFromCart(Tenant, Customer, array $lines, string $paymentMethod)
```

#### 5.1.3 Constantes para métodos de pagamento

```
App\Enums\PaymentMethod::MP_PIX, ::MP_CREDIT_CARD, etc.
```

### 5.2 Médio Prazo (Arquitetura)

#### 5.2.1 Introduzir conceito de Payment Gateway

```php
interface PaymentGatewayInterface
{
    public function createPayment(Order $order, array $data): PaymentResult;
    public function getPayment(string $paymentId): ?PaymentResult;
    public function supportsWebhook(): bool;
    public function handleWebhook(array $payload): WebhookResult;
}
```

#### 5.2.2 Criar modelo Payment/Transaction

```
payments
├── id (uuid)
├── tenant_id
├── order_id
├── gateway (mercadopago, stripe, manual)
├── gateway_payment_id
├── amount_cents
├── status (pending, approved, rejected, refunded)
├── status_detail
├── meta (jsonb)
├── created_at
└── updated_at
```

#### 5.2.3 Implementar PaymentService

```php
class PaymentService
{
    public function process(Order $order, string $gateway, array $data): Payment;
    public function handleWebhook(string $gateway, array $payload): void;
    public function syncPendingPayments(): void;
}
```

#### 5.2.4 Eventos e Listeners

```
PaymentCreated → Log, Notify
PaymentApproved → UpdateOrder, SendConfirmation
PaymentFailed → Log, Alert
PaymentRefunded → UpdateOrder, Notify
```

### 5.3 Longo Prazo (Escalabilidade)

#### 5.3.1 Processamento assíncrono de webhooks

- Webhook recebe, valida assinatura, enfileira job
- Job processa com retry automático
- Dead letter queue para falhas persistentes

#### 5.3.2 Reconciliação automática

- Job periódico verifica pagamentos `pending` há mais de X minutos
- Consulta status no gateway e atualiza

#### 5.3.3 Dashboard de pagamentos

- Métricas por gateway
- Taxa de aprovação
- Tempo médio de confirmação
- Alertas de anomalias

---

## 6. Priorização para Novos Gateways

Antes de adicionar Stripe, PagSeguro ou outro gateway, recomendo:

| Prioridade | Ação | Esforço | Impacto |
|------------|------|---------|---------|
| 1 | Extrair `decryptIfNeeded` para helper | Baixo | Médio |
| 2 | Criar `OrderService` | Médio | Alto |
| 3 | Criar enum `PaymentMethod` | Baixo | Médio |
| 4 | Criar interface `PaymentGatewayInterface` | Médio | Alto |
| 5 | Criar modelo `Payment` | Médio | Alto |
| 6 | Refatorar MP para implementar interface | Alto | Alto |
| 7 | Adicionar eventos de pagamento | Médio | Médio |

Com os itens 1-6 implementados, adicionar um novo gateway seria:

1. Criar `App\Gateways\StripeGateway implements PaymentGatewayInterface`
2. Registrar no container/config
3. Adicionar métodos em `PaymentMethod` enum
4. Adicionar campos de credenciais em Settings (se necessário)
5. Criar webhook controller específico (ou usar genérico com dispatch)

---

## 7. Conclusão

A implementação atual é **funcional para MVP** e atende bem ao caso de uso inicial (Mercado Pago + métodos manuais). No entanto, a arquitetura atual apresenta:

- **Duplicação** que dificulta manutenção
- **Acoplamento** que dificulta extensão
- **Falta de abstrações** que exigirá refatoração significativa para novos gateways

Recomendo investir nas melhorias de **curto e médio prazo** antes de adicionar novos provedores de pagamento, especialmente a criação da interface `PaymentGatewayInterface` e do modelo `Payment`, que são os pilares para uma arquitetura extensível.

---

## Anexo: Arquivos Relacionados

```
src/
├── app/
│   ├── Http/Controllers/
│   │   ├── TenantPaymentController.php      # Processa pagamentos MP
│   │   └── MercadoPagoWebhookController.php # Webhooks MP
│   ├── Livewire/
│   │   ├── Admin/Tenant/Settings.php        # Config de métodos
│   │   └── Tenant/Checkout.php              # Checkout storefront
│   └── Models/
│       └── Order.php                        # payment_status, payment_method, meta
├── config/
│   └── services.php                         # mercadopago.integrator_id
├── bootstrap/
│   └── app.php                              # CSRF exception para webhook
├── routes/
│   └── web.php                              # Rotas de checkout e webhook
└── resources/views/livewire/
    ├── admin/tenant/settings.blade.php      # UI config pagamentos
    └── tenant/checkout.blade.php            # UI checkout + JS Brick
```
