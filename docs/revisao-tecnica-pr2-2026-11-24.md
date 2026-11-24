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

**Severidade: média. Pendente.**

`login.php` mantém contadores em `login_tentativas`, usando hashes do e-mail normalizado e IP. A falha de autenticação não aparece na cadeia geral `auditoria`, embora a migration 001 permita eventos sem usuário associado. A política documentada no código é cinco falhas em janela de 15 minutos, com bloqueio de 15 minutos, mas não foi localizado teste HTTP que prove todos esses limites e a expiração.

**Ação proposta:** definir evento de auditoria sem expor e-mail/IP puros; adicionar testes para 1ª–5ª falha, bloqueio, tentativa durante bloqueio, expiração e limpeza após login válido. Tratar concorrência e o crescimento da tabela explicitamente.

## Achado 3 — revalidação da autorização na criação de contas

**Severidade: média. Pendente.**

`salvar-usuario.php` chama `exigirPermissao($usuario, 'users.manage')` antes de iniciar a transação. Diferentemente de alteração de perfil/status, a criação não revalida o administrador sob lock. Se o privilégio for revogado concorrentemente após a checagem, a requisição já iniciada pode criar uma conta.

**Ação proposta:** mover criação de conta para `UserAccountAdministrationService`, revalidando ator ativo, administrador e `deve_trocar_senha = 0` dentro da transação bloqueada; acrescentar teste concorrente de revogação contra criação.

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

A revisão consultou os arquivos e testes versionados no HEAD identificado. Não executou uma instância local de MySQL/Apache nesta sessão. A confirmação final das mudanças depende da CI executada no commit novo.

## Decisão atual

**Não aprovar merge ainda.** Fechar o achado 1 com CI verde e tratar os achados 2 e 3 antes de considerar a fundação pronta para integração.
