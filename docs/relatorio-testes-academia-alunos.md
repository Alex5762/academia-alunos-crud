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

## 6. Autenticação, contas, auditoria e sessões — 20/11/2026

**Commit validado:** [\`7ff2a9da84a20e0fe160d43fd9f30b681e0023d6\`](https://github.com/Alex5762/academia-alunos-crud/commit/7ff2a9da84a20e0fe160d43fd9f30b681e0023d6).

Execuções do commit:
- [Run 44855484995 — push, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44855484995): integração, captura visual e publicação das capturas concluídas.
- [Run 44855491003 — pull request, sucesso](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44855491003): integração e captura visual concluídas; o job de publicação é ignorado em eventos de PR conforme a condição do workflow.

### 6.1 Cenários cobertos pela CI

| Área | Verificações | Resultado |
|---|---|---|
| Preparação | Sintaxe PHP, arquivos obrigatórios, schema MySQL e migrações 001/002/003 | Aprovado |
| Provisionamento | Conta fictícia, hash, auditoria e e-mail duplicado | Aprovado |
| Login | Credenciais inválidas/válidas, conta inativa, troca obrigatória da senha temporária e rejeição da senha antiga | Aprovado |
| Limite de login | Quatro falhas, bloqueio na quinta, manutenção do bloqueio e limpeza após login válido | Aprovado |
| Gestão de funcionários | Criação, desativação, reativação, auditoria e bloqueio de autodesativação | Aprovado |
| CRUD e auditoria | Cadastro, pesquisa, edição, exclusão e autoria dos eventos | Aprovado |
| Sessão | Revogação após desativação, logout, expiração aos 30 minutos e limite absoluto de 8 horas | Aprovado |
| Renovação | Endpoint autenticado, CSRF inválido (403), método GET (405) e continuidade após renovação | Aprovado |
| Interface | Capturas desktop/mobile, temas, erros JavaScript e avisos de expiração via Playwright | Aprovado |

### 6.2 Avisos de sessão validados

O Playwright avança o relógio do navegador para confirmar que o aviso por inatividade aparece aos 25 minutos e que o aviso do limite absoluto aparece antes das 8 horas. O teste também aciona **Continuar conectado** e verifica que o aviso de inatividade é fechado após a renovação autenticada.

Esses testes automatizados validam os cenários programados. Eles não substituem inspeção humana da usabilidade, contraste, foco e interação em dispositivos reais.

## 7. Limites atuais do sistema

- Todas as contas ativas possuem a mesma permissão administrativa; não existe separação por cargo.
- Não há MFA nem recuperação de senha por e-mail.
- A tabela \`alunos\` ainda guarda o plano como texto; não existe relação com uma tabela própria de planos.
- Não existem módulos de matrícula/contrato, pagamentos, frequência/check-in, aulas, agenda ou relatórios operacionais.
- O projeto permanece acadêmico/local. A CI não comprova prontidão para produção, uso público ou tratamento de dados reais.

Antes de qualquer uso em produção, ainda são necessários revisão independente de segurança, HTTPS no ambiente de hospedagem, gestão de segredos, backups com teste de restauração, monitoramento, revisão de privacidade/LGPD e inspeção visual/acessibilidade.

## 8. Conclusão

Em 20/11/2026, os workflows de push e pull request do commit \`7ff2a9da84a20e0fe160d43fd9f30b681e0023d6\` concluíram com sucesso. A validação automatizada cobriu o CRUD, autenticação, gestão de funcionários, auditoria, limite de tentativas, expiração/renovação de sessão e verificações de interface.

**Estado:** a implementação e a CI estão aprovadas. A inspeção visual manual em navegador/dispositivo real continua pendente antes de considerar o Bloco 00 totalmente revisado. As limitações de segurança para produção permanecem explícitas e fora do escopo desta etapa.

---

**Autor:** Joseph Alexsander  
**Projeto:** FitControl — Gestão de alunos de academia
