# Estado do projeto e plano de integração gradual à main

**Data do registro:** 24/11/2026  
**Repositório:** `Alex5762/academia-alunos-crud`  
**Branch de trabalho:** `feat/dashboard-portfolio`  
**PR atual:** [#1 — FitControl](https://github.com/Alex5762/academia-alunos-crud/pull/1)  
**Objetivo deste documento:** servir como ponto de retomada do projeto e registrar a estratégia aprovada para integrar o trabalho em entregas menores, sem depender do histórico de conversa.

> Este é um retrato do repositório no momento indicado. Antes de executar qualquer operação futura, atualizar os SHAs e estados no GitHub; não presumir que continuam iguais.

---

## 1. Estado verificado

**Atualização de progresso em 24/11/2026:** bloco de administração concorrente concluído e validado pela CI #1716; ver `docs/seguranca-administracao-contas-concorrencia.md`.

### Branch e PR

| Item | Estado observado em 24/11/2026 |
|---|---|
| Branch de trabalho | `feat/dashboard-portfolio` |
| HEAD da branch no snapshot desta atualização | `0310e4432429541a1f3bfdeaa216d68464af9a5c` |
| Último commit no snapshot | `docs(users): record concurrent administrator safeguards` |
| Branch de destino da PR | `main` |
| PR #1 | Aberta, em Draft, não merged |
| Arquivos na PR | 189 |
| Commits apresentados na PR | 1.197 |
| Adições / remoções | 38.381 / 305 |
| Estado de merge reportado pelo GitHub | `mergeable: true` |

O `mergeable: true` indica que o GitHub não reportou conflito de merge naquele momento. **Não significa que a PR esteja aprovada, revisada ou pronta para integração.**

Links:
- [PR #1](https://github.com/Alex5762/academia-alunos-crud/pull/1)
- [HEAD de referência do snapshot](https://github.com/Alex5762/academia-alunos-crud/commit/0310e4432429541a1f3bfdeaa216d68464af9a5c)

### Estado da main e divergência de histórico

| Referência | SHA observado |
|---|---|
| `main` | `e1f4c8084dc6dec8c772a699855562944d50e89e` |
| `feat/dashboard-portfolio` no snapshot | `0310e4432429541a1f3bfdeaa216d68464af9a5c` |
| Base comum identificada | `887fb6549888132202d3561faaed76a9928c0314` |

A comparação GitHub entre `main` e `feat/dashboard-portfolio` retornou estado divergente: 1.197 commits à frente na branch de trabalho e 6 commits à frente na `main`, em relação à base comum.

Os seis commits da `main` depois da base comum foram inspecionados por comparação: a comparação da base `887fb654...` até `main` retornou seis commits e **nenhum arquivo com diferença líquida**. O histórico registra adições e remoções de arquivos de diagnóstico e uma mudança no histórico financeiro posteriormente revertida. Isso não autoriza ignorar os commits; apenas indica que, na comparação de arquivos feita naquele momento, não havia alteração líquida para transportar.

A quantidade de commits da PR e o histórico divergente tornam desaconselhável fazer um merge integral sem antes reconstruir e revisar os conjuntos lógicos de alterações. Também não é recomendável tentar transportar os 1.197 commits individualmente.

### CI da implementação técnica

A [CI #1716](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45340419014) foi executada no commit de implementação `8ab8315125b790fecffa962b1a05b23be5a14615` e concluiu com sucesso.

- Sete jobs funcionais concluídos com sucesso.
- Compatibilidade automatizada com MySQL 8.0 e MariaDB 10.11.
- Migrations, testes de domínio/banco, concorrência, integração HTTP e verificações PHP executadas conforme os jobs do workflow.
- O job de publicação de screenshots foi ignorado (`skipped`), não falhou.

A CI verde é evidência automatizada importante, mas não substitui validação no XAMPP, inspeção em navegador/dispositivo real ou abertura dos CSV em Excel/LibreOffice.

### Situação funcional e documental

- Blocos 01–07 têm implementação relevante na branch e evidências automatizadas registradas nos documentos do projeto.
- A revisão consolidada de segurança está em [`docs/revisao-seguranca-consolidada.md`](revisao-seguranca-consolidada.md).
- A revisão de administração concorrente está em [`docs/seguranca-administracao-contas-concorrencia.md`](seguranca-administracao-contas-concorrencia.md).
- O roadmap permanece em [`docs/roadmap.md`](roadmap.md).
- A CSP está em modo **Report-Only**; não converter para enforcement sem revisão manual e aprovação específica.
- O projeto continua acadêmico/local. Não usar dados reais nem expor publicamente como aplicação de produção.

---

## 2. Bloco técnico concluído — administração de contas e concorrência

A inconsistência de proteção do último administrador foi tratada no commit `8ab8315125b790fecffa962b1a05b23be5a14615`.

- Foi criado `src/Domain/Users/UserAccountAdministrationService.php` para centralizar alterações de perfil e status.
- Cada operação abre sua própria transação e bloqueia as contas em ordem crescente de ID com `SELECT ... ORDER BY id FOR UPDATE`.
- Depois de obter os locks, o serviço relê o estado atual do ator e exige conta ativa, perfil com `users.manage` e troca obrigatória de senha concluída. Uma requisição que aguardou o lock não conserva autorização revogada por outra operação.
- A contagem de administradores ativos considera perfil/permissão administrativa, e não todas as contas ativas.
- O bloqueio de autodesativação e autorrebaixamento permanece explícito.
- Alteração de perfil, histórico especializado e auditoria SHA-256 participam da mesma transação; mudança de status e auditoria também.
- Os endpoints continuam exigindo POST, autenticação, permissão e CSRF; agora delegam a alteração ao serviço e tratam resultados de autorização, alvo inexistente e operação inválida.

### Testes e evidências

- `tests/database-user-account-administration.php` executa dois processos PHP com conexões PDO independentes e uma barreira de concorrência. Um processo tenta rebaixar A enquanto o outro tenta desativar B. O teste exige que exatamente uma operação seja aceita, a outra perca autorização após o lock e permaneça exatamente um administrador ativo.
- O mesmo teste cobre autodesativação, autorrebaixamento, ator sem `users.manage`, transições válidas e integridade da auditoria. Ele exige `GITHUB_ACTIONS=true`, pois cria fixtures permanentes na trilha append-only; roda somente nos bancos efêmeros da CI.
- `tests/integration-crud.sh` também cobre a rota HTTP de status: CSRF ausente retorna 403, autodesativação é bloqueada, desativação e reativação funcionam e cada transição gera exatamente um evento de auditoria.
- A CI #1716 ([run 45340419014](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45340419014)) passou no HEAD `8ab8315125b790fecffa962b1a05b23be5a14615`: os sete jobs funcionais concluíram com sucesso, incluindo compatibilidade MySQL 8.0/MariaDB 10.11, teste concorrente de administração, sintaxe PHP e integração HTTP/Playwright. A publicação de screenshots foi ignorada pela condição do workflow.
- A revisão é de código e testes automatizados; não é pentest nem validação local/XAMPP.

**Resultado:** Ações 1 e 2 do plano estão concluídas. Não houve migration, mudança de schema, alteração da `main` ou merge. A PR #1 continua em Draft.



---

## 3. Estratégia de integração definida

### Decisão

Não fazer merge da PR #1 inteira neste momento. Preservar `feat/dashboard-portfolio` e a PR #1 como referência histórica e fonte do estado funcional completo. Não apagar, reescrever ou fazer force-push nessa branch como tentativa de reduzir o contador de commits.

A integração será feita por **PRs pequenas, sequenciais e revisáveis**, cada uma com um escopo funcional coerente e uma CI própria. O fluxo padrão será:

1. Criar uma branch nova a partir da `main` atual.
2. Trazer somente o conjunto de alterações pertencente ao bloco, preservando a intenção e os testes.
3. Revisar o diff contra a `main`, incluindo migrations, dependências, documentação, permissões e regressões.
4. Executar os testes relevantes e a CI completa.
5. Abrir uma PR direcionada à `main`, inicialmente em Draft quando ainda houver revisão.
6. Só integrar depois de CI verde, revisão do diff e autorização explícita do usuário.
7. Após a integração, atualizar a referência da `main` e criar a branch seguinte a partir dela.

O padrão será **uma PR por vez, todas direcionadas à `main`**. Não vamos criar várias branches empilhadas antecipadamente por padrão: isso adicionaria complexidade de base/retarget e poderia dificultar a revisão. PRs empilhadas só serão consideradas se uma dependência concreta justificar o uso.

### Divisão funcional candidata

A divisão abaixo é uma hipótese inicial, não uma autorização para criar branches. Antes de cada PR, mapear os arquivos reais, dependências e ordem obrigatória das migrations. Os arquivos compartilhados — por exemplo `funcoes.php`, `conexao.php`, `index.php`, `style.css`, RBAC e auditoria — podem precisar de divisão cuidadosa para que cada entrega seja funcional e testável.

| Ordem candidata | Entrega | Conteúdo a mapear |
|---|---|---|
| 1 | Fundação, autenticação e segurança | Configuração PDO, sessões, CSRF, login/logout, RBAC, contas, proteções webroot, auditoria-base e CI |
| 2 | Gestão de alunos, planos e matrículas | Status/histórico de alunos, migrations de planos/matrículas, catálogo, contratos e revisão de vínculos legados |
| 3 | Frequência | Check-in, elegibilidade, unicidade diária, auditoria e consultas |
| 4 | Financeiro | Cobranças, pagamentos, idempotência, histórico, permissões e relatórios financeiros |
| 5 | Grade de aulas | Modalidades, instrutores, horários recorrentes, conflitos e consultas |
| 6 | Dashboard, relatórios e CSV | Agregadores, período, cartões, relatórios, exportação e testes de download |
| 7 | Fechamento documental | README, roadmap, checklist, capturas e registro final, sem duplicar documentação já entregue junto aos blocos |

A ordem final poderá ser ajustada quando a matriz de dependências for concluída. **Não reordenar migrations numeradas por conveniência**; respeitar dependências de schema e dados, e validar a sequência em MySQL e MariaDB.

### Critérios mínimos para uma PR de bloco

Uma PR só fica pronta para integração quando:

- O escopo e os critérios de aceite estão explícitos.
- O diff contém somente mudanças pertencentes à entrega, sem arquivos acidentais ou credenciais.
- Testes novos cobrem regras de negócio, permissões, CSRF e falhas relevantes.
- Testes de regressão pertinentes passam.
- Migrations são aplicáveis na ordem documentada e preservam os dados legados.
- Documentação acompanha a implementação.
- CI do HEAD exato da PR está verde.
- Não há pendência conhecida bloqueadora sem decisão explícita.
- O usuário revisou e autorizou o merge.

Preferir **Squash and merge** nas novas PRs quando cada bloco formar uma unidade lógica e o histórico interno de commits não precisar ser preservado individualmente. A escolha será confirmada em cada PR; não aplicar squash retroativo à PR #1.

---

## 4. Proteção da main

Na consulta feita em 24/11/2026, o GitHub retornou `protected: false` para a branch `main`.

Antes do primeiro merge da nova sequência, avaliar e configurar proteção de branch/regras de repositório compatíveis com o plano, incluindo:

- exigir PR para alterações na `main`;
- exigir as verificações obrigatórias da CI;
- impedir force-push e exclusão da branch;
- exigir revisão antes da integração, quando disponível e adequado ao fluxo individual.

A configuração deve ser testada para não exigir uma aprovação impossível do próprio autor em um repositório individual. Não alterar as regras do repositório sem confirmar a configuração e seus efeitos.

---

## 5. Validação prática — reservar para o final

A validação local foi deliberadamente adiada porque o ambiente do usuário não está disponível neste momento. Ela não deve bloquear as etapas de revisão e integração que podem ser verificadas com segurança por código e CI, mas deve continuar registrada como pendente.

Quando o ambiente estiver disponível, fazer um bloco final específico para:

- backup e inspeção do banco local antes de qualquer migration;
- aplicação controlada das migrations no XAMPP, conforme `docs/guia-migrations-xampp.md`;
- smoke test dos fluxos principais com dados fictícios;
- inspeção em navegador instalado e aparelho físico;
- abertura dos quatro CSV no Excel e no LibreOffice;
- registro dos resultados, problemas e evidências.

Até lá: não executar migrations no banco local do usuário, não inserir dados reais e não declarar a validação local concluída.

---

## 6. Registro de decisões e ações proibidas sem aprovação

- Não fazer merge da PR #1 integral.
- Não fechar nem apagar a PR #1 agora; mantê-la como referência até a nova sequência cobrir o conteúdo necessário.
- Não reescrever histórico, fazer force-push, rebase destrutivo ou cherry-pick em massa.
- Não alterar `main` diretamente.
- Não integrar uma PR apenas porque o GitHub informa `mergeable: true`.
- Não converter CSP de Report-Only para enforcement nesta etapa.
- Não executar migrations locais ou usar dados reais.
- Não declarar FitControl pronto para produção.
- Todo merge exige autorização explícita do usuário, mesmo com CI verde.

---

## 7. Próximas ações, em ordem

- [x] **Ação 1:** revisar e fechar a regra explícita do último administrador e a revalidação do ator sob concorrência.
- [x] **Ação 2:** criar testes de banco/concorrência e validar a CI completa, incluindo testes HTTP de status e CSRF.
- [ ] **Ação 3:** inventariar os 189 arquivos da PR #1 por domínio, migration, teste, documentação e arquivo compartilhado.
- [ ] **Ação 4:** construir um grafo de dependências e uma matriz arquivo → bloco → PR candidata.
- [ ] **Ação 5:** confirmar os limites do primeiro conjunto de integração e revisar o procedimento de proteção da `main`.
- [ ] **Ação 6:** criar a primeira branch limpa a partir da `main` e preparar a primeira PR pequena.
- [ ] **Ação 7:** repetir o ciclo de validação, PR e autorização para cada bloco.
- [ ] **Ação 8:** fazer a validação prática local no final, quando o ambiente estiver disponível.

**Ponto exato de retomada:** Ações 1 e 2 concluídas e validadas pela CI #1716. A próxima tarefa é a Ação 3: inventariar os 189 arquivos da PR #1 por domínio, migration, teste, documentação e arquivo compartilhado. HEAD de código validado pela CI #1716: `8ab8315125b790fecffa962b1a05b23be5a14615`. O snapshot documental posterior era `0310e4432429541a1f3bfdeaa216d68464af9a5c`; a CI #1717 para esse commit documental estava na fila na última consulta. A PR #1 permanece Draft. Nenhuma branch nova, regra de proteção, migration local ou integração à `main` foi criada/realizada como parte deste plano.

## 8. Instrução para retomar em outro chat

Ao retomar o trabalho:

1. Ler este documento primeiro.
2. Consultar o estado atual de `main`, `feat/dashboard-portfolio`, PR #1 e Actions; comparar com os SHAs deste registro.
3. Confirmar que a PR #1 continua sem merge e que não houve mudanças externas.
4. Retomar pela primeira ação ainda não concluída na seção 7.
5. Atualizar este documento sempre que uma decisão, estado de branch, CI ou etapa de integração mudar.

Este documento é o registro de continuidade do plano; o estado vivo do GitHub prevalece sobre qualquer SHA ou status antigo aqui anotado.
