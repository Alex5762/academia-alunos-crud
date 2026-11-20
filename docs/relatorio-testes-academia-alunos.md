# Relatório de testes - Academia de Alunos (CRUD)

**Responsável:** Joseph Alexsander  
**Repositório:** [Alex5762/academia-alunos-crud](https://github.com/Alex5762/academia-alunos-crud)

Este documento mantém o registro dos testes manuais realizados em 11/11/2026 e acrescenta, separadamente, a validação automatizada executada em 20/11/2026. Os resultados de cada modalidade não devem ser confundidos.

## 1. Objetivo

Registrar a preparação do ambiente local e os testes funcionais executados na aplicação de cadastro de alunos da academia, verificando as operações CRUD (Create, Read, Update e Delete), a validação dos dados e a persistência no MySQL.

## 2. Ambiente do teste manual - 11/11/2026

- Sistema operacional: Windows.
- Ambiente local: XAMPP.
- Servidor web: Apache 2.4.58 (Win64), porta 80.
- PHP identificado no cabeçalho do servidor: 8.2.12.
- Banco de dados: MySQL, acessado pelo phpMyAdmin.
- Editor: Visual Studio Code.
- Diretório esperado do projeto: `C:\\xampp\\htdocs\\academia-alunos-crud`.
- Endereço local da aplicação: `http://localhost/academia-alunos-crud/`.
- Endereço do phpMyAdmin: `http://localhost/phpmyadmin/`.

## 3. Preparação e configuração manual

1. O repositório foi clonado e aberto no Visual Studio Code.
2. Os arquivos principais foram conferidos no terminal: `index.php`, `conexao.php`, `banco.sql`, `cadastro.php`, `salvar.php`, `excluir.php` e `funcoes.php`.
3. O projeto foi colocado no diretório `htdocs` do XAMPP.
4. Os serviços Apache e MySQL foram iniciados pelo XAMPP Control Panel.
5. O endereço `http://localhost/dashboard/` abriu a página do XAMPP, confirmando que o Apache estava atendendo na porta 80.
6. O phpMyAdmin abriu normalmente.

### 3.1 Configuração do banco

Na primeira abertura da aplicação, foi apresentada a mensagem de falha de conexão indicando que o MySQL, o banco e/ou a configuração de `conexao.php` precisavam ser verificados.

Em seguida, o arquivo `banco.sql` foi importado pelo phpMyAdmin. A saída da importação exibiu mensagens `#1046 - Nenhum banco de dados foi selecionado` em parte do retorno; por isso, a estrutura final foi conferida diretamente no phpMyAdmin antes de continuar.

A conferência confirmou:

- Banco de dados: `academia_alunos`.
- Tabela: `alunos`.
- Colunas:
  - `id`: `INT UNSIGNED`, chave primária e `AUTO_INCREMENT`.
  - `nome`: `VARCHAR(100)`.
  - `telefone`: `VARCHAR(20)`.
  - `email`: `VARCHAR(150)`.
  - `plano`: `VARCHAR(40)`.

Depois da criação e conferência da estrutura, a aplicação passou a abrir e conectar ao banco.

## 4. Testes funcionais manuais - 11/11/2026

### 4.1 Create - cadastro de aluno

**Procedimento:** foi preenchido o formulário com um registro fictício de teste e acionado o botão de cadastro.

**Resultado observado:** a aplicação exibiu a mensagem **“Aluno cadastrado com sucesso!”** e o registro apareceu na tabela da página principal, com ID gerado automaticamente.

**Status:** aprovado.

### 4.2 Read - listagem e pesquisa

**Procedimento:** foi verificada a listagem e utilizada a pesquisa por nome.

**Resultados observados:**
- A listagem exibiu o aluno cadastrado.
- A pesquisa por “João” encontrou o registro.
- A pesquisa por “Ana” não retornou registro, pois não havia aluna com esse nome cadastrada.

**Status:** aprovado.

### 4.3 Update - edição

**Procedimento:** foi acionada a opção **Editar** no registro e o fluxo de edição foi testado.

**Resultado informado durante a execução:** a edição funcionou corretamente.

**Status:** aprovado pelo teste manual informado.

### 4.4 Delete - exclusão

**Procedimento:** foi acionada a opção **Excluir**. A aplicação apresentou uma tela de confirmação antes de remover o registro. A exclusão foi confirmada.

**Verificação no banco:** no phpMyAdmin, a consulta `SELECT * FROM alunos` retornou um conjunto vazio (zero registros), confirmando que o registro foi removido da tabela.

**Status:** aprovado.

## 5. Resultado consolidado - teste manual

| Operação | Resultado |
|---|---|
| Create - cadastrar aluno | Aprovado |
| Read - listar alunos | Aprovado |
| Read - pesquisar por nome | Aprovado |
| Update - editar aluno | Aprovado |
| Delete - confirmar e excluir aluno | Aprovado |
| Verificar exclusão diretamente no MySQL | Aprovado |

Ao final dos testes manuais, a tabela `alunos` estava sem registros, conforme a consulta executada no phpMyAdmin após a exclusão do aluno de teste.

## 6. Testes automatizados de integração - 20/11/2026

### 6.1 Ambiente utilizado

- GitHub Actions, runner Ubuntu.
- PHP 8.2 com extensão `pdo_mysql`.
- Serviço MySQL 8.0.
- Servidor embutido do PHP para disponibilizar a aplicação por HTTP.
- Script `tests/integration-crud.sh`, executado com Bash e curl.
- Commit testado: `2e32105982a3c52ca153f1b9c687ea56320346da`.
- Execução: [GitHub Actions - Run #4](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44728236912).

### 6.2 Cenários automatizados

| Cenário | Verificação | Resultado |
|---|---|---|
| Token CSRF inválido | Endpoint de gravação responde HTTP 403 | Aprovado |
| E-mail inválido | Servidor rejeita o dado e preserva os dados preenchidos | Aprovado |\n| Telefone inválido | Servidor rejeita letras e quantidade insuficiente de dígitos | Aprovado |
| Create | Cadastro pelo endpoint `salvar.php` e geração de ID | Aprovado |
| Read | Pesquisa do aluno pelo endpoint `index.php` | Aprovado |
| Update | Edição pelo endpoint `salvar.php` e conferência no MySQL | Aprovado |
| Confirmação de exclusão | Requisição GET exibe confirmação e mantém o registro | Aprovado |
| Delete | Exclusão via POST e verificação da remoção no MySQL | Aprovado |\n| Método HTTP | Endpoint de gravação rejeita GET com HTTP 405 | Aprovado |

**Resultado da execução:** o job `PHP syntax and CRUD integration` finalizou com conclusão `success`. As etapas de sintaxe PHP, arquivos obrigatórios, importação do schema e execução dos sete blocos do teste de integração foram concluídas com sucesso.

O workflow também verifica a conexão com o MySQL por meio da aplicação, pois os endpoints PHP carregam o arquivo `conexao.php` configurado com variáveis de ambiente.

### 6.3 Limites da validação automatizada

A execução confirma os cenários explicitamente descritos acima. Ela não representa cobertura completa de todos os caminhos possíveis, não substitui testes manuais em diferentes navegadores ou tamanhos de tela e não verifica autenticação de usuários, pois o projeto ainda não implementa login.

## 7. Pendências conhecidas

Continuam recomendados para uma próxima rodada de testes:

1. Verificar no navegador a experiência de campos obrigatórios vazios, incluindo mensagens e foco nos campos.
2. Revisar a validação e a formatação do telefone. Na captura da listagem manual, o número de teste pareceu longo; convém confirmar o formato esperado.
3. Repetir os testes visuais em diferentes larguras de tela e navegadores.
4. Planejar autenticação e controle de acesso antes de disponibilizar o painel em ambiente público.

Os testes de e-mail inválido, token CSRF inválido, preservação de dados, confirmação sem exclusão via GET e persistência do CRUD já estão cobertos pela automação de 20/11/2026 e não devem ser tratados como pendentes.

## 8. Conclusão

Os testes manuais de 11/11/2026 registraram o funcionamento básico do CRUD no ambiente local Windows/XAMPP. Em 20/11/2026, a integração contínua foi ampliada para executar a aplicação PHP real conectada ao MySQL e validar, por HTTP, cadastro, pesquisa, edição, validação de e-mail e telefone, proteção CSRF, rejeição de método HTTP indevido e exclusão.

**Conclusão:** os principais fluxos do CRUD foram aprovados tanto no registro manual quanto na execução automatizada do GitHub Actions. Permanecem a revisão visual em navegadores e a implementação de autenticação antes de qualquer exposição pública do painel.

---

**Autor:** Joseph Alexsander  
**Projeto:** FitControl — Gestão de alunos de academia
