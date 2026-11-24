# Revisão técnica da PR #2 — 2026-11-24

## Contexto

PR #2 — `feat: integrar fundação de autenticação e segurança`, branch `feat/foundation-auth-security`, base `main`.

A revisão é estática sobre o HEAD `4e9d3b8074e4d665967d4382d130f6e6d0e4469e`. A CI estava verde nesse checkpoint, mas isso não elimina riscos não cobertos pelos testes existentes. A PR permanece aberta e não mesclada.

## Achado 1 — sessões paralelas após troca de senha

**Severidade: alta. Resolvido e validado em CI.**

Os fluxos `trocar-senha.php` e `alterar-senha.php` atualizavam `senha_hash` sem alterar `sessao_versao`. Sessões independentes com cookies já emitidos mantinham a versão persistida e continuavam autenticadas.

**Correção implementada no commit `0b201b2`:** incrementar `sessao_versao` transacionalmente em ambos os fluxos, conferir a versão com `FOR UPDATE`, preservar a sessão que realizou a troca e revogar as outras. A cobertura HTTP abre cookies paralelos e testa `renovar-sessao.php` após a troca.

**Validação concluída no HEAD `6282bb03b8dcb5d3f1b0af8f4f71fb1595de9373`:** runs #45399787132 (push) e #45399800406 (pull_request) com 5/5 jobs aprovados em cada. A primeira CI revelou que a bateria HTTP de administração usava a senha inicial após a bateria anterior alterá-la; o fixture foi corrigido para acompanhar a senha compartilhada.

## Achado 2 — auditoria de falhas de login e rate limit

**Severidade: média. Resolvido e validado em CI.**

O rate limit foi extraído para `LoginAttemptRateLimiter`. A política é de cinco falhas em uma janela de 15 minutos, com bloqueio por 15 minutos. O contador fica limitado a cinco mesmo sob concorrência; a atualização do contador e o evento de auditoria são confirmados ou revertidos juntos.

Cada falha de credenciais que passa pelo bloqueio gera `login_falhou` na cadeia append-only, com `usuario_id = NULL`, `entidade = 'autenticacao'` e `entidade_id = NULL`. Nenhum e-mail, IP ou hash de identificação entra no evento. Tentativas já bloqueadas não incrementam o contador nem geram eventos repetidos; um login válido limpa a combinação. Registros sem atividade por mais de um dia são removidos da tabela de tentativas.

**Cobertura adicionada:** integração HTTP para falha inicial, sequência de 1 a 5 tentativas, bloqueio, tentativa durante bloqueio, expiração/reinício da janela e limpeza após login válido. Teste de banco com quatro processos concorrentes (20 falhas) verifica contador limitado, bloqueio, auditoria sem PII e integridade da cadeia.

## Achado 3 — revalidação da autorização na criação de contas

**Severidade: média. Resolvido e validado em CI.**

`salvar-usuario.php` mantém a checagem antecipada para rejeição rápida, mas a criação efetiva agora passa por `UserAccountAdministrationService::createAccount()`. O serviço usa a mesma transação com `SELECT ... FOR UPDATE` de todas as contas, revalida ator ativo, perfil com `users.manage` e `deve_trocar_senha = 0`, cria a conta e grava `usuario_criado` na mesma transação. Assim, se a autorização for revogada antes de a operação obter o lock, a criação retorna `UNAUTHORIZED` sem inserir a conta.

**Cobertura adicionada:** o teste de administração de contas inicia uma criação após pré-autorização, mantém a operação aguardando o lock global, revoga o perfil do ator em outra transação e comprova que, ao prosseguir, o serviço nega a criação e não deixa conta persistida. O caminho autorizado e a auditoria da criação também são verificados.

## Pontos positivos observados

- Matriz RBAC centralizada e negativa por padrão para perfis desconhecidos.
- Validação da versão persistida da sessão em requisições autenticadas.
- Revogação de sessão por desativação/reativação coberta com segundo cookie independente.
- Administração de perfil/status serializada sob lock e com proteção do último administrador.
- Escritas da cadeia de auditoria participam da mesma transação do evento.
- Testes de rollback, adulteração e concorrência da cadeia.
- Configuração PDO separa variáveis de produção e arquivo local.
- Proteção Apache verificada em servidor real com teste de caminhos e metadados.
- CI incremental executa MySQL 8.0, MariaDB 10.11, integração HTTP e webroot.

## Limites da revisão

A revisão consultou os arquivos e testes versionados no HEAD identificado. A validação de execução foi realizada pela CI remota; não foi executada uma instância local de MySQL/Apache nesta sessão.

## Decisão atual

**Revisão técnica dos três achados concluída.** Os achados 1, 2 e 3 estão resolvidos e validados em CI. A PR permanece aberta e não mesclada para revisão final e decisão explícita do usuário.


## Validação do Achado 2

- Validação final no commit `d52fe3bb0937b2cbaf26313dbfcfd03f31bdd3f8`.
- Run #45413951571 (push) e run #45413968757 (pull_request): ambos concluídos com sucesso, 5/5 jobs aprovados.
- O workflow executa uma única vez o teste concorrente por job; a contagem de auditoria compara o delta da própria execução, sem depender de eventos anteriores.
- O teste concorrente executa quatro processos com cinco falhas cada; verifica contador limitado a cinco, bloqueio ativo e 20 eventos auditados sem PII.
- A integração HTTP cobre falhas 1–5, bloqueio, tentativa durante bloqueio, expiração/reinício da janela e limpeza após login válido.
- A expiração é simulada ajustando os timestamps no banco; não exige espera real de 15 minutos.
- A limpeza automática remove registros de rate limit sem atividade por mais de um dia. A cadeia geral de auditoria permanece append-only e não é apagada automaticamente.


## Validação do Achado 3

- Implementação commitada na branch `feat/foundation-auth-security`.
- `salvar-usuario.php` delega a criação ao `UserAccountAdministrationService`; a autorização é revalidada dentro do lock transacional.
- O teste concorrente de administração cobre pré-autorização, revogação de perfil antes da obtenção do lock, negação da criação, ausência de conta persistida, caminho autorizado e evento de auditoria.
- Run #45417710794 (push) e run #45417736963 (pull_request), no commit `c46373aa2abf20726ff113f70f8d8780defc0cd8`: ambos concluídos com sucesso, 5/5 jobs aprovados em cada.
- O teste de administração de contas foi executado nos bancos MySQL 8.0 e MariaDB 10.11; o fluxo HTTP de criação existente também passou.

## Revisão complementar do diff completo — 2026-11-24

**Observação adicional antes do merge — admissão concorrente no rate limit.**

A implementação atual consulta `LoginAttemptRateLimiter::isBlocked()` em `login.php` antes de verificar a senha. Se a combinação ainda não estiver bloqueada, a requisição segue; somente depois de uma credencial inválida ela chama `recordFailure()`, que incrementa o contador e grava `login_falhou` atomicamente.

A operação de incremento é serializada pelo UPSERT e o teste de banco comprova que 20 falhas concorrentes deixam o contador limitado a cinco e registram 20 eventos. Entretanto, esse teste não cobre a admissão HTTP: várias requisições podem consultar o estado desbloqueado antes de qualquer uma gravar o bloqueio. Portanto, o contador final correto não prova que tentativas simultâneas foram impedidas de prosseguir.

**Cobertura existente:** a integração HTTP cobre cinco tentativas sequenciais, bloqueio subsequente, expiração/reinício e limpeza após login válido. Não foi localizado teste HTTP concorrente que sincronize múltiplas requisições antes da consulta de bloqueio.

**Recomendação:** tratar a admissão/consumo de tentativa como operação atômica no início do fluxo de login e adicionar teste HTTP concorrente que prove que o limite é aplicado na entrada, sem expor e-mail/IP na auditoria. Preservar resposta genérica para evitar enumeração de contas. A estratégia deve distinguir tentativas já bloqueadas das falhas efetivamente verificadas e manter a política de janela/expiração documentada.

**Decisão:** pendente antes do merge. Os Achados 1, 2 e 3 da revisão original permanecem resolvidos e validados nas execuções registradas acima; esta é uma observação nova da revisão final, não uma reversão desses resultados.
