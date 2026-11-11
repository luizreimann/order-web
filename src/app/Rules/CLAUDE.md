# Rules

`ValidMoney` aceita o que `Money::toCents` aceita. Vazio não chega aqui: combine com `required` ou `nullable`.

`PhoneNumber` exige `Phone::isValid` (10–15 dígitos depois de normalizar).

`UniqueCustomerPhone` compara o canônico, na loja, ignorando o próprio id e o soft delete. É a unique parcial `(tenant_id, phone) WHERE deleted_at IS NULL`.

## Como Usar

- Reais de formulário: `ValidMoney`, combinada com `required` ou `nullable`. Não reimplemente o parser na Livewire. Vazio não chega na rule.
- Telefone de formulário: `PhoneNumber`. Unicidade de cliente: `UniqueCustomerPhone`. As duas delegam a `Phone`.
- Rule nova de formato fica aqui e chama `Support`. A regex não se duplica no componente.
- Checkout trata telefone como opcional. Perfil trata como obrigatório. A rule não decide essa obrigação: quem chama combina `required` ou não.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Rules/ValidMoney.php` — delega a `Money::toCents`.
> - `src/app/Rules/PhoneNumber.php` — delega a `Phone::isValid`.
> - `src/app/Rules/UniqueCustomerPhone.php` — canônico, ignora o próprio, ignora excluído.
> - `src/app/Livewire/Admin/Products/Form.php` — `ValidMoney` no preço do produto.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `rules-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
