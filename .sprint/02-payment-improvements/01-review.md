# Review - Sprint 02: Payment Improvements

**Data:** 2026-01-09  
**Baseado em:** Sprint 01-payment (04-qa-review.md)

---

## Contexto

A Sprint 01-payment implementou a refatoração da arquitetura de pagamentos com sucesso. Durante a revisão de QA, foram identificados pontos que precisam ser endereçados para completar a implementação e melhorar a experiência do usuário.

---

## Pontos Pendentes da Sprint Anterior

### 1. QA-011: Modelo Payment Não Utilizado

**Severidade:** Média  
**Descrição:** O modelo `Payment` foi criado com migration, mas não está integrado ao fluxo de pagamento. Os dados de pagamento continuam sendo salvos apenas em `order.meta`.

**Impacto:**
- Não há histórico de tentativas de pagamento
- Não é possível ter múltiplos pagamentos por pedido (ex: pagamento parcial, retry)
- Queries de pagamento são complexas (JSON path)
- Não há como gerar relatórios de pagamentos facilmente

**Solução proposta:**
- Integrar criação de `Payment` no `MercadoPagoGateway::createPayment()`
- Atualizar `Payment` no `MercadoPagoGateway::handleWebhook()`
- Manter `order.meta.mercadopago` para compatibilidade, mas usar `Payment` como fonte primária

---

### 2. QA-005: Webhook Sem Validação de Assinatura

**Severidade:** Média  
**Descrição:** O endpoint `/webhooks/mercadopago` não valida a assinatura do Mercado Pago (header `x-signature`). Qualquer requisição é processada.

**Impacto:**
- Vulnerabilidade de segurança: atacante pode forjar webhooks
- Possível manipulação de status de pagamento
- Risco de fraude

**Solução proposta:**
- Implementar validação de assinatura conforme documentação do MP
- Rejeitar webhooks com assinatura inválida (HTTP 401)
- Logar tentativas de webhook inválido

---

### 3. QA-004: Sem Timeout nas Chamadas HTTP

**Severidade:** Baixa  
**Descrição:** As chamadas HTTP para a API do Mercado Pago não têm timeout configurado. Em caso de lentidão da API, a requisição pode travar indefinidamente.

**Impacto:**
- Experiência ruim para o usuário (checkout travado)
- Possível esgotamento de workers PHP
- Timeout do nginx/browser antes do PHP

**Solução proposta:**
- Configurar timeout de 30 segundos para chamadas HTTP
- Retornar erro amigável em caso de timeout

---

### 4. Testes Unitários

**Severidade:** Baixa  
**Descrição:** Não há testes automatizados para os componentes críticos criados na sprint anterior.

**Impacto:**
- Risco de regressão em futuras alterações
- Dificuldade de validar comportamento esperado
- Menor confiança no código

**Solução proposta:**
- Criar testes unitários para:
  - `EncryptionHelper`
  - `PaymentMethod` enum
  - `OrderService`
  - `MercadoPagoGateway` (com mocks)

---

## Nova Funcionalidade: Página de Configurações de Pagamento

### Problema Atual

As configurações de pagamento estão na página geral de "Configurações" do tenant (`/admin/configuracoes`), junto com outras configurações como slug. Isso causa:

- Interface poluída com muitas informações
- Dificuldade de encontrar configurações específicas
- Não há espaço para expandir funcionalidades de pagamento
- UX confusa para lojistas

### Solução Proposta

Criar uma página dedicada para configurações de pagamento (`/admin/pagamentos`) com:

1. **Lista de métodos de pagamento**
   - Cards expandíveis para cada método
   - Toggle de ativação/desativação
   - Drag-and-drop para reordenar
   - Configurações específicas por método

2. **Seção de credenciais por gateway**
   - Mercado Pago: public_key, access_token
   - Preparado para futuros gateways (Stripe, PagSeguro)
   - Indicador de status de conexão (válido/inválido)

3. **Preview de checkout**
   - Visualização de como os métodos aparecerão no checkout
   - Atualização em tempo real

4. **Histórico de pagamentos** (opcional)
   - Lista de pagamentos recentes
   - Filtros por status, método, período

### Benefícios

- Interface mais limpa e focada
- Melhor UX para configuração de pagamentos
- Espaço para crescimento (novos gateways, funcionalidades)
- Separação de responsabilidades na UI

---

## Arquivos Afetados

### Novos Arquivos

| Arquivo | Descrição |
|---------|-----------|
| `app/Livewire/Admin/Tenant/PaymentSettings.php` | Componente Livewire da nova página |
| `resources/views/livewire/admin/tenant/payment-settings.blade.php` | View da nova página |

### Arquivos Modificados

| Arquivo | Mudança |
|---------|---------|
| `routes/web.php` | Nova rota `/admin/pagamentos` |
| `app/Gateways/MercadoPagoGateway.php` | Integrar Payment, validar assinatura, timeout |
| `app/Livewire/Admin/Tenant/Settings.php` | Remover seção de pagamentos |
| `resources/views/livewire/admin/tenant/settings.blade.php` | Remover seção de pagamentos |
| `resources/views/layouts/tenant-admin.blade.php` | Adicionar link no menu |

### Testes

| Arquivo | Descrição |
|---------|-----------|
| `tests/Unit/Support/EncryptionHelperTest.php` | Testes do helper |
| `tests/Unit/Enums/PaymentMethodTest.php` | Testes do enum |
| `tests/Unit/Services/OrderServiceTest.php` | Testes do service |
| `tests/Unit/Gateways/MercadoPagoGatewayTest.php` | Testes do gateway |

---

## Dependências

- Sprint 01-payment concluída ✅
- Migration de `payments` executada ✅

---

## Riscos

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| Quebra de compatibilidade ao mover configurações | Baixa | Médio | Manter dados em `tenant.settings`, apenas mudar UI |
| Validação de assinatura rejeitar webhooks válidos | Média | Alto | Testar exaustivamente com sandbox do MP |
| Testes falharem em CI por falta de mock | Média | Baixo | Configurar mocks corretamente |

---

## Critérios de Aceite

1. Modelo `Payment` é criado e atualizado em todos os fluxos de pagamento
2. Webhooks com assinatura inválida são rejeitados
3. Chamadas HTTP têm timeout de 30 segundos
4. Nova página de configurações de pagamento funcional
5. Testes unitários passando com cobertura > 80% nos componentes críticos
6. Configurações existentes continuam funcionando (retrocompatibilidade)
