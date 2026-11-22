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


## 6. Atualização após a implementação do Bloco 04 — 22/11/2026

Esta seção atualiza e substitui as pendências de implementação do Bloco 04 descritas anteriormente neste checkpoint.

### Estado confirmado

- Bloco 04 — Frequência e check-in implementado na branch `feat/dashboard-portfolio`.
- Migration 009, serviço transacional, endpoint POST, página de frequência, histórico filtrável e indicadores implementados.
- Regras: aluno ativo, exatamente uma matrícula vigente, uma presença por aluno/dia em `America/Sao_Paulo`, instante UTC persistido, autoria do funcionário e auditoria na mesma transação.
- Testes cobrem aluno inexistente/inativo, ausência de matrícula, matrícula futura/vencida/cancelada/ambígua, duplicidade, novo dia, virada do dia local, rollback por falha na cadeia e verificação de integridade.
- A página de frequência foi verificada automaticamente em Chromium, Firefox e WebKit, nos viewports desktop, 390px e 320px.
- CI #1221: todos os sete jobs aprovados — [execução](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45027184228).
- README, roadmap, matriz de permissões, guia XAMPP, especificação do Bloco 04 e descrição da PR foram atualizados.

### Estado da PR e branch

- PR #1 permanece aberta em Draft e não integrada.
- A branch permanece `feat/dashboard-portfolio`; a comparação consultada após a documentação indicava 744 commits à frente e 4 atrás de `main`.
- A diferença de quatro commits de `main` ainda precisa ser inspecionada antes de qualquer reconciliação.
- Nenhum merge foi executado; `main` não foi alterada por esta retomada.
- O CI #1226 foi disparado pelos commits documentais finais e estava pendente na última consulta; o código funcional já havia passado na CI #1221.

### Pendências reais antes de encerrar o Bloco 04

1. Aplicar migrations 005–009 no XAMPP local somente após backup, inspeção da versão e confirmação de InnoDB, seguindo `docs/guia-migrations-xampp.md`.
2. Fazer inspeção manual em dispositivo físico e navegador instalado.
3. Confirmar a experiência local de registrar e consultar um check-in.
4. Manter a PR em Draft até essas verificações e aprovação explícita.

### Próximo bloco

O Bloco 05 é **Financeiro**. Ainda não foi implementado nem iniciado. Antes de desenhar o modelo de dados, decidir se as cobranças serão geradas automaticamente a partir das matrículas ou lançadas manualmente. Integrações com gateways e cobrança automática continuam fora do escopo inicial até decisão específica.

### Observação sobre a política de commits

A implementação do Bloco 04 gerou commits separados por arquivo devido ao fluxo de publicação utilizado. A ADR-001 registra que isso foi uma exceção operacional e que futuras entregas devem agrupar mudanças relacionadas em commits lógicos. Não reescrever o histórico publicado para reduzir o contador.


## 7. Atualização — Bloco 05 Financeiro (22/11/2026)

### Decisões de negócio
- Uma matrícula nova gera uma cobrança única pelo valor total do contrato; duração do plano não parcela nem divide o preço.
- Exemplo: plano de três meses por R$ 300 gera uma cobrança de R$ 300. Para cobrança mensal, usa-se um plano com duração de um mês.
- Vencimento padrão: data civil local do início da matrícula.
- Pagamentos parciais permitidos; não podem exceder o saldo.
- Cancelamento da matrícula não apaga pagamentos nem cancela a cobrança automaticamente.
- Cobrança sem pagamentos pode ser cancelada separadamente, depois do cancelamento contratual, com justificativa e auditoria.
- Cobrança com pagamentos não pode ser cancelada como se nada tivesse sido recebido; estorno/devolução é fluxo futuro.
- Não há override pontual do preço no primeiro corte; o valor é copiado do plano e congelado.
- Matrículas legadas/importadas não recebem cobranças retroativas.
- Não há multi-tenancy nem integração com gateway.

### Implementação publicada na branch
- Migration database/migrations/010_financeiro.sql cria cobrancas e pagamentos.
- EnrollmentCreationService cria cobrança única na mesma transação da matrícula e da cadeia de auditoria.
- src/Domain/Finance/FinanceService.php registra pagamentos parciais, bloqueia concorrência por cobrança, rejeita excesso e cancela somente cobranças sem pagamentos após cancelamento da matrícula.
- financeiro.php apresenta resumo, situação calculada, recebimentos e cancelamento condicionado.
- RBAC finance.view, finance.register_payment e finance.cancel_charge para Administrador/Gestor; Operador sem acesso.
- Testes de domínio e integração cobrem cobrança automática, preço total, vencimento, unicidade, pagamentos parciais, excesso de saldo, cancelamento e auditoria.
- tests/integration-crud.sh inclui fixture de cobrança para validar o fluxo HTTP real de nova matrícula.
- tests/browser-compatibility.mjs verifica a tela financeira em desktop e mobile.
- README, guia XAMPP, matriz RBAC, roadmap e especificação financeira atualizados.

### Estado técnico
- Branch: feat/dashboard-portfolio.
- PR #1 permanece aberta, Draft e não integrada.
- HEAD atual após a publicação automatizada de screenshots: 86b8e33f19001b616c0468b216a56ebecacccca8.
- CI #1243 concluiu com sucesso no commit de implementação cc6565be0f6da73c611961d6b8e6ade2cdcac92c. As execuções #1244 e #1245 também passaram com os sete jobs aprovados no HEAD documental 1184e73bb33ffe537763f3754ce33564f29acbee. Depois, o workflow publicou screenshots em um commit automatizado 86b8e33f19001b616c0468b216a56ebecacccca8; a branch aponta agora para esse commit de automação.
- CI #1238/#1239 de commits anteriores falhou no teste HTTP de matrícula porque o fixture isolado de tests/integration-crud.sh ainda não criava a tabela cobrancas. A correção foi publicada em cc6565b: fixture agora cria a tabela, verifica cobrança e auditoria e remove a tabela antes de matriculas. As falhas antigas não representam o estado atual.
- Nenhuma migration foi aplicada no XAMPP nem em banco local do usuário.
- Não houve alteração em main, merge, force-push ou reescrita de histórico.

### Próximos passos
1. Confirmar que a branch ainda aponta para o commit automatizado de screenshots 86b8e33 e que a PR #1 continua Draft.
2. A descrição da PR #1 já foi atualizada com o Bloco 05 e as execuções CI #1243–#1245 aprovadas.
3. Preparar aplicação local da migration 010 somente após backup e inspeção de versão/engine; não executar automaticamente.
4. Validar manualmente a experiência financeira em navegador/dispositivo real.