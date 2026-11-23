# Bloco 07 — Relatórios e experiência

> **Estado:** direção da Visão geral aceita; catálogo técnico da etapa 07.1 documentado e aguardando revisão/aprovação. Nenhuma funcionalidade do Bloco 07 foi implementada.
> **Branch de referência:** `feat/dashboard-portfolio`.
> **Dependência anterior:** Bloco 06 com validação automatizada aprovada na CI #1512. A validação manual dos blocos no XAMPP/dispositivo permanece adiada e registrada separadamente.
> **Regra:** revisar e aprovar o catálogo `bloco-07-catalogo-indicadores.md` antes de iniciar a etapa 07.2. A aprovação da direção da Visão geral não antecipa a aprovação automática de cada fórmula ou endpoint.

## Documentos do bloco

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

O período padrão dos indicadores de movimentação será o mês civil atual, no fuso `America/Sao_Paulo`. Alterar o período não deve alterar os cartões de fotografia atual nem deslocar a janela do alerta de próximos 7 dias. A interface deve exibir o período aplicado e identificar os cartões que representam situação atual.

A visualização da frequência pode apresentar uma evolução diária de check-ins, acompanhada por resumo textual ou tabela. Não deve sugerir ocupação de aulas, presença em modalidades ou frequência individual por aula.

O resumo financeiro deve ser omitido para perfis sem `finance.view`. Além de ocultar o cartão, a camada de servidor não deve enviar valores financeiros ao cliente nem permitir consulta indireta por endpoint. As demais seções seguem suas permissões de domínio.

A Visão geral deve reutilizar os mesmos agregadores/serviços e as mesmas definições dos relatórios detalhados. Não manter fórmulas SQL independentes para o cartão e para o relatório correspondente. Cada indicador terá um atalho para seu relatório de origem.

**Limites de apresentação:** priorizar poucos indicadores acionáveis; não incluir percentuais de crescimento, rankings, metas, churn, previsões, faturamento projetado ou comparações com períodos anteriores sem base temporal e definição confiáveis. Estados sem dados, zero legítimo, falha de consulta e falta de permissão devem ser tratados como situações diferentes.

## 3. Escopo proposto para o MVP

### 3.1 Visão operacional

Indicadores de situação atual, claramente identificados como fotografia do momento da consulta:

- alunos ativos e inativos;
- matrículas atualmente agendadas, vigentes, vencidas e canceladas, conforme as regras de domínio existentes;
- matrículas com início previsto nos próximos 7 e 30 dias;
- matrículas com término previsto nos próximos 7 e 30 dias.

A contagem de situação deve usar uma única referência temporal por requisição, no fuso `America/Sao_Paulo`, respeitando o intervalo semiaberto das matrículas `[início, fim exclusivo)`.

### 3.2 Relatório de matrículas

Para o período selecionado:

- quantidade de matrículas cujo `inicio_em` ocorreu no período;
- agrupamento por plano contratado (snapshot da matrícula, não nome/preço atual do catálogo);
- quantidade de matrículas canceladas no período, usando `cancelada_em` e sua convenção temporal documentada;
- separação entre matrículas de origem `SISTEMA` e `LEGADO`, quando isso for útil para interpretação.

A tela deve nomear o indicador como **inícios de contrato no período**, não como quantidade de matrículas criadas, quando o filtro usar `inicio_em`.

### 3.3 Relatório de frequência

Para o período selecionado:

- total de check-ins registrados;
- quantidade de alunos distintos com ao menos um check-in;
- evolução diária de entradas;
- distribuição agregada por dia da semana, quando o intervalo tiver dados suficientes.

O período usa `checkins.data_local`, que já representa a data civil no fuso da academia. Não converter essa coluna para UTC nem derivar presenças de horários da grade.

### 3.4 Relatório financeiro

Visível somente a quem possui `finance.view`:

- total de pagamentos registrados no período, agrupado por `pagamentos.data_pagamento`;
- quantidade de pagamentos e distribuição por forma de pagamento;
- valores de cobranças com vencimento no período, apresentados separadamente dos pagamentos registrados;
- fotografia atual de saldo cobrável e saldo em revisão, caso reutilize os indicadores existentes do painel financeiro.

Não apresentar saldo histórico de uma data passada como fato garantido sem definir uma consulta temporal que considere pagamentos, cancelamentos e lançamentos retroativos. Não somar cobranças canceladas nem tratar matrícula cancelada como pagamento estornado.

### 3.5 Filtros e experiência

- período inicial padrão: mês civil atual;
- filtros de data inicial/final validados no servidor, com início menor ou igual ao fim;
- limite proposto de 365 dias por consulta/exportação;
- rótulo visível do período aplicado e indicação do eixo temporal de cada métrica;
- estados vazios, erro de filtro, carregamento e ausência de dados;
- gráficos simples e responsivos, sempre acompanhados por tabela ou resumo textual equivalente;
- navegação por teclado, foco visível, rótulos acessíveis e compatibilidade com tema claro/escuro.

### 3.6 Exportação proposta

- CSV agregado por relatório e pelo mesmo período exibido na tela;
- exportação somente dos campos necessários à métrica escolhida;
- sem nome, e-mail, telefone, observações, tokens ou dados de autenticação;
- exportação financeira restrita a `finance.view`;
- validação do tipo de relatório, das datas e da autorização no servidor; não confiar em campos ocultos ou em permissões da interface;
- respostas CSV devem neutralizar valores que possam ser interpretados como fórmulas por planilhas e usar cabeçalhos estáveis/documentados.

PDF, XLSX e exportação linha a linha de alunos ficam fora desta proposta inicial.

## 4. Permissões

Proposta: reaproveitar permissões existentes, sem criar um `reports.view` genérico que amplie acesso sem necessidade.

| Seção | Permissão do servidor | Administrador | Gestor | Operador |
|---|---|:---:|:---:|:---:|
| Visão operacional/alunos | `students.view` e/ou `enrollments.view` conforme a consulta | Sim | Sim | Sim |
| Matrículas | `enrollments.view` | Sim | Sim | Sim |
| Frequência | `attendance.view` | Sim | Sim | Sim |
| Financeiro | `finance.view` | Sim | Sim | Não |

Cada seção e cada endpoint CSV devem revalidar a permissão correspondente no servidor. Não basta ocultar cartões ou botões. O Operador nunca deve receber totais financeiros, cobranças, pagamentos ou arquivos financeiros.

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
- [ ] Cartões da Visão geral apresentam os mesmos resultados dos relatórios de origem, reutilizando seus agregadores/serviços.
- [ ] Perfis sem `finance.view` não recebem valores financeiros nem conseguem consultá-los por endpoints.
- [ ] Filtros de data rejeitam datas inválidas, períodos invertidos e intervalos acima do limite.
- [ ] Agregados de matrículas respeitam `inicio_em`, `fim_exclusivo_em`, cancelamento e origem.
- [ ] Agregados de frequência usam `data_local` e contam alunos distintos sem duplicar entradas.
- [ ] Agregados financeiros distinguem cobranças, pagamentos registrados, saldo cobrável e saldo em revisão.
- [ ] Operador não consegue consultar nem exportar dados financeiros; Admin/Gestor conseguem.
- [ ] Todos os endpoints validam autorização no servidor e usam consultas preparadas.
- [ ] CSV contém somente agregados permitidos, respeita filtros/RBAC e neutraliza conteúdo de fórmula.
- [ ] Estados vazios, erros, acessibilidade, teclado, tema claro/escuro e viewports mobile/desktop são cobertos.
- [ ] Testes de regressão comprovam que os relatórios não alteram dados operacionais nem a cadeia de auditoria.
- [ ] MySQL 8.0 e MariaDB 10.11 passam pela CI; o smoke test local permanece separado e documentado.

## 8. Decisões que precisam de aprovação antes da implementação

1. Confirmar os indicadores operacionais e de matrícula listados nas seções 3.1 e 3.2.
2. Confirmar o relatório de frequência com total de entradas, alunos distintos e evolução diária.
3. Confirmar o relatório financeiro limitado a recebimentos por `data_pagamento`, vencimentos de cobranças e fotografia atual de saldos.
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

**Decisão registrada em 23/11/2026:** nomenclatura **“Recebido no período”** aprovada para a soma de pagamentos por `data_pagamento`; não chamar de faturamento ou lucro. O catálogo continua aguardando aprovação global das demais fórmulas e permissões.

**Decisão registrada em 23/11/2026:** o rótulo **“Recebido no período”** foi aprovado para a soma dos pagamentos por `data_pagamento`, com legenda esclarecendo que se trata de pagamentos registrados e não de liquidação bancária. Os demais indicadores, filtros e permissões aguardam aprovação global.

**Saída esperada da 07.1:** especificação revisável e testável, sem alteração de banco nem implementação de telas. A etapa seguinte só começa depois da revisão das fórmulas e do contrato de acesso.

**Recomendação:** implementar primeiro consultas agregadas e testes de domínio/SQL; depois a página de relatórios; por último os endpoints CSV e a cobertura Playwright. Só iniciar após aprovação deste escopo.

### Etapa 07.2 — contratos de consulta e validação (desenho revisado; aprovação pendente)

Documento: [Bloco 07.2 — Contratos de consulta e validação](bloco-07.2-contratos-consulta.md).

O desenho define responsabilidades para período imutável, relógio único, política de autorização, consultas agregadas, payload de saída e matriz de testes. A revisão alinhou a omissão integral de seções não autorizadas, a janela inclusiva dos próximos sete dias e a legenda do indicador financeiro. Não introduz migrations, telas ou código de relatório. A implementação da 07.2 depende da aprovação global do catálogo 07.1 e deste contrato.
