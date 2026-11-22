# Revisão técnica do Bloco 05 — Financeiro

**Data:** 22/11/2026  
**Escopo:** branch `feat/dashboard-portfolio`, módulo financeiro do FitControl.  
**Resultado:** base bem encaminhada; há correções importantes antes de considerar o módulo concluído. Esta é uma revisão estática do código e dos testes existentes, não uma certificação de segurança nem uma validação do banco local.

## Material revisado

- `database/migrations/010_financeiro.sql`
- `src/Domain/Finance/FinanceService.php`
- `src/Domain/Enrollment/EnrollmentCreationService.php`
- `src/Domain/Enrollment/EnrollmentCancellationService.php`
- `financeiro.php`
- `src/Security/AccessControl.php` e `src/Security/AuditIntegrityChain.php`
- `tests/database-finance.php`, `tests/access-control.php`, `tests/integration-crud.sh`
- `.github/workflows/ci.yml`

## O que está bem resolvido

1. **Valor contratual claro:** preço do plano é tratado como o total do contrato. A duração não é confundida com periodicidade de cobrança.
2. **Snapshot:** o valor da cobrança vem do valor contratado na matrícula; alterar o catálogo não deveria reescrever contratos anteriores.
3. **Atomicidade:** matrícula, cobrança e eventos de auditoria são gravados dentro da mesma transação. O PDO oferece commit/rollback transacional; a aplicação trata falhas revertendo a operação.
4. **Proteção contra excesso de pagamento:** `registerPayment()` bloqueia a cobrança com `SELECT ... FOR UPDATE`, soma os pagamentos existentes e só então insere o novo lançamento. O cancelamento financeiro usa bloqueio da cobrança também.
5. **Valores exatos no banco:** `DECIMAL(10,2)` é apropriado para valores monetários e o serviço compara quantias em centavos inteiros, em vez de somar `float`.
6. **Autorização:** a página exige autenticação e permissões financeiras específicas; Administrador e Gestor têm acesso, Operador não.
7. **Legado protegido:** a migration não inventa cobranças retroativas para matrículas importadas.
8. **Auditoria:** criação de cobrança, pagamento e cancelamento são registrados pela cadeia de auditoria na mesma transação do evento financeiro.

Essas escolhas estão alinhadas com as recomendações de transações PDO, leituras de bloqueio do InnoDB, valores DECIMAL exatos e autorização negada por padrão. Referências no fim deste documento.

## Achados priorizados

### F-01 — Alto: idempotência no lançamento de pagamento

**Estado: implementado na branch `feat/dashboard-portfolio`; validação em andamento.**

**Correção entregue:**
- Migration 011 adiciona `chave_idempotencia` e `hash_requisicao`, com índice único por cobrança. Registros antigos permanecem válidos com as colunas nulas.
- O formulário gera um token aleatório de 128 bits por operação.
- `FinanceService::registerPayment()` calcula SHA-256 dos campos normalizados: cobrança, ator, valor, forma, data e observação.
- Reenvio com mesma chave e mesmo conteúdo retorna o ID original sem criar pagamento ou evento de auditoria adicional.
- Reutilização da chave com conteúdo diferente é recusada.
- A leitura idempotente ocorre após o bloqueio da cobrança com `SELECT ... FOR UPDATE`, serializando tentativas concorrentes na mesma cobrança.

**Cobertura adicionada:** teste sequencial de reenvio, conflito de conteúdo e teste de corrida com dois processos PHP e conexões PDO independentes. O cenário concorrente exige um único pagamento, o mesmo ID nos dois processos e exatamente um evento de auditoria.

**Validação observada:** no run CI #1266, o job financeiro em MariaDB 10.11 concluiu com sucesso, incluindo migration 011 e o teste concorrente. O job equivalente em MySQL 8.0 ainda estava em execução na última consulta. A confirmação final depende dos dois motores.

### F-02 — Alto: histórico de pagamentos append-only

**Estado: implementação na branch `feat/dashboard-portfolio`; CI da migration 012 pendente.**

**Correção entregue:**
- Migration 012 adiciona um guard de INSERT que exige a variável de sessão controlada por `FinanceService`.
- Gatilhos bloqueiam UPDATE e DELETE de pagamentos confirmados.
- `FinanceService::registerPayment()` ativa o guard somente durante o INSERT e o limpa em bloco `finally`, inclusive no caminho de falha.
- Testes tentam INSERT, UPDATE e DELETE diretamente e exigem rejeição; a limpeza das fixtures usa uma variável de sessão reservada ao teardown automatizado.
- Correções futuras devem ser lançamentos compensatórios (estorno/devolução), com referência ao pagamento original, motivo, ator, instante e auditoria.

**Limite importante:** os guards por variável de sessão protegem contra caminhos SQL acidentais da aplicação, não contra um administrador do banco que controle a própria sessão ou os gatilhos. O ambiente real deve usar uma conta SQL de menor privilégio; acesso administrativo ao banco permanece uma fronteira de confiança.

**Ponto ainda não coberto:** o evento geral de auditoria continua encadeando metadados e ID, não um snapshot criptográfico independente dos campos financeiros. Um verificador de conteúdo pode ser considerado em etapa posterior, se o escopo justificar.

### F-03 — Médio: classificação financeira após cancelamento da matrícula

**Estado: implementado na branch `feat/dashboard-portfolio`; CI pendente.**

**Correção entregue:**
- Cobrança cuja matrícula foi cancelada e que ainda não recebeu decisão financeira passa a aparecer como **Em revisão**.
- O resumo separa saldo cobrável de saldo em revisão; valores em revisão deixam de compor o saldo cobrável.
- O indicador de valor contratado e o total recebido preservam o histórico, inclusive de matrículas canceladas.
- Cobrança financeiramente cancelada aparece como **Cancelada** e não mantém saldo em aberto.
- Testes unitários cobrem os estados ativo, parcial, quitado, revisão e cancelado; o teste de banco cria uma matrícula com pagamento parcial, cancela o contrato e verifica a classificação e a separação dos R$ 250 restantes.

### F-04 — Médio: falta consulta do histórico individual de pagamentos

**Evidência:** a página calcula `SUM(p.valor)` e `COUNT(p.id)`, mas não apresenta cada pagamento com valor, data, forma, observação e funcionário responsável.

**Risco concreto:** o Gestor vê apenas o total recebido e não consegue conferir quais lançamentos formam aquele total, nem identificar facilmente um pagamento duplicado.

**Correção recomendada:** incluir uma área expansível ou detalhe da cobrança com a lista cronológica dos pagamentos, exibindo os campos relevantes e mantendo a interface utilizável em telas estreitas. O resumo agregado pode continuar na tabela principal.

### F-05 — Médio: faltam restrições positivas no próprio schema

**Evidência:** `valor_total` e `valor` são `DECIMAL(10,2) NOT NULL`, mas a migration não declara `CHECK (valor_total > 0)` e `CHECK (valor > 0)`.

**Risco concreto:** os serviços PHP rejeitam valores inválidos, mas uma rotina SQL, importação ou futuro caminho de escrita pode inserir cobrança/pagamento zero ou negativo. O banco deve proteger invariantes simples independentemente da interface.

**Correção recomendada:** adicionar constraints nomeadas para valores positivos. MySQL aplica CHECK a partir da versão 8.0.16; validar a mesma migration no MySQL 8.0 e MariaDB 10.11 já usados pela CI.

### F-06 — Médio: a proteção de concorrência ainda não tem teste de corrida financeira

**Evidência:** `tests/database-finance.php` testa pagamentos parciais e excesso de saldo em sequência, numa única conexão. Não simula dois processos independentes tentando pagar a mesma cobrança ao mesmo tempo.

**Leitura do código:** o `SELECT ... FOR UPDATE` é uma estratégia correta para serializar a leitura e a atualização do saldo no caminho normal. A falha aqui é de cobertura: ainda não há teste que prove o comportamento sob duas conexões concorrentes.

**Correção recomendada:** criar teste com dois processos PDO independentes, mesma cobrança e valores que, juntos, excederiam o saldo. Um pagamento deve ser confirmado; o outro deve ser recusado após obter o bloqueio e reler o saldo. Adicionar também o cenário de duas tentativas com a mesma chave idempotente.

### F-07 — Baixo: formatação monetária converte DECIMAL para float

**Evidência:** `financeiroMoeda()` usa `number_format((float) ...)`, apesar de os cálculos de saldo já usarem centavos inteiros.

**Risco:** não é um erro observado nos testes atuais, mas mistura representação aproximada na camada de apresentação com o restante do domínio baseado em centavos.

**Correção recomendada:** formatar a partir de centavos inteiros ou de texto decimal validado, sem converter valores monetários para float.

### F-08 — Baixo: política de data do pagamento está implícita na interface

**Evidência:** o formulário envia `data_pagamento` como campo oculto com a data de hoje. O serviço aceita datas anteriores e recusa datas futuras.

**Risco:** a regra para registrar recebimento retroativo existe no serviço, mas não é explicada nem exposta na tela. O cliente também pode alterar o campo oculto.

**Correção recomendada:** decidir explicitamente se a interface deve permitir data passada. Se sim, mostrar um campo de data e exigir observação/justificativa quando houver retroatividade; se não, validar no servidor que a data é a data local atual.

## Plano de correção recomendado

1. **F-01:** implementado e validado em MySQL 8.0 e MariaDB 10.11 no CI #1266; integração CRUD/visual ainda estava em execução.
2. **F-02:** implementado com guards append-only; aguardar CI e avaliar snapshot criptográfico independente em etapa posterior.
3. **F-05:** adicionar constraints positivas no schema.
4. **F-03:** implementado; aguardar CI nos dois bancos.
5. **F-04:** disponibilizar o histórico de pagamentos por cobrança.
6. **F-06:** adicionar testes de concorrência do limite de saldo e HTTP para registro de pagamento/cancelamento.
7. **F-07 e F-08:** acabamento da apresentação e explicitação da política de datas.

Não recomendo adicionar agora um razão contábil de partidas dobradas, conciliação bancária ou integração com gateway. Para o escopo local/acadêmico, um registro de contas a receber e pagamentos com histórico imutável, idempotência, saldo exato, transações e auditoria é proporcional.

## Estado de validação

- CI #1243–#1245 foram aprovadas nos jobs anteriores.
- CI #1266: job financeiro em MariaDB 10.11 aprovado, incluindo migration 011 e concorrência idempotente; MySQL 8.0 ainda em execução na última consulta.
- F-01 agora possui cobertura sequencial e concorrente; F-02, F-03 e F-04 permanecem pendentes. A concorrência de F-06 sobre o limite de saldo ainda requer cenário próprio.
- Nenhuma migration foi aplicada no banco local do usuário.
- PR #1 permanece Draft e não integrada. Esta revisão não altera a decisão de manter a PR aberta.

## Referências consultadas

- [OWASP ASVS 5.0](https://asvs.owasp.org/) — baseline de requisitos verificáveis de segurança.
- [OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html) — autorização no servidor, menor privilégio e negação por padrão.
- [OWASP Transaction Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html) — garantir que os dados relevantes da operação sejam autorizados no servidor.
- [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) — objetivos de log/auditoria, proteção de integridade e cuidado com dados sensíveis.
- [MySQL 8.0 — Locking Reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html) — comportamento de `SELECT ... FOR UPDATE`.
- [MySQL 8.0 — Deadlocks](https://dev.mysql.com/doc/refman/8.0/en/innodb-deadlocks.html) e [como minimizá-los](https://dev.mysql.com/doc/refman/8.0/en/innodb-deadlocks-handling.html) — transações curtas, ordem de bloqueios consistente, índices e tratamento de deadlocks.
- [MySQL 8.0 — DECIMAL e precisão exata](https://dev.mysql.com/doc/refman/8.0/en/precision-math.html) — evitar tipos aproximados em valores financeiros.
- [MySQL 8.0 — CHECK Constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html) — restrições de domínio no banco.
- [AWS Well-Architected — Make mutating operations idempotent](https://docs.aws.amazon.com/wellarchitected/latest/framework/rel04-bp04.html) e [Adyen — API idempotency](https://docs.adyen.com/development-resources/api-idempotency/) — chave estável por operação e proteção contra reenvios.
- [PHP Manual — PDO Transactions](https://www.php.net/manual/en/pdo.transactions.php) — begin/commit/rollback e atomicidade.
