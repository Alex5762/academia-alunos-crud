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

## Estado

**Núcleo transacional, interface e F-01 implementados na branch de trabalho.** A migration 011 está incluída na CI e no guia XAMPP. Aguardando execução dos jobs e revisão adicional de concorrência real; não aplicar migration no banco local sem backup e validação. PR #1 permanece Draft.
