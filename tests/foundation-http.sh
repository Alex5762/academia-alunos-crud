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

    # O endpoint de renovação distingue revogação real do redirecionamento de troca obrigatória.
    stale_token="$(get_token "$TMP_DIR/$prefix-stale-first-access.html")"
    stale_status="$(curl -sS -o "$TMP_DIR/$prefix-stale-renewal.json" -w '%{http_code}' \
        -b "$stale_jar" -c "$stale_jar" "$BASE_URL/renovar-sessao.php" \
        --data-urlencode "token=$stale_token")"
    [[ "$stale_status" == "401" ]] || fail "$prefix segunda sessão deveria ser revogada após troca obrigatória; renovar-sessao.php retornou $stale_status."
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
[[ "$OPERATOR_DELETE_CODE" == "403" ]] || fail "Operador deveria receber 403 na exclusão; recebeu $OPERATOR_DELETE_CODE."

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
