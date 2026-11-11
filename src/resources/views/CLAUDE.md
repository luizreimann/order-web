# Views

Gaveta de markup. Regra de negócio não nasce aqui. O que cada tela grava está em `src/app/Livewire`. Este arquivo é a casca: os dois layouts, os partials que eles incluem, a landing e os poucos componentes que têm contrato.

`livewire/**` só desenha. `btn`, `card`, `confirm-action`, `data-table`, `empty-state`, `form-check`, `form-field`, `form-select`, `inline-loader`, `loader`, `page-header`, `section-title`, `toast-container` não têm regra de domínio.

## Layouts

`layouts/app.blade.php` é a loja. Exige o middleware `tenant` (compartilha `currentTenant`). Sem tenant, o header, o modal e o popup de login não entram. O carrinho do header é `CartService::count` (linhas válidas, não a soma). Vite: `app.css` e `app.js`.

`layouts/tenant-admin.blade.php` é o painel. Também inclui `partials/tenant-theme` (a cor da loja vale no admin) e não inclui `tenant-meta` (sem Analytics). Menu: `src/app/Support/CLAUDE.md`. O único script inline é o que aplica `data-bs-theme` antes do paint, lendo `localStorage.theme` (`light` ou `dark`; senão o esquema do sistema). O resto do JS está em `resources/js/admin/`. Tema claro/escuro não é a cor da loja.

## Tema, SEO, rodapé

`partials/tenant-theme.blade.php` é o único lugar que escreve cor e fonte em CSS. Tudo passa por `TenantTheme`. Não interpolar coluna nem `settings` direto num `<style>`. Vale em `:root` e em `[data-bs-theme="light"]`, senão o preview da personalização perde a cor. `--bs-secondary-rgb` e `.badge.bg-secondary` não recebem a cor da loja (D8: status neutro).

`partials/tenant-meta.blade.php` usa `TenantHelper::getSeoData`. Título: o da página Livewire mais o nome da loja, ou o `meta_title`, ou o nome. Descrição: `meta_description` ou `store.short_description`. Imagem: og, senão banner, senão logo. Analytics só se `GoogleAnalyticsId::valid` devolve o id; no JS entra por `Js::from`, não por interpolação. Favicon sai de `theme.favicon_path`.

`partials/tenant-footer.blade.php` e `components/contact-modal.blade.php` só leem `TenantHelper` (contato, endereço, redes, horário). Endereço só aparece com `show_on_storefront`. Horário "aberto" no header é `isOpen` e não trava pedido.

`components/whatsapp-button.blade.php` só renderiza com `contact.show_whatsapp_button` e URL. `components/tenant-header.blade.php` mostra logo ou nome, badge aberto/fechado, taxa, mínimo, prazo, carrinho e conta.

`components/status-badge.blade.php` exige enum com `label()` e `variant()`, ou o bool `active`. Variantes aceitas: success, warning, info, secondary, light, dark. `primary` e `danger` caem em secondary. A cor do status mora no enum.

## Admin fora do menu

`components/admin-store-actions.blade.php`: o botão de pausa só existe com `toggleStoreStatus`. O POST é `tenant.admin.toggle-store-status`. O JS é `resources/js/admin/store-status.js`, no seletor `[data-store-status-toggle]`. O link "ir para a loja" abre `/{slug}` em outra aba e não passa pelo gate.

`components/admin-account.blade.php`: nome de `tenantAdmin`, botão de tema (`resources/js/admin/theme.js`, ids `themeToggleBtn` e `themeToggleBtnMobile`) e POST de logout. Ids do offcanvas levam sufixo `Mobile`.

## Landing

`GET /` (`platform.landing`) não tem tenant, não lê loja e não usa `layouts/app`. A view é `platform/landing.blade.php`. Conteúdo de marketing e planos não existem ainda.

## Como Usar

- Regra de negócio não nasce em Blade. A tela que grava está em `src/app/Livewire`. Esta pasta é a casca.
- Gaveta: componente sem contrato (`btn`, `card`, `form-field` e os demais citados no topo) não ganha seção aqui.
- Cor e fonte só em `partials/tenant-theme`, via `TenantTheme`. Não interpole coluna nem `settings` num `<style>`. `--bs-secondary-rgb` e `.badge.bg-secondary` não recebem a cor da loja.
- Analytics entra por `Js::from` depois de `GoogleAnalyticsId::valid`. Não concatene o id no script.
- Admin não usa `wire:navigate`. Link de offcanvas não leva `data-bs-dismiss`.
- Landing não usa `layouts/app` e não lê tenant. Não "aproveite" o header da loja nela.
- O POST do checkout mora em `livewire/tenant/checkout.blade.php` porque fala com o Brick. O contrato desse POST está em `Livewire/Tenant` e em `Controllers`. Não o mova para `app.js` como organização.

## Exemplo Real

> Antes de implementar, leia:
> - `src/resources/views/layouts/app.blade.php` — a loja, o contador do carrinho, o Vite.
> - `src/resources/views/partials/tenant-theme.blade.php` — o único `<style>` de cor.
> - `src/resources/views/partials/tenant-meta.blade.php` — título, OG e Analytics.
> - `src/resources/views/components/status-badge.blade.php` — variante cai para secondary.
> - `src/resources/views/components/admin-nav.blade.php` — desenha; não lista item.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `views-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
