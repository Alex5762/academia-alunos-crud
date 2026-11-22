# Bloco 05 — Financeiro

## Objetivo

Adicionar controle financeiro básico ao FitControl, vinculando cobranças às matrículas e registrando pagamentos com histórico auditável. O módulo será local e acadêmico, sem integração bancária ou gateway nesta etapa.

## Decisões de domínio confirmadas

### 1. Uma cobrança pelo valor total do contrato

O preço cadastrado no plano representa o valor total daquele contrato, independentemente da duração. Ao criar uma matrícula nova pelo sistema, será gerada automaticamente **uma única cobrança** pelo valor contratado.

| Plano | Duração | Preço do contrato | Cobrança gerada |
|---|---:|---:|---:|
| Mensal | 1 mês | R$ 100,00 | 1 × R$ 100,00 |
| Trimestral | 3 meses | R$ 300,00 | 1 × R$ 300,00 |
| Anual | 12 meses | R$ 1.000,00 | 1 × R$ 1.000,00 |

Não haverá divisão automática do preço em parcelas. Se a academia quiser cobrar por mês, deverá cadastrar/contratar um plano com duração de um mês. Duração contratual e valor total não serão interpretados como frequência de cobrança.

### 2. Catálogo editável e snapshots contratuais

- Cada instalação poderá configurar seus próprios planos, valores e durações no catálogo existente.
- O valor e a duração efetivamente contratados devem permanecer congelados na matrícula e na cobrança.
- Alterar o preço ou a duração de um plano no catálogo não altera matrículas nem cobranças já criadas.
- Exceções pontuais no contrato, se forem necessárias, exigirão decisão de escopo própria; não serão introduzidas silenciosamente.

### 3. Geração automática e proteção do legado

- A criação de uma matrícula nova pelo fluxo normal deverá gerar uma cobrança automaticamente.
- Matrículas legadas/importadas não gerarão cobranças históricas automaticamente. Não presumir dívidas a partir de dados incompletos.
- A criação da matrícula e da cobrança deverá ocorrer na mesma transação; se qualquer etapa falhar, ambas serão revertidas.
- Deve existir proteção contra cobrança duplicada para a mesma matrícula.

## Escopo funcional proposto

- Consulta de cobranças por aluno, matrícula, situação e período.
- Registro de pagamentos associados a uma cobrança.
- Cálculo da situação financeira a partir do valor cobrado, pagamentos confirmados, vencimento e cancelamento; evitar que o usuário altere livremente um estado sem evento correspondente.
- Histórico de pagamentos e alterações, sem apagar lançamentos financeiros.
- Indicadores simples de valores recebidos, em aberto e vencidos por período.
- Permissões específicas para consultar cobranças, registrar pagamentos e executar ajustes.
- Ajustes financeiros excepcionais com justificativa obrigatória e trilha de auditoria.

## Regras de integridade propostas

- Valores monetários persistidos em DECIMAL, nunca em ponto flutuante.
- Cobrança vinculada à matrícula e ao aluno correspondente.
- Pagamentos não podem exceder o saldo em aberto, salvo decisão explícita posterior sobre crédito/adiantamento.
- Pagamento e evento de auditoria devem ser persistidos atomicamente.
- Correções não devem apagar pagamentos anteriores; devem gerar estorno/ajuste rastreável.
- Datas civis e instantes devem seguir a convenção temporal já adotada pelo projeto (America/Sao_Paulo para calendário local e UTC para instantes persistidos).

## Fora do escopo desta etapa

- Gateway de pagamento, Pix integrado, boleto bancário ou cartão.
- Armazenamento de dados de cartão.
- Débito recorrente automático.
- Parcelamento automático de um contrato em várias cobranças.
- Multi-tenancy: a configuração é por instalação da aplicação, não por várias academias isoladas no mesmo banco.
- Migração automática de dívidas históricas de matrículas legadas.

## Decisões operacionais adotadas para o primeiro corte

- **Vencimento:** data civil local de início da matrícula; sem edição individual nesta primeira versão.
- **Pagamento parcial:** permitido. Uma cobrança recebe vários pagamentos até atingir o total; valores acima do saldo são rejeitados.
- **Cancelamento contratual:** não apaga nem cancela automaticamente a cobrança e não apaga pagamentos.
- **Cancelamento financeiro:** cobrança sem pagamentos pode ser cancelada separadamente, somente depois do cancelamento da matrícula, com justificativa obrigatória, funcionário e instante UTC.
- **Cobrança com pagamentos:** fica bloqueada para cancelamento simples. Estorno/devolução exigirá fluxo próprio e lançamento compensatório; nunca editar/excluir pagamento original.
- **Preço excepcional:** não haverá override individual no primeiro corte; o preço contratado é copiado do plano e congelado.

## Estrutura do primeiro incremento

- Migration 010 cria \`cobrancas\` e \`pagamentos\`, com DECIMAL, chaves estrangeiras e unicidade de uma cobrança por matrícula.
- A migration não cria cobranças retroativas para matrículas legadas.
- \`EnrollmentCreationService\` cria a cobrança única pelo preço total do plano, vencendo na data de início, dentro da transação da matrícula e da auditoria.
- \`FinanceService\` registra pagamentos parciais, bloqueia concorrência na cobrança, rejeita excesso de saldo e grava eventos na cadeia de auditoria.
- \`FinanceService\` permite cancelar somente cobrança sem pagamentos após cancelamento contratual.
- A tela `financeiro.php` e as permissões RBAC `finance.*` foram entregues e cobertas pela CI. Permanecem pendentes os achados da revisão técnica registrada em `docs/revisao-financeira-2026-11-22.md`, além da validação manual/local.

A migration 010 deve ser executada somente após backup e conferência da versão/engine, seguindo o guia local.

## Critérios de aceite preliminares

- Uma matrícula nova cria exatamente uma cobrança com o valor total contratado.
- Um plano de três meses por R$ 300,00 gera uma cobrança de R$ 300,00, não três cobranças de R$ 100,00.
- Matrículas legadas não recebem cobranças retroativas automaticamente.
- Alterar o catálogo não altera snapshots nem cobranças anteriores.
- Falha na criação da cobrança reverte a matrícula.
- Não é possível duplicar a cobrança da mesma matrícula.
- Todo pagamento, ajuste ou estorno mantém histórico e auditoria.

## F-01 — Idempotência de pagamentos

- Migration 011 adiciona `chave_idempotencia` e `hash_requisicao` à tabela `pagamentos`, com unicidade por cobrança.
- O formulário gera um token aleatório de 128 bits por operação; reenvios do mesmo POST reutilizam o mesmo token.
- `FinanceService::registerPayment()` calcula SHA-256 dos campos normalizados (cobrança, ator, valor, forma, data e observação).
- Reenvio com a mesma chave e o mesmo conteúdo retorna o ID original sem criar novo pagamento nem novo evento de auditoria.
- Reutilização da chave com conteúdo diferente é recusada.
- A consulta idempotente ocorre depois do `SELECT ... FOR UPDATE` da cobrança, serializando requisições concorrentes para a mesma cobrança.
- Pagamentos anteriores à migration permanecem com as colunas novas nulas; a proteção se aplica aos novos registros.
- Testes de domínio/banco cobrem reenvio idêntico, conflito de conteúdo e preservação da contagem de lançamentos.
- Teste de concorrência inicia dois processos PHP com conexões PDO independentes, sincronizados atrás do lock da cobrança; ambos devem retornar o mesmo ID, com um único pagamento e um único evento de auditoria.

## F-02 — Pagamentos append-only

- Migration 012 cria gatilhos que bloqueiam INSERT direto fora do `FinanceService` e impedem UPDATE/DELETE em pagamentos confirmados.
- `FinanceService::registerPayment()` habilita a escrita somente durante o INSERT transacional e limpa a variável de sessão mesmo quando a operação falha.
- O teste financeiro tenta INSERT, UPDATE e DELETE diretamente e exige rejeição; a limpeza de fixtures usa uma variável de sessão reservada ao teardown automatizado.
- Correções financeiras futuras devem ser lançamentos compensatórios, nunca edição ou exclusão do pagamento original.
- A proteção por gatilhos reduz alterações acidentais por caminhos SQL da aplicação. Como em qualquer controle dentro do próprio banco, uma conta administrativa capaz de alterar gatilhos ou variáveis de sessão continua sendo uma fronteira privilegiada; o ambiente real deve usar usuário SQL de menor privilégio.

## F-03 — Matrícula cancelada e saldo em revisão

- `FinanceDashboardStatus` classifica a cobrança como **Em revisão** quando a matrícula foi cancelada, mas a cobrança ainda não recebeu decisão financeira.
- O resumo histórico mantém valor contratado e recebido; o saldo cobrável considera somente matrículas ativas e cobranças não canceladas.
- Saldo pendente de matrícula cancelada é mostrado separadamente como **Em revisão** e não é oferecido para novo pagamento.
- Cobrança com cancelamento financeiro concluído aparece como **Cancelada**, sem saldo aberto.
- Testes unitários e de integração cobrem o estado com pagamento parcial e saldo remanescente de R$ 250,00.

## F-04 — Histórico individual de pagamentos

- `FinancePaymentHistoryQuery::forCharges()` recebe os IDs das cobranças da página e busca todos os pagamentos em uma consulta parametrizada, evitando N+1.
- A consulta agrupa os registros por cobrança e ordena por data do pagamento e ID, do mais recente para o mais antigo.
- A interface exibe painel expansível com valor, data do pagamento, forma, observação, data/hora de registro, responsável e identificador.
- O instante de registro armazenado em UTC é convertido para o fuso da academia apenas na apresentação.
- O histórico é somente leitura: F-02 continua impedindo alteração ou exclusão de pagamentos confirmados.
- Testes de integração verificam agrupamento, ordenação, campos históricos e cobrança sem pagamentos.

## F-05 — Constraints de valores positivos

- Migration `013_financeiro_valores_positivos.sql` adiciona `CHECK (valor_total > 0.00)` em `cobrancas` e `CHECK (valor > 0.00)` em `pagamentos`.
- Os nomes são explícitos: `chk_cobrancas_valor_total_positivo` e `chk_pagamentos_valor_positivo`.
- Nenhum dado legado é alterado automaticamente; a migration deve interromper se encontrar valor inválido.
- Testes de integração tentam gravar cobrança e pagamento com zero e valores negativos diretamente no banco; verificam também que valores positivos válidos continuam aceitos.
- As exceções esperadas precisam mencionar as constraints nomeadas, evitando que outro erro (por exemplo, o guard de INSERT) seja confundido com a validação do CHECK.
- Para testar o CHECK de pagamentos, o teste habilita temporariamente o guard de INSERT do F-02 e o limpa em `finally`.
- A CI aplica a migration 013 nos jobs MySQL 8.0 e MariaDB 10.11, além do caminho de integração/browser.
- Requisito de compatibilidade: MySQL 8.0.16+; conferir dados inválidos antes da migration e não corrigir histórico automaticamente.

## F-06 — Concorrência no limite do saldo

- O teste mantém a linha da cobrança bloqueada na conexão principal e inicia dois processos PHP com conexões PDO independentes.
- As duas requisições usam chaves idempotentes distintas e tentam registrar R$ 200,00 cada em uma cobrança de R$ 300,00.
- Após a liberação do lock, uma requisição deve confirmar e a outra deve receber a rejeição de domínio por exceder o saldo restante.
- O teste verifica exatamente um pagamento de R$ 200,00 e exatamente um evento de auditoria para o lançamento aceito.
- O cenário anterior de F-01 permanece: reenvio concorrente da mesma operação e mesma chave deve retornar o mesmo ID sem duplicar pagamento.
- **Validação:** jobs financeiros da CI #1362 aprovados em MySQL 8.0 e MariaDB 10.11; ambos confirmaram exatamente um pagamento de R$ 200,00, uma recusa por saldo insuficiente e um evento de auditoria.

## F-07 — Formatação monetária sem ponto flutuante

- `FinanceMoneyFormatter` formata valores DECIMAL recebidos do PDO como texto e saldos calculados a partir de centavos inteiros.
- A tela financeira não converte dinheiro para `float`, nem usa `number_format((float) ...)`.
- `toCents()` centraliza a conversão exata de decimal para inteiro, com proteção contra overflow.
- O formatador usa separadores brasileiros de milhar e decimal, valida exatamente duas casas e rejeita entradas inválidas e `float`.
- Testes unitários cobrem valores zero, centavos, milhares, valores grandes, negativos e rejeição de tipos aproximados.
- **Validação:** suíte unitária, sintaxe PHP e integração CRUD aprovadas integralmente na CI #1385.

## F-08 — Política de datas retroativas

- A política permite registrar um recebimento passado, desde que o responsável informe uma observação/justificativa.
- Datas futuras são proibidas tanto no serviço quanto no formulário.
- O formulário apresenta data de pagamento visível, inicia em hoje no fuso da academia e usa hoje como limite máximo da interface.
- O serviço normaliza a observação e exige justificativa quando `data_pagamento` for anterior à data local da academia; não confia no limite HTML.
- Testes de integração verificam retroativo sem observação (rejeitado), retroativo com observação (aceito e preservado) e data futura (rejeitada mesmo com observação).
- Data e observação permanecem incluídas no fingerprint de idempotência e na consulta de histórico.
- **Estado:** implementado; aguardando validação na CI.

## Estado

**F-01 a F-04 validados na CI #1332; F-05 validado na CI #1361; F-06 validado integralmente na CI #1362; F-07 validado integralmente na CI #1385; F-08 implementado e aguardando CI.** A execução geral da CI ainda está concluindo os jobs de integração; não aplicar migrations no banco local sem backup e validação. PR #1 permanece Draft. Migration 013 documentada com pré-verificação dos dados e cautela para falha parcial dos dois comandos DDL. As migrations 011 e 012 estão incluídas na CI e no guia XAMPP. Não aplicar migrations no banco local sem backup e validação. PR #1 permanece Draft.
