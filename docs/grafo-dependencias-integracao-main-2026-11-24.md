# Grafo de dependências — integração gradual do FitControl à main

**Data:** 24/11/2026  
**PR analisada:** #1 — 190 arquivos no snapshot  
**Branch:** `feat/dashboard-portfolio`  
**Snapshot analisado:** `2bc92c0e9005b6419766bc1255d734b9032b5224`

## 1. Objetivo

Este mapa registra dependências observadas entre páginas PHP, serviços de domínio, RBAC, tabelas e migrations. Foi construído pela leitura de imports/includes, permissões, chamadas de serviços, referências SQL e jobs da CI. É uma base de planejamento, não uma autorização para transportar arquivos sem revisar seus patches.

Inventário completo: [`docs/inventario-pr-1-integracao-main-2026-11-24.md`](inventario-pr-1-integracao-main-2026-11-24.md).

## 2. Fundação compartilhada

A maioria das páginas importa `funcoes.php` e `conexao.php`:

- `conexao.php` carrega `DatabaseConfig` e instancia PDO.
- `funcoes.php` centraliza sessão, autenticação, CSRF, autorização e funções comuns.
- `AccessControl` fornece a matriz RBAC.
- `AuditIntegrityChain` grava auditoria encadeada dentro da transação do domínio.

**Implicação:** configuração, autenticação, RBAC, CSRF e auditoria precisam entrar antes dos serviços mutáveis dos módulos. As migrations 001/002/003/007/008 e a inicialização do baseline formam parte da fundação.

## 3. Dependências de serviços e páginas

| Área | Serviços/arquivos principais | Dependências importantes |
|---|---|---|
| Usuários | `UserAccountAdministrationService`, `usuarios.php`, `salvar-usuario.php`, `alterar-perfil-usuario.php`, `alterar-status-usuario.php` | `AccessControl`, `AuditIntegrityChain`, tabelas `usuarios` e `usuarios_perfis_eventos`, permissão `users.manage` |
| Alunos | `cadastro.php`, `detalhes-aluno.php`, `alterar-status-aluno.php` | tabela `alunos`, RBAC `students.*`, helpers compartilhados |
| Planos/matrículas | `PlanManagementService`, `EnrollmentCreationService`, `EnrollmentCancellationService`, serviços de legado | `alunos`, `planos`, `revisoes_legado`, `matriculas`, `AuditIntegrityChain` |
| Frequência | `AttendanceCheckinService`, `checkins.php`, `registrar-checkin.php` | `alunos`, `matriculas`, `usuarios`, `checkins`, auditoria |
| Financeiro | `FinanceService`, `FinanceDashboardStatus`, `FinancePaymentHistoryQuery`, `FinanceMoneyFormatter`, `financeiro.php` | `cobrancas`, `pagamentos`, `matriculas`, `usuarios`, `EnrollmentPeriodCalculator`, auditoria |
| Grade de aulas | `ClassCatalogService`, `ClassScheduleService`, `ClassScheduleQuery`, páginas de aulas | `modalidades`, `instrutores`, `horarios_aula`, `usuarios`, auditoria |
| Relatórios | consultas OP/MT/FR/FN, `relatorios.php`, `exportar-relatorio.php`, `index.php` | dados de alunos, matrículas, check-ins, cobranças e pagamentos |

### Dependência crítica encontrada

`EnrollmentCreationService` cria a matrícula e, na mesma transação, insere uma cobrança em `cobrancas`. Essa tabela é criada pela migration `010_financeiro.sql`.

Portanto, a PR de planos/matrículas **precisa incluir o schema 010** ou o serviço precisará ser refatorado preservando a cobrança automática e a atomicidade. Dividir simplesmente Bloco 03 e Bloco 05 em PRs independentes não funciona com o acoplamento atual.

Decisão candidata:
- Levar `010_financeiro.sql` junto da entrega de planos/matrículas como schema mínimo necessário à cobrança automática.
- Deixar `FinanceService`, a interface financeira e as migrations `011`–`013` para a PR financeira posterior.
- Revisar o patch 010 e os testes de criação automática de cobrança antes de confirmar.

## 4. Dependências das migrations

| Migration | Pré-requisito/consumidor |
|---|---|
| 001 — autenticação/auditoria base | schema original; cria `usuarios` e `auditoria` |
| 002 — troca de senha | 001 / `usuarios` |
| 003 — rate limit | infraestrutura de autenticação |
| 004 — status de aluno | tabela original `alunos` |
| 015 — constraint de status | 004 / `alunos.ativo` |
| 005 — planos/matrículas/revisões | 001 e 004; cria tabelas usadas por matrícula, check-in e cobrança |
| 006 — importação/revisão legada | 005 / tabelas de revisão |
| 007 — perfis administrativos | 001 / `usuarios`; cria histórico de perfil |
| 008 — cadeia de auditoria | 001 / `auditoria`; exige evento baseline anterior e inicialização CLI |
| 009 — check-ins | 004, 005 e 001 |
| 010 — cobranças/pagamentos | 005 / `matriculas` e 001 / `usuarios` |
| 011 — idempotência | 010 / `pagamentos` |
| 012 — append-only | 010 / `pagamentos` |
| 013 — valores positivos | 010 / `cobrancas` e `pagamentos` |
| 014 — grade de aulas | 001 / `usuarios` |

### Ordem observada na CI

O workflow aplica `015` depois de `004` e antes de `005`–`014` em alguns jobs. A sequência completa observada no job de frequência é:

`001 → 002 → 003 → 004 → 015 → 005 → 006 → 007 → 008 → 009 → 010 → 011 → 012 → 013 → 014`.

O job de compatibilidade de autenticação usa `001 → 002 → 003 → 004 → 015 → 007 → evento baseline → 008`. Os jobs de `005/006` também rodam separadamente.

**Não presumir ordem numérica simples.** Confirmar DDL, FKs, importação legada, triggers e inicialização da cadeia de auditoria antes de qualquer mudança de sequência. Não renumerar migrations existentes.

## 5. Dashboard, relatórios e auditoria

As consultas de relatórios dependem dos módulos que fornecem seus dados. `index.php`, `relatorios.php` e `exportar-relatorio.php` importam essas consultas. Portanto, dashboard/relatórios/CSV devem vir depois de alunos, matrículas, frequência e financeiro, ou ser divididos em entregas que tenham as dependências já integradas.

Os serviços de matrícula, frequência, financeiro, aulas e usuários chamam `AuditIntegrityChain`; a migration 008 e a inicialização do baseline precisam estar disponíveis antes deles.

## 6. Implicações para CI e testes

O workflow atual valida o projeto completo. Uma PR limpa a partir da `main` ainda não terá todos os módulos/migrations; copiar o workflow atual sem adaptação fará a primeira PR executar testes de arquivos ausentes.

A integração gradual exige:
1. A PR de fundação deixar a CI executável com schema, autenticação, RBAC, auditoria e testes da base.
2. Cada PR de módulo acrescentar seus testes e jobs de migration/compatibilidade.
3. O monolítico `tests/integration-crud.sh`, que percorre múltiplos módulos, ser separado por etapa ou reorganizado para executar somente fluxos já integrados.
4. Testes cross-browser/capturas serem acrescentados junto às páginas correspondentes; a captura global do dashboard/relatórios fica para a camada final.
5. MySQL 8.0 e MariaDB 10.11 continuarem cobrindo migrations e operações de cada etapa.

Não reduzir a CI para obter um verde artificial: cada PR deve manter regressão suficiente para tudo que já entrou na `main`.

## 7. Sequência candidata revisada

| Ordem | PR candidata | Conteúdo |
|---|---|---|
| 1 | Fundação e segurança | PDO, sessão/autenticação, CSRF, RBAC, contas, auditoria 001–003/007/008, proteções webroot, provisionamento e CI-base |
| 2 | Alunos, planos e matrículas | migrations 004/015/005/006, páginas/serviços de alunos, planos, matrículas e legados, mais schema 010 necessário à cobrança automática |
| 3 | Frequência | migration 009, check-ins, serviço e testes |
| 4 | Financeiro | FinanceService, painel/histórico financeiro e migrations 011–013; schema 010 já entrou com matrículas |
| 5 | Grade de aulas | migration 014, modalidades, instrutores, horários e testes |
| 6 | Dashboard, relatórios e CSV | consultas OP/MT/FR/FN, cartões, `index.php`, relatórios, CSV, acessibilidade e cross-browser |
| 7 | Fechamento documental | somente documentação/evidências ainda não entregues junto dos módulos |

Essa sequência continua candidata até revisão dos patches e da `main`. A PR #1 permanece como referência; não fazer merge integral nem transportar commits em massa.

## 8. Próxima ação

A Ação 4 — grafo inicial de dependências — está concluída. A próxima etapa é a Ação 5: confirmar os limites da primeira PR limpa, preparar a CI incremental e revisar as regras de proteção da `main`. Ainda não criar branch nem alterar a `main` até aprovarmos esse escopo.
