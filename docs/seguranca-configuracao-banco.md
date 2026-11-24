# SEC-02 — Configuração do banco e credenciais padrão

## Achado

A implementação anterior de `conexao.php` usava `root` e senha vazia sempre que as variáveis de ambiente não estavam definidas. Esse comportamento é conveniente em algumas instalações XAMPP, mas também fazia com que ambientes não configurados tentassem conectar com credenciais administrativas padrão.

## Decisão

A resolução da conexão foi centralizada em `src/Infrastructure/DatabaseConfig.php`.

- O ambiente `production` é o padrão quando não há configuração local explícita.
- Em `production`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD` devem ser informadas explicitamente e não podem estar vazias.
- A aplicação não assume mais `root`, senha vazia ou host/banco padrão em production.
- Em desenvolvimento local, o arquivo ignorado `config/database.local.php` habilita o modo local quando `APP_ENV` não está definido. Ele é criado copiando `config/database.local.example.php`.
- No modo local, variáveis de ambiente têm precedência sobre o arquivo local. A senha vazia é permitida apenas nesse modo e precisa estar declarada explicitamente.
- Definir `APP_ENV=production` desabilita a leitura da configuração local e exige todas as cinco variáveis do banco.
- A pasta `config/` bloqueia acesso HTTP direto por meio de `.htaccess`. O arquivo local real também é excluído pelo `.gitignore`.

## Configuração local com XAMPP

Copie `config/database.local.example.php` para `config/database.local.php`. Confira host, porta, nome do banco, usuário e senha conforme a instalação local. A configuração de exemplo usa `root` sem senha exclusivamente para o XAMPP local; não reutilize esse usuário em um ambiente compartilhado ou de produção.

Não faça commit de `config/database.local.php` e não o inclua em artefatos de implantação.

## Configuração em produção

Configure as cinco variáveis de conexão no ambiente do processo PHP ou nos secrets do provedor:

- `APP_ENV=production`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`

Use um usuário dedicado, com privilégios mínimos necessários à aplicação. Não utilize a conta administrativa `root`. A senha deve ser não vazia e armazenada no gerenciador de segredos do ambiente.

Quando `APP_ENV=production`, o arquivo local não é carregado, mesmo que exista na cópia de trabalho.

## Validação automatizada

`tests/database-configuration.php` cobre:

- exigência de todas as variáveis em production;
- rejeição de usuário ausente e senha ausente/vazia;
- ausência de fallback para credenciais locais em production;
- compatibilidade explícita com XAMPP local e senha vazia;
- precedência das variáveis de ambiente;
- rejeição de ambiente desconhecido, `APP_ENV` vazio e porta inválida.

A CI executa esse teste sem precisar abrir conexão com um banco real. Os demais jobs continuam passando credenciais explícitas aos bancos efêmeros MySQL e MariaDB.

## Limites

Esta mudança não cria usuários nem altera permissões no MySQL local ou remoto. A criação de um usuário de banco dedicado, a rotação de credenciais, a configuração do servidor de produção e a validação manual no XAMPP permanecem tarefas operacionais separadas.
