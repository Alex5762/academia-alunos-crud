# Checkpoint pós-merge — Bloco 02: Gestão de alunos

**Data:** 2026-11-24  
**PR integrada:** [#7 — feat: integrar gestão de alunos com status e auditoria](https://github.com/Alex5762/academia-alunos-crud/pull/7)  
**Commit de merge:** `47e9d0cd8126785aa439ed93e981fac71e76532c`  
**Base anterior:** `1d6df18c0209557bd735912aba25a5c601fcddaf`

## Estado confirmado do GitHub

- PR #7 está fechada e mesclada.
- A branch `main` foi consultada após o merge e aponta para `47e9d0cd8126785aa439ed93e981fac71e76532c`.
- CI da PR antes do merge: 7/7 checks concluídos com sucesso no HEAD `8ac769b6a3ec5b6f761176110e13a3ea41da6da3`.
- CI pós-merge: 7/7 checks concluídos com sucesso no run [#45476888464](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45476888464).
- Não há PRs de implementação novas abertas. A única PR aberta é a antiga #1, ainda Draft e não mergeável.

## Entregas integradas

- Migration 004: coluna `alunos.ativo`, padrão 1, com índice para situação e nome.
- Migration 015: restrição CHECK para aceitar somente 0 ou 1; no fluxo consolidado, executada depois da migration 008.
- Listagem padrão de alunos ativos, filtros Ativos/Inativos/Todos, busca por nome/e-mail/telefone e totais independentes dos filtros.
- Inativação e reativação por POST, com autenticação, permissão RBAC, CSRF, bloqueio de linha, transação e auditoria.
- Repetição de transição já aplicada é idempotente e não duplica evento.
- Tela de detalhes com dados básicos e histórico de eventos.
- Exclusão física aposentada: `excluir.php` responde HTTP 410.
- Testes de migrations em MySQL 8.0 e MariaDB 10.11 e testes de integração HTTP.

## Limites de validação ainda pendentes

A CI utilizou bancos descartáveis. Ela não aplicou migrations no XAMPP ou em banco local/de destino.

Quando o computador estiver disponível, continuam necessários:
- backup completo e verificável, fora do webroot;
- inspeção do schema, versão do MySQL/MariaDB e engines;
- revisão da condição das migrations antes de executar qualquer SQL;
- smoke tests no ambiente local;
- inspeção manual em navegador e dispositivo físico.

Não executar cegamente o fixture `tests/prepare-foundation-database.sh` no banco local: ele prepara um banco descartável de CI e importa o schema base.

## Tratamento da antiga PR #1

A PR [#1 — feat: transforma CRUD em dashboard FitControl para portfólio](https://github.com/Alex5762/academia-alunos-crud/pull/1) permanece aberta em Draft e **não deve ser mesclada integralmente**.

Na última consulta, ela tinha 191 arquivos alterados, 38.927 adições, 305 exclusões e estava marcada como não mergeável. O HEAD consultado era `0cc9d9673e5cc68237eff262802f34f8c7656420`. Ela mistura a fundação já integrada com os Blocos 03–07 e a experiência de dashboard.

A PR #1 será usada somente como fonte de referência para extrair, revisar e testar cada bloco em branches novas criadas a partir da `main`. Não fazer merge, rebase ou cherry-pick em massa dessa PR.

## Próximo passo

Iniciar a revisão isolada do **Bloco 03 — Planos e matrículas**, dependente do Bloco 02. A especificação e os critérios de isolamento ficam em `docs/plano-bloco-03-2026-11-24.md`.

Nenhuma migration do Bloco 03 foi aplicada ao banco local e nenhuma funcionalidade do Bloco 03 foi integrada à `main` neste checkpoint.
