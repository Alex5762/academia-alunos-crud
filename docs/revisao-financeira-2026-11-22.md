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

### F-01 — Alto: falta idempotência no lançamento de pagamento

**Evidência:** `FinanceService::registerPayment()` insere um novo registro em `pagamentos` toda vez que a operação chega ao serviço. A tabela não possui chave de idempotência nem unicidade que represente uma tentativa de pagamento.

**Risco concreto:** uma cobrança de R$ 300 pode receber um pagamento parcial de R$ 100. Se o navegador reenviar o mesmo POST (duplo clique, repetição do formulário ou resposta perdida), o segundo envio também será aceito enquanto houver saldo — registrando R$ 200, embora só tenha ocorrido um recebimento real.

**Correção recomendada:**
- Gerar um identificador aleatório por operação/formulário (por exemplo, UUID).
- Persistir a chave junto ao pagamento, com índice único no escopo da cobrança.
- Calcular e guardar uma impressão dos campos significativos (cobrança, funcionário, valor, forma, data e observação).
- Reenvio com a mesma chave e mesmos dados deve devolver o ID do lançamento original sem criar outro evento.
- Reutilização da chave com conteúdo diferente deve ser recusada.

**Teste necessário:** repetir o mesmo POST sequencialmente e em duas conexões concorrentes; deve existir um único lançamento e um único evento de auditoria.

### F-02 — Alto: o histórico de pagamentos não é imutável no banco

**Evidência:** a migration 010 não cria proteções contra UPDATE/DELETE em `pagamentos`. Não há serviço de edição/exclusão, o que é bom, mas a tabela aceita alterações diretas. A cadeia geral de auditoria encadeia os metadados do evento (ator, ação, entidade, ID e instante), não um snapshot criptográfico de `pagamentos.valor`, `forma_pagamento` ou `data_pagamento`.

**Risco concreto:** uma alteração direta do valor de um pagamento pode mudar o saldo calculado pela tela sem invalidar o hash do evento de auditoria que apenas aponta para o ID do pagamento.

**Correção recomendada:**
- Tratar pagamentos como lançamentos append-only: não editar nem apagar registros confirmados.
- Planejar proteção de UPDATE/DELETE no banco, compatível com a estratégia de testes e manutenção.
- Para correções futuras, criar lançamentos compensatórios (estorno/devolução) que referenciem o pagamento original, com motivo, ator, instante e auditoria.
- Considerar vincular o evento de auditoria a um resumo/hash dos campos críticos do lançamento, para detectar alteração do conteúdo.

**Observação:** não existe endpoint de edição/exclusão de pagamento hoje. O achado é uma lacuna de integridade em nível de banco e de verificabilidade do conteúdo, não a afirmação de que a interface atual oferece essa ação.

### F-03 — Médio: cobranças de matrículas canceladas são apresentadas como abertas

**Evidência:** `financeiroStatus()` considera o cancelamento da cobrança, valor recebido e vencimento, mas não considera `matricula_cancelada_em`. O resumo também soma cobranças não canceladas mesmo quando a matrícula vinculada foi cancelada.

**Risco concreto:** o serviço bloqueia novos pagamentos depois do cancelamento contratual, mas a tela ainda pode mostrar a cobrança como “Em aberto”, “Parcial” ou “Vencida” e incluí-la no saldo em aberto. Isso passa a impressão de que a academia deve continuar cobrando um contrato cujo cancelamento está em revisão financeira.

**Correção recomendada:**
- Exibir estado “Em revisão” quando a matrícula estiver cancelada e a cobrança ainda não tiver uma decisão financeira.
- Separar saldo efetivamente cobravel de valores pendentes de revisão.
- Preservar o total histórico contratado e os valores já recebidos; não apagar os lançamentos.

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

1. **F-01:** idempotência, para impedir duplicidade em reenvios.
2. **F-02 e F-05:** proteção de imutabilidade e invariantes no schema.
3. **F-03:** separar valores cobraveis de valores em revisão por cancelamento contratual.
4. **F-04:** disponibilizar o histórico de pagamentos por cobrança.
5. **F-06:** adicionar testes reais de concorrência e HTTP para registro de pagamento/cancelamento.
6. **F-07 e F-08:** acabamento da apresentação e explicitação da política de datas.

Não recomendo adicionar agora um razão contábil de partidas dobradas, conciliação bancária ou integração com gateway. Para o escopo local/acadêmico, um registro de contas a receber e pagamentos com histórico imutável, idempotência, saldo exato, transações e auditoria é proporcional.

## Estado de validação

- CI #1243, #1244 e #1245 foram aprovadas nos jobs existentes.
- A cobertura atual valida migration, criação automática, pagamentos sequenciais, limite de saldo, cancelamento condicionado e auditoria.
- A CI verde não cobre ainda os cenários F-01, F-02, F-03, F-04 e a corrida de concorrência de F-06.
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
