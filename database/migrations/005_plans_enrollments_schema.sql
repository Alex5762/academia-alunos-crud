-- FitControl — Bloco 03
-- Migração 005: catálogo, matrículas e controle de revisão dos vínculos legados.
-- Pré-requisitos: banco.sql e migrations 001 a 004.
-- Esta migração é DDL e deve ser executada uma única vez após backup.
-- Não altera alunos.plano nem inventa contratos; a carga inicial fica na 006.

USE academia_alunos;

CREATE TABLE planos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    descricao VARCHAR(255) NULL,
    duracao_valor SMALLINT UNSIGNED NULL,
    duracao_unidade ENUM('DIA', 'SEMANA', 'MES') NULL,
    valor_atual DECIMAL(10,2) NULL,
    situacao ENUM('RASCUNHO', 'ATIVO', 'INATIVO') NOT NULL DEFAULT 'RASCUNHO',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_planos_situacao_nome (situacao, nome)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE matriculas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aluno_id INT UNSIGNED NOT NULL,
    plano_id INT UNSIGNED NULL,
    plano_nome_contratado VARCHAR(100) NULL,
    valor_contratado DECIMAL(10,2) NULL,
    duracao_contratada_valor SMALLINT UNSIGNED NULL,
    duracao_contratada_unidade ENUM('DIA', 'SEMANA', 'MES') NULL,
    inicio_em DATETIME NOT NULL,
    fim_exclusivo_em DATETIME NOT NULL,
    dia_referencia_calendario TINYINT UNSIGNED NULL,
    cancelada_em DATETIME NULL,
    origem ENUM('SISTEMA', 'LEGADO') NOT NULL DEFAULT 'SISTEMA',
    motivo_cancelamento VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_matriculas_aluno_periodo (aluno_id, inicio_em, fim_exclusivo_em),
    KEY idx_matriculas_plano (plano_id),
    CONSTRAINT fk_matriculas_aluno
        FOREIGN KEY (aluno_id) REFERENCES alunos (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_matriculas_plano
        FOREIGN KEY (plano_id) REFERENCES planos (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE revisoes_legado (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aluno_id_origem INT UNSIGNED NOT NULL,
    plano_texto_original VARCHAR(40) NOT NULL,
    resultado_atual ENUM(
        'VINCULO_CONFIRMADO',
        'LEGADO_SEM_VIGENCIA',
        'PENDENTE_REVISAO'
    ) NOT NULL DEFAULT 'PENDENTE_REVISAO',
    fonte_referencia VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_revisoes_legado_aluno (aluno_id_origem),
    KEY idx_revisoes_legado_resultado (resultado_atual, id),
    CONSTRAINT fk_revisoes_legado_aluno
        FOREIGN KEY (aluno_id_origem) REFERENCES alunos (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE revisoes_legado_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revisao_legado_id BIGINT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    tipo_evento ENUM('DECISAO', 'RETIFICACAO_VIGENCIA') NOT NULL,
    resultado_anterior ENUM(
        'VINCULO_CONFIRMADO',
        'LEGADO_SEM_VIGENCIA',
        'PENDENTE_REVISAO'
    ) NOT NULL,
    resultado_novo ENUM(
        'VINCULO_CONFIRMADO',
        'LEGADO_SEM_VIGENCIA',
        'PENDENTE_REVISAO'
    ) NOT NULL,
    matricula_id BIGINT UNSIGNED NULL,
    inicio_anterior DATETIME NULL,
    fim_exclusivo_anterior DATETIME NULL,
    inicio_novo DATETIME NULL,
    fim_exclusivo_novo DATETIME NULL,
    fonte_referencia VARCHAR(255) NULL,
    observacoes TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_revisoes_eventos_revisao_data (revisao_legado_id, criado_em, id),
    KEY idx_revisoes_eventos_matricula_data (matricula_id, criado_em),
    KEY idx_revisoes_eventos_usuario_data (usuario_id, criado_em),
    CONSTRAINT fk_revisoes_eventos_revisao
        FOREIGN KEY (revisao_legado_id) REFERENCES revisoes_legado (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_revisoes_eventos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_revisoes_eventos_matricula
        FOREIGN KEY (matricula_id) REFERENCES matriculas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- A aplicação controla consistência temporal e snapshots no serviço transacional.
-- Não usar CHECK como única proteção: versões antigas de MariaDB/MySQL podem
-- ter comportamento diferente. As tabelas são InnoDB para locks e rollback.
