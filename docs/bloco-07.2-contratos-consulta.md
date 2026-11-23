# Bloco 07.2 — Contratos de consulta e validação

> **Estado:** desenho técnico revisado; as decisões do catálogo estão sendo aprovadas por etapas. FN-04 e FN-05 foram aprovados em 23/11/2026; FN-06 e demais itens permanecem em revisão. Não representa implementação.
> **Pré-requisito:** catálogo 07.1 e definições de indicadores aprovados.
> **Escopo desta etapa:** contratos de entrada/saída, datas, relógio, autorização, agregação e testes. Sem migrations, alteração de schema, telas ou exportação.

## 1. Objetivo

Definir uma fronteira estável entre páginas/controllers e consultas de relatório. As páginas não devem montar SQL, recalcular indicadores ou decidir permissões. O domínio de relatórios recebe filtros validados, instante de referência e permissões resolvidas no servidor; retorna apenas agregados autorizados.

## 2. Componentes propostos

A nomenclatura final deve seguir os padrões reais do repositório, mas as responsabilidades são:

| Componente | Responsabilidade | Não deve fazer |
|---|---|---|
| `ReportPeriod` | Validar datas ISO reais, ordem e máximo de 365 dias inclusivos; expor limites civis e datas normalizadas | Consultar banco ou ler relógio global |
| `AcademyClock` (ou adaptador equivalente) | Fornecer um único `DateTimeImmutable` no fuso `America/Sao_Paulo` por requisição | Ser instanciado separadamente por cada indicador |
| `ReportAccessPolicy` | Determinar se cada seção pode ser consultada usando `AccessControl` | Confiar em parâmetros do cliente ou só ocultar elementos na interface |
| `ReportQuery` / serviços por domínio | Executar consultas agregadas e parametrizadas para operacional, matrículas, frequência e financeiro | Renderizar HTML/CSV ou alterar registros |
| `ReportDashboardService` | Orquestrar indicadores permitidos, compartilhando período e instante de referência | Duplicar fórmulas dos relatórios detalhados |
| `ReportResult` (DTO/array tipado conforme padrão existente) | Transportar valores agregados e metadados de referência | Incluir PII, entidades completas ou dados de seções não autorizadas |

Antes da implementação, verificar se o projeto já possui abstrações de relógio, filtros, DTOs e consultas reutilizáveis. Não criar uma segunda infraestrutura para resolver o mesmo problema.

## 3. Contrato de entrada

Uma consulta recebe:
- `startDate`: data ISO `YYYY-MM-DD`, inclusiva, no calendário local.
- `endDate`: data ISO `YYYY-MM-DD`, inclusiva, no calendário local.
- `asOf`: instante confiável único, obtido no fuso da academia.
- identidade/perfil autenticado: resolvido exclusivamente no servidor a partir da sessão; não aceitar objeto `permissions` fornecido pelo cliente. A política de acesso consulta `AccessControl` no servidor.

Regras:
1. Fazer parsing estrito de datas (incluindo round-trip de formatação); rejeitar datas inexistentes como 2026-02-30.
2. Rejeitar campos ausentes, formato inesperado, início posterior ao fim e período maior que 365 dias inclusivos.
3. O padrão de mês atual é aplicado no controller/factory de filtros usando o mesmo relógio da requisição; não usar `date()` espalhado em consultas.
4. O filtro normalizado é imutável e reutilizado em todos os relatórios e exportações.
5. Não aceitar nome de coluna, agrupamento SQL, tabela, ordem ou permissão como parâmetro livre.
6. Filtros futuros podem ser válidos para relatórios de início contratual e vencimento; não inferir pagamentos futuros.

## 4. Contrato de tempo

Criar uma representação explícita dos limites para dois tipos de coluna:

### 4.1 DATETIME UTC

- Início: 00:00:00 local na data inicial, convertido para UTC.
- Fim exclusivo: 00:00:00 local no dia seguinte à data final, convertido para UTC.
- SQL: `coluna >= :start_utc AND coluna < :end_exclusive_utc`.
- Nunca envolver a coluna em `DATE()` ou função de conversão no filtro.

### 4.2 DATE local

- SQL: `coluna >= :start_date AND coluna <= :end_date`.
- Não converter para UTC.

### 4.3 Relógio e janelas móveis

- O serviço de dashboard captura `asOf` uma única vez e compartilha com todos os serviços.
- Situação de matrícula deve reutilizar `EnrollmentStatus::derive()`, ou uma tradução SQL rigorosamente equivalente, com testes de paridade.
- Janela de alertas é calculada em calendário local; VG-06 considera matrícula ATIVA com `fim_exclusivo_em` estritamente maior que `asOf` e menor ou igual a `asOf + 7 dias`. O limite final é inclusivo.
- A conversão de `asOf` e do limite para UTC ocorre antes da consulta.
- Não usar relógio do banco em algumas consultas e relógio PHP em outras.

## 5. Contrato de saída

Cada seção autorizada retorna:
- `reference`: `current`, `selected_period` ou `rolling_window`.
- `period`: datas normalizadas quando aplicável.
- `generatedAt`: instante `asOf` usado.
- `metrics`: mapa de chaves estáveis e valores numéricos/decimais serializáveis sem float para moeda.

Regras:
- Omitir completamente seção não autorizada do payload; não enviar `available=false`, valores, totais, chaves de métricas ou metadados que permitam inferir dados protegidos.
- Zero é um resultado real e distinto de falha.
- Erro de consulta deve resultar em erro controlado; nunca converter exceção em zero.
- Moeda deve permanecer DECIMAL/string ou centavos inteiros; formatar na camada de apresentação.
- Para o indicador VG-05/FN-01, usar o rótulo aprovado “Recebido no período” e legenda que esclareça ser a soma dos pagamentos registrados pela `data_pagamento` informada, não comprovação de liquidação bancária.
- Não incluir nomes, e-mails, telefones, observações, IDs individuais ou outros dados pessoais.
- O dashboard não calcula métricas: apenas apresenta o resultado retornado.

## 6. Fronteira de autorização

- Página do dashboard continua exigindo `dashboard.view`.
- Operacional exige `students.view`.
- Matrículas exige `enrollments.view`.
- Frequência exige `attendance.view`.
- Financeiro exige `finance.view`.
- A política usa `FitControl\Security\AccessControl::allows()` e o papel da sessão autenticada.
- Autorizar antes de instanciar/executar o query object da seção.
- Revalidar em cada endpoint futuro, inclusive CSV.
- O payload da Visão geral do Operador não pode conter qualquer chave/valor financeiro.
- Teste deve provar não só HTTP 403 no relatório financeiro, mas também que a consulta financeira não foi executada.

## 7. Consultas e desempenho

- Usar prepared statements PDO e parâmetros nomeados.
- Agregar no banco; evitar carregar linhas individuais para somar em PHP.
- Consultas de domínio devem ser somente leitura.
- Evitar N+1; agrupar indicadores por consultas previsíveis e documentadas.
- Agrupamentos dinâmicos devem usar allowlist fechada; nunca interpolar entrada do usuário.
- Não adicionar índices preventivos ou migrations nesta etapa. Primeiro medir planos/consultas e demonstrar necessidade.
- Não adicionar cache até existir medição; se futuramente adicionado, chave deve incluir período, instante/referência e conjunto de permissões.
- Não usar `SQL_CALC_FOUND_ROWS`, nem buscar colunas não necessárias.
- As consultas de matrícula devem respeitar cancelamentos e snapshots; consultas financeiras devem manter cobrança cancelada, saldo cobrável e saldo em revisão separados. FN-05 é fotografia atual: somar `max(valor_total - pagamentos_acumulados, 0)` para cobranças não canceladas financeiramente vinculadas a matrículas não canceladas, independentemente do período selecionado; reutilizar `FinanceDashboardStatus::collectibleBalanceCents()` ou provar paridade do agregado SQL.
- **FN-04 aprovado:** filtrar `cobrancas.data_vencimento` com limites `>= :start_date` e `<= :end_date`; excluir `cobrancas.cancelada_em_utc IS NOT NULL`; agrupar por `matriculas.cancelada_em IS NULL` (grupo regular) versus `IS NOT NULL` (grupo “Em revisão”). Para cada grupo, retornar `COUNT(c.id)` e `SUM(c.valor_total)` como valor nominal original. O grupo regular inclui matrículas agendadas, ativas e vencidas, desde que não canceladas. A classificação reflete a situação atual, não a situação histórica na data do vencimento. Não juntar `pagamentos` nesta consulta, pois o indicador não calcula saldo e o join poderia multiplicar cobranças.

## 8. Matriz mínima de testes

### Datas e relógio
- Data válida, bissexto, data impossível, formato inválido.
- Início = fim, intervalo de 365 dias, intervalo de 366 dias, início > fim.
- Virada de mês/ano e conversão local/UTC.
- Limites exatos do início e fim semiaberto para DATETIME.
- Data final inclusiva para DATE.
- Uma única chamada de relógio por resposta agregada.
- Janela móvel de 7 dias com teste no limite exato.

### Matrículas
- CANCELADA antes do início.
- AGENDADA antes do início.
- ATIVA no instante anterior ao fim.
- VENCIDA exatamente no fim exclusivo.
- Status cadastral do aluno independente da situação contratual.
- Paridade entre classificação de domínio e consulta agregada.

### Frequência
- Data local inclusiva inicial/final.
- Aluno com check-ins em dias diferentes.
- Dias sem registros preenchidos com zero na série.
- FR-04: média por ocorrência de cada dia ISO (segunda–domingo), total bruto e denominador; média com uma casa decimal apenas na apresentação.
- Período de uma única data: apenas o weekday correspondente tem denominador 1; os demais exibem média não aplicável, total 0 e ocorrências 0.
- Período com denominadores diferentes por weekday: verificar que a média divide pelo número real de ocorrências de cada weekday, não por uma constante semanal.
- Weekday com ocorrências e zero check-ins: média real 0,0; weekday sem ocorrências: média não aplicável.

### Financeiro
- Pagamento por `data_pagamento`, mesmo quando `registrado_em_utc` pertence a outro dia.
- Pagamento parcial e integral.
- Cobrança cancelada financeiramente.
- FN-04: datas de vencimento nos dois limites inclusivos; exclusão de cobrança cancelada financeiramente; separação entre matrícula não cancelada e cancelada/em revisão; matrícula agendada, ativa e vencida no grupo não cancelado; cobrança com pagamento parcial ou integral continua somando o valor original; ausência de duplicação por múltiplos pagamentos.
- Matrícula cancelada com saldo em revisão.
- FN-05: agregar pagamentos por cobrança antes do relacionamento; incluir somente cobrança não cancelada financeiramente ligada a matrícula não cancelada; calcular `max(valor_total - pagamentos_acumulados, 0)`; comprovar paridade com `FinanceDashboardStatus::collectibleBalanceCents()`; não aplicar filtro histórico de datas; saldo parcial, quitado, cancelamento financeiro e matrícula cancelada tratados corretamente.
- Valores DECIMAL/centavos sem float.
- Perfil Operador não aciona consulta financeira e não recebe campos financeiros.

### Segurança e regressão
- Período inválido não chega ao SQL.
- Parâmetros não controlam identificadores SQL.
- Exceção de banco não vira resultado zero.
- Serviços não executam INSERT/UPDATE/DELETE.
- Nenhuma PII na saída agregada.
- MySQL 8.0 e MariaDB 10.11.

## 9. Frequência semanal — regra específica FR-04

Para evitar uma consulta dependente de recursos específicos de calendário do MySQL/MariaDB, o período normalizado fornece as datas civis inicial/final. A camada PHP pode percorrer esse intervalo com `DateTimeImmutable` e contar as ocorrências de cada weekday ISO (1–7). A consulta de check-ins agrega `COUNT(*)` por weekday de `data_local`; o serviço combina os sete buckets com os denominadores calculados. A apresentação calcula/mostra a média com uma casa decimal, mantendo total e ocorrências. Não consultar o banco sete vezes nem buscar linhas individuais para contar.

## 10. Pontos de atenção que exigem verificação no código antes da implementação

- Confirmar se a implementação atual de `EnrollmentStatus::derive()` recebe `DateTimeImmutable` no fuso local ou converte internamente; o adapter SQL precisa reproduzir exatamente a mesma fronteira.
- Confirmar o comportamento do serviço financeiro para pagamentos retroativos e a semântica de `data_pagamento`; a legenda do indicador não deve sugerir liquidação bancária.
- Confirmar os nomes e tipos reais das colunas no schema/migrations antes de escrever qualquer SQL; este documento define responsabilidades, não autoriza presumir nomes.
- A definição de saldo em revisão/cobrável deve ser validada contra a regra de cancelamento e pagamentos acumulados existente, sem introduzir soma duplicada por joins de 1:N.

## 11. Critério de saída da 07.2

A etapa só é considerada pronta quando:
1. contratos de período e relógio estão implementados e cobertos;
2. autorização é testada no servidor antes de cada consulta;
3. formatos de saída e referências temporais estão estáveis;
4. testes de datas, matrícula, moeda e RBAC passam em MySQL 8.0 e MariaDB 10.11;
5. nenhuma migration ou alteração de schema foi introduzida sem necessidade comprovada e aprovação separada.

Após essa etapa, a 07.3 pode implementar os relatórios operacional e de matrículas sobre os contratos aprovados.
