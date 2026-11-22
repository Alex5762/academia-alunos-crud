-- FitControl — Bloco 04 / Segurança
-- Migration 008: marco histórico e cadeia SHA-256 da trilha geral.
-- Pré-requisito: migrations 001 e 007.
-- Após aplicar, inicialize o marco pelo CLI antes de liberar a aplicação.

USE academia_alunos;

CREATE TABLE auditoria_integridade_estado (
    id TINYINT UNSIGNED NOT NULL,
    baseline_ultimo_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    baseline_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    baseline_inicializado TINYINT(1) NOT NULL DEFAULT 0,
    ultimo_evento_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

INSERT INTO auditoria_integridade_estado (
    id, baseline_ultimo_id, baseline_hash, baseline_inicializado,
    ultimo_evento_id, ultimo_hash
) VALUES (
    1, 0,
    '0000000000000000000000000000000000000000000000000000000000000000',
    0, 0,
    '0000000000000000000000000000000000000000000000000000000000000000'
);

CREATE TABLE auditoria_integridade_eventos (
    evento_id BIGINT UNSIGNED NOT NULL,
    hash_anterior CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    hash_evento CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    criado_em DATETIME NOT NULL,
    PRIMARY KEY (evento_id),
    KEY idx_auditoria_integridade_hash (hash_evento),
    CONSTRAINT fk_auditoria_integridade_evento
        FOREIGN KEY (evento_id) REFERENCES auditoria (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER trg_auditoria_no_update
BEFORE UPDATE ON auditoria
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'A trilha de auditoria é somente append-only.';
END//

CREATE TRIGGER trg_auditoria_no_delete
BEFORE DELETE ON auditoria
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'A trilha de auditoria não permite exclusão.';
END//

CREATE TRIGGER trg_auditoria_integridade_no_update
BEFORE UPDATE ON auditoria_integridade_eventos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Os hashes da auditoria não podem ser alterados.';
END//

CREATE TRIGGER trg_auditoria_integridade_no_delete
BEFORE DELETE ON auditoria_integridade_eventos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Os hashes da auditoria não podem ser excluídos.';
END//

DELIMITER ;
