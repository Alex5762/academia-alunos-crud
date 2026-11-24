#!/usr/bin/env bash
set -Eeuo pipefail

MYSQL_BIN="${MYSQL_BIN:-mysql}"
MYSQL_HOST="${MYSQL_HOST:-${DB_HOST:-127.0.0.1}}"
MYSQL_PORT="${MYSQL_PORT:-${DB_PORT:-3306}}"
MYSQL_USER="${MYSQL_USER:-${DB_USER:-root}}"
MYSQL_DATABASE="${MYSQL_DATABASE:-${DB_NAME:-academia_alunos}}"
MYSQL_PASSWORD="${DB_PASSWORD:-${MYSQL_PWD:-}}"

mysql_cli() {
    MYSQL_PWD="$MYSQL_PASSWORD" "$MYSQL_BIN" --host="$MYSQL_HOST" --port="$MYSQL_PORT" --user="$MYSQL_USER" --database="$MYSQL_DATABASE" "$@"
}

db_value() {
    mysql_cli --batch --skip-column-names -e "$1"
}

INVALID_EMAIL="migration-015-invalid-status@example.test"
ACTIVE_EMAIL="migration-015-active@example.test"
INACTIVE_EMAIL="migration-015-inactive@example.test"
REJECTED_INSERT_EMAIL="migration-015-rejected-insert@example.test"

constraint_count() {
    db_value "
        SELECT COUNT(*)
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = '$MYSQL_DATABASE'
          AND TABLE_NAME = 'alunos'
          AND CONSTRAINT_NAME = 'chk_alunos_ativo_boolean'
          AND CONSTRAINT_TYPE = 'CHECK'
    "
}

cleanup() {
    db_value "DELETE FROM alunos WHERE email IN ('$INVALID_EMAIL', '$ACTIVE_EMAIL', '$INACTIVE_EMAIL', '$REJECTED_INSERT_EMAIL')" >/dev/null 2>&1 || true
}

for email in "$INVALID_EMAIL" "$ACTIVE_EMAIL" "$INACTIVE_EMAIL" "$REJECTED_INSERT_EMAIL"; do
    existing="$(db_value "SELECT COUNT(*) FROM alunos WHERE email = '$email'")"
    [[ "$existing" == "0" ]] || {
        echo "FALHA: fixture $email já existe antes do teste." >&2
        exit 1
    }
done

# Só instala o cleanup depois de comprovar que os identificadores de fixture
# estão livres; assim uma colisão nunca apaga registros preexistentes.
trap cleanup EXIT

before="$(constraint_count)"
if [[ "$before" == "0" ]]; then
    echo "1/5 - Criando registro legado com status fora do domínio..."
    mysql_cli -e "
        INSERT INTO alunos (nome, telefone, email, plano, ativo)
        VALUES ('Status legado inválido', '(21) 90000-0150', '$INVALID_EMAIL', 'Mensal', 2)
    "

    echo "2/5 - Confirmando que a migration recusa dados inválidos sem corrigi-los..."
    if migration_output="$(mysql_cli < database/migrations/015_student_status_constraint.sql 2>&1)"; then
        echo "FALHA: migration 015 aceitou ativo = 2." >&2
        exit 1
    fi

    invalid_status="$(db_value "SELECT ativo FROM alunos WHERE email = '$INVALID_EMAIL' LIMIT 1")"
    [[ "$invalid_status" == "2" ]] || {
        echo "FALHA: a tentativa de migration alterou o dado legado inválido." >&2
        exit 1
    }

    after_failed_migration="$(constraint_count)"
    [[ "$after_failed_migration" == "0" ]] || {
        echo "FALHA: constraint foi criada apesar da presença de dado inválido." >&2
        exit 1
    }

    echo "3/5 - Removendo somente a fixture e aplicando a migration..."
    db_value "DELETE FROM alunos WHERE email = '$INVALID_EMAIL'" >/dev/null
    mysql_cli < database/migrations/015_student_status_constraint.sql
elif [[ "$before" == "1" ]]; then
    echo "Migration 015 já aplicada; validando a constraint existente..."
else
    echo "FALHA: estado inesperado da constraint chk_alunos_ativo_boolean." >&2
    exit 1
fi

after="$(constraint_count)"
[[ "$after" == "1" ]] || {
    echo "FALHA: a constraint CHECK não foi registrada." >&2
    exit 1
}

echo "4/5 - Conferindo aceitação dos estados válidos 0 e 1..."
mysql_cli -e "
    INSERT INTO alunos (nome, telefone, email, plano, ativo) VALUES
    ('Aluno ativo protegido', '(21) 90000-0151', '$ACTIVE_EMAIL', 'Mensal', 1),
    ('Aluno inativo protegido', '(21) 90000-0152', '$INACTIVE_EMAIL', 'Mensal', 0)
"
valid_statuses="$(db_value "
    SELECT CONCAT(
        (SELECT ativo FROM alunos WHERE email = '$ACTIVE_EMAIL' LIMIT 1),
        '|',
        (SELECT ativo FROM alunos WHERE email = '$INACTIVE_EMAIL' LIMIT 1)
    )
")"
[[ "$valid_statuses" == "1|0" ]] || {
    echo "FALHA: os estados válidos não foram preservados (obtido=$valid_statuses)." >&2
    exit 1
}

echo "5/5 - Rejeitando INSERT e UPDATE fora do domínio..."
if mysql_cli -e "
    INSERT INTO alunos (nome, telefone, email, plano, ativo)
    VALUES ('Status inválido em INSERT', '(21) 90000-0153', '$REJECTED_INSERT_EMAIL', 'Mensal', 2)
" >/dev/null 2>&1; then
    echo "FALHA: INSERT com ativo = 2 foi aceito." >&2
    exit 1
fi

if mysql_cli -e "UPDATE alunos SET ativo = -1 WHERE email = '$ACTIVE_EMAIL'" >/dev/null 2>&1; then
    echo "FALHA: UPDATE com ativo = -1 foi aceito." >&2
    exit 1
fi

persisted_active="$(db_value "SELECT ativo FROM alunos WHERE email = '$ACTIVE_EMAIL' LIMIT 1")"
[[ "$persisted_active" == "1" ]] || {
    echo "FALHA: UPDATE inválido alterou o estado do aluno." >&2
    exit 1
}

invalid_count="$(db_value "SELECT COUNT(*) FROM alunos WHERE ativo NOT IN (0, 1)")"
[[ "$invalid_count" == "0" ]] || {
    echo "FALHA: ainda existem registros com ativo fora do domínio." >&2
    exit 1
}

echo "Migration 015: preflight, constraint e rejeição de INSERT/UPDATE inválidos: OK"
