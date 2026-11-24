# QA — Agendamento da encomenda

## Rodada 1
- Veredito: aberta
- Provas reexecutadas: `docker compose exec app php artisan test --filter SchedulingWindowTest`; `docker compose exec app php artisan test --filter SchedulingSchemaTest`; `docker compose exec app php artisan test --filter SchedulingCalendarTest`; `docker compose exec app php artisan test --filter SchedulingOrderWriteTest`; `docker compose exec app php artisan test --filter 'SchedulingCapacityTest|TenantIsolationAuditTest'`; `docker compose exec app php artisan test --filter SchedulingCapacityRaceTest`; `docker compose exec app php artisan test --filter 'ScheduleSettingsTest|AdminNavigationTest|LivewireAdminAuthorizationAuditTest|RouteMiddlewareAuditTest'`; `docker compose exec app php artisan test --filter SchedulingCheckoutTest`; `docker compose exec app php artisan test --filter SchedulingOnlineCheckoutTest`; `(cd src && npm run build) && docker compose exec app php artisan test --filter SchedulingCalendarTest`; `docker compose exec app php artisan test --filter ProductFormValidationMarkupTest`; `docker compose exec app php artisan test --filter SchedulingOrderDisplayTest`

### Achados
- Código: `src/app/Models/CLAUDE.md` registra `orders.scheduled_on` e não registra o snapshot `orders.meta.scheduling` (irmão de `meta.fulfillment` e `meta.payment`: `date` e `option` com `kind`, `start`, `end`, `label`). O índice de `Tenant.settings` também não cita `settings.scheduling`. O plano aceito manda esse registro. `grep -n scheduling src/app/Models/CLAUDE.md` não imprime nada. Vira a T13.
- Spec: nenhum.
- Dispensa: nenhuma.

Os 15 critérios da spec passaram nas provas acima. O clique no widget, a seta do mês e o texto pintado na célula continuam a lacuna aceita no plano e nas tarefas. O JS em `src/resources/js/schedule-calendar.js` pinta esse texto; o PHPUnit não clica.

Contagens desta rodada: Window 10/17, Schema 2/9, Calendar 9/122, OrderWrite 8/145, Capacity+Audit 11/56, Race 1/10, Settings+Navigation+Auth+Routes 30/329, Checkout 8/91, Online 7/219, build Vite 7.3.0 e Calendar de novo 9/122, ProductForm 17/133, Display 3/73. HEAD `2e439b2a12e8f2651e3b10363f446b27f5506d44` não mudou. O status não ganhou arquivo.

## Rodada 2
- Veredito: fechada
- Provas reexecutadas: `docker compose exec app php artisan test --filter SchedulingWindowTest`; `docker compose exec app php artisan test --filter SchedulingSchemaTest`; `docker compose exec app php artisan test --filter SchedulingCalendarTest`; `docker compose exec app php artisan test --filter SchedulingOrderWriteTest`; `docker compose exec app php artisan test --filter 'SchedulingCapacityTest|TenantIsolationAuditTest'`; `docker compose exec app php artisan test --filter SchedulingCapacityRaceTest`; `docker compose exec app php artisan test --filter 'ScheduleSettingsTest|AdminNavigationTest|LivewireAdminAuthorizationAuditTest|RouteMiddlewareAuditTest'`; `docker compose exec app php artisan test --filter SchedulingCheckoutTest`; `docker compose exec app php artisan test --filter SchedulingOnlineCheckoutTest`; `(cd src && npm run build) && docker compose exec app php artisan test --filter SchedulingCalendarTest`; `docker compose exec app php artisan test --filter ProductFormValidationMarkupTest`; `docker compose exec app php artisan test --filter SchedulingOrderDisplayTest`; `grep -n -e 'orders.meta.scheduling' -e 'settings.scheduling' src/app/Models/CLAUDE.md`

### Achados
- Código: nenhum. A frase da T13 está nas linhas 85 e 93 de `src/app/Models/CLAUDE.md`.
- Spec: nenhum.
- Dispensa: nenhuma.

Os 15 critérios da spec passaram de novo. Contagens: Window 10/17, Schema 2/9, Calendar 9/122, OrderWrite 8/145, Capacity+Audit 11/56, Race 1/10, Settings+Navigation+Auth+Routes 30/329, Checkout 8/91, Online 7/219, build Vite 7.3.0 e Calendar de novo 9/122, ProductForm 17/133, Display 3/73. O grep achou as duas expressões. HEAD `2e439b2a12e8f2651e3b10363f446b27f5506d44` não mudou. O status não ganhou arquivo.
