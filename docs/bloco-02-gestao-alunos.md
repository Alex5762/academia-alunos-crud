# Bloco 02 — Gestão de alunos

## Escopo desta entrega

Esta etapa estende o CRUD original sem trazer planos como entidade, matrículas, frequência, financeiro, agenda, relatórios ou o dashboard completo da antiga PR #1.

- Situação binária do aluno: ativo/inativo.
- Listagem padrão somente com alunos ativos; filtros Ativos, Inativos e Todos.
- Pesquisa por nome, e-mail ou telefone.
- Totais independentes de ativos, inativos e total cadastrado.
- Inativação e reativação auditadas; nenhum DELETE físico no fluxo de alunos.
- Detalhes básicos e histórico de eventos de criação, edição, inativação e reativação.

## Banco de dados

1. `004_student_status.sql`: adiciona `alunos.ativo TINYINT(1) NOT NULL DEFAULT 1` e índice `(ativo, nome)`. Os alunos já existentes recebem `ativo = 1`, sem reescrita dos outros campos.
2. `015_student_status_constraint.sql`: exige `ativo IN (0, 1)`. Requer MySQL 8.0.16+ ou MariaDB 10.11.

As duas migrations usam o schema `academia_alunos`, devem ser aplicadas uma única vez e não são uma operação de reexecução livre. Antes de aplicar em banco existente, fazer backup verificável e inspecionar a estrutura/dados. Se a migration 015 encontrar valores inválidos, ela deve falhar sem corrigi-los automaticamente; investigar e decidir explicitamente como tratar esses registros.

## Segurança e comportamento

- `alterar-status-aluno.php` aceita somente POST, exige autenticação, permissão `students.status` e token CSRF.
- O endpoint valida ID e ação, bloqueia a linha com `SELECT ... FOR UPDATE`, altera o estado e grava a auditoria na mesma transação.
- Repetir a solicitação para o estado atual é idempotente e não duplica evento.
- `excluir.php` responde HTTP 410 para todos os métodos e não executa DELETE.
- `detalhes-aluno.php` exige autenticação e `students.view`, valida ID e retorna 400/404 para entradas inválidas/inexistentes.
- A edição não recebe nem altera o campo `ativo`; a mudança de situação tem endpoint separado.
- E-mail e telefone não ganham restrição UNIQUE neste bloco.

## Testes automatizados

- `tests/migration-student-status.sh`: insere um aluno legado antes da migration 004, confere preservação de ID/campos e `ativo = 1`, verifica o padrão de novos registros e o índice.
- `tests/migration-student-status-constraint.sh`: testa dados inválidos preexistentes, CHECK, aceitação de 0/1 e rejeição de INSERT/UPDATE inválidos.
- `tests/foundation-http.sh`: cobre GET/CSRF, inativação, reativação, auditoria, detalhes autenticados e aposentadoria da exclusão física.
- A CI acrescenta o job `Block 02 student status` em MySQL 8.0 e MariaDB 10.11.

## Limites e pendências

- A CI usa bancos descartáveis; não aplica migrations no XAMPP/local do responsável.
- Inspeção manual em aparelho físico/navegador instalado continua pendente.
- Não declarar o Bloco 02 concluído antes da revisão do diff, CI verde, integração autorizada e validação local quando o computador estiver disponível.
