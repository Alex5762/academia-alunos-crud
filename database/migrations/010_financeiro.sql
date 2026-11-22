-- FitControl — Bloco 05
-- Migração 010: cobranças únicas por matrícula e registro de pagamentos.
-- Pré-requisitos: migrations 001 a 009.
-- Executar uma única vez após backup; não gera cobranças para matrículas legadas.

USE academia_alunos;

CREATE TABLE cobrancas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    matricula_id BIGINT UNSIGNED NOT NULL,
    valor_total DECIMAL(10,2) NOT NULL,
    data_vencimento DATE NOT NULL,
    criado_por INT UNSIGNED NOT NULL,
    criado_em_utc DATETIME NOT NULL,
    cancelada_em_utc DATETIME NULL,
    cancelada_por INT UNSIGNED NULL,
    motivo_cancelamento VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cobrancas_matricula (matricula_id),
    KEY idx_cobrancas_vencimento_id (data_vencimento, id),
    KEY idx_cobrancas_cancelada_vencimento (cancelada_em_utc, data_vencimento),
    CONSTRAINT fk_cobrancas_matricula
        FOREIGN KEY (matricula_id) REFERENCES matriculas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cobrancas_criado_por
        FOREIGN KEY (criado_por) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cobrancas_cancelada_por
        FOREIGN KEY (cancelada_por) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pagamentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cobranca_id BIGINT UNSIGNED NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    forma_pagamento ENUM('DINHEIRO', 'PIX', 'TRANSFERENCIA', 'CARTAO', 'OUTRO') NOT NULL,
    data_pagamento DATE NOT NULL,
    observacao VARCHAR(255) NULL,
    registrado_por INT UNSIGNED NOT NULL,
    registrado_em_utc DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pagamentos_cobranca_data_id (cobranca_id, data_pagamento, id),
    KEY idx_pagamentos_usuario_data (registrado_por, registrado_em_utc),
    CONSTRAINT fk_pagamentos_cobranca
        FOREIGN KEY (cobranca_id) REFERENCES cobrancas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_pagamentos_usuario
        FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Uma matrícula legada não recebe cobrança por esta migração.
-- Cobranças são criadas exclusivamente pelo serviço transacional de novas matrículas.
