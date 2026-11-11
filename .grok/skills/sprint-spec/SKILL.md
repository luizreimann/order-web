---
name: sprint-spec
description: >
  Escreve a spec de uma sprint do order-web: comportamento, motivo, critérios
  de aceite e fora de escopo, sem stack. Use em /sprint-spec, "escrever a spec",
  "especificar a sprint" ou quando /sprint indicar que falta a spec.
---

# Spec da sprint

Leia `.grok/skills/sprint/references/contract.md`. Você escreve só `spec.md`.

Se `spec.md` já existe, não reescreva. Aceitação segue o portão do contrato: grave `Status: aceita` e pare. Mudança pedida pelo usuário edita o trecho e devolve o status para `rascunho`.

## Antes de escrever

Leia o `CLAUDE.md` da raiz. Se o pedido cita uma pasta que tem `CLAUDE.md`, leia esse também. Se o comportamento depende de código que você ainda não viu, chame `spawn_subagent` com `background` true, `isolation` `"none"` e descrição `[explore]`. O prompt pede fatos do código, com caminho e método, e proíbe editar arquivo. Espere com `get_command_or_subagent_output`, passando o id e `timeout_ms` 600000. Se ainda estiver rodando, espere de novo.

Pasta nova: próximo `NN` em `.sprint/`, slug a partir do pedido. Não recrie as sprints 01–06.

Pergunta de produto que o código não responde: pare e pergunte. Não invente o aceite.

## Arquivo

```markdown
# Spec — Título
Status: rascunho

## Por quê
Quem sofre e o que muda para essa pessoa.

## Comportamento
Frases observáveis. Cada uma pode virar prova.

## Aceite
- [ ] Critério que um teste ou comando consegue mostrar.

## Fora de escopo
O que alguém vai querer incluir e esta sprint não faz.

## Dispensado
Vazio até o usuário tirar um critério.
```

Mostre o caminho e pare. A implementação espera `Status: aceita`.