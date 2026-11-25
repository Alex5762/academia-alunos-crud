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
db_exec() {
    SQL_QUERY="$1" php -r '
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_NAME"));
        $pdo = new PDO($dsn, getenv("DB_USER"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(getenv("SQL_QUERY"));
    '
}
login_and_change_password() {
    local jar="$1" email="$2" password="$3" new_password="$4" prefix="$5"
    local token stale_jar stale_token stale_status
    stale_jar="$TMP_DIR/$prefix-stale-first-access.cookies"

    curl -fsS -c "$jar" -b "$jar" "$BASE_URL/login.php" -o "$TMP_DIR/$prefix-login.html"
    token="$(get_token "$TMP_DIR/$prefix-login.html")"
    curl -fsS -L -c "$jar" -b "$jar" "$BASE_URL/login.php" \
        --data-urlencode "token=$token" \
        --data-urlencode "email=$email" \
        --data-urlencode "senha=$password" \
        -o "$TMP_DIR/$prefix-first-access.html"
    grep -Fq 'Crie sua senha pessoal' "$TMP_DIR/$prefix-first-access.html" || fail "$prefix não foi direcionado à troca obrigatória de senha."

    # Abre uma segunda sessão independente ainda com a senha temporária.
    curl -fsS -c "$stale_jar" -b "$stale_jar" "$BASE_URL/login.php" -o "$TMP_DIR/$prefix-stale-login.html"
    stale_token="$(get_token "$TMP_DIR/$prefix-stale-login.html")"
    curl -fsS -L -c "$stale_jar" -b "$stale_jar" "$BASE_URL/login.php" \
        --data-urlencode "token=$stale_token" \
        --data-urlencode "email=$email" \
        --data-urlencode "senha=$password" \
        -o "$TMP_DIR/$prefix-stale-first-access.html"
    grep -Fq 'Crie sua senha pessoal' "$TMP_DIR/$prefix-stale-first-access.html" || fail "$prefix segunda sessão não chegou à troca obrigatória."

    token="$(get_token "$TMP_DIR/$prefix-first-access.html")"
    curl -fsS -L -c "$jar" -b "$jar" "$BASE_URL/trocar-senha.php" \
        --data-urlencode "token=$token" \
        --data-urlencode "senha_atual=$password" \
        --data-urlencode "nova_senha=$new_password" \
        --data-urlencode "confirmacao=$new_password" \
        -o "$TMP_DIR/$prefix-dashboard.html"
    grep -Fq 'Cadastro de alunos' "$TMP_DIR/$prefix-dashboard.html" || fail "$prefix não chegou ao CRUD após trocar a senha."

    grep -Fq 'href="alterar-senha.php"' "$TMP_DIR/$prefix-dashboard.html" \
        || fail "$prefix painel não oferece acesso à alteração voluntária de senha."
    curl -fsS -b "$jar" -c "$jar" "$BASE_URL/alterar-senha.php" \
        -o "$TMP_DIR/$prefix-voluntary-change.html"
    grep -Fq '<h1 id="password-title">Alterar senha</h1>' "$TMP_DIR/$prefix-voluntary-change.html" \
        || fail "$prefix não conseguiu abrir a tela de alteração voluntária de senha."
    grep -Fq 'href="index.php"' "$TMP_DIR/$prefix-voluntary-change.html" \
        || fail "$prefix tela de alteração de senha não oferece retorno ao painel."

    # O endpoint de renovação distingue revogação real do redirecionamento de troca obrigatória.
    stale_token="$(get_token "$TMP_DIR/$prefix-stale-first-access.html")"
    stale_status="$(curl -sS -o "$TMP_DIR/$prefix-stale-renewal.json" -w '%{http_code}' \
        -b "$stale_jar" -c "$stale_jar" "$BASE_URL/renovar-sessao.php" \
        --data-urlencode "token=$stale_token")"
    [[ "$stale_status" == "401" ]] || fail "$prefix segunda sessão deveria ser revogada após troca obrigatória; renovar-sessao.php retornou $stale_status."
}

PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8080 -t . >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for attempt in $(seq 1 30); do
    if curl -sS "$BASE_URL/login.php" -o /dev/null; then break; fi
    sleep 1
done

ANON_CODE="$(curl -sS -o "$TMP_DIR/anonymous.html" -w '%{http_code}' "$BASE_URL/index.php")"
[[ "$ANON_CODE" == "303" ]] || fail "Acesso anônimo ao CRUD deveria redirecionar para login; recebeu $ANON_CODE."
CADASTRO_ANON_CODE="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/cadastro.php")"
[[ "$CADASTRO_ANON_CODE" == "303" ]] || fail "Cadastro anônimo deveria redirecionar para login; recebeu $CADASTRO_ANON_CODE."

# Uma falha de credenciais é auditada sem registrar e-mail/IP; login válido limpa a combinação.
curl -fsS -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/admin-failure-login.html"
ADMIN_FAILURE_TOKEN="$(get_token "$TMP_DIR/admin-failure-login.html")"
ADMIN_FAILURE_CODE="$(curl -sS -o "$TMP_DIR/admin-failure.html" -w '%{http_code}' -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$ADMIN_FAILURE_TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=SenhaIncorreta-2026!")"
[[ "$ADMIN_FAILURE_CODE" == "200" ]] || fail "Falha de credenciais deveria manter resposta genérica; recebeu $ADMIN_FAILURE_CODE."
grep -Fq 'E-mail ou senha inválidos.' "$TMP_DIR/admin-failure.html" || fail "A falha de login não exibiu mensagem genérica."
ADMIN_FAILURE_COUNTER="$(db_value "SELECT tentativas FROM login_tentativas WHERE email_hash = SHA2('provision-ci@example.test', 256) AND origem_hash = SHA2('127.0.0.1', 256)")"
[[ "$ADMIN_FAILURE_COUNTER" == "1" ]] || fail "A falha inicial não incrementou o rate limit."
ADMIN_FAILURE_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao='login_falhou' AND usuario_id IS NULL AND entidade='autenticacao' AND entidade_id IS NULL")"
[[ "$ADMIN_FAILURE_AUDIT" == "1" ]] || fail "A falha de login não foi auditada sem associação de conta."

login_and_change_password "$ADMIN_JAR" "provision-ci@example.test" "$ADMIN_PASSWORD" "SenhaAdminNova-2026!" "admin"
ADMIN_COUNTER_AFTER_SUCCESS="$(db_value "SELECT COUNT(*) FROM login_tentativas WHERE email_hash = SHA2('provision-ci@example.test', 256) AND origem_hash = SHA2('127.0.0.1', 256)")"
[[ "$ADMIN_COUNTER_AFTER_SUCCESS" == "0" ]] || fail "Login válido não limpou o contador."

# Rajada HTTP concorrente: sessões distintas disputam a mesma combinação e-mail/IP.
# O servidor embutido usa workers para que as requisições realmente se sobreponham.
CONCURRENT_EMAIL="rate-limit-concurrent-http@example.test"
CONCURRENT_REQUESTS=12
CONCURRENT_AUDIT_BEFORE="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao='login_falhou' AND entidade='autenticacao' AND usuario_id IS NULL AND entidade_id IS NULL")"
CONCURRENT_PIDS=()
for attempt in $(seq 1 "$CONCURRENT_REQUESTS"); do
    concurrent_jar="$TMP_DIR/concurrent-$attempt.cookies"
    concurrent_page="$TMP_DIR/concurrent-$attempt-login.html"
    concurrent_response="$TMP_DIR/concurrent-$attempt-response.html"
    concurrent_status="$TMP_DIR/concurrent-$attempt-status.txt"

    curl -fsS -c "$concurrent_jar" -b "$concurrent_jar" "$BASE_URL/login.php" -o "$concurrent_page"
    concurrent_token="$(get_token "$concurrent_page")"

    (
        curl -sS -o "$concurrent_response" -w '%{http_code}' \
            -b "$concurrent_jar" -c "$concurrent_jar" "$BASE_URL/login.php" \
            --data-urlencode "token=$concurrent_token" \
            --data-urlencode "email=$CONCURRENT_EMAIL" \
            --data-urlencode "senha=SenhaIncorreta-2026!" > "$concurrent_status"
    ) &
    CONCURRENT_PIDS+=("$!")
done

for concurrent_pid in "${CONCURRENT_PIDS[@]}"; do
    wait "$concurrent_pid" || fail "Uma requisição HTTP concorrente de login falhou."
done

for attempt in $(seq 1 "$CONCURRENT_REQUESTS"); do
    concurrent_status="$(cat "$TMP_DIR/concurrent-$attempt-status.txt")"
    [[ "$concurrent_status" == "200" ]] || fail "Requisição concorrente $attempt retornou HTTP $concurrent_status."
    grep -Fq 'E-mail ou senha inválidos.' "$TMP_DIR/concurrent-$attempt-response.html" \
        || fail "Requisição concorrente $attempt não manteve resposta genérica."
done

CONCURRENT_COUNTER="$(db_value "SELECT tentativas FROM login_tentativas WHERE email_hash = SHA2('$CONCURRENT_EMAIL', 256) AND origem_hash = SHA2('127.0.0.1', 256)")"
CONCURRENT_BLOCKED="$(db_value "SELECT (bloqueado_ate > NOW()) FROM login_tentativas WHERE email_hash = SHA2('$CONCURRENT_EMAIL', 256) AND origem_hash = SHA2('127.0.0.1', 256)")"
CONCURRENT_AUDIT_AFTER="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao='login_falhou' AND entidade='autenticacao' AND usuario_id IS NULL AND entidade_id IS NULL")"
[[ "$CONCURRENT_COUNTER" == "5" && "$CONCURRENT_BLOCKED" == "1" ]] \
    || fail "Rajada concorrente deveria admitir cinco falhas e bloquear o restante; contador=$CONCURRENT_COUNTER bloqueado=$CONCURRENT_BLOCKED."
[[ "$((CONCURRENT_AUDIT_AFTER - CONCURRENT_AUDIT_BEFORE))" == "5" ]] \
    || fail "Rajada concorrente deveria gerar exatamente cinco eventos de falha auditados."

# Cinco falhas em 15 minutos ativam o bloqueio por 15 minutos.
RATE_LIMIT_JAR="$TMP_DIR/rate-limit.cookies"
RATE_LIMIT_EMAIL="rate-limit-http@example.test"
curl -fsS -c "$RATE_LIMIT_JAR" -b "$RATE_LIMIT_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/rate-limit-login.html"
RATE_LIMIT_TOKEN="$(get_token "$TMP_DIR/rate-limit-login.html")"
for attempt in 1 2 3 4 5; do
    rate_status="$(curl -sS -o "$TMP_DIR/rate-limit-failure-$attempt.html" -w '%{http_code}' -b "$RATE_LIMIT_JAR" -c "$RATE_LIMIT_JAR" "$BASE_URL/login.php" \
        --data-urlencode "token=$RATE_LIMIT_TOKEN" \
        --data-urlencode "email=$RATE_LIMIT_EMAIL" \
        --data-urlencode "senha=SenhaIncorreta-2026!")"
    [[ "$rate_status" == "200" ]] || fail "Falha $attempt do rate limit retornou HTTP $rate_status."
    rate_count="$(db_value "SELECT tentativas FROM login_tentativas WHERE email_hash = SHA2('$RATE_LIMIT_EMAIL', 256) AND origem_hash = SHA2('127.0.0.1', 256)")"
    [[ "$rate_count" == "$attempt" ]] || fail "Após $attempt falhas, contador esperado $attempt; recebeu $rate_count."
done
RATE_BLOCKED_UNTIL="$(db_value "SELECT (bloqueado_ate > NOW()) FROM login_tentativas WHERE email_hash = SHA2('$RATE_LIMIT_EMAIL', 256) AND origem_hash = SHA2('127.0.0.1', 256)")"
[[ "$RATE_BLOCKED_UNTIL" == "1" ]] || fail "A quinta falha não ativou o bloqueio."
RATE_AUDIT_BASE="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao='login_falhou'")"
RATE_BLOCKED_STATUS="$(curl -sS -o "$TMP_DIR/rate-limit-blocked.html" -w '%{http_code}' -b "$RATE_LIMIT_JAR" -c "$RATE_LIMIT_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$RATE_LIMIT_TOKEN" \
    --data-urlencode "email=$RATE_LIMIT_EMAIL" \
    --data-urlencode "senha=SenhaIncorreta-2026!")"
[[ "$RATE_BLOCKED_STATUS" == "200" ]] || fail "Tentativa bloqueada deveria manter resposta genérica."
RATE_COUNT_BLOCKED="$(db_value "SELECT tentativas FROM login_tentativas WHERE email_hash = SHA2('$RATE_LIMIT_EMAIL', 256) AND origem_hash = SHA2('127.0.0.1', 256)")"
RATE_AUDIT_BLOCKED="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao='login_falhou'")"
[[ "$RATE_COUNT_BLOCKED" == "5" && "$RATE_AUDIT_BLOCKED" == "$RATE_AUDIT_BASE" ]] || fail "Tentativa bloqueada alterou contador ou gerou evento indevido."

# Simula a passagem da janela e do bloqueio sem esperar 15 minutos.
db_exec "UPDATE login_tentativas SET janela_inicio = DATE_SUB(NOW(), INTERVAL 16 MINUTE), bloqueado_ate = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE email_hash = SHA2('$RATE_LIMIT_EMAIL', 256) AND origem_hash = SHA2('127.0.0.1', 256)"
RATE_AFTER_EXPIRY="$(curl -sS -o "$TMP_DIR/rate-limit-after-expiry.html" -w '%{http_code}' -b "$RATE_LIMIT_JAR" -c "$RATE_LIMIT_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$RATE_LIMIT_TOKEN" \
    --data-urlencode "email=$RATE_LIMIT_EMAIL" \
    --data-urlencode "senha=SenhaIncorreta-2026!")"
[[ "$RATE_AFTER_EXPIRY" == "200" ]] || fail "A tentativa após expiração deveria ser processada."
RATE_COUNT_RESET="$(db_value "SELECT tentativas FROM login_tentativas WHERE email_hash = SHA2('$RATE_LIMIT_EMAIL', 256) AND origem_hash = SHA2('127.0.0.1', 256)")"
[[ "$RATE_COUNT_RESET" == "1" ]] || fail "A expiração não reiniciou a janela em uma tentativa."


# Troca voluntária também revoga outra sessão e preserva a sessão que fez a alteração.
ADMIN_VOLUNTARY_STALE_JAR="$TMP_DIR/admin-voluntary-stale.cookies"
curl -fsS -c "$ADMIN_VOLUNTARY_STALE_JAR" -b "$ADMIN_VOLUNTARY_STALE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/admin-voluntary-stale-login.html"
TOKEN="$(get_token "$TMP_DIR/admin-voluntary-stale-login.html")"
curl -fsS -L -c "$ADMIN_VOLUNTARY_STALE_JAR" -b "$ADMIN_VOLUNTARY_STALE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$TOKEN" \
    --data-urlencode "email=provision-ci@example.test" \
    --data-urlencode "senha=SenhaAdminNova-2026!" \
    -o "$TMP_DIR/admin-voluntary-stale-home.html"
grep -Fq 'Cadastro de alunos' "$TMP_DIR/admin-voluntary-stale-home.html" || fail "Sessão paralela do Administrador não autenticou antes da troca voluntária."
curl -fsS -b "$ADMIN_VOLUNTARY_STALE_JAR" -c "$ADMIN_VOLUNTARY_STALE_JAR" "$BASE_URL/alterar-senha.php" -o "$TMP_DIR/admin-voluntary-stale-change.html"
ADMIN_VOLUNTARY_STALE_TOKEN="$(get_token "$TMP_DIR/admin-voluntary-stale-change.html")"
curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-senha.php" -o "$TMP_DIR/admin-change-password.html"
TOKEN="$(get_token "$TMP_DIR/admin-change-password.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-senha.php" \
    --data-urlencode "token=$TOKEN" \
    --data-urlencode "senha_atual=SenhaAdminNova-2026!" \
    --data-urlencode "nova_senha=SenhaAdminVoluntaria-2026!" \
    --data-urlencode "confirmacao=SenhaAdminVoluntaria-2026!" \
    -o "$TMP_DIR/admin-after-voluntary-change.html"
grep -Fq 'Senha alterada com sucesso.' "$TMP_DIR/admin-after-voluntary-change.html" || fail "Troca voluntária de senha não foi concluída."
ADMIN_VOLUNTARY_STALE_STATUS="$(curl -sS -o "$TMP_DIR/admin-voluntary-stale-renewal.json" -w '%{http_code}' \
    -b "$ADMIN_VOLUNTARY_STALE_JAR" -c "$ADMIN_VOLUNTARY_STALE_JAR" "$BASE_URL/renovar-sessao.php" \
    --data-urlencode "token=$ADMIN_VOLUNTARY_STALE_TOKEN")"
[[ "$ADMIN_VOLUNTARY_STALE_STATUS" == "401" ]] || fail "Sessão paralela do Administrador deveria ser revogada após troca voluntária; recebeu $ADMIN_VOLUNTARY_STALE_STATUS."
ADMIN_CURRENT_SESSION_STATUS="$(curl -sS -o "$TMP_DIR/admin-current-session.html" -w '%{http_code}' -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/index.php")"
[[ "$ADMIN_CURRENT_SESSION_STATUS" == "200" ]] || fail "A sessão que trocou a senha voluntariamente deveria continuar válida; recebeu $ADMIN_CURRENT_SESSION_STATUS."
TOKEN="$(get_token "$TMP_DIR/admin-current-session.html")"

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
OPERATOR_ID="$(db_value "SELECT id FROM usuarios WHERE email = 'operator-http@example.test' LIMIT 1")"
ADMIN_ID="$(db_value "SELECT id FROM usuarios WHERE email = 'provision-ci@example.test' LIMIT 1")"
[[ "$OPERATOR_ID" =~ ^[0-9]+$ && "$ADMIN_ID" =~ ^[0-9]+$ ]] || fail "Não foi possível identificar as contas de teste."

# Perfis sem users.manage não acessam a gestão de contas.
OPERATOR_USERS_CODE="$(curl -sS -o "$TMP_DIR/operator-users.html" -w '%{http_code}' -b "$OPERATOR_JAR" -c "$OPERATOR_JAR" "$BASE_URL/usuarios.php")"
[[ "$OPERATOR_USERS_CODE" == "403" ]] || fail "Operador deveria receber 403 na gestão de funcionários; recebeu $OPERATOR_USERS_CODE."
OPERATOR_DELETE_CODE="$(curl -sS -o "$TMP_DIR/operator-delete.html" -w '%{http_code}' -b "$OPERATOR_JAR" -c "$OPERATOR_JAR" "$BASE_URL/excluir.php?id=$STUDENT_ID")"
[[ "$OPERATOR_DELETE_CODE" == "410" ]] || fail "O endpoint legado de exclusão deveria estar aposentado com 410; recebeu $OPERATOR_DELETE_CODE."

# Operador pode consultar/criar/editar, mas não pode inativar ou reativar alunos.
OPERATOR_STUDENT_STATUS_TOKEN="$(get_token "$TMP_DIR/operator-dashboard.html")"
OPERATOR_STUDENT_STATUS_CODE="$(curl -sS -o "$TMP_DIR/operator-student-status.html" -w '%{http_code}' -b "$OPERATOR_JAR" -c "$OPERATOR_JAR" -X POST "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$OPERATOR_STUDENT_STATUS_TOKEN" --data-urlencode "id=$STUDENT_ID" --data-urlencode "acao=inativar")"
[[ "$OPERATOR_STUDENT_STATUS_CODE" == "403" ]] || fail "Operador deveria receber 403 ao alterar status do aluno; recebeu $OPERATOR_STUDENT_STATUS_CODE."
STUDENT_STILL_ACTIVE="$(db_value "SELECT ativo FROM alunos WHERE id = $STUDENT_ID")"
[[ "$STUDENT_STILL_ACTIVE" == "1" ]] || fail "Operador sem students.status alterou o aluno."

# Alteração de status sem CSRF não pode produzir efeito.
STATUS_CSRF_CODE="$(curl -sS -o "$TMP_DIR/status-invalid-csrf.html" -w '%{http_code}' -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "id=$OPERATOR_ID" --data-urlencode "acao=desativar")"
[[ "$STATUS_CSRF_CODE" == "403" ]] || fail "Alteração de status sem CSRF deveria retornar 403; recebeu $STATUS_CSRF_CODE."
OPERATOR_STILL_ACTIVE="$(db_value "SELECT ativo FROM usuarios WHERE id = $OPERATOR_ID")"
[[ "$OPERATOR_STILL_ACTIVE" == "1" ]] || fail "Requisição sem CSRF alterou o status da conta."

# O administrador promove e depois rebaixa o operador; cada mudança é persistida.
TOKEN="$(get_token "$TMP_DIR/users.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-perfil-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$OPERATOR_ID" \
    --data-urlencode "perfil=GESTOR" -o "$TMP_DIR/operator-promoted.html"
grep -Fq 'Perfil do funcionário atualizado com sucesso.' "$TMP_DIR/operator-promoted.html" || fail "Alteração de perfil para Gestor não foi concluída."
OPERATOR_ROLE="$(db_value "SELECT perfil FROM usuarios WHERE id = $OPERATOR_ID")"
[[ "$OPERATOR_ROLE" == "GESTOR" ]] || fail "O endpoint não persistiu o perfil Gestor."
OPERATOR_USERS_CODE="$(curl -sS -o "$TMP_DIR/manager-users.html" -w '%{http_code}' -b "$OPERATOR_JAR" -c "$OPERATOR_JAR" "$BASE_URL/usuarios.php")"
[[ "$OPERATOR_USERS_CODE" == "403" ]] || fail "Gestor não deveria acessar a gestão de funcionários; recebeu $OPERATOR_USERS_CODE."

TOKEN="$(get_token "$TMP_DIR/operator-promoted.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-perfil-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$OPERATOR_ID" \
    --data-urlencode "perfil=OPERADOR" -o "$TMP_DIR/operator-demoted.html"
grep -Fq 'Perfil do funcionário atualizado com sucesso.' "$TMP_DIR/operator-demoted.html" || fail "Retorno ao perfil Operador não foi concluído."
OPERATOR_ROLE="$(db_value "SELECT perfil FROM usuarios WHERE id = $OPERATOR_ID")"
[[ "$OPERATOR_ROLE" == "OPERADOR" ]] || fail "O endpoint não restaurou o perfil Operador."

# O último administrador não pode desativar a própria conta.
TOKEN="$(get_token "$TMP_DIR/operator-demoted.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$ADMIN_ID" \
    --data-urlencode "acao=desativar" -o "$TMP_DIR/admin-self-deactivation.html"
grep -Fq 'Você não pode desativar sua própria conta.' "$TMP_DIR/admin-self-deactivation.html" || fail "O endpoint não bloqueou autodesativação do Administrador."
ADMIN_STILL_ACTIVE="$(db_value "SELECT ativo FROM usuarios WHERE id = $ADMIN_ID")"
[[ "$ADMIN_STILL_ACTIVE" == "1" ]] || fail "O Administrador conseguiu desativar a própria conta."

# Cria uma segunda sessão independente e preserva seu cookie até depois da reativação.
# A primeira sessão será consultada enquanto a conta estiver inativa; esta não.
OPERATOR_STALE_JAR="$TMP_DIR/operator-stale.cookies"
curl -fsS -c "$OPERATOR_STALE_JAR" -b "$OPERATOR_STALE_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/operator-stale-login.html"
OPERATOR_STALE_TOKEN="$(get_token "$TMP_DIR/operator-stale-login.html")"
curl -fsS -L -c "$OPERATOR_STALE_JAR" -b "$OPERATOR_STALE_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$OPERATOR_STALE_TOKEN" \
    --data-urlencode "email=operator-http@example.test" \
    --data-urlencode "senha=SenhaOperadorNova-2026!" \
    -o "$TMP_DIR/operator-stale-session.html"
grep -Fq 'Cadastro de alunos' "$TMP_DIR/operator-stale-session.html" || fail "A segunda sessão independente não foi autenticada."

# Desativação revoga a sessão existente.
TOKEN="$(get_token "$TMP_DIR/operator-demoted.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$OPERATOR_ID" \
    --data-urlencode "acao=desativar" -o "$TMP_DIR/operator-deactivated.html"
grep -Fq 'Conta desativada.' "$TMP_DIR/operator-deactivated.html" || fail "O endpoint não desativou a conta do funcionário."
OPERATOR_DISABLED="$(db_value "SELECT ativo FROM usuarios WHERE id = $OPERATOR_ID")"
[[ "$OPERATOR_DISABLED" == "0" ]] || fail "A conta do funcionário continua ativa após desativação."
OPERATOR_REVOKED_CODE="$(curl -sS -o "$TMP_DIR/operator-session-revoked.html" -w '%{http_code}' -b "$OPERATOR_JAR" -c "$OPERATOR_JAR" "$BASE_URL/index.php")"
[[ "$OPERATOR_REVOKED_CODE" == "303" ]] || fail "A sessão do funcionário desativado deveria ser revogada; recebeu $OPERATOR_REVOKED_CODE."

# Reativar não restaura os cookies antigos: a versão persistente já foi incrementada.
TOKEN="$(get_token "$TMP_DIR/operator-deactivated.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-usuario.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$OPERATOR_ID" \
    --data-urlencode "acao=reativar" -o "$TMP_DIR/operator-reactivated.html"
grep -Fq 'Conta reativada com sucesso.' "$TMP_DIR/operator-reactivated.html" || fail "O endpoint não reativou a conta."
OPERATOR_ACTIVE="$(db_value "SELECT ativo FROM usuarios WHERE id = $OPERATOR_ID")"
[[ "$OPERATOR_ACTIVE" == "1" ]] || fail "A conta não foi reativada."
STALE_AFTER_REACTIVATION_CODE="$(curl -sS -o "$TMP_DIR/operator-stale-after-reactivation.html" -w '%{http_code}' -b "$OPERATOR_STALE_JAR" -c "$OPERATOR_STALE_JAR" "$BASE_URL/index.php")"
[[ "$STALE_AFTER_REACTIVATION_CODE" == "303" ]] || fail "A segunda sessão, preservada sem uso durante a desativação, voltou a funcionar após reativação; esperava 303 e recebeu $STALE_AFTER_REACTIVATION_CODE."

# Uma sessão nova, autenticada depois da reativação, deve funcionar.
rm -f "$OPERATOR_JAR"
curl -fsS -c "$OPERATOR_JAR" -b "$OPERATOR_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/operator-relogin.html"
OPERATOR_LOGIN_TOKEN="$(get_token "$TMP_DIR/operator-relogin.html")"
curl -fsS -L -c "$OPERATOR_JAR" -b "$OPERATOR_JAR" "$BASE_URL/login.php" \
    --data-urlencode "token=$OPERATOR_LOGIN_TOKEN" --data-urlencode "email=operator-http@example.test" \
    --data-urlencode "senha=SenhaOperadorNova-2026!" -o "$TMP_DIR/operator-relogin-success.html"
grep -Fq 'Cadastro de alunos' "$TMP_DIR/operator-relogin-success.html" || fail "O funcionário não conseguiu iniciar uma sessão nova após reativação."

# Logout encerra a sessão e registra o evento.
OPERATOR_LOGOUT_TOKEN="$(get_token "$TMP_DIR/operator-relogin-success.html")"
curl -fsS -L -c "$OPERATOR_JAR" -b "$OPERATOR_JAR" "$BASE_URL/logout.php" \
    --data-urlencode "token=$OPERATOR_LOGOUT_TOKEN" -o "$TMP_DIR/operator-logout.html"
OPERATOR_AFTER_LOGOUT="$(curl -sS -o /dev/null -w '%{http_code}' -b "$OPERATOR_JAR" -c "$OPERATOR_JAR" "$BASE_URL/index.php")"
[[ "$OPERATOR_AFTER_LOGOUT" == "303" ]] || fail "Logout não encerrou a sessão; /index.php retornou $OPERATOR_AFTER_LOGOUT."

PROFILE_AUDIT_COUNT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $ADMIN_ID AND entidade = 'usuarios' AND entidade_id = $OPERATOR_ID AND acao = 'usuario_perfil_alterado'")"
[[ "$PROFILE_AUDIT_COUNT" == "2" ]] || fail "Promoção e rebaixamento não produziram dois eventos de auditoria."
STATUS_AUDIT_COUNT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $ADMIN_ID AND entidade = 'usuarios' AND entidade_id = $OPERATOR_ID AND acao IN ('usuario_desativado','usuario_reativado')")"
[[ "$STATUS_AUDIT_COUNT" == "2" ]] || fail "Desativação e reativação não produziram dois eventos de auditoria."
OPERATOR_LOGOUT_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $OPERATOR_ID AND acao = 'logout'")"
[[ "$OPERATOR_LOGOUT_AUDIT" == "1" ]] || fail "Logout do funcionário não foi auditado."

TOKEN="$(get_token "$TMP_DIR/admin-current-session.html")"
STATUS_GET_CODE="$(curl -sS -o "$TMP_DIR/student-status-get.html" -w '%{http_code}' -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-aluno.php?id=$STUDENT_ID")"
[[ "$STATUS_GET_CODE" == "405" ]] || fail "GET no endpoint de status do aluno deveria retornar 405; recebeu $STATUS_GET_CODE."
STATUS_CSRF_CODE="$(curl -sS -o "$TMP_DIR/student-status-csrf.html" -w '%{http_code}' -b "$ADMIN_JAR" -c "$ADMIN_JAR" -X POST "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=token-invalido" --data-urlencode "id=$STUDENT_ID" --data-urlencode "acao=inativar")"
[[ "$STATUS_CSRF_CODE" == "403" ]] || fail "Status do aluno sem CSRF deveria retornar 403; recebeu $STATUS_CSRF_CODE."

curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$STUDENT_ID" --data-urlencode "acao=inativar" \
    --data-urlencode "busca=Aluno Foundation HTTP" --data-urlencode "status=ativos" -o "$TMP_DIR/student-inactivated.html"
grep -Fq 'Aluno inativado com sucesso.' "$TMP_DIR/student-inactivated.html" || fail "Inativação do aluno não foi concluída."
REMAINING="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $STUDENT_ID AND ativo = 0")"
[[ "$REMAINING" == "1" ]] || fail "O aluno foi removido ou não ficou inativo."
curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/index.php?status=inativos&busca=foundation-http" -o "$TMP_DIR/student-filter-inactive.html"
grep -Fq 'Aluno Foundation HTTP' "$TMP_DIR/student-filter-inactive.html" || fail "Filtro de inativos e pesquisa não localizaram o aluno."
curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/index.php?status=ativos&busca=foundation-http" -o "$TMP_DIR/student-filter-active.html"
if grep -Fq 'Aluno Foundation HTTP' "$TMP_DIR/student-filter-active.html"; then fail "Aluno inativo apareceu no filtro de ativos."; fi
AUDIT_INACTIVE="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $ADMIN_ID AND acao = 'aluno_inativado' AND entidade='alunos' AND entidade_id=$STUDENT_ID")"
[[ "$AUDIT_INACTIVE" == "1" ]] || fail "Inativação não produziu exatamente um evento de auditoria."

# Repetir a mesma transição não deve gerar um segundo evento.
TOKEN="$(get_token "$TMP_DIR/student-inactivated.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$STUDENT_ID" --data-urlencode "acao=inativar" \
    --data-urlencode "busca=Aluno Foundation HTTP" --data-urlencode "status=ativos" -o "$TMP_DIR/student-inactivated-again.html"
grep -Fq 'O aluno já estava inativo.' "$TMP_DIR/student-inactivated-again.html" || fail "A transição repetida não informou que o aluno já estava inativo."
AUDIT_INACTIVE_REPEAT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $ADMIN_ID AND acao = 'aluno_inativado' AND entidade='alunos' AND entidade_id=$STUDENT_ID")"
[[ "$AUDIT_INACTIVE_REPEAT" == "1" ]] || fail "Repetir a inativação duplicou o evento de auditoria."

curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/detalhes-aluno.php?id=$STUDENT_ID&busca=Aluno+Foundation+HTTP&status=inativos" -o "$TMP_DIR/student-details.html"
grep -Fq 'PERFIL DO ALUNO' "$TMP_DIR/student-details.html" || fail "Página de detalhes não abriu."
grep -Fq 'Aluno inativado' "$TMP_DIR/student-details.html" || fail "Histórico não mostrou a inativação."

TOKEN="$(get_token "$TMP_DIR/student-details.html")"
curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/alterar-status-aluno.php" \
    --data-urlencode "token=$TOKEN" --data-urlencode "id=$STUDENT_ID" --data-urlencode "acao=reativar" \
    --data-urlencode "retorno=detalhes" --data-urlencode "busca=Aluno Foundation HTTP" --data-urlencode "status=inativos" \
    -o "$TMP_DIR/student-reactivated.html"
grep -Fq 'Aluno reativado com sucesso.' "$TMP_DIR/student-reactivated.html" || fail "Reativação do aluno não foi concluída."
REMAINING="$(db_value "SELECT COUNT(*) FROM alunos WHERE id = $STUDENT_ID AND ativo = 1")"
[[ "$REMAINING" == "1" ]] || fail "O aluno não foi reativado."
AUDIT_REACTIVATED="$(db_value "SELECT COUNT(*) FROM auditoria WHERE usuario_id = $ADMIN_ID AND acao = 'aluno_reativado' AND entidade='alunos' AND entidade_id=$STUDENT_ID")"
[[ "$AUDIT_REACTIVATED" == "1" ]] || fail "Reativação não produziu exatamente um evento de auditoria."

ANON_DETAILS_CODE="$(curl -sS -o "$TMP_DIR/anon-student-details.html" -w '%{http_code}' "$BASE_URL/detalhes-aluno.php?id=$STUDENT_ID")"
[[ "$ANON_DETAILS_CODE" == "303" ]] || fail "Detalhes do aluno deveriam exigir autenticação; recebeu $ANON_DETAILS_CODE."

LEGACY_DELETE_CODE="$(curl -sS -o "$TMP_DIR/legacy-delete.html" -w '%{http_code}' -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/excluir.php?id=$STUDENT_ID")"
[[ "$LEGACY_DELETE_CODE" == "410" ]] || fail "Exclusão física deveria permanecer aposentada; recebeu $LEGACY_DELETE_CODE."

if [[ "\${FITCONTROL_BLOCK_03:-0}" == "1" ]]; then
    echo "Bloco 03 HTTP - validando catálogo, contratação, cancelamento e revisão..."

    ANON_PLANS_CODE="$(curl -sS -o "$TMP_DIR/anon-plans.html" -w '%{http_code}' "$BASE_URL/planos.php")"
    [[ "$ANON_PLANS_CODE" == "303" ]] || fail "Catálogo de planos deveria exigir autenticação; recebeu $ANON_PLANS_CODE."

    curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/planos.php" -o "$TMP_DIR/block03-plans.html"
    grep -Fq 'Catálogo cadastrado' "$TMP_DIR/block03-plans.html" || fail "Catálogo de planos não abriu para Administrador."
    TOKEN="$(get_token "$TMP_DIR/block03-plans.html")"

    curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/salvar-plano.php" \
        --data-urlencode "token=$TOKEN" \
        --data-urlencode "nome=Plano HTTP Bloco 03" \
        --data-urlencode "descricao=Fixture automatizada de catálogo" \
        --data-urlencode "duracao_valor=1" \
        --data-urlencode "duracao_unidade=MES" \
        --data-urlencode "valor_atual=149,90" \
        -o "$TMP_DIR/block03-plan-created.html"
    grep -Fq 'Rascunho de plano criado com sucesso.' "$TMP_DIR/block03-plan-created.html" || fail "Criação de rascunho de plano não foi concluída."
    PLAN_ID="$(db_value "SELECT id FROM planos WHERE nome = 'Plano HTTP Bloco 03' ORDER BY id DESC LIMIT 1")"
    [[ "$PLAN_ID" =~ ^[1-9][0-9]*$ ]] || fail "ID do plano criado não foi encontrado."

    TOKEN="$(get_token "$TMP_DIR/block03-plan-created.html")"
    curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/gerenciar-plano.php" \
        --data-urlencode "token=$TOKEN" \
        --data-urlencode "id=$PLAN_ID" \
        --data-urlencode "acao=ativar" \
        -o "$TMP_DIR/block03-plan-activated.html"
    grep -Fq 'Plano ativado e disponível para novas matrículas.' "$TMP_DIR/block03-plan-activated.html" || fail "Ativação do plano não foi concluída."
    PLAN_STATE="$(db_value "SELECT situacao FROM planos WHERE id = $PLAN_ID")"
    [[ "$PLAN_STATE" == "ATIVO" ]] || fail "O plano não ficou ativo."

    BLOCK03_EMAIL="block03-http-$(date +%s)-$@example.test"
    db_exec "INSERT INTO alunos (nome, telefone, email, plano, ativo) VALUES ('Aluno HTTP Bloco 03', '21990003003', '$BLOCK03_EMAIL', 'Legado sem vínculo', 1)"
    BLOCK03_STUDENT_ID="$(db_value "SELECT id FROM alunos WHERE email = '$BLOCK03_EMAIL'")"
    [[ "$BLOCK03_STUDENT_ID" =~ ^[1-9][0-9]*$ ]] || fail "Aluno de teste do Bloco 03 não foi criado."

    curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/planos.php?nova_matricula=1&aluno_id=$BLOCK03_STUDENT_ID" -o "$TMP_DIR/block03-enrollment-form.html"
    grep -Fq 'Confirmar matrícula' "$TMP_DIR/block03-enrollment-form.html" || fail "Formulário de contratação não abriu."
    TOKEN="$(get_token "$TMP_DIR/block03-enrollment-form.html")"
    curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/planos.php?nova_matricula=1" \
        --data-urlencode "token=$TOKEN" \
        --data-urlencode "acao=criar_matricula" \
        --data-urlencode "aluno_id=$BLOCK03_STUDENT_ID" \
        --data-urlencode "plano_id=$PLAN_ID" \
        --data-urlencode "data_inicio=2027-01-10" \
        --data-urlencode "hora_inicio=09:00" \
        -o "$TMP_DIR/block03-enrollment-created.html"
    grep -Fq 'Matrícula #' "$TMP_DIR/block03-enrollment-created.html" || fail "Contratação não retornou a confirmação da matrícula."

    ENROLLMENT_ID="$(db_value "SELECT id FROM matriculas WHERE aluno_id = $BLOCK03_STUDENT_ID ORDER BY id DESC LIMIT 1")"
    [[ "$ENROLLMENT_ID" =~ ^[1-9][0-9]*$ ]] || fail "Matrícula criada não foi encontrada no banco."
    SNAPSHOT_COUNT="$(db_value "SELECT COUNT(*) FROM matriculas WHERE id = $ENROLLMENT_ID AND plano_nome_contratado = 'Plano HTTP Bloco 03' AND valor_contratado = 149.90 AND duracao_contratada_valor = 1 AND duracao_contratada_unidade = 'MES'")"
    [[ "$SNAPSHOT_COUNT" == "1" ]] || fail "Snapshot da contratação não preservou nome, preço e duração."
    ENROLLMENT_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao = 'matricula_criada' AND entidade = 'matriculas' AND entidade_id = $ENROLLMENT_ID")"
    [[ "$ENROLLMENT_AUDIT" == "1" ]] || fail "Criação de matrícula não foi auditada exatamente uma vez."
    CHARGE_TABLE_COUNT="$(db_value "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cobrancas'")"
    [[ "$CHARGE_TABLE_COUNT" == "0" ]] || fail "O Bloco 03 não deve criar nem depender da tabela financeira cobrancas."

    TOKEN="$(get_token "$TMP_DIR/block03-enrollment-created.html")"
    BOUNDARY_CANCEL_REASON="$(php -r 'echo str_repeat("ç", 255);')"
    curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/cancelar-matricula.php" \
        --data-urlencode "token=$TOKEN" \
        --data-urlencode "student_id=$BLOCK03_STUDENT_ID" \
        --data-urlencode "enrollment_id=$ENROLLMENT_ID" \
        --data-urlencode "motivo_cancelamento=$BOUNDARY_CANCEL_REASON" \
        -o "$TMP_DIR/block03-enrollment-cancelled.html"
    grep -Fq 'Matrícula cancelada com sucesso.' "$TMP_DIR/block03-enrollment-cancelled.html" || fail "Cancelamento da matrícula agendada não foi concluído."
    CANCELLED_AT="$(db_value "SELECT COUNT(*) FROM matriculas WHERE id = $ENROLLMENT_ID AND cancelada_em IS NOT NULL")"
    [[ "$CANCELLED_AT" == "1" ]] || fail "Cancelamento não foi persistido."
    CANCEL_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao = 'matricula_cancelada' AND entidade = 'matriculas' AND entidade_id = $ENROLLMENT_ID")"
    [[ "$CANCEL_AUDIT" == "1" ]] || fail "Cancelamento não foi auditado exatamente uma vez."
    CANCEL_REASON_CHARS="$(db_value "SELECT CHAR_LENGTH(motivo_cancelamento) FROM matriculas WHERE id = $ENROLLMENT_ID")"
    CANCEL_REASON_BYTES="$(db_value "SELECT LENGTH(motivo_cancelamento) FROM matriculas WHERE id = $ENROLLMENT_ID")"
    [[ "$CANCEL_REASON_CHARS" == "255" && "$CANCEL_REASON_BYTES" -gt 255 ]] || fail "O endpoint não preservou o limite de 255 caracteres Unicode."

    TOKEN="$(get_token "$TMP_DIR/block03-enrollment-cancelled.html")"
    OVER_LIMIT_CANCEL_REASON="$(php -r 'echo str_repeat("ç", 256);')"
    OVER_LIMIT_CANCEL_RESPONSE="$(curl -fsS -L -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/cancelar-matricula.php" \
        --data-urlencode "token=$TOKEN" \
        --data-urlencode "student_id=$BLOCK03_STUDENT_ID" \
        --data-urlencode "enrollment_id=$ENROLLMENT_ID" \
        --data-urlencode "motivo_cancelamento=$OVER_LIMIT_CANCEL_REASON")"
    grep -Fq 'no máximo 255 caracteres válidos' <<< "$OVER_LIMIT_CANCEL_RESPONSE" || fail "O endpoint deveria rejeitar 256 caracteres Unicode."
    CANCEL_AUDIT_AFTER_REJECTION="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao = 'matricula_cancelada' AND entidade = 'matriculas' AND entidade_id = $ENROLLMENT_ID")"
    [[ "$CANCEL_AUDIT_AFTER_REJECTION" == "1" ]] || fail "Motivo acima do limite duplicou auditoria."
    CANCEL_REASON_AFTER_REJECTION="$(db_value "SELECT CHAR_LENGTH(motivo_cancelamento) FROM matriculas WHERE id = $ENROLLMENT_ID")"
    [[ "$CANCEL_REASON_AFTER_REJECTION" == "255" ]] || fail "Motivo inválido alterou o motivo original."

    curl -fsS -b "$ADMIN_JAR" -c "$ADMIN_JAR" "$BASE_URL/revisoes-legado.php" -o "$TMP_DIR/block03-legacy-review.html"
    grep -Fq 'Revisão de vínculos legados' "$TMP_DIR/block03-legacy-review.html" || fail "Fila de revisão de legados não abriu."

    # O perfil OPERADOR pode contratar/cancelar, mas não gerir o catálogo
    # nem visualizar/decidir/retificar vínculos legados.
    BLOCK03_OPERATOR_JAR="$TMP_DIR/block03-operator.cookies"
    curl -fsS -c "$BLOCK03_OPERATOR_JAR" -b "$BLOCK03_OPERATOR_JAR" "$BASE_URL/login.php" -o "$TMP_DIR/block03-operator-login.html"
    BLOCK03_OPERATOR_TOKEN="$(get_token "$TMP_DIR/block03-operator-login.html")"
    curl -fsS -L -c "$BLOCK03_OPERATOR_JAR" -b "$BLOCK03_OPERATOR_JAR" "$BASE_URL/login.php" \
        --data-urlencode "token=$BLOCK03_OPERATOR_TOKEN" \
        --data-urlencode "email=operator-http@example.test" \
        --data-urlencode "senha=SenhaOperadorNova-2026!" \
        -o "$TMP_DIR/block03-operator-dashboard.html"
    grep -Fq 'Cadastro de alunos' "$TMP_DIR/block03-operator-dashboard.html" || fail "Operador do Bloco 03 não conseguiu autenticar."

    OPERATOR_PLANS_CODE="$(curl -sS -o "$TMP_DIR/block03-operator-plans.html" -w '%{http_code}' -b "$BLOCK03_OPERATOR_JAR" -c "$BLOCK03_OPERATOR_JAR" "$BASE_URL/planos.php?nova_matricula=1&aluno_id=$BLOCK03_STUDENT_ID")"
    [[ "$OPERATOR_PLANS_CODE" == "200" ]] || fail "Operador deveria consultar catálogo e abrir matrícula; recebeu $OPERATOR_PLANS_CODE."
    grep -Fq 'Confirmar matrícula' "$TMP_DIR/block03-operator-plans.html" || fail "Operador deveria visualizar o formulário de matrícula."
    if grep -Fq 'Adicionar plano' "$TMP_DIR/block03-operator-plans.html"; then
        fail "Operador não deveria visualizar o formulário de gestão do catálogo."
    fi
    OPERATOR_PLAN_TOKEN="$(get_token "$TMP_DIR/block03-operator-plans.html")"

    PLAN_COUNT_BEFORE_DENIAL="$(db_value "SELECT COUNT(*) FROM planos")"
    OPERATOR_SAVE_PLAN_CODE="$(curl -sS -o "$TMP_DIR/block03-operator-save-plan.html" -w '%{http_code}' -b "$BLOCK03_OPERATOR_JAR" -c "$BLOCK03_OPERATOR_JAR" "$BASE_URL/salvar-plano.php" \
        --data-urlencode "token=$OPERATOR_PLAN_TOKEN" \
        --data-urlencode "nome=Plano indevido pelo Operador" \
        --data-urlencode "duracao_valor=1" \
        --data-urlencode "duracao_unidade=MES" \
        --data-urlencode "valor_atual=99,90")"
    [[ "$OPERATOR_SAVE_PLAN_CODE" == "403" ]] || fail "Operador deveria receber 403 ao criar plano; recebeu $OPERATOR_SAVE_PLAN_CODE."
    PLAN_COUNT_AFTER_DENIAL="$(db_value "SELECT COUNT(*) FROM planos")"
    [[ "$PLAN_COUNT_AFTER_DENIAL" == "$PLAN_COUNT_BEFORE_DENIAL" ]] || fail "Tentativa negada de criar plano alterou o catálogo."

    OPERATOR_MANAGE_PLAN_CODE="$(curl -sS -o "$TMP_DIR/block03-operator-manage-plan.html" -w '%{http_code}' -b "$BLOCK03_OPERATOR_JAR" -c "$BLOCK03_OPERATOR_JAR" "$BASE_URL/gerenciar-plano.php" \
        --data-urlencode "token=$OPERATOR_PLAN_TOKEN" \
        --data-urlencode "id=$PLAN_ID" \
        --data-urlencode "acao=inativar")"
    [[ "$OPERATOR_MANAGE_PLAN_CODE" == "403" ]] || fail "Operador deveria receber 403 ao alterar o catálogo; recebeu $OPERATOR_MANAGE_PLAN_CODE."
    PLAN_STATE_AFTER_DENIAL="$(db_value "SELECT situacao FROM planos WHERE id = $PLAN_ID")"
    [[ "$PLAN_STATE_AFTER_DENIAL" == "ATIVO" ]] || fail "Tentativa negada alterou a situação do plano."

    OPERATOR_LEGACY_VIEW_CODE="$(curl -sS -o "$TMP_DIR/block03-operator-legacy.html" -w '%{http_code}' -b "$BLOCK03_OPERATOR_JAR" -c "$BLOCK03_OPERATOR_JAR" "$BASE_URL/revisoes-legado.php")"
    [[ "$OPERATOR_LEGACY_VIEW_CODE" == "403" ]] || fail "Operador deveria receber 403 ao consultar revisões legadas; recebeu $OPERATOR_LEGACY_VIEW_CODE."
    OPERATOR_LEGACY_DECISION_CODE="$(curl -sS -o "$TMP_DIR/block03-operator-legacy-decision.html" -w '%{http_code}' -b "$BLOCK03_OPERATOR_JAR" -c "$BLOCK03_OPERATOR_JAR" "$BASE_URL/salvar-revisao-legado.php" \
        --data-urlencode "token=$OPERATOR_PLAN_TOKEN" \
        --data-urlencode "review_id=1" \
        --data-urlencode "resultado=LEGADO_SEM_VIGENCIA")"
    [[ "$OPERATOR_LEGACY_DECISION_CODE" == "403" ]] || fail "Operador deveria receber 403 ao decidir revisão legada; recebeu $OPERATOR_LEGACY_DECISION_CODE."
    OPERATOR_LEGACY_RECTIFY_CODE="$(curl -sS -o "$TMP_DIR/block03-operator-legacy-rectify.html" -w '%{http_code}' -b "$BLOCK03_OPERATOR_JAR" -c "$BLOCK03_OPERATOR_JAR" "$BASE_URL/retificar-vigencia-legado.php?review_id=1")"
    [[ "$OPERATOR_LEGACY_RECTIFY_CODE" == "403" ]] || fail "Operador deveria receber 403 ao abrir retificação legada; recebeu $OPERATOR_LEGACY_RECTIFY_CODE."
    OPERATOR_SAVE_RECTIFY_CODE="$(curl -sS -o "$TMP_DIR/block03-operator-save-rectify.html" -w '%{http_code}' -b "$BLOCK03_OPERATOR_JAR" -c "$BLOCK03_OPERATOR_JAR" "$BASE_URL/salvar-retificacao-legado.php" \
        --data-urlencode "token=$OPERATOR_PLAN_TOKEN" \
        --data-urlencode "review_id=1" \
        --data-urlencode "enrollment_id=1" \
        --data-urlencode "fonte_referencia=Tentativa indevida" \
        --data-urlencode "observacoes=Tentativa sem permissão" \
        --data-urlencode "inicio_em=2026-01-01T09:00" \
        --data-urlencode "fim_exclusivo_em=2026-02-01T09:00")"
    [[ "$OPERATOR_SAVE_RECTIFY_CODE" == "403" ]] || fail "Operador deveria receber 403 ao enviar retificação legada; recebeu $OPERATOR_SAVE_RECTIFY_CODE."

    OPERATOR_ENROLL_START_TOKEN="$(get_token "$TMP_DIR/block03-operator-plans.html")"
    curl -fsS -L -b "$BLOCK03_OPERATOR_JAR" -c "$BLOCK03_OPERATOR_JAR" "$BASE_URL/planos.php?nova_matricula=1" \
        --data-urlencode "token=$OPERATOR_ENROLL_START_TOKEN" \
        --data-urlencode "acao=criar_matricula" \
        --data-urlencode "aluno_id=$BLOCK03_STUDENT_ID" \
        --data-urlencode "plano_id=$PLAN_ID" \
        --data-urlencode "data_inicio=2027-01-11" \
        --data-urlencode "hora_inicio=09:00" \
        -o "$TMP_DIR/block03-operator-enrollment-created.html"
    grep -Fq 'Matrícula #' "$TMP_DIR/block03-operator-enrollment-created.html" || fail "Operador autorizado não conseguiu criar matrícula."
    OPERATOR_ENROLLMENT_ID="$(db_value "SELECT id FROM matriculas WHERE aluno_id = $BLOCK03_STUDENT_ID ORDER BY id DESC LIMIT 1")"
    [[ "$OPERATOR_ENROLLMENT_ID" =~ ^[1-9][0-9]*$ ]] || fail "Matrícula criada pelo Operador não foi encontrada."
    OPERATOR_DETAIL_TOKEN="$(get_token "$TMP_DIR/block03-operator-enrollment-created.html")"
    grep -Fq 'Cancelar matrícula' "$TMP_DIR/block03-operator-enrollment-created.html" || fail "Operador deveria visualizar a ação de cancelamento."
    curl -fsS -L -b "$BLOCK03_OPERATOR_JAR" -c "$BLOCK03_OPERATOR_JAR" "$BASE_URL/cancelar-matricula.php" \
        --data-urlencode "token=$OPERATOR_DETAIL_TOKEN" \
        --data-urlencode "student_id=$BLOCK03_STUDENT_ID" \
        --data-urlencode "enrollment_id=$OPERATOR_ENROLLMENT_ID" \
        --data-urlencode "motivo_cancelamento=Cancelamento por Operador HTTP" \
        -o "$TMP_DIR/block03-operator-enrollment-cancelled.html"
    grep -Fq 'Matrícula cancelada com sucesso.' "$TMP_DIR/block03-operator-enrollment-cancelled.html" || fail "Operador autorizado não conseguiu cancelar matrícula."
    OPERATOR_CANCELLED_COUNT="$(db_value "SELECT COUNT(*) FROM matriculas WHERE id = $OPERATOR_ENROLLMENT_ID AND cancelada_em IS NOT NULL")"
    [[ "$OPERATOR_CANCELLED_COUNT" == "1" ]] || fail "Cancelamento do Operador não foi persistido."
    OPERATOR_CANCEL_AUDIT="$(db_value "SELECT COUNT(*) FROM auditoria WHERE acao = 'matricula_cancelada' AND entidade = 'matriculas' AND entidade_id = $OPERATOR_ENROLLMENT_ID")"
    [[ "$OPERATOR_CANCEL_AUDIT" == "1" ]] || fail "Cancelamento do Operador não foi auditado exatamente uma vez."

    echo "Bloco 03 HTTP: catálogo, contratação, snapshot, cancelamento e revisão + RBAC Operador: OK"
fi

echo "Integração HTTP: autenticação, RBAC, CRUD, status de alunos, detalhes e auditoria: OK"
