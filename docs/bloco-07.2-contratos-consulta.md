# Bloco 07.2 — Contratos de consulta e validação

> **Estado:** desenho técnico revisado; as decisões do catálogo estão sendo aprovadas por etapas. OP-01 a OP-06, MT-01 a MT-05, FR-01 a FR-05 e FN-04, FN-05 e FN-06 foram aprovados/definidos em 23/11/2026; os demais itens permanecem em revisão. Não representa implementação.
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
- Janela de alertas é calculada em calendário local; VG-06 e OP-06/7 dias consideram matrícula ATIVA com `fim_exclusivo_em` estritamente maior que `asOf` e menor ou igual a `asOf + 7 dias`. O limite final é inclusivo.
- Para OP-05 e OP-06, calcular `limit7 = asOfLocal + P7D` e `limit30 = asOfLocal + P30D` por calendário local; converter `asOf`, `limit7` e `limit30` para UTC antes da consulta.
- As janelas de 30 dias são cumulativas: incluem as matrículas dos primeiros 7 dias. Não somar OP-05/7 com OP-05/30 ou OP-06/7 com OP-06/30 como grupos exclusivos.
- Comparar `inicio_em`/`fim_exclusivo_em` armazenados como UTC com os limites UTC. O SQL deve reproduzir as fronteiras de `EnrollmentStatus::derive()`; datas locais convertidas para UTC representam os mesmos instantes.
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

### 5.1 Invariantes cadastrais usados por OP-01 a OP-03

OP-01 (total), OP-02 (ativos) e OP-03 (inativos) são fotografias atuais de `alunos`, sem filtro de período, e exigem `students.view`. Retornar os três em um único agregado; usar `COUNT(*)` e somas condicionais para `ativo = 1` e `ativo = 0`. A migration 015, fora da implementação de relatórios, adiciona `CHECK (ativo IN (0, 1))` e garante total = ativos + inativos.

### 5.2 Contrato de agregação operacional de matrículas — OP-04 a OP-06

OP-04, OP-05 e OP-06 são fotografias calculadas com um único `asOf` por requisição, sem filtro de período e sob `enrollments.view`. A consulta agregada retorna as quatro contagens de OP-04 e os totais de OP-05/OP-06 para 7 e 30 dias; valores zero devem ser apresentados como zero, sem omitir categorias.

- **OP-04:** reproduzir `EnrollmentStatus::derive()` com prioridade de CANCELADA (`cancelada_em IS NOT NULL`); em seguida AGENDADA (`inicio_em > :as_of_utc`); depois VENCIDA (`fim_exclusivo_em <= :as_of_utc`); caso contrário ATIVA. As condições são aplicadas somente após validar os intervalos.
- **OP-05:** contar matrículas não canceladas com `inicio_em > :as_of_utc` e `inicio_em <= :limit_7_utc` ou `:limit_30_utc`. Com intervalo válido, a condição identifica exatamente matrículas AGENDADAS.
- **OP-06:** contar matrículas não canceladas, com `inicio_em <= :as_of_utc`, `fim_exclusivo_em > :as_of_utc` e `fim_exclusivo_em <= :limit_7_utc` ou `:limit_30_utc`. Isso inclui somente matrículas ATIVAS que ainda não chegaram ao fim exclusivo.
- **Paridade VG-06:** OP-06/7 dias e VG-06 devem consumir o mesmo valor/resultado agregado, não implementações independentes que possam divergir.
- **Integridade defensiva:** no mesmo snapshot de leitura, retornar `invalid_interval_count = COUNT(*)` condicional para `fim_exclusivo_em <= inicio_em`. Se o resultado for maior que zero, descartar todas as contagens e devolver erro controlado; não mostrar métricas parciais, não classificar essas linhas como CANCELADA/AGENDADA/VENCIDA/ATIVA e não alterar os dados. Não adicionar migration ou índice para isso nesta etapa.
- **Tempo:** `asOf`, `limit7` e `limit30` são calculados em `America/Sao_Paulo` com `DateTimeImmutable`/`DateInterval` e convertidos para UTC antes de vincular parâmetros PDO. O filtro de datas selecionado pelo usuário não participa dessas métricas.

### 5.3 Contrato de consulta do relatório de matrículas — MT-01 a MT-05

MT-01 a MT-05 usam `enrollments.view` e o período local normalizado em limites UTC semiabertos (`:start_utc <= coluna AND coluna < :end_exclusive_utc`). Não usar `criado_em` como substituto das datas efetivas/eventos. A consulta deve verificar intervalos inválidos (`fim_exclusivo_em <= inicio_em`) no mesmo snapshot; se houver qualquer registro inválido, descartar todas as métricas do relatório e devolver erro controlado, sem correção automática.

- **Coorte de inícios efetivos (MT-01 a MT-04):** `inicio_em >= :start_utc AND inicio_em < :end_exclusive_utc`, com `fim_exclusivo_em > inicio_em` e (`cancelada_em IS NULL OR cancelada_em > inicio_em`). Assim, cancelamento anterior ou exatamente no início não conta como início realizado; cancelamento posterior ao início mantém o contrato na coorte.
- **MT-01:** contar essa coorte uma única vez por matrícula.
- **MT-02:** agrupar a mesma coorte por `plano_nome_contratado` (snapshot); nulo ou texto vazio é apresentado como “Plano não informado no snapshot”. Não consultar o catálogo atual para preencher histórico.
- **MT-03:** somar `valor_contratado` como DECIMAL apenas quando informado, agrupado pelo snapshot. Retornar também a quantidade de valores conhecidos; não converter NULL para zero e não usar float.
- **MT-04:** agrupar exatamente a mesma coorte por `origem` (ENUM `SISTEMA`/`LEGADO`); apresentar como dimensão secundária.
- **MT-05:** contar eventos com `cancelada_em >= :start_utc AND cancelada_em < :end_exclusive_utc`, sem condicionar a `cancelada_em > inicio_em`. Cancelamentos antes ou exatamente no início pertencem a MT-05 porque houve um evento de cancelamento, embora não exista início efetivo.
- **Reconciliação:** a soma dos grupos de MT-02 e MT-04 deve reconciliar com MT-01. MT-03 reconcilia o subconjunto com `valor_contratado IS NOT NULL`; snapshots nulos/vazios permanecem em grupo explícito, sem descartar linhas.

### 5.4 Testes de contrato para FR-01 a FR-05

- Período com zero check-ins: FR-01 = 0, FR-02 = 0, FR-03 contém zeros em todas as datas e FR-05 é não aplicável.
- Um aluno com entradas em vários dias incrementa FR-01 por dia, mas conta uma vez em FR-02; FR-05 reflete a média de dias por aluno frequentador.
- A unicidade `(aluno_id, data_local)` deve impedir duplicidade diária e sustentar FR-01 >= FR-02.
- Dias sem registros continuam presentes na série FR-03, sem inferir funcionamento da academia.
- Para FR-04, usar o número real de ocorrências do weekday no calendário; feriados e fechamentos não são conhecidos pelo modelo atual.
- O resultado de FR-05 não pode exceder o número de datas do período quando a regra de unicidade está íntegra.

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
- Agregar no banco; evitar carregar linhas individuais para somar em PHP. Para FN-01 a FN-03, preferir uma única consulta agregada por `forma_pagamento` com `COUNT(*)` e `SUM(valor)`; os totais FN-01/FN-02 podem ser reconciliados a partir desses grupos com centavos inteiros, evitando consultas separadas que observem populações diferentes.
- Consultas de domínio devem ser somente leitura.
- Evitar N+1; agrupar indicadores por consultas previsíveis e documentadas.
- Agrupamentos dinâmicos devem usar allowlist fechada; nunca interpolar entrada do usuário.
- Não adicionar índices preventivos ou migrations nesta etapa. Primeiro medir planos/consultas e demonstrar necessidade.
- Não adicionar cache até existir medição; se futuramente adicionado, chave deve incluir período, instante/referência e conjunto de permissões.
- Não usar `SQL_CALC_FOUND_ROWS`, nem buscar colunas não necessárias.
- As consultas de matrícula devem respeitar cancelamentos e snapshots; consultas financeiras devem manter cobrança cancelada, saldo cobrável e saldo em revisão separados. FN-05 é fotografia atual: somar `max(valor_total - pagamentos_acumulados, 0)` para cobranças não canceladas financeiramente vinculadas a matrículas não canceladas, independentemente do período selecionado; reutilizar `FinanceDashboardStatus::collectibleBalanceCents()` ou provar paridade do agregado SQL.
- **FN-01 a FN-03 aprovados em 23/11/2026:** consultar diretamente `pagamentos`, com `data_pagamento >= :start_date AND data_pagamento <= :end_date`; não usar `registrado_em_utc` nem filtrar por estado atual de cobrança/matrícula. FN-01 é a soma, FN-02 a contagem, FN-03 a soma agrupada por `forma_pagamento`. Preferir uma consulta única `GROUP BY forma_pagamento` com `COUNT(*)` e `SUM(valor)` para todos os três valores; inicializar totais em zero e derivá-los dos grupos, mantendo valores como DECIMAL/string ou centavos inteiros. As cinco formas são a allowlist fixa do ENUM; sem pagamentos, a consulta não retorna grupos e FN-03 fica vazio. Os grupos devem reconciliar com FN-01/FN-02.
- **FN-04 aprovado:** filtrar `cobrancas.data_vencimento` com limites `>= :start_date` e `<= :end_date`; excluir `cobrancas.cancelada_em_utc IS NOT NULL`; agrupar por `matriculas.cancelada_em IS NULL` (grupo regular) versus `IS NOT NULL` (grupo “Em revisão”). Para cada grupo, retornar `COUNT(c.id)` e `SUM(c.valor_total)` como valor nominal original. O grupo regular inclui matrículas agendadas, ativas e vencidas, desde que não canceladas. A classificação reflete a situação atual, não a situação histórica na data do vencimento. Não juntar `pagamentos` nesta consulta, pois o indicador não calcula saldo e o join poderia multiplicar cobranças.
- **FN-06 aprovado em 23/11/2026:** calcular a fotografia atual pela soma de `max(valor_total - pagamentos_acumulados, 0)` somente quando `cobrancas.cancelada_em_utc IS NULL` e `matriculas.cancelada_em IS NOT NULL`. Pré-agregar pagamentos por cobrança antes do JOIN. Reutilizar `FinanceDashboardStatus::reviewBalanceCents()` ou comprovar paridade do agregado SQL com a regra canônica. Não aplicar filtro histórico de datas. A legenda deve informar que os valores exigem análise e não representam automaticamente reembolso; saldo zero não determina se há devolução de pagamentos anteriores.

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
- CANCELADA antes do início (prioridade sobre demais estados).
- AGENDADA antes do início e início exatamente em `asOf` (já não é agendada).
- ATIVA em `asOf`, no instante imediatamente anterior ao fim; VENCIDA exatamente no fim exclusivo.
- OP-05: início exatamente em `asOf` excluído; instantes `asOf < inicio <= limit7/limit30` incluídos; 30 dias inclui 7.
- OP-06: fim exatamente em `asOf` excluído; fim exatamente em `limit7/limit30` incluído; somente matrículas ATIVAS.
- OP-06/7 dias idêntico a VG-06.
- Intervalo inválido (`fim_exclusivo_em <= inicio_em`) faz a consulta falhar de modo controlado e sem métricas parciais.
- Status cadastral do aluno independente da situação contratual.
- Paridade entre classificação de domínio e consulta agregada em MySQL 8.0 e MariaDB 10.11.

### Frequência
- Usar `checkins.data_local` com início/fim inclusivos; não converter para UTC.
- FR-01 = `COUNT(*)`; FR-02 = `COUNT(DISTINCT aluno_id)` no mesmo período; para o mesmo filtro, FR-01 >= FR-02 por causa da unicidade diária por aluno.
- FR-03 retorna uma linha por data civil do intervalo; completar dias sem registros com zero na apresentação. Zero significa ausência de registro, não dia confirmado como aberto.
- FR-04: média por ocorrência de cada dia ISO (segunda–domingo), total bruto e denominador; média com uma casa decimal apenas na apresentação. O denominador conta todas as datas daquele weekday no calendário do intervalo, independentemente de feriados/fechamentos; não chamar de dia aberto.
- Período de uma única data: apenas o weekday correspondente tem denominador 1; os demais exibem média não aplicável, total 0 e ocorrências 0.
- Período com denominadores diferentes por weekday: dividir pelo número real de ocorrências do calendário, não por uma constante semanal.
- Weekday com ocorrências e zero check-ins: média real 0,0; weekday sem ocorrências: média não aplicável.
- **FR-05:** calcular `FR-01 / FR-02` sobre exatamente o mesmo conjunto e período. Como a unicidade limita um check-in por aluno/dia, o resultado é média de dias com entrada por frequentador; arredondar para uma casa decimal somente na apresentação. Não incluir alunos ativos sem entrada. Se FR-02 = 0, retornar não aplicável (—), nunca dividir por zero nem retornar 0,0.

### Financeiro
- **FN-01:** somar `pagamentos.valor` filtrando `data_pagamento >= :start_date AND data_pagamento <= :end_date`; ambas as datas são inclusivas. Lançamentos retroativos entram pela data declarada, mesmo quando `registrado_em_utc` pertence a outro dia.
- **FN-02:** `COUNT(*)` sobre exatamente o mesmo conjunto de FN-01; cada lançamento é contado uma vez, inclusive cada parcela/pagamento parcial individual.
- **FN-03:** agrupar `SUM(valor)` por `forma_pagamento` sobre exatamente o conjunto de FN-01; usar a allowlist fixa do ENUM (`DINHEIRO`, `PIX`, `TRANSFERENCIA`, `CARTAO`, `OUTRO`).
- Reconciliação: soma dos grupos de FN-03 = FN-01; soma das contagens auxiliares por forma = FN-02. Não arredondar valores intermediários para float.
- Se não houver linhas no período: FN-01 = `0.00`, FN-02 = `0`, FN-03 retorna coleção vazia e a apresentação exibe estado vazio.
- Não excluir pagamentos por cancelamento posterior de matrícula ou por status atual da cobrança; FN-01 a FN-03 consultam `pagamentos` sem JOIN a `cobrancas`/`matriculas` que altere a população histórica.
- Pagamento parcial e integral.
- Cobrança cancelada financeiramente.
- FN-01 a FN-03: ambos os limites de `data_pagamento` inclusivos; registrar pagamento retroativo em um dia e `registrado_em_utc` em outro para confirmar que a data declarada comanda a inclusão; excluir registros fora do intervalo.
- FN-02: vários pagamentos parciais para uma cobrança contam como vários lançamentos; FN-01 soma seus valores.
- FN-03: distribuir todos os cinco valores ENUM existentes, reconciliar valores com FN-01 e contagens com FN-02; método ausente não cria linha artificial.
- FN-01 a FN-03: incluir pagamentos vinculados a matrícula que venha a ser cancelada depois; consulta não deve excluir pelo estado atual nem duplicar por JOIN.
- Período sem pagamentos: soma decimal zero, quantidade zero e FN-03 vazio.
- FN-04: datas de vencimento nos dois limites inclusivos; exclusão de cobrança cancelada financeiramente; separação entre matrícula não cancelada e cancelada/em revisão; matrícula agendada, ativa e vencida no grupo não cancelado; cobrança com pagamento parcial ou integral continua somando o valor original; ausência de duplicação por múltiplos pagamentos.
- Matrícula cancelada com saldo em revisão.
- Relatório de matrículas: início efetivo sem cancelamento, cancelamento após início, cancelamento antes do início e cancelamento exatamente no início; o primeiro grupo conta em MT-01 a MT-04 somente quando houve intervalo positivo, enquanto MT-05 conta todos os eventos no período. Reconciliar MT-02/MT-04 com MT-01 e validar valores NULL de MT-03.
- FN-05: agregar pagamentos por cobrança antes do relacionamento; incluir somente cobrança não cancelada financeiramente ligada a matrícula não cancelada; calcular `max(valor_total - pagamentos_acumulados, 0)`; comprovar paridade com `FinanceDashboardStatus::collectibleBalanceCents()`; não aplicar filtro histórico de datas; saldo parcial, quitado, cancelamento financeiro e matrícula cancelada tratados corretamente.
- FN-06: incluir matrícula cancelada com cobrança sem pagamentos (saldo total), pagamento parcial (saldo restante) e pagamento integral (saldo zero); excluir cobrança cancelada financeiramente; comprovar paridade entre o agregado SQL pré-agregado e `FinanceDashboardStatus::reviewBalanceCents()`. Deixar explícito que saldo zero não calcula nem exclui eventual reembolso.
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
- Antes de fechar a consulta FN-01 a FN-03, executar `EXPLAIN` com um intervalo representativo em MySQL 8.0 e MariaDB 10.11. A migration 010 declara `idx_pagamentos_cobranca_data_id (cobranca_id, data_pagamento, id)`, cujo primeiro campo não é o filtro do relatório; não presumir que esse índice resolve uma busca apenas por data. Registrar plano/custo observado. Não criar índice nem migration preventiva; se a medição mostrar varredura/custo incompatível, propor índice específico em decisão separada e testar impacto nas escritas.
- As definições FN-05 e FN-06 devem ser validadas separadamente contra `collectibleBalanceCents()` e `reviewBalanceCents()`, respectivamente; pré-agregar pagamentos por cobrança para evitar soma duplicada por joins de 1:N.

## 11. Critério de saída da 07.2

A etapa só é considerada pronta quando:
1. contratos de período e relógio estão implementados e cobertos;
2. autorização é testada no servidor antes de cada consulta;
3. formatos de saída e referências temporais estão estáveis;
4. testes de datas, matrícula, moeda e RBAC passam em MySQL 8.0 e MariaDB 10.11;
5. nenhuma migration ou alteração de schema foi introduzida sem necessidade comprovada e aprovação separada.

Após essa etapa, a 07.3 pode implementar os relatórios operacional e de matrículas sobre os contratos aprovados.
