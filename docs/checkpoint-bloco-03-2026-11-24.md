# Checkpoint de implementação — Bloco 03: Planos e matrículas

**Data:** 2026-11-24  
**Branch:** `feat/block-03-plans-enrollments`  
**Base:** `main` em `47e9d0cd8126785aa439ed93e981fac71e76532c`  
**Estado:** implementação isolada concluída para revisão; PR #9 em Draft, não integrada.

## Objetivo

Separar o catálogo comercial do texto legado `alunos.plano`, criar histórico de matrículas com snapshots e permitir revisão auditável dos vínculos antigos sem inventar contratos, preços ou vigências.

## Arquivos adicionados nesta branch

### Banco e migrações
- `database/migrations/005_plans_enrollments_schema.sql`
- `database/migrations/006_import_legacy_review.sql`

A migration 005 cria `planos`, `matriculas`, `revisoes_legado`, `controle_importacao_legado` e `revisoes_legado_eventos`. A 006 cria um registro de revisão pendente por aluno existente até o corte inicial de IDs, preserva o texto original e não cria matrículas.

### Serviços de domínio
- `PlanManagementService`
- `EnrollmentCreationService`
- `EnrollmentCancellationService`
- `EnrollmentPeriodCalculator`
- `EnrollmentInterval`
- `EnrollmentStatus`
- `LegacyReviewDecisionService`
- `LegacyEnrollmentRectificationService`

Os serviços usam transações próprias, locks e auditoria pela cadeia já integrada na fundação. O cálculo temporal usa `America/Sao_Paulo` para regras de calendário e persiste instantes em UTC.

### Interfaces e endpoints
- `planos.php`, `salvar-plano.php`, `gerenciar-plano.php`
- `cancelar-matricula.php`
- `revisoes-legado.php`, `salvar-revisao-legado.php`
- `retificar-vigencia-legado.php`, `salvar-retificacao-legado.php`
- atualização de `detalhes-aluno.php` para exibir histórico operacional
- atualização de `index.php` para expor links conforme RBAC

### Testes e CI
- Testes de cálculo temporal, intervalos e estados.
- Testes de catálogo, criação/cancelamento, concorrência, revisão e retificação.
- `tests/migration-block-03.sh` valida migrations 005/006, preservação do texto legado, corte de importação, reexecução e compatibilidade de InnoDB.
- `tests/prepare-block-03-test-database.sh` prepara banco descartável para testes de serviço.
- Workflow ampliado com jobs matriciais para migrations e serviços em MySQL 8.0 e MariaDB 10.11.

## Isolamento do Bloco 05 — Financeiro

A implementação encontrada na antiga PR #1 acoplava a criação de matrícula à criação automática de uma cobrança na tabela `cobrancas`. Esse acoplamento foi removido de `EnrollmentCreationService` nesta extração.

**Contrato atual do Bloco 03:** criar a matrícula, persistir o snapshot e registrar `matricula_criada` atomicamente. Não criar cobrança e não depender de tabelas ou serviços financeiros. A integração de cobrança deverá ocorrer em etapa própria do Bloco 05, com seus testes e transação coordenadora.

O teste `tests/database-enrollment-creation.php` também foi adaptado para não criar nem consultar `cobrancas`.

## Ordem proposta de migrations

`001 → 002 → 003 → 004 → 005 → 006 → 007 → marcador histórico → 008 → 015`.

Essa ordem precisa continuar sincronizada entre README, scripts e CI. A sequência de migrations do Bloco 03 é testada em banco descartável; não foi executada no XAMPP nem em banco real.

## Validação automatizada confirmada

**CI da PR #9:** run [#45482239964](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45482239964), concluído com sucesso em 2026-11-24 — **12/12 jobs aprovados**.

- PHP quality e sintaxe: sucesso.
- Migrations 005/006: MySQL 8.0 e MariaDB 10.11 aprovados.
- Domínio, catálogo, criação/cancelamento, revisão, retificação e concorrência: MySQL 8.0 e MariaDB 10.11 aprovados.
- Integração HTTP da fundação: sucesso.
- Integração HTTP do Bloco 03: catálogo, criação/ativação de plano, contratação, snapshot, auditoria, cancelamento, ausência de dependência financeira e fila de revisão aprovados.
- Regressão do Bloco 02 e proteção Apache: sucesso.

## Pendências antes de retirar a PR do Draft

- [x] Confirmar que todos os arquivos adicionados estão no diff da branch.
- [x] Revisar as páginas e endpoints, incluindo autorização, CSRF, método HTTP e redirecionamentos em CI.
- [x] Testes HTTP para catálogo, contratação, cancelamento e fila de revisão.
- [x] Novos jobs CI em MySQL 8.0 e MariaDB 10.11.
- [x] Testes de banco executados em sequência nos dois motores.
- [ ] Fazer revisão final do diff completo, arquivo a arquivo.
- [ ] Conferir a experiência visual em navegador e dispositivo físico.
- [ ] Quando o computador estiver disponível: backup verificável, inspeção do schema real e smoke test no XAMPP.
- [ ] Atualizar a sequência operacional final após revisão.
- [ ] Não mesclar sem autorização explícita.

## Limites de validação

A CI, quando aprovada, comprovará somente os bancos efêmeros e os navegadores cobertos pelos testes automatizados. Continuam pendentes backup verificável, inspeção do schema/versão/engine e aplicação controlada no XAMPP, além de smoke tests manuais e inspeção física em dispositivo.

A antiga PR #1 permanece Draft e não deve ser mesclada em massa.
