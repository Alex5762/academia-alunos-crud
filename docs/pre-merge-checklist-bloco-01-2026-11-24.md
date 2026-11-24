# Preparação de integração — Bloco 01

Data: 2026-11-24  
Repositório: `Alex5762/academia-alunos-crud`  
PR: [#2 — integrar fundação de autenticação e segurança](https://github.com/Alex5762/academia-alunos-crud/pull/2)

## 1. Snapshot do GitHub

Checkpoint de revisão final — 2026-11-24:

- Base: `main`, SHA `e1f4c8084dc6dec8c772a699855562944d50e89e`.
- Branch: `feat/foundation-auth-security`, HEAD antes desta atualização final do checklist: `8e89c9c1051b365253589ae378a008a8d5c9ba1a`.
- Comparação registrada na PR: 66 commits à frente, 0 atrás.
- Diff: 58 arquivos, 5.763 adições e 73 deleções.
- PR #2: aberta, pronta para revisão (não draft), reportada como mergeable, ainda não mesclada.
- Revisões formais e threads inline: nenhuma registrada na consulta mais recente.

`mergeable: true` é apenas uma informação de conflito/merge do GitHub. Não substitui CI verde, revisão do conteúdo, requisitos de proteção ou autorização explícita.

## 2. Validação técnica concluída

- Autenticação, sessões protegidas, CSRF, expiração e revogação por versão da conta.
- Troca de senha temporária e voluntária, preservando a sessão que concluiu a troca e revogando sessões paralelas.
- RBAC dos perfis Administrador, Gestor e Operador.
- Administração concorrente de contas com revalidação de autorização dentro do lock transacional e proteção do último Administrador.
- Auditoria append-only com cadeia SHA-256, inicialização por CLI e testes de rollback/integridade.
- Rate limit persistente: cinco falhas em 15 minutos, bloqueio por 15 minutos, expiração e limpeza.
- Admissão concorrente do login serializada com `GET_LOCK`/`RELEASE_LOCK`; a senha é verificada antes de contar a falha e login válido não consome tentativa.
- Teste HTTP com 12 requisições simultâneas e sessões independentes: exatamente cinco falhas persistidas/auditadas, bloqueio ativo e respostas genéricas.
- Testes de banco em MySQL 8.0 e MariaDB 10.11.
- Proteção Apache do webroot.

Evidências na correção do rate limit (`c63c2d008416224d24c59bc79ec70906ca392c5e`):

- Run #45425206298 (push): 5/5 jobs aprovados.
- Run #45425223992 (pull_request): 5/5 jobs aprovados.

Evidências da documentação de estado/revisão (`37dda52f2893545c31dec842076757995a05bced`):

- Run #45425534390 (push): 5/5 jobs aprovados.
- Run #45425546689 (pull_request): 5/5 jobs aprovados.

Os cinco jobs nas duas rodadas documentais passaram: qualidade PHP, MySQL 8.0, MariaDB 10.11, integração HTTP MySQL 8.0 e proteção Apache.

## 3. Portões obrigatórios antes de integrar

- [ ] A branch continua baseada na mesma `main` e não ficou atrás dela.
- [ ] O HEAD atual da PR foi conferido, sem commits inesperados após o checkpoint revisado.
- [x] Os cinco jobs obrigatórios passaram no evento `pull_request` no HEAD documental `8e89c9c1051b365253589ae378a008a8d5c9ba1a` (execução #45432286395, 5/5). Este é o evento que valida os checks da PR.
- [ ] O evento `push` do mesmo HEAD (#45432279529) permanece pendente/na fila por uma execução anterior ainda ativa. É uma verificação suplementar; acompanhar, mas não confundir com o resultado verde do evento `pull_request`.
- [x] Não há conversas de revisão, solicitações de mudança ou comentários pendentes; a PR não possui avaliações formais registradas. Isso não equivale a uma aprovação formal independente.
- [ ] As regras atuais de proteção da `main` foram conferidas na interface do GitHub. O endpoint de proteção retornou 403 para a integração nesta sessão; o último checkpoint documentado registrava PR obrigatória, cinco checks exigidos, branch atualizada e bloqueio de bypass/force-push/exclusão.
- [ ] O banco de destino foi identificado e seu backup foi confirmado antes de qualquer migration.
- [ ] O responsável confirmou explicitamente a integração.

**Não executar o merge enquanto qualquer portão acima estiver pendente.** A autorização do usuário deve ser uma etapa explícita e separada da preparação técnica.

## 4. Estratégia de merge recomendada

Preferir **merge commit** para preservar a sequência dos commits de implementação e documentação deste bloco. Não usar squash/rebase automaticamente: isso apagaria ou reescreveria o histórico incremental que foi usado para revisar os achados por etapa.

No momento da execução, conferir novamente o HEAD e usar proteção contra movimento da branch (expected head SHA). Se a branch ou a base mudar, interromper e repetir a revisão/CI no novo estado.

## 5. Sequência operacional depois do merge

Nenhuma migration foi executada no ambiente local do usuário durante esta preparação. A integração do código e a atualização do banco são operações separadas.

1. Confirmar backup íntegro do banco `academia_alunos` e registrar o ponto de restauração.
2. Configurar `config/database.local.php` a partir do exemplo, ou as variáveis de ambiente exigidas pelo ambiente. Nunca versionar credenciais reais.
3. Para uma instalação inicial, importar `banco.sql` (ele cria o banco/tabela de alunos com `IF NOT EXISTS`; não substitui a conferência do schema existente).
4. Aplicar uma única vez, nesta ordem: `001_authentication_audit.sql`, `002_force_password_change.sql`, `003_login_rate_limit.sql`, `007_administrative_roles.sql`.
5. Antes da migration 008, inserir o marcador histórico indicado no README:
   `INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id) VALUES (NULL, 'evento_legado_baseline', 'sistema', NULL);`
6. Aplicar uma única vez `008_audit_integrity_chain.sql`.
7. Executar `php scripts/inicializar-integridade-auditoria.php` e confirmar que a cadeia está íntegra.
8. Executar `php scripts/provisionar-usuario.php` pelo terminal, copiar a senha temporária e entregá-la por canal seguro.
9. Fazer smoke test: login do Administrador, troca obrigatória da senha, acesso ao CRUD, permissões e consulta de integridade/auditoria.

**Atenção às migrations:** 002 e 007 alteram a tabela com `ALTER TABLE`; 008 cria tabelas e triggers sem guardas de repetição. Não reaplicar migrations por tentativa. Em caso de falha parcial, inspecionar o schema e comparar com o backup antes de decidir como retomar.

## 6. Plano de recuperação

- Antes das migrations, o backup do banco é o ponto de retorno.
- Não presumir que exista rollback SQL automático; esta etapa não fornece migrations reversas.
- Se a aplicação falhar após habilitar autenticação, não voltar cegamente ao código antigo da `main`: ele antecede a proteção de login e o CRUD protegido. Manter o site em manutenção/bloqueado enquanto se recupera uma versão compatível.
- Restaurar o backup do banco somente após avaliar perda de dados escritos depois do backup e com decisão explícita.
- Não remover as tabelas/triggers de auditoria como atalho de rollback.

## 7. Revisão de navegação e senha

A revisão final encontrou uma referência quebrada para `configuracoes.php`, página que não existe nesta branch. O link foi substituído por retorno ao painel; o painel agora oferece acesso explícito a `alterar-senha.php`; a integração HTTP verifica o link, a abertura da tela e o retorno ao painel. O README também documenta a funcionalidade.

Commits da correção:
- `ac62a82eaab5b2a2abebf0f9fa091d894d1dcce2` — link de alteração de senha no painel.
- `c7cbe44c731354ec1272538dc4d29f62755a36fe` — retorno da tela de senha.
- `f288d2707f2bfbe08ff60f5035907a4a570e186e` — README.
- `2e600860dadc0d335c5b1608945f11a2a8440163` — teste HTTP.

A CI da revisão de navegação no HEAD `2e600860dadc0d335c5b1608945f11a2a8440163` passou no evento `pull_request` (#45432104997), 5/5 jobs. A CI do HEAD documental `8e89c9c1051b365253589ae378a008a8d5c9ba1a` também passou no evento `pull_request` (#45432286395), 5/5 jobs. O evento `push` desse último HEAD (#45432279529) permanece pendente; uma execução push anterior (#45432063265) não terminou normalmente.

## 9. Estado da decisão

## 8. Estado da decisão

**Preparação técnica concluída; merge não executado.**

A PR #2 permanece aberta. A `main` permanece no SHA de base acima. Nenhuma migration, alteração de dados ou operação de merge foi executada nesta preparação.
