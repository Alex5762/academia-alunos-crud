-- FitControl — Bloco 02
-- Migração 015: integridade binária do status cadastral do aluno.
-- Pré-requisito: migration 004 (coluna alunos.ativo).
-- Requisito do CHECK: MySQL 8.0.16+ ou MariaDB 10.11 (alvo do projeto).
--
-- Pré-verificação:
--   SELECT COUNT(*) FROM alunos WHERE ativo NOT IN (0, 1);
-- O resultado precisa ser zero. A inclusão do CHECK valida os registros
-- existentes; valores legados inválidos fazem o ALTER TABLE falhar.
-- Nenhum dado é corrigido ou descartado automaticamente.

USE academia_alunos;

ALTER TABLE alunos
    ADD CONSTRAINT chk_alunos_ativo_boolean
    CHECK (ativo IN (0, 1));
