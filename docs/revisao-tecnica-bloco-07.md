# Revisão técnica do Bloco 07 — aderência, métricas e arquitetura

> **Estado:** revisão técnica e reconciliação global concluídas em 23/11/2026; implementação inicial da 07.2 em andamento com `ReportPeriod` e testes puros. Integração HTTP, consultas e interface permanecem pendentes.
> **Data:** 23/11/2026.
> **Escopo analisado:** catálogo 07.1, contratos 07.2, código atual de alunos, matrículas, frequência, financeiro, RBAC e dashboard.
> **Importante:** esta revisão não implementa funcionalidades, não altera schema e não substitui a aprovação do escopo.

## 1. Conclusão executiva

O escopo é compatível com os recursos já implementados, desde que o Bloco 07 permaneça como camada de leitura e agregação. Não há justificativa atual para migration, novo serviço externo, API JSON, framework, BI, cache ou uma camada genérica de autorização.

A principal recomendação é manter a arquitetura simples e coerente com os padrões existentes: classes de consulta com PDO, classes puras para regras temporais/monetárias, autorização por `usuarioTemPermissao()`/ `AccessControl`, arrays associativos para resultados e renderização server-side em PHP.

As CIs #1555 e #1556 validaram os respectivos estados da branch e os contratos já existentes à época; elas não validam implementação futura de relatórios. A CI #1563 (commit `78b872a0`) também passou após as atualizações documentais desta revisão. Como o Bloco 07 ainda não possui consultas ou telas implementadas, essas execuções comprovam a integridade dos testes já presentes no repositório, não a funcionalidade futura dos relatórios. O job de publicação de screenshots segue sujeito à condição do workflow.

## 2. Revisão dos indicadores da Visão geral

| ID | Parecer | Justificativa / recomendação |
|---|---|---|
| VG-01 — Alunos ativos | **Manter** | `alunos.ativo = 1` representa corretamente uma fotografia cadastral atual. Não aplicar o filtro histórico nem chamar esse número de matrículas vigentes. |
| VG-02 — Matrículas vigentes | **Manter** | Reutilizar exatamente a regra de `EnrollmentStatus::derive()`. É uma contagem de contratos ATIVOS, independente de `alunos.ativo`. O nome “Matrículas vigentes” é adequado. |
| VG-03 — Check-ins no período | **Manter** | `COUNT(*)` por `checkins.data_local`, com ambas as datas inclusivas. A coluna já é data civil local; não converter para UTC. |
| VG-04 — Alunos com check-in | **Manter** | `COUNT(DISTINCT aluno_id)` no mesmo período. É diferente do total de entradas: o mesmo aluno pode aparecer em dias distintos. |
| VG-05 — Recebido no período | **Manter com legenda obrigatória** | Somar `pagamentos.valor` por `data_pagamento`. O serviço permite lançamento retroativo justificado, então essa é a data declarada do pagamento, não necessariamente o instante de registro nem uma liquidação bancária comprovada. Usar a legenda “Soma dos pagamentos registrados pela data informada no lançamento”. |
| VG-06 — Matrículas terminando em 7 dias | **Manter e definir janela** | Somente matrículas ATIVAS; condição `asOf < fim_exclusivo_em <= asOf + 7 dias de calendário local`. O limite de sete dias é inclusivo; no próprio fim exclusivo, a matrícula já está VENCIDA e não entra. |

**Decisão aprovada em 23/11/2026:** manter seis cartões, em dois grupos: situação atual/atenção (VG-01, VG-02 e VG-06) e movimentação selecionada (VG-03, VG-04 e VG-05). O Operador não recebe nem reserva espaço para o cartão financeiro.

### Decisões de apresentação e aderência ao dashboard atual

A leitura de `index.php` confirma que a página já contém a barra lateral, cabeçalho, linha de três cards, painel de distribuição por plano, atalho de cadastro, pesquisa e tabela de alunos. A decisão é substituir somente a linha de três cards pelos seis indicadores aprovados, preservando a pesquisa, os filtros, a tabela de alunos e o atalho de cadastro. Não reconstruir a página nem alterar sua navegação geral.

O painel de distribuição atual consulta `alunos.plano` somente para `ativo = 1`, mas calcula o percentual dividindo por `$totalAlunos`, que inclui inativos. Isso torna o percentual inconsistente com a população agrupada. Corrigir o denominador para `$alunosAtivos`; o rótulo deve identificar a distribuição do plano cadastral entre alunos ativos, não snapshots contratuais. O schema define `plano VARCHAR(40) NOT NULL`, mas permite string vazia; portanto, valores vazios ou somente espaços devem ser apresentados em um grupo “Não informado”, sem excluí-los dos ativos contados. O estado vazio ocorre quando não há alunos ativos, e a divisão por zero deve ser evitada.

A estrutura CSS existente suporta cards em três colunas no desktop, duas no breakpoint de 760 px e uma coluna até 420 px; os novos seis cards podem reutilizar esses padrões sem novo sistema de layout. A responsividade de relatórios deve preservar a leitura sem rolagem horizontal da página inteira; tabelas com estrutura bidimensional podem ter rolagem localizada e identificada. A distribuição por planos permanece secundária.

Para a hierarquia dos relatórios, usar tabelas para situação operacional e matrículas; gráfico de barras diário acompanhado por tabela/resumo textual para frequência; resumo numérico e tabela por forma no financeiro. Nenhum valor essencial depende de hover, tooltip ou cor isolada. Zero, estado vazio, não aplicável, erro de consulta e falta de permissão são estados distintos. Como as páginas são renderizadas no servidor, skeletons ficam fora enquanto não houver carregamento assíncrono.

### Acessibilidade — verificação da base visual

`style.css` já oferece `:focus-visible`, `prefers-reduced-motion`, `.visually-hidden`, rolagem localizada em tabela, dois temas e breakpoints mobile. O contorno global atual usa `rgba(49, 95, 232, 0.28)`. A composição aproximada sobre o canvas claro resulta em cerca de 1,50:1 e sobre o canvas escuro em cerca de 1,32:1, abaixo dos 3:1 esperados para a informação visual não textual que identifica o estado focado contra cores adjacentes (WCAG 2.2 SC 1.4.11). O teste definitivo deve medir o contorno contra o fundo adjacente real de links, botões, inputs e selects em ambos os temas; substituir a cor translúcida por indicador sólido/duplo se necessário.

Requisitos adotados: foco de teclado visível (SC 2.4.7) e contraste não textual suficiente para o estado focado (SC 1.4.11); texto normal com 4,5:1 (SC 1.4.3); alvos de pelo menos 24 × 24 CSS px ou exceção aplicável (SC 2.5.8); reflow a 320 CSS px sem perda de informação/funcionalidade, exceto conteúdo essencialmente bidimensional como tabelas de dados (SC 1.4.10). Gráficos terão tabela/resumo equivalente e não dependerão somente de cor. Preservar suporte a tema escuro e redução de movimento já existente.

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

## 10. Sequência técnica aprovada e estado de execução

1. **Concluído no primeiro incremento:** `ReportPeriod` e testes puros de parsing, limites inclusivos, período padrão, futuro, conversão UTC e transição histórica de horário de verão, sem migration/schema.
2. Integrar `ReportPeriod` aos controllers, capturar um único `asOf` por requisição, preservar `de`/`ate` entre navegação e CSV e tratar erros de validação sem fallback silencioso.
3. Implementar consultas operacional e de matrículas (07.3), incluindo paridade com `EnrollmentStatus::derive()` e testes com dados fictícios em MySQL 8.0/MariaDB 10.11.
4. Implementar frequência (07.4), incluindo FR-03, FR-04 por ocorrência do calendário e FR-05.
5. Implementar financeiro (07.5), RBAC e paridade de FN-05/FN-06 com `FinanceDashboardStatus`; executar `EXPLAIN` em MySQL 8.0 e MariaDB 10.11 antes de decidir sobre índices.
6. Integrar os cartões no dashboard existente (07.6), reutilizando agregadores sem duplicar SQL e corrigindo a distribuição cadastral por plano.
7. Implementar CSV agregado seguro (07.7) por último, com escaping, neutralização documentada e testes em leitores de planilha.
8. Finalizar regressão (07.8): desktop/mobile, reflow a 320 CSS px, temas claro/escuro, teclado/foco, contraste, redução de movimento, acessibilidade e comparação com a experiência anterior.

## 11. Referências de boas práticas

- [PHP — PDO prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php): parâmetros para valores e proteção contra injeção; identificadores dinâmicos não são substituídos por placeholders.
- [PHP — PDO::prepare](https://www.php.net/manual/pt_BR/pdo.prepare.php): marcadores representam literais completos, não palavras-chave/identificadores SQL.
- [PHP — DateTimeImmutable::createFromFormat](https://www.php.net/manual/pt_BR/datetimeimmutable.createfromformat.php): parsing estrito exige verificação de erros e do valor normalizado.
- [OWASP — CSV Injection](https://owasp.org/www-community/attacks/CSV_Injection): fórmulas maliciosas, neutralização por célula e trade-offs em planilhas.
- [W3C — WCAG 2.2, SC 1.4.3 Contrast (Minimum)](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html): texto normal em 4,5:1 e texto grande em 3:1.
- [W3C — WCAG 2.2, SC 1.4.11 Non-text Contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast): componentes, gráficos e estados visuais relevantes com contraste 3:1 contra cores adjacentes.
- [W3C — WCAG 2.2, SC 2.4.7 Focus Visible](https://www.w3.org/WAI/WCAG22/Understanding/focus-visible): foco de teclado precisa permanecer visível; os indicadores também se relacionam com SC 1.4.11.
- [W3C — WCAG 2.2, SC 2.5.8 Target Size (Minimum)](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum): alvos de pelo menos 24 × 24 CSS px, com exceções definidas.
- [W3C — WCAG 2.2, SC 1.4.10 Reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow): apresentação sem perda de informação/funcionalidade a 320 CSS px, exceto conteúdos que exijam layout bidimensional.

**Resultado desta revisão:** o desenho geral combina com o FitControl. A especificação global está aprovada. O primeiro incremento da 07.2 (`ReportPeriod` e testes puros) foi implementado sem alteração de schema; a integração HTTP, consultas, telas e exportação ainda não foram implementadas.
