-- FitControl — Bloco 01
-- Migração 002: exigir troca da senha temporária no primeiro acesso.
-- Pré-requisito: executar 001_authentication_audit.sql antes desta migração.
-- Esta alteração não remove dados existentes; contas existentes recebem o valor 1.

USE academia_alunos;

ALTER TABLE usuarios
    ADD COLUMN deve_trocar_senha TINYINT(1) NOT NULL DEFAULT 1
    AFTER senha_hash;
