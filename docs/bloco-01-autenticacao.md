# Bloco 01 — Autenticação, contas individuais e auditoria

## Objetivo

Exigir autenticação antes de permitir acesso ao painel e às operações administrativas do FitControl. Cada funcionário terá sua própria conta, permitindo atribuir ações a uma pessoa específica e consultar um histórico básico de atividade.

**Estado:** implementado e validado pela CI em 20/11/2026 no commit `7ff2a9da84a20e0fe160d43fd9f30b681e0023d6`. [Run de Push #211 — sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44855484995) e [Run da PR #212 — sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44855491003).

## Diagnóstico da base atual

- `funcoes.php` agora configura a sessão, mantém o token CSRF e centraliza as verificações de autenticação.
- Dashboard, cadastro, configurações e endpoints de escrita agora exigem autenticação.
- `configuracoes.php` também está protegida pela sessão.
- As operações de escrita já validam método HTTP e token CSRF.
- O banco mantém a tabela `alunos` e adiciona `usuarios`, `auditoria` e `login_tentativas` pelas migrações 001, 002 e 003.
- A CI cobre autenticação, gestão de contas, revogação de sessão, autoria das ações, expiração, renovação e interface; a execução do commit atual passou.

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

## Migração 002 — troca obrigatória de senha\n\nA migração `database/migrations/002_force_password_change.sql` adiciona `usuarios.deve_trocar_senha`, com valor padrão `1`. Assim, contas novas e contas já existentes devem definir uma senha pessoal antes de acessar o painel. Execute-a depois da migração 001.\n\nO provisionador marca explicitamente novas contas para troca no primeiro acesso. O login redireciona essas contas para `trocar-senha.php`; a tela exige a senha temporária atual, uma nova senha com pelo menos 12 caracteres e confirmação. A alteração atualiza o hash, remove a exigência e registra o evento `senha_alterada_primeiro_acesso` na mesma transação.\n\n## Provisionamento de contas implementado

O script `scripts/provisionar-usuario.php` cria uma conta a partir do terminal, solicita nome e e-mail, normaliza o e-mail para minúsculas, valida os campos e gera uma senha temporária criptograficamente aleatória. A senha é exibida uma única vez no terminal e somente seu hash, gerado com `password_hash()`, é persistido. A inserção do usuário e o evento `usuario_provisionado_cli` são confirmados na mesma transação. O script recusa execução via HTTP e não aceita senha por argumento ou variável de ambiente.

Exemplo no terminal do projeto:

```bash
php scripts/provisionar-usuario.php
```

No XAMPP para Windows, execute no Prompt de Comando ou PowerShell a partir da pasta do projeto, usando o PHP do XAMPP se ele não estiver no PATH, por exemplo `C:\\xampp\\php\\php.exe scripts\\provisionar-usuario.php`. Copie a senha temporária e entregue-a ao funcionário por canal seguro; o funcionário deverá substituí-la obrigatoriamente no primeiro acesso. Não publique nem envie a senha em commits, issues ou mensagens de equipe.

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

**Limitações conhecidas desta primeira versão:** todas as contas ativas possuem as mesmas permissões administrativas; não há separação entre administrador e funcionário. A limitação persistente de tentativas de login foi adicionada na migração 003. Ainda não há recuperação de senha por e-mail nem MFA. O provisionador mostra a senha uma única vez; trate-a como segredo e entregue por canal seguro. Não exponha o sistema publicamente até concluir a revisão de segurança e HTTPS.

## Limitação de tentativas de login — implementação

A migração `database/migrations/003_login_rate_limit.sql` cria a tabela `login_tentativas`, com chave única por hash do e-mail normalizado e hash do endereço de origem. Não armazena os valores puros; os hashes são pseudonimizados, não anônimos.

- Cinco falhas dentro de uma janela de 15 minutos ativam bloqueio temporário de 15 minutos para aquela combinação.
- Tentativas recebidas durante o bloqueio mantêm a resposta genérica e não incrementam o contador.
- Um login válido limpa o contador da combinação correspondente.
- O incremento é feito por `INSERT ... ON DUPLICATE KEY UPDATE` para evitar perda de incrementos concorrentes.
- Registros sem atividade por mais de um dia são removidos durante requisições válidas de login.
- O controle é por e-mail + origem, não é um bloqueio global por IP e não impede ataques distribuídos com várias origens.

A CI executa a migração 003 e os testes cobrem quatro falhas, bloqueio na quinta, manutenção do bloqueio na sexta e limpeza após login válido.

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

## Estado de validação e pendências

A CI do commit \`7ff2a9da84a20e0fe160d43fd9f30b681e0023d6\` passou em 20/11/2026. Foram aprovados os testes PHP, migrações, provisionamento, integração HTTP, autenticação, auditoria, gestão de contas, expiração/renovação de sessão e Playwright.

Antes de concluir a revisão da PR, ainda falta a inspeção visual manual das telas em navegador e dispositivo real, incluindo foco por teclado, contraste e legibilidade nos temas claro/escuro.

Limitações mantidas fora do escopo desta versão: todas as contas têm a mesma permissão administrativa; não há MFA nem recuperação de senha por e-mail. A aplicação não deve ser considerada pronta para produção sem revisão independente de segurança, HTTPS, backups, monitoramento e revisão de privacidade.

## Gestão de contas — implementação

A interface `usuarios.php` permite listar contas, criar novos funcionários e desativar/reativar contas existentes. Os endpoints de escrita (`salvar-usuario.php` e `alterar-status-usuario.php`) aceitam somente POST, exigem sessão autenticada e validam CSRF.

- A criação valida nome e e-mail, normaliza o e-mail, gera senha temporária criptograficamente aleatória, persiste somente o hash e exige troca no primeiro acesso.
- A senha temporária é mantida temporariamente na sessão do criador para ser apresentada uma única vez após o redirecionamento; depois é removida da sessão. Não é persistida na tabela de usuários nem incluída na auditoria. O armazenamento físico da sessão depende do handler configurado no PHP.
- A desativação não remove a conta nem os eventos históricos.
- Não é permitido desativar a própria conta nem a última conta ativa.
- Criação, desativação e reativação são registradas na tabela `auditoria`, com o usuário autenticado como autor e a conta afetada como entidade.
- Contas inativas são rejeitadas no login e sessões existentes perdem acesso na próxima requisição protegida.

Todas as contas continuam com a mesma permissão administrativa, conforme a decisão funcional desta etapa. A separação por cargos/permissões permanece fora do escopo.

### Cenários automatizados adicionados

- Criação de funcionário e persistência de hash/troca obrigatória.
- Login inicial da nova conta e definição da senha pessoal.
- Desativação e rejeição de login da conta inativa.
- Reativação e verificação dos eventos de auditoria.
- Bloqueio de tentativa de desativar a própria conta.


## Expiração de sessão — implementação

A camada central em \`funcoes.php\` aplica dois limites a sessões autenticadas:

- **Inatividade:** 30 minutos sem requisições autenticadas.
- **Duração absoluta:** 8 horas desde o login, mesmo que a conta continue ativa no navegador.

O timestamp de autenticação é definido no login e não é renovado por atividade. O timestamp de último acesso é atualizado após confirmar no banco que a conta continua ativa. Sessões antigas sem os dois marcadores, timestamps futuros ou sessões fora dos limites são invalidadas.

Quando expira, os dados autenticados são limpos, o identificador de sessão é regenerado, um novo token CSRF é criado e a tela de login informa que será necessário entrar novamente. O cookie continua sendo de sessão, HttpOnly, SameSite=Lax e Secure quando HTTPS é detectado. O \`session.gc_maxlifetime\` foi alinhado ao limite absoluto de oito horas; a expiração efetiva é feita pela validação da aplicação, não depende apenas da coleta de lixo do PHP.

A CI testa os dois limites com sessões de teste independentes, sem alterar a sessão usada pelos testes de CRUD.


### Aviso antecipado e renovação da sessão

O monitor da interface apresenta um aviso após 25 minutos sem interação, com uma contagem regressiva de cinco minutos e a ação **Continuar conectado**. A confirmação envia uma requisição POST protegida por CSRF ao endpoint renovar-sessao.php; o servidor renova o prazo de inatividade somente depois de validar a sessão e confirmar que a conta continua ativa.

Durante a utilização, eventos reais de interação na página atualizam o marcador local de atividade. No máximo uma vez por minuto, enquanto a pessoa continua interagindo, o navegador envia uma confirmação autenticada ao servidor. Isso evita expiração durante o preenchimento prolongado de formulários. Deixar uma página aberta sem interação não renova a sessão.

Cinco minutos antes do limite absoluto de oito horas, a interface exibe um aviso separado para salvar o trabalho. A confirmação desse aviso apenas o dispensa; ela não estende o limite absoluto. A expiração continua sendo aplicada no servidor, independentemente do JavaScript.

Os testes de integração verificam renovação válida, rejeição de CSRF inválido, rejeição de método GET e continuidade do acesso após renovação. O Playwright avança o relógio para verificar os avisos de inatividade e de duração absoluta.


## Alteração voluntária de senha após o primeiro acesso

A tela `alterar-senha.php` fica disponível em **Configurações → Segurança da conta** para funcionários que já concluíram a troca obrigatória da senha temporária.

- Exige a senha atual e valida sua correspondência com o hash armazenado.
- Exige nova senha com pelo menos 12 caracteres e confirmação correspondente.
- Rejeita a reutilização da senha atual.
- Atualiza o hash e registra `senha_alterada` na mesma transação.
- Regenera o identificador de sessão e o token CSRF após a alteração; o limite absoluto de oito horas continua o mesmo.
- Testes cobrem senha atual incorreta, senha curta, confirmação divergente, reutilização, alteração válida, rejeição da senha antiga, login com a nova senha e auditoria.

A navegação mobile possui rolagem horizontal com indicação visual, pode receber foco pelo teclado e é validada pelo Playwright. A revisão de contraste também ajusta os rótulos dos gráficos no tema escuro.
