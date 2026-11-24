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


## Atualização da revisão final — 2026-11-24

### Correções de integridade e validação

- A confirmação/reconfirmação de vínculo LEGADO compara os campos opcionais comprovados com o snapshot existente. Campos omitidos preservam o histórico; valores divergentes são rejeitados. Retificação continua sendo fluxo separado.
- Testes de regressão cobrem tentativa de introduzir preço divergente tanto na repetição idempotente quanto na reconfirmação após reabertura.
- `EnrollmentCancellationService` valida no domínio motivo de cancelamento UTF-8 válido e com até 255 caracteres, alinhado à migration. Teste usa coluna `VARCHAR(255)` e cobre motivo acima do limite.
- `LegacyReviewDecisionService` valida observações UTF-8 e limite de 65.535 bytes do campo TEXT. Testes cobrem valor acima do limite e UTF-8 inválido.
- A regra `LEGADO_SEM_VIGENCIA` foi mantida: significa ausência de matrícula LEGADO efetivamente vigente no instante da decisão; uma matrícula futura não é vigente ainda. A interface já descreve a decisão como “Sem contrato vigente”.

### Permissões nas interfaces

- `detalhes-aluno.php`: ações de criar e cancelar matrícula são exibidas somente quando o usuário possui `enrollments.create` e `enrollments.cancel`, respectivamente.
- `revisoes-legado.php`: formulário de decisão depende de `legacy.review`; link de retificação depende de `legacy.rectify`. A autorização nos endpoints permanece obrigatória.

### Commits desta rodada

- `ae16480` — proteger snapshots históricos em reconfirmações legadas.
- `c536a7c` — testes de divergência do snapshot.
- `584cb19` — validar motivo de cancelamento no domínio.
- `baf976e` — teste do limite do motivo.
- `611bcd7` — alinhar ações de matrícula às permissões visuais.
- `6d68ee4` — ocultar ações de revisão sem permissão.
- `10b5c3b` — validar tamanho e UTF-8 das observações legadas.
- `66f827a` — testes de limite e UTF-8 das observações.
- `400e1be` — ocultar o formulário de nova matrícula quando o usuário acessa manualmente a URL sem `enrollments.create`.

### CI e estado da PR

- A execução `45484910188`, no commit `baf976e`, concluiu com 12/12 jobs aprovados.
- Após os ajustes de interface e observações, a execução `45485885670` foi criada para o commit `66f827a` e está pendente/iniciando. Não considerar as últimas alterações validadas até todos os 12 jobs concluírem com sucesso.
- PR #9 continua aberta em Draft, base `main`, sem merge.
- Último commit conhecido: `400e1bacea73469c118cc99b72a744efcb117d53`.
- A execução `45485962808` foi iniciada para o checkpoint `5c61d27`, anterior ao último ajuste de permissão; não a considerar validação final do commit atual. Aguardar nova execução associada ao HEAD atualizado.

### Próximos passos

1. Aguardar e conferir os 12 jobs da CI no commit `66f827a`.
2. Concluir a revisão do diff completo, arquivo por arquivo, com foco em interfaces, endpoints e testes HTTP.
3. Atualizar descrição/checklist da PR com o resultado final.
4. Manter inspeção visual, backup, schema real e smoke test no XAMPP pendentes até acesso ao computador.
5. Não retirar a PR do Draft nem mesclar sem revisão completa e autorização explícita.


---

## ## Atualização de continuidade — CI final e RBAC HTTP — 2026-11-24

### Estado confirmado no GitHub

- HEAD da PR #9: `b5d56288ff3815f329c477da5e07288a0e541d06`.
- PR #9: aberta, Draft, base `main`, sem merge.
- A execução `45488596825`, associada exatamente ao HEAD acima, concluiu com sucesso: **12/12 jobs aprovados**.
- A execução anterior `45487776063`, no commit `1fd6d17`, também concluiu com 12/12.
- A falha anterior `45486063860` ocorreu porque o teste de UTF-8 inválido enviava a string literal `\\xB1`; o caso foi corrigido para `chr(0xB1)`. A nova CI aprovou o caso corrigido nos dois bancos.

### Cobertura HTTP de RBAC ampliada

- Commit `47f6638`: `tests/foundation-http.sh` passou a autenticar um perfil OPERADOR e verificar que ele pode consultar planos e criar/cancelar matrículas.
- O mesmo cenário verifica HTTP 403 para criar/alterar planos e para visualizar/decidir/abrir retificação de vínculos legados.
- O teste verifica que as tentativas negadas não alteram o catálogo nem a situação do plano.
- Commit `b5d5628`: acrescenta também tentativa POST ao endpoint `salvar-retificacao-legado.php`, que deve retornar HTTP 403 para OPERADOR.
- A execução `45488596825` aprovou a integração HTTP do Bloco 03 com o cenário ampliado.

### Revisão estática adicional

- Foram inspecionados os fluxos de formulário e apresentação de `planos.php`, `detalhes-aluno.php`, `revisoes-legado.php` e `retificar-vigencia-legado.php`, além dos serviços de catálogo, criação, cancelamento, revisão e retificação.
- Saídas de dados de alunos, planos, fontes e observações são escapadas na renderização HTML.
- Os endpoints de mutação revisados exigem POST, autenticação, permissão RBAC e CSRF.
- Não foi identificado novo blocker nesta passada estática.
- A revisão completa de todos os 39 arquivos ainda não está encerrada; não marcar a PR como pronta para merge somente com base nesta amostra.

### Pendências preservadas

1. Terminar a leitura integral do diff dos 39 arquivos e reconciliar o checklist/descrição da PR.
2. Inspeção visual em navegador e dispositivo físico.
3. No computador/XAMPP: backup verificável, inspeção do schema real e engine das tabelas, execução controlada das migrations e smoke test.
4. Manter a PR em Draft e não mesclar sem autorização explícita.

**Link da CI aprovada no HEAD atual:** https://github.com/Alex5762/academia-alunos-crud/actions/runs/45488596825
