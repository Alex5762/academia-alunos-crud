-- FitControl — Bloco 04 / Segurança
-- Migração 007: perfis administrativos e matriz RBAC.
-- Compatível com MySQL 8.0 e MariaDB 10.11.
--
-- Compatibilidade: contas existentes recebem ADMINISTRADOR para preservar
-- exatamente o acesso que já tinham antes desta migração. Contas criadas
-- depois passam a receber OPERADOR por padrão.
-- Após a migração, o administrador deve revisar e reduzir os perfis existentes.

USE academia_alunos;

ALTER TABLE usuarios
    ADD COLUMN perfil ENUM('ADMINISTRADOR', 'GESTOR', 'OPERADOR')
        NOT NULL DEFAULT 'ADMINISTRADOR'
        AFTER ativo,
    ADD KEY idx_usuarios_perfil_ativo (perfil, ativo);

ALTER TABLE usuarios
    ALTER COLUMN perfil SET DEFAULT 'OPERADOR';

-- Histórico especializado: registra o perfil anterior e o novo sem depender
-- apenas do evento genérico da tabela auditoria.
CREATE TABLE IF NOT EXISTS usuarios_perfis_eventos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    responsavel_id INT UNSIGNED NOT NULL,
    perfil_anterior ENUM('ADMINISTRADOR', 'GESTOR', 'OPERADOR') NOT NULL,
    perfil_novo ENUM('ADMINISTRADOR', 'GESTOR', 'OPERADOR') NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuario_perfil_evento_alvo
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_usuario_perfil_evento_responsavel
        FOREIGN KEY (responsavel_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    KEY idx_usuario_perfil_eventos_alvo_data (usuario_id, criado_em),
    KEY idx_usuario_perfil_eventos_responsavel_data (responsavel_id, criado_em)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
