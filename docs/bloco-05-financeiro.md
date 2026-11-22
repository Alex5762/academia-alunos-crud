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

## Decisões pendentes antes da migration e implementação

1. **Vencimento inicial:** usar a data de início da matrícula como vencimento padrão ou permitir que o funcionário escolha a data ao matricular?
2. **Pagamento parcial:** permitir vários pagamentos parciais até quitar a cobrança ou exigir pagamento integral em um único lançamento?
3. **Cancelamento de matrícula:** definir o tratamento de cobranças ainda não pagas e de cobranças já pagas. Pagamentos históricos não devem desaparecer; eventual devolução precisa ser um evento próprio.
4. **Ajuste excepcional de valor:** confirmar se haverá override pontual do preço do catálogo na matrícula, com permissão e justificativa, ou se o preço será sempre exatamente o do plano.

Nenhuma migration financeira foi criada nesta etapa. O modelo de tabelas e a migration 010 só devem ser definidos após fechar as regras pendentes e revisar os fluxos atuais de matrícula/cancelamento.

## Critérios de aceite preliminares

- Uma matrícula nova cria exatamente uma cobrança com o valor total contratado.
- Um plano de três meses por R$ 300,00 gera uma cobrança de R$ 300,00, não três cobranças de R$ 100,00.
- Matrículas legadas não recebem cobranças retroativas automaticamente.
- Alterar o catálogo não altera snapshots nem cobranças anteriores.
- Falha na criação da cobrança reverte a matrícula.
- Não é possível duplicar a cobrança da mesma matrícula.
- Todo pagamento, ajuste ou estorno mantém histórico e auditoria.

## Estado

**Modelagem parcialmente definida.** Regra de preço total e cobrança única automática aprovada em 22/11/2026. Pendências listadas acima precisam ser decididas antes da implementação.
