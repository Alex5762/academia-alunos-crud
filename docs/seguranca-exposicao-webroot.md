# SEC-03 — Proteção dos diretórios internos no webroot

## Achado

O projeto é executado diretamente em uma pasta pública do Apache/XAMPP. Sem regras de servidor, diretórios como database/, scripts/, src/, tests/ e docs/ ficam potencialmente acessíveis por URL. Isso pode expor migrations SQL, ferramentas administrativas/CLI, código interno, testes e detalhes da arquitetura. O arquivo raiz banco.sql também poderia ser baixado. Quando o checkout Git é colocado dentro do webroot, metadados de .git/ e .github/ também precisam ser protegidos.

## Controles aplicados

- O .htaccess raiz bloqueia banco.sql, arquivos ocultos e caminhos de metadados iniciados por ponto, incluindo .git/ e .github/.
- Os diretórios config/, database/, docs/, scripts/, src/, tests/ e .github/ contêm um .htaccess com Require all denied.
- A pasta config/ já estava protegida pelo controle criado na SEC-02.
- Os endpoints PHP públicos do sistema e os ativos estáticos da interface permanecem na raiz do projeto e não são bloqueados por essas regras.
- As proteções são específicas para Apache 2.4. Em Apache, o projeto precisa permitir AllowOverride All (ou pelo menos os grupos de diretivas usados) no diretório da aplicação e ter mod_alias habilitado para a regra de arquivos/caminhos ocultos.

## Validação automatizada

tests/webroot-protection.sh inicia um Apache 2.4 real em container, habilita AllowOverride All apenas no ambiente de teste e verifica:

- style.css continua acessível (HTTP 200);
- banco.sql, migrations, ferramentas CLI, testes, código interno e documentação retornam HTTP 403 ou 404;
- os arquivos de configuração e metadados Git/GitHub não são servidos.

A CI executa esse teste em container, além de verificar a presença das regras. O servidor embutido do PHP não interpreta .htaccess e, portanto, não é usado para validar esses bloqueios.

## Verificação local no XAMPP

Depois de copiar/atualizar o projeto, confirme que o Apache está lendo .htaccess e que mod_alias está ativo. Os caminhos abaixo devem retornar 403 ou 404:

- /academia-alunos-crud/banco.sql
- /academia-alunos-crud/database/migrations/001_authentication_audit.sql
- /academia-alunos-crud/scripts/provisionar-usuario.php
- /academia-alunos-crud/tests/password-policy.php
- /academia-alunos-crud/src/Infrastructure/DatabaseConfig.php
- /academia-alunos-crud/config/database.local.example.php
- /academia-alunos-crud/.git/config

A folha de estilos em /academia-alunos-crud/style.css deve continuar retornando HTTP 200, assim como as páginas normais do FitControl.

## Limites

Essas regras não substituem uma configuração de virtual host que mantenha o código fora do webroot. Em produção, prefira publicar somente os arquivos necessários à aplicação, mantenha segredos fora da raiz pública e não implante o diretório .git/. Em Nginx ou outro servidor, traduza explicitamente as regras para a configuração correspondente: arquivos .htaccess são ignorados por esses servidores. A inspeção manual no XAMPP do usuário permanece pendente.