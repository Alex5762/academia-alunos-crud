# Cadastro de Alunos de Academia

Projeto acadêmico de Desenvolvimento Web. Esta base foi montada a partir da estrutura do **Tutorial 7 — PHP e MySQL com XAMPP** do professor, adaptando o exemplo de produtos para alunos.

## Funcionalidades
- Cadastrar alunos.
- Listar alunos.
- Pesquisar pelo nome.
- Editar cadastros.
- Excluir com tela de confirmação.

Cada aluno possui nome, telefone, e-mail e plano.

## Tecnologias
- HTML5 e CSS3.
- JavaScript básico para validação no navegador.
- PHP 8+ com PDO.
- MySQL ou MariaDB.

## Estrutura de arquivos
```
academia-alunos-crud/
├── banco.sql
├── conexao.php
├── funcoes.php
├── index.php
├── cadastro.php
├── salvar.php
├── excluir.php
├── style.css
└── script.js
```

## Como executar com XAMPP
1. Copie a pasta para a pasta `htdocs` do XAMPP.
2. Inicie Apache e MySQL no painel do XAMPP.
3. Acesse `http://localhost/phpmyadmin/`.
4. Importe ou execute o arquivo `banco.sql`.
5. Confira host, porta, banco, usuário e senha em `conexao.php`. O padrão local comum do XAMPP é usuário `root` e senha vazia.
6. Acesse `http://localhost/academia-alunos-crud/`.

Se a pasta dentro de `htdocs` tiver outro nome, ajuste o endereço no navegador.

## Relação com o material do professor
A estrutura PHP segue o exemplo CRUD da pasta `php-mysql-cadastro` (Tutorial 7): conexão PDO, funções auxiliares, validação de ID, token de formulário, consultas preparadas, preservação dos dados em caso de erro e confirmação de exclusão por GET/POST. Os nomes e campos foram adaptados de produtos para alunos. O arquivo `script.js` aplica conceitos de DOM e eventos apresentados no Tutorial 2.

O exemplo `php-mysql-alunos` do repositório do professor também foi consultado, mas ele demonstra somente cadastro e listagem; por isso, não é suficiente sozinho para o CRUD completo pedido no trabalho.

## Próximos passos do grupo
- Ler os arquivos e entender o fluxo de cadastro, edição e exclusão.
- Executar e testar cada operação no XAMPP.
- Personalizar o visual e os campos somente após decisão do grupo.
- Preparar documentação, prints reais e divisão verdadeira das tarefas.

Esta é uma base de estudo, ainda precisa ser executada e testada no ambiente PHP/MySQL antes de ser considerada concluída.
