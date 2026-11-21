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
assert_contains "$TMP_DIR/update.html" 'name="busca" value="Aluno CI CRUD"'
assert_contains "$TMP_DIR/update.html" 'value="ativos"'

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
assert_contains "$TMP_DIR/student-inactivated.html" 'name="busca" value="Aluno CI Atualizado"'
assert_contains "$TMP_DIR/student-inactivated.html" 'value="ativos"'

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
assert_contains "$TMP_DIR/student-reactivated.html" 'name="busca" value="Aluno CI Atualizado"'
assert_contains "$TMP_DIR/student-reactivated.html" 'value="inativos"'
assert_contains "$TMP_DIR/student-reactivated.html" "Aluno reativado com sucesso."
ATIVO="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID AND ativo = 1")"
[[ "$ATIVO" == "1" ]] || fail "A reativação não foi persistida."
AUDIT_REATIVACAO="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND entidade = 'alunos' AND entidade_id = $ALUNO_ID AND acao = 'aluno_reativado'")"
[[ "$AUDIT_REATIVACAO" == "1" ]] || fail "A reativação não gerou exatamente um evento de auditoria."

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
[[ "$EVENTOS" == "4" ]] || fail "Esperava 4 eventos de criação/edição/status associados ao usuário; encontrei $EVENTOS."
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


echo "10/10 - Verificando que o endpoint de gravação rejeita GET..."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/method.html" -w '%{http_code}' "$BASE_URL/salvar.php")"
[[ "$HTTP_CODE" == "405" ]] || fail "GET em salvar.php deveria retornar HTTP 405; retornou $HTTP_CODE."

echo "10/10 - Verificações de integração concluídas."
echo "CRUD real via HTTP, validação de e-mail e telefone, CSRF, pesquisa e persistência: OK"
