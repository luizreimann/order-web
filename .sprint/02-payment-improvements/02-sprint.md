# Sprint 02: Payment Improvements

**Data:** 2026-01-09  
**Baseado em:** 01-review.md  
**Objetivo:** Completar integração do modelo Payment, melhorar segurança e criar nova página de configurações

---

## Resumo

Esta sprint endereça os pontos pendentes da revisão de QA da sprint anterior e adiciona uma nova página dedicada para configurações de pagamento.

---

## Tarefas Necessárias

### N1. Integrar Modelo Payment no Fluxo

**Prioridade:** Alta  
**Esforço:** Médio (3-4h)  
**Arquivos afetados:** 2

| Tarefa | Descrição |
|--------|-----------|
| Modificar `MercadoPagoGateway::createPayment()` | Criar registro em `payments` ao processar pagamento |
| Modificar `MercadoPagoGateway::handleWebhook()` | Atualizar registro em `payments` ao receber webhook |
| Manter compatibilidade | Continuar salvando em `order.meta.mercadopago` |

**Detalhes de implementação:**

```php
// Em createPayment(), após sucesso:
Payment::create([
    'tenant_id' => $tenant->id,
    'order_id' => $order->id,
    'gateway' => 'mercadopago',
    'gateway_payment_id' => $paymentId,
    'amount_cents' => $order->total_cents,
    'status' => $status,
    'status_detail' => $statusDetail,
    'meta' => $result->meta,
]);

// Em handleWebhook(), atualizar:
$payment = Payment::where('gateway_payment_id', $paymentId)->first();
if ($payment) {
    $payment->update([
        'status' => $newStatus,
        'status_detail' => $statusDetail,
        'meta' => array_merge($payment->meta ?? [], [...]),
    ]);
}
```

**Critério de aceite:** 
- Todo pagamento MP cria registro em `payments`
- Webhook atualiza registro existente
- `order.meta.mercadopago` continua sendo populado

---

### N2. Validação de Assinatura do Webhook

**Prioridade:** Alta  
**Esforço:** Médio (2-3h)  
**Arquivos afetados:** 2

| Tarefa | Descrição |
|--------|-----------|
| Criar método `validateSignature()` | Validar header `x-signature` conforme docs MP |
| Modificar `handleWebhook()` | Chamar validação antes de processar |
| Modificar `MercadoPagoWebhookController` | Passar headers para o gateway |
| Retornar 401 se inválido | Rejeitar webhooks não autenticados |

**Detalhes de implementação:**

```php
// Assinatura do MP usa HMAC-SHA256
// Header: x-signature: ts=timestamp,v1=hash
// Hash = HMAC-SHA256(id.ts.secret_key)

public function validateSignature(string $paymentId, array $headers, string $secretKey): bool
{
    $signature = $headers['x-signature'] ?? '';
    // Parse ts e v1 do header
    // Calcular hash esperado
    // Comparar com hash_equals()
}
```

**Critério de aceite:**
- Webhooks com assinatura válida são processados
- Webhooks com assinatura inválida retornam 401
- Webhooks sem assinatura são rejeitados (ou aceitos em dev)

---

### N3. Timeout nas Chamadas HTTP

**Prioridade:** Média  
**Esforço:** Baixo (30min)  
**Arquivos afetados:** 1

| Tarefa | Descrição |
|--------|-----------|
| Modificar `makeRequest()` | Adicionar `->timeout(30)` nas chamadas HTTP |
| Tratar timeout | Retornar `PaymentResult::failure()` com mensagem apropriada |

**Detalhes de implementação:**

```php
$request = Http::withToken($accessToken)
    ->withHeaders($headers)
    ->timeout(30)
    ->connectTimeout(10);
```

**Critério de aceite:**
- Chamadas HTTP têm timeout de 30 segundos
- Timeout de conexão de 10 segundos
- Erro de timeout retorna mensagem amigável

---

### N4. Nova Página de Configurações de Pagamento

**Prioridade:** Alta  
**Esforço:** Alto (6-8h)  
**Arquivos afetados:** 5+

| Tarefa | Descrição |
|--------|-----------|
| Criar `PaymentSettings.php` | Componente Livewire para a nova página |
| Criar `payment-settings.blade.php` | View com UI moderna e abas |
| Adicionar rota | `/admin/pagamentos` |
| Adicionar link no menu | Sidebar do admin |
| Migrar lógica de `Settings.php` | Mover métodos de pagamento para novo componente |
| Remover seção de `settings.blade.php` | Limpar página antiga |

**Estrutura da nova página (com abas):**

A página terá duas abas principais:
1. **Métodos de Pagamento** - Configuração dos métodos disponíveis no checkout
2. **Integrações** - Credenciais dos gateways de pagamento (Mercado Pago, futuramente Stripe, PagSeguro, etc.)

**Regra importante:** Os métodos de pagamento do Mercado Pago (PIX MP, Cartão de crédito MP, Cartão de débito MP) só devem aparecer na aba "Métodos de Pagamento" se o gateway Mercado Pago estiver integrado (credenciais configuradas e válidas) na aba "Integrações".

```
┌─────────────────────────────────────────────────────────────┐
│  Configurações de Pagamento                                 │
├─────────────────────────────────────────────────────────────┤
│  [Métodos de Pagamento]  [Integrações]                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ABA 1: MÉTODOS DE PAGAMENTO                                │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Métodos Manuais                                     │   │
│  ├─────────────────────────────────────────────────────┤   │
│  │ ☰ ✓ Dinheiro (na entrega)              [Editar] ▼  │   │
│  │ ☰ ✓ Cartão (na entrega)                [Editar] ▼  │   │
│  │ ☰ ✓ PIX (Chave)                        [Editar] ▼  │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Mercado Pago                    ● Integrado         │   │
│  ├─────────────────────────────────────────────────────┤   │
│  │ ☰ ○ PIX (Mercado Pago)                 [Editar] ▼  │   │
│  │ ☰ ○ Cartão de crédito (Mercado Pago)   [Editar] ▼  │   │
│  │ ☰ ○ Cartão de débito (Mercado Pago)    [Editar] ▼  │   │
│  └─────────────────────────────────────────────────────┘   │
│  * Seção acima só aparece se MP estiver integrado          │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Preview do Checkout                                 │   │
│  ├─────────────────────────────────────────────────────┤   │
│  │  ○ Dinheiro (na entrega)                           │   │
│  │  ○ Cartão (na entrega)                             │   │
│  │  ○ PIX (Chave)                                     │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│                              [Salvar Configurações]         │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  Configurações de Pagamento                                 │
├─────────────────────────────────────────────────────────────┤
│  [Métodos de Pagamento]  [Integrações]                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ABA 2: INTEGRAÇÕES                                         │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Mercado Pago                                        │   │
│  ├─────────────────────────────────────────────────────┤   │
│  │ Public Key:    [________________________]           │   │
│  │ Access Token:  [________________________]           │   │
│  │                                                     │   │
│  │ Status: ● Conectado  [Testar Conexão]              │   │
│  │                                                     │   │
│  │                         [Salvar Credenciais]        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Stripe (em breve)                                   │   │
│  ├─────────────────────────────────────────────────────┤   │
│  │ Status: ○ Não disponível                           │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ PagSeguro (em breve)                                │   │
│  ├─────────────────────────────────────────────────────┤   │
│  │ Status: ○ Não disponível                           │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

**Lógica de exibição de métodos:**

| Gateway | Condição para exibir métodos |
|---------|------------------------------|
| Manual | Sempre visível |
| Mercado Pago | Somente se `public_key` e `access_token` estiverem configurados |
| Stripe | Somente se credenciais configuradas (futuro) |
| PagSeguro | Somente se credenciais configuradas (futuro) |

**Critério de aceite:**
- Nova página acessível em `/admin/pagamentos`
- Link no menu lateral do admin
- Duas abas: "Métodos de Pagamento" e "Integrações"
- Métodos manuais sempre visíveis
- Métodos do Mercado Pago só aparecem se gateway integrado
- Credenciais configuráveis na aba "Integrações"
- Indicador de status de conexão por gateway
- Preview em tempo real (apenas métodos habilitados e disponíveis)
- Página antiga de configurações sem seção de pagamentos
- Preparado para futuros gateways (Stripe, PagSeguro)

---

### N5. Testes Unitários

**Prioridade:** Média  
**Esforço:** Médio (3-4h)  
**Arquivos afetados:** 4 novos

| Tarefa | Descrição |
|--------|-----------|
| Criar `EncryptionHelperTest.php` | Testes para encrypt/decrypt |
| Criar `PaymentMethodTest.php` | Testes para enum |
| Criar `OrderServiceTest.php` | Testes para criação de pedidos |
| Criar `MercadoPagoGatewayTest.php` | Testes com mocks HTTP |

**Casos de teste:**

**EncryptionHelper:**
- `encryptIfNeeded()` com null → null
- `encryptIfNeeded()` com string vazia → null
- `encryptIfNeeded()` com valor já criptografado → mesmo valor
- `encryptIfNeeded()` com valor novo → valor com prefixo `enc:`
- `decryptIfNeeded()` com null → null
- `decryptIfNeeded()` com valor não criptografado → mesmo valor
- `decryptIfNeeded()` com valor criptografado → valor original
- `decryptIfNeeded()` com valor inválido → null

**PaymentMethod:**
- `isMercadoPago()` retorna true para MP_PIX, MP_CREDIT_CARD, MP_DEBIT_CARD
- `isMercadoPago()` retorna false para métodos manuais
- `isManual()` retorna true para CASH_ON_DELIVERY, CARD_ON_DELIVERY, PIX_MANUAL
- `allIds()` retorna todos os 6 IDs
- `manualIds()` retorna 3 IDs
- `mercadoPagoIds()` retorna 3 IDs
- `defaultConfig()` retorna array com estrutura correta

**OrderService:**
- `createFromCart()` cria Customer, Order e OrderItems
- `createFromCart()` com cliente existente atualiza dados
- `resolveCartLines()` ignora produtos inexistentes
- `calculateSubtotal()` soma corretamente

**MercadoPagoGateway:**
- `createPayment()` sucesso retorna PaymentResult::success
- `createPayment()` falha retorna PaymentResult::failure
- `createPayment()` sem credenciais retorna erro
- `handleWebhook()` atualiza order e payment
- `validateSignature()` aceita assinatura válida
- `validateSignature()` rejeita assinatura inválida

**Critério de aceite:**
- Todos os testes passando
- Cobertura > 80% nos componentes testados

---

## Tarefas Opcionais

### O1. Indicador de Status de Conexão MP

**Prioridade:** Baixa  
**Esforço:** Baixo (1-2h)

| Tarefa | Descrição |
|--------|-----------|
| Criar método `testConnection()` | Chamar API do MP para validar credenciais |
| Exibir status na UI | Ícone verde/vermelho com tooltip |

---

### O2. Histórico de Pagamentos na Página

**Prioridade:** Baixa  
**Esforço:** Médio (2-3h)

| Tarefa | Descrição |
|--------|-----------|
| Adicionar seção de histórico | Lista dos últimos 10 pagamentos |
| Filtros básicos | Por status, método |
| Link para pedido | Navegar para detalhes do pedido |

---

### O3. Drag-and-Drop para Reordenar Métodos

**Prioridade:** Baixa  
**Esforço:** Médio (2-3h)

| Tarefa | Descrição |
|--------|-----------|
| Integrar SortableJS | Biblioteca de drag-and-drop |
| Atualizar ordem via Livewire | Persistir nova ordem |

---

## Ordem de Execução

### Fase 1: Infraestrutura (Necessário)

```
N3 (timeout) → N2 (validação webhook) → N1 (integrar Payment)
```

### Fase 2: UI (Necessário)

```
N4 (nova página de configurações)
```

### Fase 3: Qualidade (Necessário)

```
N5 (testes unitários)
```

### Fase 4: Melhorias (Opcional)

```
O1, O2, O3 (em paralelo)
```

---

## Estimativa Total

| Categoria | Tarefas | Esforço Estimado |
|-----------|---------|------------------|
| Necessárias | N1-N5 | 15-20h |
| Opcionais | O1-O3 | 5-8h |
| **Total** | 8 tarefas | **20-28h** |

---

## Critérios de Conclusão da Sprint

### Mínimo Viável (Necessárias)

- [ ] N1: Modelo Payment integrado no fluxo
- [ ] N2: Validação de assinatura do webhook implementada
- [ ] N3: Timeout configurado nas chamadas HTTP
- [ ] N4: Nova página de configurações de pagamento
- [ ] N5: Testes unitários criados e passando

### Completo (Necessárias + Opcionais)

- [ ] Todos os itens acima
- [ ] O1: Indicador de status de conexão
- [ ] O2: Histórico de pagamentos
- [ ] O3: Drag-and-drop para reordenar

---

## Checklist de Deploy

1. [ ] Executar migration (se houver novas)
2. [ ] Verificar se testes passam: `docker compose exec app php artisan test`
3. [ ] Testar fluxo de checkout manual
4. [ ] Testar fluxo de checkout Mercado Pago
5. [ ] Testar webhook com assinatura válida
6. [ ] Testar webhook com assinatura inválida (deve rejeitar)
7. [ ] Verificar nova página de configurações
8. [ ] Verificar se configurações existentes foram preservadas

---

## Notas Técnicas

### Validação de Assinatura do Mercado Pago

Documentação: https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks

```
Header: x-signature: ts=1234567890,v1=abc123...
Header: x-request-id: request-id-value

Template: id.ts.secret
Hash: HMAC-SHA256(template, webhook_secret)
```

### Configuração de Timeout HTTP

```php
Http::timeout(30)        // Timeout total da requisição
    ->connectTimeout(10) // Timeout de conexão
    ->retry(2, 100)      // Opcional: retry em caso de falha
```

### Estrutura de Testes

```
tests/
├── Unit/
│   ├── Support/
│   │   └── EncryptionHelperTest.php
│   ├── Enums/
│   │   └── PaymentMethodTest.php
│   ├── Services/
│   │   └── OrderServiceTest.php
│   └── Gateways/
│       └── MercadoPagoGatewayTest.php
└── Feature/
    └── (testes de integração futuros)
```
