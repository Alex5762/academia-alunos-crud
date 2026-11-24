# Estado da fundação de autenticação e segurança — 2026-11-24

## Estado confirmado

- PR #2: aberta, marcada pronta para revisão após CI completa e revisão de escopo; sem merge.
- Branch: `feat/foundation-auth-security`.
- HEAD do checkpoint validado: `fd8f8adef73e6ccd727c0469beae29a46f9c679f`.
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

Após adicionar `tests/foundation-account-http.sh` ao workflow, o checkpoint `fd8f8adef73e6ccd727c0469beae29a46f9c679f` foi validado integralmente:
- Run #45392052117, evento pull_request: cinco jobs aprovados.
- Run #45392039299, evento push: cinco jobs aprovados.
- No job HTTP da PR, o passo `Verify account administration HTTP endpoints and audit integrity` foi executado e aprovado, além do fluxo `foundation-http.sh`.
- Checks obrigatórios observados: `PHP quality and unit tests`; `Foundation database (MySQL 8.0)`; `Foundation database (MariaDB 10.11)`; `Foundation HTTP integration (MySQL 8.0)`; `Apache webroot protection`.

Revisão do PR #2 no mesmo checkpoint:
- 54 arquivos alterados; 4.715 adições e 73 remoções.
- Nenhum comentário de revisão ou review pendente retornado pelo GitHub.
- GitHub reportou `mergeable: true`; isso não substitui a revisão nem autoriza merge.

## Escopo da PR #2

Inclui autenticação, sessões, troca de senha, RBAC, rate limit, administração concorrente de contas, auditoria append-only com cadeia SHA-256, configuração PDO por ambiente, CRUD protegido, proteção do webroot e CI incremental.

Ficam fora: matrículas, frequência, financeiro, grade, dashboard completo, relatórios e migration 010.

## Próximos passos

1. CI do checkpoint `fd8f8ade` concluída com sucesso nos eventos push e pull_request.
2. Escopo revisado: a PR mantém a fundação, sem matrículas, frequência, financeiro, grade, dashboard, relatórios ou migration 010.
3. PR #2 pode ficar pronta para revisão; não fazer merge nesta etapa.
4. Configurar proteção da `main` nas Settings do GitHub: exigir PR, os cinco checks acima, bloquear force-push e exclusão, sem exigir aprovação de terceiro no repositório individual. A conexão GitHub atual não possui permissão administrativa para aplicar essas regras; `main.protected` permanece `false`.
5. Fazer merge somente após autorização explícita do usuário e após a proteção da `main` estar ativa.
