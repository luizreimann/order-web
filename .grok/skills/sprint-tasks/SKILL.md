---
name: sprint-tasks
description: >
  Quebra o plano aceito de uma sprint do order-web em tarefas pequenas, cada
  uma com prova. Use em /sprint-tasks, "quebrar em tarefas" ou quando /sprint
  indicar que as tarefas são o próximo passo.
---

# Tarefas da sprint

Leia `.grok/skills/sprint/references/contract.md`, a `spec.md` e a `plan.md`. Os dois precisam estar `Status: aceita`. Senão, pare.

Se `tasks.md` já existe, não reescreva. Aceitação segue o portão do contrato. Mudança pedida pelo usuário edita o trecho e devolve o status para `rascunho`.

Cada critério de aceite vira uma ou mais tarefas. Tarefa que nenhuma outra precisa fica sem dependência. A que cria o teste da prova vem antes da que faz o teste passar, quando isso separar o trabalho. Uma tarefa que não cabe numa sessão se divide.

Use o bloco de tarefa do contrato. `Estado: pendente`. A prova é um comando da tabela Comandos do `CLAUDE.md` da raiz.

```markdown
# Tarefas — Título
Status: rascunho

## T1 — ...
```

No topo, depois do status, uma lista da ordem e das dependências. Cubra todo critério da spec. Critério sem tarefa aparece como lacuna, e você para sem marcar o arquivo como aceito.

Mostre o caminho e pare.