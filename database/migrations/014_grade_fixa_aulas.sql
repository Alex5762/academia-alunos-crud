-- FitControl — Bloco 06: grade fixa de aulas
-- Migração 014: modalidades, instrutores e horários semanais recorrentes.
-- Pré-requisitos: migrations 001–013, incluindo usuários e cadeia de auditoria.
-- Executar uma única vez após backup. Não cria reservas, presenças ou sessões datadas.

USE academia_alunos;

CREATE TABLE modalidades (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    descricao VARCHAR(255) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_modalidades_nome (nome),
    KEY idx_modalidades_ativo_nome (ativo, nome),
    CONSTRAINT ck_modalidades_ativo
        CHECK (ativo IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE instrutores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NULL,
    telefone VARCHAR(20) NULL,
    usuario_id INT UNSIGNED NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_instrutores_usuario (usuario_id),
    KEY idx_instrutores_ativo_nome (ativo, nome),
    CONSTRAINT fk_instrutores_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_instrutores_ativo
        CHECK (ativo IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE horarios_aula (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    modalidade_id BIGINT UNSIGNED NOT NULL,
    instrutor_id BIGINT UNSIGNED NULL,
    dia_semana TINYINT UNSIGNED NOT NULL
        COMMENT 'Padrão ISO: 1=segunda-feira até 7=domingo',
    hora_inicio TIME NOT NULL,
    hora_fim TIME NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_horarios_aula_modalidade_dia_inicio (
        modalidade_id, dia_semana, hora_inicio
    ),
    KEY idx_horarios_aula_grade (ativo, dia_semana, hora_inicio, id),
    KEY idx_horarios_aula_instrutor (instrutor_id, ativo, dia_semana, hora_inicio),
    CONSTRAINT fk_horarios_aula_modalidade
        FOREIGN KEY (modalidade_id) REFERENCES modalidades (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_horarios_aula_instrutor
        FOREIGN KEY (instrutor_id) REFERENCES instrutores (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_horarios_aula_dia_semana
        CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT ck_horarios_aula_intervalo
        CHECK (
            hora_inicio >= '00:00:00'
            AND hora_inicio < '24:00:00'
            AND hora_fim > hora_inicio
            AND hora_fim < '24:00:00'
        ),
    CONSTRAINT ck_horarios_aula_ativo
        CHECK (ativo IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- A combinação modalidade/dia/início é única porque o MVP ainda não modela
-- turmas paralelas, salas ou grupos distintos da mesma modalidade.
-- A validação de sobreposição entre horários do mesmo instrutor pertence ao serviço
-- de domínio e deve ocorrer antes da gravação, dentro de transação.
