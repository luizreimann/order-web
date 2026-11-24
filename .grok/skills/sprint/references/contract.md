# Contrato da sprint

Fonte única do formato. As skills leem daqui e não copiam estas regras para outro arquivo.

As pastas `.sprint/01-payment` até `.sprint/06-refactor` ficam como estão. Sprint nova usa só os seis arquivos abaixo.

## Pasta

`.sprint/NN-slug/`

- `NN` são dois dígitos, o maior número já existente mais um.
- `slug` é minúsculo, com hífen, sem acento.
- Não crie a pasta antes da spec.

## Arquivos

| Arquivo | Quem escreve | O que é |
| --- | --- | --- |
| `spec.md` | `/sprint-spec` | Comportamento, motivo, aceite, fora de escopo. Sem stack, sem nome de classe, sem caminho de arquivo. |
| `plan.md` | `/sprint-plan` | Como implementar, preso à spec aceita e ao `CLAUDE.md`. |
| `tasks.md` | `/sprint-tasks` | Caminho. Uma tarefa cabe numa sessão e tem uma prova. |
| `log.md` | `/sprint-dev` | Uma entrada por tentativa de tarefa. Não é fonte da verdade. |
| `qa.md` | `/sprint-qa` | Uma rodada por vez, medida contra a spec e o código. |
| `report.md` | `/sprint-close` | Fechamento medido de novo. Existe só quando a sprint fecha. |

## Portões

A skill para e mostra o arquivo. O usuário aceita com uma frase explícita. A skill então grava `Status: aceita` no topo. Sem isso, a fase seguinte não começa.

1. Spec aceita.
2. Plano aceito.
3. Tarefas aceitas.
4. `/sprint-dev` executa sozinho, uma tarefa por vez, e para na primeira prova que falha.
5. `/sprint-qa` roda quando não há tarefa `pendente`, `em andamento` nem `bloqueada`.
6. Achado de código vira tarefa nova. O usuário manda `/sprint-dev` de novo.
7. `/sprint-close` só com a última rodada de `qa.md` em `Veredito: fechada`.

`/sprint` com o pedido de fechar os achados alterna dev e QA no máximo três vezes. Na terceira rodada ainda aberta, para e entrega o `qa.md`.

## Ciclo de um achado

- Código não cumpre a spec: nova tarefa em `tasks.md`. A spec não muda.
- A spec é que está errada: a spec muda primeiro, o usuário aceita de novo, o plano e as tarefas acompanham.
- Dispensa: só com a frase do usuário nesta conversa. A spec ganha o critério em "Dispensado", com a data e a frase. Não conta como cumprido.

## Tarefa

```markdown
## T1 — Título curto
- Estado: pendente
- Depende de: nenhuma
- Arquivos: `src/app/...`
- Prova: `docker compose exec app php artisan test --filter NomeDoTeste`
- Feito quando: o comportamento observável que a prova demonstra.

O pedido, em poucas frases. O aceite que esta tarefa cobre está na spec.
```

`Estado` é `pendente`, `em andamento`, `feita` ou `bloqueada`. `Depende de` lista ids `T2, T3` ou `nenhuma`. A prova é um comando copiável da raiz do repositório. Tarefa sem prova não entra no arquivo.

Uma tarefa pronta tem estado `pendente` e todas as dependências `feita`. A ordem de execução é o número do id.

`bloqueada` para a fila. Não é tarefa pronta e não libera o QA. Nova invocação de `/sprint-dev` devolve cada `bloqueada` para `pendente` antes de montar a fila. Dentro da mesma execução, a falha continua parando a fila. `em andamento` também volta para `/sprint-dev`, que retoma o subagente em vez de abrir outro.

## Log

```markdown
## T1 — Título — tentativa 1
- Subagente: id
- Prova: comando
- Resultado: passou | falhou
- Arquivos: lista

O que o orquestrador viu na saída da prova. Se falhou, a primeira linha do erro.
```

## QA

```markdown
## Rodada 1
- Veredito: aberta | fechada
- Provas reexecutadas: comandos

### Achados
- Código: o que a spec pede e o código não faz, ou `CLAUDE.md` desatualizado segundo a seção homônima. Vira tarefa.
- Spec: o que a spec diz e o produto não quer mais. Não vira tarefa até a spec mudar.
- Dispensa: critério que o usuário tirou, já escrito na spec.

Sem achado de código e sem spec pendente, o veredito é `fechada`.
```

## Report

Mede de novo. Não copia número do `log.md`. Para cada critério da spec: cumprido, dispensado ou ausente, com o comando que mostrou isso. O que a sprint não prova fica numa seção própria.

## Quem edita o quê

- O chat orquestrador edita os seis markdowns. O `CLAUDE.md` segue a seção abaixo.
- O subagente de tarefa edita código, teste e o `CLAUDE.md` local quando a seção abaixo manda. Não edita `.sprint/`, não edita o `CLAUDE.md` da raiz, não roda git, não pega a tarefa seguinte.
- O subagente de QA não edita arquivo nenhum, salvo a escrita que a seção Frontend permite ao rodar a prova.
- Commit, push, branch e checkout ficam fora destas skills. Não reverta o que o subagente fez.

## CLAUDE.md na sprint

As regras do arquivo estão no `CLAUDE.md` da raiz: Etiqueta do Repositório, a tabela Localização de Conhecimento, Testes e Verificação e Manutenção deste arquivo. Não as copie para cá.

A atualização acontece na mesma tarefa que muda o comportamento.

- Mudou o contrato da pasta: o `CLAUDE.md` dessa pasta.
- A regra atravessa pastas, nasce um comando, ou uma pasta entra no mapa: o `CLAUDE.md` da raiz, inclusive a tabela de localização.
- O comportamento da pasta não mudou: não toque no arquivo.

Quem escreve:

- O subagente grava o `CLAUDE.md` local das pastas cujo contrato ele mudou. Não grava o da raiz e não cria `AGENTS.md`.
- O orquestrador, antes de marcar `feita`, lê esse arquivo. Se o contrato mudou e a frase não está lá, ele grava. Se não der para gravar sem inventar, a tarefa fica `bloqueada`. Se a regra atravessa pastas, ele atualiza o da raiz nessa tarefa. Arquivo que quebra a regra da raiz impede `feita`.
- O QA lê esta seção e os `CLAUDE.md` das pastas que o plano cita. Mapa desatualizado é achado de código e vira tarefa. A prova dessa tarefa é um `grep` da frase nova no arquivo, rodado na raiz do repositório. As outras tarefas continuam com a prova da tabela Comandos.

## Frontend

Vale quando a pessoa perceberia a mudança no navegador: tela, texto, estado visual ou fluxo. Migration, serviço ou teste sem essa mudança não pede esta prova. Não coloca o Playwright no CI. As dispensas da sprint 06 sobre CI e sobre o checkout como gate continuam.

Uma tarefa só, por último, dependendo das tarefas que montam a tela. A prova é a linha `E2E da sprint` da tabela Comandos do `CLAUDE.md` da raiz, com o `NN-slug` desta pasta.

- O spec fica em `src/e2e/sprint/NN-slug.spec.ts`. Essa pasta já está fora do Git, como o resto de `src/e2e`. Não crie outro runner e não rode `npx playwright test` no host.
- Sem `src/e2e/`, a prova falha e a tarefa fica `bloqueada`.
- O spec afirma o que a tela mostra. Cada tela que ele abre grava um PNG de página inteira em `E2E_CAPTURA`. Sem `E2E_CAPTURA`, o spec falha. Não use `toHaveScreenshot` nem `e2e:update`: o baseline visual continua em `src/e2e/__screenshots__/`.
- Viewport do projeto desktop (1280x800). Tela de celular: `test.use` com 390x844 dentro do spec. Não marque `test.fail`.
- Copie login, seed e bloqueio de rede do spec vizinho em `src/e2e/specs`. A loja é a do `E2eSeeder`.
- As PNG ficam em `.sprint/NN-slug/capturas/` e não são versionadas. Log, QA e relatório citam o comando e os nomes dos arquivos.
- O QA roda a mesma prova. Ela pode escrever em `capturas/` e na saída já ignorada do Playwright (`src/test-results`, `src/playwright-report`, `src/public/build`). PNG que falta, ou asserção que falha, é achado de código. Achado que muda a tela edita o mesmo spec; não cria outro.

```ts
import { expect, test } from '@playwright/test';
import { TENANT_A } from '../support/env';

const captura = process.env.E2E_CAPTURA;
if (!captura) throw new Error('E2E_CAPTURA ausente. Rode com E2E_SPRINT.');

test('a tela da sprint', async ({ page }) => {
    await page.goto(`/${TENANT_A.slug}`);
    await expect(page.getByRole('heading', { name: '...' })).toBeVisible();
    await page.screenshot({ path: `${captura}/nome.png`, fullPage: true });
});
```

## Leitura de git

Antes de cada subagente, e de novo quando ele termina, o orquestrador roda só estas duas leituras:

- `git status --porcelain`
- `git rev-parse HEAD`

- Implementação: se o HEAD mudou, ou se apareceu caminho em `.sprint/` que não estava no status de antes, pare e mostre. Não marque `feita`. `capturas/` não entra no status: está no `.gitignore`. Se um PNG de lá aparecer no status, pare: o ignore falhou.
- QA: se o HEAD mudou ou se o status ganhou arquivo, pare e mostre o diff. Não apague. O `qa.md` só é escrito depois dessa comparação.

## Subagentes

Tarefa de implementação: um subagente por tarefa, no workspace compartilhado, sem worktree. A tarefa seguinte precisa ver o arquivo que a anterior gravou. Worktree não volta sozinho para o workspace.

QA e o levantamento de spec ou plano: um subagente só de leitura por rodada.

O orquestrador não implementa código de aplicação no próprio chat. Ele não acredita no "passou" do subagente: roda a prova de novo, cumpre a seção `CLAUDE.md na sprint` e só então marca `feita`.
