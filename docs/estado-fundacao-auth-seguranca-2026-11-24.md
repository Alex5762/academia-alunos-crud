# Estado da fundação de autenticação e segurança — 2026-11-24

## Estado do repositório

- Repositório: `Alex5762/academia-alunos-crud`.
- PR #2: aberta e pronta para revisão; não mesclada.
- Branch da PR: `feat/foundation-auth-security`.
- HEAD anterior à correção de sessões: `4e9d3b8074e4d665967d4382d130f6e6d0e4469e`.
- HEAD da correção e fixture HTTP: `6282bb03b8dcb5d3f1b0af8f4f71fb1595de9373`.
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

**Status de validação:** correção implementada e coberta. A primeira CI identificou dependência de fixture entre as duas baterias HTTP (senha do administrador compartilhado); o fixture foi alinhado em `6282bb0`. No HEAD `6282bb03b8dcb5d3f1b0af8f4f71fb1595de9373`, os runs #45399787132 (push) e #45399800406 (pull_request) concluíram com sucesso, 5/5 jobs cada. A bateria `foundation-http.sh` e a bateria `foundation-account-http.sh` passaram no job HTTP dos dois eventos.

### Outros pontos identificados, ainda pendentes

1. **Auditoria de falhas de login e cobertura do rate limit:** o login incrementa `login_tentativas`, mas as falhas não geram evento na cadeia de auditoria. Faltam testes HTTP dedicados para o limite de cinco falhas, janela e bloqueio.
2. **Criação administrativa de contas:** `salvar-usuario.php` valida `users.manage` antes da transação, mas não revalida o administrador sob lock dentro da transação. Avaliar centralização no serviço de administração e teste de concorrência com revogação de privilégio.

## Escopo da PR #2

Inclui autenticação, sessões, troca de senha, RBAC, rate limit, administração concorrente de contas, auditoria append-only com cadeia SHA-256, configuração PDO por ambiente, CRUD protegido, proteção do webroot e CI incremental.

Ficam fora: matrículas, frequência, financeiro, grade, dashboard completo, relatórios e migration 010.

## Próxima sequência

1. Achado 1 — revogação de sessões paralelas após troca de senha: resolvido e validado em CI.
2. Achado 2 — auditoria de falhas de login e rate limit: resolvido e validado em CI no commit `d52fe3bb0937b2cbaf26313dbfcfd03f31bdd3f8`.
3. Próxima etapa: tratar o Achado 3 — revalidação da autorização dentro da transação de criação de contas.
4. Reexecutar e confirmar os cinco checks após a correção do Achado 3.
5. Não fazer merge sem autorização explícita do usuário.


## Etapa 2 — rate limit e auditoria de falhas de login

**Implementação concluída e validada em CI.**

- Criado `src/Security/LoginAttemptRateLimiter.php` para centralizar consulta, bloqueio, registro e limpeza de tentativas.
- Política: cinco falhas em janela de 15 minutos; bloqueio por 15 minutos. O contador permanece limitado a cinco mesmo sob requisições concorrentes.
- A tabela mantém hashes SHA-256 do e-mail normalizado e IP de origem. Registros sem atividade por mais de um dia são removidos.
- Cada falha que passa pelo bloqueio registra `login_falhou` na cadeia append-only, sem e-mail, IP ou hashes de identificação. Contador e auditoria usam a mesma transação.
- Tentativas bloqueadas não geram eventos repetidos. Login válido limpa o contador da combinação.
- `tests/foundation-http.sh` cobre falhas 1–5, bloqueio, tentativa durante bloqueio, expiração/reinício e limpeza após login válido.
- Novo `tests/database-login-rate-limit.php` cobre concorrência com quatro processos, contador limitado, auditoria sem PII, expiração, limpeza e integridade da cadeia.
- CI adicionada para executar o teste concorrente no MySQL 8.0 e MariaDB 10.11.

### Resultado e próximos passos

1. Runs #45413951571 (push) e #45413968757 (pull_request), no commit `d52fe3bb0937b2cbaf26313dbfcfd03f31bdd3f8`: ambos verdes, 5/5 jobs.
2. A etapa duplicada do teste concorrente foi removida; a asserção de auditoria compara a diferença de eventos gerados pela própria bateria.
3. Próxima etapa: tratar o Achado 3 — revalidar a autorização administrativa dentro da transação de criação de contas.
4. Não fazer merge sem autorização explícita.


## Validação final do Achado 2 — 2026-11-24

- HEAD validado: `d52fe3bb0937b2cbaf26313dbfcfd03f31bdd3f8`.
- Run #45413951571 (push): sucesso, 5/5 jobs.
- Run #45413968757 (pull_request): sucesso, 5/5 jobs.
- Jobs aprovados em ambos: PHP quality and unit tests; Foundation database (MySQL 8.0); Foundation database (MariaDB 10.11); Foundation HTTP integration (MySQL 8.0); Apache webroot protection.
- O workflow executa somente uma vez o teste concorrente por job. A asserção compara o delta de eventos `login_falhou` criados pelo teste; a cadeia geral continua append-only.
- `main` permanece em `e1f4c8084dc6dec8c772a699855562944d50e89e`; PR #2 permanece aberta e não mesclada.
- Achado 3 é o único dos três achados da revisão técnica ainda pendente.
