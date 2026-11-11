---
name: sprint
description: >
  Orquestra uma sprint spec-driven do order-web. Lê o estado de .sprint/,
  diz a fase atual e executa só o próximo passo, parando para o usuário
  aceitar spec, plano e tarefas. Use quando o usuário disser /sprint,
  "começar uma sprint", "continuar a sprint", "fecha os achados",
  "qual o próximo passo da sprint", "aceito" ou "pode seguir"
  sobre o artefato da sprint.
---

# Sprint

Você orquestra. Não implementa código neste chat.

Leia `.grok/skills/sprint/references/contract.md` e siga-o. Os formatos moram lá.

## Achar a sprint

O argumento é um caminho `.sprint/NN-slug` ou um slug. Sem argumento, use a pasta nova de maior número que ainda não tem `report.md`. Se existir mais de uma aberta, pergunte qual. Se não existir nenhuma e o usuário descreveu um trabalho, o próximo passo é `/sprint-spec`.

## Próximo passo

Olhe os `Status` e os estados das tarefas.

| Situação | Ação |
| --- | --- |
| Sem `spec.md` | Siga `.grok/skills/sprint-spec/SKILL.md`. |
| `spec.md` em rascunho | Mostre o caminho e peça a aceitação. Não reescreva. |
| Spec aceita, sem `plan.md` | Siga `.grok/skills/sprint-plan/SKILL.md`. |
| `plan.md` em rascunho | Mostre o caminho e peça a aceitação. Não reescreva. |
| Plano aceito, sem `tasks.md` | Siga `.grok/skills/sprint-tasks/SKILL.md`. |
| `tasks.md` em rascunho | Mostre o caminho e peça a aceitação. Não reescreva. |
| Tarefas aceitas e há tarefa pronta, `em andamento` ou `bloqueada` | Siga `.grok/skills/sprint-dev/SKILL.md`. |
| Nenhuma tarefa `pendente`, `em andamento` ou `bloqueada`, e o QA ainda não rodou depois do último log | Siga `.grok/skills/sprint-qa/SKILL.md`. |
| Última rodada de QA fechada e sem `report.md` | Siga `.grok/skills/sprint-close/SKILL.md`. |
| `report.md` presente | Diga que a sprint está fechada e aponte o arquivo. |

"Aceito", "pode seguir" ou equivalente, dito sobre o arquivo que você acabou de mostrar, grava `Status: aceita` nesse arquivo e para. Não avance duas fases no mesmo turno.

## Fechar achados

Se o usuário pedir para corrigir até o QA passar, alterne `/sprint-dev` e `/sprint-qa` no máximo três vezes. Mostre o veredito de cada rodada em poucas linhas. Na terceira rodada ainda aberta, pare.

## Resposta

Diga a pasta, a fase, o que você fez e o que o usuário responde para continuar. Não resuma a spec inteira.