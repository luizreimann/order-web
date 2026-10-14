# Itens herdados das sprints 01–05 — triagem (E6.2 / QA-006)

**Sprint:** 06-refactor
**Origem:** `01-review.md` §7 (QA-006) e os documentos de QA/review/dev das sprints 01–05.
**Método:** cada item foi conferido contra o **código e os testes do working tree** no momento da triagem, não contra o que o documento de origem afirma. Onde o documento de origem estava errado, está dito.

> Prefixos: `S01`..`S05` indicam a sprint de origem (os códigos `QA-001`.. da sprint 01 colidem com os `QA-001`.. do `01-review.md` desta sprint, por isso o prefixo).
> Referências `arquivo:linha` de origem apontam para `.sprint/0X-*/`. Referências de evidência apontam para `src/` quando não há prefixo.

## Legenda de status

| Status | Significado |
|---|---|
| **RESOLVIDO** | Corrigido no código, com teste ou verificação reproduzível citada |
| **ACEITO** | Decisão registrada em documento (D3 etc.) ou comportamento deliberado, documentado e fixado por teste |
| **ADIADO** | Fora da sprint 06 com motivo. `(§8)` = já estava em `02-sprint.md` §8; `(proposto)` = proposta desta triagem, ainda sem registro em `04-decisions.md` |
| **ABERTO** | Existe hoje, sem decisão nem dono |
| **NÃO VERIFICADO** | Não foi possível comprovar; a coluna de evidência diz o que faltaria |

## 1. Tabela do `01-review.md` §7 (QA-006), reconciliada

| # | Item | Origem | Status | Evidência |
|---|---|---|---|---|
| 1 | QA-011: model `Payment` criado e não usado | `01-payment/04-qa-review.md:263` | **RESOLVIDO** | `app/Services/PaymentService.php:95` cria o `Payment` (tentativa persistida antes de chamar o gateway, `amount_cents` do pedido, `idempotency_key`). `PaymentConsistencyTest::test_the_payment_attempt_is_persisted_before_the_gateway_is_called_with_the_idempotency_key`, `PaymentIntegrityTest`. Commit `46c561d`. O review dizia "parcialmente resolvido" |
| 2 | QA-005: webhook não valida `x-signature` | `01-payment/04-qa-review.md:136` | **RESOLVIDO** | Assinatura validada com o secret do tenant antes de qualquer consulta: `WebhookIdempotencyTest::test_invalid_signature_is_rejected_even_when_the_order_does_not_exist_and_nothing_is_consulted`, `::test_signature_made_with_another_tenants_secret_is_rejected`, `FakeGatewayEndToEndTest::test_webhook_with_a_bad_signature_is_rejected_before_anything_is_consulted`, `MercadoPagoGatewayTest::test_validate_webhook_signature_*`. Commit `46c561d`. Ver linha 26 sobre validação contra o sandbox real |
| 3 | Duplicação de Customer/Order entre `Checkout` e `TenantPaymentController` | `01-payment/01-review.md:142-148` | **RESOLVIDO** | Os dois caminhos chamam `OrderService::createFromCart` (`Livewire/Tenant/Checkout.php:211`; `PaymentService.php:87`, usado pelo controller). Carrinho único em `CartService` (`CartUnificationTest`, `CartServiceTest`). Commits `667bb54`, `46c561d` |
| 4 | Webhook sem retry/queue/timeout/dead letter | `01-payment/01-review.md:168-173` | Dividido nas linhas 4a–4c | O review marcava tudo como "Aberto"; timeout já existe |
| 4a | … timeout nas chamadas HTTP | idem | **RESOLVIDO** | `app/Gateways/MercadoPagoGateway.php:302-303` (`timeout(30)`, `connectTimeout(10)`). Comportamento em falha: `PaymentConsistencyTest::test_a_gateway_timeout_cancels_the_order_and_returns_the_stock`. Não há teste que assere os valores numéricos. Limite: `makeRequest` só captura `ConnectionException` (`:315`) |
| 4b | … retry próprio, fila e dead-letter do webhook | idem | **ADIADO** (§8) | `02-sprint.md` §8 ("Webhook com fila, retry e dead-letter queue; ARC-005 entrega apenas idempotência"). Hoje: processamento síncrono; se a API do gateway está fora responde 503 e depende do reenvio do próprio gateway (`PaymentWebhookProcessor`; `.sprint/06-refactor/05-development.md` §11, E3.3). Não existe `app/Jobs` |
| 4c | … verificação periódica de pagamentos pendentes (reconciliação) | `01-payment/01-review.md:172`, `01-payment/02-sprint.md:181` (O4) | **ABERTO** | Não existe `app/Console`, comando nem `Schedule::`. PIX sem webhook fica `pending` até alguém agir. Não está em `02-sprint.md` §8: precisa de dono (sugestão: junto da fila/retry, sprint 07) |
| 5 | VAL-001/002: `tenant_id` em `category_id` e produtos da vitrine | `03-showcases/04-qa-review.md:96,115` | **RESOLVIDO** | `Admin/Showcases/Form.php:86-97` (`Rule::exists(...)->where('tenant_id', ...)->whereNull('deleted_at')`). **O review marcava "Aberto" por engano**: o código já estava assim no commit `900c3dc`, anterior à sprint 06. Prova nova nesta triagem: `ShowcaseFormValidationTest::test_category_from_another_tenant_is_rejected`, `::test_products_from_another_tenant_are_rejected` (+ controles positivos). Resíduo no banco: ver linha 31 |
| 6 | VAL-004: race no limite de 10 vitrines | `03-showcases/04-qa-review.md:145` | **RESOLVIDO** | `Showcase::createWithinLimit()` (transação + `FOR NO KEY UPDATE` na linha do tenant antes de contar e inserir); usado por `Admin/Showcases/Form.php`. `ShowcaseLimitConcurrencyTest` (processos PHP separados em PG): sem o lock os 3 testes falham (verificado removendo o `lock`), com o lock passam. `ShowcaseLimitTest` cobre a regra e o formulário pelo endpoint real |
| 7 | MEL-006: faltam testes de integração/feature | `03-showcases/04-qa-review.md:221` | **RESOLVIDO** | Componentes Livewire de vitrine pelo endpoint real: `ShowcaseLimitTest`, `ShowcaseFormValidationTest`, `AdminActionsAuthorizationTest` (toggle/delete/updateOrder), `PivotTenantIntegrityTest`, `SoftDeletesTest`. Rotas e auth: `RouteMiddlewareAuditTest`. Suíte inteira em PostgreSQL (89 testes no início da sprint 06; ver `05-development.md` §1 para o total atual) |
| 8 | Login sem verificação — "risco alto" | `05-buyer-experience/01-review.md:721` | **ACEITO** | D3 (`04-decisions.md:105-131`): telefone sem verificação é risco aceito; OTP via WhatsApp fica para sprint 07+ (`02-sprint.md` §8). Regra de projeto no `README.md` ("Riscos aceitos"): a área do cliente não recebe dado sensível. `CustomerGuardTest::test_d3_accepted_risk_phone_alone_authenticates` marca o comportamento de propósito |
| 9 | Conflito de sessão entre tenants | `05-buyer-experience/01-review.md:717` | **RESOLVIDO** | Cliente: cookie `customer_session_{tenant_id}` (`Services/CustomerAuthService.php:72`); `CustomerGuardTest::test_guard_rejects_a_cookie_of_another_tenant`. Admin: chave de sessão por tenant (`Auth/TenantAdminGuard.php`); `TenantAdminGuardTest::test_two_tenants_can_be_logged_in_simultaneously_in_the_same_browser`. Commit `7cd6b4e`. O review dizia "mitigado" |

## 2. Demais itens encontrados nos documentos das sprints 01–05

### Sprint 01 — pagamentos

| # | Item | Origem | Status | Evidência |
|---|---|---|---|---|
| 10 | S01-QA-001: `resolveCartLines()` ignora produto não encontrado em silêncio | `01-payment/04-qa-review.md:82` | **ACEITO** | Comportamento deliberado e fixado por teste: produto inativo, excluído, de outro tenant ou inexistente sai do carrinho, do total e do pedido (`CartServiceTest::test_inactive_deleted_foreign_and_unknown_products_are_excluded`, `OrderServiceTest::test_resolve_cart_lines_ignores_nonexistent_products`). Registrado em `05-development.md` §10b ("Mudanças de comportamento"). Não é erro para o usuário |
| 11 | S01-QA-002: `createFromCart()` não valida carrinho vazio | `01-payment/04-qa-review.md:83` | **ABERTO** | Os chamadores protegem: `Livewire/Tenant/Checkout.php:202-207` ("Carrinho vazio") e `TenantPaymentController.php:79-90` (422 "Carrinho vazio"/"Total inválido"). O serviço em si não: verifiquei chamando `OrderService::createFromCart($tenant, $cliente, [], 'cash_on_delivery')` e o resultado é um pedido com **0 itens e total 0** gravado (`app/Services/OrderService.php:39-66`). Risco baixo hoje (sem caller que passe vazio), mas é invariante que deveria viver no serviço |
| 12 | S01-QA-003: `handleWebhook()` consulta o banco direto no gateway | `01-payment/04-qa-review.md:134` | **RESOLVIDO** | `MercadoPagoGateway` não tem `Order::`/`Payment::`/`DB::` (grep vazio); a persistência vive em `Services/PaymentWebhookProcessor.php`. Commit `46c561d` |
| 13 | S01-QA-004: sem timeout nas chamadas HTTP | `01-payment/04-qa-review.md:135` | **RESOLVIDO** | Ver linha 4a |
| 14 | S01-QA-006: `getPayment()` devolve `null` e perde o erro | `01-payment/04-qa-review.md:137` | **ABERTO** | `Gateways/MercadoPagoGateway.php:152-165` ainda devolve `null` em falha de credencial, HTTP não 2xx ou timeout, sem log. O processador converte em 503 (para reenvio), mas a causa (token inválido, 404, 500) não é registrada |
| 15 | S01-QA-007: validação de credenciais duplicada (controller e gateway) | `01-payment/04-qa-review.md:165` | **ABERTO** | `TenantPaymentController.php:63` (`isConfigured`) e `MercadoPagoGateway.php:69-72` (token ausente). Cosmético, P4; a checagem no gateway pode ser vista como defesa em profundidade, mas não há decisão registrada |
| 16 | S01-QA-008: `resolveCartLines()` chamado duas vezes no controller | `01-payment/04-qa-review.md:166` | **ABERTO** | `TenantPaymentController.php:85` resolve para o total e `OrderService::createFromCart` resolve de novo dentro da transação. A segunda resolução é desejável (valida de novo dentro do lock); só falta registrar isso. P4 |
| 17 | S01-QA-009: `loadCart()` duplica `OrderService::resolveCartLines()` | `01-payment/04-qa-review.md:209` | **RESOLVIDO** | `loadCart` não existe mais (grep vazio); tudo passa por `CartService` (`CartUnificationTest`). Commit `667bb54` |
| 18 | S01-QA-010: `isMercadoPagoMethodId()` é wrapper desnecessário | `01-payment/04-qa-review.md:234` | **RESOLVIDO** | Método removido (grep vazio); sobra `PaymentMethod::isMercadoPagoId` (`Enums/PaymentMethod.php:81`) |
| 19 | S01: `decryptIfNeeded` duplicado em 3 arquivos; lista de métodos MP hardcoded | `01-payment/01-review.md:144-146` | **RESOLVIDO** | Uma só implementação em `Support/EncryptionHelper` (`EncryptionHelperTest`); ids do MP saíram do `Checkout.php` (`FakeGatewayEndToEndTest.php:208` lê o fonte e falha se voltarem); lista vive em `Enums/PaymentMethod` |
| 20 | S01: sem abstração de gateway, sem histórico de tentativas | `01-payment/01-review.md:161-166` | **RESOLVIDO** | `Contracts/PaymentGatewayInterface` + `Gateways/PaymentGatewayManager` + `FakeGateway` em teste (`FakeGatewayEndToEndTest`, `PaymentGatewayManagerTest`); tentativas em `payments` (ver linha 1) |
| 21 | S01-O2: eventos/listeners para mudança de status de pagamento | `01-payment/01-review.md:164`, `01-payment/02-sprint.md:147` | **ABERTO** | Não existe `app/Events` nem `app/Listeners`. Era opcional na sprint 01. O histórico de transições existe em `payment_webhook_events` (idempotência), mas não é um barramento de eventos. Não está em `02-sprint.md` §8; "histórico de mudança de status de pedido" está (§8) e cobre parte disso |
| 22 | S01: observabilidade — logging estruturado, métricas por provedor, alertas | `01-payment/01-review.md:175-179` | **ABERTO** | Parcial: há logs estruturados de incidente (`payment.incident.*` em `PaymentService.php:211,271` e `PaymentWebhookProcessor.php:242`, `payment.webhook.transition` `:211`). Não há métricas, painel nem alerta: um `critical` só aparece se alguém ler `laravel.log` |

### Sprint 02 — melhorias de pagamento

| # | Item | Origem | Status | Evidência |
|---|---|---|---|---|
| 23 | S02: testes unitários de `EncryptionHelper`, `PaymentMethod`, `OrderService`, `MercadoPagoGateway` | `02-payment-improvements/01-review.md:67` | **RESOLVIDO** | `tests/Unit/Support/EncryptionHelperTest.php`, `tests/Unit/Enums/PaymentMethodTest.php`, `tests/Unit/Services/OrderServiceTest.php`, `tests/Unit/Gateways/MercadoPagoGatewayTest.php` (todos verdes) |
| 24 | S02: critério de aceite "cobertura > 80% nos componentes críticos" | `02-payment-improvements/01-review.md:182` | **NÃO VERIFICADO** | O container PHP não tem Xdebug nem PCOV e o CI roda com `coverage: none`; nenhuma medição existe. Faltaria instalar `pcov` (imagem e CI) e rodar `phpunit --coverage-text` |
| 25 | S02: 25 testes falhando por falta de factories | `02-payment-improvements/04-test-analysis.md:13-30` | **RESOLVIDO** | Factories existem para todos os models de negócio (`database/factories`); a suíte inteira está verde |
| 26 | S02: risco "validação de assinatura rejeitar webhooks válidos — testar com sandbox do MP" | `02-payment-improvements/01-review.md:171` | **NÃO VERIFICADO** | Os testes assinam o payload localmente seguindo a documentação (`MercadoPagoGatewayTest::test_validate_webhook_signature_validates_correct_signature`), o que não prova aceitação de um webhook real. Faltaria: um webhook de sandbox do Mercado Pago contra `/webhooks/mercadopago/{tenantId}`. Também sem tolerância de `ts` (`05-development.md` §11, "Abertos") |
| 27 | S02: "configurar webhook secret em produção" | `02-payment-improvements/03-dev.md:362` | **ABERTO** | Ação operacional, não verificável pelo repositório. O código falha fechado: sem secret em produção, 401 (`Support/WebhookSecurity`; `WebhookIdempotencyTest::test_missing_signature_header_is_rejected_when_the_tenant_has_a_secret`). Falta um checklist de deploy que exija o secret e a troca da URL antiga `/webhooks/mercadopago` por `/webhooks/mercadopago/{tenantId}` (`05-development.md` §11) |

### Sprint 03 — vitrines

| # | Item | Origem | Status | Evidência |
|---|---|---|---|---|
| 28 | BUG-001: asserção de `max_items` em `TYPE_ALL` | `03-showcases/04-qa-review.md:17-48` | **RESOLVIDO** | D5: `Showcase::getProducts()` em `all` usa `min(max_items, 100)` (`app/Models/Showcase.php`, `ALL_TYPE_CEILING`). O diagnóstico de D5 vale: não era asserção errada, era divergência entre formulário e comportamento. Antes: `Failed asserting that actual size 10 matches expected size 5`. Depois: `ShowcaseTest::test_type_all_respects_max_items`, `::test_type_all_caps_max_items_at_the_safety_ceiling` (101+ produtos, `max_items=500` → 100), `::test_type_all_without_valid_max_items_falls_back_to_the_ceiling`; `ShowcaseFormValidationTest::test_max_items_validation_is_within_the_all_type_ceiling` (formulário 1–50 coerente com o teto) |
| 29 | BUG-002: Sortable não reinicia após re-render | `03-showcases/04-qa-review.md:51-68` | **NÃO VERIFICADO** | O código reinicia (`resources/views/livewire/admin/showcases/index.blade.php:125-142`: `showcasesUpdated` e `morph.updated`), mas `05-development.md:471` registra que o drag-and-drop das vitrines não foi verificado no navegador. Faltaria o E2E de Playwright (E4.5, ainda aberto) |
| 30 | BUG-003: `category_id` obrigatório para o tipo `category` | `03-showcases/04-qa-review.md:70-93` | **RESOLVIDO** | `Admin/Showcases/Form.php:86` (`Rule::requiredIf`); código já presente em `900c3dc`. Prova nova: `ShowcaseFormValidationTest::test_category_type_requires_a_category` |
| 31 | VAL-001/002 no nível do banco: `showcases.category_id` aceita categoria de outro tenant | `03-showcases/04-qa-review.md:96-133`; `01-review.md` DB-002 | **ABERTO** | A validação PHP fecha o caminho do admin, mas a FK é só por `id` (sem FK composta, ao contrário dos pivôs). Registrado em `05-development.md:32` ("3 FKs ainda sem composição por tenant": `showcases.category_id`, `orders.customer_id`, `order_items.product_id`). Defesa em profundidade, sem rota conhecida de exploração |
| 32 | VAL-003: slug duplicado estoura erro de banco | `03-showcases/04-qa-review.md:135` | **RESOLVIDO** | `Models/Concerns/GeneratesUniqueSlug` (sufixo `-2`, `-3`) e unique parcial; `SlugIntegrityTest::test_models_generate_unique_slugs_for_repeated_names`. Slug informado à mão é validado por `Rule::unique` no formulário. Limite conhecido: corrida entre dois slugs iguais é barrada pelo índice único (erro, nunca duplicata) |
| 33 | VAL-005: categoria inativa aceita na validação | `03-showcases/04-qa-review.md:157` | **ABERTO** | `Form.php:89-90` valida tenant e `deleted_at`, não `is_active`; a lista do formulário só mostra categorias ativas. O próprio QA dizia "pode ser intencional". P4 |
| 34 | MEL-001: índice em `showcases.type` | `03-showcases/04-qa-review.md:169` | **ADIADO** (proposto) | A migration não tem o índice; nenhuma query filtra por `type` (só o tipo da própria vitrine é lido). Reavaliar quando o painel de plataforma ou relatórios filtrarem por tipo |
| 35 | MEL-002: feedback de loading no drag-and-drop | `03-showcases/04-qa-review.md:181` | **ADIADO** (proposto) | Sem `wire:loading` na listagem (grep). D8/9.4 manda o estado de loading para `<x-btn>`; resolver junto da migração das views para componentes (E4), não antes |
| 36 | MEL-003: contador de produtos na listagem | `03-showcases/04-qa-review.md:191` | **ABERTO** | `Admin/Showcases/Index.php` e a view mostram só "Máx: N itens"; nenhum `withCount`/contagem de produtos |
| 37 | MEL-004: avisar ao excluir a última vitrine ativa | `03-showcases/04-qa-review.md:201` | **ABERTO** | Existe só o `wire:confirm="Excluir esta vitrine?"` genérico (`index.blade.php:85`); `Index::delete` (`:36-46`) não olha se é a última ativa |
| 38 | MEL-005: seletor de produtos que escale (tipo `custom`) | `03-showcases/04-qa-review.md:211` | **ABERTO** | `form.blade.php` ainda lista checkboxes de todos os produtos ativos, sem busca (`Form::render` carrega todos) |
| 39 | MEL-007: lazy loading/cache de vitrines na home | `03-showcases/04-qa-review.md:230` | **ADIADO** (§8) | "Cache de vitrines" em `02-sprint.md` §8. Hoje `Livewire/Tenant/Home.php:33` chama `getProducts()` para cada vitrine a cada render |
| 40 | MEL-008: documentar vitrine vazia (some da home) | `03-showcases/04-qa-review.md:240` | **ABERTO** | `Home.php:53` ainda filtra vitrines sem produtos; o admin não é avisado (sem texto na listagem nem no formulário) |

### Sprint 04 — personalização do tenant

| # | Item | Origem | Status | Evidência |
|---|---|---|---|---|
| 41 | Pedido mínimo, taxa de entrega, entrega grátis acima de X e tempo estimado marcados como "Implementado" | `04-tenant-customization/06-implementation.md:212-213,223`; `04-market-research.md:291` | **ABERTO** | **Achado novo desta triagem:** o documento diz implementado, mas só existe a configuração (`Admin/Tenant/DeliverySettings.php`) e a exibição na home (`Support/TenantHelper::getDeliveryInfo`, `Livewire/Tenant/Home.php:59`). O checkout não aplica nada: `minimum_order_cents`, `default_fee_cents` e `free_delivery_above_cents` não são lidos fora desses dois arquivos (grep), `OrderService.php:59` grava `delivery_fee_cents = 0` e `:56` fixa `fulfillment_type = 'pickup'`. Um tenant que configura pedido mínimo de R$ 20 recebe pedidos de R$ 5. Nenhum documento da sprint 06 menciona isso. A Fase 4 do plano (`03-dev.md:634`, "Validar pedido mínimo / Somar taxa / Exibir tempo") não foi feita |
| 42 | Cupons, fidelidade, avaliações (P2) | `04-tenant-customization/06-implementation.md:232-241` | **ADIADO** (§8) | `02-sprint.md` §8: "Cupons, notificações, PWA, fidelidade". Avaliações não estão listadas (proposto: sprint 07+) |
| 43 | Áreas de entrega com geolocalização, múltiplos períodos por dia, feriados (P3) | `04-tenant-customization/06-implementation.md:232-241` | **ADIADO** (proposto) | Roadmap de produto sem dependência de infraestrutura; não há registro em §8. Áreas de entrega dependem do item 41 |
| 44 | Checklist de implementação todo desmarcado | `04-tenant-customization/03-dev.md:600-642` | **ABERTO** | O `03-dev.md` é o plano (todas as caixas `[ ]`); o registro do que foi feito é o `06-implementation.md`. Quem ler só o `03-dev.md` conclui que nada foi feito. Documentação, P4 |

### Sprint 05 — experiência do comprador

| # | Item | Origem | Status | Evidência |
|---|---|---|---|---|
| 45 | Login sem verificação SMS | `05-buyer-experience/02-sprint.md:684`; `03-dev.md:977` | **ACEITO** | Mesmo item da linha 8 (D3); OTP **ADIADO** (§8) |
| 46 | Cookie bloqueado pelo navegador: "fallback para sessionStorage" | `05-buyer-experience/01-review.md:718` | **ABERTO** | Não implementado: `sessionStorage` não aparece em `resources/js` nem nas views (grep). Sem cookie o cliente simplesmente não fica logado e o carrinho (sessão do Laravel) depende do cookie de sessão. Baixa prioridade |
| 47 | Performance com muitos produtos: paginação e lazy load | `05-buyer-experience/01-review.md:720` | **ABERTO** | `grep paginate` em `app/` não encontra nada: `CategoryShow.php:38` faz `->get()` sem limite e as listagens de admin carregam tudo. O teto de 100 itens vale só para vitrines `all`. Hoje o volume é de demo |
| 48 | Área do cliente incompleta (detalhe de pedido, endereços) | `05-buyer-experience/02-sprint.md:685`; `03-dev.md:987` | **ADIADO** (proposto) | Prometido "para a sprint 06" mas fora de `02-sprint.md`. Tem de respeitar D3: **enquanto o login for só telefone, endereços e dados sensíveis não entram na área do cliente**; logo, depende do OTP |
| 49 | Sem sistema de notificações | `05-buyer-experience/02-sprint.md:686` | **ADIADO** (§8) | `02-sprint.md` §8 ("Cupons, notificações, PWA, fidelidade") |
| 50 | Carrinho ainda em sessão (migrar para banco) | `05-buyer-experience/02-sprint.md:687`; `03-dev.md:982` | **ADIADO** (proposto) | Prometido "para a sprint 06" mas fora do escopo; hoje `CartService::sessionKey` (`cart.{tenant_id}`) é a fonte única, o que facilita a migração. "Reserva no carrinho" já está em §8 (estoque avançado) e depende disto |

## 3. Resumo (contagem conferida por script sobre as tabelas acima)

52 itens classificados (a linha 4 é só o agrupador de 4a–4c).

| Status | Quantidade | Linhas |
|---|---|---|
| **RESOLVIDO** | 19 | 1, 2, 3, 4a, 5, 6, 7, 9, 12, 13, 17, 18, 19, 20, 23, 25, 28, 30, 32 |
| **ACEITO** | 3 | 8, 10, 45 |
| **ADIADO** | 9 | 4b, 34, 35, 39, 42, 43, 48, 49, 50 |
| **ABERTO** | 18 | 4c, 11, 14, 15, 16, 21, 22, 27, 31, 33, 36, 37, 38, 40, 41, 44, 46, 47 |
| **NÃO VERIFICADO** | 3 | 24, 26, 29 |

Nenhum item ficou sem status. Dos 9 itens do `01-review.md` §7, 7 estão resolvidos (1, 2, 3, 5, 6, 7, 9), 1 aceito (8, D3) e 1 dividido em resolvido/adiado/aberto (4). O review listava VAL-001/002 como "Aberto" e QA-011 como "parcialmente resolvido" quando já estavam resolvidos.

## 4. Itens ABERTOS e NÃO VERIFICADOS que merecem decisão

Em ordem de impacto:

1. **Linha 41 (pedido mínimo/taxa de entrega não aplicados no checkout).** É o único item com efeito direto no dinheiro do tenant e foi descoberto por esta triagem: o documento dizia "Implementado". Precisa de decisão de produto (aplicar de verdade no checkout, ou remover a configuração do admin até existir) e de dono na sprint 07.
2. **Linha 4c (sem reconciliação de pagamento pendente)** e **linha 4b (sem fila/retry/dead-letter):** juntos formam a resiliência do webhook. Hoje a única rede de segurança é o reenvio do gateway.
3. **Linha 11 (`createFromCart` aceita carrinho vazio):** correção de 3 linhas no serviço com teste; ficou de fora desta sprint porque `OrderService` é caminho crítico de receita e a triagem não deve mexer nele sem o bloco de testes dedicado.
4. **Linhas 24 e 26 (cobertura e sandbox do MP):** precisam de ambiente (pcov no container/CI; credenciais de sandbox do Mercado Pago). Não são verificáveis por leitura de código.
5. **Linha 29 (Sortable das vitrines no navegador):** depende do Playwright (E4.5).
6. **Linha 27 (checklist de deploy do webhook secret):** operacional; sugerido registrar no checklist de produção.

Os demais ABERTOS são P4 (cosméticos, UX ou documentação) e podem entrar na sprint 07 em lote.
