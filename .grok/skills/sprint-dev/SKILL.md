---
name: sprint-dev
description: >
  Executa as tarefas aceitas de uma sprint do order-web, um subagente por
  tarefa, e só marca a tarefa feita depois de rodar a prova. Use em /sprint-dev,
  "implementar a sprint", "rodar as tarefas" ou quando /sprint chegar na fase de dev.
---

# Dev da sprint

Você orquestra. Não edite código de aplicação neste chat.

Leia `.grok/skills/sprint/references/contract.md`, `spec.md`, `plan.md` e `tasks.md`. Sem `Status: aceita` nas tarefas, pare.

## Fila

Siga o contrato para `bloqueada` e `em andamento`: no começo de uma invocação nova, `bloqueada` volta a `pendente`. Dentro da mesma execução, falha continua parando a fila.

Pegue tarefas `pendente` cujas dependências estão `feita`, na ordem do id. `em andamento` com id de subagente no log: retome com `resume_from` em vez de abrir outra. Sem id recuperável, lance outra e mande o subagente olhar o que já mudou antes de editar.

Uma por vez. `isolation` `"none"`, como o contrato exige. A próxima só começa depois da prova.

## Lançar

Grave `Estado: em andamento` antes de lançar. Faça a leitura de git do contrato. `spawn_subagent` com `background` true, `isolation` `"none"` e descrição `[implementer]` mais o id e o título reais. O prompt leva tudo, porque o subagente não vê este chat:

- A spec inteira e o trecho do plano que cita os arquivos desta tarefa.
- O bloco da tarefa, inclusive a prova.
- Usar `read_file` no código real antes de editar. Ler o `CLAUDE.md` da raiz e o `CLAUDE.md` de cada diretório que for editar.
- Implementar só esta tarefa. Não abrir a próxima.
- Não editar arquivo dentro de `.sprint/`. Não rodar git.
- Seguir o vizinho. Não copiar dívida listada no `CLAUDE.md` da raiz.
- Comportamento que a spec não decide: parar e devolver a pergunta. Não inventar.
- Rodar a prova. Seguir a seção `CLAUDE.md na sprint` do contrato: gravar o `CLAUDE.md` local se o contrato da pasta mudou, e não gravar o da raiz.
- Devolver: arquivos alterados, se o contrato da pasta mudou, se a prova passou, a pergunta bloqueante se houver.

Espere com `get_command_or_subagent_output` no id devolvido, `timeout_ms` 600000. Se ainda estiver rodando, espere de novo. Não comece a próxima tarefa enquanto essa não terminou. Não diga que lançou sem ter chamado `spawn_subagent`.

## Prova

Repita a leitura de git do contrato. Rode você mesmo o comando da tarefa, a partir da raiz. Só então:

- A leitura acusou HEAD novo ou arquivo em `.sprint/`: pare e mostre. Não marque `feita`. Não reverta.
- A prova falhou ou o subagente devolveu pergunta: `Estado: bloqueada`, entrada no log com o erro ou a pergunta, e pare. Não lance a próxima.
- A prova passou, a leitura está limpa e a seção `CLAUDE.md na sprint` foi cumprida: `Estado: feita`, entrada no `log.md` com o id do subagente e o resultado. Se o mapa não deu para atualizar sem inventar, `Estado: bloqueada` e pare.

Quando a fila esvaziar, diga para o usuário rodar `/sprint-qa`. Não rode o QA no mesmo turno, a menos que o pedido tenha sido fechar os achados pelo `/sprint`.

## Resposta

Uma linha por tarefa: id, passou ou falhou, prova. O detalhe fica no `log.md`.