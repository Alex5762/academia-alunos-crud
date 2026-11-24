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
- Exclusão física temporariamente restrita ao Administrador; será aposentada quando a inativação auditada de alunos for integrada.

## Tecnologias

PHP 8.2+, PDO, MySQL 8.0 ou MariaDB 10.11, HTML, CSS e JavaScript.

## Instalação local (XAMPP)

1. Copie o repositório para `htdocs`.
2. Inicie Apache e MySQL.
3. Importe `banco.sql` no banco local.
4. Copie `config/database.local.example.php` para `config/database.local.php` e ajuste host, porta, banco, usuário e senha. O arquivo local real é ignorado pelo Git.
5. Execute as migrations, nesta ordem, pelo terminal na raiz do projeto:
   - `database/migrations/001_authentication_audit.sql`
   - `database/migrations/002_force_password_change.sql`
   - `database/migrations/003_login_rate_limit.sql`
   - `database/migrations/007_administrative_roles.sql`
6. Antes da migration 008, grave o marcador histórico:
   `INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id) VALUES (NULL, 'evento_legado_baseline', 'sistema', NULL);`
7. Execute `database/migrations/008_audit_integrity_chain.sql`.
8. Inicialize e verifique a cadeia:
   `php scripts/inicializar-integridade-auditoria.php`
9. Provisione o primeiro Administrador:
   `php scripts/provisionar-usuario.php`
   Informe nome e e-mail quando solicitado e copie a senha temporária exibida. Ela será solicitada somente no primeiro login.
10. Acesse `http://localhost/academia-alunos-crud/`.

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
