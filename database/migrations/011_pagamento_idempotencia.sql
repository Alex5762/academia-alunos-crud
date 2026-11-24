-- FitControl — Bloco 05
-- Migração 011: chave idempotente para novos pagamentos.
-- Pré-requisito: migration 010_financeiro.sql.
-- Registros anteriores permanecem sem chave; a proteção vale para novos lançamentos.

USE academia_alunos;

ALTER TABLE pagamentos
    ADD COLUMN chave_idempotencia CHAR(32)
        CHARACTER SET ascii COLLATE ascii_bin NULL
        COMMENT 'Token aleatório da operação de registro do pagamento',
    ADD COLUMN hash_requisicao CHAR(64)
        CHARACTER SET ascii COLLATE ascii_bin NULL
        COMMENT 'SHA-256 dos dados normalizados da operação',
    ADD UNIQUE KEY uq_pagamentos_idempotencia (cobranca_id, chave_idempotencia),
    ADD CONSTRAINT chk_pagamentos_idempotencia_pareada CHECK (
        (chave_idempotencia IS NULL AND hash_requisicao IS NULL)
        OR (chave_idempotencia IS NOT NULL AND hash_requisicao IS NOT NULL)
    );
