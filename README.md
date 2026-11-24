# Academia Alunos CRUD — fundação segura

Projeto acadêmico de Desenvolvimento Web. Esta versão preserva o CRUD original de alunos e acrescenta autenticação individual, autorização por perfil e auditoria transacional.

## Funcionalidades desta etapa

- Login e logout com sessão protegida, token CSRF, expiração e revogação persistente por versão da conta; sessões anteriores não voltam a funcionar após reativação ou troca de senha.
- Primeiro acesso com troca obrigatória de senha.
- Alteração voluntária de senha pelo painel, com revogação das sessões paralelas.
- Perfis Administrador, Gestor e Operador, com autorização verificada no servidor.
- Cadastro e administração de contas individuais.
- Limitação persistente de tentativas de login.
- Auditoria append-only com encadeamento SHA-256.
- CRUD original de alunos protegido por autenticação e permissões.
- Gestão de alunos com status ativo/inativo, filtros por situação e pesquisa por nome, e-mail ou telefone.
- Inativação/reativação por POST com CSRF, RBAC, transação e auditoria; exclusão física aposentada (HTTP 410).

## Tecnologias

PHP 8.2+, PDO, MySQL 8.0 ou MariaDB 10.11, HTML, CSS e JavaScript.

## Instalação local (XAMPP)

1. Copie o repositório para `htdocs`.
2. Inicie Apache e MySQL.
3. Para uma instalação nova em banco vazio, importe `banco.sql`. Se o banco já existir ou contiver dados, não importe cegamente: faça backup e inspecione o schema antes de decidir como aplicar as migrations; siga o checklist em `docs/pre-merge-checklist-bloco-01-2026-11-24.md`.
4. Copie `config/database.local.example.php` para `config/database.local.php` e ajuste host, porta, banco, usuário e senha. O arquivo local real é ignorado pelo Git.
5. Execute as migrations, nesta ordem, pelo terminal na raiz do projeto:
   - `database/migrations/001_authentication_audit.sql`
   - `database/migrations/002_force_password_change.sql`
   - `database/migrations/003_login_rate_limit.sql`
   - `database/migrations/004_student_status.sql`
   - `database/migrations/007_administrative_roles.sql`
6. Antes da migration 008, grave o marcador histórico:
   `INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id) VALUES (NULL, 'evento_legado_baseline', 'sistema', NULL);`
7. Execute `database/migrations/008_audit_integrity_chain.sql`.
8. Execute `database/migrations/015_student_status_constraint.sql` (MySQL 8.0.16+ ou MariaDB 10.11).
9. Inicialize e verifique a cadeia:
   `php scripts/inicializar-integridade-auditoria.php`
10. Provisione o primeiro Administrador:
   `php scripts/provisionar-usuario.php`
   Informe nome e e-mail quando solicitado e copie a senha temporária exibida. Ela será solicitada somente no primeiro login.
11. Acesse `http://localhost/academia-alunos-crud/`.

A migration 004 adiciona `alunos.ativo` e preserva os registros existentes como ativos. A migration 015 adiciona uma constraint CHECK que só aceita `0` ou `1`. Execute ambas uma única vez, após backup e inspeção do banco, e confirme a compatibilidade da versão MySQL (8.0.16+) ou MariaDB (10.11).

### Cuidados obrigatórios antes de liberar o sistema

- **Nome do banco:** `banco.sql` e as migrations atuais usam explicitamente o schema `academia_alunos` (por meio de `CREATE DATABASE` / `USE academia_alunos`). Mantenha `database` no arquivo local e `DB_NAME` no ambiente exatamente como `academia_alunos`. Alterar apenas a configuração da aplicação para outro nome não redireciona as migrations; uma mudança de nome exige ajuste coordenado dos scripts SQL e da configuração.
- **Banco existente:** não trate a sequência de instalação nova como atualização automática. Antes de importar qualquer SQL ou executar migrations em banco com dados, faça backup completo e inspecione tabelas, colunas, índices e triggers. Não repita migrations parcialmente executadas.
- **Perfis herdados:** a migration `007_administrative_roles.sql` atribui `ADMINISTRADOR` às contas que já existiam em `usuarios` quando a coluna é criada. Antes de abrir o sistema a usuários reais, revise individualmente essas contas e reduza os perfis que não devam ser administrativos, usando o fluxo autorizado da aplicação para preservar a auditoria. Em instalação nova, registre que não havia contas preexistentes antes do provisionamento.
- **Prontidão:** use o runbook e o checklist acima para backup, sequência das migrations, inicialização da cadeia de auditoria, provisionamento CLI e smoke tests. CI verde não substitui esses passos no banco local/de destino.

> A migration 008 instala triggers que impedem inserções diretas na tabela de auditoria. Depois dela, eventos devem ser escritos pelo serviço transacional da aplicação. Por isso, o marcador histórico precisa ser criado antes de aplicar essa migration.

## Configuração do banco

- Ambiente local: arquivo não versionado `config/database.local.php`.
- CI/ambiente de produção: variáveis `APP_ENV=production`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD` obrigatórias.
- Nunca versione credenciais reais. Não exponha o projeto ou dados reais; esta é uma base acadêmica.

## Testes

A CI valida sintaxe PHP, política de senha, RBAC, configuração de banco, migrations e integridade transacional em MySQL 8.0 e MariaDB 10.11. A integração HTTP cobre autenticação, CSRF, RBAC, operações do CRUD protegido e revogação de sessões paralelas após troca obrigatória e voluntária de senha.

## Escopo futuro

Planos, matrículas, frequência, financeiro, grade, dashboard e relatórios serão integrados em PRs independentes, com suas migrations e regressões próprias. A migration 010 permanece fora desta etapa.


### Rate limit e auditoria de autenticação

O login bloqueia uma combinação de e-mail normalizado e origem após cinco falhas em uma janela de 15 minutos, por mais 15 minutos. A tabela de tentativas guarda hashes SHA-256; falhas de credenciais que passam pelo bloqueio entram na cadeia append-only como `login_falhou`, sem e-mail ou IP. Um login válido limpa o contador. Registros sem atividade por mais de um dia são removidos.
