# Modelo de dados — autenticação, contas e auditoria

Este documento descreve as estruturas adicionadas ao FitControl pelas migrações do Bloco 01. A tabela original \`alunos\` é preservada.

## Sequência de migrações

Depois de importar \`banco.sql\` e selecionar o banco \`academia_alunos\`, execute nesta ordem:

1. \`database/migrations/001_authentication_audit.sql\`
2. \`database/migrations/002_force_password_change.sql\`
3. \`database/migrations/003_login_rate_limit.sql\`

A CI executa as três migrações em um MySQL limpo antes dos testes de integração.

## Tabela \`usuarios\`

Criada pela migração 001 e ampliada pela migração 002.

| Campo | Tipo | Regra |
|---|---|---|
| id | INT UNSIGNED | Chave primária |
| nome | VARCHAR(100) | Obrigatório |
| email | VARCHAR(150) | Obrigatório e único |
| senha_hash | VARCHAR(255) | Hash produzido pelo PHP; nunca senha em texto puro |
| deve_trocar_senha | TINYINT(1) | Adicionado na migração 002; padrão 1 |
| ativo | TINYINT(1) | 1 por padrão; 0 bloqueia autenticação |
| criado_em | DATETIME | Preenchido pelo banco |
| atualizado_em | DATETIME | Atualizado pelo banco |

A collation \`utf8mb4_unicode_ci\` torna a comparação do e-mail insensível a maiúsculas/minúsculas no índice único. A aplicação normaliza e-mails removendo espaços externos e convertendo-os para minúsculas.

Contas não são removidas fisicamente pelo fluxo normal. Para retirar o acesso, são desativadas, preservando a relação com os eventos históricos. A versão atual não possui coluna de cargo ou papéis: todas as contas ativas têm as mesmas permissões administrativas.

## Tabela \`auditoria\`

Criada pela migração 001.

| Campo | Tipo | Regra |
|---|---|---|
| id | BIGINT UNSIGNED | Chave primária |
| usuario_id | INT UNSIGNED NULL | Funcionário responsável; NULL para eventos sem sessão autenticada, como provisionamento CLI |
| acao | VARCHAR(50) | Código do evento |
| entidade | VARCHAR(40) | Tipo do registro afetado |
| entidade_id | BIGINT UNSIGNED NULL | Identificador lógico do registro afetado |
| criado_em | DATETIME | Horário gerado pelo banco |

Índices cobrem consultas por usuário/data, entidade/registro/data e ação/data. A FK de \`usuario_id\` usa \`ON DELETE RESTRICT\`: o banco impede apagar uma conta que possua histórico. O uso normal deve ser desativar a conta.

\`entidade_id\` é uma referência lógica sem chave estrangeira porque pode identificar registros de tabelas diferentes, como \`alunos\` ou \`usuarios\`. A combinação de \`entidade\` e \`entidade_id\` identifica o alvo.

### Eventos atualmente registrados

| Ação | Entidade | Quando registrar |
|---|---|---|
| \`usuario_provisionado_cli\` | \`usuarios\` | Conta criada pelo provisionador local; \`usuario_id\` do autor fica NULL |
| \`login\` | \`usuarios\` | Após autenticação válida |
| \`logout\` | \`usuarios\` | Antes de invalidar a sessão autenticada |
| \`senha_alterada_primeiro_acesso\` | \`usuarios\` | Após troca da senha temporária |
| \`usuario_criado\` | \`usuarios\` | Após criação por funcionário autenticado |
| \`usuario_desativado\` | \`usuarios\` | Após desativação por funcionário autenticado |
| \`usuario_reativado\` | \`usuarios\` | Após reativação por funcionário autenticado |
| \`aluno_criado\` | \`alunos\` | Após INSERT confirmado |
| \`aluno_editado\` | \`alunos\` | Após UPDATE confirmado |
| \`aluno_excluido\` | \`alunos\` | Após DELETE confirmado |

O ID de quem executou a ação vem exclusivamente da sessão autenticada no servidor. A implementação atual não grava tentativas de login malsucedidas na tabela \`auditoria\`; elas são tratadas pela estrutura \`login_tentativas\`.

## Tabela \`login_tentativas\`

Criada pela migração 003 para limitar tentativas de autenticação.

| Campo | Tipo | Regra |
|---|---|---|
| id | BIGINT UNSIGNED | Chave primária |
| email_hash | CHAR(64) | SHA-256 do e-mail normalizado |
| origem_hash | CHAR(64) | SHA-256 do endereço IP de origem |
| tentativas | SMALLINT UNSIGNED | Contador dentro da janela |
| janela_inicio | DATETIME | Início da janela de 15 minutos |
| bloqueado_ate | DATETIME NULL | Fim do bloqueio temporário |
| atualizado_em | DATETIME | Atualizado pelo banco |

O limite atual é de cinco falhas para a combinação de e-mail e origem dentro de 15 minutos; a partir da quinta falha, essa combinação fica bloqueada por 15 minutos. Um login válido remove o contador correspondente. Registros sem atividade por mais de um dia são removidos durante requisições válidas de login.

Os hashes são dados pseudonimizados, não anônimos. O IP não é armazenado em texto puro nessa tabela. O limite é por e-mail + origem, não é um bloqueio global por endereço IP e não impede ataques distribuídos com várias origens.

## Provisionamento da primeira conta e contas adicionais

A migração não cria uma conta padrão. Não existem usuário/senha conhecidos nem credenciais embutidas no repositório.

O script \`scripts/provisionar-usuario.php\`:
1. recusa execução via servidor web;
2. solicita nome e e-mail pelo terminal;
3. valida os campos e normaliza o e-mail;
4. gera uma senha temporária com 192 bits aleatórios;
5. persiste somente o hash com \`password_hash()\`;
6. marca a conta como ativa e exige troca no primeiro acesso;
7. registra \`usuario_provisionado_cli\` na mesma transação, sem atribuir autor autenticado;
8. exibe a senha temporária uma única vez no terminal.

Contas adicionais podem ser criadas pela tela protegida **Funcionários**. A senha temporária é gerada no servidor, exibida uma única vez após a criação e substituída obrigatoriamente no primeiro acesso por uma senha pessoal com pelo menos 12 caracteres.

## Transações e preservação

Nas operações de cadastro, edição e exclusão de alunos, a alteração do aluno e a inserção do evento correspondente ocorrem na mesma transação PDO. Criação, desativação e reativação de contas também associam a operação e seu evento de auditoria na mesma transação.

A desativação de funcionário bloqueia o próximo acesso protegido. Não é permitido desativar a própria conta nem a última conta ativa. As contas não são apagadas fisicamente, preservando o histórico.

## Privacidade e limites

- Não armazenar senhas, tokens CSRF, IDs de sessão ou cópias integrais de dados pessoais na auditoria.
- A tabela \`login_tentativas\` guarda hashes de e-mail e IP de origem para aplicar o limite; esses valores são pseudonimizados, não anônimos.
- A trilha básica identifica autor, ação, alvo e horário; não é um histórico de alterações campo a campo.
- Não há interface para consulta de auditoria nesta versão.
- A estrutura não implementa papéis/permissões diferentes, MFA ou recuperação de senha por e-mail.
- A aplicação não está pronta para produção; hospedagem, HTTPS, backups, monitoramento e revisão de privacidade exigem etapa própria.
