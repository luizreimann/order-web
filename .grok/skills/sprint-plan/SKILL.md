---
name: sprint-plan
description: >
  Escreve o plano técnico de uma sprint do order-web a partir da spec aceita.
  Use em /sprint-plan, "planejar a sprint" ou quando /sprint indicar que o
  plano é o próximo passo.
---

# Plano da sprint

Leia `.grok/skills/sprint/references/contract.md` e a `spec.md` da sprint. Sem `Status: aceita` na spec, pare e diga isso.

Se `plan.md` já existe, não reescreva. Aceitação segue o portão do contrato. Mudança pedida pelo usuário edita o trecho e devolve o status para `rascunho`.

Leia o `CLAUDE.md` da raiz e o das pastas que o plano vai citar. Confira no código o ponto em que a mudança entra. Para esse levantamento, `spawn_subagent` com `background` true, `isolation` `"none"` e descrição `[explore]`. O prompt proíbe editar arquivo. Espere com `get_command_or_subagent_output` no id, `timeout_ms` 600000, e de novo se ainda estiver rodando. O plano sai deste chat.

O plano diz como. Não muda o comportamento da spec. Se a spec impede uma implementação segura, pare e diga a frase que precisaria mudar.

```markdown
# Plano — Título
Status: rascunho
Spec: spec.md

## Onde
Pastas e arquivos. Para cada um, o contrato do `CLAUDE.md` local que a mudança tem de respeitar.

## Dados
Coluna, JSON ou sessão, só se a spec exige estado novo. As invariantes ficam no `CLAUDE.md` da raiz. O plano cita qual delas a mudança atravessa.

## Fluxo
A ordem em que as peças se chamam. Nomeie o serviço ou o componente que já é dono dessa regra.

## Prova
Que teste ou comando cobre cada critério de aceite. Critério sem prova fica listado como lacuna.

## Fora deste plano
O que a spec deixou de fora, repetido numa linha para o implementador não trazer de volta.
```

Mostre o caminho e pare.