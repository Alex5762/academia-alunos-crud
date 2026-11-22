#!/usr/bin/env bash
set -euo pipefail

MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_DATABASE="${MYSQL_DATABASE:-academia_alunos}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"

mysql_cmd() {
  "${MYSQL_BIN}" --host="${MYSQL_HOST}" --port="${MYSQL_PORT}" --user="${MYSQL_USER}" --database="${MYSQL_DATABASE}" --batch --skip-column-names "$@"
}

migration_dir="database/migrations"

echo "Importando schema base e migrations 001–004..."
"${MYSQL_BIN}" --host="${MYSQL_HOST}" --port="${MYSQL_PORT}" --user="${MYSQL_USER}" < banco.sql
for migration in \
  001_authentication_audit.sql \
  002_force_password_change.sql \
  003_login_rate_limit.sql \
  004_student_status.sql; do
  "${MYSQL_BIN}" --host="${MYSQL_HOST}" --port="${MYSQL_PORT}" --user="${MYSQL_USER}" < "${migration_dir}/${migration}"
done

echo "Criando alunos legados antes das migrations 005/006..."
mysql_cmd <<'SQL'
INSERT INTO alunos (nome, telefone, email, plano, ativo) VALUES
('Legado migration A', '21990001001', 'migration-legacy-a@example.test', 'Mensal legado', 1),
('Legado migration B', '21990001002', 'migration-legacy-b@example.test', 'Plano sem preço', 1);
SQL

echo "Aplicando migration 005 (DDL)..."
"${MYSQL_BIN}" --host="${MYSQL_HOST}" --port="${MYSQL_PORT}" --user="${MYSQL_USER}" --database="${MYSQL_DATABASE}" < "${migration_dir}/005_plans_enrollments_schema.sql"

echo "Aplicando migration 006 (carga inicial)..."
"${MYSQL_BIN}" --host="${MYSQL_HOST}" --port="${MYSQL_PORT}" --user="${MYSQL_USER}" --database="${MYSQL_DATABASE}" < "${migration_dir}/006_import_legacy_review.sql"

assert_equals() {
  local expected="$1"
  local actual="$2"
  local message="$3"
  if [[ "${expected}" != "${actual}" ]]; then
    printf 'FALHA: %s (esperado=%s, obtido=%s)\n' "${message}" "${expected}" "${actual}" >&2
    exit 1
  fi
}

assert_equals "2" "$(mysql_cmd -e "SELECT COUNT(*) FROM revisoes_legado")" "deve criar uma revisão por aluno legado"
assert_equals "2" "$(mysql_cmd -e "SELECT COUNT(*) FROM revisoes_legado WHERE resultado_atual = 'PENDENTE_REVISAO' AND fonte_referencia IS NULL")" "revisões iniciais devem ficar pendentes e sem evidência inventada"
assert_equals "0" "$(mysql_cmd -e "SELECT COUNT(*) FROM matriculas")" "a carga não pode inventar matrículas"
assert_equals "2" "$(mysql_cmd -e "SELECT COUNT(*) FROM revisoes_legado r INNER JOIN alunos a ON a.id = r.aluno_id_origem AND a.plano = r.plano_texto_original")" "texto original do plano deve ser preservado"

echo "Simulando decisão humana e novo aluno após o corte inicial..."
mysql_cmd <<'SQL'
UPDATE revisoes_legado
SET resultado_atual = 'LEGADO_SEM_VIGENCIA',
    fonte_referencia = 'Contrato consultado no arquivo'
WHERE aluno_id_origem = (
    SELECT id FROM alunos WHERE email = 'migration-legacy-a@example.test'
);

INSERT INTO alunos (nome, telefone, email, plano, ativo)
VALUES ('Aluno posterior', '21990001003', 'migration-later@example.test', 'Mensal', 1);
SQL

echo "Reexecutando migration 006..."
"${MYSQL_BIN}" --host="${MYSQL_HOST}" --port="${MYSQL_PORT}" --user="${MYSQL_USER}" --database="${MYSQL_DATABASE}" < "${migration_dir}/006_import_legacy_review.sql"

assert_equals "2" "$(mysql_cmd -e "SELECT COUNT(*) FROM revisoes_legado")" "reexecução não deve incluir aluno criado depois do corte"
assert_equals "LEGADO_SEM_VIGENCIA" "$(mysql_cmd -e "SELECT resultado_atual FROM revisoes_legado r INNER JOIN alunos a ON a.id = r.aluno_id_origem WHERE a.email = 'migration-legacy-a@example.test'")" "reexecução não pode sobrescrever decisão humana"
assert_equals "1" "$(mysql_cmd -e "SELECT COUNT(*) FROM controle_importacao_legado WHERE id = 1")" "corte de importação deve existir uma única vez"
assert_equals "InnoDB" "$(mysql_cmd -e "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matriculas'")" "matriculas deve usar InnoDB"
assert_equals "InnoDB" "$(mysql_cmd -e "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revisoes_legado_eventos'")" "eventos de revisão devem usar InnoDB"

echo "Migrations 005/006: preservação, integridade e reexecução segura aprovadas."
