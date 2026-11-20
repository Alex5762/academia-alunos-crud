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

curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/login.html"
LOGIN_TOKEN="$(get_token "$TMP_DIR/login.html")"
HTTP_CODE="$(curl -sS -o "$TMP_DIR/bad-login.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/login.php" \
    --data-urlencode "token=$LOGIN_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=senha-incorreta")"
[[ "$HTTP_CODE" == "200" ]] || fail "Login inválido deveria retornar a tela com HTTP 200."
assert_contains "$TMP_DIR/bad-login.html" "E-mail ou senha inválidos."

curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/login-again.html"
LOGIN_TOKEN="$(get_token "$TMP_DIR/login-again.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$LOGIN_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=$FITCONTROL_TEST_PASSWORD" \
    -o "$TMP_DIR/login-success.html"
assert_contains "$TMP_DIR/login-success.html" "Crie sua senha pessoal"

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
[[ \${#TEMP_PASSWORD} -ge 30 ]] || fail "A senha temporária não foi exibida corretamente."

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
assert_contains "$TMP_DIR/search.html" "Aluno CI CRUD"

curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/cadastro.php?id=$ALUNO_ID" -o "$TMP_DIR/edit-form.html"
EDIT_TOKEN="$(get_token "$TMP_DIR/edit-form.html")"
assert_contains "$TMP_DIR/edit-form.html" 'value="Aluno CI CRUD"'

curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/salvar.php" \
    --data-urlencode "token=$EDIT_TOKEN" \
    --data-urlencode "id=$ALUNO_ID" \
    --data-urlencode "nome=Aluno CI Atualizado" \
    --data-urlencode "telefone=21999990001" \
    --data-urlencode "email=crud-ci-update@example.test" \
    --data-urlencode "plano=Anual" \
    -o "$TMP_DIR/update.html"
assert_contains "$TMP_DIR/update.html" "Aluno atualizado com sucesso!"

ATUALIZADO="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID AND nome = 'Aluno CI Atualizado' AND email = 'crud-ci-update@example.test' AND plano = 'Anual'")"
[[ "$ATUALIZADO" == "1" ]] || fail "A edição não foi persistida corretamente."

echo "5/10 - Confirmando que abrir a tela de exclusão não apaga dados..."
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/excluir.php?id=$ALUNO_ID" -o "$TMP_DIR/delete-confirmation.html"
assert_contains "$TMP_DIR/delete-confirmation.html" "Deseja excluir"
AINDA_EXISTE="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID")"
[[ "$AINDA_EXISTE" == "1" ]] || fail "Uma requisição GET alterou ou removeu o registro."

echo "6/10 - Excluindo pelo endpoint real..."
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/excluir.php" \
    --data-urlencode "token=$EDIT_TOKEN" \
    --data-urlencode "id=$ALUNO_ID" \
    -o "$TMP_DIR/delete.html"
assert_contains "$TMP_DIR/delete.html" "Aluno excluído com sucesso!"

RESTANTE="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID")"
[[ "$RESTANTE" == "0" ]] || fail "O registro continuou no banco após a exclusão."

echo "7/10 - Conferindo autoria dos eventos de auditoria..."
USUARIO_ID="$(db_value "SELECT id FROM usuarios WHERE email = 'provision-ci@example.test' LIMIT 1")"
EVENTOS="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND entidade = 'alunos' AND entidade_id = $ALUNO_ID AND acao IN ('aluno_criado', 'aluno_editado', 'aluno_excluido')")"
[[ "$EVENTOS" == "3" ]] || fail "Esperava 3 eventos do CRUD associados ao usuário; encontrei $EVENTOS."
LOGIN_EVENTOS="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND acao = 'login'")"
[[ "$LOGIN_EVENTOS" == "1" ]] || fail "O login não foi registrado exatamente uma vez."

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
[[ "$LOGIN_EVENTOS" == "2" ]] || fail "O segundo login após reativação não foi registrado."

echo "9/10 - Encerrando sessão por POST com CSRF..."
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/index.php" -o "$TMP_DIR/dashboard-before-logout.html"
LOGOUT_TOKEN="$(get_token "$TMP_DIR/dashboard-before-logout.html")"
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/logout.php" --data-urlencode "token=$LOGOUT_TOKEN" -o "$TMP_DIR/logout.html"
HTTP_CODE="$(curl -sS -o "$TMP_DIR/after-logout.html" -w '%{http_code}' -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/index.php")"
[[ "$HTTP_CODE" == "303" ]] || fail "A sessão continuou acessando o painel após logout."
LOGOUT_EVENTOS="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $USUARIO_ID AND acao = 'logout'")"
[[ "$LOGOUT_EVENTOS" == "1" ]] || fail "O logout não foi registrado."


echo "10/10 - Verificando que o endpoint de gravação rejeita GET..."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/method.html" -w '%{http_code}' "$BASE_URL/salvar.php")"
[[ "$HTTP_CODE" == "405" ]] || fail "GET em salvar.php deveria retornar HTTP 405; retornou $HTTP_CODE."

echo "10/10 - Verificações de integração concluídas."
echo "CRUD real via HTTP, validação de e-mail e telefone, CSRF, pesquisa e persistência: OK"
