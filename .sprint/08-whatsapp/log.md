# Log — WhatsApp da loja

## T1 — Telefone brasileiro — tentativa 1
- Subagente: 01a20f1a-20b3-76b0-bfd4-fe5a23defddc
- Prova: `docker compose exec app php artisan test --filter PhoneTest`
- Resultado: passou
- Arquivos: `src/app/Support/Phone.php`, `src/tests/Unit/Support/PhoneTest.php`, `src/app/Support/CLAUDE.md`

16 testes, 70 asserções. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. O `CLAUDE.md` de Support passou a dizer `isValid` de 10 ou 11, DDD sem zero, `+` só se for `+55`, e `format` da máscara nacional.

## T2 — Cliente HTTP do Zaptria — tentativa 1
- Subagente: 01a20f1f-4523-73e3-90f8-6c30dcc85297
- Prova: `docker compose exec app php artisan test --filter ZaptriaClientTest`
- Resultado: passou
- Arquivos: `src/app/Services/ZaptriaClient.php`, `src/config/services.php`, `src/.env.example`, `src/tests/Unit/Services/ZaptriaClientTest.php`, `src/app/Services/CLAUDE.md`, `Claude.md`

11 testes, 53 asserções. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. O `CLAUDE.md` da raiz ganhou a frase de que a credencial do Zaptria fica no ambiente, o código e o aviso não saem de propriedade pública, e o HTTP fica fora da transação do pedido.

## T3 — Pedir e acertar o código — tentativa 1
- Subagente: 01a20f27-1f5d-7b93-a056-39a248f13a20
- Prova: `docker compose exec app php artisan test --filter 'CustomerLoginCodeTest|TenantIsolationAuditTest'`
- Resultado: passou
- Arquivos: `src/database/migrations/2026_11_24_120000_create_customer_login_codes_table.php`, `src/app/Models/CustomerLoginCode.php`, `src/app/Services/CustomerLoginCodeService.php`, `src/tests/Feature/Auth/CustomerLoginCodeTest.php`, `src/app/Models/CLAUDE.md`, `src/app/Services/CLAUDE.md`

18 testes, 201 asserções. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. O audit de isolamento passou sem afrouxar a lista: o model novo usa a trait e a suíte já varre quem tem `tenant_id`.

## T4 — Sem remetente e falha temporária — tentativa 1
- Subagente: 01a20f39-e17a-70b3-a0c8-be6ed8607827
- Prova: `docker compose exec app php artisan test --filter CustomerLoginCodeFallbackTest`
- Resultado: passou
- Arquivos: `src/app/Services/CustomerLoginCodeService.php`, `src/tests/Feature/Auth/CustomerLoginCodeFallbackTest.php`, `src/app/Services/CLAUDE.md`

18 testes no filtro da tarefa. `CustomerLoginCodeTest` continuou verde. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. Sem remetente entra pelo login e avisa o responsável; falha temporária não entra.

## T5 — Popup em dois passos — tentativa 1
- Subagente: 01a20f49-1982-7260-938b-80aec656aac6
- Prova: `docker compose exec app php artisan test --filter 'CustomerLoginPopupTest|TenantAdminGuardTest|CustomerGuardTest'`
- Resultado: passou
- Arquivos: `src/app/Livewire/Tenant/Auth/LoginPopup.php`, `src/resources/views/livewire/tenant/auth/login-popup.blade.php`, `src/tests/Feature/Auth/CustomerLoginPopupTest.php`, `src/app/Livewire/Tenant/CLAUDE.md`

27 testes, 135 asserções. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. A HTML do popup não contém o código. Fechar no primeiro passo não chama o HTTP. O código digitado não é propriedade pública. O login do admin não pede código.

## T6 — Webhook — tentativa 1
- Subagente: 01a20f52-8f66-7d53-a5a2-5e82eb50225a
- Prova: `docker compose exec app php artisan test --filter 'ZaptriaWebhookTest|RouteMiddlewareAuditTest'`
- Resultado: passou
- Arquivos: `src/app/Http/Controllers/ZaptriaWebhookController.php`, `src/routes/web.php`, `src/tests/Feature/WhatsApp/ZaptriaWebhookTest.php`, `src/tests/Feature/Tenancy/RouteMiddlewareAuditTest.php`, `src/app/Http/Controllers/CLAUDE.md`, `src/routes/CLAUDE.md`

20 testes, 105 asserções. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. Assinatura inválida responde 401 e não muda o passo. Entrega repetida não desfaz a troca do meio. `message.received` não cria cliente nem código.

## T7 — Tela e textos — tentativa 1
- Subagente: 01a20f5f-f151-74c1-9aad-d26600b83793
- Prova: `docker compose exec app php artisan test --filter 'WhatsAppSettingsTest|AdminNavigationTest|LivewireAdminAuthorizationAuditTest|RouteMiddlewareAuditTest'`
- Resultado: passou
- Arquivos: `src/app/Livewire/Admin/Tenant/WhatsAppSettings.php`, `src/resources/views/livewire/admin/tenant/whatsapp-settings.blade.php`, `src/routes/web.php`, `src/app/Support/AdminNavigation.php`, `src/tests/Unit/Support/AdminNavigationTest.php`, `src/tests/Feature/WhatsApp/WhatsAppSettingsTest.php`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`, `src/tests/Feature/Admin/AdminLayoutTest.php`, `src/tests/Feature/Components/AdminNavTest.php`

60 testes, 559 asserções, contando também `AdminLayoutTest` e `AdminNavTest`, que o item de menu obrigou a atualizar. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. Quem administra vê o passo gravado. A chave não aparece na HTML. O interruptor nasce desligado.

## T8 — Conectar e desconectar — tentativa 1
- Subagente: 01a20f6c-4e1e-74e1-9c75-a3ddfe9af5ba
- Prova: `(cd src && npm run build) && docker compose exec app php artisan test --filter WhatsAppConnectTest`
- Resultado: passou
- Arquivos: `src/app/Livewire/Admin/Tenant/WhatsAppSettings.php`, `src/resources/views/livewire/admin/tenant/whatsapp-settings.blade.php`, `src/resources/js/admin.js`, `src/package.json`, `src/package-lock.json`, `src/tests/Feature/WhatsApp/WhatsAppConnectTest.php`, `src/resources/js/CLAUDE.md`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`, `src/tests/Feature/WhatsApp/WhatsAppSettingsTest.php`

28 testes, 182 asserções. O build do Vite passou. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. Conectar mostra espera, QR ou código, e o número. No meio do caminho não há outro conectar. Desconectar sem sessão não chama a API.

## T9 — Aviso de status — tentativa 1
- Subagente: 01a20f87-5e0e-7621-bc41-6128bd664d81
- Prova: `docker compose exec app php artisan test --filter OrderWhatsAppStatusTest`
- Resultado: passou
- Arquivos: `src/app/Services/WhatsAppNotifier.php`, `src/app/Services/OrderService.php`, `src/tests/Feature/WhatsApp/OrderWhatsAppStatusTest.php`, `src/app/Services/CLAUDE.md`, `src/app/Livewire/Admin/CLAUDE.md`

18 testes, 106 asserções. `InventoryTest` continuou verde. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. O aviso sai depois do commit, com `sender=tenant`. Falha não desfaz o status.

## T10 — Cancelamento pelo pagamento — tentativa 1
- Subagente: 01a20f93-bea3-74e1-b4cc-973701520904
- Prova: `docker compose exec app php artisan test --filter OrderWhatsAppPaymentTest`
- Resultado: passou
- Arquivos: `src/tests/Feature/WhatsApp/OrderWhatsAppPaymentTest.php`, `src/app/Services/CLAUDE.md`

6 testes, 46 asserções. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. O cancelamento pelo webhook e pela cobrança que falha manda o texto de cancelado depois do commit. Rollback não chama o HTTP. Falha de envio deixa o pedido cancelado.

## T11 — Mensagem na ficha — tentativa 1
- Subagente: 01a20fa4-a114-73c1-9dd6-8daa446eadd0
- Prova: `docker compose exec app php artisan test --filter OrderWhatsAppMessageTest`
- Resultado: passou
- Arquivos: `src/app/Livewire/Admin/Orders/Show.php`, `src/resources/views/livewire/admin/orders/show.blade.php`, `src/app/Services/WhatsAppNotifier.php`, `src/tests/Feature/WhatsApp/OrderWhatsAppMessageTest.php`, `src/app/Livewire/Admin/CLAUDE.md`, `src/app/Services/CLAUDE.md`

18 testes, 188 asserções. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. Com a loja conectada e o interruptor desligado, o texto sai `sender=tenant` e `purpose=generic`, a tela confirma e o campo esvazia. O clique seguinte manda outra. Vazio, telefone inválido, desconectado e texto acima de 500 não chamam o HTTP. Sem número, a tela diz que não está conectado e as colunas do pedido ficam. Falha mantém o texto. Lock ocupado não manda a segunda; livre de novo, manda. Pedido de outra loja é 404.

## T12 — Contato, checkout e número publicado — tentativa 1
- Subagente: 01a20fb7-32c0-7501-8f71-7967e2cfcd12
- Prova: `docker compose exec app php artisan test --filter StorePhoneTest`
- Resultado: passou
- Arquivos: `src/app/Support/Phone.php`, `src/app/Support/TenantHelper.php`, `src/app/Livewire/Admin/Tenant/ContactSettings.php`, `src/app/Http/Controllers/TenantPaymentController.php`, `src/app/Livewire/Tenant/Checkout.php`, `src/app/Livewire/Tenant/Account/Profile.php`, `src/app/Livewire/Admin/Customers/Form.php`, `src/resources/views/partials/tenant-footer.blade.php`, `src/resources/views/components/contact-modal.blade.php`, `src/resources/views/livewire/admin/tenant/contact-settings.blade.php`, `src/tests/Feature/WhatsApp/StorePhoneTest.php`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`, `src/app/Http/Controllers/CLAUDE.md`, `src/app/Support/CLAUDE.md`, `src/app/Livewire/Tenant/CLAUDE.md`, `src/app/Livewire/Admin/CLAUDE.md`, `src/resources/views/CLAUDE.md`

27 testes, 241 asserções. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. O regex `^55\d{10,11}$` saiu. WhatsApp de contato `(11) 99999-9999` grava o nacional e o legado `5511999990000` abre `wa.me/55` mais esse nacional. Telefone inválido não grava o contato e não cria pedido no checkout manual, no pagamento online da tela nem no POST. Vazio cria no manual e no POST. Texto antigo que não é telefone continua visível.

## T13 — Máscara ao digitar — tentativa 1
- Subagente: 01a20fd2-e475-7f12-8ce5-5fed442d4917
- Prova: `(cd src && npm run build) && docker compose exec app php artisan test --filter PhoneMaskTest`
- Resultado: passou
- Arquivos: `src/resources/js/phone-mask.js`, `src/resources/js/app.js`, `src/resources/js/admin.js`, `src/resources/views/livewire/tenant/auth/login-popup.blade.php`, `src/resources/views/livewire/tenant/checkout.blade.php`, `src/resources/views/livewire/tenant/account/profile.blade.php`, `src/resources/views/livewire/admin/customers/form.blade.php`, `src/resources/views/livewire/admin/tenant/contact-settings.blade.php`, `src/resources/views/livewire/admin/tenant/whatsapp-settings.blade.php`, `src/tests/Feature/WhatsApp/PhoneMaskTest.php`, `src/tests/Feature/WhatsApp/WhatsAppConnectTest.php`, `src/resources/js/CLAUDE.md`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`

7 testes, 62 asserções. O build gerou `src/public/build/manifest.json`. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. Popup, checkout, perfil, cliente, telefone e WhatsApp do contato, e o telefone de parear têm `data-phone-mask`. Busca, chave Pix, código de 6 dígitos e telefone só de leitura não têm. O `id` `customerPhoneInput` permanece.

## T14 — Crédito do Zaptria — tentativa 1
- Subagente: não lançado
- Prova: `src/public/images/zaptria-logo.svg`
- Resultado: falhou
- Arquivos: nenhum

`src/public/images/` não existe. A tarefa manda parar sem desenhar outro SVG. Fila parada.

## T14 — Crédito do Zaptria — tentativa 2
- Subagente: não lançado
- Prova: `src/public/images/zaptria-logo.svg`
- Resultado: falhou
- Arquivos: nenhum

A pasta `src/public/images/` existe e só tem `zaptria-logo-placeholder.svg`. O arquivo da marca, `zaptria-logo.svg`, continua ausente. A tarefa manda parar sem desenhar outro SVG. Fila parada.

## T14 — Crédito do Zaptria — tentativa 3
- Subagente: 01a2105d-8a7a-78d3-98a9-4fb99281b796
- Prova: `docker compose exec app php artisan test --filter ZaptriaCreditTest`
- Resultado: passou
- Arquivos: `src/resources/views/livewire/admin/tenant/whatsapp-settings.blade.php`, `src/public/images/zaptria.webp`, `src/public/images/zaptria-dark.webp`, `src/tests/Feature/WhatsApp/ZaptriaCreditTest.php`, `src/tests/Feature/WhatsApp/WhatsAppSettingsTest.php`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`

2 testes, 24 asserções. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. A pessoa trocou o SVG por WebP 72×24: `zaptria.webp` no tema claro e `zaptria-dark.webp` no tema escuro. A tela mostra «Fornecido por» e `alt="Zaptria"`. Contato, ficha, vitrine e outra configuração não mostram o crédito.

## T15 — Telas no navegador — tentativa 1
- Subagente: 01a21063-895e-7b41-8ff9-f563dd743473
- Prova: `E2E_SPRINT=.sprint/08-whatsapp npm --prefix src run e2e -- sprint/08-whatsapp.spec.ts`
- Resultado: passou
- Arquivos: `src/e2e/sprint/08-whatsapp.spec.ts`

3 testes no desktop, 16,3 s. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. Capturas em `.sprint/08-whatsapp/capturas/`: `whatsapp-config.png`, `pedido-mensagem.png`, `telefone-mascara.png`. O agrupamento com parênteses da prova original não roda no bash; o comando acima é o mesmo `e2e.sh` com `E2E_SPRINT`.

## T16 — Mapa da regra de telefone — tentativa 1
- Subagente: 01a2108a-9dc3-7d53-9b96-66cb733b4103
- Prova: `rg -n "10 ou 11, DDD sem zero" src/app/Rules/CLAUDE.md`
- Resultado: passou
- Arquivos: `src/app/Rules/CLAUDE.md`

A linha 5 agora diz telefone brasileiro de 10 ou 11, DDD sem zero, e `+` só se for `+55`. `10–15` saiu do arquivo. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. O shell desta máquina não tem o binário `rg`; a mesma busca confirmou a frase.

## T17 — Mapa do bloco WhatsApp — tentativa 1
- Subagente: 01a2108c-13c1-7383-ad45-92b36a41a91b
- Prova: `rg -n "bloco \`whatsapp\`" src/app/Models/CLAUDE.md`
- Resultado: passou
- Arquivos: `src/app/Models/CLAUDE.md`

A seção `Tenant.settings` agora diz que o bloco `whatsapp` é gravado na tela de WhatsApp, em `src/app/Livewire/Admin/Tenant/CLAUDE.md`, junto dos outros blocos. `business_hours`, `address`, `theme`, `contact`, `social`, `seo`, `store` e `settings.scheduling` continuam na mesma seção. O arquivo ficou com 123 linhas. HEAD permaneceu `b88fc404d1f151d43583f6d0b1ab7c28ef0b6038`. O shell desta máquina não tem o binário `rg`; a mesma busca confirmou a frase na linha 95.
