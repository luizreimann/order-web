---
name: sprint-close
description: >
  Fecha uma sprint do order-web com um relatório medido de novo, depois do QA
  fechado. Use em /sprint-close, "fechar a sprint" ou "relatório final da sprint".
---

# Fechamento da sprint

Leia `.grok/skills/sprint/references/contract.md`, a `spec.md` e a última rodada de `qa.md`. Sem `Veredito: fechada` na última rodada, pare. Não feche em cima de achado aberto.

Rode de novo cada prova das tarefas `feita`. Não copie número do `log.md` nem do `qa.md`.

Escreva `report.md`. A linha `Data` é a data desta sessão no formato `YYYY-MM-DD`.

```markdown
# Relatório — Título
Data: YYYY-MM-DD

## Veredito
A sprint fecha, ou não fecha. Uma frase.

## Critérios
| Critério | Resultado | Prova |
| --- | --- | --- |
| texto da spec | cumprido ou dispensado | comando e o que a saída mostrou |

## O que isto não prova
Lacuna real. Se não houver, escreva que os critérios da spec foram medidos.

## Dispensado
O que a spec tirou, com a frase do usuário.
```

Critério que a prova não mostra: não marque cumprido. Diga que o fechamento parou e qual prova falta.

Aponte o `report.md` e pare.