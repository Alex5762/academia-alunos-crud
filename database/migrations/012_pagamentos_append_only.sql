-- FitControl — Bloco 05
-- Migration 012: pagamentos append-only no banco.
-- Pré-requisito: migrations 010 e 011.
-- A escrita normal é autorizada somente pelo FinanceService transacional.
-- A variável de limpeza é reservada aos testes automatizados e operações DBA controladas.

USE academia_alunos;

DELIMITER //

CREATE TRIGGER trg_pagamentos_insert_guard
BEFORE INSERT ON pagamentos
FOR EACH ROW
BEGIN
    IF COALESCE(@fitcontrol_payment_write, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Registre pagamentos pelo serviço financeiro transacional.';
    END IF;
END//

CREATE TRIGGER trg_pagamentos_no_update
BEFORE UPDATE ON pagamentos
FOR EACH ROW
BEGIN
    IF COALESCE(@fitcontrol_finance_test_cleanup, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Pagamentos confirmados são imutáveis; registre um estorno compensatório.';
    END IF;
END//

CREATE TRIGGER trg_pagamentos_no_delete
BEFORE DELETE ON pagamentos
FOR EACH ROW
BEGIN
    IF COALESCE(@fitcontrol_finance_test_cleanup, 0) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Pagamentos confirmados não podem ser excluídos.';
    END IF;
END//

DELIMITER ;
