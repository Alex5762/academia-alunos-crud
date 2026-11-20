# Bloco 01 — Autenticação e proteção do painel

## Objetivo

Exigir autenticação antes de permitir acesso ao painel e às operações administrativas do FitControl, sem comprometer o CRUD existente nem introduzir complexidade desnecessária.

**Estado:** planejamento iniciado. A decisão sobre o modelo de contas precisa ser confirmada antes da implementação.

## Diagnóstico da base atual

- `funcoes.php` inicia a sessão e mantém o token CSRF, mas não aplica configuração explícita de segurança da sessão.
- `index.php`, `cadastro.php`, `salvar.php` e `excluir.php` não exigem autenticação.
- `configuracoes.php` também não possui verificação de acesso.
- As operações de escrita já validam método HTTP e token CSRF.
- O banco atual contém somente a tabela `alunos`; não existe estrutura de usuários.
- A CI exercita o CRUD, mas ainda não cobre autenticação.

## Decisão funcional pendente

**Recomendação:** permitir várias contas individuais, uma por funcionário, todas com a mesma permissão administrativa nesta primeira versão.

Isso permite que recepcionistas tenham credenciais próprias sem introduzir níveis como administrador, recepção e instrutor antes de haver regras concretas para cada perfil.

A recomendação ainda precisa ser confirmada. Não haverá cadastro público de usuários.

## Escopo proposto

### Incluído

1. Tela de login com identificação por e-mail e senha.
2. Logout por requisição POST com proteção CSRF.
3. Armazenamento de senha com `password_hash()` e verificação com `password_verify()`.
4. Tabela de usuários com identificador, nome, e-mail único, hash da senha e datas de criação/atualização.
5. Rotina de provisionamento de conta administrativa sem credenciais padrão ou senha fixa no código.
6. Proteção centralizada das páginas e endpoints administrativos.
7. Configuração segura de sessão: cookies HttpOnly e SameSite, modo estrito, uso exclusivo de cookies e atributo Secure quando a aplicação estiver em HTTPS.
8. Renovação do identificador da sessão após autenticação e encerramento correto da sessão no logout.
9. Mensagens genéricas para falha de autenticação, sem revelar se o e-mail existe.
10. Testes HTTP de acesso anônimo, login válido, login inválido, logout e regressão do CRUD/CSRF.

### Fora do escopo nesta etapa

- Cadastro público de contas.
- Recuperação de senha por e-mail.
- Autenticação em dois fatores.
- Perfis com permissões diferentes.
- Gestão de funcionários, convites ou painel de administração de usuários.
- Integração com provedores externos de identidade.

## Impacto técnico previsto

- `funcoes.php`: inicialização segura da sessão, funções de autenticação e proteção centralizada de rotas.
- `login.php` e `logout.php`: novos fluxos públicos de entrada e saída.
- `index.php`, `cadastro.php`, `salvar.php`, `excluir.php` e `configuracoes.php`: exigir sessão autenticada antes de exibir dados ou executar ações.
- `conexao.php` e banco: manter PDO e adicionar a estrutura de usuários por migração.
- `tests/` e `.github/workflows/ci.yml`: cobrir os fluxos novos e manter os testes atuais.
- `README.md` e documentação local: explicar a criação inicial de uma conta e a configuração necessária.

A implementação deve preservar os registros existentes na tabela `alunos`. A migração não deve apagar nem recriar dados.

## Segurança e decisões de implementação

- Não usar senha padrão, usuário fixo embutido ou cadastro público de administrador.
- Não registrar senha em logs, mensagens ou documentação.
- Aplicar limitação básica de tentativas de login somente se houver uma solução proporcional e testável no ambiente atual; não inventar dependência externa para isso.
- Manter CSRF nos formulários de escrita e também proteger o fluxo de logout.
- Aplicar a verificação de autenticação antes de consultar ou alterar dados administrativos.
- Não confiar em controles visuais ou links ocultos como mecanismo de autorização.
- Não afirmar que o sistema está pronto para produção apenas por adicionar login; a revisão de hospedagem, HTTPS, backups e privacidade pertence ao Bloco 08.

## Critérios de aceite

- Usuário sem sessão não consegue abrir páginas administrativas nem executar endpoints protegidos.
- Login válido cria uma sessão autenticada e renova o identificador de sessão.
- Login inválido retorna mensagem genérica e não autentica.
- Logout invalida a sessão e impede reutilização do acesso.
- Senhas são armazenadas apenas como hash.
- Cookies e configurações da sessão seguem os requisitos definidos para ambiente local e HTTPS.
- O CRUD, as validações e a proteção CSRF continuam funcionando após o login.
- A CI executa os cenários de autenticação e regressão com sucesso.
- A documentação explica como preparar o banco e provisionar a primeira conta sem publicar credenciais.

## Sequência sugerida

1. Confirmar o modelo de contas.
2. Definir a migração e o provisionamento inicial.
3. Implementar a camada de sessão e autenticação.
4. Criar login/logout e proteger as rotas.
5. Expandir os testes HTTP e a CI.
6. Revisar o comportamento, atualizar documentação e validar a PR.
