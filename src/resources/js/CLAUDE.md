# JavaScript

Não há regra de pedido, preço ou estoque aqui. O servidor recalcula.

`app.js` é a loja (`layouts/app`). Sobe Bootstrap, Font Awesome e o Splide de `.showcase-carousel` (`carousel.js`, uma vez por elemento). O bundle não cria `window.bootstrap`. O modal do Pix usa `window.StorefrontUI.Modal`, a classe do mesmo módulo. O POST do checkout está no Blade, não neste arquivo (`src/app/Livewire/Tenant/CLAUDE.md`).

`admin.js` é o painel e também a tela de login. Expõe `window.Sortable` para o script de reordenar das views. `admin/index.js` só liga tema, tooltip e pausa se existir `.admin-shell` (o login não tem). O QR do WhatsApp sai de `data-whatsapp-qr`, desenhado com `qrcode` 1.5.4 (`toCanvas`). Redesenha no `morph.updated` quando a string muda. Não decide conectar, desconectar nem pairing. Sem CDN.

`phone-mask.js` é importado por `app.js` e por `admin.js`. Formata só o input `data-phone-mask`: no máximo 11 dígitos nacionais, tira letra, tira `55` colado quando o total tem 12 ou 13 e o DDD restante não tem zero, e mostra `(11) 3333-4444` ou `(11) 99999-9999`. Não decide login, pedido nem DDD. O valor que o Livewire envia é o texto mascarado do input. O `id` `customerPhoneInput` permanece. O POST do checkout continua no Blade.

`schedule-calendar.js` não calcula vaga, antecedência nem folga: pinta o payload do servidor no checkout e remonta no `morph.updated`. No admin, o mesmo widget só chama `addOffDay`. O POST do checkout não vem para este diretório. Semana no domingo. Célula `off` mostra `Folga` e célula `full` mostra `Lotado`.

`admin/theme.js`: `localStorage.theme` é `light` ou `dark`. A pintura antes do primeiro frame é o script inline do layout, com a mesma regra. Ids: `themeToggleBtn` e `themeToggleBtnMobile`.

`admin/store-status.js`: POST na URL do botão, CSRF no meta. Atualiza os dois botões (sidebar e offcanvas) com `{ success, paused }`. O texto do toast fala em pedidos pausados. O servidor só inverte `business_hours.paused`. A vitrine não recusa pedido por isso.

`admin/toast.js` registra `window.showToast`. `admin/tooltips.js` liga `[data-bs-toggle="tooltip"]`.

## Como Usar

- Não calcule preço, taxa, mínimo nem estoque aqui. O servidor recalcula.
- Modal da loja é `window.StorefrontUI.Modal`. O bundle não cria `window.bootstrap`. Não procure o global do Bootstrap para abrir o Pix.
- O POST do checkout fica no Blade do checkout, não neste diretório. Não o traga para `app.js` como limpeza.
- `admin/index.js` só liga tema, tooltip e pausa se existir `.admin-shell`. A tela de login carrega `admin.js` e não tem essa casca: não assuma que o bind rodou.
- O toast de pausa fala em pedidos pausados. O servidor só inverte `business_hours.paused`, e a vitrine não recusa o pedido. Não altere o PHP para cumprir o texto do toast, e não copie o texto como se fosse a regra.
- Tema claro/escuro é `localStorage.theme` (`light` ou `dark`). Não é a cor da loja. A pintura antes do primeiro frame é o script inline do layout, com a mesma regra. Mantenha os dois iguais.
- Reordenar no admin usa `window.Sortable`, exposto por `admin.js`.
- O QR do WhatsApp é `data-whatsapp-qr` desenhado por `admin.js` com `qrcode` pinado no lock. O script não decide conexão. Sem CDN.
- A máscara de telefone é `phone-mask.js`, no input `data-phone-mask`. O script não decide login, pedido nem DDD. Não troque o `wire:model` do campo. O `id` `customerPhoneInput` permanece.
- `schedule-calendar.js` não calcula vaga, antecedência nem folga. No checkout pinta o JSON `data-schedule-calendar` e remonta no `morph.updated`, sem `wire:ignore`. No admin só chama `addOffDay` com o `Y-m-d` da célula, sem `toISOString()`. Semana no domingo, mesmo com o locale `pt` na segunda. Célula `off` mostra `Folga` e célula `full` mostra `Lotado`. O POST do checkout não vem para este diretório.

## Exemplo Real

> Antes de implementar, leia:
> - `src/resources/js/app.js` — Bootstrap, Font Awesome, Splide, `StorefrontUI`.
> - `src/resources/js/admin.js` — `window.Sortable` e o que a tela de login também carrega.
> - `src/resources/js/admin/store-status.js` — POST, os dois botões, o texto do toast.
> - `src/resources/js/admin/theme.js` — `themeToggleBtn` e `themeToggleBtnMobile`.
> - `src/resources/js/schedule-calendar.js` — payload no checkout, `addOffDay` no admin, `morph.updated`.
> - `src/resources/js/phone-mask.js` — só formata `data-phone-mask`. Não decide login, pedido nem DDD.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `js-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
