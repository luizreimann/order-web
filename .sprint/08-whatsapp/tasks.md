# Tarefas — WhatsApp da loja
Status: aceita

Ordem: T1, T2, T3, T4, T5, T6, T7, T8, T9, T10, T11, T12, T13, T14, T15.

| Id | Depende de |
| --- | --- |
| T1 | nenhuma |
| T2 | nenhuma |
| T3 | T1, T2 |
| T4 | T3 |
| T5 | T4 |
| T6 | T2 |
| T7 | T2 |
| T8 | T6, T7 |
| T9 | T2, T7 |
| T10 | T9 |
| T11 | T9 |
| T12 | T1 |
| T13 | T5, T8, T12 |
| T14 | T7 |
| T15 | T8, T11, T13, T14 |

Critérios da spec: 1 em T7 e T8. 2 em T8. 3 em T6 e T8. 4 em T6. 5 em T2 e T7. 6 em T3 e T5. 7 em T3 e T4. 8 em T4. 9 em T3 e T5. 10 em T9 e T10. 11 em T9. 12 em T9. 13 em T5, T9 e T12. 14 em T11. 15 em T11. 16 em T11. 17 em T14. 18 em T12, T13 e T15. 19 em T1 e T12. 20 em T12 e T13.

Lacuna: nenhuma. O PHPUnit não digita a máscara; quem prova a tecla é a T15.

## T1 — Telefone brasileiro
- Estado: feita
- Depende de: nenhuma
- Arquivos: `src/app/Support/Phone.php`, `src/tests/Unit/Support/PhoneTest.php`, `src/app/Support/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter PhoneTest`
- Feito quando: fixo de 10 dígitos e celular de 11, com DDD sem zero, passam; `(11) 3333-4444` e `(11) 99999-9999` formatam assim; `+55` na frente continua o mesmo canônico; DDD com zero, texto livre, `+1` e `+351` não passam em `isValid`; o `normalize` desses estrangeiros não muda.

`format` nasce aqui. A regra do 55 dentro de `normalize` não muda. Formulário, máscara e botão da vitrine ficam para a T12 e a T13.

## T2 — Cliente HTTP do Zaptria
- Estado: feita
- Depende de: nenhuma
- Arquivos: `src/app/Services/ZaptriaClient.php`, `src/config/services.php`, `src/.env.example`, `src/tests/Unit/Services/ZaptriaClientTest.php`, `src/app/Services/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter ZaptriaClientTest`
- Feito quando: a assinatura cobre timestamp, método, path, chave de idempotência e corpo cru; `POST /messages` manda `Idempotency-Key`; sem `key`, `secret` ou `base_url` não há HTTP; o timeout é 15 segundos.

A credencial fica em `services.zaptria`, vazia no exemplo, com o comentário de que é da plataforma e não entra na loja. Não criar tela. Não gravar o `CLAUDE.md` da raiz: o orquestrador acrescenta a frase de que a credencial fica no ambiente, o código e o aviso não saem de propriedade pública, e o HTTP fica fora da transação do pedido.

## T3 — Pedir e acertar o código
- Estado: feita
- Depende de: T1, T2
- Arquivos: `src/database/migrations/`, `src/app/Models/CustomerLoginCode.php`, `src/app/Services/CustomerLoginCodeService.php`, `src/tests/Feature/Tenancy/TenantIsolationAuditTest.php`, `src/tests/Feature/Auth/CustomerLoginCodeTest.php`, `src/app/Models/CLAUDE.md`, `src/app/Services/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter 'CustomerLoginCodeTest|TenantIsolationAuditTest'`
- Feito quando: o envio usa `sender=auto` e `purpose=otp`, o texto é `{nome da loja}: seu código é {seis dígitos}`, e pedir não cria cliente nem sessão; o código certo, em até 10 minutos, entra na sessão de hoje e só então cria o cliente; código errado não entra; um pedido novo aposenta o anterior; repetir o mesmo envio não troca o código.

Migration nova. O hash é HMAC com a `APP_KEY`. O código em claro não vai para o banco. O model entra na lista fechada do audit. A asserção não afrouxa. O popup fica na T5. Falha de rede, vencido e e-mail ficam na T4.

## T4 — Sem remetente e falha temporária
- Estado: feita
- Depende de: T3
- Arquivos: `src/app/Services/CustomerLoginCodeService.php`, `src/tests/Feature/Auth/CustomerLoginCodeFallbackTest.php`, `src/app/Services/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter CustomerLoginCodeFallbackTest`
- Feito quando: 429, 502, 500 esgotado e `instance_busy` deixam a pessoa deslogada e não mandam e-mail; 401, 403, sem credencial e 409 `instance_not_connected` entram só com o telefone; o e-mail sai só com `owner_email`, cita a loja, não traz o código, e sem endereço a entrada ainda acontece; código vencido, já usado, de outra loja ou após cinco erros não entra.

Uma falha 500 ou de timeout repete uma vez, com a mesma chave e o mesmo código, antes de desistir. Cliente desativado segue o `login` de hoje.

## T5 — Popup em dois passos
- Estado: feita
- Depende de: T4
- Arquivos: `src/app/Livewire/Tenant/Auth/LoginPopup.php`, `src/resources/views/livewire/tenant/auth/login-popup.blade.php`, `src/tests/Feature/Auth/CustomerLoginPopupTest.php`, `src/app/Livewire/Tenant/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter 'CustomerLoginPopupTest|TenantAdminGuardTest|CustomerGuardTest'`
- Feito quando: a HTML do popup não contém o código; fechar no primeiro passo não chama o HTTP e não entra; acertar entra na sessão de hoje; o login do admin continua sem pedir código.

O código digitado é argumento do método, não propriedade pública. Não editar `TenantAdminAuthController`. A máscara desse campo entra na T13.

## T6 — Webhook
- Estado: feita
- Depende de: T2
- Arquivos: `src/app/Http/Controllers/ZaptriaWebhookController.php`, `src/routes/web.php`, `src/tests/Feature/WhatsApp/ZaptriaWebhookTest.php`, `src/tests/Feature/Tenancy/RouteMiddlewareAuditTest.php`, `src/app/Http/Controllers/CLAUDE.md`, `src/routes/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter 'ZaptriaWebhookTest|RouteMiddlewareAuditTest'`
- Feito quando: assinatura inválida responde 401 e não muda o passo; a mesma entrega, repetida, não desfaz uma troca feita no meio; `status_changed` atualiza o passo e o número e preserva interruptor e textos; `message.received` não cria cliente nem código.

A rota é `POST /webhooks/zaptria`, antes do grupo da loja, na allowlist do audit com o motivo. A asserção não afrouxa. Não tratar `lid` como telefone. Não criar tela.

## T7 — Tela e textos
- Estado: feita
- Depende de: T2
- Arquivos: `src/app/Livewire/Admin/Tenant/WhatsAppSettings.php`, `src/resources/views/livewire/admin/tenant/whatsapp-settings.blade.php`, `src/routes/web.php`, `src/app/Support/AdminNavigation.php`, `src/tests/Unit/Support/AdminNavigationTest.php`, `src/tests/Feature/WhatsApp/WhatsAppSettingsTest.php`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter 'WhatsAppSettingsTest|AdminNavigationTest|LivewireAdminAuthorizationAuditTest|RouteMiddlewareAuditTest'`
- Feito quando: quem administra a configuração vê o passo gravado; quem não administra, e o `platform_admin`, não abre e um POST não grava; a chave não aparece na HTML nem num campo; o interruptor nasce desligado; os três textos nascem preenchidos e sobrevive o bloco `contact`; ligar o interruptor não apaga texto; texto acima de 500 não grava.

Rota `tenant.admin.whatsapp` dentro de `tenant.auth`. Item «WhatsApp» ao lado de Contato, `can` null. `AuthorizesOnHydrate` e `manageSettings`. `data_set` só do bloco `whatsapp`. Nesta tarefa a tela lê o JSON, sem consultar o serviço ao abrir. Conectar fica na T8. O crédito fica na T14.

## T8 — Conectar e desconectar
- Estado: feita
- Depende de: T6, T7
- Arquivos: `src/app/Livewire/Admin/Tenant/WhatsAppSettings.php`, `src/resources/views/livewire/admin/tenant/whatsapp-settings.blade.php`, `src/resources/js/admin.js`, `src/package.json`, `src/package-lock.json`, `src/tests/Feature/WhatsApp/WhatsAppConnectTest.php`, `src/resources/js/CLAUDE.md`
- Prova: `(cd src && npm run build) && docker compose exec app php artisan test --filter WhatsAppConnectTest`
- Feito quando: conectar mostra a espera, o QR ou o código para digitar no WhatsApp, e então o número; no meio do caminho não há outro conectar; desconectar volta a sem número; sem sessão, desconectar não chama a API; um QR novo substitui o anterior; a tela montada de novo, sem poll, mostra o passo que o webhook gravou.

Ler a API da versão de `qrcode` pinada no lock antes de desenhar. O poll de 3 segundos só existe em subindo, pronto para ler e desconectando. O campo de telefone do pairing ainda não tem máscara.

## T9 — Aviso de status
- Estado: feita
- Depende de: T2, T7
- Arquivos: `src/app/Services/WhatsAppNotifier.php`, `src/app/Services/OrderService.php`, `src/tests/Feature/WhatsApp/OrderWhatsAppStatusTest.php`, `src/app/Services/CLAUDE.md`, `src/app/Livewire/Admin/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter OrderWhatsAppStatusTest`
- Feito quando: desligado, texto vazio, pedido que nasce pendente, volta para pendente, mesmo status, telefone inválido ou número da loja desconectado não chamam o HTTP, e o status muda assim mesmo; ligado e conectado, confirmado, concluído e cancelado mandam uma vez `sender=tenant` com o texto da loja; a mensagem não usa `sender=auto`; 409 grava o passo como caiu e não chama de novo; exceção do HTTP deixa o status novo no banco.

O HTTP entra em `DB::afterCommit` dentro de `updateOrder`. O cancelamento pelo pagamento é a T10.

## T10 — Cancelamento pelo pagamento
- Estado: feita
- Depende de: T9
- Arquivos: `src/app/Services/OrderService.php`, `src/tests/Feature/WhatsApp/OrderWhatsAppPaymentTest.php`, `src/app/Services/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter OrderWhatsAppPaymentTest`
- Feito quando: o cancelamento pelo webhook de pagamento e o da cobrança que falha mandam o texto de cancelado uma vez, depois do commit; se a transação de fora desfaz, não há HTTP; o status cancelado permanece quando o envio falha.

O gancho é o da T9. Se o HTTP correr dentro da transação de fora, ou não correr, ajustar o `afterCommit` para esperar o commit de fora. Não criar um segundo aviso.

## T11 — Mensagem na ficha
- Estado: feita
- Depende de: T9
- Arquivos: `src/app/Livewire/Admin/Orders/Show.php`, `src/resources/views/livewire/admin/orders/show.blade.php`, `src/app/Services/WhatsAppNotifier.php`, `src/tests/Feature/WhatsApp/OrderWhatsAppMessageTest.php`, `src/app/Livewire/Admin/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter OrderWhatsAppMessageTest`
- Feito quando: a ficha mostra «Enviar mensagem via WhatsApp»; com número conectado e telefone válido, o texto sai `sender=tenant` uma vez, a tela confirma e o campo esvazia; confirmar de novo manda outra; vazio, sem telefone ou desconectado não envia; sem número, a tela diz isso e as colunas do pedido ficam; falha mantém o texto; o envio não exige o interruptor, não usa a plataforma e não lista o que já foi enviado; pedido de outra loja é 404; dois cliques ao mesmo tempo geram um HTTP só.

## T12 — Contato, checkout e número publicado
- Estado: feita
- Depende de: T1
- Arquivos: `src/app/Livewire/Admin/Tenant/ContactSettings.php`, `src/app/Http/Controllers/TenantPaymentController.php`, `src/app/Livewire/Tenant/Checkout.php`, `src/app/Livewire/Tenant/Account/Profile.php`, `src/app/Livewire/Admin/Customers/Form.php`, `src/app/Support/TenantHelper.php`, `src/resources/views/partials/tenant-footer.blade.php`, `src/resources/views/components/contact-modal.blade.php`, `src/tests/Feature/WhatsApp/StorePhoneTest.php`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`, `src/app/Http/Controllers/CLAUDE.md`, `src/app/Support/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter StorePhoneTest`
- Feito quando: campo opcional vazio grava vazio; DDD com zero, texto livre, outro país ou valor cru inválido não grava o formulário e a mensagem pede um telefone válido com DDD; o perfil continua obrigatório; o WhatsApp de contato aceita `(11) 99999-9999`, grava o canônico e o botão abre `wa.me/55` mais esse nacional; o legado `5511999990000` abre o mesmo link; o telefone publicado válido aparece com DDD; texto antigo que não é telefone continua visível; checkout manual, online na tela e o POST do pagamento com telefone inválido não criam pedido, e vazio cria.

O regex `^55\d{10,11}$` sai. Reabrir contato, perfil, cliente e checkout mostra `format`. A máscara enquanto digita é a T13.

## T13 — Máscara ao digitar
- Estado: feita
- Depende de: T5, T8, T12
- Arquivos: `src/resources/js/phone-mask.js`, `src/resources/js/app.js`, `src/resources/js/admin.js`, `src/resources/views/livewire/tenant/auth/login-popup.blade.php`, `src/resources/views/livewire/tenant/checkout.blade.php`, `src/resources/views/livewire/tenant/account/profile.blade.php`, `src/resources/views/livewire/admin/customers/form.blade.php`, `src/resources/views/livewire/admin/tenant/contact-settings.blade.php`, `src/resources/views/livewire/admin/tenant/whatsapp-settings.blade.php`, `src/tests/Feature/WhatsApp/PhoneMaskTest.php`, `src/resources/js/CLAUDE.md`
- Prova: `(cd src && npm run build) && docker compose exec app php artisan test --filter PhoneMaskTest`
- Feito quando: popup, checkout, perfil, cadastro de cliente, telefone e WhatsApp do contato, e o telefone de conectar por código têm `data-phone-mask`; busca, chave Pix, código de 6 dígitos e telefone só de leitura não têm; o build gera o manifest.

O script só formata. Não decide DDD nem login. O `id` `customerPhoneInput` permanece. A tecla que tira a letra e monta `(11) 99999-9999` é a T15.

## T14 — Crédito do Zaptria
- Estado: feita
- Depende de: T7
- Arquivos: `src/resources/views/livewire/admin/tenant/whatsapp-settings.blade.php`, `src/public/images/zaptria.webp`, `src/public/images/zaptria-dark.webp`, `src/tests/Feature/WhatsApp/ZaptriaCreditTest.php`, `src/app/Livewire/Admin/Tenant/CLAUDE.md`
- Prova: `docker compose exec app php artisan test --filter ZaptriaCreditTest`
- Feito quando: a configuração do WhatsApp mostra «Fornecido por» e a imagem com `alt="Zaptria"`; contato, ficha do pedido, vitrine e as outras configurações não mostram esse crédito nem essa imagem.

As marcas oficiais já estão em `src/public/images/zaptria.webp` (fundo claro) e `src/public/images/zaptria-dark.webp` (fundo escuro), 72×24. Não desenhar outra e não trocar por SVG. O tema do painel é `data-bs-theme`.

## T15 — Telas no navegador
- Estado: feita
- Depende de: T8, T11, T13, T14
- Arquivos: `src/e2e/sprint/08-whatsapp.spec.ts`
- Prova: `E2E_SPRINT=.sprint/08-whatsapp npm --prefix src run e2e -- sprint/08-whatsapp.spec.ts`
- Feito quando: com a rede de fora bloqueada, a configuração abre em sem número e mostra o crédito; a ficha do pedido mostra o campo de mensagem; digitar no WhatsApp do checkout formata o celular e não deixa letra; as capturas `whatsapp-config.png`, `pedido-mensagem.png` e `telefone-mascara.png` existem em `.sprint/08-whatsapp/capturas/`.

Copiar login, seed e bloqueio de rede do spec vizinho. A loja é a do `E2eSeeder`. Viewport desktop 1280x800. Sem `toHaveScreenshot` e sem `e2e:update`. QR e código de login não entram aqui.

## T16 — Mapa da regra de telefone
- Estado: feita
- Depende de: nenhuma
- Arquivos: `src/app/Rules/CLAUDE.md`
- Prova: `rg -n "10 ou 11, DDD sem zero" src/app/Rules/CLAUDE.md`
- Feito quando: a linha de `PhoneNumber` deixa de dizer 10–15 dígitos e diz telefone brasileiro de 10 ou 11, DDD sem zero, e `+` só se for `+55`.

Achado da rodada 1. O código já valida assim. Só o mapa da pasta está velho.

## T17 — Mapa do bloco WhatsApp
- Estado: feita
- Depende de: nenhuma
- Arquivos: `src/app/Models/CLAUDE.md`
- Prova: `rg -n "bloco \`whatsapp\`" src/app/Models/CLAUDE.md`
- Feito quando: a seção `Tenant.settings` cita o bloco `whatsapp`, gravado na tela de WhatsApp, junto dos outros blocos.

Achado da rodada 1. O bloco já existe. O mapa dos models não o cita.
