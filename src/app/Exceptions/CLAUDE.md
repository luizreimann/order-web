# Exceptions

Falham dentro da transação do pedido e desfazem o que ela já escreveu: `EmptyCartException`, `BelowMinimumOrderException`, `FulfillmentException` (campo => mensagem), `InsufficientStockException`.

`CartStockLimitException` é o teto do carrinho na sessão, antes da venda. Não grava pedido.

`CrossTenantWriteException`: `BelongsToTenant` barrou `save` de outra loja. Não capture para seguir gravando.

`ShowcaseLimitReachedException`: `Showcase::createWithinLimit` passou de 10.

`UnsupportedPaymentMethodException`: método desconhecido, manual passado a `forMethod()`, ou provedor sem `extend()`.

## Como Usar

- Falha que desfaz o pedido nasce dentro de `createFromCart` e é uma das quatro primeiras classes deste arquivo. Não capture para continuar gravando.
- `CrossTenantWriteException` não vira flash que segue o `save`. Ela é barreira.
- Recusa de gateway não é exceção de domínio: `charge()` devolve `ChargeOutcome::failed`. O texto ao comprador é `PaymentFailure::userMessage()`.
- Exceção nova carrega o campo quando o formulário precisa marcá-lo (`FulfillmentException` faz isso). Não use mensagem solta se a tela já tem chave de erro.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Exceptions/InsufficientStockException.php` — a baixa que aborta a transação.
> - `src/app/Exceptions/FulfillmentException.php` — campo e mensagem, vários erros juntos.
> - `src/app/Exceptions/CrossTenantWriteException.php` — a escrita que a trait barra.
> - `src/app/Services/OrderService.php:createFromCart` — quem deixa essas exceções subirem.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `exceptions-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
