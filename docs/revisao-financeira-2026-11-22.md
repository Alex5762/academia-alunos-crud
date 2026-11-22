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

**Estado: validado integralmente nas CI #1312 e #1362 em MySQL 8.0 e MariaDB 10.11.**

**Correção entregue:**
- Migration 011 adiciona `chave_idempotencia` e `hash_requisicao`, com índice único por cobrança. Registros antigos permanecem válidos com as colunas nulas.
- O formulário gera um token aleatório de 128 bits por operação.
- `FinanceService::registerPayment()` calcula SHA-256 dos campos normalizados: cobrança, ator, valor, forma, data e observação.
- Reenvio com mesma chave e mesmo conteúdo retorna o ID original sem criar pagamento ou evento de auditoria adicional.
- Reutilização da chave com conteúdo diferente é recusada.
- A leitura idempotente ocorre após o bloqueio da cobrança com `SELECT ... FOR UPDATE`, serializando tentativas concorrentes na mesma cobrança.
- Testes sequenciais e concorrentes verificam pagamento único, ID estável e evento de auditoria único.

### F-02 — Alto: histórico de pagamentos append-only

**Estado: validado integralmente na CI #1312 em MySQL 8.0 e MariaDB 10.11.**

**Correção entregue:**
- Migration 012 adiciona um guard de INSERT que exige a variável de sessão controlada por `FinanceService`.
- Gatilhos bloqueiam UPDATE e DELETE de pagamentos confirmados.
- `FinanceService::registerPayment()` ativa o guard somente durante o INSERT e o limpa em bloco `finally`, inclusive no caminho de falha.
- Testes tentam INSERT, UPDATE e DELETE diretamente e exigem rejeição; a limpeza das fixtures usa uma variável de sessão reservada ao teardown automatizado.
- Correções futuras devem ser lançamentos compensatórios (estorno/devolução), com referência ao pagamento original, motivo, ator, instante e auditoria.

**Limite importante:** os guards usam variáveis de sessão MySQL. Qualquer cliente que consiga conectar com as mesmas credenciais SQL da aplicação pode definir essas variáveis; portanto, os gatilhos reduzem gravações acidentais por caminhos normais, mas não constituem uma fronteira de privilégio contra credenciais comprometidas ou SQL arbitrário na mesma sessão. A variável de limpeza do teste também pode ser definida por qualquer sessão com essas credenciais. Para um ambiente exposto, o próximo endurecimento deve usar conta SQL de menor privilégio e separar a escrita por rotina controlada (`SQL SECURITY DEFINER`) ou estratégia equivalente, revogando DML direto da conta da aplicação. O XAMPP local e a conta root da CI não representam esse modelo de produção.

**Ponto ainda não coberto:** o evento geral de auditoria continua encadeando metadados e ID, não um snapshot criptográfico independente dos campos financeiros. Um verificador de conteúdo pode ser considerado em etapa posterior, se o escopo justificar.

### F-03 — Médio: classificação financeira após cancelamento da matrícula

**Estado: validado integralmente na CI #1385.**

**Correção entregue:**
- Cobrança cuja matrícula foi cancelada e que ainda não recebeu decisão financeira passa a aparecer como **Em revisão**.
- O resumo separa saldo cobrável de saldo em revisão; valores em revisão deixam de compor o saldo cobrável.
- O indicador de valor contratado e o total recebido preservam o histórico, inclusive de matrículas canceladas.
- Cobrança financeiramente cancelada aparece como **Cancelada** e não mantém saldo em aberto.
- Testes unitários cobrem os estados ativo, parcial, quitado, revisão e cancelado; o teste de banco cria uma matrícula com pagamento parcial, cancela o contrato e verifica a classificação e a separação dos R$ 250 restantes.

### F-04 — Médio: consulta do histórico individual de pagamentos

**Estado: validado integralmente na CI #1332 em MySQL 8.0 e MariaDB 10.11.**

**Correção entregue:**
- Criada `FinancePaymentHistoryQuery`, que consulta os pagamentos das cobranças exibidas em uma única ida ao banco, evitando N+1.
- O histórico expansível apresenta valor, data efetiva do pagamento, forma, observação, data/hora de registro convertida de UTC para o fuso da academia, responsável e ID do lançamento.
- Os lançamentos são ordenados por data de pagamento e ID, ambos em ordem decrescente por cobrança.
- Testes de integração verificam quantidade, ordenação, forma, observação, responsável e ausência de histórico para cobrança sem pagamentos.
- A tabela mantém os totais agregados e a interface usa painel expansível, com estilos responsivos e suporte ao tema escuro.

### F-05 — Médio: faltam restrições positivas no próprio schema

**Estado: validado na CI #1361 em MySQL 8.0 e MariaDB 10.11.**

**Correção entregue:**
- `chk_cobrancas_valor_total_positivo`: exige `cobrancas.valor_total > 0.00`.
- `chk_pagamentos_valor_positivo`: exige `pagamentos.valor > 0.00`.
- A migration não altera nem normaliza dados existentes; se encontrar registros inválidos, o `ALTER TABLE` deve falhar para revisão manual.
- Testes de integração tentam atualizar cobrança para zero e valor negativo e inserir pagamento com zero e valor negativo diretamente no banco.
- O teste de pagamento habilita apenas a variável de sessão exigida pelo guard append-only para que o INSERT alcance a CHECK; a variável é limpa em `finally`.
- Os casos positivos do fluxo continuam sendo conferidos: cobrança de R$ 300,00 e dois pagamentos positivos.
- CI aplica a migration 013 nos dois ambientes e executa o teste financeiro em MySQL 8.0 e MariaDB 10.11.
- Compatibilidade mínima: MySQL 8.0.16, versão a partir da qual o servidor passa a aplicar CHECK constraints; a matriz usa MySQL 8.0 atual e MariaDB 10.11. Consulte a [documentação do MySQL](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html) e a [documentação do MariaDB](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint).

### F-06 — Médio: concorrência no limite do saldo

**Estado: teste concorrente validado nos jobs financeiros MySQL 8.0 e MariaDB 10.11 da CI #1362; execução geral ainda em andamento.**

**Cobertura adicionada:**
- O teste mantém a cobrança bloqueada na conexão principal e inicia dois processos PHP independentes, cada um com sua própria conexão PDO.
- Ambos tentam registrar R$ 200,00 na mesma cobrança de R$ 300,00, usando chaves idempotentes diferentes.
- A expectativa é exatamente um pagamento confirmado e um evento de auditoria; a segunda requisição deve ser recusada especificamente por exceder o saldo, depois de obter o lock e reler o total pago.
- A suíte também preserva o cenário F-01 de duas requisições concorrentes com a mesma chave, que devem retornar o mesmo ID de pagamento.
- O teste verifica no banco quantidade, soma dos pagamentos e quantidade de eventos de auditoria.

### F-07 — Baixo: formatação monetária exata

**Estado: implementado na branch `feat/dashboard-portfolio`; CI pendente.**

**Correção entregue:**
- Criada `FinanceMoneyFormatter`, que formata DECIMAL vindo do PDO como texto e valores calculados a partir de centavos inteiros.
- Removida a conversão para `float` e o uso de `number_format((float) ...)` na tela financeira.
- O formatador valida duas casas decimais, agrupa milhares no padrão brasileiro e rejeita entradas inválidas ou do tipo `float`.
- A conversão decimal→centavos foi centralizada para a tela e para a classificação de saldo do dashboard.
- Testes unitários cobrem zeros, centavos, separador de milhares, números grandes, sinal negativo, conversão exata e rejeição de `float`.

### F-08 — Baixo: política explícita para datas retroativas

**Decisão de domínio:** permitir registrar pagamentos com data passada quando o recebimento ocorreu antes do lançamento no sistema; exigir observação/justificativa para todo registro retroativo. Datas futuras continuam proibidas.

**Implementação na branch `feat/dashboard-portfolio`; testes financeiros aprovados em MySQL 8.0 e MariaDB 10.11 nos jobs Block 04 da CI #1397. A integração CRUD geral ainda está em execução.**
- O formulário apresenta um campo de data visível, preenchido com a data local da academia e com limite máximo de hoje.
- A tela apresenta um campo de observação com indicação de que a justificativa é obrigatória para datas anteriores.
- `FinanceService::registerPayment()` aplica a regra no servidor depois de normalizar a observação; campo oculto ou manipulação do navegador não contorna a validação.
- O teste financeiro cobre três casos: retroativo sem observação é rejeitado; retroativo com justificativa é aceito e preserva data/observação; data futura é rejeitada mesmo com justificativa.
- A data e justificativa fazem parte do fingerprint idempotente e do histórico individual.

## Plano de correção — estado atual

1. **F-01 — Idempotência:** validado na CI em MySQL 8.0 e MariaDB 10.11, incluindo reenvio sequencial e duas conexões com a mesma chave.
2. **F-02 — Imutabilidade:** validado na CI; pagamentos confirmados são append-only no fluxo normal da aplicação. Snapshot criptográfico independente do conteúdo permanece como melhoria futura, não requisito do escopo atual.
3. **F-03 — Revisão de cancelamentos:** validado; saldo de contratos cancelados fica separado do saldo cobrável.
4. **F-04 — Histórico individual:** validado; consulta em lote e painel expansível com responsável, data, método e observação.
5. **F-05 — Constraints:** validado; cobranças e pagamentos exigem valores positivos no banco.
6. **F-06 — Concorrência:** validado; duas operações distintas que excederiam o saldo foram serializadas, com um lançamento aceito e outro recusado.
7. **F-07 — Formatação monetária:** validado na CI #1385; interface e cálculo usam texto decimal/centavos inteiros sem conversão para `float`.
8. **F-08 — Datas retroativas:** validado integralmente na CI #1397; retroativo exige justificativa e data futura é bloqueada.

Não recomendo adicionar agora um razão contábil de partidas dobradas, conciliação bancária ou integração com gateway. Para o escopo local/acadêmico, um registro de contas a receber e pagamentos com histórico imutável, idempotência, saldo exato, transações e auditoria é proporcional.

## Estado de validação

- CI #1312 foi aprovada integralmente: sete jobs verdes, incluindo MySQL 8.0, MariaDB 10.11, migrations, concorrência financeira, classificação de revisão e integração CRUD.
- F-01 a F-04 estão implementados e validados na CI #1332, com sete jobs verdes nos fluxos MySQL 8.0 e MariaDB 10.11.
- F-05 foi validado integralmente na CI #1361: migration 013 e rejeição de zero/negativos em cobranças e pagamentos, em MySQL 8.0 e MariaDB 10.11.
- F-06 foi validado nos jobs de Bloco 04 da CI #1362 em MySQL 8.0 e MariaDB 10.11: duas conexões independentes disputaram uma cobrança de R$ 300,00 com pagamentos de R$ 200,00; um foi aceito, o outro foi recusado por saldo, e houve somente um lançamento e um evento de auditoria.
- F-07 foi validado integralmente na CI #1385: formatação decimal/centavos sem `float`, rejeição de tipos aproximados e integração CRUD aprovadas.
- F-08 foi validado nos jobs financeiros da CI #1397 em MySQL 8.0 e MariaDB 10.11: retroativo sem justificativa rejeitado, retroativo com justificativa persistido e data futura recusada.
- CI #1412 foi aprovada após a simplificação do workflow: sete jobs funcionais concluídos com sucesso; a publicação de screenshots foi ignorada pelo critério condicional do workflow.
- O workflow agora valida pushes somente em `main` e atualizações da PR pelo evento `pull_request`; a concorrência cancela execuções obsoletas da mesma PR.
- Nenhuma migration foi aplicada no banco local do usuário.
- PR #1 permanece Draft e não integrada; não fazer merge antes da validação final.

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
