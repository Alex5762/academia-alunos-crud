# Estado da fundação de autenticação e segurança — 2026-11-24

## Estado do repositório

- Repositório: `Alex5762/academia-alunos-crud`.
- PR #2: aberta e pronta para revisão; não mesclada.
- Branch da PR: `feat/foundation-auth-security`.
- HEAD anterior à correção de sessões: `4e9d3b8074e4d665967d4382d130f6e6d0e4469e`.
- Correção de revogação de sessões commitada em `0b201b2f30b2ab928f3cf45300e2875a5c69a0b9`.
- A primeira execução de CI identificou dependência entre as baterias HTTP: a primeira troca voluntariamente a senha do Administrador compartilhado; a segunda ainda tentava usar a senha anterior. O fixture da segunda bateria foi alinhado para usar a senha resultante.
- `main`: `e1f4c8084dc6dec8c772a699855562944d50e89e`, sem alterações.
- Proteção da `main`: ativa e confirmada por API (`protected: true`).
- Regras: PR obrigatória; cinco checks obrigatórios; branch atualizada; bypass, force-push e exclusão bloqueados.
- Nenhuma migration foi aplicada ao ambiente local do usuário.

## CI anterior à correção

No HEAD `4e9d3b8074e4d665967d4382d130f6e6d0e4469e`, os dois runs mais recentes estavam verdes:
- Run #45392512469 (pull_request): 5/5 jobs aprovados.
- Run #45392500487 (push): 5/5 jobs aprovados.

Os cinco checks configurados na proteção da `main`:
1. `PHP quality and unit tests`
2. `Foundation database (MySQL 8.0)`
3. `Foundation database (MariaDB 10.11)`
4. `Foundation HTTP integration (MySQL 8.0)`
5. `Apache webroot protection`

Essas aprovações são anteriores às alterações descritas abaixo. Uma nova CI é necessária para validar o novo HEAD.

## Revisão técnica da PR #2 — 2026-11-24

### Bloqueador encontrado

A revisão identificou que a troca obrigatória (`trocar-senha.php`) e a troca voluntária (`alterar-senha.php`) atualizavam o hash, mas não incrementavam `usuarios.sessao_versao`. Assim, sessões paralelas poderiam continuar válidas depois da troca de senha.

### Correção preparada nesta etapa

- Fazer a leitura da conta com `FOR UPDATE` dentro da transação.
- Confirmar que a versão persistida ainda corresponde à versão da sessão que solicitou a troca.
- Incrementar `sessao_versao` na mesma transação que altera o hash da senha.
- Atualizar a versão da sessão atual depois de regenerar o identificador, preservando a sessão que concluiu a troca.
- Invalidar sessões paralelas antigas, que passam a falhar na próxima validação de sessão.
- Expandir `tests/foundation-http.sh`: abrir cookies independentes antes da troca obrigatória e voluntária; comprovar revogação usando `renovar-sessao.php` (HTTP 401); comprovar que a sessão autora permanece válida.
- Atualizar README para descrever o comportamento.

**Status de validação:** correção de código commitada em `0b201b2`; a CI inicial encontrou apenas uma dependência de fixture entre as duas baterias HTTP (senha do administrador compartilhado). O fixture foi alinhado em commit subsequente; aguardar nova CI antes de fechar o bloqueador.

### Outros pontos identificados, ainda pendentes

1. **Auditoria de falhas de login e cobertura do rate limit:** o login incrementa `login_tentativas`, mas as falhas não geram evento na cadeia de auditoria. Faltam testes HTTP dedicados para o limite de cinco falhas, janela e bloqueio.
2. **Criação administrativa de contas:** `salvar-usuario.php` valida `users.manage` antes da transação, mas não revalida o administrador sob lock dentro da transação. Avaliar centralização no serviço de administração e teste de concorrência com revogação de privilégio.

## Escopo da PR #2

Inclui autenticação, sessões, troca de senha, RBAC, rate limit, administração concorrente de contas, auditoria append-only com cadeia SHA-256, configuração PDO por ambiente, CRUD protegido, proteção do webroot e CI incremental.

Ficam fora: matrículas, frequência, financeiro, grade, dashboard completo, relatórios e migration 010.

## Próxima sequência

1. Confirmar o novo HEAD da branch e revisar o diff dos arquivos alterados.
2. Acompanhar a CI nova e confirmar os cinco checks.
3. Fechar ou corrigir eventuais falhas da correção de revogação de sessões.
4. Tratar os dois pontos pendentes da revisão (rate limit/auditoria e revalidação de criação de contas).
5. Atualizar este documento e o parecer de revisão a cada etapa.
6. Não fazer merge sem autorização explícita do usuário.
