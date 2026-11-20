-- FitControl — Bloco 01
-- Migração 001: contas individuais e trilha de auditoria.
-- Pré-requisito: importar banco.sql e selecionar o banco academia_alunos.
-- Esta migração não altera nem remove os registros existentes em alunos.

USE academia_alunos;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_usuarios_email UNIQUE (email),
    KEY idx_usuarios_ativo_nome (ativo, nome)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    acao VARCHAR(50) NOT NULL,
    entidade VARCHAR(40) NOT NULL,
    entidade_id BIGINT UNSIGNED NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auditoria_usuario
        FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    KEY idx_auditoria_usuario_data (usuario_id, criado_em),
    KEY idx_auditoria_entidade_data (entidade, entidade_id, criado_em),
    KEY idx_auditoria_acao_data (acao, criado_em)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- usuario_id aceita NULL somente para eventos sem sessão autenticada,
-- como falha de login ou provisionamento inicial executado via CLI.
-- Contas existentes não devem ser apagadas; use ativo = 0.
-- entidade_id é uma referência lógica: pode apontar para alunos, usuarios
-- ou outra entidade futura, por isso não possui FK polimórfica.
