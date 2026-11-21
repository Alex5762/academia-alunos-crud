-- FitControl — Bloco 02
-- Migração 004: situação ativa/inativa dos alunos.
-- Pré-requisito: executar as migrações 001, 002 e 003 antes desta migração.
-- Os registros existentes recebem ativo = 1 pelo valor padrão.

USE academia_alunos;

ALTER TABLE alunos
    ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1;

CREATE INDEX idx_alunos_ativo_nome
    ON alunos (ativo, nome);
