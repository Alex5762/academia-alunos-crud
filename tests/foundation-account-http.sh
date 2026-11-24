#!/usr/bin/env bash
set -Eeuo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
ADMIN_PASSWORD="SenhaAdminNova-2026!"
TMP_DIR="$(mktemp -d)"
ADMIN_JAR="$TMP_DIR/admin.cookies"
TARGET_JAR="$TMP_DIR/target.cookies"
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

login_user() {
    local jar="$1" email="$2" password="$3" prefix="$4"
    curl -fsS -c "$jar" -b "$jar" "$BASE_URL/login.php" -o "$TMP_DIR/$prefix-login.html"
    local token
    token="$(get_token "$TMP_DIR/$prefix-login.html")"
    curl -fsS -L -c "$jar" -b "$jar" "$BASE_URL/login.php" \
        --data-urlencode "token=$token" \
        --data-urlencode "email=$email" \
        --data-urlencode "senha=$password" \
        -o "$TMP_DIR/$prefix-home.html"
    grep -Fq 'Cadastro de alunos' "$TMP_DIR/$prefix-home.html" || fail "$prefix não conseguiu entrar no CRUD."
}

php -S 127.0.0.1:8080 -t . >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for attempt in $(seq 1 30); do
    if curl -sS "$BASE_URL/login.php" -o /dev/null; then break; fi
    sleep 1
done

login_user "$ADMIN_JAR" "provision-ci@example.test" "$ADMIN_PASSWORD" "admin"
ADMIN_ID="$(db_value "SELECT id FROM usuarios WHERE email = 'provision-ci@example.test' LIMIT 1")"
[[ "$ADMIN_ID" =~ ^[0-9]+$ ]] || fail "Administrador inicial não encontrado."

curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/usuarios.php" -o "$TMP_DIR/admin-users.html"
TOKEN="$(get_token "$TMP_DIR/admin-users.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/salvar-usuario.php" \
    --data-urlencode "token=$TOKEN" \
    --data-urlencode "nome=Conta de Teste Endpoint" \
    --data-urlencode "email=endpoint-operator@example.test" \
    -o "$TMP_DIR/target-created.html"
grep -Fq 'Senha temporária' "$TMP_DIR/target-created.html" || fail "O endpoint não criou a conta de teste."
TEMP_PASSWORD="$(sed -n 's/.*<code id="temporary-password">\([^<]*\)<\/code>.*/\1/p' "$TMP_DIR/target-created.html" | head -n 1)"
[[ "${#TEMP_PASSWORD}" -eq 32 ]] || fail "Senha temporária do usuário de teste não foi encontrada."
TARGET_ID="$(db_value "SELECT id FROM usuarios WHERE email = 'endpoint-operator@example.test' LIMIT 1")"
[[ "$TARGET_ID" =~ ^[0-9]+$ ]] || fail "Conta de teste não encontrada no banco."

curl -fsS -c "$TARGET_JAR" -b "$TARGET_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/target-login.html"
TOKEN="$(get_token "$TMP_DIR/target-login.html")"
curl -fsS -L -c "$TARGET_JAR" -b "$TARGET_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$TOKEN" \
    --data-urlencode "email=endpoint-operator@example.test" \
    --data-urlencode "senha=$TEMP_PASSWORD" \
    -o "$TMP_DIR/target-first-access.html"
grep -Fq 'Crie sua senha pessoal' "$TMP_DIR/target-first-access.html" || fail "Primeiro acesso não exigiu troca de senha."
TOKEN="$(get_token "$TMP_DIR/target-first-access.html")"
TARGET_PASSWORD="SenhaEndpointOperator-2026!"
curl -fsS -L -b "$TARGET_JAR" -c "$TARGET_JAR" "$BASE_URL/trocar-senha.php" \
    --data-urlencode "token=$TOKEN" \
    --data-urlencode "senha_atual=$TEMP_PASSWORD" \
    --data-urlencode "nova_senha=$TARGET_PASSWORD" \
    --data-urlencode "confirmacao=$TARGET_PASSWORD" \
    -o "$TMP_DIR/target-home.html"
grep -Fq 'Cadastro de alunos' "$TMP_DIR/target-home.html" || fail "Troca de senha inicial não foi concluída."

TARGET_DENIED="$(curl -sS -o "$TMP_DIR/target-users-denied.html" -w '%{http_code}' -b "$TARGET_JAR" -c "$TARGET_JAR" "$BASE_URL/usuarios.php")"
[[ "$TARGET_DENIED" == "403" ]] || fail "Operador deveria receber 403 em usuarios.php; recebeu $TARGET_DENIED."
TOKEN="$(get_token "$TMP_DIR/target-home.html")"
PROFILE_DENIED="$(curl -sS -o "$TMP_DIR/target-profile-denied.html" -w '%{http_code}' -b "$TARGET_JAR" -c "$TARGET_JAR" "$BASE_URL/alterar-perfil-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$ADMIN_ID" --data-urlencode "perfil=GESTOR")"
[[ "$PROFILE_DENIED" == "403" ]] || fail "Operador deveria receber 403 ao alterar perfis; recebeu $PROFILE_DENIED."
STATUS_DENIED="$(curl -sS -o "$TMP_DIR/target-status-denied.html" -w '%{http_code}' -b "$TARGET_JAR" -c "$TARGET_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$ADMIN_ID" --data-urlencode "acao=desativar")"
[[ "$STATUS_DENIED" == "403" ]] || fail "Operador deveria receber 403 ao alterar status; recebeu $STATUS_DENIED."

curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/usuarios.php" -o "$TMP_DIR/admin-users-before.html"
TOKEN="$(get_token "$TMP_DIR/admin-users-before.html")"
INVALID_CSRF="$(curl -sS -o "$TMP_DIR/invalid-csrf.html" -w '%{http_code}' -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=invalid-token" --data-urlencode "id=$TARGET_ID" --data-urlencode "acao=desativar")"
[[ "$INVALID_CSRF" == "403" ]] || fail "Status sem CSRF válido deveria retornar 403; recebeu $INVALID_CSRF."
[[ "$(db_value "SELECT ativo FROM usuarios WHERE id = $TARGET_ID")" == "1" ]] || fail "CSRF inválido alterou o status da conta."

curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-perfil-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$TARGET_ID" --data-urlencode "perfil=GESTOR" \
    -o "$TMP_DIR/target-promoted.html"
grep -Fq 'Perfil do funcionário atualizado com sucesso.' "$TMP_DIR/target-promoted.html" || fail "Alteração de perfil não foi concluída."
[[ "$(db_value "SELECT perfil FROM usuarios WHERE id = $TARGET_ID")" == "GESTOR" ]] || fail "Perfil Gestor não foi persistido."
TARGET_MANAGEMENT_DENIED="$(curl -sS -o "$TMP_DIR/manager-users-denied.html" -w '%{http_code}' -b "$TARGET_JAR" -c "$TARGET_JAR" "$BASE_URL/usuarios.php")"
[[ "$TARGET_MANAGEMENT_DENIED" == "403" ]] || fail "Gestor não deveria administrar funcionários; recebeu $TARGET_MANAGEMENT_DENIED."

TOKEN="$(get_token "$TMP_DIR/target-promoted.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$ADMIN_ID" --data-urlencode "acao=desativar" \
    -o "$TMP_DIR/admin-self-deactivation.html"
grep -Fq 'Você não pode desativar sua própria conta.' "$TMP_DIR/admin-self-deactivation.html" || fail "Autodesativação do Administrador não foi bloqueada."
[[ "$(db_value "SELECT ativo FROM usuarios WHERE id = $ADMIN_ID")" == "1" ]] || fail "Administrador ficou inativo após autodesativação."

TOKEN="$(get_token "$TMP_DIR/target-promoted.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$TARGET_ID" --data-urlencode "acao=desativar" \
    -o "$TMP_DIR/target-deactivated.html"
grep -Fq 'Conta desativada.' "$TMP_DIR/target-deactivated.html" || fail "Desativação da conta não foi concluída."
[[ "$(db_value "SELECT ativo FROM usuarios WHERE id = $TARGET_ID")" == "0" ]] || fail "Conta continua ativa após desativação."
REVOKED_SESSION="$(curl -sS -o "$TMP_DIR/target-revoked.html" -w '%{http_code}' -b "$TARGET_JAR" -c "$TARGET_JAR" "$BASE_URL/index.php")"
[[ "$REVOKED_SESSION" == "303" ]] || fail "Sessão do usuário inativo não foi revogada; recebeu $REVOKED_SESSION."

TOKEN="$(get_token "$TMP_DIR/target-deactivated.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$TARGET_ID" --data-urlencode "acao=reativar" \
    -o "$TMP_DIR/target-reactivated.html"
grep -Fq 'Conta reativada com sucesso.' "$TMP_DIR/target-reactivated.html" || fail "Reativação não foi concluída."
[[ "$(db_value "SELECT ativo FROM usuarios WHERE id = $TARGET_ID")" == "1" ]] || fail "Conta continua inativa após reativação."

login_user "$TARGET_JAR" "endpoint-operator@example.test" "$TARGET_PASSWORD" "target-relogin"
TOKEN="$(get_token "$TMP_DIR/target-relogin-home.html")"
curl -fsS -L -b "$TARGET_JAR" -c "$TARGET_JAR" "$BASE_URL/logout.php" \
    --data-urlencode "token=$TOKEN" -o "$TMP_DIR/target-logout.html"
AFTER_LOGOUT="$(curl -sS -o /dev/null -w '%{http_code}' -b "$TARGET_JAR" -c "$TARGET_JAR" "$BASE_URL/index.php")"
[[ "$AFTER_LOGOUT" == "303" ]] || fail "Logout não encerrou a sessão; recebeu $AFTER_LOGOUT."

PROFILE_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $ADMIN_ID AND entidade = 'usuarios' AND entidade_id = $TARGET_ID AND acao = 'usuario_perfil_alterado'")"
STATUS_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $ADMIN_ID AND entidade = 'usuarios' AND entidade_id = $TARGET_ID AND acao IN ('usuario_desativado','usuario_reativado')")"
LOGOUT_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $TARGET_ID AND acao = 'logout'")"
[[ "$PROFILE_AUDIT" == "1" && "$STATUS_AUDIT" == "2" && "$LOGOUT_AUDIT" == "1" ]] || fail "Auditoria de perfil, status ou logout está incompleta."

php -r '
    require "conexao.php";
    require "src/Security/AuditIntegrityChain.php";
    $result = \FitControl\Security\AuditIntegrityChain::verify($pdo);
    if (!$result["ok"]) { fwrite(STDERR, $result["message"] . PHP_EOL); exit(1); }
    echo "Integridade da auditoria após fluxo HTTP: OK" . PHP_EOL;
'

echo "Endpoints HTTP de contas: CSRF, RBAC, perfil, autodesativação, desativação, revogação, reativação, login, logout e auditoria: OK"
