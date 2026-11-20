# Relatório de testes — FitControl

**Responsável:** Joseph Alexsander  
**Repositório:** [Alex5762/academia-alunos-crud](https://github.com/Alex5762/academia-alunos-crud)

Este documento separa os testes manuais realizados em 11/11/2026 da validação automatizada realizada em 20/11/2026.

## 1. Objetivo

Registrar os testes do CRUD de alunos, validação dos dados, persistência no MySQL e verificações automatizadas da interface.

## 2. Testes manuais — 11/11/2026

**Ambiente:** Windows, XAMPP, Apache 2.4.58, PHP 8.2.12, MySQL, phpMyAdmin e Visual Studio Code.

O projeto foi colocado em `C:\\xampp\\htdocs\\academia-alunos-crud`, com Apache e MySQL iniciados pelo XAMPP. Após a importação de `banco.sql`, a estrutura foi conferida no phpMyAdmin.

Estrutura confirmada:
- Banco: `academia_alunos`.
- Tabela: `alunos`.
- Colunas: `id` (INT UNSIGNED, PK, AUTO_INCREMENT), `nome` (VARCHAR 100), `telefone` (VARCHAR 20), `email` (VARCHAR 150) e `plano` (VARCHAR 40).

| Operação | Resultado observado |
|---|---|
| Create — cadastrar aluno | Aprovado; mensagem de sucesso e ID gerado |
| Read — listar alunos | Aprovado |
| Read — pesquisar por nome | Aprovado; “João” encontrou o registro e “Ana” não retornou resultado |
| Update — editar aluno | Aprovado conforme teste manual informado |
| Delete — confirmar e excluir | Aprovado |
| Conferir exclusão no MySQL | Aprovado; consulta retornou zero registros |

## 3. Integração HTTP automatizada — 20/11/2026

**Ambiente:** GitHub Actions (Ubuntu), PHP 8.2 com `pdo_mysql`, MySQL 8.0, Node.js 24, Playwright 1.65.1, Chromium e servidor embutido do PHP.

Script: `tests/integration-crud.sh`.

### 3.1 Cenários executados

| Cenário | Verificação | Resultado |
|---|---|---|
| CSRF | Token inválido retorna HTTP 403 | Aprovado |
| E-mail inválido | Rejeição no servidor e preservação dos dados | Aprovado |
| Telefone inválido | Rejeição de letras e quantidade insuficiente de dígitos | Aprovado |
| Create | Cadastro via `salvar.php` e geração de ID | Aprovado |
| Read | Pesquisa via `index.php` | Aprovado |
| Update | Edição via `salvar.php` e conferência no MySQL | Aprovado |
| Confirmação de exclusão | GET exibe confirmação e mantém o registro | Aprovado |
| Delete | POST remove o registro e o MySQL confirma | Aprovado |
| Método HTTP | GET em `salvar.php` retorna HTTP 405 | Aprovado |

**Total: nove verificações funcionais aprovadas.** O script possui etapas numeradas de 1/8 a 8/8; algumas etapas agrupam mais de uma asserção. A última etapa apenas encerra e resume a execução, não sendo um cenário adicional.

### 3.2 Execuções

- [Run 44779978743 — execução por push](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44779978743): job de integração/captura concluído com sucesso; job de publicação das capturas também concluído com sucesso.
- [Run 44779990730 — execução da pull request](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44779990730): job de integração/captura concluído com sucesso; publicação das capturas ignorada pela condição do workflow, que só publica em push para a branch de desenvolvimento.

Commit testado pelo job: [`6821128671ed8b1a4aa2db0c0fa10f763840f35e`](https://github.com/Alex5762/academia-alunos-crud/commit/6821128671ed8b1a4aa2db0c0fa10f763840f35e). As capturas versionadas foram atualizadas pelo commit automatizado [`daed384d89585b8fe52b43d4912534e1dcf8e87f`](https://github.com/Alex5762/academia-alunos-crud/commit/daed384d89585b8fe52b43d4912534e1dcf8e87f).

## 4. Verificações automatizadas de interface

Script: `tests/capture-screenshots.mjs`.

Foram geradas seis capturas:
- `dashboard-desktop.png`
- `dashboard-mobile.png`
- `alunos-mobile.png`
- `cadastro-desktop.png`
- `configuracoes-escuro.png`
- `dashboard-escuro.png`

O Playwright verifica a presença de oito registros fictícios, ausência de rolagem horizontal inesperada no documento desktop/mobile, rolagem interna da tabela no mobile, carregamento do formulário e configurações, aplicação e persistência do tema escuro e ausência de erros JavaScript de página.

Os registros são inseridos apenas no banco efêmero do CI. As capturas e verificações de viewport não equivalem a uma avaliação visual humana completa, não comprovam acessibilidade por si só e não substituem testes em dispositivos físicos ou navegadores diferentes.

## 5. Pendências de revisão manual

1. Conferir em celular real se a rolagem horizontal interna da tabela é fácil de perceber e usar.
2. Revisar contraste e legibilidade dos textos menores nos temas claro e escuro.
3. Conferir navegação por teclado, foco visível e mensagens de campos obrigatórios vazios.
4. Repetir a inspeção visual em outro navegador, se disponível.

## 6. Limites atuais do sistema

- Não há login, autenticação ou controle de acesso por usuário.
- O banco contém apenas a tabela `alunos`; `plano` é texto, sem relação com tabela própria.
- Não existem módulos de matrícula/contrato, pagamentos, frequência, agenda ou aulas.

O FitControl permanece uma aplicação acadêmica/local. Não deve ser exposto como painel administrativo público nem receber dados reais de alunos antes da implementação e revisão das proteções necessárias.

## 7. Conclusão

Os testes manuais registraram o funcionamento básico do CRUD no ambiente XAMPP. Em 20/11/2026, a CI aprovou as nove verificações funcionais descritas e gerou capturas automatizadas desktop/mobile para os temas claro e escuro.

**Conclusão:** os fluxos atualmente implementados passaram pelas verificações registradas. A revisão visual manual continua pendente, assim como autenticação e os módulos futuros descritos em [`docs/roadmap.md`](roadmap.md).

---

**Autor:** Joseph Alexsander  
**Projeto:** FitControl — Gestão de alunos de academia
