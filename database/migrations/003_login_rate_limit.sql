-- FitControl — Bloco 01
-- Migração 003: limitação persistente de tentativas de login.
-- Pré-requisito: executar as migrações 001 e 002 antes desta migração.
-- Armazena somente hashes SHA-256 do e-mail normalizado e do IP de origem.
-- Os identificadores são pseudonimizados, não anônimos.

USE academia_alunos;

CREATE TABLE IF NOT EXISTS login_tentativas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    origem_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    janela_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bloqueado_ate DATETIME NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_login_tentativas_email_origem
        UNIQUE (email_hash, origem_hash),
    KEY idx_login_tentativas_atualizado (atualizado_em),
    KEY idx_login_tentativas_bloqueio (bloqueado_ate)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
