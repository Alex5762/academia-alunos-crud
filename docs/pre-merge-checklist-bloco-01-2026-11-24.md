# Preparação de integração — Bloco 01

Data: 2026-11-24  
Repositório: `Alex5762/academia-alunos-crud`  
PR: [#2 — integrar fundação de autenticação e segurança](https://github.com/Alex5762/academia-alunos-crud/pull/2)

## 1. Snapshot atual

- Base: `main`, SHA `e1f4c8084dc6dec8c772a699855562944d50e89e`.
- Branch: `feat/foundation-auth-security`.
- HEAD do último checkpoint técnico validado: `a4564ffb272f916144d75fe1c149c60f1625bc68`.
- Comparação: 77 commits à frente, 0 atrás.
- Diff: 58 arquivos, 5.984 adições e 73 deleções.
- PR #2: aberta, não draft, reportada como mergeable, não mesclada.
- Revisões formais e threads inline: nenhuma registrada na última consulta.
- `main` permanece no SHA acima; nenhuma migration ou alteração de banco foi executada nesta preparação.

`mergeable: true` informa apenas que o GitHub não detectou conflito de merge naquele snapshot. Não significa que todos os requisitos de integração estejam cumpridos.

## 2. Validação técnica

Os seguintes itens foram implementados e cobertos pelos testes da branch:

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
- Navegação da alteração voluntária de senha corrigida e coberta por integração HTTP.
- Exemplo de configuração local corrigido para orientar a cópia para `config/database.local.php`.

## 3. CI — estado real por HEAD

HEAD validado: `501f49cea8ecd405b0a22057c50ab482ec56fe62`.

- Run pull_request #45457276790: sucesso, 5/5 jobs.
- Run push #45457261651: sucesso, 5/5 jobs.
- Jobs aprovados nos dois eventos: PHP quality and unit tests; Foundation database (MySQL 8.0); Foundation database (MariaDB 10.11); Foundation HTTP integration (MySQL 8.0); Apache webroot protection.
- Resultado: 10/10 checks aprovados no mesmo HEAD, incluindo o teste HTTP concorrente de login e a matriz MySQL/MariaDB.

Links:
- [CI pull_request aprovada](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45457276790)
- [CI push aprovada](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45457261651)

## 4. Portões antes do merge

- [x] Base e branch comparadas: 75 à frente, 0 atrás.
- [x] HEAD do checkpoint técnico conferido: `a4564ffb272f916144d75fe1c149c60f1625bc68`.
- [x] Cinco jobs obrigatórios aprovados no evento `pull_request` (#45457276790), 5/5.
- [x] Cinco jobs complementares aprovados no evento `push` (#45457261651), 5/5.
- [x] A API confirma `main` com `protected: true`. [Detalhes da proteção](https://api.github.com/repos/Alex5762/academia-alunos-crud/branches/main/protection) retornam 403 para a integração; conferir manualmente branch protection/rulesets, checks obrigatórios, política de revisão e restrições de bypass/force-push/exclusão.
- [ ] Confirmar se a proteção exige revisão independente e, se exigir, obter aprovação de outro colaborador. Não há avaliações formais registradas; não há reviewer solicitado.
- [ ] Confirmar backup íntegro do banco de destino e registrar onde está guardado.
- [ ] Confirmar o ambiente de destino (local/XAMPP ou outro) e janela de manutenção.
- [ ] Autorização explícita do responsável para executar o merge.

**Não executar o merge enquanto qualquer portão acima estiver pendente.** Preparação técnica não equivale a autorização de merge.

## 5. Estratégia de integração no GitHub

- Método recomendado: merge commit, para preservar o histórico incremental usado na revisão por blocos.
- Antes de mesclar, consultar novamente PR, base, HEAD e CI.
- Usar o HEAD esperado `a4564ffb272f916144d75fe1c149c60f1625bc68` como referência deste checkpoint. Se a branch avançar, repetir revisão e CI no novo HEAD.
- Não usar squash ou rebase automaticamente.
- Depois do merge, confirmar o SHA resultante e que a branch `main` recebeu exatamente a PR #2.

## 6. Runbook do banco — executar somente depois da integração e do backup

A atualização do código e a atualização do banco são operações separadas. Nenhuma migration foi executada nesta preparação.

### 6.1 Preparação e backup

1. Identificar o banco correto e confirmar que não é produção nem um banco compartilhado sem autorização.
2. Colocar a aplicação em manutenção ou impedir gravações durante a janela de backup/migrations.
3. Fazer exportação completa de `academia_alunos`, incluindo estrutura, dados, triggers, rotinas e eventos existentes quando aplicável.
4. Guardar o backup fora da pasta pública do projeto e verificar que o arquivo não está vazio e pode ser lido/importado em ambiente de teste.
5. Registrar data, tamanho, local seguro do backup e responsável pela restauração.
6. Não prosseguir se não houver backup verificável.

### 6.2 Configuração da aplicação

- Copiar `config/database.local.example.php` para `config/database.local.php`.
- Editar host, porta, banco, usuário e senha conforme o ambiente.
- O arquivo `config/database.local.php` está no `.gitignore`; nunca adicionar credenciais reais ao Git.
- Em ambiente de produção, definir explicitamente `APP_ENV=production`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD`.
- Validar a conexão com o banco correto antes de executar qualquer SQL.

### 6.3 Instalação inicial versus banco existente

- Instalação inicial vazia: importar `banco.sql` e conferir o schema criado.
- Banco que já contém dados: não importar cegamente `banco.sql`; inspecionar o schema e fazer backup primeiro. O script não substitui a análise do banco existente.
- Não executar a sequência de migrations em banco que já tenha parte delas aplicada. Inspecionar tabelas, colunas, índices e triggers antes de decidir como continuar.

### 6.4 Ordem das migrations

Executar cada arquivo uma única vez, nesta ordem:

1. `database/migrations/001_authentication_audit.sql`
2. `database/migrations/002_force_password_change.sql`
3. `database/migrations/003_login_rate_limit.sql`
4. `database/migrations/007_administrative_roles.sql`

Antes da migration 008, inserir o marcador histórico, conforme o README:

```sql
INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id)
VALUES (NULL, 'evento_legado_baseline', 'sistema', NULL);
```

Depois:

5. Aplicar `database/migrations/008_audit_integrity_chain.sql`.
6. Executar no terminal, a partir da raiz do projeto: `php scripts/inicializar-integridade-auditoria.php`.
7. Confirmar a mensagem de integridade e o marco histórico antes de liberar a aplicação.

**Atenção:** as migrations 002 e 007 usam `ALTER TABLE`; a 008 cria tabelas e triggers sem guardas de repetição. Não reaplicar uma migration por tentativa. Se uma etapa falhar parcialmente, parar, inspecionar o schema e comparar com o backup antes de retomar. Não improvisar rollback SQL.

### 6.5 Provisionamento e smoke test

1. Executar `php scripts/provisionar-usuario.php` pelo terminal, nunca pelo navegador.
2. Criar o primeiro Administrador e entregar a senha temporária por canal seguro.
3. Entrar com a senha temporária e concluir a troca obrigatória.
4. Validar painel, CRUD de alunos, permissões dos perfis e logout.
5. Validar a alteração voluntária de senha e revogação de sessões paralelas.
6. Executar a verificação da cadeia de auditoria e conferir eventos de provisionamento/login.
7. Só retirar a manutenção após todos os testes passarem.

## 7. Plano de recuperação

- O backup íntegro anterior às migrations é o ponto de retorno.
- Esta etapa não fornece migrations reversas; não presumir rollback automático.
- Se a aplicação falhar depois de ativar autenticação, não voltar cegamente ao código antigo da `main`, pois ele antecede a proteção de login e do CRUD.
- Manter a aplicação em manutenção/bloqueada enquanto se recupera uma versão compatível.
- Restaurar o backup somente após avaliar os dados escritos depois do backup e obter decisão explícita.
- Não remover tabelas ou triggers de auditoria como atalho de rollback.

## 8. Histórico da revisão final

- Os três achados originais foram resolvidos e validados: revogação de sessões após troca de senha; auditoria/rate limit; revalidação concorrente da autorização administrativa.
- A observação adicional de admissão concorrente no login também foi resolvida com named locks e teste HTTP de 12 requisições.
- A revisão de navegação corrigiu o link inexistente para `configuracoes.php`, adicionou o acesso à alteração de senha no painel e ampliou a integração HTTP.
- O exemplo de configuração local agora indica corretamente o destino `config/database.local.php`.
- CI aprovada no HEAD `caa7f969151b0519e1683f68261315633cfdee35`, run #45432715149, 5/5 jobs. As atualizações documentais posteriores estão sendo validadas por CI; a última execução conhecida antes desta atualização é #45457124106.

## 9. Estado da decisão

**Preparação operacional documentada; merge ainda não autorizado nem executado.**

A PR #2 continua aberta. A `main` continua no SHA `e1f4c8084dc6dec8c772a699855562944d50e89e`. Nenhuma migration, alteração de dados ou operação de merge foi realizada nesta preparação.


## 10. Confirmação CI — 2026-11-24

No HEAD `501f49cea8ecd405b0a22057c50ab482ec56fe62`, ambos os eventos finalizaram com sucesso:

- Pull request #45457276790 — 5/5 jobs.
- Push #45457261651 — 5/5 jobs.

O estado consultado da PR #2 foi `open`, `merged=false`, `mergeable=true`, `mergeable_state=clean`, com base `e1f4c8084dc6dec8c772a699855562944d50e89e`. A branch está 71 commits à frente e 0 atrás. Não havia avaliações formais, comentários de revisão ou threads inline registrados.

A proteção atual da `main` não pôde ser confirmada pela API nesta sessão (403). Backup do banco, ambiente de destino, aprovação humana caso exigida e autorização explícita continuam pendentes. Nenhum merge ou migration foi executado.


## 10. Confirmação da CI final — 2026-11-24

No HEAD `501f49cea8ecd405b0a22057c50ab482ec56fe62`, ambos os eventos terminaram com sucesso:

- Pull request #45457276790 — 5/5 jobs.
- Push #45457261651 — 5/5 jobs.

A PR #2 foi consultada novamente: `open`, `merged=false`, `mergeable=true`, `mergeable_state=clean`, base `e1f4c8084dc6dec8c772a699855562944d50e89e`. Comparação: 71 commits à frente, 0 atrás. Não havia avaliações formais, comentários de revisão ou threads inline.

A API de proteção da `main` retornou 403 para a integração; confirmar as regras manualmente. Backup do banco, ambiente de destino, revisão independente caso exigida e autorização explícita continuam pendentes. Nenhum merge ou migration foi executado.


## 11. Confirmação anterior — HEAD 2bafa933 (histórico)

- PR #2 aberta, não draft, não mesclada; GitHub reporta `mergeable=true`.
- `main` está protegida (`protected: true`) e permanece em `e1f4c8084dc6dec8c772a699855562944d50e89e`.
- Branch da PR: 75 commits à frente, 0 atrás.
- CI pull_request #45461133988: sucesso, 5/5 jobs.
- CI push #45461117533: sucesso, 5/5 jobs.
- Nenhuma avaliação formal, comentário de revisão ou thread inline registrada.
- A política detalhada de proteção não pôde ser lida via API (403); conferir na interface do GitHub.
- Backup do banco, ambiente de destino, janela de manutenção, aprovação independente caso exigida e autorização explícita continuam pendentes.
- Nenhuma migration, alteração de dados ou merge foi executado.


## 12. Último checkpoint validado — HEAD a4564ffb — 2026-11-24

- PR #2 aberta, não draft, não mesclada; `mergeable=true`.
- HEAD: `a4564ffb272f916144d75fe1c149c60f1625bc68`; base `main`: `e1f4c8084dc6dec8c772a699855562944d50e89e`.
- Comparação: 77 commits à frente, 0 atrás; 58 arquivos, 5.984 adições e 73 deleções.
- `main` está protegida (`protected: true`); detalhes das regras retornam 403 para a integração e precisam ser conferidos manualmente.
- CI pull_request #45461486756: sucesso, 5/5 jobs.
- CI push #45461470680: sucesso, 5/5 jobs.
- Os dois eventos passaram PHP quality, MySQL 8.0, MariaDB 10.11, integração HTTP MySQL 8.0 e proteção Apache.
- Nenhuma avaliação formal, comentário de revisão ou thread inline registrada.
- Backup, ambiente de destino, janela de manutenção, aprovação independente caso exigida e autorização explícita continuam pendentes.
- Nenhuma migration, alteração de dados ou merge foi executado.


## 13. Registro pós-merge — 2026-11-24

Esta seção atualiza a decisão de integração. As seções anteriores são snapshots históricos da preparação e não representam mais o estado atual da PR.

- PR #2: **merged**.
- Branch integrada: `feat/foundation-auth-security`.
- HEAD da PR: `678d9eeb0d7d882e3017ca60525afcd5b42a0765`.
- Commit de merge: `c80986b763e23d5a9541334c9894dbd9effa5e27`.
- `main` consultada após o merge: SHA exatamente igual a `c80986b763e23d5a9541334c9894dbd9effa5e27`.
- CI pós-merge no commit resultante: run #45464299740, 5/5 checks concluídos com sucesso.
- Branch `main`: protegida; os cinco checks de qualidade, banco, HTTP e proteção Apache continuam configurados como obrigatórios.
- Commit de merge verificado; pais correspondem à base anterior e ao HEAD da PR.
- Escopo integrado: 78 commits, 58 arquivos, 6.020 adições e 73 exclusões.

### 13.1 Próxima fase — implantação controlada do Bloco 01

**A integração de código terminou. A implantação no banco ainda não está concluída.** Não executar migrations automaticamente nem presumir que o ambiente local está vazio.

- [x] Confirmar PR #2 mesclada.
- [x] Confirmar que `main` aponta para o commit de merge.
- [x] Confirmar CI pós-merge 5/5 no commit de merge.
- [ ] Atualizar o clone local a partir da `main` e confirmar o SHA antes de testar.
- [ ] Identificar explicitamente o ambiente e o banco alvo; confirmar que não é produção ou banco compartilhado sem autorização.
- [ ] Verificar a configuração local não versionada e validar a conexão com o banco correto.
- [ ] Fazer backup completo, fora da pasta pública, e comprovar que pode ser lido/restaurado em ambiente de teste.
- [ ] Inspecionar o schema atual para decidir entre instalação vazia e banco existente; não importar `banco.sql` cegamente em banco com dados.
- [ ] Aplicar migrations 001, 002, 003 e 007 uma única vez, na ordem do runbook.
- [ ] Inserir o marcador histórico antes da migration 008, conforme instrução documentada.
- [ ] Aplicar migration 008 e executar `php scripts/inicializar-integridade-auditoria.php`.
- [ ] Verificar a integridade da cadeia de auditoria.
- [ ] Provisionar o primeiro Administrador com `php scripts/provisionar-usuario.php`, exclusivamente pelo terminal.
- [ ] Executar smoke tests: login, troca obrigatória de senha, alteração voluntária, revogação de sessões paralelas, RBAC, CRUD protegido, auditoria, logout e acesso por perfil.
- [ ] Registrar evidências, resultado e eventuais desvios em novo checkpoint documental.

### 13.2 Regras de segurança para a próxima fase

- Não colocar credenciais reais no Git.
- Não executar migrations sem backup verificável e inspeção do schema.
- Não reaplicar migrations parcialmente executadas; parar e inspecionar o estado antes de qualquer retomada.
- Não improvisar rollback SQL; usar o plano de recuperação e o backup validado.
- Não liberar o ambiente até que provisionamento, integridade da auditoria e smoke tests estejam aprovados.

**Estado:** integração de código concluída; execução operacional do banco e validação local continuam pendentes. Nenhuma migration, alteração de dados ou provisionamento foi realizado neste checkpoint.


### 13.3 Gate adicional — revisão dos perfis após a migration 007

A migration `007_administrative_roles.sql` adiciona o perfil `ADMINISTRADOR` com esse valor inicial para todas as contas que já existirem na tabela `usuarios`. Em seguida, altera o padrão para que novas contas sejam `OPERADOR`.

Antes de liberar o sistema após as migrations:

- [ ] Listar todas as contas existentes em `usuarios` (id, nome, e-mail, ativo e perfil).
- [ ] Confirmar individualmente quais pessoas realmente devem manter `ADMINISTRADOR`.
- [ ] Rebaixar contas que não devam ter privilégios administrativos, usando a interface/serviço autorizado da aplicação e preservando a auditoria de alteração de perfil.
- [ ] Confirmar que existe pelo menos um Administrador legítimo e ativo.
- [ ] Validar por teste que Gestor e Operador não conseguem executar ações exclusivas de Administrador.
- [ ] Registrar quem revisou os perfis, quando a revisão ocorreu e o resultado.

**Regra de liberação:** não disponibilizar o sistema a usuários reais enquanto a revisão dos perfis herdados não estiver concluída. O provisionamento do primeiro Administrador por CLI não substitui a revisão de contas preexistentes.

Esta verificação é especialmente importante em banco já existente. Em uma instalação nova sem contas preexistentes, registrar explicitamente que a lista estava vazia antes do provisionamento inicial.
