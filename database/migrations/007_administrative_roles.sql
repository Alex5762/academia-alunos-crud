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
