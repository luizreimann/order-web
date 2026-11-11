# routes/

Mapa de URL. A rota aponta para um Livewire ou para um controller. Validação, preço, estoque e autorização não nascem aqui.

## Estrutura

| Arquivo | Papel |
| --- | --- |
| `web.php` | Tudo que o navegador chama. Landing e webhooks ficam fora do grupo `{tenant:slug}`. Loja, checkout, conta e admin ficam dentro. |
| `console.php` | `payments:reconcile --pix-only` todo minuto, `withoutOverlapping(10)`. O comando em si está em `app/Console/Commands/ReconcilePayments.php`; o efeito está em `src/app/Services/CLAUDE.md`. |

Não há `api.php`. Saúde é `GET /up`, registrado em `bootstrap/app.php`.

## Como Usar

- **Loja nova entra no grupo `prefix('{tenant:slug}')->middleware(['tenant', 'tenant.customer'])`.** Admin autenticado acrescenta `tenant.auth`. Conta (`/conta`) acrescenta `tenant.customer.auth`. Login do admin fica dentro de `tenant` e fora de `tenant.auth`.
- **Webhook fica antes desse grupo.** `{tenant:slug}` casaria `webhooks/{x}` como slug. A variante com `{tenantId}` (UUID) é a do painel; a sem tenant é compatibilidade.
- **CSRF:** `webhooks/*` já está na exceção em `bootstrap/app.php`. Não abra outra exceção para rota de navegador.
- **Rota sem tenant** (landing, webhook) entra na allowlist de `RouteMiddlewareAuditTest`, com o motivo no código. O teste é que impede a rota nova de nascer solta.
- **Tela é classe Livewire na rota**, não closure com regra. HTTP que não é componente (login, pausa, `process_payment`, status, webhook, landing) aponta para controller.
- **Nome estável:** `tenant.*` na vitrine, `tenant.admin.*` no painel. O menu (`AdminNavigation`) e os testes de navegação dependem desses nomes.
- **Throttle nomeado** (`checkout-status`) é registrado no `AppServiceProvider` e aplicado na rota. Não espalhe número mágico no `web.php`.

## Exemplo Real

> Antes de implementar, leia:
> - `src/routes/web.php` — landing, os dois webhooks, o grupo da loja e o grupo `admin`.
> - `src/routes/console.php` — o agendamento do Pix.
> - `src/bootstrap/app.php` — aliases e a exceção CSRF.
> - `src/tests/Feature/Tenancy/RouteMiddlewareAuditTest.php` — a lista do que pode ficar sem `tenant`.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `routes-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
