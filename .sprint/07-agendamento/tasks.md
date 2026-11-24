# Tarefas — Agendamento da encomenda
Status: aceita

Ordem: T1, T2, T3, T4, T5, T6, T7, T8, T9, T10, T11, T12, T13.

| Id | Depende de |
| --- | --- |
| T1 | nenhuma |
| T2 | nenhuma |
| T3 | T1 |
| T4 | T1, T2 |
| T5 | T4 |
| T6 | T5 |
| T7 | T1 |
| T8 | T3, T4 |
| T9 | T4, T8 |
| T10 | T3, T7, T8 |
| T11 | nenhuma |
| T12 | T4 |
| T13 | nenhuma |

Critérios da spec: 1 desligado em T4, T8 e T9. 2 ligado em T4, T8 e T9. 3 calendário em T3, T8 e T10. 4 dia desmarcado, pausa e expediente em T3 e T8. 5 antecedência em T1 e um caso no payload em T8. 6 produto em T1. 7 checkbox em T8 e T11. 8 máximo em T1. 9 folga em T3, T7 e T10. 10 capacidade em T5. 11 cancelar e corrida em T5 e T6. 12 select em T3, T7 e T8. 13 exibição em T12. 14 recusa em T4, T5 e T8. 15 preservar configuração em T7.

Lacuna herdada do plano, sem tarefa de navegador: o clique num dia desabilitado, a seta do mês e o texto `Folga` ou `Lotado` pintado pelo JavaScript na célula. A prova para no payload, na recusa do servidor e no build do asset.

## T1 — Janela e antecedência
- Estado: feita
- Depende de: nenhuma
- Arquivos: `src/app/Services/SchedulingService.php`, `src/tests/Feature/Scheduling/SchedulingWindowTest.php`, `src/app/Services/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter SchedulingWindowTest`
- Feito quando: com o relógio numa segunda-feira em `America/Sao_Paulo`, antecedência 0 abre na terça e antecedência 1 na quarta; hoje nunca entra; o máximo 0 não oferece dia, o máximo 7 corta em sete dias e mínimo 1 com máximo 1 não oferece dia; a antecedência do produto substitui a da loja, em branco usa a da loja, e o carrinho fica com a maior, inclusive 0 contra em branco.

O serviço nasce aqui, sem checkout e sem coluna nova. O fuso sai de `business_hours.timezone`, com default `America/Sao_Paulo`, mesmo com o horário desligado. Congelar o teste em 2026-11-09 15:00 UTC. Não editar o `CLAUDE.md` da raiz: o orquestrador grava lá a frase de que o servidor decide o dia e a opção.

## T2 — Coluna do dia
- Estado: feita
- Depende de: nenhuma
- Arquivos: `src/database/migrations/`, `src/app/Models/Order.php`, `src/app/Models/CLAUDE.md`, `src/tests/Feature/Scheduling/SchedulingSchemaTest.php`
- Prova: `docker compose exec app php artisan test --filter SchedulingSchemaTest`
- Feito quando: `scheduled_on` guarda o dia de calendário e a leitura devolve a mesma data, sem puxar um dia para trás por causa do fuso.

Migration nova. Não editar migration antiga. `date` nullable, índice `(tenant_id, scheduled_on)`, no fillable e no cast. Pedido sem dia continua null. O snapshot em `meta` fica para a T4.

## T3 — Payload do calendário
- Estado: feita
- Depende de: T1
- Arquivos: `src/app/Services/SchedulingService.php`, `src/tests/Feature/Scheduling/SchedulingCalendarTest.php`, `src/app/Services/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter SchedulingCalendarTest`
- Feito quando: o payload lista cada dia do intervalo como aberto ou desabilitado, com semana começando no domingo e meses em português; dia desmarcado, dia sem opção, dia fora da janela e folga entram desabilitados; folga traz o motivo próprio, igual para entrega e retirada; a folga não reaparece no ano seguinte; o mês navegável não passa da primeira nem da última candidata.

Ainda não é a biblioteca. Capacidade e o motivo lotado ficam na T5. O payload não depende do tipo de recebimento.

## T4 — Gravar e recusar o dia
- Estado: feita
- Depende de: T1, T2
- Arquivos: `src/app/Services/OrderService.php`, `src/app/Services/SchedulingService.php`, `src/app/Exceptions/SchedulingException.php`, `src/app/Exceptions/CLAUDE.md`, `src/app/Services/CLAUDE.md`, `src/tests/Feature/Scheduling/SchedulingOrderWriteTest.php`
- Prova: `docker compose exec app php artisan test --filter SchedulingOrderWriteTest`
- Feito quando: com o bloco desligado, entrega e retirada gravam pedido sem dia mesmo que o cliente mande data; com o bloco ligado, falta de dia ou de opção, folga e dia fora da janela não criam pedido nem baixam estoque; dia e opção válidos gravam `scheduled_on` e o snapshot com o rótulo gerado no servidor.

A checagem entra em `createFromCart` antes de cliente e estoque. O argumento novo tem default vazio. `SchedulingException` carrega o campo, como `FulfillmentException`. A vaga e o lock ficam na T5: aqui a capacidade ainda não recusa.

## T5 — Vaga, cancelar e reabrir
- Estado: feita
- Depende de: T4
- Arquivos: `src/app/Services/SchedulingService.php`, `src/app/Services/OrderService.php`, `src/tests/Feature/Tenancy/TenantIsolationAuditTest.php`, `src/tests/Feature/Scheduling/SchedulingCapacityTest.php`, `src/app/Services/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter 'SchedulingCapacityTest|TenantIsolationAuditTest'`
- Feito quando: capacidade vazia não limita; 0 e dia lotado ficam desabilitados com o motivo de vaga; cada data tem a própria contagem; entrega e retirada dividem a vaga e a quantidade de itens não conta; cancelar devolve; reabrir sem vaga falha e o pedido continua `cancelled`; baixar o limite não apaga pedido já feito.

O lock é `pg_advisory_xact_lock` dentro da transação de `createFromCart` e de `updateOrder`, na chave `order-schedule` mais tenant e data. O `SELECT` entra na lista fechada do audit, com o motivo. A asserção não afrouxa. A corrida de dois processos é a T6.

## T6 — Dois fechamentos no último lugar
- Estado: feita
- Depende de: T5
- Arquivos: `src/tests/Feature/Scheduling/SchedulingCapacityRaceTest.php`, `src/tests/Feature/Scheduling/Support/place_scheduled_order_child.php`
- Prova: `docker compose exec app php artisan test --filter SchedulingCapacityRaceTest`
- Feito quando: duas gravações ao mesmo tempo, com limite 1, produzem um pedido só. O outro processo é o padrão de `InventoryConcurrencyTest`, com chave de espera diferente da chave `order-schedule`.

Não mudar a regra. Esta tarefa só prova o lock da T5.

## T7 — Configuração da loja
- Estado: feita
- Depende de: T1
- Arquivos: `src/app/Livewire/Admin/Tenant/ScheduleSettings.php`, `src/resources/views/livewire/admin/tenant/schedule-settings.blade.php`, `src/routes/web.php`, `src/app/Support/AdminNavigation.php`, `src/tests/Unit/Support/AdminNavigationTest.php`, `src/tests/Feature/Scheduling/ScheduleSettingsTest.php`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter 'ScheduleSettingsTest|AdminNavigationTest|LivewireAdminAuthorizationAuditTest|RouteMiddlewareAuditTest'`
- Feito quando: quem já configura a loja liga o bloco, grava antecedência, máximo, dias, capacidade e opções na ordem, e o restante da configuração sobrevive; intervalo com fim antes do começo não salva; a folga entra por `addOffDay` no calendário, sem campo de texto, uma vez só, e sai devolvendo o dia.

Rota `tenant.admin.scheduling` dentro de `tenant.auth`. Item ao lado de Entrega, `can` null. O componente usa `AuthorizesOnHydrate` e `manageSettings`. `data_set` só do bloco `scheduling`. Valores iniciais da tela: desligado, mínimo 0, máximo 30, sete dias marcados. Vazio na capacidade grava null, 0 grava 0. O widget da biblioteca entra na T10; aqui o ponto de montagem e a ação já existem.

## T8 — Checkout manual
- Estado: feita
- Depende de: T3, T4
- Arquivos: `src/app/Livewire/Tenant/Checkout.php`, `src/resources/views/livewire/tenant/checkout.blade.php`, `src/tests/Feature/Scheduling/SchedulingCheckoutTest.php`, `src/app/Livewire/Tenant/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter SchedulingCheckoutTest`
- Feito quando: desligado, o manual fecha sem dia na entrega e na retirada; ligado, os dois tipos exigem dia e opção e o pedido não nasce sem eles; o select mostra só as opções daquele dia, na ordem, sem grade; trocar o dia limpa opção que não existe nele; dia desabilitado mandado pelo cliente não troca a data já marcada nem cria pedido; carrinho mais exigente invalida o dia que ficou cedo; loja pausada e domingo fechado no expediente ainda aceitam o dia marcado no agendamento; produto com a flag antiga não exige dia enquanto o bloco está desligado; com hoje na segunda e antecedência 1, a primeira data aberta do payload é quarta.

O calendário é bloco próprio, também quando só existe retirada. O payload é computado, não propriedade pública. O POST do Mercado Pago não muda nesta tarefa.

## T9 — Checkout online
- Estado: feita
- Depende de: T4, T8
- Arquivos: `src/app/Services/PaymentService.php`, `src/app/Http/Controllers/TenantPaymentController.php`, `src/resources/views/livewire/tenant/checkout.blade.php`, `src/tests/Feature/Scheduling/SchedulingOnlineCheckoutTest.php`, `src/app/Services/CLAUDE.md`, `src/app/Http/Controllers/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter SchedulingOnlineCheckoutTest`
- Feito quando: ligado, o online exige dia e opção na entrega e na retirada, no Pix e no cartão do gateway falso; sem isso a resposta é 422 e o pedido não nasce; com dia válido o pedido guarda o snapshot; desligado, o POST sem `schedule` continua fechando; outra data muda a `checkoutKey`.

`schedule` é irmão de `fulfillment` no JSON. `fulfillmentFrom` não ganha campo novo. O evento `checkout-mp-pay` leva o `schedule`, e o script que já faz o POST encaminha. O lock termina no `begin()`, antes do HTTP.

## T10 — Calendário na tela
- Estado: feita
- Depende de: T3, T7, T8
- Arquivos: `src/package.json`, `src/package-lock.json`, `src/resources/js/schedule-calendar.js`, `src/resources/js/app.js`, `src/resources/js/admin.js`, `src/resources/views/livewire/tenant/checkout.blade.php`, `src/resources/views/livewire/admin/tenant/schedule-settings.blade.php`, `src/resources/js/CLAUDE.md`, `src/tests/Feature/Scheduling/SchedulingCalendarTest.php`
- Prova: `(cd src && npm run build) && docker compose exec app php artisan test --filter SchedulingCalendarTest`
- Feito quando: o checkout mostra o mês pelo Air Datepicker, inline, sem input de data e sem seletor nativo; a semana começa no domingo; o script só pinta o payload e remonta depois do morph; a folga é incluída na configuração pelo mesmo calendário, sem campo de texto; o build gera o manifest.

Ler a API da versão pinada antes de usar `onRenderCell` e o locale. Célula de folga e de dia lotado recebe esse texto no DOM. O PHPUnit não clica no widget: a lacuna do topo continua.

## T11 — Antecedência do produto
- Estado: feita
- Depende de: nenhuma
- Arquivos: `src/app/Livewire/Admin/Products/Form.php`, `src/resources/views/livewire/admin/products/form.blade.php`, `src/tests/Feature/Admin/ProductFormValidationMarkupTest.php`, `src/app/Livewire/Admin/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter ProductFormValidationMarkupTest`
- Feito quando: o formulário não mostra "Exigir data/hora no pedido"; salvar não apaga o valor antigo da coluna; `min_days_notice` vazio continua null e 0 continua 0.

A coluna `requires_scheduling` permanece. O checkout que ignora a flag antiga é a T8.

## T12 — Dia visível no pedido
- Estado: feita
- Depende de: T4
- Arquivos: `src/resources/views/livewire/admin/orders/index.blade.php`, `src/resources/views/livewire/admin/orders/show.blade.php`, `src/resources/views/livewire/tenant/account/index.blade.php`, `src/tests/Feature/Scheduling/SchedulingOrderDisplayTest.php`, `src/app/Livewire/Admin/CLAUDE.md`, `src/app/Livewire/Tenant/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter SchedulingOrderDisplayTest`
- Feito quando: a lista e a ficha do admin, e a conta do cliente, mostram o dia e o rótulo gravados; apagar a opção na loja não muda o pedido; pedido antigo, só com `scheduled_for`, continua no format de hoje; a ficha não edita o dia.

Formatar `scheduled_on` com `d/m/Y` sem converter fuso. O rótulo é o `label` do snapshot.

## T13 — Snapshot no contrato de Models
- Estado: feita
- Depende de: nenhuma
- Arquivos: `src/app/Models/CLAUDE.md`
- Prova: `grep -n -e 'orders.meta.scheduling' -e 'settings.scheduling' src/app/Models/CLAUDE.md`
- Feito quando: o arquivo registra `orders.meta.scheduling` como snapshot da opção, irmão de `meta.fulfillment` e `meta.payment`, com `date` e `option` (`kind`, `start`, `end`, `label`), e o índice de `Tenant.settings` cita `settings.scheduling`.

Só o `CLAUDE.md`. Não mude `Order.php` nem o comportamento. O arquivo tem menos de 300 linhas; continue abaixo do limite. A rodada 1 do QA achou essa frase faltando.
