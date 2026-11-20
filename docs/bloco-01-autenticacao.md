# Bloco 01 — Autenticação, contas individuais e auditoria

## Objetivo

Exigir autenticação antes de permitir acesso ao painel e às operações administrativas do FitControl. Cada funcionário terá sua própria conta, permitindo atribuir ações a uma pessoa específica e consultar um histórico básico de atividade.

**Estado:** modelo de contas individuais confirmado; planejamento técnico registrado. Implementação ainda não iniciada.

## Diagnóstico da base atual

- `funcoes.php` inicia a sessão e mantém o token CSRF, mas não aplica configuração explícita de segurança da sessão.
- `index.php`, `cadastro.php`, `salvar.php` e `excluir.php` não exigem autenticação.
- `configuracoes.php` também não possui verificação de acesso.
- As operações de escrita já validam método HTTP e token CSRF.
- O banco atual contém somente a tabela `alunos`; não existe estrutura de usuários nem de auditoria.
- A CI exercita o CRUD, mas ainda não cobre autenticação ou autoria das ações.

## Decisão funcional confirmada

O FitControl terá **contas individuais por funcionário**, todas com a mesma permissão administrativa nesta primeira versão.

Motivos:
- Cada funcionário usa suas próprias credenciais, sem compartilhar uma senha entre a equipe.
- O acesso de uma pessoa pode ser desativado sem trocar a senha de todos.
- As operações podem ser associadas ao funcionário responsável.
- A equipe pode começar com uma permissão única, sem criar níveis de acesso que ainda não têm regras definidas.

Não haverá cadastro público de usuários.

## Escopo proposto

### Autenticação

1. Tela de login com identificação por e-mail e senha.
2. Logout por requisição POST com proteção CSRF.
3. Armazenamento de senha com `password_hash()` e verificação com `password_verify()`.
4. Tabela de usuários com identificador, nome, e-mail único, hash da senha, situação ativa/inativa e datas de criação/atualização.
5. Rotina segura para provisionar a primeira conta e criar contas adicionais sem credenciais padrão ou senha fixa no código.
6. Proteção centralizada das páginas e endpoints administrativos.
7. Configuração segura de sessão: cookies HttpOnly e SameSite, modo estrito, uso exclusivo de cookies e atributo Secure quando a aplicação estiver em HTTPS.
8. Renovação do identificador da sessão após autenticação e encerramento correto da sessão no logout.
9. Mensagens genéricas para falha de autenticação, sem revelar se o e-mail existe.

### Auditoria básica

10. Tabela de auditoria associada ao usuário responsável, à ação, ao tipo de registro afetado, ao identificador desse registro e à data/hora.
11. Registrar pelo menos: login, logout, cadastro de aluno, edição de aluno e exclusão de aluno.
12. A auditoria deve ser gravada no servidor; não confiar em nome ou ID de usuário enviados pelo navegador.
13. Manter o histórico mesmo quando uma conta deixa de ser usada. Por isso, contas serão desativadas em vez de apagadas fisicamente.
14. Não registrar senhas, tokens de sessão ou dados pessoais completos sem necessidade. Nesta primeira versão, o log guarda o tipo de ação e a referência ao registro, não uma cópia integral dos dados antes/depois.

### Testes

15. Testes HTTP de acesso anônimo, login válido, login inválido, logout, permissões e regressão do CRUD/CSRF.
16. Testes que confirmem que cada evento de auditoria foi associado ao usuário autenticado correto.

### Fora do escopo nesta etapa

- Cadastro público de contas.
- Recuperação de senha por e-mail.
- Autenticação em dois fatores.
- Perfis com permissões diferentes.
- Painel completo de consulta/filtragem de auditoria (os registros serão persistidos; uma tela de histórico pode ser planejada depois).
- Integração com provedores externos de identidade.
- Histórico detalhado de cada campo antes/depois.

## Impacto técnico previsto

- `funcoes.php`: inicialização segura da sessão, funções de autenticação, proteção centralizada de rotas e helper de auditoria.
- `login.php` e `logout.php`: novos fluxos públicos de entrada e saída.
- `index.php`, `cadastro.php`, `salvar.php`, `excluir.php` e `configuracoes.php`: exigir sessão autenticada antes de exibir dados ou executar ações.
- `conexao.php` e banco: manter PDO e adicionar tabelas de usuários e auditoria por migração.
- `salvar.php` e `excluir.php`: registrar eventos após operações concluídas com sucesso, associando-os ao usuário da sessão.
- `tests/` e `.github/workflows/ci.yml`: cobrir os fluxos novos e manter os testes atuais.
- `README.md` e documentação local: explicar como preparar o banco, provisionar contas e interpretar os eventos registrados.

A implementação deve preservar os registros existentes na tabela `alunos`. A migração não deve apagar nem recriar dados.

## Segurança e decisões de implementação

- Não usar senha padrão, usuário fixo embutido ou cadastro público de administrador.
- Não registrar senha, token CSRF ou identificador de sessão em logs.
- Aplicar limitação básica de tentativas de login somente se houver uma solução proporcional e testável no ambiente atual; não inventar dependência externa para isso.
- Manter CSRF nos formulários de escrita e também proteger o fluxo de logout.
- Aplicar a verificação de autenticação antes de consultar ou alterar dados administrativos.
- Obter a identidade do autor exclusivamente da sessão validada no servidor.
- Usar transação quando uma operação de negócio e seu evento de auditoria precisarem ser confirmados juntos, para não registrar ação que falhou nem concluir alteração sem o respectivo evento.
- Não confiar em controles visuais ou links ocultos como mecanismo de autorização.
- Não afirmar que o sistema está pronto para produção apenas por adicionar login e auditoria; a revisão de hospedagem, HTTPS, backups e privacidade pertence ao Bloco 08.

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
- A documentação explica como preparar o banco e provisionar contas sem publicar credenciais.

## Sequência sugerida

1. Definir migração de usuários e auditoria, inclusive a política de desativação de contas.
2. Criar o mecanismo seguro de provisionamento inicial e de contas adicionais.
3. Implementar a camada de sessão e autenticação.
4. Criar login/logout e proteger as rotas.
5. Implementar o registro dos eventos de auditoria nos fluxos do CRUD.
6. Expandir os testes HTTP e a CI.
7. Revisar o comportamento, atualizar documentação e validar a PR.
