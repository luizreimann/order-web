# Enums

Papel novo: só `Role::permissions()`. As policies não conhecem papel.

`tenant_admin` recebe `Permission::cases()`. `platform_admin` recebe lista vazia e não tem `tenant_id`. `requiresTenant()` é falso só para ele. Papel legado não é case: `resolvedRole()` e o guard leem com `tryFrom` e negam.

`PaymentMethod`: manuais `cash_on_delivery`, `card_on_delivery`, `pix_manual` (`provider()` `manual`); Mercado Pago `mp_pix`, `mp_credit_card`, `mp_debit_card` (`provider()` `mercadopago`). `isOnlinePix()` é só `mp_pix`. E-mail de cobrança online: `EMAIL_RULES` (`email:rfc,filter`, max 180).

Três status, e não se misturam. Colunas e o efeito no pedido: `src/app/Models/CLAUDE.md` e `src/app/Services/CLAUDE.md`.

`OrderStatus`, `PaymentStatus` e `GatewayPaymentStatus` têm `label()` e `variant()` para `<x-status-badge>`. Variantes usadas: success, warning, info, secondary. Cancelado, recusado e reembolsado são secondary, não danger.

## Como Usar

- Papel ou permissão nova: case em `Permission` e a lista em `Role::permissions()`. A policy pede a permissão. Não compare o papel no `if`.
- `platform_admin` continua com lista vazia e sem `tenant_id`. Não lhe dê permissão de loja aqui para "liberar o painel".
- Método de pagamento novo é case de `PaymentMethod`, com `provider()` e `isManual()`. Manual não ganha gateway. Online entra no `defaultConfig()` desligado.
- Variante de badge fica em success, warning, info, secondary. Cancelado, recusado e reembolsado permanecem secondary (decisão D8, badge neutro).
- Não reutilize o enum de um status para coluna de outro. São três vocabulários.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Enums/Role.php` — `permissions()` e `requiresTenant()`.
> - `src/app/Enums/Permission.php` — os cases que as policies pedem.
> - `src/app/Enums/PaymentMethod.php` — manual versus Mercado Pago, `EMAIL_RULES`.
> - `src/app/Enums/GatewayPaymentStatus.php` — `toPaymentStatus()` e o que não altera o pedido.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `enums-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
