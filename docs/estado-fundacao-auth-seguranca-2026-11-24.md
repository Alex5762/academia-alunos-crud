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
3. Achado 3 — criação administrativa: implementação em `UserAccountAdministrationService::createAccount()`, com revalidação de autorização sob lock e teste concorrente; CI do novo HEAD pendente.
4. Confirmar os cinco checks após a correção do Achado 3.
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


## Etapa 3 — revalidação da autorização na criação de contas — 2026-11-24

**Implementação concluída e validada em CI.**

- Criado `UserAccountAdministrationService::createAccount()`.
- A criação usa a mesma transação e o mesmo lock global `SELECT ... FOR UPDATE` compartilhado pelas alterações de perfil/status.
- Dentro da transação, o serviço revalida que o ator existe, está ativo, não precisa trocar senha e ainda possui a permissão `users.manage`.
- A inserção da conta e o evento `usuario_criado` na cadeia append-only ocorrem na mesma transação.
- `salvar-usuario.php` mantém a checagem antecipada para rejeição rápida, mas delega a criação efetiva ao serviço; se o ator perder a permissão antes de obter o lock, a resposta é HTTP 403 e nenhuma conta é criada.
- `tests/database-user-account-administration.php` cobre o cenário concorrente: pré-autorização válida, criação aguardando lock, revogação do perfil, negação posterior da criação e ausência de registro persistido. Também verifica o caminho autorizado e a auditoria.
- A bateria é executada no MySQL 8.0 e MariaDB 10.11 pelo job de administração de contas.
- Não houve alteração na `main` nem merge da PR #2.


## Validação final do Achado 3 — 2026-11-24

- HEAD validado: `c46373aa2abf20726ff113f70f8d8780defc0cd8`.
- Run #45417710794 (push): sucesso, 5/5 jobs.
- Run #45417736963 (pull_request): sucesso, 5/5 jobs.
- Jobs aprovados em ambos: PHP quality and unit tests; Foundation database (MySQL 8.0); Foundation database (MariaDB 10.11); Foundation HTTP integration (MySQL 8.0); Apache webroot protection.
- O teste concorrente confirma que uma criação iniciada com pré-autorização válida, mas aguardando o lock enquanto o perfil do ator é revogado, termina com `UNAUTHORIZED` e não persiste a conta.
- A criação autorizada permanece coberta pelo fluxo HTTP, incluindo auditoria e senha temporária.
- Os três achados da revisão técnica estão resolvidos e validados. A PR #2 permanece aberta, sem merge, aguardando revisão final e autorização explícita.
- `main` permanece intacta em `e1f4c8084dc6dec8c772a699855562944d50e89e`.

## Revisão final complementar — 2026-11-24

A revisão do diff completo da PR #2 confirmou que os três achados originais permanecem resolvidos e que os cinco jobs do run #45418011102 passaram no HEAD documental anterior. Foi identificada, porém, uma observação adicional no fluxo de rate limit:

- Em `login.php`, a chamada `isBlocked()` ocorre antes da verificação da senha e não reserva/serializa a tentativa.
- A gravação do contador e do evento `login_falhou` acontece depois, por `recordFailure()`.
- Requisições concorrentes podem consultar o estado ainda desbloqueado e avançar simultaneamente até a verificação de credenciais. O teste de banco demonstra que o contador final fica limitado a cinco, mas não demonstra que o gate HTTP impede bursts já admitidos.
- A cobertura HTTP atual valida cinco falhas sequenciais, bloqueio e expiração; não há teste de integração concorrente no endpoint de login.

**Status:** observação adicional pendente de decisão/correção antes do merge. Não altera os três achados originais já validados. A descrição da PR #2 foi sincronizada para deixar explícita essa pendência. Nenhuma alteração foi feita na `main`; nenhum merge foi realizado.


## Correção da admissão concorrente no login — 2026-11-24

**Implementação preparada; CI do novo HEAD pendente.**

A observação adicional da revisão final foi tratada com serialização por chave no banco:

- LoginAttemptRateLimiter::acquireAttemptLock() adquire um named lock de MySQL/MariaDB derivado de SHA-256 dos hashes de e-mail e origem.
- O nome do lock tem 64 caracteres, sem e-mail, IP ou identificadores em texto puro.
- login.php mantém o lock durante a consulta de bloqueio, verificação da credencial e gravação da falha/auditoria ou limpeza após login válido.
- O lock é liberado em finally antes do redirecionamento de sucesso; falhas/exceções também passam pela liberação.
- A verificação de senha continua ocorrendo antes de contar a falha. Portanto, credenciais válidas não consomem uma das cinco falhas; tentativas bloqueadas não geram novos eventos de auditoria.
- O teste HTTP usa oito workers do servidor embutido e dispara 12 requisições com sessões independentes para a mesma combinação e-mail/IP. Espera exatamente cinco falhas persistidas, bloqueio ativo e cinco eventos login_falhou; todas as respostas permanecem genéricas.
- A suíte já existente mantém a validação sequencial, expiração, reinício da janela e limpeza após login válido.
- Compatibilidade esperada: named locks GET_LOCK/RELEASE_LOCK disponíveis no MySQL 8.0 e MariaDB 10.11; a CI dos dois bancos precisa confirmar a implementação.

Commits desta correção:
- 6a1c22e — adicionar locks nomeados ao rate limiter.
- e0922fb — serializar o ciclo HTTP do login.
- 97121b6 — liberar o lock antes do redirecionamento.
- afb6771 — adicionar teste HTTP concorrente com múltiplas sessões.

**Validação ainda não concluída:** aguardar os cinco jobs da CI no HEAD que inclui todos os commits acima. Se algum job falhar, corrigir antes de fechar o bloco. main permanece intacta; PR #2 continua sem merge.


## Validação final da admissão concorrente — 2026-11-24

**Correção validada em CI no commit c63c2d008416224d24c59bc79ec70906ca392c5e.**

- Run #45425206298 (push): sucesso, 5/5 jobs.
- Run #45425223992 (pull_request): sucesso, 5/5 jobs.
- Os cinco jobs passaram nos dois eventos: PHP quality and unit tests; Foundation database (MySQL 8.0); Foundation database (MariaDB 10.11); Foundation HTTP integration (MySQL 8.0); Apache webroot protection.
- A integração HTTP executou o teste de 12 requisições simultâneas com sessões independentes e confirmou contador igual a cinco, bloqueio ativo e exatamente cinco eventos de auditoria sem PII.
- A suíte sequencial, expiração, reinício da janela, limpeza após login válido e os fluxos existentes de autenticação/RBAC/CRUD também passaram.
- A primeira execução intermediária encontrou somente um erro de sintaxe no namespace do rate limiter; corrigido em c63c2d0. A CI final acima foi executada no commit corrigido.
- O protocolo usa named locks GET_LOCK/RELEASE_LOCK; compatibilidade confirmada pelos jobs de MySQL 8.0 e MariaDB 10.11.
- A observação adicional de admissão concorrente fica resolvida e validada. Os três achados originais também permanecem validados.
- PR #2 segue aberta e sem merge. main permanece em e1f4c8084dc6dec8c772a699855562944d50e89e. Nenhuma migration foi aplicada ao ambiente local do usuário.

**Próxima etapa:** revisão final do diff e do estado da PR; não mesclar sem autorização explícita.

## Checkpoint operacional consolidado — 2026-11-24

Este checkpoint supersede os estados operacionais antigos descritos nas seções históricas acima. As anotações anteriores permanecem como histórico da evolução da revisão; para a decisão atual, usar os valores desta seção e o checklist `docs/pre-merge-checklist-bloco-01-2026-11-24.md`.

### Estado atual do GitHub

- Repositório: `Alex5762/academia-alunos-crud`.
- PR #2: aberta, não draft, reportada como mergeable, não mesclada.
- Branch: `feat/foundation-auth-security`.
- HEAD no início deste checkpoint: `6fb629e4a57483eae1c66f423a0d873bae44cf06`.
- Base `main`: `e1f4c8084dc6dec8c772a699855562944d50e89e`.
- Comparação: 68 commits à frente, 0 atrás; 58 arquivos, 5.764 adições e 73 deleções.
- Revisões formais e threads inline: nenhuma registrada na consulta.
- A API de proteção de branch retornou 403 para a integração; a proteção atual precisa ser conferida na interface do GitHub.

### Última correção documental

O comentário em `config/database.local.example.php` foi corrigido para instruir a cópia para `config/database.local.php`. O arquivo local real é ignorado pelo Git. O runbook de pré-merge foi consolidado com:
- snapshot da PR e estado real de CI;
- portões obrigatórios antes do merge;
- verificação de backup e distinção entre instalação inicial e banco existente;
- ordem única das migrations 001, 002, 003, 007 e 008;
- marcador histórico antes da migration 008;
- inicialização e verificação da cadeia;
- provisionamento CLI do primeiro Administrador;
- smoke test e plano de recuperação.

### CI do checkpoint

- HEAD `6fb629e4a57483eae1c66f423a0d873bae44cf06`, run pull_request #45456925659: em andamento na última consulta. PHP quality e Apache webroot protection estavam verdes; os jobs de MySQL 8.0, MariaDB 10.11 e integração HTTP ainda estavam em andamento.
- O checklist foi atualizado depois desse HEAD. A nova CI precisa validar o HEAD documental resultante antes de fechar a preparação.

### Decisão

A preparação técnica e operacional está documentada, mas **não há autorização de merge registrada**. A PR #2 permanece aberta, a `main` permanece intacta e nenhuma migration foi executada no banco local do usuário.

Não mesclar até: CI verde no HEAD atual; regras de proteção da `main` conferidas; revisão formal obtida caso exigida; backup do banco de destino confirmado; autorização explícita do responsável.

## Resultado da CI e revisão operacional — 2026-11-24

Este registro atualiza o checkpoint anterior e passa a ser a referência atual para a decisão de integração.

### Snapshot confirmado

- PR #2: aberta, não mesclada, não draft.
- Branch: `feat/foundation-auth-security`.
- HEAD validado: `501f49cea8ecd405b0a22057c50ab482ec56fe62`.
- Base `main`: `e1f4c8084dc6dec8c772a699855562944d50e89e`.
- Comparação: 71 commits à frente, 0 atrás.
- Diff: 58 arquivos, 5.853 adições e 73 deleções.
- Estado reportado pelo GitHub: `mergeable=true`, `mergeable_state=clean`.
- Revisões formais, comentários de revisão e threads inline: nenhum registrado na consulta.

### CI final — 10/10

No mesmo HEAD `501f49cea8ecd405b0a22057c50ab482ec56fe62`:

- Pull request #45457276790: sucesso, 5/5 jobs.
- Push #45457261651: sucesso, 5/5 jobs.

Jobs aprovados nos dois eventos:
1. PHP quality and unit tests.
2. Foundation database (MySQL 8.0).
3. Foundation database (MariaDB 10.11).
4. Foundation HTTP integration (MySQL 8.0).
5. Apache webroot protection.

A CI confirma a implementação da fundação, incluindo rate limit concorrente HTTP com 12 requisições, auditoria sem PII e compatibilidade dos named locks nos dois bancos.

### Portões ainda pendentes

- A API de branch protection retornou 403 para a integração. Conferir manualmente na interface do GitHub se a `main` exige PR, quais checks e revisões são obrigatórios, e se bypass, force-push e exclusão estão bloqueados.
- Se houver exigência de aprovação independente, solicitar e obter a revisão de outro colaborador. Não há avaliação formal registrada até agora.
- Confirmar backup íntegro e verificável do banco de destino.
- Identificar o ambiente de destino e a janela de manutenção.
- Obter autorização explícita para executar o merge.

### Documentação operacional

O checklist `docs/pre-merge-checklist-bloco-01-2026-11-24.md` contém a sequência detalhada de backup, configuração, migrations 001/002/003/007/008, marcador histórico, inicialização da cadeia, provisionamento CLI, smoke tests e recuperação.

Nenhuma migration foi executada; nenhum dado foi alterado; nenhum merge foi realizado. A `main` permanece no SHA informado acima.

## Resultado final da CI e estado da PR — 2026-11-24

Este registro supersede os snapshots operacionais anteriores desta documentação.

- PR #2: aberta, não mesclada, não draft.
- HEAD validado: `501f49cea8ecd405b0a22057c50ab482ec56fe62`.
- Base `main`: `e1f4c8084dc6dec8c772a699855562944d50e89e`.
- Comparação: 71 commits à frente, 0 atrás; 58 arquivos, 5.853 adições e 73 deleções.
- Estado da PR na última consulta: `mergeable=true`, `mergeable_state=clean`.
- Run pull_request #45457276790: sucesso, 5/5 jobs.
- Run push #45457261651: sucesso, 5/5 jobs.
- Os dois eventos passaram em PHP quality, MySQL 8.0, MariaDB 10.11, integração HTTP MySQL 8.0 e proteção Apache.
- Não havia avaliações formais, comentários de revisão ou threads inline registrados.
- A API de proteção da `main` retornou 403 para a integração; as regras atuais precisam ser conferidas manualmente na interface do GitHub.
- O checklist operacional foi atualizado em `docs/pre-merge-checklist-bloco-01-2026-11-24.md`.

**Pendências fora da CI:** conferir proteção da branch e política de revisão; obter aprovação independente caso exigida; confirmar backup íntegro e ambiente de destino; definir janela de manutenção; receber autorização explícita para o merge.

**Não houve merge, alteração da `main`, migration ou alteração de dados.** A conclusão dos checks não substitui os portões operacionais nem a autorização do responsável.

## Checkpoint atualizado — HEAD 2bafa933 — 2026-11-24

Este registro atualiza os snapshots anteriores e é a referência mais recente desta documentação.

### Repositório e PR

- PR #2: aberta, não draft, não mesclada; GitHub reporta `mergeable=true`.
- Branch: `feat/foundation-auth-security`.
- HEAD validado: `2bafa933f237035e7d5fd105c5cb4715b024aaf2`.
- Base `main`: `e1f4c8084dc6dec8c772a699855562944d50e89e`.
- Comparação: 75 commits à frente, 0 atrás.
- Diff: 58 arquivos, 5.938 adições e 73 deleções.
- `main` está protegida (`protected: true`).
- Não há avaliações formais, comentários de revisão ou threads inline registradas.

### CI no HEAD validado — 10/10

- Pull request #45461133988: sucesso, 5/5 jobs.
- Push #45461117533: sucesso, 5/5 jobs.
- Jobs aprovados em ambos: PHP quality and unit tests; Foundation database (MySQL 8.0); Foundation database (MariaDB 10.11); Foundation HTTP integration (MySQL 8.0); Apache webroot protection.
- O job HTTP concluiu o provisionamento inicial, autenticação/RBAC/CRUD/auditoria e administração de contas com sucesso.
- Os jobs de banco confirmaram os testes concorrentes de rate limit, auditoria e administração em MySQL 8.0 e MariaDB 10.11.

### Proteção e pendências operacionais

A branch `main` está marcada como protegida, mas a API de detalhes da proteção retorna 403 para a integração. Conferir manualmente na interface do GitHub os checks obrigatórios, exigência de aprovação independente, bypass e restrições de force-push/exclusão.

Ainda faltam: confirmar backup íntegro do banco de destino; identificar ambiente e janela de manutenção; obter aprovação independente se a proteção exigir; autorização explícita para executar o merge.

O checklist `docs/pre-merge-checklist-bloco-01-2026-11-24.md` contém o runbook detalhado de backup, migrations, marcador histórico, inicialização da cadeia, provisionamento CLI, smoke test e recuperação.

**Estado:** validação técnica e CI concluídas neste HEAD; integração ainda não autorizada. Nenhuma migration ou alteração de dados foi executada. A `main` permanece no SHA acima.
