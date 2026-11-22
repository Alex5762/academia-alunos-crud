# Registro de retomada — 22/11/2026

Este documento preserva o ponto de trabalho para a próxima sessão. Foi criado na branch `feat/dashboard-portfolio`; não altera `main`, não integra a PR e não aplica migrations ao banco local.

## 1. Estado do GitHub no início desta retomada

- Repositório: `Alex5762/academia-alunos-crud` (FitControl).
- PR: [#1 — feat: transforma CRUD em dashboard FitControl para portfólio](https://github.com/Alex5762/academia-alunos-crud/pull/1).
- Estado consultado: aberta, Draft, não integrada.
- Branch: `feat/dashboard-portfolio`.
- HEAD consultado antes deste registro: `696d2d041fd1fface5073fcd77d7edfe819f61f3` — `chore: atualiza screenshots do FitControl [skip ci]`.
- Comparação com `main): 705 commits à frente e 4 atrás; estado divergente. Merge base: `887fb6549888132202d3561faaed76a9928c0314`.
- PR com 111 arquivos alterados no momento da consulta.
- O campo `mergeable: true` do GitHub não elimina o fato de a branch estar 4 commits atrás de `main`; não deve ser tratado como autorização de merge.
- Última CI completa confirmada antes do commit de screenshots: push #1163 e PR #1164 passaram no SHA `a042020390a0484e6c0d207fed3812acd3bbccff`, incluindo jobs MySQL 8.0 e MariaDB 10.11.
- O commit `696d2d0` altera apenas seis screenshots e foi criado pelo `github-actions[bot]` com `[skip ci]`.

## 2. Decisão de qualidade do histórico Git

Foi registrada a ADR-001 em `docs/decisoes/ADR-001-politica-de-historico-git.md`.

Resumo:
- Não perseguir uma quantidade-alvo de commits.
- Não reescrever o histórico antigo só para reduzir o contador.
- Fazer commits por unidade lógica, com mensagens descritivas.
- Separar e reconhecer commits de automação.
- Revisar diff, testes, CI e documentação em conjunto.
- Manter PRs com escopo revisável.
- Não fazer force-push, squash retroativo ou merge sem decisão explícita.

O commit da ADR foi criado depois da consulta inicial do HEAD. Portanto, a referência de partida do código antes da documentação é `696d2d041fd1fface5073fcd77d7edfe819f61f3`; a ADR e este registro são commits documentais posteriores na mesma branch.

## 3. Pendências conhecidas — não resolvidas

### Documentação
- O README contém passos 8, 9 e 10 do XAMPP sem instruções completas.
- A árvore do projeto no README não reflete todos os diretórios, scripts, endpoints e migrations atuais.
- A seção de gestão de funcionários afirma que todas as contas ativas têm as mesmas permissões. Isso está desatualizado: existe RBAC com perfis e permissões.
- O README ainda descreve CI e migrations 005/006 de forma incompleta e cita execuções antigas; precisa refletir migrations 007/008, RBAC, cadeia de integridade e diagnóstico de recuperação de auditoria.
- O corpo da PR #1 está desatualizado: cita execuções antigas, afirma que não há papéis/cargos, informa 51 arquivos e 246 commits, enquanto a consulta atual mostrava 111 arquivos e 705 commits.
- O guia local `docs/guia-migrations-xampp.md` cobre migrations 005 a 008; manter a orientação de backup, inspeção e execução manual. Não executar migrations no banco local sem validação explícita e backup.

### Código e revisão técnica
- Revisar e unificar a extração de iniciais: `funcoes.php::inicialNome()` usa PCRE `\\X`, que corresponde a um grapheme cluster; o ponto a corrigir é a conversão para maiúsculas de caracteres Unicode, não uma regex literal para X. `index.php` possui helper separado com mapeamento de caracteres acentuados. Preservar a decisão anterior de não reintroduzir dependência obrigatória de mbstring sem avaliar alternativa e cobertura de testes.
- Fazer revisão de entendimento da cadeia de auditoria (`src/Security/AuditIntegrityChain.php`), dos checkpoints HMAC (`src/Security/AuditIntegrityCheckpoint.php`), do diagnóstico (`src/Security/AuditRecoveryDiagnostic.php`) e do controle de acesso (`src/Security/AccessControl.php`). Explicar em documentação o que é garantido e o que permanece como limite.
- Não afirmar que a aplicação está pronta para produção. MFA, recuperação de senha, revisão de segurança independente, HTTPS real, backups, monitoramento e revisão LGPD continuam sendo limites/itens de preparação.

### Validação e integração
- A CI #1163/#1164 passou no commit pai `a042020...`; o commit de screenshots `696d2d0...` tinha `[skip ci]`. Verificar a validação apropriada para os commits documentais novos antes de declarar a branch integralmente validada.
- Fazer inspeção manual em dispositivo físico e navegador real, conforme checklist existente.
- Planejar reconciliação dos quatro commits que entraram em `main` após o merge base. Primeiro inspecionar exatamente os commits e diferenças; só depois decidir como atualizar a branch. Não fazer merge automático.
- Manter PR #1 em Draft até documentação, revisão, testes e inspeção manual estarem concluídos.
- Não integrar a PR e não alterar `main` sem autorização explícita do usuário.

## 4. Próxima sequência recomendada

1. Confirmar o estado atual da branch e a CI após os commits documentais.
2. Corrigir README e corpo da PR, atualizando as descrições para a implementação real de RBAC e migrations 007/008.
3. Completar a seção XAMPP e atualizar a árvore/descrição do pipeline no README.
4. Unificar a lógica de iniciais com testes para caracteres ASCII, acentuados e grafemas Unicode, sem introduzir dependência desnecessária.
5. Revisar os módulos de auditoria e RBAC, documentando limites e garantias.
6. Inspecionar os quatro commits exclusivos de `main` e preparar plano seguro de reconciliação.
7. Executar checklist manual e manter a PR Draft até aprovação explícita.

## 5. Regra de segurança para a retomada

Este registro é um checkpoint, não uma autorização para executar todas as etapas. Trabalhar incrementalmente, confirmar os resultados, preservar o banco local e a branch `main`, e pedir confirmação antes de qualquer operação de integração ou mudança destrutiva.
