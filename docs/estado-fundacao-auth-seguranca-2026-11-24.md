# Estado da fundação de autenticação e segurança — 2026-11-24

## Estado confirmado

- PR #2: aberta em Draft, base `main`, sem merge.
- Branch: `feat/foundation-auth-security`.
- HEAD atual: `d5a077eae43f80d665a729a68f54d9f54ca08090`.
- `main`: permanece em `e1f4c8084dc6dec8c772a699855562944d50e89e`.
- Proteção de branch: ainda desativada (`protected: false`); falta permissão administrativa para configurá-la pela conexão atual.
- Nenhuma migration foi aplicada ao ambiente local do usuário.

## Segurança de sessão

A migration inicial inclui `usuarios.sessao_versao`. O login grava a versão na sessão; cada alteração de status incrementa a versão dentro da transação; toda requisição autenticada compara a versão da sessão com a versão persistida. Isso impede que cookies emitidos antes da desativação voltem a funcionar depois da reativação. Um novo login cria uma sessão com a versão atual.

## Testes de conta via HTTP

Há duas baterias:
- `tests/foundation-http.sh`: fluxo CRUD protegido e cenários básicos de autenticação, CSRF, RBAC, perfil, status, revogação e auditoria.
- `tests/foundation-account-http.sh`: fluxo dedicado de criação de conta, senha temporária, troca obrigatória, negação por perfil, CSRF inválido, alteração de perfil, autodesativação, desativação, reativação, login/logout e verificação da cadeia de auditoria.

O workflow foi atualizado para executar explicitamente as duas baterias. O teste de sessão preservada usa um segundo cookie autenticado que não é acessado durante a desativação e só é testado após a reativação, comprovando a revogação por versão persistida.

## CI

Última execução completa anterior à inclusão da bateria HTTP dedicada:
- Run #45390992407, evento pull_request, HEAD `f37cb19db2e32bcaf5939beee235732724ae5a63`.
- Cinco jobs aprovados: PHP quality, MySQL 8.0, MariaDB 10.11, HTTP integration e Apache webroot protection.

Após adicionar `tests/foundation-account-http.sh` ao workflow:
- Run #45391972285, HEAD `d5a077eae43f80d665a729a68f54d9f54ca08090`.
- Estado no momento desta atualização: em execução. A aprovação final ainda não está confirmada.

## Escopo da PR #2

Inclui autenticação, sessões, troca de senha, RBAC, rate limit, administração concorrente de contas, auditoria append-only com cadeia SHA-256, configuração PDO por ambiente, CRUD protegido, proteção do webroot e CI incremental.

Ficam fora: matrículas, frequência, financeiro, grade, dashboard completo, relatórios e migration 010.

## Próximos passos

1. Aguardar a CI #45391972285 e confirmar o novo job HTTP dedicado.
2. Corrigir eventuais falhas e repetir a CI.
3. Revisar o diff final e manter a PR em Draft até conclusão da revisão.
4. Configurar proteção da `main` nas Settings do GitHub: exigir PR, checks obrigatórios, bloquear force-push e exclusão; sem exigir aprovação de terceiro no repositório individual.
5. Só fazer merge após autorização explícita do usuário.
