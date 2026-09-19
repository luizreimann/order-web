# Sprint: Refatoração da Arquitetura de Pagamentos

**Data:** 2026-01-08  
**Baseado em:** 01-analyse.md  
**Objetivo:** Preparar a arquitetura para suportar múltiplos gateways de pagamento

---

## Resumo

Esta sprint organiza as melhorias identificadas na análise em tarefas **necessárias** (bloqueantes para novos gateways) e **opcionais** (melhorias de qualidade que podem ser feitas depois).

---

## Tarefas Necessárias

> Devem ser concluídas **antes** de adicionar novos gateways (Stripe, PagSeguro, etc.)

### N1. Extrair helper de criptografia

**Prioridade:** Alta  
**Esforço:** Baixo (1-2h)  
**Arquivos afetados:** 3

| Tarefa | Descrição |
|--------|-----------|
| Criar `App\Support\EncryptionHelper` | Classe com método estático `decryptIfNeeded($value)` |
| Refatorar `Settings.php` | Usar helper ao invés de método privado |
| Refatorar `TenantPaymentController.php` | Usar helper ao invés de método privado |
| Refatorar `MercadoPagoWebhookController.php` | Usar helper ao invés de método privado |

**Critério de aceite:** Método `decryptIfNeeded` existe em apenas 1 lugar.

---

### N2. Criar enum PaymentMethod

**Prioridade:** Alta  
**Esforço:** Baixo (1-2h)  
**Arquivos afetados:** 4+

| Tarefa | Descrição |
|--------|-----------|
| Criar `App\Enums\PaymentMethod` | Enum com casos: `CASH_ON_DELIVERY`, `CARD_ON_DELIVERY`, `PIX_MANUAL`, `MP_PIX`, `MP_CREDIT_CARD`, `MP_DEBIT_CARD` |
| Adicionar método `isMercadoPago(): bool` | Retorna true para métodos MP |
| Adicionar método `isManual(): bool` | Retorna true para métodos manuais |
| Adicionar método `label(): string` | Retorna label padrão em português |
| Refatorar `Settings.php` | Usar enum ao invés de array hardcoded |
| Refatorar `Checkout.php` | Usar enum ao invés de array hardcoded |

**Critério de aceite:** Lista de métodos de pagamento definida em apenas 1 lugar.

---

### N3. Criar OrderService

**Prioridade:** Alta  
**Esforço:** Médio (3-4h)  
**Arquivos afetados:** 3

| Tarefa | Descrição |
|--------|-----------|
| Criar `App\Services\OrderService` | Service class para criação de pedidos |
| Método `createFromCart(Tenant, array $customerData, array $cart, string $paymentMethod): Order` | Cria Customer, Order, OrderItems em transação |
| Refatorar `Checkout.php::placeOrderManual()` | Usar `OrderService` |
| Refatorar `TenantPaymentController::processPayment()` | Usar `OrderService` |

**Critério de aceite:** Lógica de criação de pedido existe em apenas 1 lugar.

---

### N4. Criar interface PaymentGatewayInterface

**Prioridade:** Alta  
**Esforço:** Médio (2-3h)  
**Arquivos afetados:** 1 novo

| Tarefa | Descrição |
|--------|-----------|
| Criar `App\Contracts\PaymentGatewayInterface` | Interface com métodos padrão |
| Método `createPayment(Order $order, array $data): PaymentResult` | Cria pagamento no gateway |
| Método `getPayment(string $paymentId): ?PaymentResult` | Consulta pagamento |
| Método `handleWebhook(array $payload): WebhookResult` | Processa webhook |
| Criar `App\Contracts\PaymentResult` | DTO para resultado de pagamento |
| Criar `App\Contracts\WebhookResult` | DTO para resultado de webhook |

**Critério de aceite:** Contrato definido e documentado para implementação de gateways.

---

### N5. Implementar MercadoPagoGateway

**Prioridade:** Alta  
**Esforço:** Alto (4-6h)  
**Arquivos afetados:** 3+

| Tarefa | Descrição |
|--------|-----------|
| Criar `App\Gateways\MercadoPagoGateway` | Implementa `PaymentGatewayInterface` |
| Mover lógica de `TenantPaymentController` | Chamadas HTTP para API do MP |
| Mover lógica de `MercadoPagoWebhookController` | Processamento de webhook |
| Refatorar `TenantPaymentController` | Usar gateway via injeção |
| Refatorar `MercadoPagoWebhookController` | Usar gateway via injeção |

**Critério de aceite:** Toda lógica específica do Mercado Pago isolada em uma classe.

---

### N6. Criar modelo Payment

**Prioridade:** Média  
**Esforço:** Médio (3-4h)  
**Arquivos afetados:** 4+

| Tarefa | Descrição |
|--------|-----------|
| Criar migration `create_payments_table` | Tabela com: id, tenant_id, order_id, gateway, gateway_payment_id, amount_cents, status, status_detail, meta, timestamps |
| Criar `App\Models\Payment` | Model com relações e scopes |
| Adicionar relação `Order::payments()` | HasMany |
| Refatorar `TenantPaymentController` | Criar Payment ao processar |
| Refatorar `MercadoPagoWebhookController` | Atualizar Payment ao receber webhook |

**Critério de aceite:** Histórico de pagamentos persistido em tabela dedicada.

---

## Tarefas Opcionais

> Melhorias de qualidade que podem ser feitas após os itens necessários ou em paralelo

### O1. Extrair JavaScript do Brick para arquivo separado

**Prioridade:** Baixa  
**Esforço:** Baixo (1-2h)  
**Arquivos afetados:** 2

| Tarefa | Descrição |
|--------|-----------|
| Criar `resources/js/checkout-brick.js` | Mover ~190 linhas de JS |
| Atualizar `checkout.blade.php` | Importar script externo |
| Configurar Vite | Compilar novo arquivo |

**Benefício:** Melhor manutenibilidade do código JS, possibilidade de testes.

---

### O2. Adicionar eventos de pagamento

**Prioridade:** Média  
**Esforço:** Médio (2-3h)  
**Arquivos afetados:** 4+

| Tarefa | Descrição |
|--------|-----------|
| Criar `App\Events\PaymentCreated` | Evento disparado ao criar pagamento |
| Criar `App\Events\PaymentStatusChanged` | Evento disparado ao mudar status |
| Criar listeners para logging | Log estruturado de transações |
| Disparar eventos nos controllers/services | Integrar com fluxo existente |

**Benefício:** Extensibilidade para notificações, analytics, integrações.

---

### O3. Implementar processamento assíncrono de webhooks

**Prioridade:** Média  
**Esforço:** Médio (3-4h)  
**Arquivos afetados:** 3+

| Tarefa | Descrição |
|--------|-----------|
| Criar `App\Jobs\ProcessMercadoPagoWebhook` | Job para processar webhook |
| Refatorar `MercadoPagoWebhookController` | Apenas validar e enfileirar |
| Configurar retry e backoff | 3 tentativas com delay exponencial |
| Configurar dead letter queue | Para falhas persistentes |

**Benefício:** Resiliência, não bloqueia resposta ao MP, retry automático.

---

### O4. Implementar reconciliação de pagamentos pendentes

**Prioridade:** Baixa  
**Esforço:** Médio (2-3h)  
**Arquivos afetados:** 2+

| Tarefa | Descrição |
|--------|-----------|
| Criar `App\Console\Commands\ReconcilePendingPayments` | Comando artisan |
| Consultar pagamentos `pending` há mais de X minutos | Verificar status no gateway |
| Atualizar status conforme resposta | Usando PaymentGateway |
| Agendar no scheduler | Rodar a cada 15-30 minutos |

**Benefício:** Pagamentos PIX que não receberam webhook são atualizados.

---

### O5. Adicionar logging estruturado

**Prioridade:** Baixa  
**Esforço:** Baixo (1-2h)  
**Arquivos afetados:** 3+

| Tarefa | Descrição |
|--------|-----------|
| Criar canal de log `payments` | Em `config/logging.php` |
| Adicionar logs em pontos críticos | Criação, webhook, erros |
| Incluir contexto estruturado | tenant_id, order_id, payment_id, gateway |

**Benefício:** Debugging facilitado, auditoria, alertas.

---

### O6. Validação de assinatura de webhook

**Prioridade:** Média  
**Esforço:** Baixo (1-2h)  
**Arquivos afetados:** 1

| Tarefa | Descrição |
|--------|-----------|
| Implementar validação de assinatura MP | Verificar header `x-signature` |
| Rejeitar webhooks inválidos | Retornar 401 |

**Benefício:** Segurança contra webhooks forjados.

---

## Ordem de Execução Sugerida

### Fase 1: Fundação (Necessário)

```
N1 → N2 → N3
```

Elimina duplicação e centraliza lógica básica.

### Fase 2: Abstração (Necessário)

```
N4 → N5 → N6
```

Cria arquitetura extensível para múltiplos gateways.

### Fase 3: Qualidade (Opcional)

```
O1, O5, O6 (paralelo) → O2 → O3 → O4
```

Melhorias incrementais de qualidade e resiliência.

---

## Estimativa Total

| Categoria | Tarefas | Esforço Estimado |
|-----------|---------|------------------|
| Necessárias | N1-N6 | 14-21h |
| Opcionais | O1-O6 | 10-16h |
| **Total** | 12 tarefas | **24-37h** |

---

## Critérios de Conclusão da Sprint

### Mínimo Viável (Necessárias)

- [ ] N1: Helper de criptografia extraído
- [ ] N2: Enum PaymentMethod criado
- [ ] N3: OrderService implementado
- [ ] N4: Interface PaymentGatewayInterface definida
- [ ] N5: MercadoPagoGateway implementado
- [ ] N6: Modelo Payment criado

### Completo (Necessárias + Opcionais prioritárias)

- [ ] Todos os itens acima
- [ ] O2: Eventos de pagamento
- [ ] O5: Logging estruturado
- [ ] O6: Validação de assinatura de webhook

---

## Próximos Passos Pós-Sprint

Com a sprint concluída, adicionar um novo gateway (ex: Stripe) será:

1. Criar `App\Gateways\StripeGateway implements PaymentGatewayInterface`
2. Adicionar casos no enum `PaymentMethod`
3. Adicionar campos de credenciais em `Settings.php` (se necessário)
4. Criar webhook controller específico (ou reutilizar genérico)
5. Registrar gateway no container

Estimativa para novo gateway: **4-8h** (vs. **20-30h** na arquitetura atual).
