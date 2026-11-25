#!/usr/bin/env bash
set -Eeuo pipefail

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_NAME:=academia_alunos}"
: "${DB_USER:=root}"
: "${DB_PASSWORD:=root}"
export DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD MYSQL_PWD="$DB_PASSWORD"

mysql_cli() {
    mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$@"
}

mysql_cli < banco.sql
for migration in \
    001_authentication_audit.sql \
    002_force_password_change.sql \
    003_login_rate_limit.sql \
    004_student_status.sql \
    007_administrative_roles.sql; do
    mysql_cli < "database/migrations/$migration"
done

# A migration 008 instala proteção append-only; o marcador histórico deve
# existir antes dos triggers. A cadeia é inicializada para os testes de serviço.
mysql_cli "$DB_NAME" -e "INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id) VALUES (NULL, 'evento_legado_baseline', 'sistema', NULL)"
mysql_cli < database/migrations/008_audit_integrity_chain.sql
mysql_cli < database/migrations/015_student_status_constraint.sql
php scripts/inicializar-integridade-auditoria.php

echo "Ambiente descartável de testes do Bloco 03 preparado (sem migrations 005/006)."
