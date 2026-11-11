# Spec — Agendamento da encomenda
Status: aceita

## Por quê

A loja vive de encomenda e fecha o pedido sem saber o dia nem o horário. O cliente não escolhe quando quer receber ou retirar. A antecedência gravada no produto não muda o checkout. A loja não bloqueia folga nem limita quantos pedidos aceita num dia.

Com esta sprint, a loja liga um calendário único para entrega e retirada. O cliente escolhe o dia e uma opção de horário que a loja cadastrou. A produção passa a ver, no pedido, o dia e a opção combinados.

## Comportamento

O agendamento é uma configuração da loja, desligada por padrão. Desligado, o checkout segue como hoje: não pede dia nem horário, e o pedido fecha sem os dois. Pedidos antigos continuam sem data. Ligar ou desligar não apaga o dia já gravado num pedido.

Ligado, entrega e retirada usam as mesmas regras. O cliente escolhe o dia num calendário e uma opção de horário nos dois tipos, no pagamento manual e no online. Sem os dois, o pedido não fecha. Trocar o tipo de recebimento não troca o calendário.

O horário de funcionamento da vitrine não entra nessa conta. Domingo pode aceitar encomenda com a vitrine fechada, e o contrário também. Loja pausada ou fora do expediente continua podendo receber pedido, como hoje.

### Calendário

No checkout o calendário do mês fica visível. Os nomes dos dias e dos meses estão em português. A semana começa no domingo. Não há campo para digitar a data e o seletor de data do navegador não é o controle.

O cliente só consegue marcar um dia habilitado. O dia marcado permanece destacado. Não dá para folhear antes do mês da primeira data candidata nem depois do mês da última.

Dia que não passa nas regras continua na grade, visível e desabilitado. Clicar nele não seleciona e não troca o dia que já estava marcado. Entram nessa lista a folga, o dia da semana desmarcado, o dia fora da janela, o dia sem vaga e o dia sem opção de horário. Folga e dia lotado se distinguem do restante: a folga indica que é folga, o dia lotado indica que não há vaga.

A loja inclui cada folga escolhendo o dia num calendário, não digitando a data.

### Dias da semana

A loja marca os dias em que aceita encomenda, de segunda a domingo. Ao abrir a configuração pela primeira vez, os sete dias vêm marcados. Dia desmarcado aparece no calendário e fica desabilitado.

### Antecedência

A conta usa o fuso da loja. O dia de hoje não entra. Não há opção para incluir o hoje.

A primeira data candidata é hoje mais a antecedência mais um dia de calendário. Antecedência 0 é amanhã. Antecedência 1 é depois de amanhã. A contagem não pula dia sem entrega nem folga. Se a candidata cair num dia inválido, a primeira data escolhível é a próxima válida, para a frente.

A antecedência da loja é um inteiro de 0 a 365. O valor inicial da tela é 0.

No produto, a antecedência preenchida substitui a da loja só naquele produto. 0 é valor preenchido e significa amanhã para esse produto. Em branco, vale a antecedência da loja. O campo continua de 0 a 365.

O pedido tem uma data só e usa a antecedência mais exigente do carrinho. Loja em 2, bolo em 0 e brigadeiro em branco: o brigadeiro usa 2, o bolo usa 0, o pedido usa 2, e a primeira candidata é daqui a 3 dias. Loja em 2, bolo em 0 e brigadeiro em 0: o pedido usa 0, e a primeira candidata é amanhã.

Se o carrinho mudar e a data já escolhida ficar cedo demais, essa data deixa de valer.

O produto deixa de ter o campo "Exigir data/hora no pedido". Ter esse campo marcado hoje não obriga data. Só o agendamento ligado na loja obriga. Com o agendamento desligado, a antecedência do produto não pede data.

### Máximo

O máximo é um inteiro de 0 a 365. O valor inicial da tela é 30. 0 não significa sem limite: a última data candidata é hoje, que nunca pode ser escolhida, então não há data.

A última data candidata é hoje mais o máximo, em dias de calendário. Mínimo 0 e máximo 7 cobrem de amanhã até daqui a 7 dias. Mínimo 1 e máximo 1 não cobrem dia nenhum, porque a primeira candidata é depois de amanhã e a última é amanhã.

Data fora dessa janela não pode ser escolhida.

### Folgas

A loja informa datas avulsas, o dia inteiro, em que não aceita encomenda. A mesma lista vale para entrega e retirada. A data entra uma vez. Tirar a data da lista volta a liberar o dia, se o resto das regras permitir.

No calendário do cliente, a folga aparece desabilitada e identificada como folga. Uma folga não se repete sozinha no ano seguinte. Não há folga por horário nem por intervalo de horas.

### Capacidade

Para cada dia da semana, a loja pode informar um máximo de pedidos. O número vale para cada data daquele dia, toda semana. Terça com 10 aceita 10 pedidos em cada terça, não 10 no total das terças.

Em branco, aquele dia não tem limite. 0 aceita nenhum pedido, então o dia não pode ser escolhido.

Conta um pedido que não está cancelado e que tem aquele dia. A quantidade de itens não importa. Entrega e retirada dividem a mesma vaga. Pedido sem dia não conta. Cancelar devolve a vaga. Voltar um pedido cancelado para um estado ativo ocupa a vaga de novo; se o dia já estiver no limite, a loja não consegue reabrir e o pedido segue cancelado.

Se a loja baixar o limite abaixo do que já está ocupado, os pedidos feitos permanecem. Enquanto a ocupação estiver no limite ou acima, o dia não aceita pedido novo.

Dia cheio aparece no calendário, desabilitado, e indica que não há vaga. Duas pessoas no último lugar: uma fica com o pedido, a outra recebe recusa e o pedido dela não nasce.

### Horários

Em cada dia da semana a loja monta a lista que o cliente vê. Cada item é um horário fixo, como 10:00, ou um intervalo fixo, como 14:00–16:00. Os dois tipos podem conviver no mesmo dia. A lista segue a ordem que a loja definiu. A hora usa relógio de 24 horas, de 00:00 a 23:59. No intervalo, o fim é depois do começo e no mesmo dia.

Com um dia habilitado marcado, o select de horário mostra os itens daquele dia da semana. Terça com "10:00" e "14:00–16:00" mostra esses dois itens e não mostra 14:30 nem 15:00. Trocar o dia limpa a opção que não existe no dia novo. Dia da semana marcado sem nenhum item fica desabilitado no calendário.

A opção gravada no pedido é a que o cliente escolheu. Se a loja apagar essa opção depois, o pedido antigo continua mostrando a mesma opção, e o checkout novo não oferece mais.

### O que fica visível

No pedido, a loja e o cliente veem o dia escolhido e a opção de horário escolhida. O dia mostrado é o dia do calendário da loja, o mesmo que o cliente marcou.

Não há data válida quando o agendamento está ligado e nenhuma data passa em todas as regras ao mesmo tempo: dia da semana, janela, folga, vaga e pelo menos uma opção de horário. O checkout avisa e não fecha o pedido.

Quem já altera a configuração da loja altera o agendamento. Salvar essa configuração não apaga taxa, bairro, horário de funcionamento nem o restante da loja.

## Aceite

- [ ] Com o agendamento desligado, o checkout fecha sem dia e sem horário, na entrega e na retirada, no manual e no online.
- [ ] Com o agendamento ligado, entrega e retirada exigem dia e opção de horário, no manual e no online. Sem os dois, o pedido não nasce.
- [ ] O checkout mostra um calendário de mês, em português, com a semana começando no domingo. Não dá para digitar a data. Dia desabilitado continua visível, não seleciona, e não troca uma data já marcada.
- [ ] Dia da semana desmarcado fica desabilitado nesse calendário. O expediente e a pausa da vitrine não mudam essa lista e continuam sem impedir o pedido.
- [ ] Se hoje é segunda, antecedência 0 só permite a partir de terça, e antecedência 1 só permite a partir de quarta. Hoje nunca entra.
- [ ] A antecedência do produto, se preenchida, vale no lugar da loja para aquele produto. Em branco, vale a da loja. O pedido usa a mais longa do carrinho, inclusive quando um produto está em 0 e outro está em branco.
- [ ] O produto não mostra mais "Exigir data/hora no pedido". Produto que já tinha isso marcado não exige data com o agendamento desligado.
- [ ] Máximo 0 não oferece data. Máximo 7, com antecedência 0, não oferece data depois de daqui a 7 dias. Mínimo 1 e máximo 1 não oferecem data.
- [ ] Uma folga de dia inteiro aparece desabilitada e identificada como folga, na entrega e na retirada. A loja inclui a folga pelo calendário. Removê-la devolve o dia se as outras regras permitirem. A folga não volta sozinha no ano seguinte.
- [ ] Capacidade em branco não limita. Capacidade 0 ou dia já lotado fica desabilitado no calendário e o dia lotado indica que não há vaga. Cada data daquele dia da semana tem o próprio limite. Um pedido ocupa uma vaga, somando entrega e retirada.
- [ ] Cancelar devolve a vaga. Reabrir um cancelado sem vaga falha e o pedido continua cancelado. Dois fechamentos ao mesmo tempo no último lugar geram um pedido só.
- [ ] Com o dia marcado, o select de horário mostra só as opções que a loja cadastrou naquele dia da semana, horário ou intervalo, na ordem cadastrada, sem grade gerada. Dia sem opção fica desabilitado. Intervalo com fim antes do começo não salva.
- [ ] A loja e o cliente veem, no pedido, o dia e a opção escolhidos. Apagar a opção na configuração não muda o pedido antigo.
- [ ] Dia, horário, folga ou vaga rejeitados pelo cliente não nascem como pedido. Carrinho que passou a exigir mais antecedência invalida a data que ficou cedo.
- [ ] Salvar o agendamento preserva o restante da configuração da loja.

## Fora de escopo

- Grade de horários gerada sozinha, de 15, 30 ou 60 minutos, dentro de um intervalo.
- Apelido no lugar do horário, como "Manhã".
- Capacidade por horário, por intervalo ou por quantidade de itens.
- Regras, folgas ou capacidade diferentes para entrega e para retirada.
- Taxa diferente por dia ou por horário.
- Incluir o dia de hoje na antecedência, ou pular dia fechado e folga na contagem dos dias.
- Folga por faixa de horas, por intervalo de várias datas, ou repetida todo ano.
- A loja alterar o dia ou o horário de um pedido já feito.
- Lembrete, e-mail e calendário externo.
- Produto que só aparece em certos dias.
- O expediente ou a pausa passarem a recusar pedido.
- Relógio de 12 horas.
- Campo de texto ou seletor nativo do navegador como forma de escolher o dia.

## Dispensado
