#!/usr/bin/env bash
set -Eeuo pipefail

MYSQL_HOST="\${DB_HOST:-127.0.0.1}"
MYSQL_PORT="\${DB_PORT:-3306}"
MYSQL_USER="\${DB_USER:-root}"
MYSQL_DATABASE="\${DB_NAME:-academia_alunos}"

mysql_cli() {
    MYSQL_PWD="\${DB_PASSWORD:-}" mysql \
        --host="$MYSQL_HOST" \
        --port="$MYSQL_PORT" \
        --user="$MYSQL_USER" \
        "$@"
}

db_value() {
    mysql_cli --batch --skip-column-names "$MYSQL_DATABASE" -e "$1"
}

LEGACY_EMAIL="legacy-migration-004@example.test"
NEW_EMAIL="default-status-migration-004@example.test"
LEGACY_ID=""

cleanup() {
    if [[ -n "$LEGACY_ID" ]]; then
        db_value "DELETE FROM alunos WHERE id = $LEGACY_ID" >/dev/null 2>&1 || true
    fi
    db_value "DELETE FROM alunos WHERE email = '$NEW_EMAIL'" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "1/4 - Inserindo um aluno legado antes da migração 004..."
EXISTING_FIXTURE="$(db_value "SELECT COUNT(*) FROM alunos WHERE email = '$LEGACY_EMAIL'")"
[[ "$EXISTING_FIXTURE" == "0" ]] || {
    echo "FALHA: fixture legado já existe antes do teste." >&2
    exit 1
}

mysql_cli "$MYSQL_DATABASE" -e "
    INSERT INTO alunos (nome, telefone, email, plano)
    VALUES ('Aluno legado da migração', '(21) 90000-0041', '$LEGACY_EMAIL', 'Mensal')
"
LEGACY_ID="$(db_value "SELECT id FROM alunos WHERE email = '$LEGACY_EMAIL' LIMIT 1")"
[[ "$LEGACY_ID" =~ ^[0-9]+$ ]] || {
    echo "FALHA: não foi possível obter o ID do aluno legado." >&2
    exit 1
}

echo "2/4 - Aplicando a migração 004..."
mysql_cli < database/migrations/004_student_status.sql

echo "3/4 - Conferindo preservação do registro e status ativo..."
LEGACY_ROW="$(db_value "
    SELECT CONCAT(
        id, '|', nome, '|', telefone, '|', email, '|', plano, '|', ativo
    )
    FROM alunos
    WHERE id = $LEGACY_ID
")"
EXPECTED_ROW="$LEGACY_ID|Aluno legado da migração|(21) 90000-0041|$LEGACY_EMAIL|Mensal|1"
[[ "$LEGACY_ROW" == "$EXPECTED_ROW" ]] || {
    echo "FALHA: dados/ID/status do aluno legado divergiram." >&2
    echo "Esperado: $EXPECTED_ROW" >&2
    echo "Obtido:   $LEGACY_ROW" >&2
    exit 1
}

echo "4/4 - Conferindo valor padrão e índice..."
mysql_cli "$MYSQL_DATABASE" -e "
    INSERT INTO alunos (nome, telefone, email, plano)
    VALUES ('Aluno com status padrão', '(21) 90000-0042', '$NEW_EMAIL', 'Mensal')
"
DEFAULT_STATUS="$(db_value "SELECT ativo FROM alunos WHERE email = '$NEW_EMAIL' LIMIT 1")"
[[ "$DEFAULT_STATUS" == "1" ]] || {
    echo "FALHA: novo aluno não recebeu ativo = 1 por padrão." >&2
    exit 1
}

INDEX_EXISTS="$(db_value "
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = '$MYSQL_DATABASE'
      AND table_name = 'alunos'
      AND index_name = 'idx_alunos_ativo_nome'
")"
[[ "$INDEX_EXISTS" == "2" ]] || {
    echo "FALHA: índice idx_alunos_ativo_nome não foi criado como esperado." >&2
    exit 1
}

echo "Migração 004: preservação do aluno legado, status padrão e índice: OK"
