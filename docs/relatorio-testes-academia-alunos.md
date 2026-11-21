# Relatório de testes — FitControl

**Responsável:** Joseph Alexsander  
**Repositório:** [Alex5762/academia-alunos-crud](https://github.com/Alex5762/academia-alunos-crud)

Este documento separa os testes manuais realizados em 11/11/2026 da validação automatizada realizada em 20/11/2026.

## 1. Objetivo

Registrar os testes do CRUD de alunos, validação dos dados, persistência no MySQL e verificações automatizadas da interface.

## 2. Testes manuais — 11/11/2026

**Ambiente:** Windows, XAMPP, Apache 2.4.58, PHP 8.2.12, MySQL, phpMyAdmin e Visual Studio Code.

O projeto foi colocado em `C:\\xampp\\htdocs\\academia-alunos-crud`, com Apache e MySQL iniciados pelo XAMPP. Após a importação de `banco.sql`, a estrutura foi conferida no phpMyAdmin.

Estrutura confirmada:
- Banco: `academia_alunos`.
- Tabela: `alunos`.
- Colunas: `id` (INT UNSIGNED, PK, AUTO_INCREMENT), `nome` (VARCHAR 100), `telefone` (VARCHAR 20), `email` (VARCHAR 150) e `plano` (VARCHAR 40).

| Operação | Resultado observado |
|---|---|
| Create — cadastrar aluno | Aprovado; mensagem de sucesso e ID gerado |
| Read — listar alunos | Aprovado |
| Read — pesquisar por nome | Aprovado; “João” encontrou o registro e “Ana” não retornou resultado |
| Update — editar aluno | Aprovado conforme teste manual informado |
| Delete — confirmar e excluir | Aprovado |
| Conferir exclusão no MySQL | Aprovado; consulta retornou zero registros |

## 3. Integração HTTP automatizada — 20/11/2026

**Ambiente:** GitHub Actions (Ubuntu), PHP 8.2 com `pdo_mysql`, MySQL 8.0, Node.js 24, Playwright 1.65.1, Chromium e servidor embutido do PHP.

Script: `tests/integration-crud.sh`.

### 3.1 Cenários executados

| Cenário | Verificação | Resultado |
|---|---|---|
| CSRF | Token inválido retorna HTTP 403 | Aprovado |
| E-mail inválido | Rejeição no servidor e preservação dos dados | Aprovado |
| Telefone inválido | Rejeição de letras e quantidade insuficiente de dígitos | Aprovado |
| Create | Cadastro via `salvar.php` e geração de ID | Aprovado |
| Read | Pesquisa via `index.php` | Aprovado |
| Update | Edição via `salvar.php` e conferência no MySQL | Aprovado |
| Confirmação de exclusão | GET exibe confirmação e mantém o registro | Aprovado |
| Delete | POST remove o registro e o MySQL confirma | Aprovado |
| Método HTTP | GET em `salvar.php` retorna HTTP 405 | Aprovado |

**Total: nove verificações funcionais aprovadas.** O script possui etapas numeradas de 1/8 a 8/8; algumas etapas agrupam mais de uma asserção. A última etapa apenas encerra e resume a execução, não sendo um cenário adicional.

### 3.2 Execuções

- [Run 44779978743 — execução por push](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44779978743): job de integração/captura concluído com sucesso; job de publicação das capturas também concluído com sucesso.
- [Run 44779990730 — execução da pull request](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44779990730): job de integração/captura concluído com sucesso; publicação das capturas ignorada pela condição do workflow, que só publica em push para a branch de desenvolvimento.

Commit testado pelo job: [`6821128671ed8b1a4aa2db0c0fa10f763840f35e`](https://github.com/Alex5762/academia-alunos-crud/commit/6821128671ed8b1a4aa2db0c0fa10f763840f35e). As capturas versionadas foram atualizadas pelo commit automatizado [`daed384d89585b8fe52b43d4912534e1dcf8e87f`](https://github.com/Alex5762/academia-alunos-crud/commit/daed384d89585b8fe52b43d4912534e1dcf8e87f).

## 4. Verificações automatizadas de interface

Script: `tests/capture-screenshots.mjs`.

Foram geradas seis capturas:
- `dashboard-desktop.png`
- `dashboard-mobile.png`
- `alunos-mobile.png`
- `cadastro-desktop.png`
- `configuracoes-escuro.png`
- `dashboard-escuro.png`

O Playwright verifica a presença de oito registros fictícios, ausência de rolagem horizontal inesperada no documento desktop/mobile, rolagem interna da tabela no mobile, carregamento do formulário e configurações, aplicação e persistência do tema escuro e ausência de erros JavaScript de página.

Os registros são inseridos apenas no banco efêmero do CI. As capturas e verificações de viewport não equivalem a uma avaliação visual humana completa, não comprovam acessibilidade por si só e não substituem testes em dispositivos físicos ou navegadores diferentes.

## 5. Pendências de revisão manual

1. Conferir em celular real se a rolagem horizontal interna da tabela é fácil de perceber e usar.
2. Revisar contraste e legibilidade dos textos menores nos temas claro e escuro.
3. Conferir navegação por teclado, foco visível e mensagens de campos obrigatórios vazios.
4. Repetir a inspeção visual em outro navegador, se disponível.

## 6. Autenticação, contas, auditoria e sessões — 20/11/2026

**Commit validado:** [\`7ff2a9da84a20e0fe160d43fd9f30b681e0023d6\`](https://github.com/Alex5762/academia-alunos-crud/commit/7ff2a9da84a20e0fe160d43fd9f30b681e0023d6).

Execuções do commit:
- [Run 44855484995 — push, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44855484995): integração, captura visual e publicação das capturas concluídas.
- [Run 44855491003 — pull request, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44855491003): integração e captura visual concluídas; o job de publicação é ignorado em eventos de PR conforme a condição do workflow.

### 6.1 Cenários cobertos pela CI

| Área | Verificações | Resultado |
|---|---|---|
| Preparação | Sintaxe PHP, arquivos obrigatórios, schema MySQL e migrações 001/002/003 | Aprovado |
| Provisionamento | Conta fictícia, hash, auditoria e e-mail duplicado | Aprovado |
| Login | Credenciais inválidas/válidas, conta inativa, troca obrigatória da senha temporária e rejeição da senha antiga | Aprovado |
| Limite de login | Quatro falhas, bloqueio na quinta, manutenção do bloqueio e limpeza após login válido | Aprovado |
| Gestão de funcionários | Criação, desativação, reativação, auditoria e bloqueio de autodesativação | Aprovado |
| CRUD e auditoria | Cadastro, pesquisa, edição, exclusão e autoria dos eventos | Aprovado |
| Sessão | Revogação após desativação, logout, expiração aos 30 minutos e limite absoluto de 8 horas | Aprovado |
| Renovação | Endpoint autenticado, CSRF inválido (403), método GET (405) e continuidade após renovação | Aprovado |
| Interface | Capturas desktop/mobile, temas, erros JavaScript e avisos de expiração via Playwright | Aprovado |

### 6.2 Avisos de sessão validados

O Playwright avança o relógio do navegador para confirmar que o aviso por inatividade aparece aos 25 minutos e que o aviso do limite absoluto aparece antes das 8 horas. O teste também aciona **Continuar conectado** e verifica que o aviso de inatividade é fechado após a renovação autenticada.

Esses testes automatizados validam os cenários programados. Eles não substituem inspeção humana da usabilidade, contraste, foco e interação em dispositivos reais.

## 7. Limites atuais do sistema

- Todas as contas ativas possuem a mesma permissão administrativa; não existe separação por cargo.
- Não há MFA nem recuperação de senha por e-mail.
- A tabela \`alunos\` ainda guarda o plano como texto; não existe relação com uma tabela própria de planos.
- Não existem módulos de matrícula/contrato, pagamentos, frequência/check-in, aulas, agenda ou relatórios operacionais.
- O projeto permanece acadêmico/local. A CI não comprova prontidão para produção, uso público ou tratamento de dados reais.

Antes de qualquer uso em produção, ainda são necessários revisão independente de segurança, HTTPS no ambiente de hospedagem, gestão de segredos, backups com teste de restauração, monitoramento, revisão de privacidade/LGPD e inspeção visual/acessibilidade.

## 8. Conclusão

Em 20/11/2026, os workflows de push e pull request do commit \`7ff2a9da84a20e0fe160d43fd9f30b681e0023d6\` concluíram com sucesso. A validação automatizada cobriu o CRUD, autenticação, gestão de funcionários, auditoria, limite de tentativas, expiração/renovação de sessão e verificações de interface.

**Estado:** a implementação e a CI estão aprovadas. A inspeção visual manual em navegador/dispositivo real continua pendente antes de considerar o Bloco 00 totalmente revisado. As limitações de segurança para produção permanecem explícitas e fora do escopo desta etapa.

---

**Autor:** Joseph Alexsander  
**Projeto:** FitControl — Gestão de alunos de academia


## 9. Alteração voluntária de senha e revisão visual — 21/11/2026

**Commit testado:** `72b43761378a1a5a8c2b3bd2a5ccb335207f7109`.

- [Run 44859058017 — push, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44859058017).
- [Run 44859062182 — pull request, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44859062182).

A alteração voluntária de senha foi validada com senha atual incorreta, senha curta, confirmação divergente, tentativa de reutilizar a senha atual, alteração válida, registro de auditoria, rejeição da senha antiga e autenticação com a nova senha.

O Playwright também verificou a navegação mobile por foco sequencial com Tab e rolagem automática até o último atalho, além de abrir a tela de alteração de senha e a página de funcionários. As capturas atualizadas foram publicadas no artefato da CI e sincronizadas na branch pelo commit automatizado `841983e1ff4aa9d9a4c01e2b0709269ef0ca1a11`.

A inspeção das capturas identificou e motivou ajustes de contraste dos rótulos dos gráficos no tema escuro e de indicação/foco da navegação horizontal mobile. A inspeção física em dispositivo real e em navegador adicional ainda deve ser realizada.

## 10. CI após a especificação do Bloco 02 — 21/11/2026

Após a inclusão do escopo detalhado do Bloco 02 e a atualização do roadmap, as execuções mais recentes da CI concluíram com sucesso:

- [Run 44862156886 — push #225, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44862156886): integração/captura e publicação das capturas concluídas com sucesso.
- [Run 44862160513 — PR #226, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44862160513): integração/captura concluída com sucesso; publicação ignorada conforme esperado para evento de pull request.

Essas execuções validam que as alterações documentais não quebraram o pipeline. O Bloco 02 permanece especificado, mas ainda não implementado; portanto, essas execuções não representam testes de status, inativação ou reativação de alunos.

A pendência de revisão manual permanece: inspeção em celular real e em navegador adicional, com atenção à rolagem interna da tabela, contraste/legibilidade nos temas claro e escuro, foco por teclado e mensagens de campos obrigatórios vazios. Não marcar essa etapa como concluída com base apenas nas capturas e testes automatizados.

## 11. Verificações multibrowser automatizadas — 21/11/2026

Foi adicionado o script `tests/browser-compatibility.mjs`, executado pela CI com Chromium, Firefox e WebKit (Playwright 1.65.1).

O teste percorre os fluxos autenticados em cada motor e verifica:
- abertura do login e dashboard;
- presença dos registros fictícios no dashboard;
- ausência de overflow horizontal global em desktop (1440px), mobile (390px) e mobile estreito (320px);
- existência de rolagem interna na tabela e na navegação mobile;
- alcance do último atalho da navegação por teclado e rolagem automática correspondente;
- validação nativa dos campos obrigatórios vazios (nome, telefone e e-mail);
- foco visível no primeiro campo do formulário após navegação por Tab;
- abertura das configurações, aplicação do tema escuro e persistência ao voltar ao dashboard;
- ausência de erros JavaScript não tratados.

Esta ampliação testa três motores de navegador em emulação de viewport. Ela não representa teste em aparelhos físicos, não valida a sensação do gesto de toque, não equivale a Safari/iOS ou Chrome/Android instalados no aparelho e não substitui inspeção humana de contraste, legibilidade, leitores de tela e acessibilidade completa.

**Resultado:** aprovado em 21/11/2026. [Run 44864733253 — push #245, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44864733253) e [Run 44864727119 — PR #244, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44864727119). Os logs do push confirmam explicitamente `Chromium: OK`, `Firefox: OK`, `WebKit: OK` e `Compatibilidade de navegadores: OK`. A aprovação refere-se aos testes automatizados em motores de navegador com viewports simulados; não substitui a inspeção em aparelhos físicos.

## 12. Início do Bloco 02 — migração de situação dos alunos — 21/11/2026

Foram adicionados à branch de desenvolvimento:

- `database/migrations/004_student_status.sql`: cria `alunos.ativo TINYINT(1) NOT NULL DEFAULT 1` e o índice `idx_alunos_ativo_nome`.
- `tests/migration-student-status.sh`: insere um aluno legado antes da migração, aplica a migração 004, verifica preservação do ID e dos campos cadastrais, confirma que o registro legado recebe `ativo = 1`, insere um novo aluno sem informar o status e verifica o valor padrão, além de conferir a criação do índice.
- `.github/workflows/ci.yml`: passa a exigir os dois arquivos e executar o teste da migração após as migrações 001–003 e antes do provisionamento do usuário de CI. Também foram removidas repetições acidentais na instalação dos navegadores e na execução do teste multibrowser.
- README, roadmap e especificação do Bloco 02 atualizados para refletir a etapa em andamento.

**Resultado da migração:** pendente de execução e inspeção da CI. Nenhuma tela, endpoint de alteração de status, consulta do dashboard ou fluxo de exclusão foi modificado nesta etapa. A aplicação ainda mantém o comportamento anterior até a implementação das próximas etapas.

## 13. Bloco 02 — endpoint e ações de status dos alunos — 21/11/2026

Após a aprovação da migração 004 nas execuções de push #268 e PR #269, foram implementados na branch:

- `alterar-status-aluno.php`: endpoint exclusivo para POST, com autenticação, CSRF, validação de ID/ação, bloqueio da linha (`SELECT ... FOR UPDATE`), atualização condicional e auditoria na mesma transação. Repetir uma solicitação que já corresponde ao status atual não cria evento duplicado.
- `index.php`: substituição da ação de exclusão por formulários de inativação/reativação, exibição do status e filtro inicial de ativos.
- `excluir.php`: aposentado com resposta HTTP 410, sem executar consultas ou comandos de exclusão.
- `tests/integration-crud.sh`: cenários para método GET (405), CSRF inválido (403), ação inválida (400), inativação, reativação, idempotência, auditoria e preservação do cadastro após chamadas ao endpoint antigo.
- `style.css` e workflow CI atualizados.

A pesquisa por nome/e-mail/telefone e o filtro Ativos/Inativos/Todos já foram incluídos na consulta da listagem nesta mesma implementação; os indicadores agora contam ativos/inativos separadamente e a distribuição de planos considera somente ativos.

**Resultado:** código implementado; aguarda as execuções de CI do novo commit. Os cenários adicionados incluem também pesquisa por e-mail/telefone e filtros Ativos/Inativos/Todos. A preservação dos filtros durante edição permanece pendente. Não marcar as etapas como aprovadas antes de verificar os logs de push e pull request.

## 14. Bloco 02 — gestão, perfil e histórico de alunos — 21/11/2026

**Commit de código/testes validado:** [3105b550e5e95f1f0737bfc20b04d5c0cc4a385f](https://github.com/Alex5762/academia-alunos-crud/commit/3105b550e5e95f1f0737bfc20b04d5c0cc4a385f).

**CI:** [Run 44876515363 — CI #359, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44876515363). O job principal de PHP, MySQL, integração HTTP e capturas visuais terminou com sucesso. A publicação de capturas na branch foi realizada pelo workflow de push.

### 14.1 Cenários do Bloco 02 validados

| Área | Cenários cobertos | Resultado |
|---|---|---|
| Migração 004 | Aluno legado preservado com ID/dados, status ativo padrão, novo aluno ativo por padrão e índice composto | Aprovado |
| Inativação/reativação | POST obrigatório, GET 405, CSRF inválido 403, ação inválida 400, persistência do status e transação | Aprovado |
| Idempotência | Repetir uma mudança para estado já existente sem duplicar auditoria | Aprovado |
| Exclusão física | Endpoint legado retorna HTTP 410 e mantém o registro | Aprovado |
| Pesquisa e filtros | Busca por nome, e-mail e telefone; filtros Ativos, Inativos e Todos | Aprovado |
| Contexto da listagem | Preservação de busca/filtro ao editar, salvar, voltar e alterar status | Aprovado |
| Perfil individual | Acesso anônimo, IDs inválidos/inexistentes e dados atuais | Aprovado |
| Histórico | Criação, edição, inativação e reativação, com data/responsável disponível | Aprovado |
| Ações no perfil | Inativar e reativar a partir da tela de detalhes | Aprovado |
| Capturas Playwright | Perfil individual em desktop e mobile, com verificação de overflow horizontal mobile | Aprovado |

### 14.2 Artefatos visuais

As capturas geradas pelo workflow e versionadas na branch incluem:
- `docs/screenshots/aluno-detalhes-desktop.png`
- `docs/screenshots/aluno-detalhes-mobile.png`

Elas foram publicadas pelo commit automatizado [1fa3be6b55a07965399be905a9b161e16365f272](https://github.com/Alex5762/academia-alunos-crud/commit/1fa3be6b55a07965399be905a9b161e16365f272).

As capturas e verificações Playwright demonstram que os fluxos programados foram executados. Não equivalem a inspeção visual humana completa nem a teste em aparelho físico.

### 14.3 Estado após a execução

**Resultado automatizado: aprovado.** Migração, fluxos de status, filtros, perfil, histórico e regressão cobertos pelo workflow passaram. A PR #1 permanece aberta e a branch de desenvolvimento continua separada da `main`.

**Pendências manuais:** revisar legibilidade, contraste, estados vazios, hierarquia visual, foco por teclado e interação em aparelho físico/navegador adicional. Não declarar o Bloco 02 totalmente encerrado até essa revisão e aprovação explícita.
## 15. Galeria do perfil do aluno — temas claro/escuro — 21/11/2026

**Execuções adicionais:**
- [Run 44877723150 — CI #373, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44877723150): regressão e captura automatizada concluídas com sucesso.
- [Run 44877943853 — CI #375, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44877943853): regressão, preparação do banco, capturas e verificações Playwright concluídas com sucesso.

O workflow prepara quatro eventos fictícios de auditoria para o registro de demonstração e exige que os rótulos visíveis estejam presentes antes de capturar as imagens. O tema escuro é aplicado e sua persistência é verificada ao navegar do dashboard para o perfil.

Capturas geradas e versionadas na branch:
- `docs/screenshots/aluno-detalhes-desktop.png` — tema claro, desktop.
- `docs/screenshots/aluno-detalhes-mobile.png` — tema claro, mobile.
- `docs/screenshots/aluno-detalhes-desktop-escuro.png` — tema escuro, desktop.
- `docs/screenshots/aluno-detalhes-mobile-escuro.png` — tema escuro, mobile.

**Resultado da revisão das imagens:** as quatro capturas exibem os quatro eventos em ordem cronológica inversa, com data/hora e funcionário responsável. O layout mobile organiza os dados em uma coluna e apresenta os botões em largura integral. Não foi observado overflow horizontal nas verificações automatizadas. A inspeção visual das capturas foi realizada; ainda não houve teste em aparelho físico.

**Pendência final:** inspecionar em celular real e navegador instalado, com atenção a toque, legibilidade, zoom, contraste percebido e tecnologias assistivas. A aprovação Playwright não substitui essa etapa.