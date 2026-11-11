# Relatório final — Sprint 06: Refactor & Hardening

**Data:** 2026-11-10
**Commit de referência:** `1184a8a` (`main`, igual ao `origin/main`)
**CI:** run `42943995108`, verde (build do Vite, Pint, PHPStan nível 5, PHPUnit em PostgreSQL)

> Este documento fecha a sprint. Ele foi escrito depois de reler os seis documentos de `.sprint/06-refactor/` e de **medir o código, o banco e os gates de novo**, sem herdar números dos logs. Onde um log antigo divergiu da realidade, vale este arquivo. O histórico de execução está em `05-development.md`; a auditoria critério a critério, em `05-development.md` §18; o isolamento de tenant, em §20.

---

## 1. Veredito

**Os 23 critérios obrigatórios da sprint estão satisfeitos e provados por teste.** Dois critérios do plano original foram **dispensados como obrigatórios por decisão do dono** em 2026-11-10 (#2 e #22): não estão cumpridos, estão fora do que a entrega exige. Nada foi dado como cumprido sem teste.

A sprint **não é uma feature**: ela construiu as camadas transversais que faltavam (isolamento de tenant, autenticação e autorização, integridade de dados, pagamentos consistentes, design system e rede de testes). Além do escopo do plano, entregou por pedido do dono a migração do Mercado Pago para a API de Orders, o Pix em modal com janela de 5 minutos, a retirada e entrega com taxa por bairro e o fechamento do isolamento de tenant na escrita.

| | Início da sprint | Hoje |
|---|---|---|
| Critérios de conclusão (§6 de `02-sprint.md`) | 0 de 25 | **23 de 23 obrigatórios** (#2 e #22 dispensados) |
| Testes PHP | 89 (todos unitários, SQLite) | **1631** passando, 5 skipped (4 precisam de Node, 1 lê o `docker-compose.yml`) |
| Banco dos testes | SQLite em memória | **PostgreSQL 16 real** |
| Análise estática | inexistente | **PHPStan nível 5, sem erros** |
| Formatação | Pint nunca rodado | **Pint PASS, 300 arquivos** |
| CI | inexistente | **GitHub Actions verde** |

---

## 2. Metas do plano (`02-sprint.md` §1.4), medidas hoje

| Métrica | Meta | Hoje | Status |
|---|---|---|---|
| Arquivos versionados | 100% do projeto | 443 arquivos, 0 pendentes, remoto igual ao local | ✅ |
| Foreign keys | todas as relações | **25** (eram 2); 0 tabelas com `tenant_id` sem FK para `tenants` | ✅ |
| Testes de feature | ≥ 40 | **799** métodos em `tests/Feature` (284 em `tests/Unit`) | ✅ |
| Testes de isolamento cross-tenant | ≥ 8 | **43** com nome de isolamento, em 8 arquivos de `Tenancy/` | ✅ |
| Chamadas `forTenant()` manuais | 0 | **66 chamadas em `app/`** | ❌ **não atingida** (ver §4.1) |
| Ações de admin sem `authorize()` | 0 | 0, com auditoria estrutural que morde | ✅ |
| Componentes Blade | ≥ 8 | **20** componentes, 467 usos | ✅ |
| `style=` inline | ≤ 5 | **1** (eram 49) | ✅ |
| Banco de testes | PostgreSQL | PostgreSQL (`db_test`) | ✅ |
| CI | verde e obrigatório | verde; **não obrigatório** (sem branch protection) | 🟡 dispensado (#2) |
| PHPStan | ≥ 5 sem erros | nível 5, sem erros | ✅ |
| Layout admin | < 150 linhas | **110** linhas (eram 705) | ✅ |

---

## 3. Os 25 critérios de conclusão

Prova = o teste ou a medição que sustenta o veredito. Todos reproduzíveis com `composer lint`, `composer analyse`, `php artisan test` e as consultas ao banco citadas.

| # | Critério | Veredito | Prova |
|---|---|---|---|
| 1 | Projeto versionado e no remoto | ✅ | `git ls-files` = 443; `main` = `origin/main`; CI verde |
| 2 | CI obrigatório e verde (+ Playwright) | ⚪ **dispensado** | CI verde com Pint, PHPStan e PHPUnit. Sem Playwright no CI e **sem branch protection** (a API do GitHub responde 404) |
| 3 | Nenhuma query de model ignora o tenant sem opt-out | ✅ | Leitura filtrada, escrita por instância barrada, re-hidratação do Livewire escopada. Ver §5 |
| 4 | Nenhuma action de admin sem `authorize()` | ✅ | `LivewireAdminAuthorizationAuditTest`; adicionar um `sneakyDelete()` sem `authorize` o derrubou nomeando o método |
| 5 | `tenant_staff` removido; `Role` com escopo | ✅ | Só restam comentários e testes que provam que ele não é aceito |
| 6 | `users.tenant_id` com FK e nullable | ✅ | `is_nullable=YES` e 1 FK no banco |
| 7 | Global scope nos 3 contextos | ✅ | `TenantScopeTest`, `TenantContext` |
| 8 | Nenhum middleware deixa passar não autenticado | ✅ | `RouteMiddlewareAuditTest`; uma rota de tenant sem middleware o derrubou |
| 9 | FKs em tudo; pivôs com `tenant_id` | ✅ com ressalva | 25 FKs; `category_product` e `showcase_product` com `tenant_id` e FK composta. Ressalva: `showcases.category_id`, `orders.customer_id` e `order_items.product_id` sem FK composta por tenant |
| 10 | `track_inventory` opt-in com concorrência | ✅ | `InventoryConcurrencyTest` (processos PHP separados) |
| 11 | Checkout ponta a ponta com gateway fake | ✅ | `FakeGateway`; prova no nível HTTP/serviço |
| 12 | `TYPE_ALL` respeita `max_items` | ✅ | Testes de limite e do teto de 100 |
| 13 | `/` serve landing sem tenant hardcoded | ✅ | `LandingTest` |
| 14 | Colunas monetárias em `bigint` | ✅ | 9 de 9 colunas `*_cents` em `bigint`, com 9 CHECKs `>= 0` |
| 15 | Status como enum com CHECK | ✅ | `orders_status_valid`, `orders_payment_status_valid`, `orders_fulfillment_type_valid`. `payments.status` fica string de propósito (guarda o status bruto do gateway) |
| 16 | Carrinho em um lugar só | ✅ | Nenhum acesso a `cart` na sessão fora do `CartService` |
| 17 | Webhook idempotente, assinatura antes de consultar | ✅ | `WebhookIdempotencyTest`, `OrdersWebhookTest`, `PaymentConcurrencyTest`. Exceção documentada: a rota `/webhooks/{gateway}` sem tenant |
| 18 | Nenhuma credencial em claro no Livewire | ✅ | `PaymentSettingsWriteOnlyCredentialsTest`, `PaymentSettingsWebhookGuideTest` |
| 19 | Componentes Blade; ≤ 5 `style=` | ✅ | 20 componentes, 467 usos, 1 `style=` |
| 20 | Tailwind removido; CDNs no Vite | ✅ com ressalva | Tailwind fora. Externos que restam: SDK e `security.js` do MP, gtag e o Google Fonts (4 fontes de uma allow-list). O critério vale para script e CSS, não para fontes |
| 21 | ≥ 40 testes de feature; ≥ 8 de isolamento | ✅ | 799 e 43 |
| 22 | E2E do checkout verde | ⚪ **dispensado** | Existe e roda na máquina do dono (193 testes locais), mas `src/e2e/` está fora do git e do CI |
| 23 | Itens herdados com status explícito | ✅ | `06-inherited-items.md`, 52 itens, nenhum sem status |
| 24 | README reflete a realidade | ✅ | Cobre Orders, webhook, Pix, `TRUSTED_PROXIES`, `scheduler`, retirada e entrega, Device ID, o nome `quitanda.app` |
| 25 | Decisões de produto registradas | ✅ | `04-decisions.md` (D1 a D8) |

---

## 4. Divergências assumidas em relação ao plano

### 4.1 E1.2 não foi feito: 66 chamadas de `forTenant()` continuam
O plano previa remover as chamadas manuais depois do global scope (meta: 0). Foi **adiado de propósito** e continua adiado. Motivo: depois de provado o escopo, a remoção é só limpeza, e ela mexe em dezenas de arquivos sem ganho de segurança (o escopo já filtra; as chamadas são redundantes, não perigosas). O risco real é de leitura e manutenção, não de vazamento. Fica para a Sprint 07 ou para uma limpeza dedicada.

### 4.2 Playwright e branch protection fora do que a entrega exige
Decisão do dono: `src/e2e/` não vai para o repositório. O E2E roda localmente (193 testes: 137 da suíte regular e 56 de retirada e entrega), com snapshots visuais, e acha bugs que testes PHP não acham (achou 13 e depois 7). Mas **nada impede um merge com a suíte E2E quebrada**.

### 4.3 Entregas fora do plano original
Pedidas pelo dono durante a sprint: migração do Mercado Pago da API de Payments para a de Orders, Pix direto sem Brick, Pix em modal com janela de 5 minutos e contador, vencimento do Pix com cancelamento do pedido e devolução do estoque, retirada e entrega com taxa por bairro e pedido mínimo, Device ID antifraude, `TrustProxies`, marca `quitanda.app`, rodapé e títulos de aba, e correção do azul padrão do Bootstrap e dos conflitos claro/escuro.

### 4.4 Decisão D6 e a configuração de entrega
A D6 manda não pôr capabilities e planos em `tenants.settings` (JSONB). A configuração de retirada e entrega (incluindo a lista de bairros, até 100) **fica em `tenants.settings`**. A D6 trata de planos e capabilities, não de configuração operacional da loja, então não a contradiz; mas uma tabela dedicada de bairros é a evolução natural se a lista crescer.

---

## 5. O isolamento de tenant (critério #3), como ficou

Era o ponto mais delicado. O global scope só filtrava **leitura**; escrita por instância e re-hidratação do Livewire ignoravam o escopo. Fechado em `b7b1be5`:

| Caminho | Antes | Agora |
|---|---|---|
| Leitura por query builder | filtrada | igual |
| `save/update/delete/restore/forceDelete` de instância | sem barreira | `CrossTenantWriteException` se a linha (atual ou original) for de outro tenant |
| Mover uma linha para outro tenant; criar já em outro tenant | possível | recusado |
| `public Model $x` do Livewire re-hidratado | sem scopes; só o checksum protegia | restaurado pelo escopo e pelo soft delete: 404, mesmo com checksum válido |
| Model novo sem a trait, opt-out novo, SQL cru novo | nada impedia | `TenantIsolationAuditTest` com listas fechadas e justificadas |

**Provas:** `TenantWriteIsolationTest` (39), `ForgedSnapshotAcrossTenantsTest` (5, inclui um snapshot com checksum válido gerado por `Checksum::generate`), `TenantIsolationAuditTest` (4). Quatro mutações derrubam de 5 a 22 testes cada.

**O que o isolamento ainda não é:**
- **Sem tenant no contexto nada é barrado** (plataforma, job, seeder, comando, webhook). É decisão de arquitetura. O painel de plataforma da Sprint 07 precisa usar `runForPlatform` de forma explícita.
- **SQL cru** (2 usos em `app/`) e **2 opt-outs do escopo** estão fora da barreira. Estão justificados e travados por teste, mas dependem de revisão humana quando alguém criar um novo.
- **Autorização é outra camada.** O isolamento barra o acesso entre lojas; quem decide se o ator pode escrever são as policies e os guards.
- **Uma regressão aparece como erro 500 com log**, não como dado vazado.

---

## 6. Pagamentos (Mercado Pago), como ficou

- **API de Orders.** A de Payments recusava toda cobrança com `401 · código 7 · Unauthorized use of live credentials` nesta aplicação; a mesma credencial cria orders (201). Descoberto por experimento direto na API, não por suposição: a causa não era localhost, e-mail, JavaScript nem cabeçalhos.
- **Cartão:** validado de ponta a ponta pelo dono. **Pix:** validado pelo dono com credenciais de produção (a conta de teste não gera QR).
- **Webhook** do tópico `order`, idempotente e com assinatura validada antes de qualquer consulta. O estado vem sempre de `GET /v1/orders/{id}`, nunca do corpo do webhook.
- **Reconciliação** sem webhook (polling do checkout e `payments:reconcile`), pelo mesmo caminho idempotente.
- **Pix:** modal com contador, janela de **5 minutos** imposta pelo servidor (o MP só aceita validade de 30 minutos ou mais), cancelamento da order no MP e só depois do pedido local, nunca cancelando um pedido pago. O estoque volta uma vez.
- **Vencimento sempre resolve:** Pix sem prazo gravado, order sem QR e order inexistente para as credenciais atuais também viram pedido cancelado e estoque devolvido. Medido no banco de dev: o agendador cancelou 5 Pix presos sozinho.
- **Segurança de dinheiro:** o cliente só envia tipo de recebimento, bairro e endereço. Taxa, mínimo e total são sempre recalculados no servidor, e o gateway é cobrado por esse total.

---

## 7. Retirada e entrega (itens herdados 11 e 41)

- **Item 11:** `OrderService::createFromCart` criava pedido com total 0 e 0 itens com carrinho vazio. Agora lança `EmptyCartException` antes de gravar qualquer coisa.
- **Item 41:** o admin oferecia mínimo, taxa e frete grátis, mas o checkout só gravava retirada com taxa 0. Agora: liga/desliga de retirada e de entrega, taxa adicional na retirada, taxa de entrega por bairro (nome e preço) ou padrão, frete grátis só na entrega, pedido mínimo sobre o subtotal para todo pedido, endereço obrigatório na entrega, snapshot em `orders.meta.fulfillment`. Sem configuração a loja se comporta como antes.
- **Provas:** cerca de 90 testes novos (domínio, serviço, Livewire, HTTP do `process_payment`, admin, carrinho e seed). Sete mutações derrubam de 2 a 7 testes cada. O E2E local (56 testes) achou 7 defeitos reais, 5 deles corrigidos.

---

## 8. Itens herdados das sprints 01 a 05

52 itens classificados em `06-inherited-items.md` (contagem recalculada por script sobre a coluna de status):

| Status | Qtd |
|---|---|
| Resolvido | 23 |
| Aceito | 3 |
| Adiado | 9 |
| Parcial | 1 |
| Aberto | 14 |
| Não verificado | 2 |

Os abertos relevantes: pedido mínimo e carrinho vazio **já resolvidos** (11 e 41); fila e retry do webhook (4b, adiado), cobertura de teste e assinatura do webhook contra o MP real (24 e 26, precisam de ambiente), checklist de deploy do segredo do webhook (27, operacional). Os demais abertos são P4 (cosméticos, UX ou documentação).

---

## 9. O que NÃO está provado

- **Cancelamento de uma order Pix em `action_required` no Mercado Pago em produção.** A sandbox só provou o cancelamento de order manual e o 409 em order `processing`/`processed`. Se o MP recusar, o pedido fica pendente e o log traz `cancel_failed`. Como validar sem risco: gerar o Pix de R$ 6,50, não pagar, esperar ~5 min 15 s, conferir o modal e o pedido, e consultar a order com `GET /v1/orders/{id}`.
- **Webhook real do Mercado Pago:** nenhuma entrega real foi vista. O formato e a assinatura vêm da documentação e do SDK.
- **Entrega com Pix de verdade:** a taxa de entrega no valor cobrado pelo MP só foi provada com o MP simulado. O corpo adulterado só foi provado nas recusas 422 antes do gateway.
- **Navegadores:** só Chromium (E2E e varredura de tema). Safari e Firefox não foram testados (máscara do accordion, `color-mix`, `radial-gradient` do switch). Leitor de tela real não foi testado.
- **Débito:** a API de Orders só aceita Elo e débito virtual Caixa. A conta de teste recusou até o Elo.
- **Concorrência em outro ambiente:** os testes de concorrência com processos PHP separados passam no CI e na máquina do dono; não foram vistos sob carga de produção.
- **Cobertura de testes (item 24 herdado):** nunca medida (sem pcov/Xdebug).

---

## 10. Riscos e pendências para produção

1. **`schedule:run` em produção.** O repositório define o agendador só no Docker de desenvolvimento (serviço `scheduler`). Em produção é preciso um cron (`* * * * * php artisan schedule:run`) ou um processo equivalente. **Sem ele, o estoque de quem fecha a aba só volta quando alguém consulta o status.**
2. **Lojas com configuração antiga.** Uma loja que já tinha pedido mínimo ou taxa gravados passa a tê-los aplicados na retirada; e a taxa e o tempo somem do cabeçalho se `delivery.enabled` não estiver ligado. Pode surpreender o lojista.
3. **Webhook em produção:** cadastrar a URL (evento Order) no painel do MP e colar o segredo gerado em Admin → Pagamentos. Sem o segredo, os webhooks são rejeitados com 401 (por segurança) e só a reconciliação confirma o pagamento.
4. **`TRUSTED_PROXIES`:** atrás de proxy HTTPS (túnel, Cloudflare, load balancer) é preciso configurar; sem isso o app gera links `http://` e o navegador bloqueia os estilos.
5. **Pagamento tardio sobre pedido cancelado:** se o MP aprovar depois de um cancelamento local (sem QR, ou `order_not_found` sob outra conta), o sistema registra `payment.incident.paid_on_cancelled_order`, mas o reembolso é manual.
6. **Cobrança aceita pelo MP sem `gateway_payment_id`** (resposta perdida) fica fora da varredura de vencimento, que precisa do id para consultar.
7. **`aria-describedby`:** o campo com erro tem `aria-invalid`, mas nada o liga à mensagem para leitores de tela. Vale para todos os formulários do app; fica como teste de falha esperada no E2E local.
8. **Premissa de `migrate:fresh` expira no primeiro cliente real.** A sprint editou migrations no lugar enquanto não havia dados de produção. A partir do primeiro cliente, só migrations aditivas (a do CHECK de `fulfillment_type` já foi aditiva).

---

## 11. Decisões de produto pendentes (para a Sprint 07)

- **Painel de administrador de plataforma** e impersonation, que dependem do isolamento fechado na §5 e de um **log de auditoria** (pré-requisito, D2).
- **OTP via WhatsApp** para o login do cliente (D3); hoje o telefone sozinho autentica, risco aceito e coberto por teste-marcador.
- **Débito:** aceitar que Visa e Mastercard débito deixam de funcionar pela API de Orders?
- **Pix que o MP nunca gera:** hoje o pedido sem QR é cancelado localmente depois da janela; decidir se isso basta.
- **Janela de 5 minutos e tolerância de 10 segundos** do Pix: confirmar.
- **CPF para Pix e cartão em produção:** a sandbox não exigiu; em produção pode diferir.
- **Branch protection** no GitHub (um clique), se o dono quiser o CI como gate.

---

## 12. Verificação que sustenta este relatório

Executada em 2026-11-10 sobre o commit `1184a8a`:

| Verificação | Resultado |
|---|---|
| `php artisan test` (PostgreSQL) | 1631 passando, 5 skipped, 8894 assertions |
| `composer lint` | PASS, 300 arquivos |
| `composer analyse` | PHPStan nível 5, sem erros |
| `npm run build` | ok |
| CI no GitHub | run `42943995108` verde |
| Migrations pendentes | 0 |
| Consultas ao banco | 25 FKs; `users.tenant_id` nullable; 9 colunas `*_cents` em `bigint`; 3 CHECKs de enum em `orders`; soft deletes em `products`, `categories`, `customers` e `showcases` |
| Mutações dos testes de segurança | `TenantWriteIsolationTest`, `TenantIsolationAuditTest`, auditoria de `authorize()` e de rotas: cada uma derruba o teste ao quebrar a regra de propósito |

**Arquivos da sprint:** `01-review.md` (auditoria pré-sprint), `02-sprint.md` (plano), `03-design-system.md`, `04-decisions.md` (D1 a D8), `05-development.md` (log de execução, auditoria §18, isolamento §20), `06-inherited-items.md` (itens herdados) e este `07-final-report.md`.
