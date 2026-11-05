# Testes do projeto

Este documento explica, de forma simples, como executar e testar o cadastro de
alunos.

## Pré-requisitos

- PHP 8 ou superior.
- Apache, PHP e MySQL/MariaDB instalados.
- Extensão `pdo_mysql` habilitada no PHP.

## Preparar o banco de dados

1. Inicie o Apache e o MySQL/MariaDB.
2. Crie o banco usando o arquivo `banco.sql`.

Pelo terminal, também é possível executar:

```bash
mysql -u root -p < banco.sql
```

3. Confira os dados de conexão em `conexao.php`:

```php
$host = '127.0.0.1';
$porta = '3306';
$banco = 'academia_alunos';
$usuario = 'root';
$senha = '';
```

Altere esses valores se a instalação local usar outra configuração.

## Executar o projeto

### Usando XAMPP

1. Copie a pasta do projeto para `htdocs`.
2. Inicie Apache e MySQL no XAMPP.
3. Acesse:

```text
http://localhost/academia-alunos-crud/
```

### Usando o servidor interno do PHP

Na pasta do projeto, execute:

```bash
php -S 127.0.0.1:8080
```

Depois, abra `http://127.0.0.1:8080`.

## Roteiro de teste manual

1. Abra a página inicial e confirme que a lista aparece.
2. Cadastre um aluno com nome, telefone, e-mail e plano válidos.
3. Confirme se o aluno aparece na lista.
4. Pesquise parte do nome e verifique o resultado.
5. Edite o aluno e confirme se os dados foram atualizados.
6. Tente salvar dados inválidos e confirme se as mensagens de erro aparecem.
7. Abra a opção de exclusão, cancele e confirme que o aluno continua na lista.
8. Exclua o aluno e confirme a mensagem de sucesso.

## Testes automatizados realizados

Foram executadas as seguintes verificações:

```bash
for f in *.php; do php -l "$f"; done
node --check script.js
git diff --exit-code
```

Resultado:

- Todos os arquivos PHP passaram na verificação de sintaxe.
- O JavaScript passou na verificação de sintaxe.
- O repositório permaneceu sem alterações durante os testes.
- As funções auxiliares de escape de HTML e validação de ID também foram
  verificadas.

## Limitação encontrada no ambiente de teste

O teste HTTP da página inicial retornou erro `500` porque o ambiente não tinha:

- servidor MySQL/MariaDB disponível;
- extensão PHP `pdo_mysql` habilitada.

O PHP possuía apenas PDO e `pdo_sqlite`. Por isso, o CRUD completo não pôde ser
testado contra o banco nesse ambiente. Depois de instalar o MySQL/MariaDB e
habilitar `pdo_mysql`, repita o roteiro manual acima.
