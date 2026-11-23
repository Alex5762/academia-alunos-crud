# Revisão técnica do Bloco 07 — aderência, métricas e arquitetura

> **Estado:** revisão técnica em andamento; decisões aprovadas até 23/11/2026 incluem Visão geral, OP-01 a OP-06, MT-01 a MT-05, “Recebido no período”, FR-01 a FR-05, FN-04, FN-05 e FN-06. As demais recomendações continuam sujeitas à revisão e aprovação do responsável pelo projeto.
> **Data:** 23/11/2026.
> **Escopo analisado:** catálogo 07.1, contratos 07.2, código atual de alunos, matrículas, frequência, financeiro, RBAC e dashboard.
> **Importante:** esta revisão não implementa funcionalidades, não altera schema e não substitui a aprovação do escopo.

## 1. Conclusão executiva

O escopo é compatível com os recursos já implementados, desde que o Bloco 07 permaneça como camada de leitura e agregação. Não há justificativa atual para migration, novo serviço externo, API JSON, framework, BI, cache ou uma camada genérica de autorização.

A principal recomendação é manter a arquitetura simples e coerente com os padrões existentes: classes de consulta com PDO, classes puras para regras temporais/monetárias, autorização por `usuarioTemPermissao()`/ `AccessControl`, arrays associativos para resultados e renderização server-side em PHP.

A CI #1555 aprovou sete jobs funcionais, incluindo a migration 015 em MySQL 8.0 e MariaDB 10.11. A CI #1556 aprovou os contratos documentais OP-04 a OP-06 no HEAD correspondente; não valida implementação futura de relatórios. Essa CI valida as alterações até o HEAD `58579e31a4fd3841131ceab118df1b7fe1a8d55a`, não a implementação futura dos relatórios. O job de publicação de screenshots foi ignorado pela condição do workflow. Essa evidência valida os cenários já existentes, não a implementação futura dos relatórios.

## 2. Revisão dos indicadores da Visão geral

| ID | Parecer | Justificativa / recomendação |
|---|---|---|
| VG-01 — Alunos ativos | **Manter** | `alunos.ativo = 1` representa corretamente uma fotografia cadastral atual. Não aplicar o filtro histórico nem chamar esse número de matrículas vigentes. |
| VG-02 — Matrículas vigentes | **Manter** | Reutilizar exatamente a regra de `EnrollmentStatus::derive()`. É uma contagem de contratos ATIVOS, independente de `alunos.ativo`. O nome “Matrículas vigentes” é adequado. |
| VG-03 — Check-ins no período | **Manter** | `COUNT(*)` por `checkins.data_local`, com ambas as datas inclusivas. A coluna já é data civil local; não converter para UTC. |
| VG-04 — Alunos com check-in | **Manter** | `COUNT(DISTINCT aluno_id)` no mesmo período. É diferente do total de entradas: o mesmo aluno pode aparecer em dias distintos. |
| VG-05 — Recebido no período | **Manter com legenda obrigatória** | Somar `pagamentos.valor` por `data_pagamento`. O serviço permite lançamento retroativo justificado, então essa é a data declarada do pagamento, não necessariamente o instante de registro nem uma liquidação bancária comprovada. Usar a legenda “Soma dos pagamentos registrados pela data informada no lançamento”. |
| VG-06 — Matrículas terminando em 7 dias | **Manter e definir janela** | Somente matrículas ATIVAS; condição `asOf < fim_exclusivo_em <= asOf + 7 dias de calendário local`. O limite de sete dias é inclusivo; no próprio fim exclusivo, a matrícula já está VENCIDA e não entra. |

**Decisão recomendada:** manter os seis cartões. São poucos, cobrem quatro domínios existentes e não dependem de criar novos dados.

## 3. Revisão dos relatórios operacionais

- OP-01 total de alunos, OP-02 ativos e OP-03 inativos: manter como fotografia atual. Não permitir que o filtro de período altere esses números.
- **OP-04 — Aprovado em 23/11/2026:** manter as quatro situações AGENDADA, ATIVA, VENCIDA e CANCELADA, com prioridade de CANCELADA exatamente como `EnrollmentStatus::derive()`.
- **OP-05 e OP-06 — Aprovados em 23/11/2026:** manter no relatório operacional detalhado, não como cartões extras do dashboard. Usar janelas cumulativas de 7 e 30 dias de calendário local, com limites finais inclusivos e um único `asOf`. OP-05 conta inícios futuros de matrículas AGENDADAS; OP-06 conta fins futuros de matrículas ATIVAS. O resultado OP-06/7 dias é a própria fonte de VG-06.
- A consulta agregada deve contar intervalos inválidos (`fim_exclusivo_em <= inicio_em`) e, se encontrar algum, falhar sem publicar quaisquer métricas operacionais de matrícula. Isso é validação de leitura; não pede migration, correção automática ou índice preventivo.
- Não tentar derivar histórico de alunos ativos ou de situação contratual para dias passados; as tabelas não guardam snapshots diários.

## 4. Revisão do relatório de matrículas

| ID | Parecer | Recomendação |
|---|---|---|
| MT-01 — Inícios de contrato | **Aprovado em 23/11/2026** | Contar somente início efetivo: `fim_exclusivo_em > inicio_em` e (`cancelada_em IS NULL OR cancelada_em > inicio_em`). Cancelamento antes ou exatamente no início não produziu vigência e não conta como início. Filtrar por `inicio_em`, não por `criado_em`, com limites civis locais convertidos para UTC. |
| MT-02 — Inícios por plano | **Aprovado em 23/11/2026** | Agrupar a mesma coorte de MT-01 pelo snapshot `plano_nome_contratado`; não usar o catálogo atual. Nulo/vazio = “Plano não informado no snapshot”. |
| MT-03 — Valor contratado por plano | **Aprovado em 23/11/2026; secundário** | Somar `valor_contratado` (DECIMAL) da mesma coorte de MT-01, agrupado pelo snapshot. Mostrar quantidade de valores conhecidos; NULL não é zero. É valor nominal contratado, nunca recebido. |
| MT-04 — Inícios por origem | **Aprovado em 23/11/2026; secundário** | Agrupar a mesma coorte de MT-01 pelo ENUM `origem` (`SISTEMA`/`LEGADO`). Útil para interpretar composição e migração; não ocupar espaço principal. |
| MT-05 — Cancelamentos | **Aprovado em 23/11/2026** | Contar o evento `cancelada_em` no período local convertido para UTC, inclusive se ocorreu antes ou exatamente no início previsto. Não usar fim contratual nem cancelamento de cobrança como substituto. |

### Decisão semântica complementar — início efetivo versus cancelamento

A distinção é coerente com o domínio implementado: `EnrollmentInterval::fromContract()` retorna `null` quando `cancelada_em <= inicio_em`; os testes `tests/enrollment-interval.php` cobrem cancelamento anterior e exatamente no início, enquanto `tests/database-enrollment-cancellation.php` confirma que uma matrícula AGENDADA pode ser cancelada e que o evento é persistido/auditado. A especificação do Bloco 03 define `inicio_em` como início efetivo da vigência. Por isso, MT-01 a MT-04 medem contratos que efetivamente começaram; MT-05 mede o evento de cancelamento, mesmo quando o contrato não iniciou. Essa é uma decisão semântica do FitControl, não uma regra legal geral para academias.

Referência conceitual externa: o National Student Clearinghouse distingue status “Withdrawn” de “Never Attended” em seu modelo de reporte. É uma analogia de separação entre início efetivo e não início, não uma norma aplicável ao FitControl ([definições de status de matrícula](https://help.studentclearinghouse.org/compliancecentral/knowledge-base/student-enrollment-statuses/)).

## 5. Revisão do relatório de frequência

- **FR-01 — Aprovado em 23/11/2026:** total de check-ins como `COUNT(*)` por `data_local` no período inclusivo.
- **FR-02 — Aprovado em 23/11/2026:** alunos distintos com `COUNT(DISTINCT aluno_id)` no mesmo período; não equivale ao total de alunos ativos.
- **FR-03 — Aprovado em 23/11/2026:** série diária por `data_local`, completando todas as datas civis sem registros com zero somente na apresentação. Zero não confirma que a academia estava aberta.
- **FR-04 — Aprovado anteriormente; ressalva confirmada em 23/11/2026:** média por ocorrência de cada weekday do calendário, com total bruto e denominador. O denominador conta datas civis do intervalo, não dias de funcionamento; a aplicação não possui calendário de feriados/fechamentos. Se não houver ocorrência daquele weekday, média não aplicável (—); se houver ocorrência sem check-ins, média real 0,0. Arredondar para uma casa decimal só na apresentação.
- **FR-05 — Aprovado em 23/11/2026; secundário:** `FR-01 / FR-02` no mesmo período, como média de dias com entrada por aluno frequentador. Um check-in diário por aluno sustenta essa interpretação. Não usar a base total de alunos ativos como denominador; se FR-02 = 0, média não aplicável (—).
- Reforçar em todas as telas que check-in significa entrada na academia, não presença em aula, modalidade ou turma.

## 6. Revisão do relatório financeiro

| ID | Parecer | Recomendação |
|---|---|---|
| FN-01 — Recebido no período | **Aprovado em 23/11/2026** | Somar `pagamentos.valor` com filtro inclusivo por `data_pagamento`. A fonte é `pagamentos`; não somar cobranças. Lançamentos retroativos seguem a data declarada; legenda não sugere liquidação bancária, faturamento, lucro ou recebimento líquido. |
| FN-02 — Quantidade de pagamentos | **Aprovado em 23/11/2026** | `COUNT(*)` do mesmo conjunto usado em FN-01; pagamentos parciais individuais contam separadamente. |
| FN-03 — Por forma de pagamento | **Aprovado em 23/11/2026** | Agrupar a soma de FN-01 por `forma_pagamento`, respeitando os cinco valores ENUM existentes. Grupos e contagens auxiliares devem reconciliar com FN-01/FN-02. |
| FN-04 — Cobranças com vencimento | **Aprovado em 23/11/2026** | Filtrar `data_vencimento` pelo intervalo inclusivo e excluir cobranças canceladas financeiramente. Exibir quantidade e soma do valor nominal original em dois grupos: matrícula não cancelada (inclui agendada, ativa ou vencida) e matrícula cancelada (“Em revisão”). A classificação usa o estado atual da matrícula, não reconstrói o estado na data de vencimento. Não chamar os valores de recebimento, inadimplência ou saldo em aberto; não juntar pagamentos à consulta nominal. |
| FN-05 — Saldo cobrável atual | **Aprovado em 23/11/2026** | Exibir a soma de `max(valor_total - pagamentos_acumulados, 0)` somente para cobranças não canceladas financeiramente e vinculadas a matrículas não canceladas. Fotografia atual, sem filtro histórico. Reutilizar `FinanceDashboardStatus::collectibleBalanceCents()` ou demonstrar paridade do agregado SQL com a regra testada. Não chamar de inadimplência, pois pode incluir valores ainda não vencidos. |
| FN-06 — Saldo em revisão atual | **Aprovado em 23/11/2026** | Somar `max(valor_total - pagamentos_acumulados, 0)` apenas para cobranças não canceladas financeiramente vinculadas a matrículas canceladas. Fotografia atual, independente do filtro de período. Reutilizar `FinanceDashboardStatus::reviewBalanceCents()` ou comprovar paridade SQL. Identificar como valor sujeito a análise, não como reembolso. |

### Regras essenciais do financeiro

1. Pagamentos são append-only; não existe fluxo de estorno implementado. Não tentar subtrair pagamentos nem chamar a soma de recebimento líquido.
2. O serviço aceita `data_pagamento` retroativa mediante observação, e impede datas futuras. A legenda deve tornar essa semântica visível.
3. FN-04 é valor nominal das cobranças que vencem no período, não o saldo ainda aberto. Cobranças quitadas continuam com seu valor original. O total e a quantidade são separados entre matrículas não canceladas e canceladas/em revisão, conforme o estado atual; cobranças canceladas financeiramente são excluídas.
4. Uma cobrança de matrícula cancelada pode permanecer não cancelada financeiramente para revisão, especialmente quando já houve pagamento. Separar esse grupo.
5. Para calcular FN-05/FN-06, pré-agregar pagamentos por cobrança antes do JOIN com cobranças/matrículas. Evitar multiplicação de linhas e soma duplicada. FN-05 inclui somente `c.cancelada_em_utc IS NULL` e `m.cancelada_em IS NULL`; FN-06 inclui somente `c.cancelada_em_utc IS NULL` e `m.cancelada_em IS NOT NULL`. Ambos aplicam `max(valor_total - pagamentos_acumulados, 0)`, são fotografias atuais independentes do filtro de período e devem ter paridade comprovada com `FinanceDashboardStatus::collectibleBalanceCents()` e `FinanceDashboardStatus::reviewBalanceCents()`, respectivamente. FN-06 não mede nem calcula reembolso, inclusive quando o saldo restante é zero.
6. Usar DECIMAL/string/centavos inteiros e o formatador existente; nunca converter moeda para float.
7. Nunca executar consultas financeiras para o perfil Operador.
8. FN-01 a FN-03 devem consultar diretamente `pagamentos`, sem JOIN a cobranças/matrículas que altere a população histórica; cancelamento posterior da matrícula não invalida um lançamento já registrado.
9. Preferir um agregado `GROUP BY forma_pagamento` com `COUNT(*)` e `SUM(valor)` para produzir FN-01, FN-02 e FN-03 de maneira reconciliável; manter precisão DECIMAL/centavos.
10. Antes da implementação, medir `EXPLAIN` em MySQL 8.0 e MariaDB 10.11. O índice atual `idx_pagamentos_cobranca_data_id (cobranca_id, data_pagamento, id)` começa por `cobranca_id`, e não garante acesso eficiente a um relatório filtrado somente por data. Não adicionar índice preventivo; qualquer novo índice depende de evidência medida e decisão separada.

## 7. Revisão dos filtros e datas

**Decisão aprovada em 23/11/2026:** período padrão do primeiro dia do mês civil atual até hoje, em `America/Sao_Paulo`; se `de` e `ate` forem ambos omitidos, aplicar o padrão; se só um faltar, rejeitar. Ambos os limites são inclusivos para o usuário, máximo de 365 dias civis inclusivos, validação estrita sem substituição silenciosa e preservação dos filtros na navegação/CSV. Datas futuras são permitidas quando selecionadas explicitamente, sem gerar projeções ou recebimentos futuros.

**Recomendação técnica: manter o contrato com os seguintes ajustes e testes.**

- Datas de usuário em `YYYY-MM-DD`, interpretadas como calendário `America/Sao_Paulo`.
- Período selecionado inclusivo para o usuário; limite de 365 dias civis inclusivos é aceitável para o MVP, com possibilidade de rever após medição real.
- Para DATETIME UTC, transformar início local 00:00 e dia seguinte ao fim local 00:00 em limites UTC e consultar `>= início AND < fim_exclusivo`.
- Para DATE local, comparar `>= data_inicial AND <= data_final`.
- Validar datas por parsing estrito e round-trip; `DateTimeImmutable::createFromFormat()` pode normalizar componentes fora da faixa, então não basta aceitar qualquer retorno não falso.
- Obter `asOf` uma vez por requisição no fuso da academia. Passar a referência às consultas; não usar relógio SQL ou chamadas independentes por cartão.
- A entrada HTTP `de`/`ate` só usa valores padrão quando ambos estão ausentes. Ausência parcial, formato inválido, data impossível, período invertido ou acima de 365 dias inclusivos deve gerar erro de validação, sem auto-correção silenciosa.
- Preservar período validado na navegação e nos links/requests de CSV; cada requisição valida novamente.
- Janela de 7/30 dias é calendário local, não 7 × 24 horas de duração fixa. O PHP `DateTimeImmutable` e `DateInterval` já são adequados para aritmética temporal sem mutar o objeto original.
- Definir comportamento para o limite máximo do DATETIME do MySQL: se calcular o dia seguinte a 9999-12-31 ultrapassar o domínio suportado, rejeitar o período com erro de validação em vez de produzir limite inválido.
- Permitir período futuro para relatórios contratuais/vencimentos se desejado; pagamentos futuros retornam zero conforme o serviço atual, sem projeções.

## 8. Revisão de arquitetura e aderência ao código existente

O documento 07.2 propõe classes que seriam mais abstração do que o projeto precisa. Recomenda-se:

| Proposta 07.2 atual | Decisão de arquitetura |
|---|---|
| `ReportPeriod` | **Manter.** Objeto de valor imutável e pequeno para validação e limites de data; segue o estilo das classes puras de Enrollment. |
| `AcademyClock` | **Não criar por enquanto.** O projeto já injeta relógios como closures em serviços e cria `DateTimeImmutable` no controller. Capturar uma vez em `index.php`/controller e passar adiante é suficiente. |
| `ReportAccessPolicy` | **Não criar.** Usar `usuarioTemPermissao()` e `AccessControl` existentes. Autorizar antes de chamar cada query. |
| `ReportQuery` genérico | **Não criar como camada universal.** Seguir `ClassScheduleQuery` e `FinancePaymentHistoryQuery`: consultas específicas por domínio, construtor PDO e métodos com responsabilidade clara. |
| `ReportDashboardService` | **Adiar.** Só criar se, durante a implementação, houver orquestração repetida que não caiba de forma simples na página. |
| `ReportResult` DTO | **Não criar inicialmente.** Arrays associativos com chaves estáveis são compatíveis com o código PHP existente. Adotar DTO apenas se a complexidade real justificar. |
| Payload JSON | **Não criar por padrão.** O FitControl renderiza páginas server-side. Não há necessidade de introduzir API assíncrona para relatórios agregados nesta primeira versão. |

### Arquitetura mínima recomendada

1. `ReportPeriod`: parsing estrito, intervalo inclusivo, limites DATE e UTC.
2. Consultas de leitura por domínio, por exemplo `OperationalReportQuery`, `EnrollmentReportQuery`, `AttendanceReportQuery` e `FinanceReportQuery`, somente quando houver necessidade concreta de cada módulo.
3. As páginas/controller obtêm o usuário, verificam `usuarioTemPermissao()` antes de consultar e passam período/instante normalizados.
4. Os métodos de consulta retornam arrays agregados; as páginas apenas formatam/exibem.
5. Dashboard e relatórios detalhados reutilizam os mesmos métodos, evitando SQL duplicado.

Não alterar `index.php` inteiro de uma vez. Extrair somente as consultas novas ou as partes necessárias, preservando a listagem e os indicadores existentes até a regressão comprovar equivalência.

## 9. Segurança e exportação CSV

**Decisão aprovada em 23/11/2026:** reutilizar RBAC existente sem permissão genérica de relatórios. A página exige `dashboard.view`; cada seção é autorizada individualmente antes de instanciar/executar sua consulta (`students.view`, `enrollments.view`, `attendance.view` ou `finance.view`). Administrador e Gestor têm acesso financeiro; Operador não. No dashboard do Operador, omitir toda a seção financeira e não executar a consulta; nenhum valor ou metadado financeiro pode chegar ao cliente. Aplicar a mesma verificação a todos os endpoints e CSV.

- PDO preparado para todos os valores; placeholders não substituem nomes de tabelas, colunas, operadores ou agrupamentos. Partes dinâmicas de SQL precisam de allowlist fixa.
**Escopo CSV aprovado em 23/11/2026:** um CSV por relatório, somente agregados exibidos, mesmo período da tela, sem PII e com revalidação de RBAC no servidor; PDF, XLSX e exportação nominal ficam fora do MVP.
- Aspas CSV, por si só, não neutralizam fórmulas. A orientação OWASP registra riscos com `=`, `+`, `-`, `@`, tab, CR/LF e variantes Unicode de largura completa, e alerta que algumas mitigações podem perder efeito após salvar/reabrir no Excel.
- Para a variante destinada à leitura humana em planilhas, aplicar escaping CSV correto e prefixar com tab campos textuais não confiáveis cujo primeiro caractere efetivo seja iniciador de fórmula reconhecido pela rotina. Nomes de planos contratados são snapshots potencialmente originados de entrada antiga e devem ser tratados como texto não confiável; cabeçalhos e ENUMs fixos são allowlists controladas.
- A tabulação adicionada passa a fazer parte do conteúdo. Documentar esse trade-off; para futura integração máquina-a-máquina, definir outro formato/contrato em vez de reutilizar o CSV de leitura humana.
- Testar casos maliciosos, UTF-8, aspas e separadores; validar abertura no Excel/LibreOffice quando houver ambiente. Não alegar proteção universal para todos os consumidores.

## 10. Sequência recomendada após aprovação

1. Fechar catálogo 07.1 com os ajustes desta revisão.
2. Enxugar o contrato 07.2 para a arquitetura mínima descrita acima.
3. Implementar e testar `ReportPeriod` sem banco/schema.
4. Implementar consultas operacional/matrículas e testar limites com dados fictícios na CI.
5. Implementar frequência, incluindo média por ocorrência do dia da semana.
6. Implementar financeiro, separação do grupo em revisão e RBAC.
7. Reutilizar os mesmos agregadores para cartões do dashboard.
8. Implementar CSV spreadsheet-safe somente depois de testar a saída.
9. Regressão desktop/mobile, tema claro/escuro, acessibilidade e comparação com o dashboard anterior.

## 11. Referências de boas práticas

- [PHP — PDO prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php): parâmetros para valores e proteção contra injeção; identificadores dinâmicos não são substituídos por placeholders.
- [PHP — PDO::prepare](https://www.php.net/manual/pt_BR/pdo.prepare.php): marcadores representam literais completos, não palavras-chave/identificadores SQL.
- [PHP — DateTimeImmutable::createFromFormat](https://www.php.net/manual/pt_BR/datetimeimmutable.createfromformat.php): parsing estrito exige verificação de erros e do valor normalizado.
- [OWASP — CSV Injection](https://owasp.org/www-community/attacks/CSV_Injection): fórmulas maliciosas, neutralização por célula e trade-offs em planilhas.

**Resultado desta revisão:** o desenho geral combina com o FitControl. O contrato de período, a matriz de autorização e o escopo de CSV agregado foram aprovados em 23/11/2026. A implementação deve respeitar o padrão mês-até-hoje, validação estrita, persistência dos filtros, autorização antes da consulta e neutralização documentada de fórmulas para planilhas. Permanecem pendentes os critérios finais de apresentação e o fechamento global do catálogo/contratos; nenhuma consulta, tela ou exportação foi implementada.
