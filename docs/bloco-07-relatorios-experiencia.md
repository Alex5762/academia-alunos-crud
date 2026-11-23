# Bloco 07 — Relatórios e experiência

> **Estado:** direção da Visão geral aceita; decisões aprovadas por etapas: OP-01 a OP-06, MT-01 a MT-05, FR-01 a FR-05, FN-01 a FN-06, filtros comuns, matriz de permissões por seção e escopo do CSV agregado (23/11/2026). Os critérios finais de apresentação foram aprovados em 23/11/2026. O fechamento global dos contratos ainda é necessário antes da implementação; nenhuma funcionalidade do Bloco 07 foi implementada.
> **Branch de referência:** `feat/dashboard-portfolio`.
> **Dependência anterior:** Bloco 06 com validação automatizada aprovada na CI #1512. A validação manual dos blocos no XAMPP/dispositivo permanece adiada e registrada separadamente.
> **Regra:** revisar e aprovar o catálogo `bloco-07-catalogo-indicadores.md` antes de iniciar a etapa 07.2. A aprovação da direção da Visão geral não antecipa a aprovação automática de cada fórmula ou endpoint.

## Documentos do bloco

- [Revisão técnica de indicadores e arquitetura](revisao-tecnica-bloco-07.md) — parecer de aderência ao código atual e boas práticas; recomendações aguardam aprovação.
- [Catálogo e contrato dos indicadores — etapa 07.1](bloco-07-catalogo-indicadores.md)

## 1. Objetivo

Transformar os dados operacionais já registrados pelo FitControl em relatórios simples, consistentes e úteis para a rotina da academia, sem introduzir um BI complexo, duplicar os módulos existentes ou inferir fatos que o banco não registra.

O bloco deve melhorar a leitura dos dados e a navegação, mantendo PHP, PDO, MySQL/MariaDB e o modelo de autorização atual.

## 2. Fontes existentes e limites semânticos

| Fonte | O que permite medir | Limite que precisa ser respeitado |
|---|---|---|
| `alunos` | Quantidade atual de alunos ativos e inativos | Não existe snapshot histórico diário do status cadastral. Não apresentar contagem histórica de ativos como se houvesse histórico de status. |
| `matriculas` | Matrículas por início contratual, plano contratado e situação derivada na data de consulta | `inicio_em` é o início do contrato; `criado_em` é o registro do vínculo. Não confundir os dois conceitos. |
| `checkins` | Entradas por data civil local, aluno e matrícula | Check-in é entrada na academia, não presença em uma modalidade/aula. |
| `cobrancas` | Valor contratado, vencimento e cancelamento da cobrança | A cobrança não é receita recebida. Não somar cobranças com pagamentos como se fossem a mesma métrica. |
| `pagamentos` | Valores recebidos por data de pagamento e forma | `data_pagamento` é a data de competência informada no lançamento; `registrado_em_utc` é o instante de registro. Os relatórios devem identificar qual dos dois eixos usam. |
| `horarios_aula` | Grade semanal recorrente | O Bloco 06 não cria sessões datadas nem presença por aula; portanto não há taxa de ocupação, presença por modalidade ou faltas por aula. |

Não criar dados históricos artificiais nem reconstruir estados passados que as tabelas atuais não conseguem provar.


## 2.1 Visão geral gerencial — direção aceita para especificação

A Visão geral será uma **camada resumida dentro do dashboard existente**, não um segundo módulo de BI nem uma página paralela. Seu papel é ajudar a equipe a identificar rapidamente a situação da academia e abrir o relatório correspondente quando precisar investigar um número.

A tela deve distinguir explicitamente três grupos de informação:

**Fotografia atual (referência: momento da consulta)**
- alunos ativos, conforme o status cadastral atual;
- matrículas vigentes, conforme as regras de vigência e cancelamento existentes.

**Movimentação no período selecionado**
- check-ins realizados no período, quantidade de entradas e alunos distintos;
- total de pagamentos registrados no período, usando `pagamentos.data_pagamento`, somente para quem possui `finance.view`.

**Atenção próxima (referência: data atual)**
- matrículas cujo término contratual está previsto nos próximos 7 dias, respeitando `fim_exclusivo_em` e sem tratar cobranças como pagamentos.

O período padrão dos indicadores de movimentação será do primeiro dia do mês civil atual até hoje, no fuso `America/Sao_Paulo`. Alterar o período não deve alterar os cartões de fotografia atual nem deslocar a janela do alerta de próximos 7 dias. A interface deve exibir o período aplicado e identificar os cartões que representam situação atual.

A visualização da frequência pode apresentar uma evolução diária de check-ins, acompanhada por resumo textual ou tabela. Não deve sugerir ocupação de aulas, presença em modalidades ou frequência individual por aula.

O resumo financeiro deve ser omitido para perfis sem `finance.view`. Além de ocultar o cartão, a camada de servidor não deve enviar valores financeiros ao cliente nem permitir consulta indireta por endpoint. As demais seções seguem suas permissões de domínio.

A Visão geral deve reutilizar os mesmos agregadores/serviços e as mesmas definições dos relatórios detalhados. Não manter fórmulas SQL independentes para o cartão e para o relatório correspondente. Cada indicador terá um atalho para seu relatório de origem.

**Composição aprovada em 23/11/2026:** substituir a linha atual de três cards pelos seis indicadores VG-01 a VG-06, organizados em dois grupos. “Situação atual e atenção”: VG-01 Alunos ativos, VG-02 Matrículas vigentes e VG-06 Matrículas terminando em até 7 dias. “Movimentação no período”: VG-03 Check-ins no período, VG-04 Alunos com check-in no período e VG-05 Recebido no período, este último somente para quem possui `finance.view`. O cartão financeiro é omitido por completo para o Operador, sem espaço reservado ou placeholder. O seletor `de`/`ate` acompanha a movimentação no período e não altera VG-01, VG-02 ou VG-06.

Preservar no dashboard a pesquisa, os filtros e a tabela de alunos, além do atalho de cadastro. Manter a distribuição de planos como painel secundário, corrigindo a proporção: cada grupo de planos contém somente alunos com `ativo = 1`, portanto o denominador dos percentuais deve ser `$alunosAtivos`, não `$totalAlunos`. Rotular o painel como distribuição do plano cadastral dos alunos ativos, sem sugerir que o campo `alunos.plano` é o snapshot contratual da matrícula. Se não houver alunos ativos ou grupos para exibir, apresentar estado vazio contextual e nunca dividir por zero.

**Hierarquia dos relatórios aprovada:** operacional com indicadores resumidos e tabelas de situação; matrículas com tabelas por situação, plano e origem; frequência com gráfico de barras diário acompanhado por tabela ou resumo equivalente; financeiro com resumo numérico e tabela por forma de pagamento, sem gráfico decorativo obrigatório. Não esconder valores ou categorias essenciais em hover, tooltip ou cor isolada.

**Estados de interface:** zero é valor legítimo; ausência de registros recebe mensagem contextual; média não aplicável usa `—`; falha de consulta apresenta erro controlado, nunca zero; falta de permissão omite a seção. Como as páginas são renderizadas no servidor, skeletons e animações de carregamento ficam fora do MVP enquanto não houver carregamento assíncrono.

**Limites de apresentação:** priorizar poucos indicadores acionáveis; não incluir percentuais de crescimento, rankings, metas, churn, previsões, faturamento projetado ou comparações com períodos anteriores sem base temporal e definição confiáveis. Estados sem dados, zero legítimo, falha de consulta e falta de permissão devem ser tratados como situações diferentes.

## 3. Escopo proposto para o MVP

### 3.1 Visão operacional

**Decisão aprovada em 23/11/2026 — OP-01 a OP-03:** total de alunos = todos os registros de `alunos`; alunos ativos = `ativo = 1`; alunos inativos = `ativo = 0`. São fotografias cadastrais atuais, exigem `students.view` e não são alteradas pelo filtro de período. Os três valores devem vir de um único agregado. A migration 015 protege o domínio binário de `ativo`, permitindo conferir a invariável total = ativos + inativos. Não confundir o estado cadastral com matrículas vigentes (VG-02).

Indicadores de situação atual, claramente identificados como fotografia do momento da consulta:

- alunos ativos e inativos;
- matrículas atualmente agendadas, vigentes, vencidas e canceladas, conforme as regras de domínio existentes;
- matrículas com início previsto nos próximos 7 e 30 dias;
- matrículas com término previsto nos próximos 7 e 30 dias.

A contagem de situação deve usar uma única referência temporal por requisição, no fuso `America/Sao_Paulo`, respeitando o intervalo semiaberto das matrículas `[início, fim exclusivo)`. **Decisão aprovada em 23/11/2026 — OP-04 a OP-06:** OP-04 classifica todas as matrículas em AGENDADA, ATIVA, VENCIDA ou CANCELADA. OP-05 conta inícios futuros de matrículas AGENDADAS; OP-06 conta términos futuros de matrículas ATIVAS. Ambos exibem janelas cumulativas de 7 e 30 dias, com limites inclusivos, sem depender do período selecionado. Os três exigem `enrollments.view`. A janela OP-06/7 dias deve ser a mesma fonte de VG-06. A consulta deve recusar a resposta inteira quando encontrar `fim_exclusivo_em <= inicio_em`, sem tentar corrigir o registro.

### 3.2 Relatório de matrículas

Para o período selecionado:

- quantidade de matrículas cujo `inicio_em` ocorreu no período;
- agrupamento por plano contratado (snapshot da matrícula, não nome/preço atual do catálogo);
- quantidade de matrículas canceladas no período, usando `cancelada_em` e sua convenção temporal documentada;
- separação entre matrículas de origem `SISTEMA` e `LEGADO`, quando isso for útil para interpretação.

A tela deve nomear o indicador como **inícios de contrato no período**, não como quantidade de matrículas criadas, quando o filtro usar `inicio_em`. **Decisão técnica registrada em 23/11/2026 — MT-01 a MT-04:** considerar início realizado somente quando o intervalo efetivo não é vazio: `fim_exclusivo_em > inicio_em` e `cancelada_em IS NULL OR cancelada_em > inicio_em`. Cancelamento antes ou exatamente no início não conta como início de contrato; cancelamento depois do início preserva o início realizado. MT-02, MT-03 e MT-04 usam exatamente a coorte de MT-01. MT-05 conta todos os eventos `cancelada_em` dentro do período, inclusive cancelamentos antes do início. Se houver intervalo inválido (`fim_exclusivo_em <= inicio_em`), a consulta falha de forma controlada sem publicar resultados parciais. MT-04 permanece informação secundária.

### 3.3 Relatório de frequência

Para o período selecionado:

- total de check-ins registrados;
- quantidade de alunos distintos com ao menos um check-in;
- evolução diária de entradas;
- distribuição agregada por dia da semana, com média por ocorrência do calendário, total bruto e quantidade de ocorrências;
- média de dias com check-in por aluno frequentador (FR-05), como indicador secundário.

O período usa `checkins.data_local`, que já representa a data civil no fuso da academia. Não converter essa coluna para UTC nem derivar presenças de horários da grade. FR-03 completa todas as datas civis do intervalo com zero quando não há registro; isso não afirma que a academia estava aberta. FR-04 divide pelo número de ocorrências de cada weekday no calendário do período, independentemente de feriados ou fechamentos, pois o sistema não mantém calendário operacional. FR-05 é `FR-01 / FR-02`, usando o mesmo filtro; mede dias com entrada por aluno que compareceu pelo menos uma vez. Quando FR-02 for zero, exibir não aplicável (—). Não incluir alunos ativos sem check-in no denominador.

### 3.4 Relatório financeiro

Visível somente a quem possui `finance.view`:

- **FN-01 — recebido no período:** soma de `pagamentos.valor` por `data_pagamento`, com início e fim inclusivos;
- **FN-02 — quantidade de pagamentos:** contagem de lançamentos individuais que compõem FN-01; pagamentos parciais da mesma cobrança contam separadamente;
- **FN-03 — recebido por forma:** soma por `forma_pagamento`, usando somente `DINHEIRO`, `PIX`, `TRANSFERENCIA`, `CARTAO` e `OUTRO`;
- os totais por forma reconciliam com FN-01 e as contagens auxiliares por forma com FN-02; os três usam exatamente o mesmo período e conjunto de pagamentos;
- valores de cobranças com vencimento no período, apresentados separadamente dos pagamentos registrados;
- fotografia atual de saldo cobrável e saldo em revisão, caso reutilize os indicadores existentes do painel financeiro.

Pagamentos retroativos com justificativa são atribuídos à `data_pagamento`, ainda que o lançamento tenha sido registrado depois. Não excluir pagamentos do conjunto por cancelamento posterior da matrícula e não juntar matrículas/cobranças para calcular FN-01 a FN-03. Se o período não tiver lançamentos, FN-01 e FN-02 são zero e FN-03 apresenta estado vazio. Não apresentar saldo histórico de uma data passada como fato garantido sem definir uma consulta temporal que considere pagamentos, cancelamentos e lançamentos retroativos. Não tratar matrícula cancelada como pagamento estornado.

### 3.5 Filtros e experiência

- período inicial padrão: do primeiro dia do mês civil atual até hoje, em `America/Sao_Paulo`;
- os parâmetros HTTP `de` e `ate` são ambos opcionais somente em conjunto; quando os dois forem omitidos, usar o padrão. Se apenas um faltar, rejeitar;
- datas `YYYY-MM-DD` devem passar por parsing estrito e round-trip; datas impossíveis, formato inválido, início posterior ao fim e períodos acima de 365 dias civis inclusivos são rejeitados no servidor, sem substituição silenciosa pelo padrão;
- datas futuras são permitidas quando selecionadas explicitamente; a permissão do filtro não cria projeções nem pagamentos futuros;
- início e fim são inclusivos para o usuário; conversões de `DATETIME` UTC usam limites semiabertos internos;
- preservar os filtros validados ao navegar entre relatórios e ao exportar CSV; revalidar no servidor em cada requisição;
- rótulo visível do período aplicado e indicação do eixo temporal de cada métrica;
- estados vazios, erro de filtro, carregamento e ausência de dados;
- gráficos simples e responsivos, sempre acompanhados por tabela ou resumo textual equivalente; nenhum dado pode depender somente de cor, hover ou tooltip;
- navegação completa por teclado e foco visível em todos os controles;
- contraste de texto mínimo de 4,5:1 (3:1 para texto grande) e contraste mínimo de 3:1 para informação visual não textual relevante de controles/estados contra as cores adjacentes;
- alvos de interação com pelo menos 24 × 24 CSS px, respeitando as exceções de espaçamento e demais exceções da WCAG 2.2;
- reflow sem perda de informação ou funcionalidade a 320 CSS px; permitir rolagem horizontal localizada apenas quando uma tabela de dados realmente exigir estrutura bidimensional;
- compatibilidade com tema claro/escuro, foco verificável nos dois temas e respeito a `prefers-reduced-motion`;
- não criar skeletons para a renderização server-side síncrona do MVP.

### 3.6 Exportação CSV — escopo aprovado para o MVP

- Um arquivo CSV por relatório, limitado aos indicadores agregados exibidos e ao mesmo período validado aplicado na tela.
- Não exportar registros individuais nem incluir identificadores de aluno/funcionário, nomes, e-mails, telefones, observações, tokens, dados de autenticação ou qualquer outra PII.
- Aplicar no servidor a mesma autorização do relatório; o CSV financeiro exige `finance.view` mesmo quando a URL é chamada diretamente.
- Usar cabeçalhos fixos/estáveis e documentados, UTF-8 e escaping CSV correto, incluindo tratamento de aspas e separadores.
- Tratar nomes de planos contratados e outros textos originados de registros como não confiáveis. Na variante destinada à leitura humana em planilhas, prefixar com tab campos textuais cujo primeiro caractere efetivo seja iniciador de fórmula (`=`, `+`, `-`, `@`, tab, CR/LF e variantes Unicode de largura completa reconhecidas pela rotina), além do escaping CSV.
- A tabulação adicionada passa a fazer parte do conteúdo; por isso, este CSV é voltado à leitura humana em planilhas e não deve ser reutilizado como formato bruto de integração entre sistemas.
- Testar os casos maliciosos e a estrutura resultante; validar abertura no Excel e LibreOffice quando os ambientes estiverem disponíveis. Não afirmar que a mitigação é universal para todo leitor de CSV.
- Não incluir SQL, erros internos, stack traces ou dados de auditoria.

**Fora do MVP:** PDF, XLSX e exportação nominal/linha a linha de alunos.

## 4. Permissões

Proposta: reaproveitar permissões existentes, sem criar um `reports.view` genérico que amplie acesso sem necessidade.

| Seção | Permissão do servidor | Administrador | Gestor | Operador |
|---|---|:---:|:---:|:---:|
| Visão operacional/alunos | `students.view` e/ou `enrollments.view` conforme a consulta | Sim | Sim | Sim |
| Matrículas | `enrollments.view` | Sim | Sim | Sim |
| Frequência | `attendance.view` | Sim | Sim | Sim |
| Financeiro | `finance.view` | Sim | Sim | Não |

Cada seção e cada endpoint CSV devem revalidar a permissão correspondente no servidor **antes de instanciar/executar consultas**. Não basta ocultar cartões ou botões. Administrador tem acesso integral; Gestor tem as permissões de leitura de operacional, matrículas, frequência e financeiro; Operador tem acesso às três primeiras, mas não a `finance.view`. Para o Operador, a Visão geral não deve executar consultas financeiras nem incluir valores, totais, contagens, formas, saldos ou metadados financeiros no HTML/JSON/gráficos/CSV. A seção financeira deve ser omitida integralmente, sem placeholders que permitam inferir dados.

## 5. Segurança, desempenho e integridade

- Consultas exclusivamente parametrizadas; nomes de coluna, agrupamentos e tipos de relatório devem vir de listas permitidas.
- Limitar intervalo de datas e volume de saída.
- Evitar N+1; calcular os agregados diretamente com SQL e índices já existentes.
- Não adicionar migration ou índice preventivamente. Primeiro medir as consultas e justificar qualquer alteração de schema.
- Exportações devem usar a mesma autorização e os mesmos filtros da tela.
- O relatório é somente leitura; não alterar matrículas, check-ins, cobranças, pagamentos ou auditoria.
- Usar os serviços/formatadores existentes para estados e valores monetários, sem converter DECIMAL para `float`.
- Registrar no log técnico falhas inesperadas sem expor SQL, credenciais ou dados pessoais ao usuário.

## 6. Fora do escopo

- Previsão de receita, churn, LTV, metas ou recomendações baseadas em modelos.
- Presença por modalidade/aula, taxa de ocupação e faltas por aula.
- Reconstrução histórica diária de status de aluno ou saldo financeiro sem snapshots auditáveis suficientes.
- Exportação de listas de dados pessoais de alunos.
- PDF, XLSX, integração com ferramentas externas ou agendamento automático de relatórios.
- Multiunidade, BI/warehouse, dashboards customizados e construtor de consultas.
- Redesign geral de todas as páginas; melhorias de experiência ficam restritas às telas e fluxos deste bloco.

## 7. Critérios de aceite propostos

- [ ] Definições de cada indicador e eixo temporal aprovadas e documentadas.
- [ ] Visão geral separa fotografia atual, movimentação do período e alertas próximos.
- [ ] A linha atual de três cards é substituída pelos seis cartões VG-01 a VG-06, nos dois grupos aprovados e em ordem responsiva.
- [ ] O Operador não recebe nem reserva espaço para VG-05; a seção financeira é omitida integralmente.
- [ ] A pesquisa, os filtros e a tabela de alunos, bem como o atalho de cadastro, são preservados.
- [ ] A distribuição por plano continua secundária, usa `$alunosAtivos` como denominador, identifica `alunos.plano` como dado cadastral e apresenta estado vazio se não houver alunos ativos/grupos.
- [ ] Cartões da Visão geral apresentam os mesmos resultados dos relatórios de origem, reutilizando seus agregadores/serviços.
- [ ] Perfis sem `finance.view` não recebem valores financeiros nem conseguem consultá-los por endpoints; a Visão geral do Operador não executa consultas financeiras e omite completamente a seção.
- [ ] Sem `de` e `ate`, o padrão é primeiro dia do mês até hoje em `America/Sao_Paulo`; se somente um parâmetro faltar, a requisição é rejeitada.
- [ ] Filtros rejeitam datas inválidas, formatos inesperados, períodos invertidos e intervalos acima de 365 dias civis inclusivos; datas futuras só entram quando selecionadas explicitamente.
- [ ] Os filtros validados persistem na navegação entre relatórios e nas exportações CSV.
- [ ] Agregados de matrículas respeitam `inicio_em`, `fim_exclusivo_em`, cancelamento e origem.
- [ ] Agregados de frequência usam `data_local` e contam alunos distintos sem duplicar entradas.
- [ ] Agregados financeiros distinguem cobranças, pagamentos registrados, saldo cobrável e saldo em revisão.
- [ ] Operador não consegue consultar nem exportar dados financeiros; Admin/Gestor conseguem.
- [ ] Todos os endpoints validam autorização no servidor e usam consultas preparadas.
- [ ] CSV exporta somente agregados exibidos, aplica o mesmo período e revalida RBAC no servidor; não contém PII.
- [ ] Campos textuais não confiáveis recebem a neutralização tab-prefix definida para a variante de planilha, além do escaping CSV correto; casos de fórmula maliciosa, UTF-8, aspas e separadores são testados.
- [ ] Abertura no Excel e LibreOffice é verificada quando os ambientes estiverem disponíveis, com documentação do trade-off e sem alegação de proteção universal.
- [ ] PDF, XLSX e exportação nominal permanecem fora do MVP.
- [ ] Zero legítimo, ausência de registros, não aplicável, erro de consulta e falta de permissão são estados distintos; falha nunca vira zero.
- [ ] Relatório operacional e de matrículas privilegia tabelas; frequência usa barras diárias com equivalente textual/tabular; financeiro usa resumo e tabela por forma, sem depender de gráfico decorativo.
- [ ] Foco visível por teclado é verificável em todos os controles; estados não textuais relevantes atendem contraste 3:1 com cores adjacentes; texto normal atende 4,5:1.
- [ ] Alvos interativos atendem 24 × 24 CSS px ou uma exceção da WCAG 2.2 é documentada; reflow é testado a 320 CSS px sem perda de informação ou funcionalidade.
- [ ] Tema claro/escuro, `prefers-reduced-motion`, navegação por teclado e gráficos que não dependem só de cor são cobertos.
- [ ] Skeletons ficam fora enquanto a página for renderizada de forma síncrona no servidor.
- [ ] Testes de regressão comprovam que os relatórios não alteram dados operacionais nem a cadeia de auditoria.
- [ ] MySQL 8.0 e MariaDB 10.11 passam pela CI; o smoke test local permanece separado e documentado.

## 8. Decisões que precisam de aprovação antes da implementação

1. Confirmar os indicadores restantes do catálogo nas seções 3.1 e 3.2; OP-01 a OP-06 e MT-01 a MT-05 já foram definidos/aprovados em 23/11/2026.
2. Relatório de frequência FR-01 a FR-05 aprovado em 23/11/2026: total de entradas, alunos distintos, evolução diária, média por ocorrência de weekday e média de dias com entrada por aluno frequentador. FR-04 usa ocorrências do calendário, não dias de funcionamento; FR-05 usa FR-01/FR-02 e é não aplicável quando não há frequentadores.
3. FN-01 a FN-06: definições aprovadas por etapas até 23/11/2026. FN-04 separa valores nominais por vencimento e situação atual da matrícula; FN-05 soma o saldo positivo das cobranças ainda cobráveis; FN-06 soma o saldo positivo das cobranças em revisão vinculadas a matrículas canceladas, sem representar reembolso.
4. Confirmar CSV agregado somente, sem dados pessoais, deixando PDF/XLSX fora do MVP.
5. Confirmar mês atual como período padrão e limite de 365 dias por consulta.
6. Confirmar o uso das permissões existentes, com financeiro visível somente para Administrador/Gestor.
7. Confirmar a Visão geral como camada de resumo do dashboard existente, com fotografia atual, indicadores do período, alerta de términos próximos e atalhos para relatórios.

### Etapa 07.1 — definição dos indicadores (catálogo documentado; aprovação parcial)

Catálogo detalhado: [Bloco 07.1 — Catálogo e contrato dos indicadores](bloco-07-catalogo-indicadores.md).

Entregáveis desta etapa:
- catálogo de indicadores com nome exibido, finalidade, fórmula, fonte de dados, eixo temporal, referência de data, permissão exigida e ressalvas;
- distinção entre fotografia atual, movimentação por período e alertas ancorados na data atual;
- contrato de filtros e parâmetros compartilhados pelos relatórios;
- matriz de visibilidade da Visão geral por permissão;
- mapeamento de cada cartão para o relatório detalhado que serve de fonte de verdade;
- decisões registradas sobre métricas que não podem ser calculadas com os dados atuais.

**Decisão registrada em 23/11/2026:** FR-01 a FR-03 e FR-05 foram aprovados; FR-04 mantém sua regra anteriormente aprovada com a ressalva de que o denominador representa ocorrências do calendário, não dias comprovadamente abertos. FR-05 calcula FR-01/FR-02 entre alunos frequentadores e exibe — quando FR-02 = 0. A seção mede entradas, não aulas ou presença por modalidade.

**Decisão registrada em 23/11/2026:** FN-01 a FN-03 foram aprovados: soma, contagem e distribuição por forma dos mesmos pagamentos filtrados por `data_pagamento` inclusiva; lançamentos retroativos seguem a data declarada; cancelamento posterior da matrícula não elimina o pagamento; o grupo por forma deve reconciliar com os totais. O rótulo **“Recebido no período”** não significa faturamento, lucro, liquidação bancária ou recebimento líquido. O catálogo continua aguardando aprovação global das demais fórmulas e permissões.

**Decisão registrada em 23/11/2026:** OP-01 a OP-03 foram aprovados como fotografias cadastrais independentes do período, sob `students.view`, com contagem agregada única e domínio de `ativo` protegido pela migration 015. OP-04 a OP-06 também foram aprovados: usam `enrollments.view`, uma única referência temporal, as quatro situações canônicas e janelas cumulativas de 7/30 dias; OP-06/7 dias corresponde a VG-06. Intervalos inválidos (`fim_exclusivo_em <= inicio_em`) interrompem a consulta sem resultados parciais. **A migration 015 é uma proteção cadastral separada; não implementa consultas ou telas do Bloco 07.**

**Decisão técnica registrada em 23/11/2026:** MT-01 a MT-04 contam inícios efetivos; cancelamentos anteriores ou simultâneos ao início não criam vigência e ficam fora da coorte de início. MT-05 conta eventos de cancelamento inclusive quando a matrícula nunca chegou a vigorar. MT-02 a MT-04 reutilizam exatamente a coorte de MT-01.

**Decisão registrada em 23/11/2026:** o rótulo **“Recebido no período”** foi aprovado para a soma dos pagamentos por `data_pagamento`, com legenda esclarecendo que se trata de pagamentos registrados e não de liquidação bancária. A decisão foi complementada pela aprovação de FR-04, FN-01 a FN-06 e do contrato de filtros/permissões: mês até hoje como padrão; ambos os parâmetros ausentes aplicam o padrão, somente um ausente é erro; datas futuras podem ser selecionadas explicitamente; limite de 365 dias inclusivos; filtros persistem na navegação/CSV; cada seção é autorizada antes da consulta, e o Operador não executa nem recebe dados financeiros. **Escopo de exportação CSV aprovado em 23/11/2026:** um arquivo agregado por relatório, mesmos filtros e permissões da tela, sem PII; escaping CSV correto e neutralização tab-prefix dos campos textuais não confiáveis para leitura em planilhas; teste em Excel/LibreOffice quando disponíveis, sem alegar proteção universal. PDF, XLSX e exportação nominal ficam fora do MVP.

**Critérios de apresentação aprovados em 23/11/2026:** seis cards VG-01 a VG-06 em dois grupos; preservar pesquisa/lista de alunos e atalho de cadastro; manter distribuição de planos como painel secundário e calcular percentuais com a base de alunos ativos (`$alunosAtivos`), não total de cadastrados. O painel deve informar que usa o plano cadastral e exibir estado vazio quando não houver alunos ativos/grupos. Relatórios operacional e de matrículas priorizam tabelas; frequência usa barras diárias e tabela/resumo equivalente; financeiro usa resumo e tabela por forma. Zero, vazio, não aplicável, erro e falta de permissão são estados distintos. Acessibilidade: foco de teclado visível, contraste WCAG aplicável, alvos mínimos, reflow a 320 CSS px, dois temas, redução de movimento e informação não dependente só de cor/hover. Skeletons ficam fora da renderização server-side síncrona. FN-06 é fotografia atual do saldo restante de cobranças não canceladas financeiramente ligadas a matrículas canceladas; não representa reembolso. Os demais indicadores, filtros e permissões continuam em revisão.

**Saída esperada da 07.1:** especificação revisável e testável, sem alteração de banco nem implementação de telas. A etapa seguinte só começa depois da revisão das fórmulas e do contrato de acesso.

**Recomendação:** implementar primeiro consultas agregadas e testes de domínio/SQL; depois a página de relatórios; por último os endpoints CSV e a cobertura Playwright. Só iniciar após aprovação deste escopo.

### Etapa 07.2 — contratos de consulta e validação (desenho revisado; aprovação pendente)

Documento: [Bloco 07.2 — Contratos de consulta e validação](bloco-07.2-contratos-consulta.md).

O desenho define responsabilidades para período imutável, relógio único, política de autorização, consultas agregadas, payload de saída e matriz de testes. FR-01 a FR-05 foram aprovados: FR-01 conta entradas, FR-02 alunos distintos, FR-03 completa datas sem registro com zero, FR-04 calcula a média por ocorrência de calendário (não por dia de funcionamento) e FR-05 calcula FR-01/FR-02 entre alunos frequentadores, com — quando não há denominador. A revisão alinhou a omissão integral de seções não autorizadas, a janela inclusiva dos próximos sete dias e a legenda do indicador financeiro. Não introduz migrations, telas ou código de relatório. A implementação da 07.2 depende da aprovação global do catálogo 07.1 e deste contrato.
