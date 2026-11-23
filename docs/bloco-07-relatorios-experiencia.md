# Bloco 07 — Relatórios e experiência

> **Estado:** proposta inicial de escopo; ainda não aprovada nem implementada.
> **Branch de referência:** `feat/dashboard-portfolio`.
> **Dependência anterior:** Bloco 06 com validação automatizada aprovada na CI #1512. A validação manual dos blocos no XAMPP/dispositivo permanece adiada e registrada separadamente.
> **Regra:** não iniciar implementação antes de confirmar indicadores, filtros, exportações e permissões.

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

- total recebido no período, agrupado por `pagamentos.data_pagamento`;
- quantidade de pagamentos e distribuição por forma de pagamento;
- valores de cobranças com vencimento no período, apresentados separadamente do dinheiro recebido;
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
- [ ] Filtros de data rejeitam datas inválidas, períodos invertidos e intervalos acima do limite.
- [ ] Agregados de matrículas respeitam `inicio_em`, `fim_exclusivo_em`, cancelamento e origem.
- [ ] Agregados de frequência usam `data_local` e contam alunos distintos sem duplicar entradas.
- [ ] Agregados financeiros distinguem cobranças, pagamentos recebidos, saldo cobrável e saldo em revisão.
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

**Recomendação:** implementar primeiro consultas agregadas e testes de domínio/SQL; depois a página de relatórios; por último os endpoints CSV e a cobertura Playwright. Só iniciar após aprovação deste escopo.
