# Bloco 07.1 — Catálogo e contrato dos indicadores

> **Estado:** especificação técnica inicial concluída para revisão; nenhuma consulta, tela ou exportação do Bloco 07 foi implementada.
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
| VG-05 | Pagamentos registrados no período | Soma de `pagamentos.valor` para lançamentos cuja `data_pagamento` esteja no período, inclusive. É a soma dos pagamentos registrados para essas datas, não lucro nem fluxo líquido com estornos. | `pagamentos.valor`, `pagamentos.data_pagamento` | `finance.view` | Relatório financeiro |
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

VG-06 considera somente matrículas que ainda estão ATIVAS no instante da consulta. O limite é calculado como sete dias de calendário no fuso local. A matrícula deve satisfazer `agora < fim_exclusivo_em < limite`, após conversão coerente dos instantes para UTC. Matrículas canceladas, agendadas ou já vencidas não entram nesse alerta.

O fim é exclusivo: no instante exato de `fim_exclusivo_em`, a matrícula já está vencida e não deve aparecer como “terminando”.

## 4. Catálogo dos relatórios

### 4.1 Operacional

| ID | Métrica | Definição | Observação |
|---|---|---|---|
| OP-01 | Total de alunos | Contagem de todos os registros em `alunos` | Fotografia atual; não é histórico |
| OP-02 | Alunos ativos | Contagem com `ativo = 1` | Fotografia atual |
| OP-03 | Alunos inativos | Contagem com `ativo = 0` | Fotografia atual |
| OP-04 | Matrículas por situação | Contagem por cada situação derivada no instante único da consulta | Não somar com status cadastral de aluno |
| OP-05 | Matrículas com início próximo | Matrículas AGENDADAS com início depois de agora e até agora + 7 ou 30 dias de calendário, incluindo o limite final | Janela móvel, não depende do período selecionado |
| OP-06 | Matrículas com término próximo | Matrículas ATIVAS com fim exclusivo depois de agora e antes de agora + 7 ou 30 dias de calendário | Janela móvel; 7 dias alimenta VG-06 |

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
| FR-04 | Distribuição por dia da semana | Contagem agrupada de segunda a domingo usando o calendário local | Dia da semana |

A restrição única em `(aluno_id, data_local)` impede mais de um check-in por aluno no mesmo dia local. Ainda assim, FR-01 pode ser maior que FR-02 porque a mesma pessoa pode frequentar em vários dias.

Esses indicadores medem entradas registradas na academia. Não representam presença em aula, participação em modalidade, ocupação de turma nem faltas.

### 4.4 Financeiro

A seção inteira requer `finance.view` tanto para HTML quanto para endpoints e exportações. O Operador não pode consultar totais ou metadados financeiros.

| ID | Métrica | Definição | Eixo temporal |
|---|---|---|---|
| FN-01 | Total de pagamentos registrados no período | Soma de `pagamentos.valor` pela `data_pagamento` dentro do período local | Data declarada de pagamento |
| FN-02 | Quantidade de pagamentos | Contagem de lançamentos incluídos em FN-01 | Data declarada de pagamento |
| FN-03 | Pagamentos registrados por forma | FN-01 agrupado por `forma_pagamento` | Data declarada de pagamento e forma |
| FN-04 | Cobranças com vencimento no período | Quantidade e soma do valor original de cobranças não canceladas financeiramente cuja `data_vencimento` está no período; apresentar separadamente de FN-01 | Data de vencimento, não data de recebimento |
| FN-05 | Saldo cobrável atual | Soma, por cobrança não cancelada financeiramente e vinculada a matrícula não cancelada, de `max(valor_total - pagamentos_acumulados, 0)` | Fotografia atual |
| FN-06 | Saldo em revisão atual | Mesma diferença positiva para cobrança não cancelada financeiramente cuja matrícula está cancelada | Fotografia atual; valor exige decisão/revisão |

Regras adicionais:
- `data_pagamento` é uma data inserida no lançamento e pode ser retroativa mediante justificativa. FN-01 usa essa data; `registrado_em_utc` é o instante em que o lançamento entrou no sistema.
- FN-01 é a soma dos lançamentos de pagamento registrados manualmente no sistema para as datas selecionadas. O sistema não comprova liquidação bancária; portanto, o indicador deve ser rotulado como pagamentos registrados, não como receita contábil, lucro, conciliação bancária ou fluxo líquido após estornos.
- Os pagamentos atuais são append-only; não existe neste escopo um fluxo de estorno que permita calcular recebimento líquido. Não subtrair cobranças, cancelamentos de matrícula ou valores em revisão do total de pagamentos.
- FN-04 informa cobranças com vencimento, não pagamentos registrados nem saldo em aberto. Cobrança com pagamento integral continua tendo valor original; portanto não chamar a soma de FN-04 de “inadimplência”.
- FN-05 e FN-06 são saldos atuais e não devem reagir ao filtro histórico de datas. Cancelamento financeiro da cobrança zera sua contribuição; matrícula cancelada move o saldo não resolvido para revisão.
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
5. Matrículas: testes para cancelamento anterior ao início, início exatamente agora, fim exatamente agora, agendada, vigente e vencida.
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

**Critério de saída da 07.1:** revisão e aprovação do catálogo de indicadores, do contrato de filtros, das permissões e dos limites semânticos. Até essa aprovação, não iniciar migrations, consultas novas nem telas do Bloco 07.
