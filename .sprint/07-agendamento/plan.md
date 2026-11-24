# Plano — Agendamento da encomenda
Status: aceita
Spec: spec.md

## Onde

A regra mora num serviço novo. Tela, JSON e o script do calendário não decidem se o dia vale.

- `src/app/Services/SchedulingService.php` — dono da conta. O `CLAUDE.md` de Services manda a tela chamar serviço: preço, taxa, mínimo e estoque não se decidem em propriedade pública. O dia e a opção entram nessa lista. `OrderService::createFromCart` continua a única gravação do pedido, numa transação. `PaymentService::begin` continua a transação curta, com o HTTP de `charge()` fora dela.
- `src/app/Services/OrderService.php` — chama o serviço novo dentro da transação, antes de cliente e estoque. `updateOrder` usa o mesmo lock ao entrar ou sair de `cancelled` quando o pedido tem dia.
- `src/app/Services/PaymentService.php` — `checkoutKey` passa a incluir o dia e a opção. Método e cliente continuam de fora.
- `src/app/Exceptions/SchedulingException.php` — mesmo contrato de `FulfillmentException`: campo e mensagem, sobe da transação e desfaz o que ela escreveu. O `CLAUDE.md` de Exceptions ganha essa classe na lista que aborta o pedido.
- `src/app/Http/Controllers/TenantPaymentController.php` — o `CLAUDE.md` de Controllers diz que do JSON só entram método, cliente, fulfillment, `formData` e device. O plano acrescenta um objeto `schedule` irmão de `fulfillment`, não um campo dentro dele. Outra chave continua descartada. `fulfillmentFrom` segue só com `type`, `region_id` e `address`.
- `src/app/Livewire/Tenant/Checkout.php` e `resources/views/livewire/tenant/checkout.blade.php` — o calendário é bloco próprio, também quando a loja só tem retirada (aí `#deliveryFields` nem aparece). `placeOrderManual` e `payOnline` revalidam. O POST do Mercado Pago continua no Blade; o payload do evento `checkout-mp-pay` ganha `schedule`. O script do Brick não é o lugar do calendário: ele só existe com gateway ou com pagamento em espera.
- `src/app/Livewire/Admin/Tenant/ScheduleSettings.php` — bloco novo, no padrão de `DeliverySettings`: `manageSettings`, `AuthorizesOnHydrate`, `data_set` do bloco inteiro. Irmãos (`pickup`, `delivery`, `business_hours`, pagamento) sobrevivem.
- `src/routes/web.php` — `GET /{tenant}/admin/agendamento`, nome `tenant.admin.scheduling`, dentro de `tenant.auth`. O `CLAUDE.md` de routes: tela é componente, nome `tenant.admin.*`.
- `src/app/Support/AdminNavigation.php` — item ao lado de Entrega. O `CLAUDE.md` de Support: a lista é `definition()`, e `AdminNavigationTest` trava ordem, rótulo, rota e ícone. O teste ganha o item. A asserção não afrouxa. Sem permissão nova: `can` null, como Entrega. A tela autoriza `store.settings.manage`.
- `src/app/Livewire/Admin/Products/Form.php` e a view — sai "Exigir data/hora no pedido". `min_days_notice` fica. O `CLAUDE.md` de Admin descreve o formulário; ganha a frase de que esse checkbox não existe e de que vazio permanece null.
- `src/app/Models/Order.php` e migration nova — `scheduled_on` é `date` nullable. O `CLAUDE.md` de Models registra a coluna e o snapshot. Não editar migration antiga. Pedido não ganha soft delete.
- Views de `admin/orders/index`, `admin/orders/show` e `tenant/account/index` — leem o dia e o rótulo gravados. Não formatam `scheduled_for` em UTC para pedido novo.
- `src/resources/js/schedule-calendar.js`, importado por `app.js` e `admin.js`. O `CLAUDE.md` de JS: o script não calcula vaga, antecedência nem folga. Pinta o payload do servidor e remonta depois do morph. O POST do checkout não vem para cá.
- `src/package.json` — dependência `air-datepicker` 3.x, versão pinada no lock. Antes de ligar `onRenderCell`, `firstDay` e o locale, ler a API da versão instalada. O `CLAUDE.md` da raiz manda isso para lib de front.
- `CLAUDE.md` da raiz — uma frase: o servidor decide o dia e a opção; propriedade pública de checkout é entrada e é revalidada. `isOpen()` continua sem recusar pedido. A regra atravessa pastas, então quem grava o da raiz é o orquestrador, na tarefa que introduz o serviço.

`TenantHelper::isOpen` não é chamado no checkout nem no `OrderService`. Continua assim.

## Dados

Bloco `tenants.settings.scheduling`. Ausente ou `enabled` falso: o checkout de hoje. Chave irmã fora do bloco não entra nele.

```text
enabled: bool, inicial false
min_days: inteiro 0–365, inicial 0
max_days: inteiro 0–365, inicial 30
weekdays.sunday … saturday:
  enabled: bool, inicial true
  capacity: null ou inteiro >= 0. Vazio grava null. 0 grava 0. Não coerce vazio para 0.
  options: lista ordenada de {kind: time|range, start: HH:MM, end: HH:MM|null}
off_days: datas Y-m-d, uma vez cada
```

As chaves dos dias são as mesmas de `business_hours.schedule` (`sunday` … `saturday`). O rótulo na tela é segunda a domingo. Dia que faltar no save volta ao inicial (marcado, sem horário, sem limite), para uma omissão não apagar a terça.

`orders.scheduled_on` (`date`, nullable) é o dia do calendário da loja. Índice `(tenant_id, scheduled_on)`. Não é `timestampTz`: meia-noite em UTC vira o dia anterior em `America/Sao_Paulo`. A tela formata `d/m/Y` nesse `date`, sem `->timezone()`.

`orders.meta.scheduling` é o snapshot da opção, irmão de `meta.fulfillment` e `meta.payment`:

```text
date: Y-m-d
option: {kind, start, end, label}
```

`label` é gerado no servidor no momento do pedido (`10:00` ou `14:00–16:00`). O texto que o cliente mandar não é gravado. Apagar a opção na loja não mexe no snapshot.

`scheduled_for` fica para pedido antigo e para o seed, que já grava hora. Pedido novo também preenche `scheduled_for` com o início da opção no fuso da loja, convertido para UTC, para a coluna não nascer vazia. A tela de pedido novo não usa esse format. Pedido só com `scheduled_for` e sem snapshot continua no format atual.

`products.requires_scheduling` permanece na coluna. O formulário não mostra e o `save()` não grava o campo. Valor antigo é ignorado. `min_days_notice` null usa a loja; 0 é antecedência do produto. A propriedade já é `?int` com regra `nullable`. Não introduzir `?? 0`.

O fuso da conta é `business_hours.timezone`, default `America/Sao_Paulo`, mesmo com o horário desligado. Timezone inválido cai no default. "Hoje" é `now` nesse fuso, no começo do dia.

Invariantes da raiz que a mudança atravessa:

- Isolamento: a contagem usa `Order` com o tenant do request. O lock não é `withoutTenantScope()`. O `SELECT pg_advisory_xact_lock` é SQL cru e entra na lista de `TenantIsolationAuditTest`, com o motivo. A asserção não afrouxa.
- O servidor recalcula. Dia e opção públicos no Livewire são revalidados no serviço.
- `save()` de configuração substitui o bloco inteiro.
- Pedido e cobrança: a vaga entra na transação de `createFromCart`. `begin()` confirma essa transação antes de `charge()`. O lock não atravessa o HTTP.
- Os três status não se misturam. A vaga olha `orders.status <> cancelled`. Pagamento não entra na conta.
- `TenantCounter` não serve para vaga. Continua só no número do pedido.
- PostgreSQL. Sem SQLite: o lock de transação e o `date` são do banco da suíte.

Não há estado novo de sessão. O nonce e a `checkoutKey` já existentes passam a distinguir o dia.

## Fluxo

`SchedulingService` normaliza o bloco, calcula a antecedência e monta o calendário. `OrderService` só aceita o que esse serviço confirmar dentro da transação.

Antecedência do carrinho: para cada produto, `min_days_notice` se não for null, senão `min_days` da loja. O pedido usa o maior. Carrinho vazio não chega a gravar (`EmptyCartException` já existe).

Primeira data candidata = hoje + antecedência + 1 dia, no fuso da loja. Última = hoje + `max_days`. A conta não pula folga nem dia desmarcado. Se a candidata cair num dia inválido, a primeira data escolhível é a próxima válida, para a frente. Se a primeira candidata for depois da última, não há data.

Um dia do intervalo fica desabilitado quando qualquer regra falha. Prioridade do motivo, uma só: folga, lotado, sem opção, dia desmarcado, fora da janela. Folga e lotado são os únicos com texto próprio (`Folga`, `Lotado`). Os outros ficam desabilitados sem esses rótulos. Dia de folga não é rotulado como lotado.

Capacidade: `null` não limita. `0` ou contagem de pedidos não cancelados com aquele `scheduled_on` maior ou igual ao limite: lotado. Entrega e retirada contam juntas. Quantidade de itens não conta. Pedido sem dia não conta. Terças diferentes têm contagens diferentes.

O checkout, com o bloco ligado, recebe um payload computado (não propriedade pública editável): meses limite, semana começando no domingo, nomes em português, e cada dia do intervalo com `open` ou o motivo. O `air-datepicker` entra inline, sem `<input type="date">` e sem campo de texto. `firstDay` é domingo mesmo que o locale comece na segunda. `minDate` da biblioteca não pode tirar o dia da grade: dia fora da janela, no mês visível, permanece desabilitado. A seta não abre mês anterior ao da primeira candidata nem posterior ao da última. Sem candidata, o checkout mostra o aviso e nenhum dia aberto.

O script lê só esse payload. Na célula de folga ou lotado, o texto do motivo entra no DOM da célula. Remonta no `morph.updated` do Livewire (o `livewire:navigated` do Splide não roda no re-render do checkout). O container do calendário não usa `wire:ignore`: senão a antecedência nova do carrinho não chega.

Escolher um dia habilitado grava `scheduled_on` no componente. O select lista as opções daquele dia da semana, na ordem do JSON, valor `kind|start|end`. Não há grade de 30 minutos. Trocar o dia, no PHP (`updatedScheduledOn`), limpa a opção que não existe no dia novo. Clicar em desabilitado não chama o `set`. Se o cliente mandar a data mesmo assim, o serviço recusa e a data que já estava marcada no componente permanece.

`payOnline` e `placeOrderManual` chamam a validação junto com `fulfillmentIsValid`. Erro no campo `scheduled_on` ou `schedule_option`. O evento `checkout-mp-pay` leva `schedule: {date, option: {kind, start, end}}`. O script existente encaminha isso no POST. O controller lê só essas chaves, exige string, e passa um argumento novo de `begin()`, ao lado de `$fulfillment`. Argumento default `[]` deixa os chamadores atuais iguais quando o bloco está desligado.

`checkoutKey` acrescenta data, kind, start e end quando o bloco está ligado. Outro horário é outra tentativa. Desligado, a chave não muda.

Dentro de `createFromCart`, a ordem fica: linhas, cotação, mínimo, endereço, agendamento, cliente, estoque, `Order::create`. Desligado: ignora dia enviado e grava `scheduled_on` null, sem snapshot. Ligado: `SchedulingException` antes de qualquer escrita se faltar dia, se a opção não for exatamente uma das cadastradas, ou se o dia não estiver aberto. O rótulo gravado sai da opção do servidor.

A vaga usa `pg_advisory_xact_lock` na mesma transação, com `hashtext('order-schedule')` e `hashtext(tenantId + '|' + data)`. É chave diferente da do teste de estoque. Em seguida conta os pedidos não cancelados daquele dia. O lock de transação segura o buraco (o dia ainda sem linha) até o commit de `begin()` ou de `createFromCart`. Duas gravações no último lugar: uma commita, a outra vê a contagem e lança. A que lança não chama o gateway.

`updateOrder`, se o pedido tem `scheduled_on` e o status entra ou sai de `cancelled`, toma o mesmo lock. Cancelar só muda o status (a contagem deixa de vê-lo). Sair de `cancelled`: se a contagem já está no limite, lança no campo `status`, a transação desfaz, o pedido segue cancelado. A baixa de estoque que já existe continua no mesmo método; a falha de vaga desfaz a baixa junto. Sem `scheduled_on`, `updateOrder` não muda.

Baixar o limite na configuração não altera pedido feito. A contagem passa a recusar dia novo enquanto estiver no limite ou acima.

A conta do cliente e as telas de pedido do admin mostram `scheduled_on` e `label` quando há snapshot. Sem snapshot, a conta segue só com `created_at` e o admin segue com `scheduled_for` como hoje.

A configuração da loja: sete dias, horários com sobe/desce na ordem do array, capacidade vazia ou número, folga por calendário. `addOffDay` recusa data inválida e data repetida. Não há input de texto para a folga. Intervalo com fim menor ou igual ao começo, hora fora de `00:00`–`23:59`, ou fim preenchido em horário simples: o save não grava. O relógio é de 24 horas.

O seed da Carol Doces e o `E2eSeeder` não mudam. Agendamento desligado é o default; a demo continua fechando pedido sem dia.

## Prova

Suíte em `wl_test`. Tempo congelado num instante que é segunda-feira em `America/Sao_Paulo` (2026-11-09 15:00 UTC), não em "hoje" do servidor. Corrida no padrão de `InventoryConcurrencyTest`: outro processo PHP, conexão própria, o pai solta os dois juntos. A chave do harness não é a chave `order-schedule`.

| Aceite | Prova |
| --- | --- |
| Desligado fecha sem dia, entrega e retirada, manual e online | `SchedulingCheckoutTest` |
| Ligado exige dia e opção nos dois tipos e nos dois pagamentos; sem isso o pedido não nasce | `SchedulingCheckoutTest`, com `FakeGateway` no online |
| Calendário de mês, português, domingo, sem campo de texto; desabilitado não seleciona | `SchedulingCalendarTest` afirma o payload e a ausência de `input` de data. Lacuna: o clique no widget não tem prova PHPUnit |
| Dia desmarcado desabilitado; pausa e expediente não impedem o pedido | `SchedulingCalendarTest` e `SchedulingCheckoutTest` |
| Segunda com antecedência 0 abre na terça; com 1, na quarta. Hoje não entra | `SchedulingWindowTest` |
| Produto preenchido substitui a loja; em branco usa a loja; o carrinho usa o maior, inclusive 0 contra em branco | `SchedulingWindowTest` |
| Some "Exigir data/hora no pedido"; flag antiga não exige data com o bloco desligado | `ProductFormValidationMarkupTest` e `SchedulingCheckoutTest` |
| Máximo 0 não oferece data; máximo 7 corta; mínimo 1 e máximo 1 não oferecem data | `SchedulingWindowTest` |
| Folga desabilitada e identificada, nos dois tipos; entra pelo calendário; sai e o dia volta; não repete no ano seguinte | `ScheduleSettingsTest` e `SchedulingOffDayTest`. Lacuna: o texto pintado na célula pelo JS |
| Capacidade vazia não limita; 0 e dia lotado desabilitam e o lotado se identifica; cada data tem o próprio limite; uma vaga por pedido, os dois tipos juntos | `SchedulingCapacityTest` |
| Cancelar devolve; reabrir sem vaga falha e o status continua `cancelled`; dois fechamentos no último lugar geram um pedido | `SchedulingCapacityTest` e `SchedulingCapacityRaceTest` |
| Select só com as opções daquele dia, na ordem, sem grade; dia sem opção desabilitado; intervalo inválido não salva | `ScheduleSettingsTest` e `SchedulingCheckoutTest` |
| Admin e conta mostram dia e rótulo; apagar a opção não muda o pedido | `SchedulingOrderDisplayTest` |
| Data, opção, folga ou vaga mandadas pelo cliente não nascem pedido; carrinho mais exigente invalida o dia que ficou cedo | `SchedulingCheckoutTest` |
| Salvar agendamento preserva taxa, bairro e horário | `ScheduleSettingsTest` |

`AdminNavigationTest` e `LivewireAdminAuthorizationAuditTest` seguem verdes com o item e a trait novos. `RouteMiddlewareAuditTest` segue verde porque a rota nasce dentro de `tenant.auth`.

Lacuna: seta do calendário e clique em dia desabilitado. O PHPUnit vê o payload (`first_month`, `last_month`, motivo) e a recusa no servidor. O CI não roda Playwright.

## Fora deste plano

- Grade de 15, 30 ou 60 minutos dentro do intervalo.
- Apelido no lugar do horário.
- Vaga por horário, por intervalo ou por quantidade de itens.
- Regra, folga ou vaga diferente para entrega e retirada.
- Taxa por dia ou por horário.
- Incluir o hoje na antecedência, ou pular dia fechado na contagem.
- Folga por hora, por várias datas ou repetida todo ano.
- A loja editar o dia de um pedido já feito. `updateStatus` não ganha campo de data.
- Lembrete, e-mail e calendário externo.
- Produto visível só em certos dias.
- Expediente ou pausa recusando pedido.
- Relógio de 12 horas.
- Campo de texto ou seletor nativo para o dia.
- Apagar a coluna `requires_scheduling`.
- Mudar o seed da demo ou o `E2eSeeder`.
