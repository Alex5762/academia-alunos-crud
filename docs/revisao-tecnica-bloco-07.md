# Revisão técnica do Bloco 07 — aderência, métricas e arquitetura

> **Estado:** revisão técnica em andamento; decisões aprovadas até 23/11/2026 incluem Visão geral, “Recebido no período”, FR-04, FN-04, FN-05 e FN-06. As demais recomendações continuam sujeitas à revisão e aprovação do responsável pelo projeto.
> **Data:** 23/11/2026.
> **Escopo analisado:** catálogo 07.1, contratos 07.2, código atual de alunos, matrículas, frequência, financeiro, RBAC e dashboard.
> **Importante:** esta revisão não implementa funcionalidades, não altera schema e não substitui a aprovação do escopo.

## 1. Conclusão executiva

O escopo é compatível com os recursos já implementados, desde que o Bloco 07 permaneça como camada de leitura e agregação. Não há justificativa atual para migration, novo serviço externo, API JSON, framework, BI, cache ou uma camada genérica de autorização.

A principal recomendação é manter a arquitetura simples e coerente com os padrões existentes: classes de consulta com PDO, classes puras para regras temporais/monetárias, autorização por `usuarioTemPermissao()`/ `AccessControl`, arrays associativos para resultados e renderização server-side em PHP.

A CI #1530 aprovou sete jobs funcionais no HEAD correspondente. O job de publicação de screenshots foi ignorado pela condição do workflow. Essa evidência valida os cenários já existentes, não a implementação futura dos relatórios.

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
- OP-04 matrículas por situação: manter as quatro situações AGENDADA, ATIVA, VENCIDA e CANCELADA. CANCELADA tem prioridade, conforme a regra existente.
- OP-05 início próximo e OP-06 término próximo: manter no relatório detalhado, não como cartões adicionais do dashboard. Usar janelas de 7 e 30 dias, calculadas a partir do mesmo `asOf`, com limite final inclusivo.
- Não tentar derivar histórico de alunos ativos ou de situação contratual para dias passados; as tabelas não guardam snapshots diários.

## 4. Revisão do relatório de matrículas

| ID | Parecer | Recomendação |
|---|---|---|
| MT-01 — Inícios de contrato | **Manter** | Filtrar por `inicio_em`, não por `criado_em`. O rótulo precisa ser “Inícios de contrato”. Como a coluna é DATETIME UTC, converter os limites civis locais para UTC. |
| MT-02 — Inícios por plano | **Manter** | Agrupar pelo snapshot `plano_nome_contratado`; não usar o nome atual do catálogo para reescrever contratos antigos. Tratar NULL e texto vazio como “Plano não informado no snapshot”. |
| MT-03 — Valor contratado por plano | **Manter como métrica secundária** | Somar `valor_contratado` (DECIMAL) dos contratos iniciados no período, agrupado pelo snapshot. Mostrar também quantos registros têm valor informado; NULL não é zero. Nomear como valor contratado/nominal, nunca como recebido. |
| MT-04 — Origem SISTEMA/LEGADO | **Rebaixar para informação técnica/secundária** | É útil para auditoria de migração e qualidade da base, mas tem pouco valor para a rotina gerencial. Não ocupar espaço principal da tela. |
| MT-05 — Cancelamentos | **Manter** | Contar pelo evento `cancelada_em` no período local convertido para UTC. Não usar fim contratual nem cancelamento de cobrança como substituto. |

## 5. Revisão do relatório de frequência

- FR-01 total de check-ins: manter usando `COUNT(*)` por `data_local`.
- FR-02 alunos distintos: manter com `COUNT(DISTINCT aluno_id)`.
- FR-03 evolução diária: manter; completar dias sem registros com zero na apresentação, não inventar registros no banco.
- FR-04 distribuição por dia da semana: **decisão aprovada em 23/11/2026**. Apresentar média de check-ins por ocorrência de cada dia (total bruto daquele weekday dividido pela quantidade de datas daquele weekday no intervalo), quantidade de ocorrências e total bruto. Arredondar a média para uma casa decimal somente na apresentação. Se não houver nenhuma ocorrência daquele dia no período, exibir média como não aplicável (—), total bruto 0 e ocorrências 0; não apresentar média zero.
- Reforçar em todas as telas que check-in significa entrada na academia, não presença em aula, modalidade ou turma.

## 6. Revisão do relatório financeiro

| ID | Parecer | Recomendação |
|---|---|---|
| FN-01 — Recebido no período | **Manter** | Mesmo cálculo e legenda de VG-05. A fonte da verdade é `pagamentos`; não somar cobranças. |
| FN-02 — Quantidade de pagamentos | **Manter** | Contar exatamente os lançamentos que compõem FN-01, pelo mesmo filtro `data_pagamento`. |
| FN-03 — Por forma de pagamento | **Manter** | Agrupar os mesmos pagamentos por `forma_pagamento`; usar valores ENUM existentes. |
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

## 7. Revisão dos filtros e datas

**Recomendação: manter o contrato atual com os seguintes ajustes e testes.**

- Datas de usuário em `YYYY-MM-DD`, interpretadas como calendário `America/Sao_Paulo`.
- Período selecionado inclusivo para o usuário; limite de 365 dias civis inclusivos é aceitável para o MVP, com possibilidade de rever após medição real.
- Para DATETIME UTC, transformar início local 00:00 e dia seguinte ao fim local 00:00 em limites UTC e consultar `>= início AND < fim_exclusivo`.
- Para DATE local, comparar `>= data_inicial AND <= data_final`.
- Validar datas por parsing estrito e round-trip; `DateTimeImmutable::createFromFormat()` pode normalizar componentes fora da faixa, então não basta aceitar qualquer retorno não falso.
- Obter `asOf` uma vez por requisição no fuso da academia. Passar a referência às consultas; não usar relógio SQL ou chamadas independentes por cartão.
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

- PDO preparado para todos os valores; placeholders não substituem nomes de tabelas, colunas, operadores ou agrupamentos. Partes dinâmicas de SQL precisam de allowlist fixa.
- CSV agregado, sem PII, continua possível, mas neutralização de fórmula precisa seguir um contrato específico para planilhas.
- Aspas CSV, por si só, não neutralizam fórmulas. A orientação OWASP registra riscos com `=`, `+`, `-`, `@`, tab, CR/LF e variantes Unicode de largura completa. Também alerta que algumas mitigações podem perder efeito após salvar e reabrir no Excel.
- Como o CSV é destinado a leitura humana em planilhas, adotar a variante “spreadsheet-safe” documentada: envolver campos corretamente em aspas e prefixar com tab os campos textuais não confiáveis cujo primeiro caractere efetivo seja iniciador de fórmula, incluindo variantes tratadas na rotina. Documentar que a tabulação passa a fazer parte do conteúdo e testar Excel/LibreOffice quando houver ambiente; não alegar proteção universal para todos os consumidores.
- Nomes de planos contratados são snapshots potencialmente originados de entrada antiga e devem ser tratados como texto não confiável. ENUMs e cabeçalhos fixos são allowlists controladas.
- Se o objetivo futuro passar a ser importação por outros sistemas, oferecer formato/contrato de integração separado; não misturar um CSV seguro para planilha com um arquivo de dados bruto.
- O CSV deve manter apenas agregados do relatório, mesmos filtros e mesmas permissões da tela.

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

**Resultado desta revisão:** o desenho geral combina com o FitControl. Aprovar os indicadores e implementar somente depois de incorporar as correções semânticas, remover abstrações redundantes e definir de forma explícita o comportamento do CSV.
