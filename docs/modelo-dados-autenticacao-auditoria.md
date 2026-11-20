# Modelo de dados — Bloco 01

Este documento descreve a migração inicial de autenticação e auditoria do FitControl.

## Arquivo de migração

`database/migrations/001_authentication_audit.sql`

A migração cria duas tabelas novas e não altera a tabela `alunos`.

## Tabela `usuarios`

| Campo | Tipo | Regra |
|---|---|---|
| id | INT UNSIGNED | Chave primária |
| nome | VARCHAR(100) | Obrigatório |
| email | VARCHAR(150) | Obrigatório e único |
| senha_hash | VARCHAR(255) | Hash produzido pelo PHP; nunca senha em texto puro |
| ativo | TINYINT(1) | 1 por padrão; 0 bloqueia autenticação |
| criado_em | DATETIME | Preenchido pelo banco |
| atualizado_em | DATETIME | Atualizado pelo banco |

A collation `utf8mb4_unicode_ci` torna a comparação do e-mail insensível a maiúsculas/minúsculas no índice único. A aplicação também deverá normalizar e-mails removendo espaços externos e convertendo-os para minúsculas antes de consultar ou gravar.

Contas não serão removidas fisicamente pelo fluxo normal. Para retirar o acesso, serão desativadas. Isso preserva a relação com os eventos históricos.

## Tabela `auditoria`

| Campo | Tipo | Regra |
|---|---|---|
| id | BIGINT UNSIGNED | Chave primária |
| usuario_id | INT UNSIGNED NULL | Funcionário autenticado; NULL para eventos sem identidade autenticada |
| acao | VARCHAR(50) | Código curto e estável do evento |
| entidade | VARCHAR(40) | Tipo do registro afetado |
| entidade_id | BIGINT UNSIGNED NULL | Identificador lógico do registro afetado |
| criado_em | DATETIME | Horário gerado pelo banco |

Índices cobrem consultas por usuário/data, entidade/registro/data e ação/data.

A FK de `usuario_id` usa `ON DELETE RESTRICT`: o banco impede apagar uma conta que possua histórico. O uso normal deve ser desativar a conta.

`entidade_id` é uma referência lógica sem chave estrangeira porque pode identificar registros de tabelas diferentes (por exemplo, `alunos` ou `usuarios`). A combinação de `entidade` e `entidade_id` identifica o alvo.

## Eventos previstos

| Ação | Entidade | Quando registrar |
|---|---|---|
| `login_sucesso` | `sessao` | Após autenticação válida |
| `login_falha` | `sessao` | Após tentativa inválida; usuario_id NULL |
| `logout` | `sessao` | Antes de invalidar a sessão autenticada |
| `aluno_criado` | `aluno` | Após INSERT confirmado |
| `aluno_atualizado` | `aluno` | Após UPDATE confirmado |
| `aluno_excluido` | `aluno` | Após DELETE confirmado |

O ID de quem executou a ação deve vir exclusivamente da sessão autenticada no servidor. Não aceitar `usuario_id` enviado pelo formulário ou query string.

## Transações

Nas operações de cadastro, edição e exclusão, a alteração do aluno e a inserção do evento correspondente devem ocorrer na mesma transação PDO. Se uma das operações falhar, ambas devem sofrer rollback.

Eventos de login/logout são registrados em seus próprios fluxos. Falhas de login não devem revelar se o e-mail existe e não devem armazenar senha, hash, token ou identificador de sessão.

## Provisionamento da primeira conta

A migração não cria uma conta padrão. Isso é intencional: não haverá usuário/senha conhecidos ou credenciais embutidas no repositório.

O próximo passo de implementação deverá criar um comando PHP executável somente via CLI, que:
1. recuse execução via servidor web;
2. solicite nome, e-mail e senha sem ecoar a senha no terminal;
3. valide os campos e a confirmação da senha;
4. gere o hash com `password_hash()`;
5. insira a conta ativa usando PDO;
6. registre o evento de provisionamento inicial com `usuario_id = NULL` e entidade `usuario`;
7. nunca imprima nem registre a senha ou o hash.

A criação de contas adicionais poderá ser feita por um comando administrativo local semelhante, até que exista uma tela protegida para gestão de funcionários.

## Execução local

Com o banco `academia_alunos` já criado e a configuração de conexão definida:

```bash
mysql -u root -p academia_alunos < database/migrations/001_authentication_audit.sql
```

No XAMPP com senha vazia, omita `-p` e execute:

```bash
mysql -u root academia_alunos < database/migrations/001_authentication_audit.sql
```

A migração usa `CREATE TABLE IF NOT EXISTS`, mas deve ser executada e validada em ambiente de desenvolvimento antes de qualquer uso com dados reais. O projeto ainda não possui ferramenta de versionamento/controle de migrações; esta pasta registra a sequência aplicada manualmente.

## Privacidade e limites

- Não armazenar senhas, tokens CSRF, IDs de sessão ou cópias integrais de dados pessoais no log.
- Não incluir endereço IP neste primeiro esquema; sua necessidade e prazo de retenção exigiriam decisão específica de privacidade.
- A trilha básica identifica autor, ação, alvo e horário; não é ainda um histórico de alterações campo a campo.
- Acesso à consulta de auditoria será protegido pela autenticação quando uma interface de consulta for criada.
