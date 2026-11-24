# Log — Agendamento da encomenda

## T1 — Janela e antecedência — tentativa 1
- Subagente: 01a1c900-0bc0-7553-8f87-be2694254df1
- Prova: `docker compose exec app php artisan test --filter SchedulingWindowTest`
- Resultado: passou
- Arquivos: `src/app/Services/SchedulingService.php`, `src/tests/Feature/Scheduling/SchedulingWindowTest.php`, `src/app/Services/CLAUDE.md`, `CLAUDE.md`

10 testes, 17 asserções. HEAD `2e439b2a12e8f2651e3b10363f446b27f5506d44` não mudou. Não apareceu caminho novo em `.sprint/` além de `plan.md` e `tasks.md`, que já estavam sem rastreio. O `CLAUDE.md` da raiz ganhou a frase de que o servidor decide o dia e a opção.

## T2 — Coluna do dia — tentativa 1
- Subagente: 01a1c909-2ad2-7c00-9c91-b560fb352aab
- Prova: `docker compose exec app php artisan test --filter SchedulingSchemaTest`
- Resultado: passou
- Arquivos: `src/database/migrations/2026_11_11_000001_add_scheduled_on_to_orders.php`, `src/app/Models/Order.php`, `src/app/Models/CLAUDE.md`, `src/tests/Feature/Scheduling/SchedulingSchemaTest.php`

2 testes, 9 asserções. `2026-11-12` volta igual. HEAD não mudou. Nenhum caminho novo em `.sprint/`.

## T3 — Payload do calendário — tentativa 1
- Subagente: 01a1c90d-91e4-73e0-a340-a1a729d56084
- Prova: `docker compose exec app php artisan test --filter SchedulingCalendarTest`
- Resultado: passou
- Arquivos: `src/app/Services/SchedulingService.php`, `src/tests/Feature/Scheduling/SchedulingCalendarTest.php`, `src/app/Services/CLAUDE.md`

8 testes, 112 asserções. `SchedulingWindowTest` continuou passando no relato do subagente. HEAD não mudou. Nenhum caminho novo em `.sprint/`.

## T4 — Gravar e recusar o dia — tentativa 1
- Subagente: 01a1c919-64f9-7911-bce7-e889bee8ac63
- Prova: `docker compose exec app php artisan test --filter SchedulingOrderWriteTest`
- Resultado: passou
- Arquivos: `src/app/Services/OrderService.php`, `src/app/Services/SchedulingService.php`, `src/app/Exceptions/SchedulingException.php`, `src/app/Exceptions/CLAUDE.md`, `src/app/Services/CLAUDE.md`, `src/tests/Feature/Scheduling/SchedulingOrderWriteTest.php`

8 testes, 145 asserções. HEAD não mudou. Nenhum caminho novo em `.sprint/`.

## T5 — Vaga, cancelar e reabrir — tentativa 1
- Subagente: 01a1c927-f3fe-7d42-8eeb-ee8d830efde4
- Prova: `docker compose exec app php artisan test --filter 'SchedulingCapacityTest|TenantIsolationAuditTest'`
- Resultado: passou
- Arquivos: `src/app/Services/SchedulingService.php`, `src/app/Services/OrderService.php`, `src/app/Livewire/Admin/Orders/Show.php`, `src/tests/Feature/Tenancy/TenantIsolationAuditTest.php`, `src/tests/Feature/Scheduling/SchedulingCapacityTest.php`, `src/app/Services/CLAUDE.md`, `src/app/Livewire/Admin/CLAUDE.md`

11 testes, 56 asserções. HEAD não mudou. Nenhum caminho novo em `.sprint/`.

## T6 — Dois fechamentos no último lugar — tentativa 1
- Subagente: 01a1c936-009c-7a93-b0ac-cccdbd986962
- Prova: `docker compose exec app php artisan test --filter SchedulingCapacityRaceTest`
- Resultado: passou
- Arquivos: `src/tests/Feature/Scheduling/SchedulingCapacityRaceTest.php`, `src/tests/Feature/Scheduling/Support/place_scheduled_order_child.php`

1 teste, 10 asserções. Um pedido no último lugar. Código de produção não mudou. HEAD não mudou. Nenhum caminho novo em `.sprint/`.

## T7 — Configuração da loja — tentativa 1
- Subagente: 01a1c93d-67dd-7861-8179-abbcd464a181
- Prova: `docker compose exec app php artisan test --filter 'ScheduleSettingsTest|AdminNavigationTest|LivewireAdminAuthorizationAuditTest|RouteMiddlewareAuditTest'`
- Resultado: passou
- Arquivos: `src/app/Livewire/Admin/Tenant/ScheduleSettings.php`, `src/resources/views/livewire/admin/tenant/schedule-settings.blade.php`, `src/routes/web.php`, `src/app/Support/AdminNavigation.php`, `src/tests/Unit/Support/AdminNavigationTest.php`, `src/tests/Feature/Scheduling/ScheduleSettingsTest.php`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`, `src/tests/Feature/Admin/AdminLayoutTest.php`, `src/tests/Feature/Components/AdminNavTest.php`

30 testes, 329 asserções. HEAD não mudou. Nenhum caminho novo em `.sprint/`.

## T8 — Checkout manual — tentativa 1
- Subagente: 01a1c952-b6f5-7fc0-896a-5c8d8af63144
- Prova: `docker compose exec app php artisan test --filter SchedulingCheckoutTest`
- Resultado: passou
- Arquivos: `src/app/Livewire/Tenant/Checkout.php`, `src/resources/views/livewire/tenant/checkout.blade.php`, `src/tests/Feature/Scheduling/SchedulingCheckoutTest.php`, `src/app/Livewire/Tenant/CLAUDE.md`

8 testes, 91 asserções. HEAD `2e439b2a12e8f2651e3b10363f446b27f5506d44` não mudou. Nenhum caminho novo em `.sprint/` além de `log.md`, `plan.md` e `tasks.md`, que já estavam sem rastreio. O `CLAUDE.md` de `Livewire/Tenant` tem a frase de que dia e opção públicos são revalidados e de que `TenantHelper::isOpen()` continua sem recusar o pedido. 82 linhas.

## T9 — Checkout online — tentativa 1
- Subagente: 01a1c969-be39-7011-8ce2-0186dfa61f8c
- Prova: `docker compose exec app php artisan test --filter SchedulingOnlineCheckoutTest`
- Resultado: passou
- Arquivos: `src/app/Services/PaymentService.php`, `src/app/Http/Controllers/TenantPaymentController.php`, `src/app/Livewire/Tenant/Checkout.php`, `src/resources/views/livewire/tenant/checkout.blade.php`, `src/tests/Feature/Scheduling/SchedulingOnlineCheckoutTest.php`, `src/app/Services/CLAUDE.md`, `src/app/Http/Controllers/CLAUDE.md`, `src/app/Livewire/Tenant/CLAUDE.md`

7 testes, 219 asserções. HEAD não mudou. Nenhum caminho novo em `.sprint/`. As frases de `checkoutKey`, do `schedule` irmão de `fulfillment` e do `payOnline` estão nos `CLAUDE.md` locais. Services 214 linhas, Controllers 73, Tenant 82.

## T10 — Calendário na tela — tentativa 1
- Subagente: 01a1c97b-cb17-7102-adf6-e7409eaf0ee3
- Prova: `(cd src && npm run build) && docker compose exec app php artisan test --filter SchedulingCalendarTest`
- Resultado: passou
- Arquivos: `src/package.json`, `src/package-lock.json`, `src/resources/js/schedule-calendar.js`, `src/resources/js/schedule-calendar.css`, `src/resources/js/app.js`, `src/resources/js/admin.js`, `src/resources/views/livewire/tenant/checkout.blade.php`, `src/resources/views/livewire/admin/tenant/schedule-settings.blade.php`, `src/resources/js/CLAUDE.md`, `src/tests/Feature/Scheduling/SchedulingCalendarTest.php`, `src/public/build/manifest.json`

9 testes, 122 asserções. Build Vite 7.3.0 gerou o manifest. `air-datepicker` 3.6.0. HEAD não mudou. Nenhum caminho novo em `.sprint/`. A frase do `schedule-calendar.js` está no `CLAUDE.md` de `resources/js` (39 linhas).

## T11 — Antecedência do produto — tentativa 1
- Subagente: 01a1c998-8757-7ec2-ab7e-d4f6d1de92c1
- Prova: `docker compose exec app php artisan test --filter ProductFormValidationMarkupTest`
- Resultado: passou
- Arquivos: `src/app/Livewire/Admin/Products/Form.php`, `src/resources/views/livewire/admin/products/form.blade.php`, `src/tests/Feature/Admin/ProductFormValidationMarkupTest.php`, `src/app/Livewire/Admin/CLAUDE.md`

17 testes, 133 asserções. HEAD não mudou. Nenhum caminho novo em `.sprint/`. A frase do formulário está no `CLAUDE.md` de `Livewire/Admin` (71 linhas). A coluna `requires_scheduling` permanece.

## T12 — Dia visível no pedido — tentativa 1
- Subagente: 01a1c9a0-4ba3-7463-a8bf-0087bcc7febe
- Prova: `docker compose exec app php artisan test --filter SchedulingOrderDisplayTest`
- Resultado: passou
- Arquivos: `src/resources/views/livewire/admin/orders/index.blade.php`, `src/resources/views/livewire/admin/orders/show.blade.php`, `src/resources/views/livewire/tenant/account/index.blade.php`, `src/tests/Feature/Scheduling/SchedulingOrderDisplayTest.php`, `src/app/Livewire/Admin/CLAUDE.md`, `src/app/Livewire/Tenant/CLAUDE.md`

3 testes, 73 asserções. HEAD `2e439b2a12e8f2651e3b10363f446b27f5506d44` não mudou. Nenhum caminho novo em `.sprint/`. A lista e a ficha leem `scheduled_on` em `d/m/Y` e `meta.scheduling.option.label`. A conta faz o mesmo quando há snapshot. Admin 73 linhas, Tenant 82.

## T13 — Snapshot no contrato de Models — tentativa 1
- Subagente: 01a1cb02-e3c2-7b82-9247-2ea9bcbf798f
- Prova: `grep -n -e 'orders.meta.scheduling' -e 'settings.scheduling' src/app/Models/CLAUDE.md`
- Resultado: passou
- Arquivos: `src/app/Models/CLAUDE.md`

As duas expressões aparecem, nas linhas 85 e 93. O arquivo ficou com 120 linhas. HEAD não mudou. Nenhum caminho novo em `.sprint/` além de `log.md`, `plan.md`, `qa.md` e `tasks.md`, que já estavam sem rastreio.
