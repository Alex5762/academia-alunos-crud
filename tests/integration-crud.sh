#!/usr/bin/env bash
set -Eeuo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
COOKIE_JAR="$(mktemp)"
TMP_DIR="$(mktemp -d)"
trap 'rm -f "$COOKIE_JAR"; rm -rf "$TMP_DIR"' EXIT

fail() {
    echo "FALHA: $1" >&2
    exit 1
}

assert_contains() {
    local file="$1"
    local expected="$2"
    grep -Fq -- "$expected" "$file" || fail "Não encontrei '$expected' em $file"
}

get_token() {
    local file="$1"
    local token
    token="$(grep -oE 'name="token" value="[^"]+"' "$file" | head -n 1 | sed -E 's/.*value="([^"]+)"/\1/')"
    [[ -n "$token" ]] || fail "Não foi possível obter o token CSRF."
    printf '%s' "$token"
}

db_value() {
    SQL_QUERY="$1" php -r '
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            getenv("DB_HOST") ?: "127.0.0.1",
            getenv("DB_PORT") ?: "3306",
            getenv("DB_NAME") ?: "academia_alunos"
        );
        $pdo = new PDO(
            $dsn,
            getenv("DB_USER") ?: "root",
            getenv("DB_PASSWORD") === false ? "" : getenv("DB_PASSWORD"),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo $pdo->query(getenv("SQL_QUERY"))->fetchColumn();
    '
}

db_exec() {
    SQL_STATEMENT="$1" php -r '
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            getenv("DB_HOST") ?: "127.0.0.1",
            getenv("DB_PORT") ?: "3306",
            getenv("DB_NAME") ?: "academia_alunos"
        );
        $pdo = new PDO(
            $dsn,
            getenv("DB_USER") ?: "root",
            getenv("DB_PASSWORD") === false ? "" : getenv("DB_PASSWORD"),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec(getenv("SQL_STATEMENT"));
    '
}

echo "1/10 - Bloqueando acesso anônimo e autenticando usuário..."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/anonymous.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/index.php")"
[[ "$HTTP_CODE" == "303" ]] || fail "Dashboard anônimo deveria redirecionar para login; retornou $HTTP_CODE."
grep -Fq 'login.php' "$TMP_DIR/anonymous.html" && fail "Redirecionamento inesperado no dashboard."

HTTP_CODE="$(curl -sS -o "$TMP_DIR/detail-anonymous.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/detalhes-aluno.php?id=1")"
[[ "$HTTP_CODE" == "303" ]] || fail "Perfil de aluno anônimo deveria redirecionar para login; retornou $HTTP_CODE."

CACHE_CONTROL="$(curl -sSI "$BASE_URL/login.php" | tr -d '\r' | grep -i '^Cache-Control:' | tail -n 1)"
[[ "$CACHE_CONTROL" == *"no-store"* ]] || fail "Login não enviou Cache-Control: no-store."
FRAME_POLICY="$(curl -sSI "$BASE_URL/login.php" | tr -d '\r' | grep -i '^X-Frame-Options:' | tail -n 1)"
[[ "$FRAME_POLICY" == *"DENY"* ]] || fail "Login não bloqueou incorporação em iframe."
NOSNIFF="$(curl -sSI "$BASE_URL/login.php" | tr -d '\r' | grep -i '^X-Content-Type-Options:' | tail -n 1)"
[[ "$NOSNIFF" == *"nosniff"* ]] || fail "Login não enviou X-Content-Type-Options: nosniff."

curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/login.html"
LOGIN_TOKEN="$(get_token "$TMP_DIR/login.html")"
HTTP_CODE="$(curl -sS -o "$TMP_DIR/bad-login.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/login.php" \
    --data-urlencode "token=$LOGIN_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=senha-incorreta")"
[[ "$HTTP_CODE" == "200" ]] || fail "Login inválido deveria retornar a tela com HTTP 200."
assert_contains "$TMP_DIR/bad-login.html" "E-mail ou senha inválidos."

echo "1.0/10 - Limitando tentativas repetidas de login..."
RATE_EMAIL="rate-limit-ci@example.test"
RATE_HASH="$(printf '%s' "$RATE_EMAIL" | sha256sum | awk '{print $1}')"
ORIGIN_HASH="$(printf '%s' '127.0.0.1' | sha256sum | awk '{print $1}')"
for attempt in 1 2 3 4; do
    curl -sS -o "$TMP_DIR/rate-limit-$attempt.html" -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/login.php" \
        --data-urlencode "token=$LOGIN_TOKEN" \
        --data-urlencode "email=$RATE_EMAIL" \
        --data-urlencode "senha=senha-incorreta"
    assert_contains "$TMP_DIR/rate-limit-$attempt.html" "E-mail ou senha inválidos."
done
RATE_BEFORE_FIFTH="$(db_value "SELECT tentativas FROM login_tentativas WHERE email_hash = '$RATE_HASH' AND origem_hash = '$ORIGIN_HASH'")"
[[ "$RATE_BEFORE_FIFTH" == "4" ]] || fail "Esperava quatro tentativas registradas antes da quinta."

curl -sS -o "$TMP_DIR/rate-limit-5.html" -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/login.php" \
    --data-urlencode "token=$LOGIN_TOKEN" \
    --data-urlencode "email=$RATE_EMAIL" \
    --data-urlencode "senha=senha-incorreta"
assert_contains "$TMP_DIR/rate-limit-5.html" "E-mail ou senha inválidos."
RATE_BLOCKED="$(db_value "SELECT COUNT(*) FROM login_tentativas WHERE email_hash = '$RATE_HASH' AND origem_hash = '$ORIGIN_HASH' AND tentativas = 5 AND bloqueado_ate > NOW()")"
[[ "$RATE_BLOCKED" == "1" ]] || fail "A quinta falha não aplicou bloqueio temporário."

curl -sS -o "$TMP_DIR/rate-limit-6.html" -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/login.php" \
    --data-urlencode "token=$LOGIN_TOKEN" \
    --data-urlencode "email=$RATE_EMAIL" \
    --data-urlencode "senha=senha-incorreta"
assert_contains "$TMP_DIR/rate-limit-6.html" "E-mail ou senha inválidos."
RATE_STILL_BLOCKED="$(db_value "SELECT COUNT(*) FROM login_tentativas WHERE email_hash = '$RATE_HASH' AND origem_hash = '$ORIGIN_HASH' AND tentativas = 5 AND bloqueado_ate > NOW()")"
[[ "$RATE_STILL_BLOCKED" == "1" ]] || fail "A tentativa durante o bloqueio alterou o contador indevidamente."


curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/login-again.html"
LOGIN_TOKEN="$(get_token "$TMP_DIR/login-again.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$LOGIN_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_TEST_PASSWORD" \
    -o "$TMP_DIR/login-success.html"
assert_contains "$TMP_DIR/login-success.html" "Crie sua senha pessoal"
CLEARED_RATE_LIMIT="$(db_value "SELECT COUNT(*) FROM login_tentativas WHERE email_hash = '$(printf '%s' 'provision-ci@example.test' | sha256sum | awk '{print $1}')' AND origem_hash = '$(printf '%s' '127.0.0.1' | sha256sum | awk '{print $1}')'")"
[[ "$CLEARED_RATE_LIMIT" == "0" ]] || fail "Login válido não limpou as tentativas anteriores."

echo "1.1/10 - Exigindo troca da senha temporária no primeiro acesso..."
PASSWORD_TOKEN="$(get_token "$TMP_DIR/login-success.html")"
FITCONTROL_NEW_PASSWORD="SenhaNova-FitControl-2026!"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/trocar-senha.php" \
    --data-urlencode "token=$PASSWORD_TOKEN" \
    --data-urlencode "senha_atual=$FITCONTROL_TEST_PASSWORD" \
    --data-urlencode "nova_senha=$FITCONTROL_NEW_PASSWORD" \
    --data-urlencode "confirmacao=$FITCONTROL_NEW_PASSWORD" \
    -o "$TMP_DIR/password-changed.html"
assert_contains "$TMP_DIR/password-changed.html" "Visão geral"
OLD_PASSWORD_HASH="$(db_value "SELECT COUNT(*) FROM usuarios WHERE email = 'provision-ci@example.test' AND deve_trocar_senha = 0")"
[[ "$OLD_PASSWORD_HASH" == "1" ]] || fail "A conta continuou marcada para troca obrigatória."
if [[ -n "${GITHUB_ENV:-}" ]]; then
    printf 'FITCONTROL_TEST_PASSWORD=%s\n' "$FITCONTROL_NEW_PASSWORD" >> "$GITHUB_ENV"
fi
if [[ -n "${RUNNER_TEMP:-}" ]]; then
    printf '%s' "$FITCONTROL_NEW_PASSWORD" > "$RUNNER_TEMP/fitcontrol-test-password"
    chmod 600 "$RUNNER_TEMP/fitcontrol-test-password"
fi

curl -fsS -c "$TMP_DIR/old-password-cookies.txt" -b "$TMP_DIR/old-password-cookies.txt" "$BASE_URL/login.php" -o "$TMP_DIR/login-old-password.html"
LOGIN_TOKEN="$(get_token "$TMP_DIR/login-old-password.html")"
curl -fsS -c "$TMP_DIR/old-password-cookies.txt" -b "$TMP_DIR/old-password-cookies.txt" -X POST "$BASE_URL/login.php" \
    --data-urlencode "token=$LOGIN_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_TEST_PASSWORD" \
    -o "$TMP_DIR/old-password-rejected.html"
assert_contains "$TMP_DIR/old-password-rejected.html" "E-mail ou senha inválidos."

echo "1.15/10 - Alteração voluntária de senha após o primeiro acesso..."
CHANGE_JAR="$TMP_DIR/password-change-cookies.txt"
curl -fsS -c "$CHANGE_JAR" -b "$CHANGE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/change-login.html"
CHANGE_LOGIN_TOKEN="$(get_token "$TMP_DIR/change-login.html")"
curl -fsS -L -c "$CHANGE_JAR" -b "$CHANGE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$CHANGE_LOGIN_TOKEN" --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_NEW_PASSWORD" -o "$TMP_DIR/change-login-success.html"
assert_contains "$TMP_DIR/change-login-success.html" "Visão geral"
curl -fsS -c "$CHANGE_JAR" -b "$CHANGE_JAR" "$BASE_URL/alterar-senha.php" -o "$TMP_DIR/change-password-form.html"
assert_contains "$TMP_DIR/change-password-form.html" "Alterar senha"
CHANGE_TOKEN="$(get_token "$TMP_DIR/change-password-form.html")"
FITCONTROL_UPDATED_PASSWORD="SenhaAtualizada-FitControl-2026!"

curl -sS -o "$TMP_DIR/change-wrong-current.html" -c "$CHANGE_JAR" -b "$CHANGE_JAR" -X POST "$BASE_URL/alterar-senha.php" \
    --data-urlencode "token=$CHANGE_TOKEN" --data-urlencode "senha_atual=senha-atual-incorreta" \
    --data-urlencode "nova_senha=$FITCONTROL_UPDATED_PASSWORD" --data-urlencode "confirmacao=$FITCONTROL_UPDATED_PASSWORD"
assert_contains "$TMP_DIR/change-wrong-current.html" "A senha atual está incorreta."

curl -sS -o "$TMP_DIR/change-short-password.html" -c "$CHANGE_JAR" -b "$CHANGE_JAR" -X POST "$BASE_URL/alterar-senha.php" \
    --data-urlencode "token=$CHANGE_TOKEN" --data-urlencode "senha_atual=$FITCONTROL_NEW_PASSWORD" \
    --data-urlencode "nova_senha=curta" --data-urlencode "confirmacao=curta"
assert_contains "$TMP_DIR/change-short-password.html" "A nova senha deve ter pelo menos 12 caracteres."

curl -sS -o "$TMP_DIR/change-mismatch.html" -c "$CHANGE_JAR" -b "$CHANGE_JAR" -X POST "$BASE_URL/alterar-senha.php" \
    --data-urlencode "token=$CHANGE_TOKEN" --data-urlencode "senha_atual=$FITCONTROL_NEW_PASSWORD" \
    --data-urlencode "nova_senha=$FITCONTROL_UPDATED_PASSWORD" --data-urlencode "confirmacao=OutraSenhaDiferente-2026!"
assert_contains "$TMP_DIR/change-mismatch.html" "A confirmação não corresponde à nova senha."

curl -sS -o "$TMP_DIR/change-reused-password.html" -c "$CHANGE_JAR" -b "$CHANGE_JAR" -X POST "$BASE_URL/alterar-senha.php" \
    --data-urlencode "token=$CHANGE_TOKEN" --data-urlencode "senha_atual=$FITCONTROL_NEW_PASSWORD" \
    --data-urlencode "nova_senha=$FITCONTROL_NEW_PASSWORD" --data-urlencode "confirmacao=$FITCONTROL_NEW_PASSWORD"
assert_contains "$TMP_DIR/change-reused-password.html" "Escolha uma senha diferente da senha atual."

curl -fsS -L -c "$CHANGE_JAR" -b "$CHANGE_JAR" "$BASE_URL/alterar-senha.php" \
    --data-urlencode "token=$CHANGE_TOKEN" --data-urlencode "senha_atual=$FITCONTROL_NEW_PASSWORD" \
    --data-urlencode "nova_senha=$FITCONTROL_UPDATED_PASSWORD" --data-urlencode "confirmacao=$FITCONTROL_UPDATED_PASSWORD" \
    -o "$TMP_DIR/change-password-success.html"
assert_contains "$TMP_DIR/change-password-success.html" "Senha alterada com sucesso."
PASSWORD_CHANGE_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = (SELECT id FROM usuarios WHERE email = 'provision-ci@example.test') AND acao = 'senha_alterada'")"
[[ "$PASSWORD_CHANGE_AUDIT" == "1" ]] || fail "A alteração voluntária da senha não foi auditada."

OLD_CHANGE_JAR="$TMP_DIR/old-changed-password-cookies.txt"
curl -fsS -c "$OLD_CHANGE_JAR" -b "$OLD_CHANGE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/login-old-changed-password.html"
OLD_CHANGE_TOKEN="$(get_token "$TMP_DIR/login-old-changed-password.html")"
curl -sS -o "$TMP_DIR/login-old-changed-password-rejected.html" -c "$OLD_CHANGE_JAR" -b "$OLD_CHANGE_JAR" -X POST "$BASE_URL/login.php" \
    --data-urlencode "token=$OLD_CHANGE_TOKEN" --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_NEW_PASSWORD"
assert_contains "$TMP_DIR/login-old-changed-password-rejected.html" "E-mail ou senha inválidos."

NEW_CHANGE_JAR="$TMP_DIR/new-changed-password-cookies.txt"
curl -fsS -c "$NEW_CHANGE_JAR" -b "$NEW_CHANGE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/login-new-changed-password.html"
NEW_CHANGE_TOKEN="$(get_token "$TMP_DIR/login-new-changed-password.html")"
curl -fsS -L -c "$NEW_CHANGE_JAR" -b "$NEW_CHANGE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$NEW_CHANGE_TOKEN" --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_UPDATED_PASSWORD" -o "$TMP_DIR/login-new-changed-password-success.html"
assert_contains "$TMP_DIR/login-new-changed-password-success.html" "Visão geral"
FITCONTROL_NEW_PASSWORD="$FITCONTROL_UPDATED_PASSWORD"
if [[ -n "$RUNNER_TEMP" ]]; then
    printf '%s' "$FITCONTROL_NEW_PASSWORD" > "$RUNNER_TEMP/fitcontrol-test-password"
    chmod 600 "$RUNNER_TEMP/fitcontrol-test-password"
fi

USUARIO_ID="$(db_value "SELECT id FROM usuarios WHERE email = 'provision-ci@example.test' LIMIT 1")"

echo "1.2/10 - Criando, desativando e reativando uma conta pela interface..."
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/usuarios.php" -o "$TMP_DIR/users-before.html"
assert_contains "$TMP_DIR/users-before.html" "Funcionários"
USERS_TOKEN="$(get_token "$TMP_DIR/users-before.html")"

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/salvar-usuario.php" \
    --data-urlencode "token=$USERS_TOKEN" \
    --data-urlencode "nome=Funcionário CI" \
    --data-urlencode "email=funcionario-ci@example.test" \
    -o "$TMP_DIR/user-created.html"
assert_contains "$TMP_DIR/user-created.html" "Conta criada com sucesso."
assert_contains "$TMP_DIR/user-created.html" "Senha temporária"

TEMP_PASSWORD="$(grep -oE '<code id="temporary-password">[^<]+' "$TMP_DIR/user-created.html" | sed -E 's/.*>//')"
[[ ${#TEMP_PASSWORD} -ge 30 ]] || fail "A senha temporária não foi exibida corretamente."

NOVO_USUARIO_ID="$(db_value "SELECT id FROM usuarios WHERE email = 'funcionario-ci@example.test' LIMIT 1")"
NOVO_HASH_OK="$(db_value "SELECT COUNT(*) FROM usuarios WHERE id = $NOVO_USUARIO_ID AND ativo = 1 AND deve_trocar_senha = 1 AND senha_hash <> '$TEMP_PASSWORD'")"
[[ "$NOVO_HASH_OK" == "1" ]] || fail "A conta não foi criada ativa, com hash e troca obrigatória."

NEW_COOKIE_JAR="$TMP_DIR/new-user-cookies.txt"
curl -fsS -c "$NEW_COOKIE_JAR" -b "$NEW_COOKIE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/new-user-login.html"
NEW_LOGIN_TOKEN="$(get_token "$TMP_DIR/new-user-login.html")"
curl -fsS -L -c "$NEW_COOKIE_JAR" -b "$NEW_COOKIE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$NEW_LOGIN_TOKEN" \
    --data-urlencode "email=funcionario-ci@example.test" \
    --data-urlencode "senha=$TEMP_PASSWORD" \
    -o "$TMP_DIR/new-user-first-login.html"
assert_contains "$TMP_DIR/new-user-first-login.html" "Crie sua senha pessoal"
NEW_PASSWORD="SenhaNova-Funcionario-2026!"
NEW_PASSWORD_TOKEN="$(get_token "$TMP_DIR/new-user-first-login.html")"
curl -fsS -L -c "$NEW_COOKIE_JAR" -b "$NEW_COOKIE_JAR" "$BASE_URL/trocar-senha.php" \
    --data-urlencode "token=$NEW_PASSWORD_TOKEN" \
    --data-urlencode "senha_atual=$TEMP_PASSWORD" \
    --data-urlencode "nova_senha=$NEW_PASSWORD" \
    --data-urlencode "confirmacao=$NEW_PASSWORD" \
    -o "$TMP_DIR/new-user-password-changed.html"
assert_contains "$TMP_DIR/new-user-password-changed.html" "Visão geral"
NOVO_ACESSO_OK="$(db_value "SELECT COUNT(*) FROM usuarios WHERE id = $NOVO_USUARIO_ID AND deve_trocar_senha = 0")"
[[ "$NOVO_ACESSO_OK" == "1" ]] || fail "A nova conta não concluiu a troca obrigatória de senha."
CRIACAO_AUDITADA="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = (SELECT id FROM usuarios WHERE email = 'provision-ci@example.test') AND acao = 'usuario_criado' AND entidade_id = $NOVO_USUARIO_ID")"
[[ "$CRIACAO_AUDITADA" == "1" ]] || fail "A criação da conta não foi auditada com o autor correto."

USERS_TOKEN="$(get_token "$TMP_DIR/user-created.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$USERS_TOKEN" \
    --data-urlencode "id=$NOVO_USUARIO_ID" \
    --data-urlencode "acao=desativar" \
    -o "$TMP_DIR/user-disabled.html"
assert_contains "$TMP_DIR/user-disabled.html" "Conta desativada."

CONTA_INATIVA="$(db_value "SELECT COUNT(*) FROM usuarios WHERE id = $NOVO_USUARIO_ID AND ativo = 0")"
[[ "$CONTA_INATIVA" == "1" ]] || fail "A desativação da conta não foi persistida."
DESATIVACAO_AUDITADA="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = (SELECT id FROM usuarios WHERE email = 'provision-ci@example.test') AND acao = 'usuario_desativado' AND entidade_id = $NOVO_USUARIO_ID")"
[[ "$DESATIVACAO_AUDITADA" == "1" ]] || fail "A desativação não foi auditada."

curl -fsS -c "$TMP_DIR/inactive-user-cookies.txt" -b "$TMP_DIR/inactive-user-cookies.txt" "$BASE_URL/login.php" -o "$TMP_DIR/inactive-login.html"
INACTIVE_LOGIN_TOKEN="$(get_token "$TMP_DIR/inactive-login.html")"
curl -sS -c "$TMP_DIR/inactive-user-cookies.txt" -b "$TMP_DIR/inactive-user-cookies.txt" -X POST "$BASE_URL/login.php" \
    --data-urlencode "token=$INACTIVE_LOGIN_TOKEN" \
    --data-urlencode "email=funcionario-ci@example.test" \
    --data-urlencode "senha=$NEW_PASSWORD" \
    -o "$TMP_DIR/inactive-login-rejected.html"
assert_contains "$TMP_DIR/inactive-login-rejected.html" "E-mail ou senha inválidos."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/revoked-user-session.html" -w '%{http_code}' -c "$NEW_COOKIE_JAR" -b "$NEW_COOKIE_JAR" "$BASE_URL/index.php")"
[[ "$HTTP_CODE" == "303" ]] || fail "A sessão da conta desativada continuou acessando o painel."

USERS_TOKEN="$(get_token "$TMP_DIR/user-disabled.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$USERS_TOKEN" \
    --data-urlencode "id=$NOVO_USUARIO_ID" \
    --data-urlencode "acao=reativar" \
    -o "$TMP_DIR/user-reactivated.html"
assert_contains "$TMP_DIR/user-reactivated.html" "Conta reativada com sucesso."
REATIVACAO_AUDITADA="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = (SELECT id FROM usuarios WHERE email = 'provision-ci@example.test') AND acao = 'usuario_reativado' AND entidade_id = $NOVO_USUARIO_ID")"
[[ "$REATIVACAO_AUDITADA" == "1" ]] || fail "A reativação não foi auditada."

USERS_TOKEN="$(get_token "$TMP_DIR/user-reactivated.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$USERS_TOKEN" \
    --data-urlencode "id=$USUARIO_ID" \
    --data-urlencode "acao=desativar" \
    -o "$TMP_DIR/self-disable.html"
assert_contains "$TMP_DIR/self-disable.html" "Você não pode desativar sua própria conta."
SELF_ACTIVE="$(db_value "SELECT COUNT(*) FROM usuarios WHERE email = 'provision-ci@example.test' AND ativo = 1")"
[[ "$SELF_ACTIVE" == "1" ]] || fail "A própria conta foi desativada indevidamente."

curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/cadastro.php" -o "$TMP_DIR/form.html"
TOKEN="$(get_token "$TMP_DIR/form.html")"
assert_contains "$TMP_DIR/form.html" 'autocomplete="tel"'
assert_contains "$TMP_DIR/form.html" 'Informe o DDD e o número'

HTTP_CODE="$(curl -sS -o "$TMP_DIR/csrf.html" -w '%{http_code}' \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/salvar.php" \
    --data-urlencode "token=token-invalido" \
    --data-urlencode "id=" \
    --data-urlencode "nome=Teste CSRF" \
    --data-urlencode "telefone=21999990000" \
    --data-urlencode "email=csrf@example.test" \
    --data-urlencode "plano=Mensal")"
[[ "$HTTP_CODE" == "403" ]] || fail "Token CSRF inválido deveria retornar HTTP 403; retornou $HTTP_CODE."

echo "2/10 - Conferindo validação de e-mail e telefone no servidor..."
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/salvar.php" \
    --data-urlencode "token=$TOKEN" \
    --data-urlencode "id=" \
    --data-urlencode "nome=Aluno Teste Inválido" \
    --data-urlencode "telefone=21999990000" \
    --data-urlencode "email=email-invalido" \
    --data-urlencode "plano=Mensal" \
    -o "$TMP_DIR/invalid-email.html"
assert_contains "$TMP_DIR/invalid-email.html" "Informe um e-mail válido"
assert_contains "$TMP_DIR/invalid-email.html" 'value="Aluno Teste Inválido"'

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/salvar.php" \
    --data-urlencode "token=$TOKEN" \
    --data-urlencode "id=" \
    --data-urlencode "nome=Aluno Telefone Inválido" \
    --data-urlencode "telefone=abc123" \
    --data-urlencode "email=telefone@example.test" \
    --data-urlencode "plano=Mensal" \
    -o "$TMP_DIR/invalid-phone.html"
assert_contains "$TMP_DIR/invalid-phone.html" "Informe um telefone com DDD e 10 a 13 dígitos"
assert_contains "$TMP_DIR/invalid-phone.html" 'value="abc123"'

echo "3/10 - Cadastrando aluno pelo endpoint real com telefone formatado..."
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/salvar.php" \
    --data-urlencode "token=$TOKEN" \
    --data-urlencode "id=" \
    --data-urlencode "nome=Aluno CI CRUD" \
    --data-urlencode "telefone=(21) 99999-0000" \
    --data-urlencode "email=crud-ci-create@example.test" \
    --data-urlencode "plano=Mensal" \
    -o "$TMP_DIR/create.html"
assert_contains "$TMP_DIR/create.html" "Aluno cadastrado com sucesso!"

ALUNO_ID="$(db_value "SELECT id FROM alunos WHERE email = 'crud-ci-create@example.test' LIMIT 1")"
[[ "$ALUNO_ID" =~ ^[0-9]+$ ]] || fail "O cadastro não gerou um ID válido."

echo "3.5/10 - Validando perfil individual e histórico..."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/detail-invalid.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/detalhes-aluno.php?id=abc")"
[[ "$HTTP_CODE" == "400" ]] || fail "ID inválido no perfil deveria retornar 400; retornou $HTTP_CODE."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/detail-missing.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/detalhes-aluno.php?id=999999")"
[[ "$HTTP_CODE" == "404" ]] || fail "Aluno inexistente deveria retornar 404; retornou $HTTP_CODE."
curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "id=$ALUNO_ID" \
    --data-urlencode "busca=Aluno CI CRUD" \
    --data-urlencode "status=ativos" \
    "$BASE_URL/detalhes-aluno.php" -o "$TMP_DIR/detail-created.html"
assert_contains "$TMP_DIR/detail-created.html" "Informações cadastrais"
assert_contains "$TMP_DIR/detail-created.html" "Aluno CI CRUD"
assert_contains "$TMP_DIR/detail-created.html" "Cadastro criado"
assert_contains "$TMP_DIR/detail-created.html" "Histórico de matrículas"
assert_contains "$TMP_DIR/detail-created.html" "O histórico operacional será habilitado após a instalação e validação das migrations do Bloco 03."
assert_contains "$TMP_DIR/detail-created.html" 'href="index.php?status=ativos&amp;busca=Aluno%20CI%20CRUD#alunos"'
DETAIL_AUDIT_COUNT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE entidade = 'alunos' AND entidade_id = $ALUNO_ID AND acao = 'aluno_criado'")"
[[ "$DETAIL_AUDIT_COUNT" == "1" ]] || fail "Perfil não refletiu o evento de criação na auditoria."

echo "4/10 - Pesquisando e editando o aluno..."
curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "busca=Aluno CI CRUD" \
    "$BASE_URL/index.php" -o "$TMP_DIR/search.html"
assert_contains "$TMP_DIR/search.html" "<strong>Aluno CI CRUD</strong>"

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "id=$ALUNO_ID" \
    --data-urlencode "busca=Aluno CI CRUD" \
    --data-urlencode "status=ativos" \
    "$BASE_URL/cadastro.php" -o "$TMP_DIR/edit-form.html"
EDIT_TOKEN="$(get_token "$TMP_DIR/edit-form.html")"
assert_contains "$TMP_DIR/edit-form.html" 'value="Aluno CI CRUD"'
assert_contains "$TMP_DIR/edit-form.html" 'name="busca" value="Aluno CI CRUD"'
assert_contains "$TMP_DIR/edit-form.html" 'name="status" value="ativos"'

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/salvar.php" \
    --data-urlencode "token=$EDIT_TOKEN" \
    --data-urlencode "id=$ALUNO_ID" \
    --data-urlencode "nome=Aluno CI Atualizado" \
    --data-urlencode "telefone=21999990001" \
    --data-urlencode "email=crud-ci-update@example.test" \
    --data-urlencode "plano=Anual" \
    --data-urlencode "busca=Aluno CI CRUD" \
    --data-urlencode "status=ativos" \
    -o "$TMP_DIR/update.html"
assert_contains "$TMP_DIR/update.html" "Aluno atualizado com sucesso!"
assert_contains "$TMP_DIR/update.html" 'id="busca" name="busca"'
assert_contains "$TMP_DIR/update.html" 'value="Aluno CI CRUD"'
assert_contains "$TMP_DIR/update.html" '<option value="ativos" selected>Ativos</option>'

ATUALIZADO="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID AND nome = 'Aluno CI Atualizado' AND email = 'crud-ci-update@example.test' AND plano = 'Anual'")"
[[ "$ATUALIZADO" == "1" ]] || fail "A edição não foi persistida corretamente."

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "busca=crud-ci-update@example.test" \
    "$BASE_URL/index.php" -o "$TMP_DIR/search-email.html"
assert_contains "$TMP_DIR/search-email.html" "<strong>Aluno CI Atualizado</strong>"

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "busca=21999990001" \
    "$BASE_URL/index.php" -o "$TMP_DIR/search-phone.html"
assert_contains "$TMP_DIR/search-phone.html" "<strong>Aluno CI Atualizado</strong>"

USUARIO_ID="$(db_value "SELECT id FROM usuarios WHERE email = 'provision-ci@example.test' LIMIT 1")"

echo "5/10 - Inativando e reativando sem excluir o cadastro..."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/status-get.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/alterar-status-aluno.php")"
[[ "$HTTP_CODE" == "405" ]] || fail "GET em alterar-status-aluno.php deveria retornar 405; retornou $HTTP_CODE."

HTTP_CODE="$(curl -sS -o "$TMP_DIR/status-csrf.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=token-invalido" --data-urlencode "id=$ALUNO_ID" --data-urlencode "acao=inativar")"
[[ "$HTTP_CODE" == "403" ]] || fail "Status de aluno sem CSRF válido deveria retornar 403; retornou $HTTP_CODE."

HTTP_CODE="$(curl -sS -o "$TMP_DIR/status-invalid-action.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$EDIT_TOKEN" --data-urlencode "id=$ALUNO_ID" --data-urlencode "acao=excluir")"
[[ "$HTTP_CODE" == "400" ]] || fail "Ação de status inválida deveria retornar 400; retornou $HTTP_CODE."

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$EDIT_TOKEN" --data-urlencode "id=$ALUNO_ID" --data-urlencode "acao=inativar" \
    --data-urlencode "busca=Aluno CI Atualizado" --data-urlencode "status=ativos" \
    -o "$TMP_DIR/student-inactivated.html"
assert_contains "$TMP_DIR/student-inactivated.html" "Aluno inativado com sucesso."
assert_contains "$TMP_DIR/student-inactivated.html" 'id="busca" name="busca"'
assert_contains "$TMP_DIR/student-inactivated.html" 'value="Aluno CI Atualizado"'
assert_contains "$TMP_DIR/student-inactivated.html" '<option value="ativos" selected>Ativos</option>'

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "busca=Aluno CI Atualizado" \
    --data-urlencode "status=ativos" \
    "$BASE_URL/index.php" -o "$TMP_DIR/inactive-hidden-from-default.html"
if grep -Fq "<strong>Aluno CI Atualizado</strong>" "$TMP_DIR/inactive-hidden-from-default.html"; then
    fail "Aluno inativo apareceu no filtro padrão de ativos."
fi

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "busca=Aluno CI Atualizado" \
    --data-urlencode "status=inativos" \
    "$BASE_URL/index.php" -o "$TMP_DIR/inactive-filter.html"
assert_contains "$TMP_DIR/inactive-filter.html" "<strong>Aluno CI Atualizado</strong>"

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "busca=Aluno CI Atualizado" \
    --data-urlencode "status=todos" \
    "$BASE_URL/index.php" -o "$TMP_DIR/all-statuses-filter.html"
assert_contains "$TMP_DIR/all-statuses-filter.html" "<strong>Aluno CI Atualizado</strong>"

INATIVO="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID AND ativo = 0")"
[[ "$INATIVO" == "1" ]] || fail "A inativação não foi persistida."

AUDIT_INATIVACAO="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND entidade = 'alunos' AND entidade_id = $ALUNO_ID AND acao = 'aluno_inativado'")"
[[ "$AUDIT_INATIVACAO" == "1" ]] || fail "A inativação não gerou exatamente um evento de auditoria."

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$EDIT_TOKEN" --data-urlencode "id=$ALUNO_ID" --data-urlencode "acao=inativar" \
    --data-urlencode "busca=Aluno CI Atualizado" --data-urlencode "status=ativos" \
    -o "$TMP_DIR/student-inactivated-again.html"
assert_contains "$TMP_DIR/student-inactivated-again.html" "O aluno já estava inativo."
AUDIT_INATIVACAO_REPETIDA="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND entidade = 'alunos' AND entidade_id = $ALUNO_ID AND acao = 'aluno_inativado'")"
[[ "$AUDIT_INATIVACAO_REPETIDA" == "1" ]] || fail "A repetição da inativação duplicou o evento de auditoria."

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$EDIT_TOKEN" --data-urlencode "id=$ALUNO_ID" --data-urlencode "acao=reativar" \
    --data-urlencode "busca=Aluno CI Atualizado" --data-urlencode "status=inativos" \
    -o "$TMP_DIR/student-reactivated.html"
assert_contains "$TMP_DIR/student-reactivated.html" "Aluno reativado com sucesso."
assert_contains "$TMP_DIR/student-reactivated.html" 'id="busca" name="busca"'
assert_contains "$TMP_DIR/student-reactivated.html" 'value="Aluno CI Atualizado"'
assert_contains "$TMP_DIR/student-reactivated.html" '<option value="inativos" selected>Inativos</option>'
ATIVO="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID AND ativo = 1")"
[[ "$ATIVO" == "1" ]] || fail "A reativação não foi persistida."
AUDIT_REATIVACAO="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND entidade = 'alunos' AND entidade_id = $ALUNO_ID AND acao = 'aluno_reativado'")"
[[ "$AUDIT_REATIVACAO" == "1" ]] || fail "A reativação não gerou exatamente um evento de auditoria."

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "id=$ALUNO_ID" \
    --data-urlencode "busca=Aluno CI Atualizado" \
    --data-urlencode "status=inativos" \
    "$BASE_URL/detalhes-aluno.php" -o "$TMP_DIR/detail-history.html"
assert_contains "$TMP_DIR/detail-history.html" "Aluno CI Atualizado"
assert_contains "$TMP_DIR/detail-history.html" "Dados cadastrais atualizados"
assert_contains "$TMP_DIR/detail-history.html" "Aluno inativado"
assert_contains "$TMP_DIR/detail-history.html" "Aluno reativado"
assert_contains "$TMP_DIR/detail-history.html" "Por Usuário de Teste CI"

echo "5.5/10 - Alterando status diretamente pelo perfil..."
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$EDIT_TOKEN" --data-urlencode "id=$ALUNO_ID" \
    --data-urlencode "acao=inativar" --data-urlencode "retorno=detalhes" \
    --data-urlencode "busca=Aluno CI Atualizado" --data-urlencode "status=ativos" \
    -o "$TMP_DIR/detail-inactivated.html"
assert_contains "$TMP_DIR/detail-inactivated.html" "Aluno inativado com sucesso."
assert_contains "$TMP_DIR/detail-inactivated.html" "Inativo"
DETAIL_INACTIVE_COUNT="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID AND ativo = 0")"
[[ "$DETAIL_INACTIVE_COUNT" == "1" ]] || fail "A ação de status pelo perfil não inativou o aluno."

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$EDIT_TOKEN" --data-urlencode "id=$ALUNO_ID" \
    --data-urlencode "acao=reativar" --data-urlencode "retorno=detalhes" \
    --data-urlencode "busca=Aluno CI Atualizado" --data-urlencode "status=inativos" \
    -o "$TMP_DIR/detail-reactivated.html"
assert_contains "$TMP_DIR/detail-reactivated.html" "Aluno reativado com sucesso."
assert_contains "$TMP_DIR/detail-reactivated.html" "Ativo"
DETAIL_ACTIVE_COUNT="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID AND ativo = 1")"
[[ "$DETAIL_ACTIVE_COUNT" == "1" ]] || fail "A ação de reativação pelo perfil não reativou o aluno."

echo "6/10 - Confirmando que a exclusão física foi aposentada..."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/legacy-delete-get.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/excluir.php?id=$ALUNO_ID")"
[[ "$HTTP_CODE" == "410" ]] || fail "GET em excluir.php deveria retornar 410; retornou $HTTP_CODE."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/legacy-delete-post.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/excluir.php" \
    --data-urlencode "token=$EDIT_TOKEN" --data-urlencode "id=$ALUNO_ID")"
[[ "$HTTP_CODE" == "410" ]] || fail "POST em excluir.php deveria retornar 410; retornou $HTTP_CODE."
AINDA_EXISTE="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID")"
[[ "$AINDA_EXISTE" == "1" ]] || fail "O endpoint legado removeu ou perdeu o registro."

echo "7/10 - Conferindo autoria dos eventos de auditoria..."
USUARIO_ID="$(db_value "SELECT id FROM usuarios WHERE email = 'provision-ci@example.test' LIMIT 1")"
EVENTOS="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND entidade = 'alunos' AND entidade_id = $ALUNO_ID AND acao IN ('aluno_criado', 'aluno_editado', 'aluno_inativado', 'aluno_reativado')")"
[[ "$EVENTOS" == "6" ]] || fail "Esperava 6 eventos de criação/edição/status associados ao usuário, incluindo as duas ações executadas pelo perfil; encontrei $EVENTOS."
LOGIN_EVENTOS="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND acao = 'login'")"
[[ "$LOGIN_EVENTOS" == "3" ]] || fail "O login não corresponde aos três logins válidos realizados até este ponto."

echo "8/10 - Revogando sessão quando a conta é desativada..."
db_exec "UPDATE usuarios SET ativo = 0 WHERE id = $USUARIO_ID"
HTTP_CODE="$(curl -sS -o "$TMP_DIR/disabled-account.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/index.php")"
[[ "$HTTP_CODE" == "303" ]] || fail "Conta desativada manteve acesso ao painel."
db_exec "UPDATE usuarios SET ativo = 1 WHERE id = $USUARIO_ID"

curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/login-reactivated.html"
LOGIN_TOKEN="$(get_token "$TMP_DIR/login-reactivated.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$LOGIN_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_NEW_PASSWORD" \
    -o "$TMP_DIR/login-reactivated-success.html"
assert_contains "$TMP_DIR/login-reactivated-success.html" "Visão geral"

LOGIN_EVENTOS="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND acao = 'login'")"
[[ "$LOGIN_EVENTOS" == "4" ]] || fail "O login após reativação não foi registrado corretamente."

echo "8.9/10 - Testando renovação de sessão e proteção CSRF..."
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/index.php" -o "$TMP_DIR/heartbeat-dashboard.html"
HEARTBEAT_TOKEN="$(grep -oE 'name="fitcontrol-csrf-token" content="[^"]+"' "$TMP_DIR/heartbeat-dashboard.html" | sed -E 's/.*content="([^"]+)"/\1/')"
[[ -n "$HEARTBEAT_TOKEN" ]] || fail "O dashboard não disponibilizou o token para o monitor de sessão."
HEARTBEAT_SESSION_ID="$(awk '$6 == "FITCONTROLSESSID" {print $7}' "$COOKIE_JAR" | tail -n 1)"
[[ -n "$HEARTBEAT_SESSION_ID" ]] || fail "Não foi possível identificar a sessão para renovação."
SESSION_ID="$HEARTBEAT_SESSION_ID" php -r '
    session_name("FITCONTROLSESSID");
    session_id(getenv("SESSION_ID"));
    session_start();
    $_SESSION["usuario_ultimo_acesso"] = time() - 1780;
    session_write_close();
'
HTTP_CODE="$(curl -sS -o "$TMP_DIR/heartbeat-success.json" -w '%{http_code}' \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/renovar-sessao.php" \
    --data-urlencode "token=$HEARTBEAT_TOKEN")"
[[ "$HTTP_CODE" == "200" ]] || fail "A renovação válida deveria retornar HTTP 200; retornou $HTTP_CODE."
assert_contains "$TMP_DIR/heartbeat-success.json" '"ok":true'
HTTP_CODE="$(curl -sS -o "$TMP_DIR/heartbeat-invalid-csrf.json" -w '%{http_code}' \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/renovar-sessao.php" \
    --data-urlencode "token=token-invalido")"
[[ "$HTTP_CODE" == "403" ]] || fail "A renovação deveria rejeitar CSRF inválido com HTTP 403; retornou $HTTP_CODE."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/heartbeat-method.json" -w '%{http_code}' "$BASE_URL/renovar-sessao.php")"
[[ "$HTTP_CODE" == "405" ]] || fail "GET em renovar-sessao.php deveria retornar HTTP 405; retornou $HTTP_CODE."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/heartbeat-dashboard-after.html" -w '%{http_code}' \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/index.php")"
[[ "$HTTP_CODE" == "200" ]] || fail "A sessão renovada não continuou acessando o dashboard."

echo "9/10 - Encerrando sessão por POST com CSRF..."
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/index.php" -o "$TMP_DIR/dashboard-before-logout.html"
LOGOUT_TOKEN="$(get_token "$TMP_DIR/dashboard-before-logout.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/logout.php" --data-urlencode "token=$LOGOUT_TOKEN" -o "$TMP_DIR/logout.html"
HTTP_CODE="$(curl -sS -o "$TMP_DIR/after-logout.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/index.php")"
[[ "$HTTP_CODE" == "303" ]] || fail "A sessão continuou acessando o painel após logout."
LOGOUT_EVENTOS="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND acao = 'logout'")"
[[ "$LOGOUT_EVENTOS" == "1" ]] || fail "O logout não foi registrado."

echo "9.1/10 - Expirando sessão após 30 minutos de inatividade..."
IDLE_JAR="$TMP_DIR/idle-session-cookies.txt"
curl -fsS -c "$IDLE_JAR" -b "$IDLE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/idle-login.html"
IDLE_TOKEN="$(get_token "$TMP_DIR/idle-login.html")"
curl -fsS -L -c "$IDLE_JAR" -b "$IDLE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$IDLE_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_NEW_PASSWORD" \
    -o "$TMP_DIR/idle-login-success.html"
assert_contains "$TMP_DIR/idle-login-success.html" "Visão geral"
IDLE_SESSION_ID="$(awk '$6 == "FITCONTROLSESSID" {print $7}' "$IDLE_JAR" | tail -n 1)"
[[ -n "$IDLE_SESSION_ID" ]] || fail "Não foi possível identificar a sessão de teste por inatividade."
SESSION_ID="$IDLE_SESSION_ID" php -r '
    session_name("FITCONTROLSESSID");
    session_id(getenv("SESSION_ID"));
    session_start();
    $_SESSION["usuario_ultimo_acesso"] = time() - 1801;
    session_write_close();
'
curl -sS -L -c "$IDLE_JAR" -b "$IDLE_JAR" "$BASE_URL/index.php" -o "$TMP_DIR/idle-expired.html"
assert_contains "$TMP_DIR/idle-expired.html" "Sua sessão expirou por inatividade ou pelo tempo máximo de acesso."

echo "9.2/10 - Aplicando limite absoluto de oito horas..."
ABSOLUTE_JAR="$TMP_DIR/absolute-session-cookies.txt"
curl -fsS -c "$ABSOLUTE_JAR" -b "$ABSOLUTE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/absolute-login.html"
ABSOLUTE_TOKEN="$(get_token "$TMP_DIR/absolute-login.html")"
curl -fsS -L -c "$ABSOLUTE_JAR" -b "$ABSOLUTE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$ABSOLUTE_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_NEW_PASSWORD" \
    -o "$TMP_DIR/absolute-login-success.html"
assert_contains "$TMP_DIR/absolute-login-success.html" "Visão geral"
ABSOLUTE_SESSION_ID="$(awk '$6 == "FITCONTROLSESSID" {print $7}' "$ABSOLUTE_JAR" | tail -n 1)"
[[ -n "$ABSOLUTE_SESSION_ID" ]] || fail "Não foi possível identificar a sessão de teste absoluto."
SESSION_ID="$ABSOLUTE_SESSION_ID" php -r '
    session_name("FITCONTROLSESSID");
    session_id(getenv("SESSION_ID"));
    session_start();
    $_SESSION["usuario_autenticado_em"] = time() - 28801;
    $_SESSION["usuario_ultimo_acesso"] = time() - 60;
    session_write_close();
'
curl -sS -L -c "$ABSOLUTE_JAR" -b "$ABSOLUTE_JAR" "$BASE_URL/index.php" -o "$TMP_DIR/absolute-expired.html"
assert_contains "$TMP_DIR/absolute-expired.html" "Sua sessão expirou por inatividade ou pelo tempo máximo de acesso."



echo "9.5/10 - Validando catálogo de planos pela interface autenticada..."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/plans-anonymous.html" -w '%{http_code}' "$BASE_URL/planos.php")"
[[ "$HTTP_CODE" == "303" ]] || fail "Catálogo de planos anônimo deveria redirecionar para login; retornou $HTTP_CODE."

curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/plans-login.html"
PLANS_LOGIN_TOKEN="$(get_token "$TMP_DIR/plans-login.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$PLANS_LOGIN_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_NEW_PASSWORD" \
    -o "$TMP_DIR/plans-login-success.html"
assert_contains "$TMP_DIR/plans-login-success.html" "Visão geral"

curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/planos.php" -o "$TMP_DIR/plans-not-installed.html"
assert_contains "$TMP_DIR/plans-not-installed.html" "Catálogo ainda não instalado"

# O fixture é exclusivo deste processo de CI. Não aplica nem antecipa migrations do projeto.
PLAN_TABLES_BEFORE="$(db_value "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('planos', 'matriculas', 'revisoes_legado')")"
[[ "$PLAN_TABLES_BEFORE" == "0" ]] || fail "O fixture de planos encontrou tabelas já existentes; não vai sobrescrevê-las."

db_exec "CREATE TABLE planos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao VARCHAR(255) NULL,
    duracao_valor SMALLINT UNSIGNED NULL,
    duracao_unidade ENUM('DIA','SEMANA','MES') NULL,
    valor_atual DECIMAL(10,2) NULL,
    situacao ENUM('RASCUNHO','ATIVO','INATIVO') NOT NULL DEFAULT 'RASCUNHO',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_plan_management_status_name (situacao, nome)
) ENGINE=InnoDB"

db_exec "CREATE TABLE matriculas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plano_id INT UNSIGNED NULL,
    plano_nome_contratado VARCHAR(100) NULL,
    valor_contratado DECIMAL(10,2) NULL,
    KEY idx_plan_management_plan (plano_id),
    CONSTRAINT fk_plan_management_plan
        FOREIGN KEY (plano_id) REFERENCES planos (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB"

curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/planos.php" -o "$TMP_DIR/plans-ready.html"
assert_contains "$TMP_DIR/plans-ready.html" "Adicionar plano"
HTTP_CODE="$(curl -sS -o "$TMP_DIR/manage-get.html" -w '%{http_code}' "$BASE_URL/gerenciar-plano.php")"
[[ "$HTTP_CODE" == "405" ]] || fail "GET em gerenciar-plano.php deveria retornar 405; retornou $HTTP_CODE."

PLAN_TOKEN="$(get_token "$TMP_DIR/plans-ready.html")"

HTTP_CODE="$(curl -sS -o "$TMP_DIR/plans-csrf.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/salvar-plano.php" \
    --data-urlencode "token=token-invalido" --data-urlencode "nome=Plano sem CSRF")"
[[ "$HTTP_CODE" == "403" ]] || fail "Salvar plano sem CSRF deveria retornar 403; retornou $HTTP_CODE."

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/salvar-plano.php" \
    --data-urlencode "token=$PLAN_TOKEN" \
    --data-urlencode "nome=Plano CI em preparação" \
    --data-urlencode "descricao=Rascunho criado pelo teste HTTP" \
    -o "$TMP_DIR/plan-created.html"
assert_contains "$TMP_DIR/plan-created.html" "Rascunho de plano criado com sucesso."
PLAN_ID="$(db_value "SELECT id FROM planos WHERE nome = 'Plano CI em preparação' LIMIT 1")"
[[ "$PLAN_ID" =~ ^[0-9]+$ ]] || fail "A interface não criou o rascunho de plano."
PLAN_DRAFT="$(db_value "SELECT COUNT(*) FROM planos WHERE id = $PLAN_ID AND situacao = 'RASCUNHO' AND valor_atual IS NULL AND duracao_valor IS NULL")"
[[ "$PLAN_DRAFT" == "1" ]] || fail "O plano incompleto não permaneceu como rascunho."

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "editar=$PLAN_ID" "$BASE_URL/planos.php" -o "$TMP_DIR/plan-edit-form.html"
assert_contains "$TMP_DIR/plan-edit-form.html" "Editar plano"
assert_contains "$TMP_DIR/plan-edit-form.html" 'value="Plano CI em preparação"'

PLAN_TOKEN="$(get_token "$TMP_DIR/plan-created.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/salvar-plano.php" \
    --data-urlencode "token=$PLAN_TOKEN" \
    --data-urlencode "id=$PLAN_ID" \
    --data-urlencode "nome=Plano CI Mensal" \
    --data-urlencode "descricao=Plano atualizado pela interface" \
    --data-urlencode "duracao_valor=1" \
    --data-urlencode "duracao_unidade=MES" \
    --data-urlencode "valor_atual=149,90" \
    -o "$TMP_DIR/plan-updated.html"
assert_contains "$TMP_DIR/plan-updated.html" "Plano atualizado com sucesso."
PLAN_VALID="$(db_value "SELECT COUNT(*) FROM planos WHERE id = $PLAN_ID AND nome = 'Plano CI Mensal' AND valor_atual = 149.90 AND duracao_valor = 1 AND duracao_unidade = 'MES'")"
[[ "$PLAN_VALID" == "1" ]] || fail "A edição não normalizou nem persistiu os dados do plano."

HTTP_CODE="$(curl -sS -o "$TMP_DIR/plan-manage-csrf.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/gerenciar-plano.php" \
    --data-urlencode "token=token-invalido" --data-urlencode "id=$PLAN_ID" --data-urlencode "acao=ativar")"
[[ "$HTTP_CODE" == "403" ]] || fail "Gerenciar plano sem CSRF deveria retornar 403; retornou $HTTP_CODE."

PLAN_TOKEN="$(get_token "$TMP_DIR/plan-updated.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/gerenciar-plano.php" \
    --data-urlencode "token=$PLAN_TOKEN" --data-urlencode "id=$PLAN_ID" --data-urlencode "acao=ativar" \
    -o "$TMP_DIR/plan-activated.html"
assert_contains "$TMP_DIR/plan-activated.html" "Plano ativado"
PLAN_ACTIVE="$(db_value "SELECT COUNT(*) FROM planos WHERE id = $PLAN_ID AND situacao = 'ATIVO'")"
[[ "$PLAN_ACTIVE" == "1" ]] || fail "A ativação do plano não foi persistida."

db_exec "INSERT INTO matriculas (plano_id, plano_nome_contratado, valor_contratado)
    VALUES ($PLAN_ID, 'Plano CI Mensal', 149.90)"

PLAN_TOKEN="$(get_token "$TMP_DIR/plan-activated.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/gerenciar-plano.php" \
    --data-urlencode "token=$PLAN_TOKEN" --data-urlencode "id=$PLAN_ID" --data-urlencode "acao=excluir" \
    -o "$TMP_DIR/plan-delete-blocked.html"
assert_contains "$TMP_DIR/plan-delete-blocked.html" "possui matrículas vinculadas"
PLAN_STILL_EXISTS="$(db_value "SELECT COUNT(*) FROM planos WHERE id = $PLAN_ID")"
[[ "$PLAN_STILL_EXISTS" == "1" ]] || fail "A interface removeu um plano com matrícula vinculada."

PLAN_TOKEN="$(get_token "$TMP_DIR/plan-delete-blocked.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/gerenciar-plano.php" \
    --data-urlencode "token=$PLAN_TOKEN" --data-urlencode "id=$PLAN_ID" --data-urlencode "acao=inativar" \
    -o "$TMP_DIR/plan-deactivated.html"
assert_contains "$TMP_DIR/plan-deactivated.html" "Plano inativado"
PLAN_INACTIVE="$(db_value "SELECT COUNT(*) FROM planos WHERE id = $PLAN_ID AND situacao = 'INATIVO'")"
[[ "$PLAN_INACTIVE" == "1" ]] || fail "A inativação não foi persistida."

PLAN_SNAPSHOT="$(db_value "SELECT COUNT(*) FROM matriculas WHERE plano_id = $PLAN_ID AND plano_nome_contratado = 'Plano CI Mensal' AND valor_contratado = 149.90")"
[[ "$PLAN_SNAPSHOT" == "1" ]] || fail "A inativação alterou o snapshot histórico."

PLAN_TOKEN="$(get_token "$TMP_DIR/plan-deactivated.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/gerenciar-plano.php" \
    --data-urlencode "token=$PLAN_TOKEN" --data-urlencode "id=$PLAN_ID" --data-urlencode "acao=ativar" \
    -o "$TMP_DIR/plan-reactivated.html"
assert_contains "$TMP_DIR/plan-reactivated.html" "Plano ativado"

# Um rascunho sem vínculo pode ser excluído, com auditoria preservada.
PLAN_TOKEN="$(get_token "$TMP_DIR/plan-reactivated.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/salvar-plano.php" \
    --data-urlencode "token=$PLAN_TOKEN" --data-urlencode "nome=Plano CI descartável" \
    -o "$TMP_DIR/plan-unused-created.html"
assert_contains "$TMP_DIR/plan-unused-created.html" "Rascunho de plano criado"
UNUSED_PLAN_ID="$(db_value "SELECT id FROM planos WHERE nome = 'Plano CI descartável' LIMIT 1")"
[[ "$UNUSED_PLAN_ID" =~ ^[0-9]+$ ]] || fail "Não foi possível criar o rascunho descartável."
PLAN_TOKEN="$(get_token "$TMP_DIR/plan-unused-created.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/gerenciar-plano.php" \
    --data-urlencode "token=$PLAN_TOKEN" --data-urlencode "id=$UNUSED_PLAN_ID" --data-urlencode "acao=excluir" \
    -o "$TMP_DIR/plan-unused-deleted.html"
assert_contains "$TMP_DIR/plan-unused-deleted.html" "Plano excluído"
UNUSED_PLAN_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND acao = 'plano_excluido' AND entidade = 'planos' AND entidade_id = $UNUSED_PLAN_ID")"
[[ "$UNUSED_PLAN_AUDIT" == "1" ]] || fail "A exclusão de plano sem vínculo não foi auditada."

PLAN_ACTOR_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND acao = 'plano_ativado' AND entidade = 'planos' AND entidade_id = $PLAN_ID")"
[[ "$PLAN_ACTOR_AUDIT" == "2" ]] || fail "Ativação inicial e reativação não foram auditadas pelo funcionário correto."

# Amplia o fixture descartável para validar o histórico no perfil do aluno.
db_exec "ALTER TABLE matriculas
    ADD COLUMN aluno_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN duracao_contratada_valor SMALLINT UNSIGNED NULL,
    ADD COLUMN duracao_contratada_unidade ENUM('DIA','SEMANA','MES') NULL,
    ADD COLUMN inicio_em DATETIME NULL,
    ADD COLUMN fim_exclusivo_em DATETIME NULL,
    ADD COLUMN dia_referencia_calendario TINYINT UNSIGNED NULL,
    ADD COLUMN cancelada_em DATETIME NULL,
    ADD COLUMN origem ENUM('SISTEMA','LEGADO') NOT NULL DEFAULT 'SISTEMA',
    ADD COLUMN motivo_cancelamento VARCHAR(255) NULL,
    ADD COLUMN criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"

db_exec "CREATE TABLE revisoes_legado (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    aluno_id_origem INT UNSIGNED NOT NULL,
    plano_texto_original VARCHAR(40) NOT NULL DEFAULT '',
    resultado_atual ENUM('VINCULO_CONFIRMADO','LEGADO_SEM_VIGENCIA','PENDENTE_REVISAO')
        NOT NULL DEFAULT 'PENDENTE_REVISAO',
    fonte_referencia VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_profile_legacy_student (aluno_id_origem)
) ENGINE=InnoDB"

db_exec "INSERT INTO matriculas (
    aluno_id, plano_id, plano_nome_contratado, valor_contratado,
    duracao_contratada_valor, duracao_contratada_unidade,
    inicio_em, fim_exclusivo_em, origem
) VALUES (
    $ALUNO_ID, $PLAN_ID, 'Plano CI Mensal', 149.90,
    1, 'MES', UTC_TIMESTAMP() - INTERVAL 1 DAY,
    UTC_TIMESTAMP() + INTERVAL 30 DAY, 'SISTEMA'
)"

db_exec "INSERT INTO matriculas (
    aluno_id, plano_id, plano_nome_contratado, valor_contratado,
    duracao_contratada_valor, duracao_contratada_unidade,
    inicio_em, fim_exclusivo_em, cancelada_em, origem, motivo_cancelamento
) VALUES (
    $ALUNO_ID, $PLAN_ID, 'Plano CI Cancelado', 99.90,
    1, 'MES', UTC_TIMESTAMP() - INTERVAL 2 DAY,
    UTC_TIMESTAMP() + INTERVAL 28 DAY,
    UTC_TIMESTAMP() - INTERVAL 1 HOUR, 'SISTEMA', 'Teste de preservação histórica'
)"

db_exec "INSERT INTO matriculas (
    aluno_id, plano_id, plano_nome_contratado, valor_contratado,
    duracao_contratada_valor, duracao_contratada_unidade,
    inicio_em, fim_exclusivo_em, origem
) VALUES (
    $ALUNO_ID, $PLAN_ID, 'Plano CI Agendado para Cancelar', 79.90,
    1, 'MES', UTC_TIMESTAMP() + INTERVAL 45 DAY,
    UTC_TIMESTAMP() + INTERVAL 75 DAY, 'SISTEMA'
)"

db_exec "INSERT INTO revisoes_legado (
    aluno_id_origem, plano_texto_original, resultado_atual, fonte_referencia
) VALUES ($ALUNO_ID, 'Plano CI Mensal legado', 'PENDENTE_REVISAO', 'Fixture automatizado de CI')"

db_exec "CREATE TABLE revisoes_legado_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    revisao_legado_id BIGINT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    tipo_evento ENUM('DECISAO','RETIFICACAO_VIGENCIA') NOT NULL,
    resultado_anterior ENUM('VINCULO_CONFIRMADO','LEGADO_SEM_VIGENCIA','PENDENTE_REVISAO') NOT NULL,
    resultado_novo ENUM('VINCULO_CONFIRMADO','LEGADO_SEM_VIGENCIA','PENDENTE_REVISAO') NOT NULL,
    matricula_id BIGINT UNSIGNED NULL,
    inicio_anterior DATETIME NULL,
    fim_exclusivo_anterior DATETIME NULL,
    inicio_novo DATETIME NULL,
    fim_exclusivo_novo DATETIME NULL,
    fonte_referencia VARCHAR(255) NULL,
    observacoes TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ci_review_event (revisao_legado_id, criado_em)
) ENGINE=InnoDB"

REVIEW_ID="$(db_value "SELECT id FROM revisoes_legado WHERE aluno_id_origem = $ALUNO_ID")"
[[ "$REVIEW_ID" =~ ^[0-9]+$ ]] || fail "O fixture não criou o registro de revisão legado."

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "id=$ALUNO_ID" "$BASE_URL/detalhes-aluno.php" \
    -o "$TMP_DIR/detail-enrollments.html"
assert_contains "$TMP_DIR/detail-enrollments.html" "Histórico de matrículas"
assert_contains "$TMP_DIR/detail-enrollments.html" "Plano CI Mensal"
assert_contains "$TMP_DIR/detail-enrollments.html" "Plano CI Cancelado"
assert_contains "$TMP_DIR/detail-enrollments.html" "Ativa"
assert_contains "$TMP_DIR/detail-enrollments.html" "Cancelada"
assert_contains "$TMP_DIR/detail-enrollments.html" "R$ 149,90"
assert_contains "$TMP_DIR/detail-enrollments.html" "Teste de preservação histórica"
assert_contains "$TMP_DIR/detail-enrollments.html" "Revisão do vínculo pendente"
assert_contains "$TMP_DIR/detail-enrollments.html" "Fixture automatizado de CI"
assert_contains "$TMP_DIR/detail-enrollments.html" "Analisar revisão"
assert_contains "$TMP_DIR/detail-enrollments.html" "Plano CI Agendado para Cancelar"
assert_contains "$TMP_DIR/detail-enrollments.html" "Cancelar matrícula"

echo "9.3/10 - Validando cancelamento HTTP de matrícula agendada..."
CANCEL_ENROLLMENT_ID="$(db_value "SELECT id FROM matriculas WHERE aluno_id = $ALUNO_ID AND plano_nome_contratado = 'Plano CI Agendado para Cancelar' LIMIT 1")"
[[ "$CANCEL_ENROLLMENT_ID" =~ ^[0-9]+$ ]] || fail "A matrícula agendada de teste não foi criada."
CANCEL_TOKEN="$(get_token "$TMP_DIR/detail-enrollments.html")"
HTTP_CODE="$(curl -sS -o "$TMP_DIR/enrollment-cancel-no-csrf.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/cancelar-matricula.php" \
    --data-urlencode "token=token-invalido" \
    --data-urlencode "student_id=$ALUNO_ID" \
    --data-urlencode "enrollment_id=$CANCEL_ENROLLMENT_ID")"
[[ "$HTTP_CODE" == "403" ]] || fail "Cancelamento sem CSRF deveria retornar 403; retornou $HTTP_CODE."

HTTP_CODE="$(curl -sS -L -o "$TMP_DIR/enrollment-cancelled.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/cancelar-matricula.php" \
    --data-urlencode "token=$CANCEL_TOKEN" \
    --data-urlencode "student_id=$ALUNO_ID" \
    --data-urlencode "enrollment_id=$CANCEL_ENROLLMENT_ID" \
    --data-urlencode "motivo_cancelamento=Solicitação do aluno pelo teste HTTP")"
[[ "$HTTP_CODE" == "200" ]] || fail "Cancelamento válido não retornou ao perfil com HTTP 200."
assert_contains "$TMP_DIR/enrollment-cancelled.html" "Matrícula cancelada com sucesso."
assert_contains "$TMP_DIR/enrollment-cancelled.html" "Solicitação do aluno pelo teste HTTP"
RESPONSAVEL_CANCELAMENTO="$(db_value "SELECT nome FROM usuarios WHERE id = $USUARIO_ID")"
assert_contains "$TMP_DIR/enrollment-cancelled.html" "$RESPONSAVEL_CANCELAMENTO"

CANCELLED_ROW="$(db_value "SELECT COUNT(*) FROM matriculas
    WHERE id = $CANCEL_ENROLLMENT_ID
      AND cancelada_em IS NOT NULL
      AND motivo_cancelamento = 'Solicitação do aluno pelo teste HTTP'")"
[[ "$CANCELLED_ROW" == "1" ]] || fail "O cancelamento não preservou instante e motivo."
CANCEL_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria
    WHERE usuario_id = $USUARIO_ID
      AND acao = 'matricula_cancelada'
      AND entidade = 'matriculas'
      AND entidade_id = $CANCEL_ENROLLMENT_ID")"
[[ "$CANCEL_AUDIT" == "1" ]] || fail "O cancelamento não foi auditado pelo funcionário autenticado."

echo "9.4/10 - Validando retificação de vigência legada pela interface HTTP..."
# Cria uma matrícula de origem LEGADO e uma decisão confirmada fictícias.
LEGACY_START_UTC="$(php -r 'echo (new DateTimeImmutable("-90 days", new DateTimeZone("UTC")))->format("Y-m-d H:i:s");')"
LEGACY_END_UTC="$(php -r 'echo (new DateTimeImmutable("-60 days", new DateTimeZone("UTC")))->format("Y-m-d H:i:s");')"
db_exec "INSERT INTO matriculas (
    aluno_id, plano_id, plano_nome_contratado, valor_contratado,
    duracao_contratada_valor, duracao_contratada_unidade,
    inicio_em, fim_exclusivo_em, origem
) VALUES (
    $ALUNO_ID, $PLAN_ID, 'Vínculo legado CI', 149.90,
    NULL, NULL, '$LEGACY_START_UTC', '$LEGACY_END_UTC', 'LEGADO'
)"
LEGACY_ENROLLMENT_ID="$(db_value "SELECT id FROM matriculas WHERE aluno_id = $ALUNO_ID AND origem = 'LEGADO' ORDER BY id DESC LIMIT 1")"
[[ "$LEGACY_ENROLLMENT_ID" =~ ^[0-9]+$ ]] || fail "O fixture não criou a matrícula legada."
db_exec "UPDATE revisoes_legado
    SET resultado_atual = 'VINCULO_CONFIRMADO',
        fonte_referencia = 'Contrato físico, pasta CI'
    WHERE id = $REVIEW_ID"
db_exec "INSERT INTO revisoes_legado_eventos (
    revisao_legado_id, usuario_id, tipo_evento, resultado_anterior,
    resultado_novo, matricula_id, inicio_novo, fim_exclusivo_novo,
    fonte_referencia, observacoes
) VALUES (
    $REVIEW_ID, $USUARIO_ID, 'DECISAO', 'PENDENTE_REVISAO',
    'VINCULO_CONFIRMADO', $LEGACY_ENROLLMENT_ID,
    '$LEGACY_START_UTC', '$LEGACY_END_UTC',
    'Contrato físico, pasta CI', 'Intervalo fictício comprovado para teste HTTP'
)"

HTTP_CODE="$(curl -sS -o "$TMP_DIR/rectification-anonymous.html" -w '%{http_code}' \
    "$BASE_URL/retificar-vigencia-legado.php?review_id=$REVIEW_ID")"
[[ "$HTTP_CODE" == "303" ]] || fail "Retificação anônima deveria redirecionar para login; retornou $HTTP_CODE."
curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "review_id=$REVIEW_ID" \
    "$BASE_URL/retificar-vigencia-legado.php" -o "$TMP_DIR/rectification-form.html"
assert_contains "$TMP_DIR/rectification-form.html" "Retificar vigência legada"
assert_contains "$TMP_DIR/rectification-form.html" "Novo início local"
assert_contains "$TMP_DIR/rectification-form.html" "Novo fim exclusivo local"
assert_contains "$TMP_DIR/rectification-form.html" "Referência da nova evidência"
RECTIFICATION_TOKEN="$(get_token "$TMP_DIR/rectification-form.html")"

HTTP_CODE="$(curl -sS -o "$TMP_DIR/rectification-get.html" -w '%{http_code}' \
    "$BASE_URL/salvar-retificacao-legado.php")"
[[ "$HTTP_CODE" == "405" ]] || fail "Endpoint de retificação deveria rejeitar GET com 405; retornou $HTTP_CODE."

HTTP_CODE="$(curl -sS -o "$TMP_DIR/rectification-no-csrf.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/salvar-retificacao-legado.php" \
    --data-urlencode "token=token-invalido" \
    --data-urlencode "review_id=$REVIEW_ID" \
    --data-urlencode "enrollment_id=$LEGACY_ENROLLMENT_ID")"
[[ "$HTTP_CODE" == "403" ]] || fail "Retificação sem CSRF deveria retornar 403; retornou $HTTP_CODE."

NEW_LEGACY_START="$(php -r 'echo (new DateTimeImmutable("-80 days", new DateTimeZone("America/Sao_Paulo")))->format("Y-m-d\\TH:i:s");')"
NEW_LEGACY_END="$(php -r 'echo (new DateTimeImmutable("-50 days", new DateTimeZone("America/Sao_Paulo")))->format("Y-m-d\\TH:i:s");')"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/salvar-retificacao-legado.php" \
    --data-urlencode "token=$RECTIFICATION_TOKEN" \
    --data-urlencode "review_id=$REVIEW_ID" \
    --data-urlencode "enrollment_id=$LEGACY_ENROLLMENT_ID" \
    --data-urlencode "inicio_em=$NEW_LEGACY_START" \
    --data-urlencode "fim_exclusivo_em=$NEW_LEGACY_END" \
    --data-urlencode "fonte_referencia=Contrato original assinado, folha 12" \
    --data-urlencode "observacoes=Correção baseada na conferência do contrato físico." \
    -o "$TMP_DIR/rectification-saved.html"
assert_contains "$TMP_DIR/rectification-saved.html" "Retificação registrada com sucesso."
RECTIFIED_START_UTC="$(VALUE="$NEW_LEGACY_START" php -r '$d=new DateTimeImmutable(getenv("VALUE"),new DateTimeZone("America/Sao_Paulo")); echo $d->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s");')"
RECTIFIED_END_UTC="$(VALUE="$NEW_LEGACY_END" php -r '$d=new DateTimeImmutable(getenv("VALUE"),new DateTimeZone("America/Sao_Paulo")); echo $d->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s");')"
RECTIFIED_ROW="$(db_value "SELECT COUNT(*) FROM matriculas
    WHERE id = $LEGACY_ENROLLMENT_ID
      AND inicio_em = '$RECTIFIED_START_UTC'
      AND fim_exclusivo_em = '$RECTIFIED_END_UTC'
      AND origem = 'LEGADO'")"
[[ "$RECTIFIED_ROW" == "1" ]] || fail "A interface não persistiu o novo intervalo em UTC."
RECTIFICATION_EVENT_COUNT="$(db_value "SELECT COUNT(*) FROM revisoes_legado_eventos
    WHERE revisao_legado_id = $REVIEW_ID
      AND tipo_evento = 'RETIFICACAO_VIGENCIA'
      AND matricula_id = $LEGACY_ENROLLMENT_ID
      AND fonte_referencia = 'Contrato original assinado, folha 12'")"
[[ "$RECTIFICATION_EVENT_COUNT" == "1" ]] || fail "A retificação não gerou exatamente um evento especializado."
RECTIFICATION_AUDIT_COUNT="$(db_value "SELECT COUNT(*) FROM auditoria
    WHERE usuario_id = $USUARIO_ID
      AND acao = 'matricula_corrigida'
      AND entidade = 'matriculas'
      AND entidade_id = $LEGACY_ENROLLMENT_ID")"
[[ "$RECTIFICATION_AUDIT_COUNT" == "1" ]] || fail "A retificação não gerou auditoria geral."
RECTIFICATION_FORM_TOKEN="$(get_token "$TMP_DIR/rectification-saved.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/salvar-retificacao-legado.php" \
    --data-urlencode "token=$RECTIFICATION_FORM_TOKEN" \
    --data-urlencode "review_id=$REVIEW_ID" \
    --data-urlencode "enrollment_id=$LEGACY_ENROLLMENT_ID" \
    --data-urlencode "inicio_em=$NEW_LEGACY_START" \
    --data-urlencode "fim_exclusivo_em=$NEW_LEGACY_END" \
    --data-urlencode "fonte_referencia=Contrato original assinado, folha 12" \
    --data-urlencode "observacoes=Correção baseada na conferência do contrato físico." \
    -o "$TMP_DIR/rectification-repeated.html"
RECTIFICATION_EVENT_COUNT_AFTER_REPEAT="$(db_value "SELECT COUNT(*) FROM revisoes_legado_eventos
    WHERE revisao_legado_id = $REVIEW_ID AND tipo_evento = 'RETIFICACAO_VIGENCIA'")"
[[ "$RECTIFICATION_EVENT_COUNT_AFTER_REPEAT" == "1" ]] || fail "A repetição idempotente duplicou o evento de retificação."

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "filtro=todos" --data-urlencode "review_id=$REVIEW_ID" \
    "$BASE_URL/revisoes-legado.php" -o "$TMP_DIR/rectification-history.html"
assert_contains "$TMP_DIR/rectification-history.html" "Retificar vigência comprovada"
assert_contains "$TMP_DIR/rectification-history.html" "Intervalo anterior:"
assert_contains "$TMP_DIR/rectification-history.html" "Novo intervalo:"
assert_contains "$TMP_DIR/rectification-history.html" "Contrato original assinado, folha 12"

# Restaura o fixture ao estado pendente para executar, em seguida, o teste original de decisão.
db_exec "UPDATE revisoes_legado SET resultado_atual = 'PENDENTE_REVISAO' WHERE id = $REVIEW_ID"
db_exec "INSERT INTO revisoes_legado_eventos (
    revisao_legado_id, usuario_id, tipo_evento, resultado_anterior,
    resultado_novo, fonte_referencia, observacoes
) VALUES (
    $REVIEW_ID, $USUARIO_ID, 'DECISAO', 'VINCULO_CONFIRMADO',
    'PENDENTE_REVISAO', 'Fixture automatizado de CI',
    'Retificação validada; cenário seguinte testa reabertura da revisão'
)"

echo "9.4/10 - Validando fila administrativa e decisão auditada de revisão..."
curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "filtro=pendentes" "$BASE_URL/revisoes-legado.php" \
    -o "$TMP_DIR/legacy-review-queue.html"
assert_contains "$TMP_DIR/legacy-review-queue.html" "Revisão de vínculos legados"
assert_contains "$TMP_DIR/legacy-review-queue.html" "Aluno #$ALUNO_ID"
assert_contains "$TMP_DIR/legacy-review-queue.html" "Plano CI"
assert_contains "$TMP_DIR/legacy-review-queue.html" "Analisar"

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "filtro=todos" --data-urlencode "review_id=$REVIEW_ID" \
    "$BASE_URL/revisoes-legado.php" -o "$TMP_DIR/legacy-review-detail.html"
assert_contains "$TMP_DIR/legacy-review-detail.html" "Analisar vínculo legado"
assert_contains "$TMP_DIR/legacy-review-detail.html" "Registrar decisão auditada"

REVIEW_TOKEN="$(get_token "$TMP_DIR/legacy-review-detail.html")"
HTTP_CODE="$(curl -sS -o "$TMP_DIR/legacy-review-no-csrf.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/salvar-revisao-legado.php" \
    --data-urlencode "token=token-invalido" \
    --data-urlencode "review_id=$REVIEW_ID" \
    --data-urlencode "resultado=PENDENTE_REVISAO" \
    --data-urlencode "observacoes=Evidência ainda insuficiente.")"
[[ "$HTTP_CODE" == "403" ]] || fail "Decisão de revisão sem CSRF deveria retornar 403; retornou $HTTP_CODE."

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/salvar-revisao-legado.php" \
    --data-urlencode "token=$REVIEW_TOKEN" \
    --data-urlencode "review_id=$REVIEW_ID" \
    --data-urlencode "resultado=PENDENTE_REVISAO" \
    --data-urlencode "fonte_referencia=Documento ilegível, pasta CI" \
    --data-urlencode "observacoes=Documento recebido não permite confirmar o período exato." \
    -o "$TMP_DIR/legacy-review-saved.html"
assert_contains "$TMP_DIR/legacy-review-saved.html" "Revisão mantida como pendente"
assert_contains "$TMP_DIR/legacy-review-saved.html" "Documento recebido não permite confirmar o período exato."
RESPONSAVEL_REVISAO="$(db_value "SELECT nome FROM usuarios WHERE id = $USUARIO_ID")"
assert_contains "$TMP_DIR/legacy-review-saved.html" "$RESPONSAVEL_REVISAO"

REVIEW_STATE="$(db_value "SELECT resultado_atual FROM revisoes_legado WHERE id = $REVIEW_ID")"
[[ "$REVIEW_STATE" == "PENDENTE_REVISAO" ]] || fail "A decisão não preservou o bloqueio da revisão pendente."
REVIEW_EVENT_COUNT="$(db_value "SELECT COUNT(*) FROM revisoes_legado_eventos WHERE revisao_legado_id = $REVIEW_ID AND resultado_novo = 'PENDENTE_REVISAO' AND fonte_referencia = 'Documento ilegível, pasta CI'")"
[[ "$REVIEW_EVENT_COUNT" == "1" ]] || fail "A decisão não gerou exatamente um evento especializado."
REVIEW_AUDIT_COUNT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND acao = 'revisao_legado_decidida' AND entidade = 'revisoes_legado' AND entidade_id = $REVIEW_ID")"
[[ "$REVIEW_AUDIT_COUNT" == "1" ]] || fail "A decisão não gerou exatamente uma auditoria geral."

curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "id=$ALUNO_ID" "$BASE_URL/detalhes-aluno.php" \
    -o "$TMP_DIR/detail-review-events.html"
assert_contains "$TMP_DIR/detail-review-events.html" "Últimas decisões registradas"
assert_contains "$TMP_DIR/detail-review-events.html" "Documento recebido não permite confirmar o período exato."

echo "9.5/10 - Validando contratação pela interface e bloqueio de revisão pendente..."
curl -fsS -G -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    --data-urlencode "nova_matricula=1" \
    --data-urlencode "aluno_id=$ALUNO_ID" \
    "$BASE_URL/planos.php" -o "$TMP_DIR/enrollment-form.html"
assert_contains "$TMP_DIR/enrollment-form.html" "Nova matrícula"
assert_contains "$TMP_DIR/enrollment-form.html" "Plano CI Mensal"

ENROLLMENT_TOKEN="$(get_token "$TMP_DIR/enrollment-form.html")"
START_DATE="$(php -r 'echo (new DateTimeImmutable("+60 days", new DateTimeZone("America/Sao_Paulo")))->format("Y-m-d");')"
HTTP_CODE="$(curl -sS -o "$TMP_DIR/enrollment-pending.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/planos.php?nova_matricula=1" \
    --data-urlencode "token=$ENROLLMENT_TOKEN" \
    --data-urlencode "acao=criar_matricula" \
    --data-urlencode "aluno_id=$ALUNO_ID" \
    --data-urlencode "plano_id=$PLAN_ID" \
    --data-urlencode "data_inicio=$START_DATE" \
    --data-urlencode "hora_inicio=")"
[[ "$HTTP_CODE" == "422" ]] || fail "Revisão legada pendente deveria bloquear contratação com HTTP 422; retornou $HTTP_CODE."
assert_contains "$TMP_DIR/enrollment-pending.html" "A matrícula está bloqueada enquanto a revisão do vínculo legado estiver pendente."

# A liberação abaixo prepara o cenário de contratação subsequente; a decisão já foi validada pelo endpoint de revisão.
db_exec "UPDATE revisoes_legado SET resultado_atual = 'LEGADO_SEM_VIGENCIA' WHERE aluno_id_origem = $ALUNO_ID"

ENROLLMENT_TOKEN="$(get_token "$TMP_DIR/enrollment-pending.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/planos.php?nova_matricula=1" \
    --data-urlencode "token=$ENROLLMENT_TOKEN" \
    --data-urlencode "acao=criar_matricula" \
    --data-urlencode "aluno_id=$ALUNO_ID" \
    --data-urlencode "plano_id=$PLAN_ID" \
    --data-urlencode "data_inicio=$START_DATE" \
    --data-urlencode "hora_inicio=" \
    -o "$TMP_DIR/enrollment-created.html"
assert_contains "$TMP_DIR/enrollment-created.html" "Matrícula #"
assert_contains "$TMP_DIR/enrollment-created.html" "Plano CI Mensal"
assert_contains "$TMP_DIR/enrollment-created.html" "Agendada"

NEW_ENROLLMENT_ID="$(db_value "SELECT id FROM matriculas
    WHERE aluno_id = $ALUNO_ID
      AND plano_id = $PLAN_ID
      AND plano_nome_contratado = 'Plano CI Mensal'
      AND origem = 'SISTEMA'
      AND inicio_em > UTC_TIMESTAMP()
    ORDER BY id DESC LIMIT 1")"
[[ "$NEW_ENROLLMENT_ID" =~ ^[0-9]+$ ]] || fail "A contratação pela interface não criou uma matrícula futura."
ENROLLMENT_SNAPSHOT="$(db_value "SELECT COUNT(*) FROM matriculas
    WHERE id = $NEW_ENROLLMENT_ID
      AND valor_contratado = 149.90
      AND duracao_contratada_valor = 1
      AND duracao_contratada_unidade = 'MES'")"
[[ "$ENROLLMENT_SNAPSHOT" == "1" ]] || fail "A nova matrícula não preservou o snapshot do plano."
ENROLLMENT_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria
    WHERE usuario_id = $USUARIO_ID
      AND acao = 'matricula_criada'
      AND entidade = 'matriculas'
      AND entidade_id = $NEW_ENROLLMENT_ID")"
[[ "$ENROLLMENT_AUDIT" == "1" ]] || fail "A criação da matrícula não foi auditada pelo funcionário autenticado."

# Limpa apenas as tabelas descartáveis criadas por este teste.
db_exec "DELETE FROM auditoria WHERE entidade = 'planos' AND entidade_id IN ($PLAN_ID, $UNUSED_PLAN_ID)"
db_exec "DELETE FROM auditoria
    WHERE entidade = 'matriculas'
      AND entidade_id IN (SELECT id FROM matriculas WHERE plano_id = $PLAN_ID)"
db_exec "DELETE FROM matriculas WHERE plano_id = $PLAN_ID"
db_exec "DELETE FROM planos WHERE id IN ($PLAN_ID, $UNUSED_PLAN_ID)"
db_exec "DELETE FROM auditoria
    WHERE entidade = 'revisoes_legado'
      AND entidade_id = $REVIEW_ID"
db_exec "DELETE FROM revisoes_legado_eventos WHERE revisao_legado_id = $REVIEW_ID"
db_exec "DROP TABLE revisoes_legado_eventos"
db_exec "DELETE FROM revisoes_legado WHERE aluno_id_origem = $ALUNO_ID"
db_exec "DROP TABLE revisoes_legado"
db_exec "DROP TABLE matriculas"
db_exec "DROP TABLE planos"

echo "10/10 - Verificando que o endpoint de gravação rejeita GET..."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/method.html" -w '%{http_code}' "$BASE_URL/salvar.php")"
[[ "$HTTP_CODE" == "405" ]] || fail "GET em salvar.php deveria retornar HTTP 405; retornou $HTTP_CODE."

echo "10/10 - Verificações de integração concluídas."
echo "CRUD real via HTTP, validação de e-mail e telefone, CSRF, pesquisa, revisão legada e persistência: OK"
