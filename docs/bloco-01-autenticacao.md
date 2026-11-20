# Bloco 01 — Autenticação, contas individuais e auditoria

## Objetivo

Exigir autenticação antes de permitir acesso ao painel e às operações administrativas do FitControl. Cada funcionário terá sua própria conta, permitindo atribuir ações a uma pessoa específica e consultar um histórico básico de atividade.

**Estado:** decisão de contas individuais confirmada; migração inicial do banco criada. Implementação da autenticação e da auditoria ainda não iniciada.

## Diagnóstico da base atual

- `funcoes.php` inicia a sessão e mantém o token CSRF, mas não aplica configuração explícita de segurança da sessão.
- `index.php`, `cadastro.php`, `salvar.php` e `excluir.php` não exigem autenticação.
- `configuracoes.php` também não possui verificação de acesso.
- As operações de escrita já validam método HTTP e token CSRF.
- O banco atual contém somente a tabela `alunos`; não existe estrutura de usuários nem de auditoria.
- A CI exercita o CRUD, mas ainda não cobre autenticação ou autoria das ações.

## Decisão funcional confirmada

O FitControl terá **contas individuais por funcionário**, todas com a mesma permissão administrativa nesta primeira versão.

- Cada funcionário usa suas próprias credenciais, sem compartilhar uma senha.
- O acesso de uma pessoa pode ser desativado sem trocar a senha de todos.
- As operações podem ser associadas ao funcionário responsável.
- A equipe começa com uma permissão única; níveis diferentes ficam fora do escopo até existirem regras concretas.

Não haverá cadastro público de usuários.

## Modelo de dados definido

A migração `database/migrations/001_authentication_audit.sql` cria duas tabelas:

### `usuarios`

- `id`: chave primária.
- `nome`: nome de exibição do funcionário.
- `email`: identificador único da conta; a collation escolhida torna a unicidade insensível a maiúsculas/minúsculas.
- `senha_hash`: hash gerado no servidor, com espaço para evolução do algoritmo.
- `ativo`: permite bloquear o acesso sem apagar a conta.
- `criado_em` e `atualizado_em`: datas mantidas pelo banco.

### `auditoria`

- `id`: chave primária.
- `usuario_id`: funcionário responsável; aceita NULL para eventos sem sessão autenticada, como tentativa de login não associada a uma conta ou provisionamento inicial.
- `acao`: evento realizado, por exemplo `login`, `logout`, `aluno_criado`, `aluno_editado` ou `aluno_excluido`.
- `entidade` e `entidade_id`: tipo e referência lógica do registro afetado.
- `criado_em`: data/hora do evento, gerada pelo banco.

A tabela de auditoria possui índices por usuário/data, entidade/registro/data e ação/data. A referência à entidade é lógica, pois poderá apontar para alunos e outras entidades futuras; não será criada uma chave estrangeira polimórfica.

Contas não serão apagadas fisicamente. A desativação preserva a relação com eventos antigos. A migração usa `CREATE TABLE IF NOT EXISTS` e não modifica nem remove a tabela `alunos`.

## Aplicação da migração

Pré-requisito: o banco `academia_alunos` já deve existir, com o schema atual importado.

No phpMyAdmin, selecione `academia_alunos`, abra a aba SQL e execute o conteúdo de `database/migrations/001_authentication_audit.sql`.

A migração cria somente as novas tabelas. Ela ainda não cria contas; o provisionamento será implementado em uma etapa própria, sem usuário/senha padrão e sem credenciais versionadas.

## Escopo proposto

### Autenticação

1. Tela de login com identificação por e-mail e senha.
2. Logout por requisição POST com proteção CSRF.
3. Armazenamento de senha com `password_hash()` e verificação com `password_verify()`.
4. Provisionamento seguro da primeira conta e criação de contas adicionais.
5. Proteção centralizada das páginas e endpoints administrativos.
6. Configuração segura de sessão: cookies HttpOnly e SameSite, modo estrito, uso exclusivo de cookies e atributo Secure quando a aplicação estiver em HTTPS.
7. Renovação do identificador da sessão após autenticação e encerramento correto da sessão no logout.
8. Mensagens genéricas para falha de autenticação, sem revelar se o e-mail existe.

### Auditoria básica

9. Registrar pelo menos login, logout, cadastro de aluno, edição de aluno e exclusão de aluno.
10. Gravar eventos no servidor; nunca confiar em nome ou ID de usuário enviados pelo navegador.
11. Não registrar senhas, tokens de sessão ou cópias desnecessárias de dados pessoais.
12. Nesta versão, guardar tipo de ação e referência ao registro, sem cópia integral dos dados antes/depois.

### Testes

13. Testes HTTP de acesso anônimo, login válido, login inválido, logout e regressão do CRUD/CSRF.
14. Testes que confirmem que cada evento de auditoria foi associado ao usuário autenticado correto.

### Fora do escopo nesta etapa

- Cadastro público de contas.
- Recuperação de senha por e-mail.
- Autenticação em dois fatores.
- Perfis com permissões diferentes.
- Painel completo de consulta/filtragem de auditoria.
- Integração com provedores externos de identidade.
- Histórico detalhado de cada campo antes/depois.

## Impacto técnico previsto

- `funcoes.php`: inicialização segura da sessão, funções de autenticação, proteção centralizada de rotas e helper de auditoria.
- `login.php` e `logout.php`: novos fluxos públicos de entrada e saída.
- `index.php`, `cadastro.php`, `salvar.php`, `excluir.php` e `configuracoes.php`: exigir sessão autenticada antes de exibir dados ou executar ações.
- `conexao.php` e banco: manter PDO e aplicar a migração já criada.
- `salvar.php` e `excluir.php`: registrar eventos após operações concluídas com sucesso, associando-os ao usuário da sessão.
- `tests/` e `.github/workflows/ci.yml`: cobrir os fluxos novos e manter os testes atuais.
- `README.md` e documentação local: explicar como preparar o banco, provisionar contas e interpretar os eventos registrados.

## Segurança e decisões de implementação

- Não usar senha padrão, usuário fixo embutido ou cadastro público de administrador.
- Não registrar senha, token CSRF ou identificador de sessão em logs.
- Aplicar limitação básica de tentativas de login somente se houver solução proporcional e testável no ambiente atual.
- Manter CSRF nos formulários de escrita e também proteger o fluxo de logout.
- Aplicar a verificação de autenticação antes de consultar ou alterar dados administrativos.
- Obter a identidade do autor exclusivamente da sessão validada no servidor.
- Usar transação quando uma operação de negócio e seu evento de auditoria precisarem ser confirmados juntos.
- Não confiar em controles visuais ou links ocultos como mecanismo de autorização.
- Login e auditoria não tornam o sistema pronto para produção; hospedagem, HTTPS, backups e privacidade serão revisados no Bloco 08.

## Critérios de aceite

- Usuário sem sessão não consegue abrir páginas administrativas nem executar endpoints protegidos.
- Login válido cria uma sessão autenticada e renova o identificador de sessão.
- Login inválido retorna mensagem genérica e não autentica.
- Logout invalida a sessão e impede reutilização do acesso.
- Contas inativas não conseguem autenticar.
- Senhas são armazenadas apenas como hash.
- Cookies e configurações da sessão seguem os requisitos definidos para ambiente local e HTTPS.
- Cadastro, edição e exclusão de aluno registram ação, usuário responsável, referência do registro e data/hora.
- Eventos de login/logout ficam associados à conta correspondente, conforme a política definida para eventos de autenticação.
- A auditoria não confia em dados de autoria enviados pelo cliente e não armazena segredos.
- O CRUD, as validações e a proteção CSRF continuam funcionando após o login.
- A CI executa os cenários de autenticação, auditoria e regressão com sucesso.
- A documentação explica como provisionar contas sem publicar credenciais.

## Próximas etapas

1. Implementar o provisionamento seguro de contas (primeira conta e contas adicionais).
2. Implementar a camada de sessão e autenticação.
3. Criar login/logout e proteger as rotas.
4. Implementar o registro dos eventos de auditoria nos fluxos do CRUD.
5. Expandir os testes HTTP e a CI.
6. Revisar o comportamento, atualizar documentação e validar a PR.
