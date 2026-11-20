#!/usr/bin/env bash
set -Eeuo pipefail

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

fail() {
    echo "FALHA: $1" >&2
    exit 1
}

echo "1/3 - Provisionando conta por CLI..."
if ! printf '%s\n%s\n' 'Usuário de Teste CI' 'provision-ci@example.test' |
    php scripts/provisionar-usuario.php >"$TMP_DIR/provision.txt" 2>"$TMP_DIR/provision.err"; then
    cat "$TMP_DIR/provision.err" >&2
    fail "O provisionador não criou a conta."
fi

grep -Fq 'Conta criada com sucesso.' "$TMP_DIR/provision.txt" ||
    fail "Mensagem de sucesso não encontrada."

SENHA="$(sed -n 's/^Senha temporária (copie agora): //p' "$TMP_DIR/provision.txt")"
[[ "${#SENHA}" -eq 32 ]] || fail "A senha temporária não tem o tamanho esperado."
if [[ -n "${GITHUB_ENV:-}" ]]; then
    printf 'FITCONTROL_TEST_PASSWORD=%s\n' "$SENHA" >> "$GITHUB_ENV"
fi

echo "2/3 - Conferindo hash e evento de auditoria..."
printf '%s' "$SENHA" | php tests/assert-user-provisioned.php ||
    fail "A validação da conta provisionada falhou."

echo "3/3 - Impedindo duplicidade de e-mail..."
if printf '%s\n%s\n' 'Outro Nome' 'provision-ci@example.test' |
    php scripts/provisionar-usuario.php >"$TMP_DIR/duplicate.txt" 2>"$TMP_DIR/duplicate.err"; then
    fail "O provisionador aceitou um e-mail duplicado."
fi

grep -Fq 'Verifique se esse e-mail já está cadastrado.' "$TMP_DIR/duplicate.err" ||
    fail "A rejeição do e-mail duplicado não retornou a mensagem esperada."

echo "Provisionamento CLI, hash de senha, auditoria e unicidade: OK"
