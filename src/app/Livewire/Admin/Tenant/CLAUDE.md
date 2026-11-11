# Admin da loja — configuração

Quem vê e quem grava: `manageSettings`, salvo o slug (`updateSlug` só no `save`) e pagamentos (seção abaixo). `AuthorizesOnHydrate` repete a autorização em todo update. O guard da área está em `src/app/Auth/CLAUDE.md`.

Cada `save()` faz `data_set` do bloco inteiro. Chave irmã em `settings` sobrevive. Chave extra dentro do bloco é descartada. Dinheiro do formulário de entrega está em reais; o JSON guarda centavos (`Money::toCents`).

## Slug (`Tenant` coluna, não JSON)

`/admin/configuracoes`. Ver a tela é `manageSettings`. Gravar é `updateSlug`. 3–50, `^[a-z0-9]+(?:-[a-z0-9]+)*$`, único global, fora da lista reservada (`admin`, `login`, `logout`, `api`, `assets`, `storage`, `css`, `js`, `img`, `images`, `vendor`, `sitemap`, `robots`, `health`, `status`). Igual ao atual não grava. Mudou: redireciona para a URL nova. Cookie de cliente e sessão do admin usam o id da loja, não o slug.

## Horário (`business_hours`)

`enabled` padrão falso (a vitrine trata como aberta). `timezone` obrigatório (`timezone` do PHP), padrão `America/Sao_Paulo`. `auto_close` padrão verdadeiro. `paused` padrão falso. `schedule.{monday…sunday}`: `open`, `close` (`H:i`), `closed`. Dia sem dado no JSON nasce 08:00–18:00 e aberto. A leitura na vitrine está em `src/app/Livewire/Tenant/CLAUDE.md`.

`togglePause` inverte e já salva o bloco. O botão do header (`ToggleStoreStatusController`) só inverte `paused`.

## Entrega (`pickup` e `delivery`)

A leitura é `FulfillmentService` (`src/app/Services/CLAUDE.md`). Este formulário recusa gravar os dois desligados. Nome de bairro único, sem diferenciar maiúsculas, 2–80. Taxa do bairro obrigatória (0 é grátis). No máximo 100. `estimated_time_minutes` vazio ou 1–480. `addRegion` gera UUID. A lista manda regiões inativas também (`onlyActive: false`).

## Endereço (`address`)

`street` 255, `number` 20, `complement` e `neighborhood` 100, `city` 100, `state` 2, `zip_code` 10, `google_maps_url` URL até 500. `show_on_storefront` padrão verdadeiro: é o que `TenantHelper::getFormattedAddress` exige para mostrar. O checkout só pré-preenche `city` e `state`. Não é o endereço do pedido.

## Loja, contato, redes

`store`: `short_description` 160, `full_description` 2000, `show_about` padrão verdadeiro.

`contact`: `phone` 20 (texto livre), `whatsapp` vazio ou `55` + DDD + número (`^55\d{10,11}$`), `email`, `whatsapp_message` 500, `show_whatsapp_button` padrão falso. `TenantHelper::getWhatsAppUrl` monta `https://wa.me/{whatsapp}`.

`social`: `instagram`, `facebook`, `tiktok`, `youtube`, cada um URL até 255 ou null. Vazio grava null.

## Tema

Colunas `name` (2–120), `primary_color`, `secondary_color`. O formulário só aceita `#` + 6 hex. `TenantTheme` na vitrine também aceita 3 hex e, se o valor não for cor, cai no padrão (`#0d6efd` / `#6c757d`) em vez de imprimir o cru. `theme.font_family` tem de ser uma chave de `TenantTheme::fontStacks()`; outra vira a fonte padrão. A URL do Google Fonts sai dessa allow-list.

Arquivos em `tenants/{id}` via `ImageUpload`: logo 2 MB, banner 5 MB, favicon 512 KB e só png/ico até 512 px. Logo na coluna `logo_path`. Banner e favicon em `theme.banner_path` e `theme.favicon_path`. Remover apaga no disco `public` se o arquivo existe e zera a coluna ou a chave.

## SEO (`seo`)

`meta_title` 60, `meta_description` 160. `google_analytics_id` vazio ou `G-` + 4–20 de `[A-Z0-9]` (`GoogleAnalyticsId`). `UA-` não entra. `og_image` 2 MB, caminho em `seo.og_image_path`. Salvar sem arquivo novo preserva o caminho. Remover apaga o arquivo e zera a chave.

## Pagamentos

Rota `/{tenant}/admin/pagamentos`. Ver a tela exige `TenantPolicy::managePaymentMethods`. Credencial (`manageIntegrations`) é outra permissão: sem ela, public key, máscaras, URL de webhook e teste de conexão não carregam. `AuthorizesOnHydrate` refaz `authorizeView()` em todo request Livewire, não só no `mount`.

## Métodos (`settings.payment.methods`)

Lista normalizada por `PaymentMethod::allIds()`. Id desconhecido ou duplicado sai. Método do enum que faltava no JSON entra com `defaultConfig()` (manuais ligados, Mercado Pago desligado). Cada item: `id`, `provider`, `label` (2–80), `enabled`, `instructions` (até 1500), `config`. Pix manual guarda a chave em `config.pix_key` (até 255).

`saveMethods()` grava a lista inteira. Não mexe em `payment.mercadopago`. Método Mercado Pago ligado sem public key e access token não aparece em `enabledMethods` (a loja também o esconde no checkout).

## Credenciais (`settings.payment.mercadopago`)

`access_token` e `webhook_secret` são write-only. Não há propriedade pública: o valor digitado chega como argumento de `saveIntegrations` e `testMercadoPagoConnection`, nunca no snapshot do Livewire. Leitura para a tela é máscara (`••••` + 4 últimos; segredo com menos de 8 caracteres não revela nada). Public key é visível.

Salvar com argumento vazio preserva o segredo cifrado. Preenchido substitui via `EncryptionHelper::encryptIfNeeded`. `clearAccessToken` / `clearWebhookSecret` gravam null. Teste com argumento vazio usa o que está salvo; o token digitado fica só em memória, num clone do tenant. Exceção do teste não vai para log nem flash.

A URL do painel é `WebhookUrl::for(APP_URL, 'mercadopago', tenantId)`: `https` público, porta 443, sem localhost, IP privado ou TLD local. Fora isso a computed devolve null (o Mercado Pago não alcançaria). O id do tenant roteia; quem autentica é o webhook secret. Tópico no painel: Order. A cobrança não envia `notification_url`.

`recentPayments`: 10 últimos da loja, `viewAny` em `Payment`. Não incluí-los em log com `meta` (pode ter dado de Pix).

## Como Usar

- `save()` substitui o bloco inteiro com `data_set`. Chave irmã em `settings` sobrevive. Chave extra dentro do bloco some. Não faça merge que preserve lixo dentro do bloco, e não apague a irmã.
- Dinheiro do formulário entra em reais. O JSON guarda centavos via `Money::toCents`. Inválido não vira 0.
- Segredo não é propriedade pública. Copie `saveIntegrations` e `testMercadoPagoConnection`: o valor é argumento, a tela mostra máscara, vazio preserva o que está cifrado.
- Slug é coluna, não JSON. Ver a tela é `manageSettings`. Gravar é `updateSlug`.
- O formulário de entrega recusa gravar retirada e entrega ambas desligadas. Se o JSON já estiver assim, `FulfillmentService` cai em retirada. Não transforme esse fallback em erro 500.
- O botão do header só inverte `paused`. Salvar horários com o `paused` antigo sobrescreve o botão. Não "conserte" o controller de pausa para regravar o bloco.
- Cor no formulário é `#` mais 6 hex. `TenantTheme` ainda aceita 3 hex e cai no padrão se o valor não for cor. Não imprima o cru.
- Plano e capability não entram em `settings` (D6). Coluna ou tabela nova, quando existirem.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Livewire/Admin/Tenant/PaymentSettings.php` — write-only, máscara, `EncryptionHelper`.
> - `src/app/Livewire/Admin/Tenant/DeliverySettings.php` — reais na tela, centavos no JSON, teto de 100 bairros.
> - `src/app/Livewire/Admin/Tenant/BusinessHours.php` — o bloco inteiro, inclusive `paused`.
> - `src/app/Livewire/Admin/Tenant/Settings.php` — slug como coluna, `updateSlug` só no save.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `admin-tenant-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
