# Bloco 01 — Autenticação, contas individuais e auditoria

## Objetivo

Exigir autenticação antes de permitir acesso ao painel e às operações administrativas do FitControl. Cada funcionário terá sua própria conta, permitindo atribuir ações a uma pessoa específica e consultar um histórico básico de atividade.

**Estado:** contas individuais confirmadas; migração validada na CI; provisionador CLI implementado; primeira versão do login/logout, proteção de rotas e auditoria do CRUD registrada na branch. A validação automatizada dessa integração está pendente.

## Diagnóstico da base atual

- `funcoes.php` agora configura a sessão, mantém o token CSRF e centraliza as verificações de autenticação.
- Dashboard, cadastro, configurações e endpoints de escrita agora exigem autenticação.
- `configuracoes.php` também está protegida pela sessão.
- As operações de escrita já validam método HTTP e token CSRF.
- O banco atual contém somente a tabela `alunos`; não existe estrutura de usuários nem de auditoria.
- A CI foi ampliada para cobrir autenticação, revogação de sessão e autoria das ações; o novo fluxo aguarda execução verde.

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

A migração cria somente as novas tabelas e não cria contas. A CI importa o schema original e executa a migração em um MySQL limpo. A validação passou no workflow Run #52.

## Provisionamento de contas implementado

O script `scripts/provisionar-usuario.php` cria uma conta a partir do terminal, solicita nome e e-mail, normaliza o e-mail para minúsculas, valida os campos e gera uma senha temporária criptograficamente aleatória. A senha é exibida uma única vez no terminal e somente seu hash, gerado com `password_hash()`, é persistido. A inserção do usuário e o evento `usuario_provisionado_cli` são confirmados na mesma transação. O script recusa execução via HTTP e não aceita senha por argumento ou variável de ambiente.

Exemplo no terminal do projeto:

```bash
php scripts/provisionar-usuario.php
```

No XAMPP para Windows, execute no Prompt de Comando ou PowerShell a partir da pasta do projeto, usando o PHP do XAMPP se ele não estiver no PATH, por exemplo `C:\\xampp\\php\\php.exe scripts\\provisionar-usuario.php`. Copie a senha temporária e entregue-a ao funcionário por canal seguro; o funcionário deverá alterá-la após o login, quando o fluxo de troca de senha for implementado. Não publique nem envie a senha em commits, issues ou mensagens de equipe.

O teste `tests/provision-user.sh` verifica a criação, a validação do hash, o evento de auditoria e a rejeição de e-mail duplicado. Ele usa uma conta fictícia no banco efêmero da CI.

## Autenticação e auditoria — primeira implementação

- `funcoes.php` configura sessão com cookies HttpOnly, SameSite=Lax, modo estrito e transporte somente por cookie; o atributo Secure é ativado quando a requisição usa HTTPS.
- `login.php` valida credenciais com `password_verify()`, rejeita contas inativas com mensagem genérica e renova o ID de sessão no sucesso.
- `logout.php` aceita somente POST com CSRF, registra o evento e invalida a sessão/cookie.
- `exigirAutenticacao()` consulta a conta ativa no banco em cada requisição protegida; a desativação de uma conta invalida o acesso na próxima requisição.
- Dashboard, cadastro, configurações, salvamento e exclusão exigem sessão autenticada.
- O dashboard apresenta nome e e-mail do funcionário autenticado e oferece logout.
- Cadastro, edição e exclusão de alunos gravam evento de auditoria na mesma transação da alteração de dados.
- O fluxo de testes agora prevê acesso anônimo, login inválido/válido, CRUD, autoria dos três eventos e logout. O Playwright autentica antes das capturas.

**Limitações conhecidas desta primeira versão:** ainda não há tela para administrar/desativar contas; a desativação deve ser feita diretamente no banco por operador autorizado. Também não há limitação persistente de tentativas de login nem fluxo de troca obrigatória da senha temporária. O provisionador mostra a senha uma única vez; trate-a como segredo e entregue por canal seguro. Não exponha o sistema publicamente até concluir a revisão de segurança e HTTPS.

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

1. Implementar e revisar o provisionamento seguro de contas (primeira conta e contas adicionais) — primeira versão implementada e coberta na CI.
2. Revisar a camada de sessão, login/logout e proteção de rotas com a CI.
3. Completar gestão segura de contas, desativação e troca de senha temporária.
4. Expandir a auditoria e os testes de regressão.
6. Revisar o comportamento, atualizar documentação e validar a PR.
