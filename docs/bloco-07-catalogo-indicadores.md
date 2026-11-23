# Bloco 07.1 — Catálogo e contrato dos indicadores

> **Estado:** definições revisadas; decisões aprovadas até 23/11/2026: direção da Visão geral gerencial, OP-01 a OP-06, nomenclatura “Recebido no período”, regra FR-04 e indicadores FN-04, FN-05 e FN-06. Os demais indicadores/decisões de experiência e permissão continuam em revisão. Nenhuma consulta, tela ou exportação do Bloco 07 foi implementada.
> **Branch:** `feat/dashboard-portfolio`.
> **Documento principal:** [Bloco 07 — Relatórios e experiência](bloco-07-relatorios-experiencia.md).

Este documento transforma a proposta do Bloco 07 em definições verificáveis. Os indicadores devem respeitar o significado real dos registros, reutilizar as regras de domínio e nunca apresentar uma estimativa como fato histórico.

## 1. Convenções temporais comuns

### 1.1 Relatórios com período selecionado

- A interface recebe data inicial e data final no calendário local da academia (`America/Sao_Paulo`); ambas são inclusivas para o usuário.
- O padrão é o mês civil atual.
- O período máximo é de 365 dias civis inclusivos.
- A validação exige datas reais no formato esperado, início menor ou igual ao fim e intervalo dentro do limite.
- Para filtrar colunas DATETIME armazenadas em UTC, a aplicação constrói o início local às 00:00:00 e o primeiro instante local do dia seguinte ao fim; converte os dois instantes para UTC e consulta o intervalo semiaberto `[início_utc, fim_exclusivo_utc)`.
- Para colunas DATE que já representam a data civil local, a consulta compara diretamente `[data_inicial, data_final]`; não converte para UTC.
- O instante atual deve ser obtido uma única vez por requisição, no fuso da academia, e reutilizado em todos os indicadores de fotografia atual e alertas.

Não usar `DATE()` sobre DATETIME UTC para simular um dia local. Não depender de `CONVERT_TZ()` no MySQL, pois a disponibilidade das tabelas de fuso pode variar entre instalações. A aplicação calcula os limites confiáveis antes de executar consultas parametrizadas.

### 1.2 Colunas temporais por domínio

| Coluna | Tipo/semântica | Uso correto |
|---|---|---|
| `matriculas.inicio_em` | DATETIME UTC correspondente ao início contratual local | Situação atual, inícios de contrato e matrículas agendadas |
| `matriculas.fim_exclusivo_em` | DATETIME UTC correspondente ao primeiro instante fora da vigência | Vencimento contratual e situação atual; o próprio instante final já não pertence à vigência |
| `matriculas.cancelada_em` | DATETIME UTC do cancelamento | Classificação como cancelada e cancelamentos dentro de um período |
| `checkins.data_local` | DATE local da academia | Filtros, totais e séries diárias de check-ins |
| `pagamentos.data_pagamento` | DATE informada como data do pagamento | Período de pagamentos lançados por data declarada |
| `pagamentos.registrado_em_utc` | DATETIME UTC do registro no sistema | Auditoria/instante de lançamento; não substitui a data de pagamento |
| `cobrancas.data_vencimento` | DATE local da data de vencimento | Relatório de cobranças com vencimento no período |
| `cobrancas.cancelada_em_utc` | DATETIME UTC do cancelamento financeiro | Excluir cobranças canceladas dos saldos atuais |

## 2. Catálogo da Visão geral gerencial

**Decisão aprovada em 23/11/2026:** o indicador principal de pagamentos será denominado **“Recebido no período”**. Sua fórmula é a soma dos lançamentos `pagamentos.valor` cuja `data_pagamento` pertence ao período selecionado. O rótulo não deve ser substituído por “faturamento”, “lucro” ou “receita líquida”, pois o sistema não realiza conciliação bancária nem modela estornos neste escopo.

A Visão geral é a camada resumida do dashboard atual. Deve exibir poucos indicadores de leitura rápida, com atalhos para os relatórios de origem. O filtro de período afeta somente os indicadores de movimentação; não altera a fotografia atual nem a janela fixa de alertas próximos.

| ID | Indicador e rótulo | Cálculo e referência | Fonte | Permissão | Destino |
|---|---|---|---|---|---|
| VG-01 | Alunos ativos | Contagem de alunos com `ativo = 1` no instante da consulta. Fotografia cadastral atual. | `alunos.ativo` | `students.view` | Relatório operacional |
| VG-02 | Matrículas vigentes | Contagem de matrículas cuja situação derivada é `ATIVA` no instante da consulta, conforme a seção 3. Não depende do status cadastral do aluno. | `matriculas` | `enrollments.view` | Relatório de matrículas |
| VG-03 | Check-ins no período | Quantidade de linhas de check-in cuja `data_local` esteja entre as datas selecionadas, inclusive. | `checkins.data_local` | `attendance.view` | Relatório de frequência |
| VG-04 | Alunos com check-in no período | Quantidade distinta de `aluno_id` com ao menos um check-in no período. Um aluno é contado uma vez no período, mesmo que tenha vários dias de presença. | `checkins.aluno_id`, `checkins.data_local` | `attendance.view` | Relatório de frequência |
| VG-05 | Recebido no período | Soma de `pagamentos.valor` para lançamentos cuja `data_pagamento` esteja no período, inclusive. É a soma dos pagamentos registrados para essas datas, não lucro nem fluxo líquido com estornos. | `pagamentos.valor`, `pagamentos.data_pagamento` | `finance.view` | Relatório financeiro |
| VG-06 | Matrículas terminando em até 7 dias | Contagem de matrículas atualmente `ATIVAS` cujo `fim_exclusivo_em` esteja estritamente depois de agora e até agora + 7 dias de calendário local, incluindo o limite final. | `matriculas.inicio_em`, `matriculas.fim_exclusivo_em`, `matriculas.cancelada_em` | `enrollments.view` | Relatório de matrículas |

### 2.1 Regras de apresentação

- VG-01 e VG-02 são fotografias atuais; o seletor de período não os altera.
- VG-03, VG-04 e VG-05 usam o período visível no filtro, iniciado no mês civil atual.
- VG-06 é sempre uma janela móvel a partir do instante atual; o filtro de relatório não a altera.
- VG-05 só é calculado e enviado ao cliente após autorização de `finance.view`. Para perfis sem essa permissão, não retornar o campo em HTML, JSON, atributos, dados de gráfico ou CSV.
- Cada indicador deve ter rótulo acessível, valor, descrição da referência temporal e link para o relatório que explica sua composição.
- O número zero é um resultado válido. Zero não pode ser usado para representar falha, indisponibilidade ou falta de permissão.
- Quando uma seção não puder ser consultada por falta de permissão, ela deve ser omitida; não mostrar um cartão com valor zero.
- Os cartões não devem exibir percentuais de crescimento, setas de tendência ou comparação com mês anterior nesta versão.
- Os agregadores/serviços dos relatórios são a fonte de verdade; a Visão geral não pode duplicar fórmulas com SQL separado.

## 3. Definição canônica de situação de matrícula

A situação não é persistida; é derivada no instante consultado conforme `EnrollmentStatus::derive()`, com datas contratuais convertidas de UTC para `America/Sao_Paulo`:

| Situação | Condição |
|---|---|
| CANCELADA | `cancelada_em` possui valor. Tem prioridade sobre as demais condições, inclusive cancelamento anterior ao início previsto. |
| AGENDADA | Não cancelada e instante atual anterior a `inicio_em`. |
| VENCIDA | Não cancelada e instante atual igual ou posterior a `fim_exclusivo_em`. |
| ATIVA | Não cancelada, início menor ou igual ao instante atual e instante atual estritamente anterior ao fim exclusivo. |

O status cadastral do aluno (`alunos.ativo`) não altera essa classificação. Inativar um cadastro não cancela automaticamente o contrato. A tela deve evitar chamar VG-02 de “alunos ativos”: ele conta contratos vigentes, não pessoas com status cadastral ativo.

### 3.1 Matrículas terminando nos próximos 7 dias

VG-06 considera somente matrículas que ainda estão ATIVAS no instante da consulta. O limite é calculado como sete dias de calendário no fuso local. A matrícula deve satisfazer `agora < fim_exclusivo_em <= limite`, após conversão coerente dos instantes para UTC. O instante correspondente a agora + 7 dias de calendário local pertence à janela. Matrículas canceladas, agendadas ou já vencidas não entram nesse alerta.

O fim é exclusivo: no instante exato de `fim_exclusivo_em`, a matrícula já está vencida e não deve aparecer como “terminando”.

## 4. Catálogo dos relatórios

### 4.1 Operacional

| ID | Métrica | Definição | Observação |
|---|---|---|---|
| OP-01 | Total de alunos | Contagem de todos os registros em `alunos` | Fotografia cadastral atual; não é histórico nem depende do período |
| OP-02 | Alunos ativos | Contagem com `ativo = 1` | Fotografia cadastral atual; não depende do período |
| OP-03 | Alunos inativos | Contagem com `ativo = 0` | Fotografia cadastral atual; não depende do período |
| OP-04 | Matrículas por situação | Contagem de matrículas AGENDADA, ATIVA, VENCIDA e CANCELADA, derivada em um único instante de referência | CANCELADA tem prioridade; não depende do status cadastral do aluno |
| OP-05 | Matrículas com início próximo | Contagem de matrículas AGENDADAS com `inicio_em` estritamente depois de agora e até o limite de 7 ou 30 dias de calendário, inclusive | As janelas são cumulativas: 30 dias inclui os primeiros 7; não depende do período selecionado |
| OP-06 | Matrículas com término próximo | Contagem de matrículas ATIVAS com `fim_exclusivo_em` estritamente depois de agora e até o limite de 7 ou 30 dias de calendário, inclusive | As janelas são cumulativas; a janela de 7 dias deve ser idêntica a VG-06 |

**Decisão aprovada em 23/11/2026 — OP-01, OP-02 e OP-03.** Os três indicadores usam uma única fotografia cadastral no momento da consulta, ignoram o filtro de datas e exigem `students.view`. OP-01 conta todos os registros de `alunos`; OP-02 conta `ativo = 1`; OP-03 conta `ativo = 0`. A consulta deve retornar os três valores em um único agregado sobre `alunos`. A migration 015 adiciona `CHECK (ativo IN (0, 1))`, garantindo **OP-01 = OP-02 + OP-03**. Status cadastral não substitui nem altera a situação contratual das matrículas.

**Decisão aprovada em 23/11/2026 — OP-04, OP-05 e OP-06.** Os três indicadores usam `enrollments.view`, são fotografias calculadas no instante da consulta e ignoram o filtro de período. OP-04 conta cada matrícula uma única vez entre AGENDADA, ATIVA, VENCIDA e CANCELADA, conforme `EnrollmentStatus::derive()`. OP-05 conta matrículas AGENDADAS cujo início futuro esteja em até 7 ou 30 dias de calendário local. OP-06 conta somente matrículas ATIVAS cujo fim exclusivo esteja em até 7 ou 30 dias de calendário local. Os limites finais são inclusivos; a janela de 30 dias é cumulativa e inclui a de 7 dias. `asOf` deve ser capturado uma única vez; os limites locais de 7 e 30 dias são calculados a partir dele e então convertidos para UTC. A métrica OP-06/7 dias deve reutilizar o mesmo agregado/resultado de VG-06. Antes de retornar métricas, a consulta deve detectar qualquer registro com `fim_exclusivo_em <= inicio_em`; se houver, deve falhar de forma controlada e não publicar contagens potencialmente inconsistentes. A proteção é de leitura, sem nova migration ou correção automática de dados.

### 4.2 Matrículas

| ID | Métrica | Definição | Eixo temporal |
|---|---|---|---|
| MT-01 | Inícios de contrato | Contagem de matrículas cujo instante de `inicio_em` pertence ao período local convertido para limites UTC | Data de início contratual |
| MT-02 | Inícios por plano contratado | MT-01 agrupado pelo snapshot `plano_nome_contratado` | Data de início contratual e nome contratado |
| MT-03 | Valor contratado por plano | Soma de `valor_contratado` do snapshot, apenas quando preenchido | Data de início contratual; valor contratado não é recebimento |
| MT-04 | Inícios por origem | MT-01 agrupado por `origem` (SISTEMA/LEGADO) | Data de início contratual |
| MT-05 | Cancelamentos no período | Contagem de matrículas cujo `cancelada_em` pertence ao período local convertido para UTC | Instante do cancelamento |

Regras adicionais:
- `inicio_em` mede início do contrato; `criado_em` mede inserção do vínculo. Não chamar MT-01 de “matrículas cadastradas”.
- Se `plano_nome_contratado` estiver nulo, agrupar em “Plano não informado no snapshot”; não buscar o nome/preço atual do catálogo para reescrever a história.
- `valor_contratado` nulo não deve ser convertido em zero nem inventado. Apresentar quantidade de valores informados ou omitir soma quando não houver valores.
- MT-05 usa a data do evento `cancelada_em`, não o fim contratual, e não mede cancelamentos de cobrança.

### 4.3 Frequência

| ID | Métrica | Definição | Eixo temporal |
|---|---|---|---|
| FR-01 | Total de check-ins | Contagem de registros em `checkins` no intervalo de `data_local` | Dia civil local |
| FR-02 | Alunos distintos | Contagem distinta de `aluno_id` no período | Pessoas com pelo menos uma entrada no período |
| FR-03 | Evolução diária | Contagem de check-ins por `data_local`; completar dias sem registros com zero na camada de apresentação | Cada dia do período |
| FR-04 | Média de check-ins por dia da semana | Para cada dia ISO (segunda a domingo): total bruto de check-ins daquele dia dividido pela quantidade de ocorrências daquele dia no intervalo. Exibir também ocorrências e total bruto. | Dia da semana no calendário local |

A restrição única em `(aluno_id, data_local)` impede mais de um check-in por aluno no mesmo dia local. Ainda assim, FR-01 pode ser maior que FR-02 porque a mesma pessoa pode frequentar em vários dias.

**Regra aprovada para FR-04:** para cada dia da semana, calcular `média = total bruto de check-ins daquele dia / quantidade de ocorrências daquele dia no intervalo selecionado`. A quantidade de ocorrências é obtida contando as datas civis reais do período que caem naquele dia da semana; não é sempre igual para todos os dias.

A apresentação deve trazer **média por ocorrência**, **total bruto** e **quantidade de ocorrências**. Exemplo ilustrativo: 6 check-ins em 2 segundas-feiras resultam em média 3,0; 2 check-ins em 1 domingo resultam em média 2,0. A média é arredondada para uma casa decimal somente na apresentação; numerador e denominador permanecem disponíveis para conferir o cálculo.

Quando o período não contém nenhuma ocorrência de determinado dia (por exemplo, filtro de uma única terça-feira), mostrar total bruto 0 e ocorrências 0, mas média como não aplicável (—), nunca como 0. Isso distingue ausência de denominador de uma média real igual a zero. A contagem de ocorrências pode ser calculada em PHP a partir das datas inicial/final normalizadas, evitando depender de recursos de calendário SQL.

Esses indicadores medem entradas registradas na academia. Não representam presença em aula, participação em modalidade, ocupação de turma nem faltas.

### 4.4 Financeiro

A seção inteira requer `finance.view` tanto para HTML quanto para endpoints e exportações. O Operador não pode consultar totais ou metadados financeiros.

| ID | Métrica | Definição | Eixo temporal |
|---|---|---|---|
| FN-01 | Recebido no período | Soma de `pagamentos.valor` pela `data_pagamento` dentro do período local | Data declarada de pagamento |
| FN-02 | Quantidade de pagamentos | Contagem de lançamentos incluídos em FN-01 | Data declarada de pagamento |
| FN-03 | Recebido por forma de pagamento | FN-01 agrupado por `forma_pagamento` | Data declarada de pagamento e forma |
| FN-04 | Cobranças com vencimento no período | Quantidade e soma do valor original de cobranças não canceladas financeiramente cuja `data_vencimento` está no período. Separar em dois grupos: matrícula não cancelada e matrícula cancelada/em revisão. | Data de vencimento; classificação conforme estado atual da matrícula, não data de recebimento |
| FN-05 | Saldo cobrável atual | Soma, por cobrança não cancelada financeiramente e vinculada a matrícula não cancelada, de `max(valor_total - pagamentos_acumulados, 0)` | Fotografia atual |
| FN-06 | Saldo em revisão atual | Soma de `max(valor_total - pagamentos_acumulados, 0)` para cobranças não canceladas financeiramente vinculadas a matrículas canceladas | Fotografia atual; exige análise e não representa reembolso |

Regras adicionais:
- `data_pagamento` é uma data inserida no lançamento e pode ser retroativa mediante justificativa. FN-01 usa essa data; `registrado_em_utc` é o instante em que o lançamento entrou no sistema.
- FN-01 é a soma dos lançamentos de pagamento registrados manualmente no sistema cuja `data_pagamento` pertence ao período selecionado. O cartão usa o rótulo aprovado **“Recebido no período”**, acompanhado da legenda “Soma dos pagamentos registrados pela data informada no lançamento”. Como o serviço permite lançamentos retroativos com justificativa, a data do indicador é a data declarada do pagamento, não necessariamente o instante em que o sistema recebeu o lançamento. O sistema não comprova liquidação bancária; o indicador não representa receita contábil, lucro, conciliação bancária ou fluxo líquido após estornos.
- Os pagamentos atuais são append-only; não existe neste escopo um fluxo de estorno que permita calcular recebimento líquido. Não subtrair cobranças, cancelamentos de matrícula ou valores em revisão do total de pagamentos.
- **FN-04 — decisão aprovada em 23/11/2026:** consultar `cobrancas.data_vencimento` no intervalo inclusivo e excluir cobranças com `cancelada_em_utc` preenchido. Apresentar quantidade e soma de `valor_total` em dois grupos: (A) matrícula não cancelada (`matriculas.cancelada_em IS NULL`), incluindo matrículas agendadas, ativas ou vencidas; (B) matrícula cancelada (`matriculas.cancelada_em IS NOT NULL`), identificada como “Em revisão”. A separação usa o estado atual da matrícula, não reconstrói seu estado na data do vencimento. O valor é o total nominal original da cobrança, mesmo quando parcialmente paga ou quitada; não representa recebido, inadimplência ou saldo em aberto. A consulta não precisa juntar `pagamentos`, evitando multiplicar cobranças por lançamentos.
- **FN-05 — decisão aprovada em 23/11/2026:** apresentar o **“Saldo cobrável atual”**, calculado pela soma de `max(valor_total - pagamentos_acumulados, 0)` apenas para cobranças não canceladas financeiramente (`cobrancas.cancelada_em_utc IS NULL`) vinculadas a matrículas não canceladas (`matriculas.cancelada_em IS NULL`). É uma fotografia atual, independente do período selecionado. Reutilizar `FinanceDashboardStatus::collectibleBalanceCents()` como regra canônica ou comprovar paridade do agregado SQL com esse método. A legenda deve esclarecer que é o saldo restante de cobranças atualmente cobráveis, não apenas valores vencidos.
- **FN-06 — decisão aprovada em 23/11/2026:** apresentar o **“Saldo em revisão atual”**, calculado pela soma de `max(valor_total - pagamentos_acumulados, 0)` somente para cobranças não canceladas financeiramente (`cobrancas.cancelada_em_utc IS NULL`) vinculadas a matrículas canceladas (`matriculas.cancelada_em IS NOT NULL`). É uma fotografia atual, independente do período selecionado. Reutilizar `FinanceDashboardStatus::reviewBalanceCents()` como regra canônica ou comprovar paridade do agregado SQL com esse método. A legenda deve esclarecer: “Saldo restante de cobranças vinculadas a matrículas canceladas, ainda não canceladas financeiramente. Os valores exigem análise e não representam automaticamente valores a reembolsar.” Saldo zero não elimina eventual decisão sobre devolução de pagamentos já recebidos; o FN-06 não calcula reembolso.
- Usar DECIMAL/string/centavos inteiros e o formatador financeiro existente. Não converter moeda para float.
- Se uma cobrança vinculada a matrícula cancelada ainda não foi financeiramente cancelada, seu saldo vai para FN-06, não FN-05.

## 5. Permissões e composição por perfil

A página continua protegida por `dashboard.view`, mas cada seção deve exigir a permissão de domínio antes de consultar seus dados. Não criar uma permissão genérica `reports.view`.

| Seção/indicadores | Permissão | Administrador | Gestor | Operador |
|---|---|:---:|:---:|:---:|
| Operacional/alunos (OP e VG-01) | `students.view` | Sim | Sim | Sim |
| Matrículas (MT, OP-04 a OP-06 e VG-02/VG-06) | `enrollments.view` | Sim | Sim | Sim |
| Frequência (FR e VG-03/VG-04) | `attendance.view` | Sim | Sim | Sim |
| Financeiro (FN e VG-05) | `finance.view` | Sim | Sim | Não |

As permissões refletem a matriz existente em `src/Security/AccessControl.php`. A autorização precisa ser validada no servidor em cada requisição. Ocultar um cartão no navegador sem impedir a consulta não é suficiente.

Para o perfil Operador, a resposta da Visão geral não deve conter valores, totais, quantidade de cobranças, formas de pagamento ou saldos financeiros em nenhum formato. O HTML, payloads JSON futuros, gráficos, logs acessíveis na interface e CSV devem respeitar o mesmo limite.

## 6. Contrato de filtros

| Parâmetro | Regra |
|---|---|
| Data inicial | Data civil válida no formato ISO `YYYY-MM-DD` |
| Data final | Data civil válida no formato ISO `YYYY-MM-DD` |
| Padrão | Primeiro e último dia do mês civil atual |
| Inclusividade | Ambas as datas são inclusivas para o usuário |
| Limite | Até 365 dias civis inclusivos |
| Início maior que fim | Rejeitar com erro de validação |
| Data futura | Pode ser aceita como filtro de período para relatórios de contratos/vencimentos, mas pagamentos futuros não podem existir pela regra atual do serviço; não inferir recebimento futuro |
| Estado atual | Independente do filtro de período |
| Alertas de próximos 7/30 dias | Janela móvel independente do filtro de período |

A interface deve preservar os filtros validados ao navegar entre relatórios e ao exportar CSV. O servidor deve validar novamente todos os parâmetros; parâmetros recebidos não podem controlar nomes de coluna, expressões SQL ou permissões.

## 7. Exportação CSV — contrato inicial

- Um CSV por relatório, limitado ao mesmo período e aos agregados exibidos.
- Sem identificadores de aluno, nome, e-mail, telefone, observação, identificadores de funcionário ou qualquer dado pessoal.
- O relatório financeiro exige `finance.view` também no endpoint de exportação.
- Cabeçalhos estáveis, documentados e em UTF-8.
- Campos textuais devem neutralizar prefixos e conteúdo que planilhas interpretem como fórmula, incluindo `=`, `+`, `-`, `@`, tabulação e retorno de carro, conforme a implementação definida e testada.
- O CSV não deve incluir consultas SQL, erros internos, stack traces ou dados de auditoria.
- PDF, XLSX e exportação de registros individuais ficam fora do MVP.

## 8. Requisitos técnicos e testes derivados

1. Agregadores compartilhados: o dashboard e os relatórios detalhados devem usar as mesmas definições e serviços.
2. Relógio único: obter `agora` uma vez por requisição para não classificar matrículas em instantes diferentes na mesma resposta.
3. SQL: prepared statements; allowlist para agrupamentos/tipos de relatório; limites de período; sem N+1.
4. Datas: testes de limites inclusivos/exclusivos, virada do mês/ano, períodos de 365 dias e conversão local/UTC.
5. Matrículas: testar as quatro situações incluindo CANCELADA antes do início; início e fim exatamente em `asOf`; janelas de 7/30 dias e limites finais inclusivos; janela de 30 cumulativa; OP-06/7 dias idêntico a VG-06; intervalo inválido (`fim_exclusivo_em <= inicio_em`) bloqueia a resposta inteira sem retornar métricas.
6. Frequência: teste de dias sem registros, aluno com check-ins em dias distintos, duplicidade diária e agrupamento segunda–domingo.
7. Financeiro: pagamentos em datas retroativas justificadas, pagamentos parciais, cobrança quitada, cobrança cancelada, matrícula cancelada com saldo em revisão e precisão decimal.
8. RBAC: provar que Operador recebe HTTP 403 nas rotas financeiras e que nenhuma consulta financeira é executada para sua Visão geral.
9. CSV: testar fórmulas maliciosas em campos textuais, ausência de PII, filtros equivalentes à tela e bloqueio financeiro por perfil.
10. Regressão: relatórios são somente leitura e não criam/alteram matrículas, check-ins, cobranças, pagamentos ou eventos de auditoria.
11. Compatibilidade: MySQL 8.0 e MariaDB 10.11; ambiente local XAMPP só após backup, inspeção de versão/engine e execução manual documentada.

## 9. Decisões mantidas fora do MVP

- Histórico de alunos ativos/inativos por dia ou mês: não existe snapshot temporal.
- Saldo financeiro histórico em uma data passada: não existe ledger completo de snapshots de saldo.
- Faturamento previsto, lucro, fluxo de caixa líquido, conciliação bancária ou estornos: não estão modelados.
- Retenção, churn, LTV, conversão, metas e previsões.
- Ocupação, capacidade, reservas, presença por aula ou modalidade.
- Comparações percentuais com períodos anteriores sem definição e base equivalentes.
- Exportação nominal de alunos, PDF e XLSX.
- BI/warehouse, multiunidade e dashboard configurável.

## 10. Sequência de implementação após revisão

1. 07.2 — contratos de consulta, relógio, validações de período e testes de domínio/SQL.
2. 07.3 — relatórios operacional e de matrículas.
3. 07.4 — relatório de frequência.
4. 07.5 — relatório financeiro e testes de RBAC.
5. 07.6 — integração dos cartões e gráfico no dashboard existente, reutilizando os agregadores.
6. 07.7 — CSV agregado seguro.
7. 07.8 — acessibilidade, responsividade, temas e regressão completa.

**Decisões registradas em 23/11/2026:** OP-01 a OP-06 foram aprovados; “Recebido no período”, FR-04, FN-04, FN-05 e FN-06 também. OP-04 a OP-06 usam `enrollments.view`, as regras temporais de `EnrollmentStatus::derive()`, uma referência única e janelas cumulativas de 7/30 dias. OP-06/7 dias deve permanecer em paridade com VG-06. Intervalos inválidos devem interromper a consulta sem expor métricas parciais. FN-06 usa o saldo restante de cobranças não canceladas financeiramente ligadas a matrículas canceladas; não representa reembolso. Os demais indicadores, filtros e permissões continuam em revisão. A aprovação individual não equivale à aprovação global nem autoriza iniciar a implementação do Bloco 07.
