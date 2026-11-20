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

echo "1/8 - Abrindo formulário e validando proteção CSRF..."
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

echo "2/8 - Conferindo validação de e-mail e telefone no servidor..."
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

echo "3/8 - Cadastrando aluno pelo endpoint real com telefone formatado..."
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

echo "4/8 - Pesquisando e editando o aluno..."
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

echo "5/8 - Confirmando que abrir a tela de exclusão não apaga dados..."
curl -fsS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE_URL/excluir.php?id=$ALUNO_ID" -o "$TMP_DIR/delete-confirmation.html"
assert_contains "$TMP_DIR/delete-confirmation.html" "Deseja excluir"
AINDA_EXISTE="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID")"
[[ "$AINDA_EXISTE" == "1" ]] || fail "Uma requisição GET alterou ou removeu o registro."

echo "6/8 - Excluindo pelo endpoint real..."
curl -fsS -L -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/excluir.php" \
    --data-urlencode "token=$EDIT_TOKEN" \
    --data-urlencode "id=$ALUNO_ID" \
    -o "$TMP_DIR/delete.html"
assert_contains "$TMP_DIR/delete.html" "Aluno excluído com sucesso!"

RESTANTE="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $ALUNO_ID")"
[[ "$RESTANTE" == "0" ]] || fail "O registro continuou no banco após a exclusão."

echo "7/8 - Verificando que o endpoint de gravação rejeita GET..."
HTTP_CODE="$(curl -sS -o "$TMP_DIR/method.html" -w '%{http_code}' "$BASE_URL/salvar.php")"
[[ "$HTTP_CODE" == "405" ]] || fail "GET em salvar.php deveria retornar HTTP 405; retornou $HTTP_CODE."

echo "8/8 - Verificações de integração concluídas."
echo "CRUD real via HTTP, validação de e-mail e telefone, CSRF, pesquisa e persistência: OK"
