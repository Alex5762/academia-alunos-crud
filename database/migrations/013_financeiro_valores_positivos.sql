-- FitControl — Bloco 05
-- Migration 013: invariantes de valores financeiros positivos.
-- Pré-requisitos: migrations 010, 011 e 012.
-- Compatibilidade alvo: MySQL 8.0.16+ e MariaDB 10.11.
--
-- Se houver registros inválidos preexistentes, o ALTER falhará e a migração
-- deverá ser interrompida para revisão dos dados. Não corrigir valores
-- financeiros históricos por UPDATE automático.

USE academia_alunos;

ALTER TABLE cobrancas
    ADD CONSTRAINT chk_cobrancas_valor_total_positivo
        CHECK (valor_total > 0.00);

ALTER TABLE pagamentos
    ADD CONSTRAINT chk_pagamentos_valor_positivo
        CHECK (valor > 0.00);
