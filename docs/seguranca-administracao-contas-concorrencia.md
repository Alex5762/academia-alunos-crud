# Administração de contas — proteção do último administrador e concorrência

**Data:** 24/11/2026  
**Branch:** `feat/dashboard-portfolio`  
**Commit de referência:** `8ab8315125b790fecffa962b1a05b23be5a14615`  
**PR:** #1 — aberta em Draft, sem merge

## Objetivo

Garantir que operações concorrentes de alteração de perfil e status não removam todos os administradores ativos nem permitam que uma requisição continue usando permissões que foram revogadas enquanto ela aguardava um bloqueio no banco.

## Diagnóstico anterior

- `alterar-perfil-usuario.php` já protegia a remoção do perfil do último administrador ativo.
- `alterar-status-usuario.php` contava todas as contas ativas, em vez de contar administradores ativos.
- Os endpoints validavam a permissão antes da transação. Duas requisições podiam passar pela autorização inicial antes de qualquer alteração concorrente.
- Era necessário revalidar o ator com estado atual do banco depois de adquirir os locks.

A revisão anterior não demonstrou uma exploração sequencial direta. O foco deste ajuste é consistência transacional e defesa em profundidade.

## Solução implementada

### Serviço de domínio compartilhado

Novo arquivo: `src/Domain/Users/UserAccountAdministrationService.php`.

As operações `changeProfile()` e `changeStatus()` usam a mesma estratégia:

1. Validar os argumentos recebidos pelo serviço.
2. Abrir uma transação exclusiva.
3. Bloquear as contas em ordem crescente de ID com `SELECT id, ativo, perfil, deve_trocar_senha FROM usuarios ORDER BY id FOR UPDATE`.
4. Construir o estado atual das contas a partir da leitura bloqueante.
5. Revalidar o ator dentro da transação: conta existente e ativa, perfil com permissão `users.manage` e troca obrigatória de senha concluída.
6. Validar alvo, autodesativação/autorrebaixamento e invariantes de administrador.
7. Atualizar o estado e, quando aplicável, registrar histórico especializado e evento de auditoria.
8. Confirmar tudo em um único COMMIT; qualquer exceção provoca ROLLBACK.

O lock do conjunto de contas é deliberadamente conservador: operações administrativas são pouco frequentes e precisam serializar decisões sobre o conjunto global de administradores. A ordem estável reduz disputas entre duas operações do mesmo domínio. Se o volume de contas crescer significativamente, a estratégia poderá ser revisada com uma linha de coordenação dedicada e testes equivalentes.

### Proteção e autorização

- A contagem de administradores ativos não usa a quantidade total de contas ativas.
- Uma conta desativada não conta como administrador ativo.
- O endpoint de status não pode desativar a própria conta.
- O endpoint de perfil não pode alterar o próprio perfil.
- O serviço revalida o ator depois do lock; uma sessão cuja conta foi desativada ou cujo perfil perdeu `users.manage` recebe `UNAUTHORIZED`, mesmo que a requisição tenha passado pela autorização HTTP inicial.
- Mudanças de perfil gravam `usuarios_perfis_eventos` e `auditoria` dentro da mesma transação.
- Mudanças de status gravam `auditoria` dentro da mesma transação.
- Os endpoints continuam exigindo POST, autenticação, autorização inicial e token CSRF.

### Arquivos alterados

- `src/Domain/Users/UserAccountAdministrationService.php` — novo serviço transacional.
- `alterar-perfil-usuario.php` — delega mudança de perfil ao serviço e traduz resultados para respostas HTTP/mensagens.
- `alterar-status-usuario.php` — delega mudança de status ao serviço e traduz resultados.
- `tests/database-user-account-administration.php` — teste de banco com processos concorrentes independentes.
- `tests/integration-crud.sh` — cobre CSRF, autodesativação, desativação, reativação e auditoria pela interface HTTP.
- `.github/workflows/ci.yml` — inclui o teste de administração concorrente nos jobs de compatibilidade do banco.

## Estratégia de testes

### Concorrência real no banco

O teste cria duas contas administradoras A/B e uma conta Operador em banco efêmero. Dois processos PHP independentes, cada um com conexão PDO própria, iniciam juntos:

- Processo 1: B tenta rebaixar A para Gestor.
- Processo 2: A tenta desativar B.

Resultados aceitos:

| Operação de perfil | Operação de status | Estado final esperado |
|---|---|---|
| Atualizada | Ator sem autorização | B continua administrador ativo; A vira Gestor ativo |
| Ator sem autorização | Desativada | A continua administrador ativo; B fica inativo |

O teste exige exatamente uma operação vencedora, uma operação negada por perda de autorização e exatamente um administrador ativo entre A/B. Em seguida, tenta reutilizar o ator que perdeu privilégios e confirma que a conta Operador de destino não foi alterada.

Também verifica autodesativação, autorrebaixamento, negação para conta sem `users.manage`, alteração de perfil, desativação, reativação e integridade final da cadeia de auditoria.

**Isolamento:** o script exige `GITHUB_ACTIONS=true`, pois eventos da trilha de auditoria são append-only e referenciam os usuários de teste. As fixtures permanecem somente nos bancos efêmeros da CI; não executar o script em banco local ou com dados reais.

### Integração HTTP

O script `tests/integration-crud.sh` valida:

- POST sem token CSRF em `alterar-status-usuario.php` retorna HTTP 403.
- Tentativa de autodesativação preserva a conta administrativa ativa.
- Desativação de outra conta persiste `ativo = 0`.
- Reativação persiste `ativo = 1`.
- Cada transição gera exatamente um evento de auditoria associado ao administrador autenticado.
- Os testes já existentes de alteração de perfil continuam verificando alteração, histórico especializado, auditoria e bloqueio de autorrebaixamento.

## Evidências CI

[CI #1716 — run 45340419014](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45340419014)

HEAD: `8ab8315125b790fecffa962b1a05b23be5a14615`

- Database compatibility — MySQL 8.0: sucesso.
- Database compatibility — MariaDB 10.11: sucesso.
- Teste de administração concorrente em MySQL 8.0: sucesso.
- Teste de administração concorrente em MariaDB 10.11: sucesso.
- Verificação de sintaxe PHP: sucesso.
- Integração HTTP/CRUD e captura visual: sucesso.
- Demais jobs funcionais da CI: sucesso.
- Publicação de screenshots: ignorada pela condição do workflow; não é falha funcional.

## Limites e decisões

- O teste de concorrência exercita o serviço transacional com processos e conexões independentes; não é um teste de carga nem um pentest.
- A integração HTTP valida CSRF e transições de status sequenciais; a corrida entre dois pedidos é testada diretamente no serviço/banco.
- O lock de todas as contas pode reduzir concorrência durante operações administrativas, mas é adequado ao baixo volume esperado de gestão de funcionários. Reavaliar somente se houver evidência de escala que justifique maior complexidade.
- Nenhuma migration ou mudança de schema foi necessária.
- Não houve alteração da `main`, merge, rebase ou criação de branch nova.
- A PR #1 continua aberta em Draft.
- A validação manual no XAMPP e em dispositivo físico permanece adiada para a etapa final.

## Resultado

**Bloco técnico concluído e CI aprovada.** A regra de autorização é revalidada dentro da transação, as alterações concorrentes de perfil/status são serializadas e o cenário adversarial testado preserva exatamente um administrador ativo.
