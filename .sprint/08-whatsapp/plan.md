# Plano — WhatsApp da loja
Status: aceita
Spec: spec.md

## Onde

A regra de enviar, de conferir o código e de decidir se o pedido avisa mora em serviço. A tela não escolhe o remetente e não guarda segredo.

- `src/app/Services/ZaptriaClient.php` — único HTTP ao Zaptria. O `CLAUDE.md` de Services manda a tela chamar serviço, e o HTTP de pagamento fica fora da transação: o deste client também. Timeout 15 e `connectTimeout` 10, no mesmo cliente `Http` do Mercado Pago. Não entra em `Gateways`: aquela pasta é só provedor de pagamento atrás de `PaymentGatewayInterface`.
- `src/app/Services/CustomerLoginCodeService.php` — pede o código, confere e só então chama `CustomerAuthService::login`. O `CLAUDE.md` de Services e o de Auth continuam com um emissor de sessão. Este serviço não grava cookie.
- `src/app/Services/WhatsAppNotifier.php` — aviso de status e mensagem escrita no pedido. Os dois saem com `sender=tenant`. Falha não desfaz pedido.
- `src/app/Services/OrderService.php` — `updateOrder` segue a única escrita de status. O aviso é `DB::afterCommit` dentro dessa transação, para o HTTP não segurar o lock. `compensate` e o webhook de pagamento já estão numa transação de fora: o callback espera o commit de fora e não corre se ela desfaz.
- `src/app/Services/CustomerAuthService.php` — não muda o cookie nem o prazo. O popup deixa de chamar `login` no primeiro passo.
- `src/app/Livewire/Tenant/Auth/LoginPopup.php` e a view — dois passos. O código digitado é argumento do método de confirmar, no padrão write-only de `PaymentSettings`: não é propriedade pública. O `CLAUDE.md` de Tenant Livewire ganha o segundo passo e a frase de que fechar continua sem sessão.
- `src/app/Livewire/Admin/Tenant/WhatsAppSettings.php` — bloco novo, no padrão de `ContactSettings`: `manageSettings`, `AuthorizesOnHydrate`, `data_set` do bloco inteiro. Irmãos (`contact`, `payment`, `scheduling`, o resto) sobrevivem.
- `src/app/Livewire/Admin/Orders/Show.php` e a view — o campo «Enviar mensagem via WhatsApp» chama o notifier. `updateStatus` continua em `updateOrder`. Não grava status, pagamento, observação nem cliente no envio manual. O `CLAUDE.md` de Admin descreve a ficha; ganha essa frase.
- `src/app/Http/Controllers/ZaptriaWebhookController.php` — `POST /webhooks/zaptria`, antes do grupo `{tenant:slug}`, na exceção CSRF `webhooks/*` que já existe. O `CLAUDE.md` de Controllers ganha o verbo: assinatura no corpo cru, 401 sem efeito, entrega repetida não reaplica.
- `src/app/Http/Controllers/TenantPaymentController.php` — telefone não vazio passa por `PhoneNumber` antes de `begin`. Inválido não cria pedido. Vazio segue como hoje. O `CLAUDE.md` de Controllers deixa de dizer que o telefone segue cru.
- `src/routes/web.php` — a rota do webhook, sem `tenant`, entra na allowlist de `RouteMiddlewareAuditTest` com o motivo (assinatura, sem sessão). A asserção não afrouxa. `GET /{tenant}/admin/whatsapp`, nome `tenant.admin.whatsapp`, dentro de `tenant.auth`. O `CLAUDE.md` de routes: tela é componente, nome `tenant.admin.*`.
- `src/app/Support/AdminNavigation.php` — item «WhatsApp» ao lado de Contato, `can` null. `AdminNavigationTest` ganha ordem, rótulo, rota e ícone. A asserção não afrouxa. A tela autoriza `store.settings.manage`.
- `src/app/Support/Phone.php` — `isValid` passa a ser telefone brasileiro com DDD, fixo ou celular. `normalize` não muda a regra do 55. Nasce `format` para a máscara. O `CLAUDE.md` de Support troca o «10–15» por essa frase. `TenantHelper::getWhatsAppUrl` prefixa 55 no nacional e continua aceitando o valor antigo que já começa com 55.
- `src/app/Livewire/Admin/Tenant/ContactSettings.php` — telefone e WhatsApp usam `PhoneNumber`. O regex `^55\d{10,11}$` sai. O save grava o canônico. O bloco `contact` não ganha chave de sessão.
- `src/app/Livewire/Tenant/Checkout.php`, `Account/Profile.php`, `Admin/Customers/Form.php` — a regra de obrigatório ou vazio não muda. O valor inválido continua barrando o `validate()` inteiro, então o resto do formulário não grava.
- `src/resources/js/phone-mask.js`, importado por `app.js` e `admin.js`. O `CLAUDE.md` de JS: o script só formata o input `data-phone-mask`. Não decide login, pedido nem DDD. O POST do checkout continua no Blade. O `id` `customerPhoneInput` permanece.
- `src/resources/js/admin.js` — desenha o QR a partir da string, com a lib pinada no lock. Antes de chamar a API dela, ler a versão instalada. Sem lib de máscara: a máscara é o script acima.
- `src/resources/views/livewire/admin/tenant/whatsapp-settings.blade.php` — único lugar do crédito «Fornecido por» e do `<img alt="Zaptria">`. A imagem é a marca oficial, em `src/public/images/zaptria-logo.svg`. Não há arquivo de marca no repositório vizinho: a tarefa não desenha outra. Se a marca não estiver no repo na hora, a tarefa para e pede o arquivo.
- `src/resources/views/partials/tenant-footer.blade.php` e `components/contact-modal.blade.php` — telefone publicado passa por `format` quando é válido. Texto antigo que não é telefone continua visível até a loja gravar de novo.
- `src/config/services.php` e `src/.env.example` — `zaptria.base_url`, `key`, `secret`, `owner_email`, vazios no exemplo. Comentário: credencial da plataforma, uma por ambiente, não é bloco de loja e não vai para o navegador.
- `CLAUDE.md` da raiz — uma frase, na tarefa que introduz o client: credencial do Zaptria fica no ambiente; código de login e aviso de pedido não saem de propriedade pública; HTTP fora da transação do pedido. Quem grava o da raiz é o orquestrador.

Login do admin, `TenantAdminAuthController`, busca, chave Pix e rota de sair do cliente não são editados.

## Dados

Bloco `tenants.settings.whatsapp`. Ausente: sem número, aviso desligado, textos padrão ainda não gravados. Chave irmã fora do bloco não entra nele.

```text
status: inactive | starting | qr_ready | connected | disconnected | error | disconnecting
        inicial inactive
number: dígitos nacionais ou null
order_updates: bool, inicial false
templates.confirmed, templates.done, templates.cancelled: string 0–500
```

Textos iniciais, usados na primeira abertura e quando a chave falta:

```text
confirmed: {loja}: seu pedido #{pedido} foi confirmado.
done:       {loja}: seu pedido #{pedido} foi concluído.
cancelled:  {loja}: seu pedido #{pedido} foi cancelado.
```

`{cliente}` vazio sai vazio. `{pedido}` é `orders.number`. `{loja}` é o nome da loja. Limpar o texto e salvar grava vazio, e aquele status não envia. Ligar o interruptor não apaga texto.

QR e pairing code não entram no JSON. Vivem na resposta da consulta enquanto a tela espera. A chave e o segredo não entram no JSON nem em propriedade pública.

`contact.phone` e `contact.whatsapp` passam a gravar o canônico nacional (10 ou 11 dígitos). O valor antigo `55` + número continua válido na leitura: `normalize` tira o 55 e o botão monta `https://wa.me/55` + nacional. Não há migration de JSON.

Tabela `customer_login_codes`. Model com `TenantScoped` e `BelongsToTenant`, UUID, na lista de `TenantIsolationAuditTest`. A asserção não afrouxa.

```text
tenant_id, phone canônico, code_hash, expires_at, attempts, consumed_at, idempotency_key
```

`code_hash` é HMAC-SHA256 com a `APP_KEY`. O código em claro não vai para o banco, log, e-mail nem snapshot. Expira em 10 minutos. `attempts` chega a 5 e consome. Outro pedido para o mesmo telefone na mesma loja consome o anterior. Índice `(tenant_id, phone)`.

Não há tabela de histórico de mensagem. A deduplicação do webhook é `Cache::add` da `X-Zaptria-Delivery` por um dia. A deduplicação de um clique duplo na mensagem manual é `Cache::lock` do pedido só enquanto o HTTP daquele clique corre. O clique seguinte, depois do lock, envia outra.

Invariantes da raiz que a mudança atravessa:

- Isolamento. Código e tela de pedido leem a loja do request. O webhook acha `Tenant` pelo `external_id` (o `tenants.id`) e só substitui o bloco `whatsapp` dessa loja. `Tenant` continua fora da trait.
- Segredo fora de propriedade pública: chave, segredo e o código gerado. O que a pessoa digita no campo de código é entrada, conferida no serviço.
- `save()` de configuração substitui o bloco inteiro.
- HTTP fora da transação do pedido. O aviso usa `afterCommit`. O código de login não cria pedido.
- Os três status não se misturam. O aviso olha só `orders.status` e só a passagem para `confirmed`, `done` ou `cancelled`.
- O servidor revalida o telefone. A máscara é entrada.
- PostgreSQL. Sem SQLite: a unique do código e o webhook cabem no banco da suíte.
- `TenantCounter` não entra. O número do pedido continua o de hoje.

## Fluxo

`ZaptriaClient` assina `timestamp`, método, path com query, `Idempotency-Key` (vazia se não houver) e o corpo cru, separados por `\n`. `POST /messages` sempre manda a chave. Sem `key`, `secret` ou `base_url`, o client nem abre HTTP.

A tela WhatsApp, ao abrir, consulta `GET /whatsapp` se houver credencial e grava `status` e `number`. Sem credencial, fica em sem número. `wire:poll.3s` só em `starting`, `qr_ready` e `disconnecting`. Fora desses, não consulta em loop.

Conectar só a partir de sem número, caiu ou falhou: `POST /whatsapp/connect` com o nome da loja. No meio do caminho o botão não existe, então não há segunda sessão. Desconectar só quando conectado: `POST /whatsapp/logout`. Nos outros passos, o método não chama o logout. Pronto para ler mostra o QR desenhado no browser e o campo de telefone do pairing; o código devolvido aparece na tela. O QR novo substitui o anterior a cada consulta.

O webhook confere o relógio (±300 s) e o HMAC do corpo cru. Assinatura ruim responde 401 e não lê loja. Entrega já vista responde 204 e não grava de novo: a prova troca o status no banco entre as duas chamadas e a segunda não desfaz a troca. `instance.status_changed` atualiza `status` e `number` e preserva interruptor e textos. Loja desconhecida: 204, sem criar tenant. `message.received` e o resto: 204, sem ler o texto e sem tratar `lid` como telefone.

Login, no primeiro passo, valida o telefone e chama `CustomerLoginCodeService`. Isso não cria `Customer` nem cookie. O texto enviado é `{nome da loja}: seu código é {seis dígitos}`, `sender=auto`, `purpose=otp`. Uma falha transitória (500, timeout) repete uma vez com a mesma chave e o mesmo código. 429, 502, 500 esgotado, `instance_busy` e falha de rede: a pessoa continua deslogada, com a mensagem para esperar ou pedir de novo. 401, 403, sem credencial, ou 409 `instance_not_connected`: entra por `CustomerAuthService::login` e, se `owner_email` não for vazio, sai um e-mail com o nome da loja e o motivo. O e-mail não leva o código. Sem endereço, entra mesmo assim e não envia e-mail.

Pedir outro código consome o anterior e gera outro. Acertar dentro de 10 minutos, com menos de cinco erros, consome e chama `login`. Errado, vencido, já usado ou de outra loja não chama. Fechar no primeiro passo não envia e não entra. Cliente desativado segue o `login` de hoje: o guard é quem recusa.

`updateOrder` compara o status anterior. Só passa ao notifier se mudou para confirmado, concluído ou cancelado. O notifier, depois do commit, exige interruptor ligado, `status=connected`, telefone do pedido válido e texto daquele status com 1 a 500 caracteres já substituídos. Aí `sender=tenant`, `purpose=order_update`, uma tentativa. 409 `instance_not_connected` grava o bloco como caiu e não tenta a plataforma. Qualquer outra falha não tenta de novo e não lança para o pedido. Pedido que nasce pendente não passa por aqui. Voltar para pendente ou gravar o mesmo status também não.

A mensagem manual exige `orders.manage` no pedido da loja. Vazio, sem telefone válido ou sem `connected`: não chama o HTTP. Sem número, a tela diz que o WhatsApp da loja não está conectado e as colunas do pedido ficam. Falha: o texto permanece e a tela diz que não saiu. Sucesso: flash e o campo esvazia. Lock ocupado pelo mesmo clique: não envia a segunda. `purpose=generic`. Não usa o interruptor nem o número da plataforma.

A máscara, no input, deixa no máximo 11 dígitos nacionais, tira letra, tira 55 colado quando o total tem 12 ou 13, e formata `(11) 3333-4444` ou `(11) 99999-9999`. O servidor aceita essa grafia e recusa o resto com «Informe um telefone válido com DDD (ex.: (11) 99999-9999).» `+` que não é `+55`, DDD com zero, outro país e texto livre não passam em `isValid`. O canônico de duas grafias do mesmo número continua igual, porque `normalize` não mudou.

## Prova

`Http::fake` em todo teste do client. Nenhum teste abre rede.

- Tela e permissão: Livewire com quem tem `store.settings.manage` vê o passo; quem não tem, e `platform_admin`, não abre e um POST não grava. Prova o primeiro aceite.
- Conectar, consultar e desconectar contra o fake: espera, QR na HTML, pairing, número conectado, segunda chamada no meio não dispara outro `connect`, logout só quando conectado, logout sem sessão não chama a API. Poll com QR novo substitui o anterior.
- Webhook: 401 não muda JSON; replay não desfaz uma troca feita no meio; `status_changed` muda o passo e preserva os textos; `message.received` não cria cliente nem código. Montar a tela de novo, sem poll, mostra o passo que o webhook gravou.
- HTML da configuração contém «Fornecido por» e `alt="Zaptria"`, e o arquivo da marca existe. Contato, pedido, vitrine e as outras configurações renderizadas não contêm esse crédito nem essa imagem.
- Login: o fake recebe `sender=auto` e `purpose=otp`, o texto cita a loja, e a HTML do popup não contém o código. O teste lê o código no corpo gravado pelo fake. Certo entra e cria o cliente só aí. Errado, vencido (`travel` 11 minutos), quinto erro, já usado e o código da outra loja não criam cookie. Pedido novo aposenta o velho. 429 e 502 não entram e não mandam e-mail. 409 `instance_not_connected` e client sem credencial entram, e o e-mail sai só quando `owner_email` está posto, sem o código. `dismiss` no primeiro passo não chama HTTP.
- Status: interruptor desligado, texto vazio, pendente no `createFromCart`, volta a pendente, mesmo status e telefone inválido não chamam HTTP. Confirmado, concluído e cancelado chamam uma vez, `sender=tenant`, com o texto da loja. Cancelar por `PaymentWebhookProcessor` e por `compensate` usa o texto de cancelado e o HTTP aparece depois do commit (fake não é chamado se a transação desfaz). 409 no aviso grava caiu e não chama de novo. Exceção do HTTP deixa o status novo no banco.
- Manual: rótulo na ficha; um envio e o campo limpo; segundo envio depois do sucesso chama de novo; vazio, sem telefone e desconectado não chamam; falha mantém o texto; colunas do pedido iguais; pedido de outra loja é 404; lock impede o segundo HTTP simultâneo. O corpo não usa `sender=auto`.
- Telefone: `PhoneTest` troca o caso do `+1` e do `+351` para inválidos em `isValid` e mantém o `normalize` deles. DDD com zero e texto livre falham. Contato recusa `123` e `recepção` e não grava o bloco. WhatsApp de contato salvo como `(11) 99999-9999` vira canônico e `getWhatsAppUrl` devolve `wa.me/55` + nacional; o valor legado `5511999990000` do seed continua abrindo o mesmo link. Footer mostra a máscara. Checkout manual, `payOnline` e `TenantPaymentController` com telefone inválido não criam pedido; vazio cria, como hoje. Perfil continua obrigatório. Busca, chave Pix e o input do código de 6 dígitos não têm `data-phone-mask`.
- Login do admin: a suíte já existente dessa tela continua, sem método novo de código.

E2E da sprint, por último, com a rede de fora bloqueada. Sem credencial a tela abre em sem número: o spec afirma o crédito, o campo na ficha do pedido e a máscara ao digitar no checkout. O QR e o código de login ficam no PHPUnit, porque o E2E não completa um pareamento real.

```bash
E2E_SPRINT=.sprint/08-whatsapp (cd src && npm run e2e -- sprint/08-whatsapp.spec.ts)
```

Capturas em `.sprint/08-whatsapp/capturas/`: `whatsapp-config.png`, `pedido-mensagem.png`, `telefone-mascara.png`.

Lacuna: nenhuma. Cada aceite da spec está numa prova acima.

## Fora deste plano

- Ler ou responder o que o cliente manda no WhatsApp. O webhook só reconhece o evento.
- Editar o texto do código de login.
- Aviso de status pelo número da plataforma, pedido novo pendente, ou volta para pendente.
- Campanha, mídia, carrinho abandonado e mensagem que não seja o código, o status ou o texto da ficha.
- Lista ou histórico do que já foi enviado.
- Cobrança, plano ou assinatura no Zaptria.
- Configurar o número da plataforma nesta tela.
- Copiar o número conectado para o contato, ou mudar o destino do botão além de continuar aceitando o nacional e o legado com 55.
- Máscara na busca, na chave Pix, no código de 6 dígitos ou num telefone só de leitura (ficha, lista, conta).
- Login do admin.
- Rota nova de sair da conta do cliente.
