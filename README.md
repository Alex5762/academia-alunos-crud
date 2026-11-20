# FitControl — Gestão de alunos de academia

Aplicação acadêmica de Desenvolvimento Web para gerenciar cadastros de alunos de uma academia. O projeto evoluiu de um CRUD em PHP e MySQL para um painel administrativo com indicadores, distribuição de alunos por plano e busca.

## Prévia do projeto

As imagens abaixo são capturadas automaticamente pelo GitHub Actions com registros de demonstração fictícios, preparados apenas no banco efêmero do CI.

![Dashboard FitControl em desktop](docs/screenshots/dashboard-desktop.png)

![Dashboard FitControl em tema escuro](docs/screenshots/dashboard-escuro.png)

<p align="center">
  <img src="docs/screenshots/dashboard-mobile.png" alt="Dashboard FitControl em tela mobile" width="280">
  <img src="docs/screenshots/cadastro-desktop.png" alt="Formulário de cadastro de aluno em desktop" width="680">
  <img src="docs/screenshots/configuracoes-escuro.png" alt="Configurações de aparência no tema escuro" width="360">
</p>

<p align="center">
  <img src="docs/screenshots/alunos-mobile.png" alt="Área de alunos cadastrados em tela mobile" width="360">
</p>

> **Escopo atual:** aplicação acadêmica/local com primeira versão de autenticação individual e auditoria. Ainda faltam revisão de segurança para produção, HTTPS de produção, monitoramento e controles adicionais. Não exponha o painel à internet nem use dados reais de alunos. Não exponha o painel à internet nem use dados reais de alunos.

## Funcionalidades

- Dashboard com total de alunos cadastrados.
- Indicador de planos em uso e plano mais frequente.
- Distribuição de alunos por plano.
- Cadastro e edição de alunos.
- Listagem e pesquisa por nome.
- Exclusão com tela de confirmação.
- Validação dos dados no servidor e no navegador, incluindo formato e quantidade de dígitos do telefone.
- Consultas preparadas com PDO.
- Token CSRF nos formulários de escrita.
- Mensagens de retorno e preservação dos dados após erros de validação.
- Layout responsivo para telas menores.
- Preferência entre tema claro e escuro, disponível em Configurações e salva no navegador.
- Contas individuais de funcionários, provisionadas pelo terminal (sem cadastro público).
- Gestão de funcionários: criação de contas, listagem, desativação e reativação sem apagar o histórico.
- Senhas temporárias aleatórias exibidas uma única vez, com troca obrigatória no primeiro acesso.
- Proteção contra desativar a própria conta e contra deixar o sistema sem contas ativas.
- Auditoria de criação, desativação e reativação de contas, identificando o funcionário responsável.
- Login e logout com senha armazenada por hash, sessão protegida e CSRF.
- Limitação persistente de tentativas: após 5 falhas na combinação de e-mail e origem em uma janela de 15 minutos, o acesso dessa combinação fica bloqueado por 15 minutos.
- Troca obrigatória da senha temporária no primeiro acesso, com mínimo de 12 caracteres.
- Bloqueio das páginas administrativas para visitantes não autenticados e contas desativadas.
- Auditoria de login/logout e das operações de cadastro, edição e exclusão de alunos.

Cada aluno possui nome, telefone, e-mail e plano (Mensal, Trimestral, Semestral ou Anual).

## Tecnologias

- PHP 8.2 ou superior, com PDO.
- MySQL 8 ou MariaDB.
- HTML5, CSS3 e JavaScript.
- GitHub Actions para integração contínua.
- Playwright e Chromium para capturas e verificações visuais automatizadas.
- JavaScript e localStorage para persistência da preferência de tema no navegador.
- Shell script, curl e servidor embutido do PHP para testes de integração.

## Estrutura do projeto

```text
academia-alunos-crud/
├── .github/
│   └── workflows/
│       └── ci.yml
├── docs/
│   ├── relatorio-testes-academia-alunos.md
│   └── screenshots/
│       ├── dashboard-desktop.png
│       ├── dashboard-mobile.png
│       ├── alunos-mobile.png
│       └── cadastro-desktop.png
├── tests/
│   ├── capture-screenshots.mjs
│   └── integration-crud.sh
├── banco.sql
├── conexao.php
├── configuracoes.php
├── funcoes.php
├── index.php
├── cadastro.php
├── salvar.php
├── excluir.php
├── style.css
├── script.js
└── theme.js
```

## Como executar localmente com XAMPP

1. Copie ou clone a pasta do projeto em `C:\\xampp\\htdocs\\academia-alunos-crud`.
2. Inicie Apache e MySQL no painel do XAMPP.
3. Acesse o phpMyAdmin em [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/).
4. Importe o arquivo `banco.sql`. Ele cria o banco `academia_alunos` e a tabela `alunos`.
5. Confira as configurações de conexão em `conexao.php`, caso seu XAMPP utilize host, porta, usuário ou senha diferentes.
6. Execute a migração `database/migrations/001_authentication_audit.sql` no banco `academia_alunos` pelo phpMyAdmin (aba SQL).
7. Execute também `database/migrations/002_force_password_change.sql` para exigir a troca da senha temporária no primeiro acesso.\n8. No terminal, a partir da pasta do projeto, execute `php scripts/provisionar-usuario.php`, informe nome e e-mail e copie a senha temporária exibida uma única vez. No XAMPP, se necessário, use `C:\\xampp\\php\\php.exe scripts\\provisionar-usuario.php`.
10. Acesse [http://localhost/academia-alunos-crud/login.php](http://localhost/academia-alunos-crud/login.php) e entre com a conta criada. Entregue a senha temporária por canal seguro.

### Configuração do banco por variáveis de ambiente

O projeto mantém os padrões comuns do XAMPP (host `127.0.0.1`, porta `3306`, banco `academia_alunos`, usuário `root` e senha vazia), mas permite substituir esses valores por variáveis de ambiente:

| Variável | Padrão local |
|---|---|
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_NAME` | `academia_alunos` |
| `DB_USER` | `root` |
| `DB_PASSWORD` | vazio |

Não coloque credenciais reais no repositório. Em ambientes compartilhados, configure os valores no ambiente de execução ou nos secrets do provedor.

## Testes automatizados

O workflow [CI - Academia Alunos CRUD](https://github.com/Alex5762/academia-alunos-crud/actions/workflows/ci.yml) é executado em pushes para `main` e `feat/dashboard-portfolio`, além de pull requests destinados a `main`.

O pipeline:

1. prepara PHP 8.2, Node.js 24, MySQL 8 e Chromium;
2. verifica a sintaxe de todos os arquivos PHP;
3. confere a presença dos arquivos essenciais;
4. importa o schema de `banco.sql` e executa as migrações de autenticação, troca obrigatória de senha e limitação de tentativas;
5. provisiona uma conta fictícia e valida hash, auditoria e unicidade do e-mail;\n6. inicia a aplicação com o servidor embutido do PHP;
7. executa `tests/integration-crud.sh` contra os endpoints reais;
8. prepara registros fictícios no banco temporário e captura as telas desktop e mobile com Playwright;
9. disponibiliza as imagens como artefato e, em pushes para a branch de desenvolvimento, atualiza os arquivos versionados em `docs/screenshots/`.

Os testes de integração cobrem:
- rejeição de token CSRF inválido (HTTP 403);
- validação de e-mail e telefone inválidos, com preservação dos dados do formulário;
- cadastro de aluno;
- pesquisa pelo nome;
- edição e conferência da persistência no MySQL;
- abertura da confirmação por GET sem excluir o registro;
- exclusão por POST e verificação da remoção no banco;
- rejeição de método GET no endpoint de gravação (HTTP 405).

A captura visual também verifica a presença dos registros de demonstração, a ausência de rolagem horizontal na página mobile, a rolagem horizontal contida na tabela de alunos e a persistência da preferência de tema escuro entre páginas.

Para conferir os resultados, consulte a aba **Actions** do GitHub. Uma execução verde confirma apenas os cenários incluídos no workflow; não substitui os testes exploratórios em navegadores e dispositivos físicos.

## Documentação

- [Relatório de testes manuais e automatizados](docs/relatorio-testes-academia-alunos.md)
- [Roadmap e critérios de evolução por blocos](docs/roadmap.md)
- [Planejamento do Bloco 01 — autenticação e auditoria](docs/bloco-01-autenticacao.md)
- [Modelo de dados e migração de autenticação/auditoria](docs/modelo-dados-autenticacao-auditoria.md)

### Gestão de funcionários

Depois de entrar com uma conta ativa, abra **Funcionários** no menu lateral. Informe nome e e-mail para criar uma conta; o sistema gera uma senha temporária e a mostra uma única vez na tela. Copie-a e entregue ao funcionário por canal seguro. No primeiro acesso, ele deverá definir uma senha pessoal com pelo menos 12 caracteres.

Na lista, é possível desativar e reativar outras contas. A própria conta não pode ser desativada, e o sistema bloqueia a operação que deixaria a base sem contas ativas. Contas inativas não conseguem entrar; o histórico de auditoria permanece associado à conta. Nesta versão, todas as contas ativas têm as mesmas permissões administrativas.

## Relação com o material da disciplina

A estrutura PHP partiu do exemplo CRUD de PHP e MySQL do Tutorial 7, adaptando os dados de produtos para alunos. O JavaScript utiliza conceitos básicos de DOM e eventos trabalhados no Tutorial 2.

---

**Projeto:** FitControl — Gestão de alunos de academia  
**Repositório:** [Alex5762/academia-alunos-crud](https://github.com/Alex5762/academia-alunos-crud)


### Limitação de tentativas de login

O login permite até cinco falhas na combinação do e-mail normalizado com o endereço de origem, dentro de uma janela de 15 minutos. A partir da quinta falha, essa combinação fica temporariamente bloqueada por 15 minutos. Um login válido remove o contador correspondente. As mensagens permanecem genéricas para não revelar se uma conta existe.

A migração `database/migrations/003_login_rate_limit.sql` cria a tabela de controle. Ela guarda hashes SHA-256 do e-mail e do endereço de origem, não os valores puros. Esses hashes são dados pseudonimizados, não anônimos; a proteção não substitui HTTPS, monitoramento, controles de borda ou uma política de retenção adequada para produção. O limite é por combinação de e-mail e origem, não um limite global por IP.
