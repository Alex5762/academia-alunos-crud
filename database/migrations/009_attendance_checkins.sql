-- FitControl — Bloco 04: frequência e check-in
-- Migration 009: presença manual vinculada a aluno, matrícula e funcionário.
-- Pré-requisitos: migrations 001–008, incluindo o catálogo e as matrículas.
-- A data_local é fornecida pela aplicação no fuso America/Sao_Paulo;
-- registrado_em_utc contém o instante equivalente em UTC.

USE academia_alunos;

CREATE TABLE checkins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aluno_id INT UNSIGNED NOT NULL,
    matricula_id BIGINT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    data_local DATE NOT NULL,
    registrado_em_utc DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checkins_aluno_data_local (aluno_id, data_local),
    KEY idx_checkins_data_local_id (data_local, id),
    KEY idx_checkins_matricula_data (matricula_id, data_local),
    KEY idx_checkins_usuario_data (usuario_id, registrado_em_utc),
    CONSTRAINT fk_checkins_aluno
        FOREIGN KEY (aluno_id) REFERENCES alunos (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_checkins_matricula
        FOREIGN KEY (matricula_id) REFERENCES matriculas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_checkins_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
