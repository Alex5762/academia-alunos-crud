# Bloco 07.2 — Contratos de consulta e validação

> **Estado:** período, consultas OP-01 a OP-06/MT-01 a MT-05 e a primeira implementação de FR-01 a FR-05 integrados a `relatorios.php`. A validação de frequência foi adicionada ao job MySQL 8.0/MariaDB 10.11 e aguarda a nova CI. Financeiro, cartões do dashboard e CSV permanecem pendentes. Não houve migration ou alteração de schema.
> **Pré-requisito:** catálogo 07.1 e definições de indicadores aprovados.
> **Escopo desta etapa:** contratos de entrada/saída, datas, relógio, autorização, agregação e testes. Sem migrations, alteração de schema, telas ou exportação.

## 1. Objetivo

Definir uma fronteira estável entre páginas/controllers e consultas de relatório. As páginas não devem montar SQL, recalcular indicadores ou decidir permissões. O domínio de relatórios recebe filtros validados, instante de referência e permissões resolvidas no servidor; retorna apenas agregados autorizados.

## 2. Arquitetura mínima decidida

A revisão técnica do código existente recomenda não criar uma infraestrutura genérica de relatórios. As responsabilidades e decisões são:

| Responsabilidade | Decisão para o FitControl |
|---|---|
| Período | Manter `ReportPeriod` como objeto de valor imutável e pequeno, responsável por parsing estrito, intervalo inclusivo e limites civis/UTC. |
| Relógio | Não criar `AcademyClock` por enquanto. Capturar um único `DateTimeImmutable` no controller/requisição, em `America/Sao_Paulo`, e passá-lo às consultas que dependem de referência temporal. |
| Autorização | Não criar `ReportAccessPolicy`. Reutilizar `usuarioTemPermissao()` e `AccessControl`; autorizar a seção antes de instanciar/executar a consulta. |
| Consultas | Criar consultas específicas por domínio somente quando necessárias (operacional, matrículas, frequência, financeiro), com PDO e parâmetros; não criar interface/classe universal `ReportQuery`. |
| Orquestração | Não criar `ReportDashboardService` inicialmente. A página/controller coordena as consultas; só extrair serviço se repetição ou complexidade real justificar. |
| Resultado | Usar arrays associativos com chaves estáveis; não criar `ReportResult`/DTO no início sem necessidade observada. |
| Renderização | Manter renderização server-side em PHP; não criar API/payload JSON como requisito do MVP. |
| Exportação | O endpoint CSV deve reutilizar filtros e autorização, mas a camada de formatação/exportação só será abstraída se houver repetição concreta. |

Antes de implementar cada parte, verificar nomes/tipos reais de tabelas e colunas, classes e convenções presentes no branch. Não introduzir abstrações só para corresponder à nomenclatura deste documento.

## 3. Contrato de entrada

Uma consulta recebe:
- `startDate` e `endDate`: strings ISO `YYYY-MM-DD`, inclusivas, no calendário local, extraídas dos parâmetros HTTP `de` e `ate`.
- `asOf`: instante confiável único, capturado pelo controller no fuso `America/Sao_Paulo` e passado explicitamente às regras temporais.
- identidade/perfil autenticado: resolvido exclusivamente no servidor a partir da sessão; não aceitar objeto `permissions` fornecido pelo cliente. A autorização consulta `AccessControl` no servidor.

**Implementado:** `ReportPeriod::fromInput($startDate, $endDate, $asOf)` recebe valores mistos para rejeitar arrays/números. Ambos `null` aplicam o mês civil atual até a data de `asOf`; ausência parcial falha. O objeto é imutável e expõe datas locais/UTC, strings de data e quantidade de dias inclusivos. Datas futuras explícitas são aceitas. `relatorios.php` captura um único `asOf` local, valida o período antes de consultar e retorna HTTP 400 sem fallback silencioso. O formulário preserva `de`/`ate`.

Regras:
1. A entrada HTTP usa `de` e `ate`. Se ambos estiverem ausentes, aplicar o período padrão do primeiro dia do mês civil atual até hoje, em `America/Sao_Paulo`.
2. Se somente um de `de`/`ate` estiver ausente, rejeitar a requisição; não completar silenciosamente o campo faltante.
3. Fazer parsing estrito de datas, com round-trip de formatação ISO `YYYY-MM-DD`; rejeitar datas inexistentes como 2026-02-30 e qualquer formato inesperado.
4. Rejeitar início posterior ao fim e período maior que 365 dias civis inclusivos. Não substituir uma entrada inválida pelo período padrão.
5. Datas futuras são permitidas quando selecionadas explicitamente; isso não autoriza projeções nem pagamentos futuros. O serviço atual não aceita pagamentos futuros.
6. O filtro normalizado é imutável e reutilizado em todos os relatórios e exportações, preservando `de`/`ate` ao navegar entre seções.
7. O mesmo relógio confiável da requisição fornece `asOf` e as datas do período padrão; não usar `date()` espalhado em consultas.
8. Não aceitar nome de coluna, agrupamento SQL, tabela, ordem ou permissão como parâmetro livre.

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
- `period`: datas normalizadas quando aplicável; o período padrão é do primeiro dia do mês civil atual até hoje, salvo datas explícitas válidas.
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
- Preservar o período normalizado ao navegar entre seções/relatórios e ao solicitar CSV; cada destino revalida os parâmetros e permissões.

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

**Implementação FR-01 a FR-05:** `FrequencyReportQuery::forPeriod()` usa uma instrução SQL somente leitura para obter resumo e agregação diária no mesmo snapshot; completa datas e calcula ocorrências dos weekdays em PHP, iterando datas civis em UTC. O controller autoriza `attendance.view` antes de executar a consulta. O teste de banco cobre limites inclusivos, frequentador repetido, aluno ativo sem check-in, lacunas diárias, denominadores semanais diferentes, período de uma data e período sem registros. A aprovação final depende da nova execução da CI em MySQL 8.0 e MariaDB 10.11.

## 6. Fronteira de autorização

- Página do dashboard continua exigindo `dashboard.view`.
- Operacional exige `students.view`.
- Matrículas exige `enrollments.view`.
- Frequência exige `attendance.view`.
- Financeiro exige `finance.view`.
- A política usa `FitControl\Security\AccessControl::allows()` e o papel da sessão autenticada.
- Autorizar cada seção independentemente antes de instanciar/executar seu query object; a página global continua exigindo `dashboard.view`.
- Matriz: Administrador tem acesso integral; Gestor e Operador têm `students.view`, `enrollments.view` e `attendance.view`; apenas Administrador e Gestor têm `finance.view`.
- Revalidar em cada endpoint futuro, inclusive CSV.
- O payload da Visão geral do Operador não pode conter qualquer chave/valor ou metadado financeiro; a seção financeira é omitida integralmente e nenhum query object/consulta financeira é executado.
- Revalidar permissões no servidor em cada endpoint, incluindo CSV, sem confiar em visibilidade de link/interface.
- Teste deve provar não só HTTP 403 no relatório financeiro, mas também que a consulta financeira não foi executada na Visão geral do Operador.

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
- Ambos parâmetros `de`/`ate` ausentes aplicam mês atual até hoje; somente um ausente é rejeitado.
- Padrão usa `America/Sao_Paulo` e o mesmo relógio da requisição.
- Início = fim, intervalo de 365 dias inclusivos, intervalo de 366 dias, início > fim.
- Datas futuras explicitamente selecionadas são aceitas; não gerar projeções nem pagamentos futuros.
- Navegação entre relatórios e CSV preserva exatamente `de`/`ate` validados.
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

### Apresentação e acessibilidade
- Os cartões VG-01 a VG-06 aparecem nos dois grupos aprovados; Operador não recebe VG-05 nem espaço/placeholder financeiro.
- O painel de distribuição cadastral usa somente alunos ativos como denominador; zero alunos ativos ou ausência de grupos produz estado vazio e nunca divisão por zero.
- Pesquisa, filtros, listagem de alunos e atalho de cadastro permanecem disponíveis após a composição dos cards.
- Relatórios operacional/matrículas usam apresentação tabular; frequência oferece gráfico de barras diário com tabela/resumo equivalente; financeiro oferece resumo e tabela por forma.
- Zero, ausência, não aplicável, erro e falta de permissão são distinguíveis; nenhum número essencial depende de cor, hover ou tooltip.
- Verificar foco visível por teclado e contraste não textual 3:1 contra cores adjacentes (WCAG 2.2 SC 2.4.7/1.4.11); texto normal 4.5:1 (SC 1.4.3).
- Testar alvos de 24 × 24 CSS px ou exceções aplicáveis (SC 2.5.8), reflow a 320 CSS px (SC 1.4.10), temas claro/escuro e `prefers-reduced-motion`.
- Não exigir skeleton para páginas com renderização server-side síncrona.

### Segurança e regressão
- Página global exige `dashboard.view`; cada seção valida a permissão de domínio antes de instanciar/executar sua consulta.
- Operador recebe HTTP 403 ao acessar endpoint financeiro diretamente; seu dashboard não instancia nem executa consulta financeira e não contém metadados/valores financeiros.
- CSV revalida a permissão da seção no servidor; Operador não acessa exportação financeira.
- CSV contém apenas agregados, não contém PII, preserva o período validado e usa cabeçalhos UTF-8 estáveis com escaping correto.
- Campos textuais não confiáveis usam a neutralização tab-prefix da variante para planilhas; testar iniciadores de fórmula, variantes cobertas, aspas e separadores.
- Validar abertura no Excel/LibreOffice quando disponíveis; documentar que tabulação integra o campo e que a mitigação não é universal.
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
1. `ReportPeriod` estiver integrado aos controllers, com uma única captura de `asOf` por requisição e preservação dos filtros `de`/`ate` entre destinos;
2. autorização for testada no servidor antes de cada consulta;
3. formatos de saída e referências temporais estiverem estáveis;
4. testes de datas, matrícula, moeda e RBAC passarem em MySQL 8.0 e MariaDB 10.11;
5. nenhuma migration ou alteração de schema tiver sido introduzida sem necessidade comprovada e aprovação separada.

**Concluído nesta fatia:** `ReportPeriod`, integração HTTP, captura única de `asOf`, preservação de `de`/`ate`, HTTP 400 para período inválido, OP-01 a OP-06 e MT-01 a MT-05 em consultas específicas e teste integrado com fixtures transacionais. Frequência, financeiro, CSV e regressão visual permanecem pendentes.

Próximo incremento: implementar frequência FR-01 a FR-05, incluindo série diária com datas sem registros preenchidas com zero.
