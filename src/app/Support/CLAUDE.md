# Support

Gaveta. Este arquivo é o índice dela: qualquer edição aqui carrega o arquivo inteiro, então cada classe cabe numa regra, não num ensaio. O fluxo que usa a classe está no mapa de quem chama.

| Arquivo | Regra que mora aqui |
|---|---|
| `Money` | Única conversão real → centavos. `toCents` devolve null se não entender; não vira 0. "1.250" sem vírgula é inválido. Float arredonda ao centavo. Teto `MAX_CENTS`. |
| `Phone` | Único normalizador. Canônico = só dígitos, sem DDI 55 quando o número é brasileiro de 12 ou 13 dígitos e o DDD é 11–99. DDD 55 (10 ou 11 dígitos) não perde o prefixo. `isValid`: 10–15 dígitos. |
| `TenantContext` | Tenant da execução. Binding scoped. Três estados e o que cada um filtra: `src/app/Models/CLAUDE.md`. |
| `FulfillmentQuote` | Cotação imutável. Quem calcula é `FulfillmentService` (`src/app/Services/CLAUDE.md`). `snapshot()` vai para `orders.meta.fulfillment`. |
| `PixExpiration` | `expiration_time` do Mercado Pago. Padrão `PT30M`, faixa 30 min–30 dias. Fora disso, `PT30M`. |
| `PixWindow` | Prazo nosso do Pix: padrão e teto 300 s, piso 60 s, mais 10 s. Chave `payments.meta.pix_expires_at`. Cartão não vence aqui. |
| `WebhookSecurity` | Sem secret, webhook falha fechado. Exceção: `local` e `testing`. |
| `WebhookUrl` | URL do painel só se for https público, porta 443, sem localhost, IP privado ou TLD local. Senão null. |
| `EncryptionHelper` | `enc:` + `Crypt`. Já cifrado não cifra de novo. Falha ao decifrar devolve null, não a string `enc:`. |
| `DeviceSessionId` | Device ID do MP: 8–512 de `[A-Za-z0-9._-]`. Fora isso, null, e a cobrança segue. |
| `ImageUpload` | Única política de imagem. Conteúdo (magic bytes), não o nome. jpg, png, webp, gif. Favicon: png ou ico, até 512 px. Sem SVG. Extensão e nome UUID vêm do conteúdo. Apagar arquivo antigo só se o caminho está sob o diretório da mesma loja. Não re-encoda. |
| `GoogleAnalyticsId` | Só `G-` + 4–20 de `[A-Z0-9]`. Inválido não é emitido. |
| `TenantTheme` | O que pode ir para CSS. Fonte: chave exata de `FONTS`, senão a do sistema. Cor: `#rgb` ou `#rrggbb`, senão `#0d6efd` / `#6c757d`. Não limpa valor quase válido. |
| `TenantHelper` | Leitura de vitrine. `isOpen` não bloqueia pedido. `getFormattedAddress` só com `address.show_on_storefront`. WhatsApp: `https://wa.me/{número}`. SEO que a casca imprime: `getSeoData`, em `resources/views/CLAUDE.md`. |
| `AdminNavigation`, `AdminNavItem`, `AdminNavSection` | Menu do admin. Ver abaixo. |

## Menu

A lista é `AdminNavigation::definition()`. `<x-admin-nav>` desenha. O layout e `components/admin-nav.blade.php` não listam item. Item novo entra na `definition()`. `AdminNavigationTest` trava ordem, rótulo, rota, ícone e exige que a rota e a ability existam.

`sidebar` no desktop, `offcanvas` no celular (ids com sufixo `Mobile`). Outro `mode` lança. Link do offcanvas sem `data-bs-dismiss`: o Bootstrap cancela o clique. O admin não usa `wire:navigate`.

`can` null: visível para quem passou de `tenant.auth`. Só Pagamentos (`managePaymentMethods`) e URL/Slug (`updateSlug`) filtram. Grupo vazio some. Esconder não autoriza a rota. Item sem `can` continua no menu mesmo que a tela responda 403.

`active` vazio destaca só a rota do link. Pedidos, clientes, produtos, categorias e vitrines usam `tenant.admin.{recurso}.*`. Chaves de id, sem acento: `Operacao`, `Catalogo`, `Config`. Pausa e sair não estão aqui: `<x-admin-store-actions>` e `<x-admin-account>`.

## Como Usar

- Classe nova nesta gaveta: uma responsabilidade, e uma linha na tabela do topo. Este arquivo é índice. Não vire ensaio da classe.
- Reais e telefone passam por `Money` e `Phone`. Não normalize de novo na tela.
- Segredo passa por `EncryptionHelper`. Falha ao decifrar devolve null. Não devolva o valor claro para propriedade pública de Livewire.
- Arquivo de imagem passa por `ImageUpload`. Sem SVG. A extensão sai do conteúdo, não do nome enviado.
- Cor e fonte que vão para CSS passam por `TenantTheme`. Não imprima `settings` cru.
- Item de menu entra em `AdminNavigation::definition()` e o `AdminNavigationTest` acompanha ordem, rótulo, rota, ícone e a existência da ability. Esconder o item não autoriza a rota.
- URL de webhook do painel é `WebhookUrl::for`. Fora de https público ela é null. Não monte a URL na Blade.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Support/Money.php:toCents` — null quando não entende.
> - `src/app/Support/Phone.php` — canônico e o caso do DDD 55.
> - `src/app/Support/AdminNavigation.php:definition` — a lista inteira do menu.
> - `src/app/Support/ImageUpload.php` — magic bytes, UUID, diretório da loja.
> - `src/app/Support/EncryptionHelper.php` — prefixo `enc:`.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `support-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
