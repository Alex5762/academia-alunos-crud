#!/usr/bin/env bash
set -Eeuo pipefail

IMAGE="${APACHE_TEST_IMAGE:-httpd:2.4}"
CONTAINER_NAME="fitcontrol-webroot-protection"
CONTAINER_PORT="${APACHE_TEST_PORT:-18081}"
BASE_URL="http://127.0.0.1:${CONTAINER_PORT}/fitcontrol"
TEMP_DIR="$(mktemp -d)"

cleanup() {
    docker rm --force "$CONTAINER_NAME" >/dev/null 2>&1 || true
    rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

fail() {
    echo "FALHA: $1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail "Docker é necessário para testar as regras no Apache real."
command -v curl >/dev/null 2>&1 || fail "curl é necessário para testar as respostas HTTP."
test -f .git/config || fail "O teste espera o checkout Git completo para validar o bloqueio de .git."

echo "Baixando Apache ${IMAGE}..."
docker pull "$IMAGE"

docker rm --force "$CONTAINER_NAME" >/dev/null 2>&1 || true
docker run --detach \
    --name "$CONTAINER_NAME" \
    --publish "127.0.0.1:${CONTAINER_PORT}:80" \
    --volume "$PWD:/usr/local/apache2/htdocs/fitcontrol:ro" \
    "$IMAGE" >/dev/null

# A imagem oficial do Apache desabilita .htaccess por padrão. Habilitamos
# AllowOverride no diretório de teste para reproduzir o uso em XAMPP.
docker cp "$CONTAINER_NAME:/usr/local/apache2/conf/httpd.conf" "$TEMP_DIR/httpd.conf"
sed -i 's/AllowOverride None/AllowOverride All/g' "$TEMP_DIR/httpd.conf"
docker cp "$TEMP_DIR/httpd.conf" "$CONTAINER_NAME:/usr/local/apache2/conf/httpd.conf"
docker exec "$CONTAINER_NAME" httpd -t
docker exec "$CONTAINER_NAME" httpd -k graceful

http_status() {
    curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
        --max-time 5 "$BASE_URL/$1" 2>/dev/null || printf '000'
}

assert_blocked() {
    local path="$1"
    local status
    status="$(http_status "$path")"

    case "$status" in
        403|404)
            echo "OK: /$path bloqueado (HTTP $status)"
            ;;
        *)
            fail "A URL /$path deveria retornar 403/404, mas retornou HTTP $status."
            ;;
    esac
}

assert_available() {
    local path="$1"
    local expected="$2"
    local status
    status="$(http_status "$path")"
    [[ "$status" == "$expected" ]] || fail "A URL /$path deveria retornar HTTP $expected, mas retornou HTTP $status."
    echo "OK: /$path permanece acessível (HTTP $status)"
}

for attempt in $(seq 1 20); do
    if [[ "$(http_status style.css)" == "200" ]]; then
        break
    fi
    sleep 1
done

assert_available "style.css" "200"
assert_blocked "banco.sql"
assert_blocked "database/migrations/001_authentication_audit.sql"
assert_blocked "scripts/provisionar-usuario.php"
assert_blocked "tests/password-policy.php"
assert_blocked "src/Infrastructure/DatabaseConfig.php"
assert_blocked "docs/matriz-permissoes.md"
assert_blocked "config/database.local.example.php"
assert_blocked ".github/workflows/ci.yml"
assert_blocked ".git/config"

echo "Proteção do webroot: todas as verificações HTTP passaram."
