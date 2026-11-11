# Admin da loja — catálogo, clientes e pedidos

Telas de configuração da loja ficam em `src/app/Livewire/Admin/Tenant/CLAUDE.md`. A regra de quem pode está em `src/app/Policies/CLAUDE.md`. O guard `tenant_admin` está em `src/app/Auth/CLAUDE.md`. O menu é `AdminNavigation::definition()`, em `src/app/Support/CLAUDE.md`.

Tudo sob `/{tenant}/admin` usa o middleware `tenant.auth`. Cada componente usa `AuthorizesOnHydrate`: `authorizeView()` roda de novo em todo `POST /livewire/update`, não só no `mount`. Ação de escrita busca o model de novo com `forTenant($this->tenant->id)` e autoriza aquele registro. Id de outra loja é 404.

## Produto

`/produtos`, `/produtos/novo`, `/produtos/{id}/editar`. Lista no máximo 200, sem página. Busca `ilike` em nome e sku. Filtro de categoria é o id na query.

O formulário não manda slug. Vazio, o model gera a partir do nome; slug já gravado não muda quando o nome muda. Preço entra como string em reais (`ValidMoney`) e o mutator grava centavos. `compare_at` vazio fica null. Sem `track_inventory`, `stock_quantity` é gravado null. Categorias do `sync` têm de ser da loja e não excluídas.

`meta` é propriedade pública e `save()` persiste o array inteiro. Imagem nova passa por `ImageUpload` (jpg, png, webp, gif; sem svg; 3 MB; até 4096 px; nome UUID em `tenants/{id}/products/{id}`). Sem arquivo novo, um `image_path` já presente em `meta` permanece. `removeImage()` apaga o arquivo no disco `public` e tira a chave.

Apagar na lista é soft delete.

## Categoria

Slug opcional, regex `^[a-z0-9]+(?:-[a-z0-9]+)*$`, único entre não excluídos da loja. Vazio: o model gera. `Str::slug` antes de gravar.

`updateProductOrder` grava `products.sort_order` daqueles ids na loja. É a ordem global do produto, não a do pivô. Reordenar dentro de uma categoria mexe na ordem dele nas outras.

Apagar é soft delete.

## Vitrine

No máximo 10. A lista esconde "nova" quando a contagem chega no teto; a criação que vale é `Showcase::createWithinLimit` (trava a linha da loja). Estourar o teto devolve flash e não redireciona. Editar não passa por esse teto.

Tipo: `all`, `featured`, `best_sellers`, `recent`, `category`, `custom`. `display_mode`: `list` ou `carousel`. `max_items` de 1 a 50. `category_id` obrigatório só no tipo `category`, e a categoria tem de ser da loja. Tipo `custom` faz `syncProducts` na ordem do array; qualquer outro tipo dá `detach` no pivô. Slug com a mesma regra da categoria.

`updateOrder` na lista grava `sort_order` pelo índice do array. Id que não é desta loja é ignorado.

## Cliente

Lista no máximo 300. O padrão do filtro é `active`, não `all`. Busca `ilike` em nome, e-mail e telefone já canônico: máscara no termo pode não achar.

Formulário: nome obrigatório, e-mail opcional, telefone opcional porém `PhoneNumber` + `UniqueCustomerPhone` (formato canônico, ignora o próprio, ignora excluído). `is_active` falso faz o guard da loja recusar o cookie. Endereço é o `CustomerAddress` com `is_default`; desmarcar apaga esse registro. Não mexe em `session_token`. Apagar na lista é soft delete; pedido antigo continua em `customer()`.

## Pedido

Lista no máximo 200. Busca: número se o termo é só dígitos, senão `ilike` em `customer_name` e `customer_phone`. `status` e `payment` fora do enum não entram na query. Ordenação: `newest` (padrão), `oldest`, `total_desc`.

A ficha não cria nem apaga pedido e não mexe em itens nem totais. `updateStatus` valida `OrderStatus` e `PaymentStatus` e chama `OrderService::updateOrder`. Cancelar devolve estoque uma vez; sair de cancelado baixa de novo e, sem saldo, o erro fica no campo `status` e o pedido não muda. `payment_method` é string livre até 50 caracteres, não o enum. Dá para marcar `paid` por aqui sem passar pelo gateway.

## Dashboard

`/admin` autoriza `TenantPolicy::view` (`dashboard.view`). Não agrega pedido nem catálogo no componente.

## Como Usar

- Componente novo usa `AuthorizesOnHydrate`. `authorizeView()` roda no mount e de novo em todo update. Escrita busca com `forTenant($this->tenant->id)` e autoriza aquele model. Id de outra loja é 404.
- Lista tem teto (200 produtos, 200 pedidos, 300 clientes) e não pagina. Não acrescente paginação sem pedido.
- O formulário de produto não envia slug. Slug já gravado não muda quando o nome muda. Não "corrija" isso reescrevendo o slug no `save`.
- `meta` de produto é propriedade pública e `save()` grava o array inteiro. Não copie esse formato para segredo. Credencial copia `PaymentSettings`: argumento do método, fora do snapshot.
- Imagem nova passa por `ImageUpload`. Apagar arquivo antigo só se o caminho está sob o diretório da mesma loja.
- Pedido nesta tela não mexe em item nem total. Status passa por `OrderService::updateOrder`. `payment_method` aqui é string até 50 caracteres, não o enum. Dá para marcar `paid` sem gateway: é o contrato da ficha, não um buraco para fechar.
- `updateProductOrder` grava `products.sort_order` global, não o pivô. Reordenar numa categoria mexe nas outras. Não mude para o pivô sem decisão.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Livewire/Admin/Concerns/AuthorizesOnHydrate.php` — a reautorização do update.
> - `src/app/Livewire/Admin/Products/Form.php` — `ValidMoney`, `meta` público, `ImageUpload`.
> - `src/app/Livewire/Admin/Orders/Show.php` — `updateOrder`, sem itens.
> - `src/app/Livewire/Admin/Showcases/Form.php` — `createWithinLimit` e o detach fora do tipo `custom`.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `admin-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
