# Inventário da PR #1 — mapa inicial para integração gradual

**Data:** 24/11/2026  
**Repositório:** `Alex5762/academia-alunos-crud`  
**PR:** [#1 — FitControl](https://github.com/Alex5762/academia-alunos-crud/pull/1)  
**Branch:** `feat/dashboard-portfolio`  
**Snapshot de referência:** `a67f7bed09578010cede29ee85d4fba2b40dd5c5`  
**Arquivos listados pelo GitHub:** 189

## 1. Objetivo e limites

Este inventário registra os arquivos que a PR #1 altera e os agrupa por área funcional, infraestrutura, migrations, testes e documentação. Ele é a base para construir o grafo de dependências e a matriz arquivo → bloco → PR candidata.

**Importante:** o agrupamento por nome/caminho é uma classificação inicial. Não prova dependência de código nem autoriza mover arquivos diretamente. Antes de dividir uma PR, inspecionar imports, includes, chamadas, schema, permissões, testes e o diff real.

## 2. Resumo quantitativo

| Grupo de caminho | Quantidade |
|---|---:|
| Arquivos na raiz | 44 |
| `.github/` | 2 |
| `config/` | 2 |
| `database/` | 16 |
| `docs/` | 40 |
| `scripts/` | 12 |
| `src/` | 30 |
| `tests/` | 43 |
| **Total** | **189** |

## 3. Arquivos compartilhados e de alto acoplamento

Estes arquivos merecem revisão especial porque podem ser usados por diversos módulos ou afetar a aplicação inteira:

- `conexao.php`
- `funcoes.php`
- `index.php`
- `style.css`
- `script.js`
- `sessao.js`
- `theme.js`
- `.htaccess`
- `.github/workflows/ci.yml`
- `README.md`
- `docs/roadmap.md`

Outros pontos transversais:
- `src/Security/AccessControl.php`: matriz RBAC consumida pelas rotas e serviços.
- `src/Security/AuditIntegrityChain.php`: escrita atômica da auditoria e dependência comum dos serviços mutáveis.
- `src/Infrastructure/DatabaseConfig.php` e `config/database.local.example.php`: contrato de configuração PDO.
- `database/migrations/001_authentication_audit.sql`, `007_administrative_roles.sql` e `008_audit_integrity_chain.sql`: fundação de contas, permissões e auditoria.
- `tests/integration-crud.sh` e `.github/workflows/ci.yml`: integração de ponta a ponta e orquestração das verificações.

Esses arquivos não devem ser copiados integralmente para várias PRs. É necessário identificar o menor conjunto compartilhado que torna cada camada funcional.

## 4. Arquivos na raiz — classificação inicial

### Aplicação PHP (36)

#### Autenticação, sessões e contas (9)

- `alterar-perfil-usuario.php`
- `alterar-senha.php`
- `alterar-status-usuario.php`
- `login.php`
- `logout.php`
- `renovar-sessao.php`
- `salvar-usuario.php`
- `trocar-senha.php`
- `usuarios.php`

#### Alunos e cadastro (5)

- `alterar-status-aluno.php`
- `cadastro.php`
- `detalhes-aluno.php`
- `excluir.php`
- `salvar.php`

#### Planos, matrículas e legado (8)

- `cancelar-matricula.php`
- `gerenciar-plano.php`
- `planos.php`
- `retificar-vigencia-legado.php`
- `revisoes-legado.php`
- `salvar-plano.php`
- `salvar-retificacao-legado.php`
- `salvar-revisao-legado.php`

#### Frequência/check-in (2)

- `checkins.php`
- `registrar-checkin.php`

#### Financeiro (1)

- `financeiro.php`

#### Aulas/grade (3)

- `aulas.php`
- `gerenciar-horarios.php`
- `gestao-aulas.php`

#### Relatórios (2)

- `exportar-relatorio.php`
- `relatorios.php`

#### Auditoria e configuração (5)

- `auditoria.php`
- `conexao.php`
- `configuracoes.php`
- `detalhes-auditoria.php`
- `funcoes.php`

#### Outros arquivos PHP (1)

- `index.php`

### Assets estáticos (4)

- `script.js`
- `sessao.js`
- `style.css`
- `theme.js`

### Metadados, configuração e arquivos compartilhados (4)

- `.gitignore`
- `.htaccess`
- `LICENSE`
- `README.md`

## 5. Camada de domínio, segurança e infraestrutura

#### `src/.htaccess` (1)

- `src/.htaccess`

#### `src/Domain/Attendance` (1)

- `src/Domain/Attendance/AttendanceCheckinService.php`

#### `src/Domain/Classes` (3)

- `src/Domain/Classes/ClassCatalogService.php`
- `src/Domain/Classes/ClassScheduleQuery.php`
- `src/Domain/Classes/ClassScheduleService.php`

#### `src/Domain/Enrollment` (8)

- `src/Domain/Enrollment/EnrollmentCancellationService.php`
- `src/Domain/Enrollment/EnrollmentCreationService.php`
- `src/Domain/Enrollment/EnrollmentInterval.php`
- `src/Domain/Enrollment/EnrollmentPeriodCalculator.php`
- `src/Domain/Enrollment/EnrollmentStatus.php`
- `src/Domain/Enrollment/LegacyEnrollmentRectificationService.php`
- `src/Domain/Enrollment/LegacyReviewDecisionService.php`
- `src/Domain/Enrollment/PlanManagementService.php`

#### `src/Domain/Finance` (4)

- `src/Domain/Finance/FinanceDashboardStatus.php`
- `src/Domain/Finance/FinanceMoneyFormatter.php`
- `src/Domain/Finance/FinancePaymentHistoryQuery.php`
- `src/Domain/Finance/FinanceService.php`

#### `src/Domain/Reports` (6)

- `src/Domain/Reports/EnrollmentReportQuery.php`
- `src/Domain/Reports/FinanceReportQuery.php`
- `src/Domain/Reports/FrequencyReportQuery.php`
- `src/Domain/Reports/OperationalReportQuery.php`
- `src/Domain/Reports/ReportCsvExporter.php`
- `src/Domain/Reports/ReportPeriod.php`

#### `src/Domain/Users` (1)

- `src/Domain/Users/UserAccountAdministrationService.php`

#### `src/Infrastructure/DatabaseConfig.php` (1)

- `src/Infrastructure/DatabaseConfig.php`

#### `src/Security/AccessControl.php` (1)

- `src/Security/AccessControl.php`

#### `src/Security/AuditIntegrityChain.php` (1)

- `src/Security/AuditIntegrityChain.php`

#### `src/Security/AuditIntegrityCheckpoint.php` (1)

- `src/Security/AuditIntegrityCheckpoint.php`

#### `src/Security/AuditRecoveryDiagnostic.php` (1)

- `src/Security/AuditRecoveryDiagnostic.php`

#### `src/Security/PasswordPolicy.php` (1)

- `src/Security/PasswordPolicy.php`

## 6. Migrations

| Migration | Responsabilidade aparente |
|---|---|
| `database/migrations/001_authentication_audit.sql` | Contas individuais e trilha de auditoria base |
| `database/migrations/002_force_password_change.sql` | Troca obrigatória de senha temporária |
| `database/migrations/003_login_rate_limit.sql` | Limitação persistente de tentativas de login |
| `database/migrations/004_student_status.sql` | Status lógico de alunos |
| `database/migrations/005_plans_enrollments_schema.sql` | Catálogo de planos e matrículas |
| `database/migrations/006_import_legacy_review.sql` | Revisão/importação de vínculos legados |
| `database/migrations/007_administrative_roles.sql` | Perfis administrativos e matriz RBAC |
| `database/migrations/008_audit_integrity_chain.sql` | Cadeia de integridade da auditoria |
| `database/migrations/009_attendance_checkins.sql` | Frequência e check-ins |
| `database/migrations/010_financeiro.sql` | Cobranças e pagamentos |
| `database/migrations/011_pagamento_idempotencia.sql` | Idempotência de pagamentos |
| `database/migrations/012_pagamentos_append_only.sql` | Proteção append-only dos pagamentos |
| `database/migrations/013_financeiro_valores_positivos.sql` | Constraints de valores financeiros positivos |
| `database/migrations/014_grade_fixa_aulas.sql` | Modalidades, instrutores e grade semanal |
| `database/migrations/015_student_status_constraint.sql` | Constraint binária do status de aluno |

### Ordem observada no workflow — ponto crítico

O workflow não aplica necessariamente as migrations em ordem numérica simples em todos os jobs:

- O job de compatibilidade importa `001`–`004`, depois `015`, depois `007` e `008`.
- O job de frequência/integração importa `001`–`004`, depois `015`, depois `005` e `006`, depois `007`, `008` e então `009`–`014`.
- Há jobs específicos que exercitam `005/006` separadamente.

Isso mostra que a numeração, sozinha, não é um grafo suficiente de dependências. Antes de planejar a PR de cada módulo, conferir DDL, FKs, colunas lidas/escritas, dados legados e o motivo de `015` ser aplicada antes de `005`–`014` em determinados jobs. **Não renumerar nem reordenar migrations durante a divisão sem uma decisão técnica explícita e teste de upgrade.**

## 7. Testes — classificação inicial

- `tests/.htaccess`
- `tests/access-control.php`
- `tests/assert-user-provisioned.php`
- `tests/browser-compatibility.mjs`
- `tests/capture-screenshots.mjs`
- `tests/database-attendance-checkin.php`
- `tests/database-audit-checkpoint.php`
- `tests/database-audit-integrity.php`
- `tests/database-class-schedule-concurrency.php`
- `tests/database-class-schedule-migration.php`
- `tests/database-class-schedule-query.php`
- `tests/database-class-schedule-services.php`
- `tests/database-configuration.php`
- `tests/database-enrollment-cancellation.php`
- `tests/database-enrollment-concurrency.php`
- `tests/database-enrollment-creation.php`
- `tests/database-finance.php`
- `tests/database-frequency-reports.php`
- `tests/database-legacy-enrollment-rectification.php`
- `tests/database-legacy-rectification-decision-concurrency.php`
- `tests/database-legacy-review-decision.php`
- `tests/database-legacy-review-rectification-concurrency.php`
- `tests/database-plan-lock-concurrency.php`
- `tests/database-plan-management.php`
- `tests/database-reports.php`
- `tests/database-temporal-compatibility.php`
- `tests/database-user-account-administration.php`
- `tests/enrollment-interval.php`
- `tests/enrollment-period-calculator.php`
- `tests/enrollment-status.php`
- `tests/finance-dashboard-status.php`
- `tests/finance-money-format.php`
- `tests/integration-class-schedule.sh`
- `tests/integration-crud.sh`
- `tests/migration-block-03.sh`
- `tests/migration-student-status-constraint.sh`
- `tests/migration-student-status.sh`
- `tests/password-policy.php`
- `tests/provision-user.sh`
- `tests/report-csv-exporter.php`
- `tests/report-period.php`
- `tests/utc-academy-time-format.php`
- `tests/webroot-protection.sh`

Os testes estão agrupados por responsabilidade aparente:
- autenticação, RBAC, provisionamento e configuração;
- migrations e preservação de dados legados;
- matrículas, planos, intervalos, retificações e concorrência;
- frequência/check-in;
- financeiro e relatórios;
- grade de aulas e concorrência;
- exportação CSV, período de relatório e compatibilidade de navegadores;
- integração HTTP e captura visual.

A associação exata de cada teste a uma PR será confirmada pelo conteúdo e pelas dependências do workflow, não apenas pelo nome.

## 8. Scripts operacionais

- `scripts/.htaccess`
- `scripts/comparar-checkpoints-auditoria.php`
- `scripts/diagnosticar-recuperacao-auditoria.php`
- `scripts/exportar-checkpoint-auditoria.php`
- `scripts/inicializar-integridade-auditoria.php`
- `scripts/listar-checkpoints-auditoria.php`
- `scripts/provisionar-usuario.php`
- `scripts/registrar-eventos-demo.php`
- `scripts/verificar-checkpoint-auditoria.php`
- `scripts/verificar-integridade-auditoria.php`
- `scripts/windows/executar-checkpoint-auditoria.ps1`
- `scripts/windows/instalar-checkpoint-auditoria.ps1`

## 9. Documentação e evidências visuais

- `docs/.htaccess`
- `docs/bloco-01-autenticacao.md`
- `docs/bloco-02-gestao-alunos.md`
- `docs/bloco-03-planos-matriculas.md`
- `docs/bloco-04-frequencia-checkin.md`
- `docs/bloco-05-financeiro.md`
- `docs/bloco-06-aulas-agenda.md`
- `docs/bloco-07-catalogo-indicadores.md`
- `docs/bloco-07-relatorios-experiencia.md`
- `docs/bloco-07.2-contratos-consulta.md`
- `docs/checklist-validacao-manual.md`
- `docs/decisoes/ADR-001-politica-de-historico-git.md`
- `docs/estado-e-plano-integracao-main-2026-11-24.md`
- `docs/guia-migrations-xampp.md`
- `docs/matriz-permissoes.md`
- `docs/modelo-dados-autenticacao-auditoria.md`
- `docs/registro-retomada-2026-11-22.md`
- `docs/relatorio-testes-academia-alunos.md`
- `docs/revisao-financeira-2026-11-22.md`
- `docs/revisao-seguranca-consolidada.md`
- `docs/revisao-tecnica-bloco-07.md`
- `docs/roadmap.md`
- `docs/screenshots/alterar-senha-desktop.png`
- `docs/screenshots/aluno-detalhes-desktop-escuro.png`
- `docs/screenshots/aluno-detalhes-desktop.png`
- `docs/screenshots/aluno-detalhes-mobile-escuro.png`
- `docs/screenshots/aluno-detalhes-mobile.png`
- `docs/screenshots/alunos-mobile.png`
- `docs/screenshots/cadastro-desktop.png`
- `docs/screenshots/configuracoes-escuro.png`
- `docs/screenshots/dashboard-desktop.png`
- `docs/screenshots/dashboard-escuro.png`
- `docs/screenshots/dashboard-mobile.png`
- `docs/screenshots/funcionarios-desktop.png`
- `docs/seguranca-administracao-contas-concorrencia.md`
- `docs/seguranca-configuracao-banco.md`
- `docs/seguranca-csp.md`
- `docs/seguranca-exposicao-webroot.md`
- `docs/seguranca-politica-senhas.md`
- `docs/seguranca-sessoes-cabecalhos.md`

Os arquivos `docs/screenshots/*.png` são evidências visuais versionadas, não código funcional. Devem acompanhar a documentação/fechamento visual correspondente, evitando misturá-los a PRs de migrations ou serviços de domínio sem necessidade.

## 10. PRs candidatas — hipótese para revisão

| PR candidata | Conteúdo principal | Dependências a confirmar |
|---|---|---|
| 1 — Fundação e segurança | PDO/configuração, autenticação, sessões, CSRF, RBAC, usuários, auditoria-base e proteções webroot | Includes compartilhados, migrations 001–004/007/008, testes de autenticação e permissões |
| 2 — Alunos, planos e matrículas | CRUD/status de alunos, catálogo, matrículas, revisão de legados e retificações | Migration 004/015, 005/006, RBAC, auditoria e dependência dos arquivos compartilhados |
| 3 — Frequência | Check-in e consultas de frequência | Migration 009, matrículas vigentes, fuso local, auditoria e RBAC |
| 4 — Financeiro | Cobranças, pagamentos, histórico e indicadores financeiros | Migrations 010–013, matrículas, auditoria, idempotência e permissões financeiras |
| 5 — Grade de aulas | Modalidades, instrutores, horários e consultas | Migration 014, RBAC, auditoria e serviços de domínio |
| 6 — Dashboard, relatórios e CSV | Indicadores, filtros de período, relatórios e exportações | Consultas de alunos/matrículas/frequência/financeiro, permissões e interface compartilhada |
| 7 — Documentação e evidências finais | README, roadmap, checklist e screenshots que não foram entregues com os blocos | Resultado final das PRs anteriores e validação manual reservada para o final |

Esta divisão é **candidata**, não definitiva. Algumas entregas podem precisar ser separadas ou combinadas após análise dos imports e do diff. Por exemplo, os relatórios financeiros dependem da camada financeira; o dashboard depende de consultas de vários módulos; e a fundação de autenticação/RBAC/auditoria atravessa quase todas as rotas.

## 11. Próxima etapa

A Ação 3 — inventário por arquivo — está concluída neste nível de classificação. A próxima etapa é construir o **grafo de dependências**, incluindo:

1. includes/requires entre arquivos PHP;
2. classes de domínio e seus consumidores;
3. permissões RBAC usadas por página/endpoint;
4. tabelas/colunas e FKs exigidas por cada módulo;
5. ordem real das migrations e preservação de dados;
6. testes e jobs de CI que comprovam cada bloco;
7. arquivos compartilhados que precisam de uma entrega de fundação antes dos módulos.

Só depois desse grafo será fechado o limite da primeira PR limpa. Nenhuma branch nova ou integração à `main` é autorizada por este inventário.
