# Checkpoint — Bloco 02: Gestão de alunos

**Data:** 2026-11-24  
**PR:** [#7 — feat: integrar gestão de alunos com status e auditoria](https://github.com/Alex5762/academia-alunos-crud/pull/7)  
**Branch:** `feat/block-02-student-management`  
**HEAD de implementação e testes validado:** `50707cf735b0dc017836473f6b78837e7ba79e6c`  
**Base:** `main` em `1d6df18c0209557bd735912aba25a5c601fcddaf`

## Estado atual

- PR #7 aberta, mergeável e ainda não integrada.
- CI do HEAD de implementação `50707cf`: 7/7 checks concluídos com sucesso (run #45474952671). O checkpoint documental e o ajuste de ordem das migrations nesta revisão geram uma nova execução obrigatória da CI antes do merge.
- `main` permanece no SHA `1d6df18c0209557bd735912aba25a5c601fcddaf`.
- Nenhuma migration foi executada no XAMPP ou em banco local/de produção.
- Validação visual/manual em navegador e dispositivo físico continua pendente.

## Entregas do bloco

- Migration 004: coluna `alunos.ativo` com default 1 e índice `(ativo, nome)`.
- Migration 015: CHECK para aceitar somente 0 ou 1; aplicada depois da migration 008 no fixture integrado.
- Listagem padrão de alunos ativos, filtros Ativos/Inativos/Todos, busca por nome/e-mail/telefone e totais independentes dos filtros.
- Endpoint POST de inativação/reativação com autenticação, RBAC, CSRF, bloqueio de linha, transação e auditoria.
- Repetição de uma transição já aplicada é idempotente e não duplica evento de auditoria.
- Página de detalhes com dados básicos e histórico de eventos.
- Exclusão física aposentada: `excluir.php` responde HTTP 410.
- Testes de migrations em MySQL 8.0 e MariaDB 10.11, além da integração HTTP.

## Revisões adicionais feitas

- Teste HTTP comprova que Operador recebe 403 ao tentar alterar status e não altera o registro.
- Teste HTTP comprova que repetir a inativação não duplica evento.
- Fixtures das migrations 004 e 015 fazem preflight dos identificadores e limpam somente pelos IDs criados na execução.
- `cadastro.php` exige `students.create` ao abrir cadastro e `students.update` ao abrir edição.
- Preparação do banco de CI executa migration 015 depois da 008.
- README atualizado com a ordem de migrations e os cuidados de instalação.

## Checklist antes do merge

- [x] Escopo separado da antiga PR #1; sem puxar dashboard completo, matrículas, financeiro ou módulos futuros.
- [x] Revisão do diff e arquivos alterados.
- [x] CI 7/7 verde no HEAD de implementação `50707cf`.
- [ ] Reexecutar CI 7/7 após esta atualização documental.
- [x] PR sem conflito e mergeável na última consulta.
- [ ] Autorização explícita do responsável para mesclar a PR #7.
- [ ] Após merge, confirmar SHA da main e CI pós-merge.
- [ ] Quando o computador estiver disponível: backup verificável, inspeção do banco real, execução planejada das migrations uma única vez e smoke tests locais.
- [ ] Validar visualmente em navegador/dispositivo físico antes de liberar a usuários reais.

## Operação local — cuidados

A CI usa bancos descartáveis e não comprova que o banco XAMPP existente está pronto para receber migrations. Não executar cegamente o script `tests/prepare-foundation-database.sh` no banco local: ele é fixture de CI e importa o schema base. Para banco existente, fazer backup e inspeção antes de qualquer alteração. Não repetir migrations parcialmente executadas.

## Última revisão documental

A sequência de implantação foi consolidada no README e neste documento: migration 004 depois de 001–003; migration 007; marcador histórico; migration 008; migration 015. O fixture integrado da CI usa a mesma ordem. A migration 015 não deve ser executada antes da 008 neste runbook.

## Próximo passo

Aguardar a CI da atualização deste checkpoint e a autorização explícita para integrar a PR #7. Depois do merge, registrar o checkpoint pós-merge e iniciar o planejamento do Bloco 03 sem antecipar módulos dependentes.
