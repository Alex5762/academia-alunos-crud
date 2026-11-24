#!/usr/bin/env bash
set -Eeuo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
ADMIN_PASSWORD="${FITCONTROL_TEST_PASSWORD:?FITCONTROL_TEST_PASSWORD ausente}"
TMP_DIR="$(mktemp -d)"
ADMIN_JAR="$TMP_DIR/admin.cookies"
OPERATOR_JAR="$TMP_DIR/operator.cookies"
SERVER_LOG="$TMP_DIR/php-server.log"
SERVER_PID=""

cleanup() {
    if [[ -n "$SERVER_PID" ]]; then kill "$SERVER_PID" >/dev/null 2>&1 || true; fi
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

fail() { echo "FALHA: $1" >&2; cat "$SERVER_LOG" >&2 || true; exit 1; }
get_token() {
    local file="$1"
    local token
    token="$(grep -oE 'name="token" value="[^"]+"' "$file" | head -n 1 | sed -E 's/.*value="([^"]+)"/\1/')"
    [[ -n "$token" ]] || fail "Token CSRF não encontrado em $file"
    printf '%s' "$token"
}
db_value() {
    SQL_QUERY="$1" php -r '
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_NAME"));
        $pdo = new PDO($dsn, getenv("DB_USER"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo $pdo->query(getenv("SQL_QUERY"))->fetchColumn();
    '
}
login_and_change_password() {
    local jar="$1" email="$2" password="$3" new_password="$4" prefix="$5"
    curl -fsS -c "$jar" -b "$jar" "$BASE_URL/login.php" -o "$TMP_DIR/$prefix-login.html"
    local token
    token="$(get_token "$TMP_DIR/$prefix-login.html")"
    curl -fsS -L -c "$jar" -b "$jar" "$BASE_URL/login.php" \
        --data-urlencode "token=$token" \
        --data-urlencode "email=$email" \
        --data-urlencode "senha=$password" \
        -o "$TMP_DIR/$prefix-first-access.html"
    grep -Fq 'Crie sua senha pessoal' "$TMP_DIR/$prefix-first-access.html" || fail "$prefix não foi direcionado à troca obrigatória de senha."
    token="$(get_token "$TMP_DIR/$prefix-first-access.html")"
    curl -fsS -L -c "$jar" -b "$jar" "$BASE_URL/trocar-senha.php" \
        --data-urlencode "token=$token" \
        --data-urlencode "senha_atual=$password" \
        --data-urlencode "nova_senha=$new_password" \
        --data-urlencode "confirmacao=$new_password" \
        -o "$TMP_DIR/$prefix-dashboard.html"
    grep -Fq 'Cadastro de alunos' "$TMP_DIR/$prefix-dashboard.html" || fail "$prefix não chegou ao CRUD após trocar a senha."
}

php -S 127.0.0.1:8080 -t . >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for attempt in $(seq 1 30); do
    if curl -sS "$BASE_URL/login.php" -o /dev/null; then break; fi
    sleep 1
done

ANON_CODE="$(curl -sS -o "$TMP_DIR/anonymous.html" -w '%{http_code}' "$BASE_URL/index.php")"
[[ "$ANON_CODE" == "303" ]] || fail "Acesso anônimo ao CRUD deveria redirecionar para login; recebeu $ANON_CODE."
CADASTRO_ANON_CODE="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/cadastro.php")"
[[ "$CADASTRO_ANON_CODE" == "303" ]] || fail "Cadastro anônimo deveria redirecionar para login; recebeu $CADASTRO_ANON_CODE."

login_and_change_password "$ADMIN_JAR" "provision-ci@example.test" "$ADMIN_PASSWORD" "SenhaAdminNova-2026!" "admin"
TOKEN="$(get_token "$TMP_DIR/admin-dashboard.html")"

INVALID_CSRF_CODE="$(curl -sS -o "$TMP_DIR/csrf.html" -w '%{http_code}' -b "$ADMIN_JAR" -c "$ADMIN_JAR" \
    "$BASE_URL/salvar.php" --data-urlencode "token=token-invalido" --data-urlencode "id=" \
    --data-urlencode "nome=Aluno CSRF" --data-urlencode "telefone=21999990000" \
    --data-urlencode "email=csrf-foundation@example.test" --data-urlencode "plano=Mensal")"
[[ "$INVALID_CSRF_CODE" == "403" ]] || fail "CSRF inválido deveria retornar 403; recebeu $INVALID_CSRF_CODE."

curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/salvar.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=" \
    --data-urlencode "nome=Aluno Foundation HTTP" --data-urlencode "telefone=21999990000" \
    --data-urlencode "email=foundation-http@example.test" --data-urlencode "plano=Mensal" \
    -o "$TMP_DIR/student-created.html"
grep -Fq 'Aluno cadastrado com sucesso!' "$TMP_DIR/student-created.html" || fail "CRUD não confirmou a criação do aluno."
STUDENT_ID="$(db_value "SELECT id FROM alunos WHERE email = 'foundation-http@example.test' LIMIT 1")"
[[ "$STUDENT_ID" =~ ^[0-9]+$ ]] || fail "Aluno de integração não encontrado no banco."

curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/usuarios.php" -o "$TMP_DIR/users.html"
TOKEN="$(get_token "$TMP_DIR/users.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/salvar-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "nome=Operador HTTP" \
    --data-urlencode "email=operator-http@example.test" -o "$TMP_DIR/operator-created.html"
grep -Fq 'Senha temporária' "$TMP_DIR/operator-created.html" || fail "A gestão de contas não exibiu a senha temporária uma única vez."
OPERATOR_PASSWORD="$(sed -n 's/.*<code id="temporary-password">\([^<]*\)<\/code>.*/\1/p' "$TMP_DIR/operator-created.html" | head -n 1)"
[[ "${#OPERATOR_PASSWORD}" -eq 32 ]] || fail "Senha temporária do operador não encontrada."

login_and_change_password "$OPERATOR_JAR" "operator-http@example.test" "$OPERATOR_PASSWORD" "SenhaOperadorNova-2026!" "operator"
OPERATOR_DELETE_CODE="$(curl -sS -o "$TMP_DIR/operator-delete.html" -w '%{http_code}' -b "$OPERATOR_JAR" -c "$OPERATOR_JAR" "$BASE_URL/excluir.php?id=$STUDENT_ID")"
[[ "$OPERATOR_DELETE_CODE" == "403" ]] || fail "Operador deveria receber 403 na exclusão; recebeu $OPERATOR_DELETE_CODE."

TOKEN="$(get_token "$TMP_DIR/admin-dashboard.html")"
ADMIN_CONFIRM_CODE="$(curl -sS -o "$TMP_DIR/admin-confirm.html" -w '%{http_code}' -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/excluir.php?id=$STUDENT_ID")"
[[ "$ADMIN_CONFIRM_CODE" == "200" ]] || fail "Administrador deveria acessar a confirmação de exclusão; recebeu $ADMIN_CONFIRM_CODE."
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/excluir.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$STUDENT_ID" -o "$TMP_DIR/student-deleted.html"
grep -Fq 'Aluno excluído com sucesso!' "$TMP_DIR/student-deleted.html" || fail "Exclusão administrativa não foi concluída."
REMAINING="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $STUDENT_ID")"
[[ "$REMAINING" == "0" ]] || fail "Registro do aluno não foi removido após exclusão autorizada."
AUDIT_COUNT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao IN ('aluno_criado','aluno_excluido') AND entidade='alunos' AND entidade_id=$STUDENT_ID")"
[[ "$AUDIT_COUNT" == "2" ]] || fail "Criação e exclusão não produziram os dois eventos de auditoria esperados."

echo "Integração HTTP: login, troca de senha, sessão, CSRF, RBAC, CRUD e auditoria: OK"
