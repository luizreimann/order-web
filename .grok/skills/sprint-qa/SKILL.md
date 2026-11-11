---
name: sprint-qa
description: >
  Confere uma sprint do order-web contra a spec, com um subagente de leitura,
  e registra a rodada em qa.md. Achado de código vira tarefa nova. Use em
  /sprint-qa, "revisar a sprint", "rodar o QA" ou quando /sprint chegar na fase de QA.
---

# QA da sprint

Leia `.grok/skills/sprint/references/contract.md`, `spec.md`, `plan.md`, `tasks.md` e o `qa.md` se já existir. Se ainda há tarefa `pendente`, `em andamento` ou `bloqueada`, pare e diga para rodar `/sprint-dev`.

Faça a leitura de git do contrato antes de lançar o subagente.

Lance com `spawn_subagent`: `background` true, `isolation` `"none"`, descrição `[qa]` e o número real da rodada. O prompt manda:

- Não criar nem editar arquivo. Não rodar git.
- Ler a spec e o código que o plano cita. Rodar cada prova das tarefas `feita`.
- Para cada critério de aceite, dizer cumprido ou ausente, com o comando e a saída que mostram isso.
- Separar achado de código, spec desencontrada e pergunta que só o usuário responde.
- Ler a seção `CLAUDE.md na sprint` do contrato. Mapa desatualizado nas pastas que o plano cita é achado de código.
- Não tratar o `log.md` como evidência. Medir de novo.

Espere com `get_command_or_subagent_output` no id, `timeout_ms` 600000, e de novo se ainda estiver rodando. Rode de novo, neste chat, as provas que o subagente disse que passaram. Repita a leitura de git do contrato. Se ela acusar diferença, pare e mostre. Não escreva o `qa.md` nesse caso. Não apague.

Escreva a próxima `## Rodada N` no formato do contrato.

- Achado de código: acrescente uma tarefa `pendente` no `tasks.md`, com prova, no formato do contrato. O `Status: aceita` das tarefas continua. Estas tarefas novas entram na próxima `/sprint-dev`.
- Spec desencontrada ou pergunta: `Veredito: aberta` e pare sem inventar tarefa.
- Nenhum achado de código e nenhuma spec pendente: `Veredito: fechada`.

Não escreva `report.md` aqui. Não implemente a correção neste chat.

Responda com o veredito, a quantidade de achados e o caminho do `qa.md`.