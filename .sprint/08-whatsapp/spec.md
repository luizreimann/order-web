# Spec — WhatsApp da loja
Status: aceita

## Por quê

O cliente entra na loja só com o telefone, sem prova de que o número é dele. A loja também não avisa, no WhatsApp dela, quando o pedido muda de situação.

Com esta sprint, o cliente prova o telefone com um código recebido no WhatsApp. A loja conecta o próprio número e, se quiser, manda um texto seu quando o pedido é confirmado, concluído ou cancelado. Na tela do pedido, a loja também escreve uma mensagem e envia para o cliente daquele pedido. Em todo campo em que alguém digita um telefone, a digitação ganha máscara e o valor inválido não grava.

## Comportamento

O botão de WhatsApp da vitrine continua abrindo a conversa do mesmo número. O número que passa a enviar mensagem é o aparelho que a loja conecta na configuração.

O login do admin da loja não muda.

### Conectar o WhatsApp da loja

Nas configurações da loja, quem já administra a configuração vê a tela WhatsApp. A chave de acesso ao serviço não aparece nessa tela e não é digitada pela loja. Ela é da plataforma, uma por ambiente.

A loja tem um número conectado por vez. A tela mostra em qual passo está:

- Sem número: a loja pode conectar.
- Subindo: a loja espera.
- Pronto para ler: a tela mostra o QR e oferece conectar por código. No código, a loja informa o telefone e vê um código para digitar no WhatsApp, em aparelhos conectados. O QR renova sozinho enquanto a tela espera.
- Conectado: a tela mostra o número e permite desconectar.
- Caiu: a loja pode reconectar.
- Falhou: a loja pode tentar de novo.
- Desconectando: a loja espera, sem outro conectar no meio.

Conectar de novo enquanto sobe, espera leitura ou já está conectado não abre uma segunda sessão. Desconectar com nada conectado não muda o estado. Se ninguém conclui a leitura, a tela volta a sem número e a loja pode conectar outra vez. Tirar o aparelho no celular, ou a sessão cair, atualiza a tela mesmo que ninguém esteja olhando. Um aviso que não seja do serviço é ignorado. O mesmo aviso, repetido, não muda o estado duas vezes.

Desconectar para na hora o aviso de pedido e a mensagem escrita na tela do pedido. O login por código, nesse caso, passa a sair pelo WhatsApp da plataforma.

Nessa tela, e só nela, aparece o crédito «Fornecido por» seguido do logo do Zaptria. O logo é a imagem da marca. Quem não vê a imagem ainda lê o nome Zaptria. A vitrine, a tela do pedido e as outras configurações não mostram esse crédito.

### Login por código

O cliente continua entrando pelo mesmo popup, com o mesmo telefone de hoje, e ainda pode fechar sem entrar. Fechar não entra na conta e não valida código.

Ao pedir para entrar, a plataforma tenta enviar um código de 6 dígitos:

1. Se o WhatsApp da loja está conectado, o código sai desse número.
2. Se não está, o código sai do WhatsApp da plataforma.
3. Se o da loja falha no envio, a tentativa seguinte é o da plataforma.
4. Se nenhum dos dois pode enviar, ou a plataforma está sem acesso ao serviço, o cliente entra só com o telefone, como hoje, e a plataforma avisa o responsável.

O texto do código cita o nome da loja e não é editável pela loja. O código não aparece na tela. A loja não escolhe desligar esse login: o atalho sem código só existe no passo 4.

Limite de envio, espera do serviço ou falha em que a mensagem pode ter saído não usam o passo 4. O cliente vê que precisa esperar ou pedir de novo, e continua deslogado. Pedir de novo gera outro código e aposenta o anterior. Repetir o mesmo envio não troca o código nem manda dois códigos diferentes.

O código vale 10 minutos, uma vez, só naquela loja. Cinco erros seguidos aposentam o código. Código errado, vencido, já usado ou de outra loja não entra. Acertar entra na mesma sessão de hoje. Pedir o código não cria a sessão e não cria o cliente: isso só acontece ao acertar, ou no passo 4. Cliente desativado segue a regra de hoje.

O aviso ao responsável é um e-mail, no endereço configurado para a plataforma, cada vez que um cliente entra pelo passo 4. O e-mail diz a loja e que a entrada foi sem código porque nenhum WhatsApp pôde enviar. Não traz o código. Sem endereço configurado, o cliente ainda entra pelo passo 4.

### Aviso de status do pedido

A mesma tela tem um interruptor, desligado por padrão, e três textos: confirmado, concluído e cancelado. Os três já vêm preenchidos, citando a loja e o número do pedido. A loja pode editar cada um. O texto pode incluir o nome do cliente, o número do pedido e o nome da loja. Nome de cliente vazio sai em branco. Cada texto aceita de 1 a 500 caracteres para ser enviado; vazio não envia aquele status. Ligar o interruptor não apaga o texto.

Nada sai pelo WhatsApp da plataforma. Só sai com o interruptor ligado e o número da loja conectado, para o telefone gravado no pedido. Sem telefone válido, não envia.

Entra na mensagem uma mudança para confirmado, concluído ou cancelado. O pedido que nasce pendente não avisa. Voltar para pendente não avisa. Gravar o mesmo status de novo não avisa. Cancelar pelo pagamento usa o texto de cancelado. Sair de cancelado para confirmado ou concluído avisa o destino.

A mudança de status conclui mesmo se a mensagem não sair. Falha no envio não manda uma segunda mensagem sozinha e não desfaz o status. Uma mesma mudança envia uma vez. Mudar de novo, para outro status que avisa, envia outra.

Desconectado, com o interruptor ligado, nada sai e o status muda assim mesmo. Uma recusa porque o número não está conectado faz a tela voltar a mostrar que não está conectado.

### Mensagem na tela do pedido

Na tela de um pedido, quem já abre essa tela vê o campo «Enviar mensagem via WhatsApp». O texto vai para o telefone gravado naquele pedido e sai só do número conectado da loja. Não usa o número da plataforma e não depende do interruptor de status.

O campo aceita de 1 a 500 caracteres. Vazio não envia. Sem telefone válido, não envia. Sem o número da loja conectado, não envia, o pedido permanece como está, e a tela diz que o WhatsApp da loja não está conectado.

Enviar não muda status, pagamento, observação nem cliente. Cada confirmação que consegue enviar manda uma mensagem. Confirmar de novo manda outra. Uma confirmação não manda duas. Se o envio falha, o texto continua no campo e a tela diz que não saiu. Se sai, a tela confirma e o campo esvazia. A tela não lista o que já foi enviado.

Pedido de outra loja não recebe a mensagem.

### Máscara de telefone

Todo campo em que a pessoa digita um telefone usa a mesma máscara e o mesmo validador. Entram o popup de entrada, o WhatsApp do checkout, o perfil do cliente, o telefone no cadastro de cliente, o telefone e o WhatsApp no contato da loja, e o telefone pedido para conectar por código. Não entram a busca de pedido ou de cliente, a chave Pix, o código de 6 dígitos, nem um telefone que a tela só mostra.

Enquanto digita, o campo mostra o DDD entre parênteses e o hífen antes dos quatro últimos. Com oito dígitos depois do DDD, fica (11) 3333-4444. O nono dígito reformata para (11) 99999-9999. Letra não permanece. Não passa de um celular completo. Colar +55 ou 55 na frente de um número brasileiro completo aparece sem o 55, na mesma máscara. Ao reabrir um telefone já gravado, o campo mostra a máscara, não a sequência crua. O WhatsApp de contato que estava como 5511… abre assim também.

O que já é obrigatório continua obrigatório. O que já é opcional continua aceitando vazio, e vazio grava vazio. Preenchido, só passa telefone brasileiro com DDD, fixo ou celular. DDD com zero não passa. Número de outro país não passa. Texto que não é telefone não passa. O telefone de contato deixa de aceitar texto livre. A pessoa não digita mais o 55 no WhatsApp de contato: a máscara é a mesma dos outros campos, e o botão da vitrine segue abrindo a conversa desse número.

Enviar um valor inválido por fora da máscara também não grava, e o restante daquele formulário não grava. A mensagem pede um telefone válido com DDD. Duas grafias do mesmo número continuam sendo o mesmo número. O telefone da loja publicado para o visitante continua legível, com DDD.

## Aceite

- [ ] Quem administra a configuração abre a tela WhatsApp e vê o passo em que o número está. Quem não administra não abre e não altera.
- [ ] Conectar mostra a espera, depois o QR ou o código para digitar no WhatsApp, e então o número conectado. Pedir de novo no meio do caminho não abre outra sessão.
- [ ] Desconectar volta a sem número. Sem nada conectado, desconectar não muda o estado. Sessão que cai fora da tela aparece atualizada na próxima visita.
- [ ] Aviso que não é do serviço não muda o passo. O mesmo aviso repetido não aplica a mudança duas vezes.
- [ ] A chave de acesso ao serviço não é pedida na tela da loja nem volta para o navegador.
- [ ] Com o número da loja conectado, o código de login sai desse número. Sem ele, sai do número da plataforma. O texto cita a loja e o código não aparece na tela.
- [ ] Código certo, dentro de 10 minutos, entra na sessão de hoje. Errado, vencido, já usado, de outra loja, ou após cinco erros, não entra. Outro pedido aposenta o anterior.
- [ ] Limite de envio ou falha temporária não entra sem código. Sem remetente nenhum, ou sem acesso ao serviço, entra só com o telefone e, se houver endereço do responsável, sai um e-mail sem o código.
- [ ] Pedir o código não cria cliente nem sessão. Fechar o popup continua sem entrar.
- [ ] Com o interruptor desligado, mudar o status não manda WhatsApp. Ligado e conectado, confirmado, concluído e cancelado mandam o texto da loja, uma vez, ao telefone do pedido. Cancelamento pelo pagamento usa o texto de cancelado.
- [ ] Pedido novo pendente, volta para pendente, mesmo status gravado de novo e pedido sem telefone válido não mandam. Texto vazio não manda aquele status.
- [ ] A mensagem de status não sai pelo número da plataforma. Se o número da loja não está conectado, o status muda e a mensagem não sai.
- [ ] Falha no envio do aviso não desfaz o status e não dispara uma segunda mensagem. O botão de WhatsApp da vitrine e o login do admin seguem como antes.
- [ ] A tela do pedido mostra o campo «Enviar mensagem via WhatsApp». Com o número da loja conectado e telefone válido, o texto sai desse número para esse telefone, uma vez por confirmação. A tela confirma e esvazia o campo. Confirmar de novo manda outra.
- [ ] Texto vazio, pedido sem telefone válido ou número da loja desconectado não envia. Sem número, a tela diz isso e o pedido permanece igual. Falha no envio mantém o texto e avisa que não saiu.
- [ ] Essa mensagem não sai pelo número da plataforma e não exige o interruptor de status. Não altera status, pagamento, observação nem cliente. A tela não lista o que já foi enviado.
- [ ] A configuração do WhatsApp mostra «Fornecido por» e o logo do Zaptria. A vitrine, a tela do pedido e as outras configurações não mostram esse crédito.
- [ ] Popup de entrada, checkout, perfil, cadastro de cliente, telefone e WhatsApp do contato, e o telefone de conectar por código mostram a máscara ao digitar e ao reabrir um número já gravado. Fixo fica (11) 3333-4444 e celular (11) 99999-9999. Letra não permanece.
- [ ] Campo opcional vazio grava vazio. Preenchido, DDD com zero, texto livre, número de outro país ou valor enviado por fora da máscara não grava o formulário. A mensagem pede um telefone válido com DDD.
- [ ] O WhatsApp de contato não pede mais o 55 digitado. O botão da vitrine abre a conversa do mesmo número, e o telefone publicado continua legível com DDD. Busca, chave Pix, código de login e telefone só de leitura não ganham essa máscara.

## Fora de escopo

- Ler ou responder mensagem que o cliente manda no WhatsApp. Código digitado na conversa não entra na conta.
- Editar o texto do código de login.
- Avisar status pelo número da plataforma, ou avisar pedido novo e volta para pendente.
- Campanha, mídia, carrinho abandonado e qualquer mensagem além do código, do aviso de status e do texto escrito na tela do pedido.
- Lista ou histórico do que já foi enviado ao cliente.
- Cobrança, plano ou assinatura no serviço de WhatsApp.
- Configurar, nesta tela, o número da plataforma. Ele não é número de loja.
- Copiar o número conectado para o contato da vitrine, ou mudar o destino do botão de WhatsApp da vitrine.
- Máscara na busca, na chave Pix, no código de login ou num telefone que a tela só mostra.
- Login do admin da loja.
- Tela nova de saída do cliente. Continua sem rota de sair.

## Dispensado
