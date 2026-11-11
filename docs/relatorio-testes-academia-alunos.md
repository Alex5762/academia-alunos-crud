# Relatório de testes - Academia de Alunos (CRUD)

**Responsável:** Joseph Alexsander  
**Data da execução:** 11/11/2026  
**Repositório:** [Alex5762/academia-alunos-crud](https://github.com/Alex5762/academia-alunos-crud)

## 1. Objetivo

Registrar a preparação do ambiente local e os testes funcionais executados na aplicação de cadastro de alunos da academia, verificando as operações CRUD (Create, Read, Update e Delete) e a persistência dos dados no MySQL.

## 2. Ambiente utilizado

- Sistema operacional: Windows.
- Ambiente local: XAMPP.
- Servidor web: Apache 2.4.58 (Win64), porta 80.
- PHP identificado no cabeçalho do servidor: 8.2.12.
- Banco de dados: MySQL, acessado pelo phpMyAdmin.
- Editor: Visual Studio Code.
- Diretório esperado do projeto: `C:\\xampp\\htdocs\\academia-alunos-crud`.
- Endereço local da aplicação: `http://localhost/academia-alunos-crud/`.
- Endereço do phpMyAdmin: `http://localhost/phpmyadmin/`.

## 3. Preparação e configuração

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

## 4. Testes funcionais executados

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

## 5. Resultado consolidado

| Operação | Resultado |
|---|---|
| Create - cadastrar aluno | Aprovado |
| Read - listar alunos | Aprovado |
| Read - pesquisar por nome | Aprovado |
| Update - editar aluno | Aprovado |
| Delete - confirmar e excluir aluno | Aprovado |
| Verificar exclusão diretamente no MySQL | Aprovado |

Ao final dos testes, a tabela `alunos` estava sem registros, conforme a consulta executada no phpMyAdmin após a exclusão do aluno de teste.

## 6. Pontos ainda pendentes

Os testes básicos do fluxo CRUD foram concluídos. Para ampliar a validação, ainda é recomendável executar:

1. Tentar salvar o formulário com campos obrigatórios vazios.
2. Testar um endereço de e-mail em formato inválido.
3. Abrir a tela de confirmação de exclusão e cancelar a operação, verificando que o registro permanece.
4. Revisar a validação e a formatação do telefone. Na captura da listagem, o número de teste pareceu longo; convém confirmar o limite e o formato esperado.
5. Repetir os testes com mais de um registro e atualizar a página após cada operação para verificar a persistência.

Esses itens estão **pendentes** e não são contabilizados como aprovados neste relatório.

## 7. Conclusão

O ambiente local com Windows e XAMPP foi preparado, a aplicação PHP foi executada pelo Apache e a conexão com o banco MySQL foi estabelecida após a criação e conferência do banco `academia_alunos` e da tabela `alunos`.

Os testes manuais realizados confirmaram o funcionamento básico das quatro operações CRUD. A listagem e a pesquisa responderam conforme os dados cadastrados, a edição foi testada e a exclusão foi confirmada também diretamente no banco de dados.

**Conclusão:** o fluxo principal do sistema foi validado em ambiente local. A validação complementar dos campos e dos cenários de cancelamento permanece pendente.

---

**Autor:** Joseph Alexsander  
**Projeto:** Academia de Alunos - CRUD  
