# Bloco 03 — Planos e matrículas

> **Estado em 22/11/2026: implementação funcional avançada na branch, com CI de push e PR aprovadas.** Catálogo de planos, criação e histórico de matrículas, cancelamento, revisão de vínculos legados e retificação de vigência estão implementados e cobertos por testes automatizados. As migrations 005 e 006 ainda não existem; o esquema do banco local/XAMPP não foi alterado. A inspeção em dispositivo físico e a validação das migrations no ambiente local continuam pendentes.

## 0. Estado de implementação (22/11/2026)

### Implementado na branch de trabalho

- Serviço transacional do catálogo de planos, incluindo criação, edição, ativação, inativação e exclusão protegida.
- Interface administrativa do catálogo em `planos.php`, com operações POST autenticadas e protegidas por CSRF.
- Formulário de contratação no catálogo, com seleção de aluno ativo, plano ativo, data e horário.
- Atalho de contratação no perfil do aluno, oculto quando o aluno está inativo ou a revisão de legado está pendente.
- Integração com `EnrollmentCreationService`: bloqueios transacionais, validação de elegibilidade, detecção de sobreposição, snapshot do preço/nome/duração e auditoria atômica.
- Regra de horário na contratação: hoje sem horário resolve o instante depois dos bloqueios; data futura sem horário começa às 00:00 no fuso da academia.
- Fila administrativa de revisão de legados em `revisoes-legado.php`, com pendentes por padrão, filtros por resultado, busca e limite explícito de 100 registros por consulta.
- Decisão de revisão por `salvar-revisao-legado.php`, com autenticação, POST, CSRF, referência de evidência, justificativa e intervalo exato quando comprovado.
- Histórico de decisões append-only na fila e no perfil do aluno, com responsável, resultado, fonte, observações e instante do evento.
- Cancelamento de matrículas agendadas/ativas no histórico do perfil, com motivo opcional, revalidação transacional e auditoria atômica.
- Serviço `LegacyEnrollmentRectificationService` para corrigir somente o intervalo de uma matrícula `LEGADO`, preservando valores anteriores, condições históricas e cancelamento.
- Interface específica em `retificar-vigencia-legado.php` e endpoint `salvar-retificacao-legado.php`, acessíveis a partir da revisão confirmada.
- Formulário de retificação exibe o intervalo atual no fuso `America/Sao_Paulo`, exige nova fonte e justificativa e explica que somente início/fim exclusivo serão alterados.
- Histórico especializado apresenta os intervalos anterior e novo; a operação registra evento append-only e auditoria geral na mesma transação.
- Idempotência para repetição exata da última retificação, evitando evento ou auditoria duplicados.
- Exibição do histórico operacional no perfil individual do aluno, sem substituir o campo legado `alunos.plano`.
- Derivação de `AGENDADA`, `ATIVA`, `VENCIDA` e `CANCELADA` a partir dos intervalos e do instante consultado; os estados temporais não são persistidos.
- Fallback seguro no perfil e na nova tela quando as estruturas do Bloco 03 ainda não existem.
- Testes de domínio, persistência, concorrência, rollback, integração HTTP, CSRF, auditoria, idempotência e regressão dos fluxos existentes.

### Validação automatizada confirmada

- CI de push #741: PHP/integração HTTP/capturas, MySQL 8.0 e MariaDB 10.11 aprovados.
- CI de pull request #742: PHP/integração HTTP, MySQL 8.0 e MariaDB 10.11 aprovados.
- A CI HTTP usa tabelas de fixture descartáveis para exercitar a interface de retificação; isso valida a aplicação, mas não substitui a execução das migrations 005/006, que ainda não foram criadas.
- A publicação de capturas é executada no fluxo de push; a execução de PR não publica diretamente os arquivos.

### Ainda pendente

- Projetar, implementar e revisar as migrations 005 (DDL do catálogo/matrículas/revisão/eventos) e 006 (carga inicial transacional dos legados).
- Adicionar as migrations à sequência de importação da CI e testar banco limpo, banco com alunos existentes, reexecução segura e preservação de dados.
- Confirmar a versão real do MySQL/MariaDB e o mecanismo InnoDB no XAMPP antes de qualquer aplicação local.
- Executar as migrations no XAMPP somente depois da revisão, com backup e inspeção de `SHOW CREATE TABLE`/metadados após o DDL.
- Validar no navegador local os fluxos completos de contratação, revisão, retificação, cancelamento e histórico.
- Fazer inspeção física em dispositivo real antes de retirar a PR do estado Draft.

Nenhuma migration 005/006 foi aplicada à base local e nenhuma operação de merge foi realizada. A PR #1 continua aberta em Draft; `main` permanece intacta.


## 1. Objetivo

Substituir gradualmente o campo textual `alunos.plano` por um modelo que represente o catálogo de planos oferecidos pela academia e as matrículas efetivamente contratadas pelos alunos.

A solução deve preservar os registros atuais, manter o histórico das condições contratadas e preparar a integração futura com frequência e financeiro, sem antecipar funcionalidades desses módulos.

## 2. Diagnóstico da base atual

No estado documentado do projeto:

- A aplicação utiliza PHP com PDO e MySQL/MariaDB.
- A tabela `alunos` possui `id`, `nome`, `telefone`, `email` e `plano`.
- A migração 004 adiciona `alunos.ativo`, com padrão 1.
- `alunos.plano` continua sendo texto livre; não existe relacionamento com uma tabela de planos.
- Os alunos existentes não possuem, no modelo atual, valor contratado, data de início ou data de término.
- A situação cadastral do aluno (ativo/inativo) já existe e não deve ser confundida com a situação de uma matrícula.

**Consequência:** o conteúdo de `alunos.plano` não é prova suficiente de preço, vigência ou situação contratual. A migração não deve inventar esses dados.

## 3. Conceito central: três entidades diferentes

O desenho recomendado separa:

1. **Aluno:** a pessoa e seus dados cadastrais.
2. **Plano:** a oferta disponível no catálogo da academia, com nome, duração e preço vigente para novas contratações.
3. **Matrícula:** o vínculo de um aluno com um plano em um período específico, registrando as condições efetivamente contratadas.

Essa separação evita que a edição do preço ou do nome de um plano altere retroativamente o histórico dos alunos. O processo de revisão dos registros antigos terá um registro de controle próprio, separado de `matriculas`; ele não representa contrato nem gera vigência por si só.

## 4. Modelo de dados proposto

O modelo abaixo é conceitual. Os nomes, tamanhos, nulabilidade, índices, restrições e estratégia de migração precisam ser revisados antes da migration.

### 4.1 Tabela `planos`

Responsabilidade: catálogo de ofertas disponíveis para novas matrículas.

Campos candidatos:

| Campo | Finalidade |
|---|---|
| `id` | Identificador primário. |
| `nome` | Nome apresentado no catálogo. |
| `descricao` | Descrição opcional. |
| `duracao_valor` | Quantidade de unidades de duração. |
| `duracao_unidade` | Unidade permitida, inicialmente dia, semana ou mês. |
| `valor_atual` | Preço vigente para novas matrículas, usando tipo monetário exato. |
| `situacao` | Estado do ciclo de vida: `RASCUNHO`, `ATIVO` ou `INATIVO`. |
| `criado_em` / `atualizado_em` | Metadados de criação e atualização, seguindo a nomenclatura existente no banco. |

Regras aprovadas e propostas:

- **Decisão aprovada:** um plano pode ser salvo como rascunho enquanto seu cadastro estiver incompleto.
- Usar `situacao` com três valores distintos: `RASCUNHO` (cadastro ainda incompleto ou não publicado), `ATIVO` (disponível para novas contratações) e `INATIVO` (retirado de novas contratações, mas preservado no histórico).
- Para mudar de `RASCUNHO` para `ATIVO`, todos os campos obrigatórios devem estar preenchidos, a duração deve ser válida e o preço deve ser maior que zero.
- Planos em `RASCUNHO` ou `INATIVO` não aparecem como opção para novas matrículas.
- Um plano sem preço, com preço zero ou com dados obrigatórios incompletos não pode ser ativado nem contratado.
- Um plano `ATIVO` pode ser desativado; reativá-lo exige que os dados obrigatórios continuem válidos.
- Não preencher preço automaticamente e não criar preços fictícios para os planos atuais.
- Alterar o preço do catálogo afeta somente novas matrículas; matrículas existentes preservam o valor contratado.
- **Decisão aprovada:** um plano que ainda não possua nenhuma matrícula vinculada pode ser excluído fisicamente.
- Se existir ao menos uma matrícula vinculada ao plano, a exclusão física é proibida; o plano deve ser desativado.
- Planos desativados permanecem consultáveis no histórico e continuam identificando as matrículas antigas, mas não podem ser selecionados em novas contratações. A desativação não cancela nem altera matrículas já criadas, inclusive as agendadas.
- A verificação de vínculos e a exclusão/desativação devem ocorrer de forma protegida contra concorrência, para evitar que uma matrícula seja criada enquanto o plano está sendo excluído.

### 4.2 Tabela `matriculas`

Responsabilidade: histórico dos vínculos e das condições de contratação.

Campos candidatos:

| Campo | Finalidade |
|---|---|
| `id` | Identificador primário. |
| `aluno_id` | Referência obrigatória ao aluno. |
| `plano_id` | Referência ao plano; poderá ser nula somente para registros legados sem correspondência segura no catálogo. |
| `plano_nome_contratado` | Cópia do nome no momento da contratação, preservando o histórico. |
| `valor_contratado` | Valor registrado para aquela matrícula, independente do preço atual do catálogo; pode ficar nulo somente em matrícula legada quando não houver evidência do valor. |
| `duracao_contratada_valor` / `duracao_contratada_unidade` | Cópia da duração contratada; podem ficar nulas somente em matrícula legada quando a duração original não for comprovada, desde que o intervalo efetivo exato possa ser determinado por fonte confiável. |
| `inicio_em` | Início efetivo da vigência (`DATETIME`), incluindo horário para permitir início após cancelamento intradiário. |
| `fim_exclusivo_em` | Limite final exclusivo (`DATETIME`): a matrícula deixa de valer exatamente neste instante. Para vigência normal, corresponde à meia-noite seguinte ao último dia de calendário inclusivo. |
| `dia_referencia_calendario` | Dia original do mês usado no cálculo de durações mensais e preservado nas renovações contínuas; fica nulo para durações em dias ou semanas. |
| `cancelada_em` | Quando preenchido, registra o instante exato do cancelamento. O evento de auditoria associado identifica o funcionário responsável. Não é necessário persistir `AGENDADA`, `ATIVA` ou `VENCIDA`. |
| `origem` | Distingue registros legados comprovados dos criados pelo fluxo normal; a nulidade de preço/duração só é permitida para a origem legada. |
| `motivo_cancelamento` | Justificativa opcional. |
| `criado_em` | Momento em que o registro foi criado. |

Regras propostas:

- Matrículas novas exigem aluno, plano com `situacao = ATIVO`, valor, duração, início e término válidos; preço e duração desconhecidos só podem permanecer nulos em registros legados cuja vigência tenha sido comprovada.
- Para matrículas com duração em dias ou semanas, `dia_referencia_calendario` não se aplica e deve permanecer nulo.
- O registro guarda uma cópia das condições contratadas. A edição posterior do catálogo não altera matrículas existentes.
- Matrículas antigas não são apagadas ao renovar ou cancelar.
- O relacionamento com aluno e plano deve usar chaves estrangeiras. A exclusão física de planos com histórico deve ser restringida; a desativação é o fluxo normal.
- O projeto já utiliza inativação lógica para alunos. A matrícula deve continuar consultável mesmo que o cadastro do aluno seja inativado.
- Uma matrícula não deve ser excluída fisicamente por uma ação comum da interface.

### 4.3 Registro de revisão dos legados

**Decisão aprovada:** manter a revisão dos registros antigos em uma estrutura própria, separada das matrículas operacionais. A proposta conceitual é uma tabela `revisoes_legado`, com relação de um para um com o aluno de origem.

Campos candidatos:

| Campo | Finalidade |
|---|---|
| `id` | Identificador do registro de revisão. |
| `aluno_id_origem` | Referência ao aluno importado; deve ter chave estrangeira e restrição `UNIQUE`, garantindo no máximo um registro inicial de revisão por aluno. |
| `plano_texto_original` | Cópia exata do valor de `alunos.plano` no momento da migração, apenas para rastreabilidade; a coluna original não será alterada. |
| `resultado_atual` | Um dos resultados `VINCULO_CONFIRMADO`, `LEGADO_SEM_VIGENCIA` ou `PENDENTE_REVISAO`. |
| `fonte_referencia` | Referência da fonte que sustenta a última conclusão humana; fica sem valor na criação automática inicial até a revisão. |
| `criado_em` / `atualizado_em` | Metadados de criação e atualização do controle de revisão. |

Cada decisão humana deve gerar auditoria com funcionário, instante, resultado e referência da fonte. A criação automática inicial como `PENDENTE_REVISAO` deve ser distinguível de uma decisão humana: não cria `revisoes_legado_eventos` com funcionário fictício. A definição física final de tipos, nomes, chaves e índices ainda será validada antes da migration.

**Regra importante:** a existência de `revisoes_legado` não significa que exista uma matrícula. Não criar linhas artificiais em `matriculas` apenas para representar o legado pendente ou sem vigência. Criar uma matrícula operacional somente quando existir contratação comprovada com os dados necessários para determinar sua vigência.

### 4.4 Histórico de decisões da revisão de legados

**Decisão técnica aprovada:** manter os dados atuais da revisão em `revisoes_legado` e registrar cada conclusão humana em uma tabela própria, append-only, proposta como `revisoes_legado_eventos`.

Campos candidatos:

| Campo | Finalidade |
|---|---|
| `id` | Identificador do evento de revisão. |
| `revisao_legado_id` | FK para `revisoes_legado.id`. |
| `usuario_id` | FK obrigatória para `usuarios.id`; decisões humanas sempre identificam o funcionário responsável. |
| `resultado_anterior` | Resultado vigente antes da decisão; será `PENDENTE_REVISAO` no primeiro evento humano, pois a carga inicial já cria o controle nesse resultado. |
| `resultado_novo` | Um dos três resultados aprovados. |
| `fonte_referencia` | Referência da fonte consultada para sustentar a decisão. |
| `observacoes` | Contexto complementar, sem substituir a referência da fonte. |
| `criado_em` | Instante da decisão. |

Regras:
- Cada decisão ou retificação humana insere um novo evento; não atualiza nem apaga eventos anteriores.
- A mesma transação atualiza o resultado/fonte atual em `revisoes_legado`, grava o evento especializado e insere o evento correspondente na tabela geral `auditoria`.
- `DECISAO` registra a conclusão humana. `RETIFICACAO_VIGENCIA` registra a correção do intervalo de uma matrícula de origem `LEGADO`, mantendo `resultado_anterior` e `resultado_novo` como `VINCULO_CONFIRMADO` e armazenando os instantes antigos e novos.
- A retificação de vigência altera somente `inicio_em` e `fim_exclusivo_em` nesta primeira versão. Preço, duração e correspondência com o catálogo não são inferidos nem corrigidos por esse fluxo; valores sem prova continuam nulos.
- Antes da retificação, bloquear o aluno e a matrícula na transação, validar `fim_exclusivo_em > inicio_em` e recalcular sobreposição com as demais matrículas não canceladas, excluindo apenas a matrícula que está sendo corrigida.
- Se uma evidência nova colocar em dúvida a vigência, mas ainda não permitir determinar o intervalo correto, registrar nova decisão `PENDENTE_REVISAO`, bloquear novas matrículas e manter a última linha legada como histórico provisório, claramente sinalizada como sob revisão. Não cancelar, apagar nem alterar datas sem evidência suficiente.
- Ao concluir uma retificação com evidência suficiente, atualizar o intervalo da matrícula, registrar o evento com os valores anteriores e novos e atualizar a referência da revisão na mesma transação. Em falha, fazer rollback integral.
- A transação só confirma se as três gravações forem concluídas. Em falha, fazer rollback para que o estado atual e os dois históricos não divirjam.
- A linha inicial criada automaticamente pela migração como `PENDENTE_REVISAO` não deve inventar funcionário nem gerar uma falsa decisão humana. A origem fica identificada pela migration SQL numerada e, se necessário, por um evento automático em `auditoria` com `usuario_id = NULL`.
- A aplicação não oferece edição ou exclusão de eventos históricos. Correções são novos eventos de revisão, preservando a trilha anterior.

### 4.5 Separar situação do aluno e situação da matrícula

`alunos.ativo` descreve o cadastro da pessoa. A situação da matrícula descreve o contrato e sua vigência. São dimensões independentes.

**Decisão aprovada — estados persistidos versus derivados:** não persistir `AGENDADA`, `ATIVA` nem `VENCIDA`. Esses estados serão calculados em tempo de consulta, comparando o instante atual com `inicio_em` e com o limite efetivo da vigência, sempre no fuso configurado da academia.

| Situação apresentada | Como determinar |
|---|---|
| `CANCELADA` | `cancelada_em` está preenchido; tem prioridade sobre os estados temporais, inclusive se o cancelamento ocorreu antes do início agendado. |
| `AGENDADA` | Não está cancelada e o instante atual é anterior a `inicio_em`. |
| `ATIVA` | Não está cancelada e o instante atual é igual ou posterior a `inicio_em`, mas anterior ao limite efetivo de término. |
| `VENCIDA` | Não está cancelada e o instante atual é igual ou posterior ao limite efetivo de término. |

O cancelamento é persistido pelo instante `cancelada_em` e pelo evento de auditoria que identifica o funcionário responsável; não precisa de um segundo estado textual que possa divergir desse evento.

Os resultados da revisão de legados (`VINCULO_CONFIRMADO`, `LEGADO_SEM_VIGENCIA` e `PENDENTE_REVISAO`) **não são situações da matrícula operacional**. São conclusões de um processo de revisão legado e devem ser armazenadas no registro próprio desse processo, com sua auditoria. Um legado só origina uma matrícula operacional quando as condições necessárias para determinar a vigência forem comprovadas. Um legado confirmado sem matrícula vigente não cria uma matrícula fictícia.

Essa separação evita tarefas periódicas apenas para atualizar estados temporais e impede que uma conclusão administrativa sobre dados antigos seja confundida com a vigência de um contrato.

### 4.6 Relacionamentos e cardinalidade

O modelo final mantém apenas os relacionamentos necessários ao escopo:

| Origem | Relação | Destino | Regra |
|---|---|---|---|
| `alunos` | 1:N | `matriculas` | Um aluno pode ter várias matrículas históricas; os períodos efetivos não podem se sobrepor. |
| `planos` | 1:N | `matriculas` | Um plano pode aparecer em várias matrículas; `plano_id` pode ser nulo somente quando o vínculo legado não puder ser associado com segurança ao catálogo. |
| `alunos` | 1:0..1 | `revisoes_legado` | Cada aluno do conjunto inicial recebe um controle de revisão; alunos criados depois não recebem esse registro automaticamente. |
| `revisoes_legado` | 1:N | `revisoes_legado_eventos` | Cada decisão humana cria um evento histórico imutável na aplicação. |
| `usuarios` | 1:N | `revisoes_legado_eventos` | Toda decisão humana identifica o funcionário autenticado. |
| `usuarios` | 1:N | `auditoria` | A trilha geral identifica quem criou, alterou, cancelou ou revisou; eventos automáticos podem ter autor nulo. |

```text
alunos 1 ───── N matriculas N ───── 0..1 planos
   │
   └────── 0..1 revisoes_legado 1 ───── N revisoes_legado_eventos
                                             │
usuarios 1 ──────────────────────────────────┘

usuarios 1 ───── N auditoria
```

A relação opcional matrícula-plano existe apenas para legados sem correspondência segura no catálogo. Matrículas novas sempre exigem plano ativo. Não criar relação direta entre aluno e plano: o vínculo é representado por `matriculas`.

### 4.7 Tipos, nulabilidade, índices e integridade sugeridos

Os tipos são uma proposta física simples, alinhada aos identificadores atuais (`alunos.id` e `usuarios.id` são `INT UNSIGNED`; `auditoria.id` é `BIGINT UNSIGNED`). Confirmar a versão real do MySQL/MariaDB antes de fechar constraints. O `banco.sql` atual não declara explicitamente `ENGINE` na tabela `alunos`; antes de criar FKs, verificar `information_schema.TABLES.ENGINE` e garantir que `alunos` use InnoDB. Se o ambiente local estiver usando outro mecanismo, converter a tabela preservando os dados antes de criar as relações.

**`planos`**

| Campo | Tipo sugerido | Regra |
|---|---|---|
| `id` | `INT UNSIGNED` | PK, auto incremento. |
| `nome` | `VARCHAR(100)` | Obrigatório. |
| `descricao` | `VARCHAR(255)` | Opcional. |
| `duracao_valor` | `SMALLINT UNSIGNED` | Nulo enquanto rascunho incompleto; maior que zero para ativação. |
| `duracao_unidade` | `ENUM('DIA','SEMANA','MES')` | Nulo enquanto rascunho incompleto. |
| `valor_atual` | `DECIMAL(10,2)` | Nulo enquanto rascunho; maior que zero para ativação. |
| `situacao` | `ENUM('RASCUNHO','ATIVO','INATIVO')` | Obrigatório, padrão `RASCUNHO`. |
| `criado_em`, `atualizado_em` | `DATETIME` | Metadados gerados pelo banco, na mesma convenção dos registros existentes; não usados em cálculos de vigência. |

**`matriculas`**

| Campo | Tipo sugerido | Regra |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK, compatível com `auditoria.entidade_id`. |
| `aluno_id` | `INT UNSIGNED` | FK obrigatória para `alunos.id`. |
| `plano_id` | `INT UNSIGNED` | FK para `planos.id`; nulo somente em legado sem correspondência segura. |
| `plano_nome_contratado` | `VARCHAR(100)` | Cópia do nome na contratação; nulo somente se desconhecido em legado. |
| `valor_contratado` | `DECIMAL(10,2)` | Cópia do preço; nulo somente se desconhecido em legado. |
| `duracao_contratada_valor` | `SMALLINT UNSIGNED` | Cópia da duração; nulo somente se desconhecido em legado. |
| `duracao_contratada_unidade` | `ENUM('DIA','SEMANA','MES')` | Cópia da unidade; nulo somente se desconhecida em legado. |
| `inicio_em`, `fim_exclusivo_em` | `DATETIME` | Obrigatórios em UTC; `fim_exclusivo_em > inicio_em`. |
| `dia_referencia_calendario` | `TINYINT UNSIGNED` | 1–31 para mês; nulo para dia/semana ou quando não comprovado no legado. |
| `cancelada_em` | `DATETIME` | Nulo enquanto não cancelada; instante UTC do cancelamento. |
| `origem` | `ENUM('SISTEMA','LEGADO')` | Obrigatório, padrão `SISTEMA`. |
| `motivo_cancelamento` | `VARCHAR(255)` | Opcional. |
| `criado_em` | `DATETIME` | Metadado de criação, na convenção existente da aplicação/banco. |

**`revisoes_legado`**

| Campo | Tipo sugerido | Regra |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK, auto incremento. |
| `aluno_id_origem` | `INT UNSIGNED` | FK para `alunos.id`, obrigatório e UNIQUE. |
| `plano_texto_original` | `VARCHAR(40)` | Cópia exata do campo de origem. |
| `resultado_atual` | `ENUM('VINCULO_CONFIRMADO','LEGADO_SEM_VIGENCIA','PENDENTE_REVISAO')` | Obrigatório, padrão `PENDENTE_REVISAO`. |
| `fonte_referencia` | `VARCHAR(255)` | Fonte da última decisão; nula antes da primeira revisão. |
| `criado_em`, `atualizado_em` | `DATETIME` | Metadados do controle, na convenção existente da aplicação/banco. |

**`revisoes_legado_eventos`**

| Campo | Tipo sugerido | Regra |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK, auto incremento. |
| `revisao_legado_id` | `BIGINT UNSIGNED` | FK obrigatória para `revisoes_legado.id`. |
| `usuario_id` | `INT UNSIGNED` | FK para `usuarios.id`; obrigatório em todo evento humano. |
| `tipo_evento` | `ENUM('DECISAO','RETIFICACAO_VIGENCIA')` | Distingue uma decisão sobre o legado de uma correção do intervalo já confirmado. |
| `resultado_anterior` | Mesmo ENUM dos resultados | Obrigatório; a revisão inicial já nasce como `PENDENTE_REVISAO`, portanto toda decisão humana tem um resultado anterior. |
| `resultado_novo` | Mesmo ENUM dos resultados | Obrigatório. Na retificação de vigência, permanece `VINCULO_CONFIRMADO`. |
| `matricula_id` | `BIGINT UNSIGNED` | FK opcional para `matriculas.id`; obrigatório em `RETIFICACAO_VIGENCIA` e quando uma decisão cria/associa uma matrícula legada. |
| `inicio_anterior`, `fim_exclusivo_anterior` | `DATETIME` | Nulos em decisões comuns; obrigatórios em retificação de vigência. Valores UTC antes da correção. |
| `inicio_novo`, `fim_exclusivo_novo` | `DATETIME` | Nulos em decisões comuns; obrigatórios em retificação de vigência. Valores UTC após a correção. |
| `fonte_referencia` | `VARCHAR(255)` | Referência da evidência que sustenta a decisão ou retificação; obrigatória em `VINCULO_CONFIRMADO`, `LEGADO_SEM_VIGENCIA` e `RETIFICACAO_VIGENCIA`. |
| `observacoes` | `TEXT` | Opcional em decisões comuns; obrigatória em `PENDENTE_REVISAO` e em retificações, para registrar a insuficiência ou a justificativa da correção. |
| `criado_em` | `DATETIME` | Metadado do evento, gerado na mesma convenção de `auditoria.criado_em`; não é usado para calcular vigência. |

Índices mínimos:
- `planos(situacao, nome)`, para listar opções disponíveis.
- `matriculas(aluno_id, inicio_em, fim_exclusivo_em)`, para histórico e sobreposição por aluno.
- `matriculas(plano_id)`, para verificar vínculos antes de excluir/desativar plano.
- `revisoes_legado(aluno_id_origem)` UNIQUE.
- `revisoes_legado_eventos(revisao_legado_id, criado_em)`, para a linha do tempo.
- `revisoes_legado_eventos(matricula_id, criado_em)`, para consultar retificações de uma matrícula legada.

Usar FKs com `ON DELETE RESTRICT` nas relações históricas. Não usar exclusão em cascata para apagar contratos ou auditoria. O aluno já é inativado logicamente; plano só pode ser excluído sem matrícula vinculada, caso contrário fica inativo.

Preço positivo, duração positiva, consistência entre duração/unidade e `fim_exclusivo_em > inicio_em` são validados no PHP. Usar `CHECK` no banco somente se a versão instalada garantir seu enforcement. Não adicionar UNIQUE ao nome do plano sem necessidade demonstrada.

**Simplificação para o portfólio:** não criar tabela genérica de eventos de matrícula nem framework de auditoria próprio. Usar `auditoria` para criação/edição/cancelamento comuns de planos e matrículas. Manter `revisoes_legado_eventos` porque esse fluxo precisa registrar resultado anterior/novo, fonte e observações.

Códigos de ação sugeridos para a auditoria geral: `plano_criado`, `plano_editado`, `plano_ativado`, `plano_inativado`, `matricula_criada`, `matricula_cancelada` e `matricula_corrigida`. O nome da entidade deve identificar `planos` ou `matriculas`; o `entidade_id` recebe o ID correspondente. Cada evento deve ser gravado na mesma transação da alteração que descreve.

## 5. Regras de negócio propostas

### 5.1 Preço e duração

- O preço do catálogo é uma referência para novas contratações.
- Um plano só pode sair de rascunho e ficar disponível para contratação quando tiver preço válido maior que zero e todos os dados obrigatórios preenchidos.
- Planos com preço nulo, zero ou dados obrigatórios incompletos não podem receber novas matrículas.
- O valor contratado fica registrado na matrícula.
- Duração também deve ser copiada para a matrícula, para que uma edição posterior do plano não modifique contratos históricos.
- A matrícula deve preservar o dia de referência do calendário para calcular os limites de períodos mensais e renovações contínuas sem deriva após meses curtos.
- Para dinheiro, usar `DECIMAL` em vez de `FLOAT` ou `DOUBLE`. `DECIMAL(10,2)` é uma candidata inicial, sujeita à validação da faixa de valores necessária ao projeto.
- Não presumir que todo mês possui 30 dias. A unidade de duração deve ser explícita.

### 5.2 Datas e representação temporal

**Decisão aprovada:** para durações mensais, o último dia de calendário calculado é integralmente incluído, mesmo quando a matrícula começa em horário intradiário. A vigência termina às 00:00 locais do dia seguinte ao último dia incluído (`fim_exclusivo_em`). Assim, um início em 10/01 às 15:00 com duração de um mês cobre todo o dia 10/02 e termina em 11/02 às 00:00. Um cancelamento pode encerrar a vigência antes desse limite, no instante exato registrado. Quando o limite efetivo de uma duração em dias/semanas ou de um cancelamento ocorrer em horário intradiário, a interface deverá mostrar o instante exato, sem sugerir que o dia inteiro está coberto.

Para suportar cancelamento e nova matrícula no mesmo dia, a recomendação técnica aprovada é armazenar os limites de vigência como instantes:

- `inicio_em DATETIME`: instante em que a matrícula passa a valer.
- `fim_exclusivo_em DATETIME`: instante em que deixa de valer. Esse instante não faz parte do período.
- `cancelada_em DATETIME`: instante efetivo do cancelamento, quando houver.

O intervalo efetivo será tratado como semiaberto: `[inicio_em, fim_exclusivo_em)`. Se houver cancelamento depois do início, o limite efetivo será o menor entre `fim_exclusivo_em` e `cancelada_em`. **Se `cancelada_em` for anterior ou igual a `inicio_em`, a matrícula terá intervalo efetivo vazio:** não houve vigência utilizada e ela não bloqueará nenhum outro período. Assim, uma matrícula pode terminar às 14:00 e a seguinte começar exatamente às 14:00, sem sobreposição. A data final inclusiva exibida na interface será derivada do limite exclusivo da vigência normal; matrículas canceladas também exibirão o instante exato do cancelamento.

Regra de calendário aprovada para durações em meses:

- Tratar mês como unidade de calendário; nunca converter um mês em 30 dias.
- `dia_referencia_calendario` guarda o dia original e nunca é substituído pelo dia ajustado de um mês curto. Em uma contratação independente, recebe o dia local de início; na renovação contínua, copia o valor anterior.
- Para calcular `N` meses, identificar o primeiro mês de ciclo: usar o próprio mês local de início quando o dia de referência ainda estiver adiante do dia local de início; caso contrário, usar o mês seguinte. A partir desse mês, avançar `N - 1` meses de calendário. No mês-alvo, a data final inclusiva é o menor valor entre o dia de referência e o último dia válido do mês.
- O último dia inclusivo é coberto integralmente. Portanto, `fim_exclusivo_em` será sempre 00:00 local do dia seguinte à data final inclusiva, independentemente do horário em que a matrícula começou. Exemplo: início em 10/01 às 15:00, duração de um mês e referência 10 resulta em data final inclusiva 10/02 e limite exclusivo 11/02 às 00:00.
- Se o mês-alvo não possuir o dia de referência (por exemplo, dia 29, 30 ou 31 em fevereiro), ajustar a data final inclusiva para o último dia válido do mês. A referência original permanece inalterada para os ciclos seguintes.
- Para durações de vários meses, avançar a partir do primeiro mês de ciclo e aplicar a referência original em cada mês, sem propagar o dia ajustado de um mês curto. Exemplo: início em 31/01 com duração de dois meses conta 28/02 e 31/03 como os dois dias finais inclusivos, terminando em 01/04 às 00:00.
- Não confiar em `DateTime::modify('+N months')` sem normalização explícita: o PHP documenta que somas a partir de 31 de janeiro podem avançar para março por overflow ([DateTime::modify](https://www.php.net/manual/pt_BR/datetime.modify.php), [exemplos de aritmética de datas](https://www.php.net/manual/pt_BR/datetime.examples-arithmetic.php)).
- Renovações contínuas começam exatamente no limite exclusivo anterior e preservam `dia_referencia_calendario`. Uma nova contratação independente começa sua própria sequência com o dia da data inicial.
- A renovação automática contínua não pode criar início retroativo: se `fim_exclusivo_em` já estiver no passado quando a operação for solicitada, a matrícula anterior já terminou e a nova matrícula deve seguir a regra de início hoje ou em data futura.
- Toda a aritmética de calendário acontece no fuso `America/Sao_Paulo`; somente depois de calcular o limite local ele é convertido para UTC para persistência.

Exemplos para matrículas iniciadas à meia-noite:

| Início / dia de referência | Próximo limite exclusivo | Último dia incluído |
|---|---|---|
| 01/11, referência 1, duração de 1 mês | 02/12 às 00:00 | 01/12 |
| 31/01, referência 31, duração de 1 mês, ano não bissexto | 01/03 às 00:00 | 28/02 |
| 31/01, referência 31, duração de 1 mês, ano bissexto | 01/03 às 00:00 | 29/02 |
| Renovação contínua em 01/03, preservando referência 31, duração de 1 mês | 01/04 às 00:00 | 31/03 |
| 10/01 às 15:00, referência 10, duração de 1 mês | 11/02 às 00:00 | 10/02 |

Para durações mensais, o início intradiário não desloca o limite mensal para o mesmo horário: o último dia de calendário é coberto até o fim e o limite exclusivo ocorre à meia-noite local seguinte. Para durações em dias/semanas, quando o cálculo resultar em limite intradiário, a interface mostra a data e a hora exatas. Em cancelamento intradiário, o limite efetivo passa a ser o próprio `cancelada_em`, por exemplo 21/11 às 14:00. Uma nova matrícula pode começar nesse mesmo instante ou depois, nunca antes.

**Decisão aprovada — durações em dias e semanas:** tratar as unidades como dias/semanas de calendário no fuso `America/Sao_Paulo`, preservando a hora local do início e calculando um limite exclusivo. Um dia corresponde à mesma hora local no dia de calendário seguinte; uma semana corresponde à mesma hora local sete dias de calendário depois. Exemplos: início em 10/01 às 15:00 + 1 dia = limite exclusivo em 11/01 às 15:00; + 1 semana (ou 7 dias) = 17/01 às 15:00. Não converter dias em 86.400 segundos nem semanas em segundos fixos; a transição entre horários civis deve ser calculada com o fuso IANA. Quando o limite for intradiário, exibir o instante exato.

- Usar `DATETIME` para os instantes de vigência e de cancelamento.
- **Decisão aprovada — fuso oficial:** usar o identificador IANA `America/Sao_Paulo` como fuso oficial da academia, centralizado na configuração da aplicação.
- **Decisão aprovada — persistência de instantes:** manter `inicio_em`, `fim_exclusivo_em` e `cancelada_em` como `DATETIME`, armazenando neles valores UTC por convenção explícita. O tipo `DATETIME` não realiza conversão automática de fuso; essa conversão deve ser feita na aplicação. Diferentemente, o MySQL converte `TIMESTAMP` entre o fuso da sessão e UTC, por isso não misturar as duas convenções nesses campos ([documentação MySQL sobre tipos temporais](https://dev.mysql.com/doc/refman/8.0/en/datetime.html) e [suporte a fusos horários](https://dev.mysql.com/doc/refman/8.0/en/time-zone-support.html)).
- Interpretar datas e horários informados pelos funcionários no fuso `America/Sao_Paulo`. Fazer cálculos de calendário (dias, semanas, meses e limites de meia-noite) nesse fuso local; depois converter o instante resultante para UTC antes de persistir.
- Ao consultar, interpretar os valores armazenados como UTC e convertê-los para `America/Sao_Paulo` antes de apresentar ou aplicar regras de calendário.
- Os instantes contratuais (`inicio_em`, `fim_exclusivo_em` e `cancelada_em`) são os únicos campos deste modelo que seguem obrigatoriamente UTC. Os metadados administrativos `criado_em`/`atualizado_em` e os horários da auditoria geral seguem a convenção já existente do banco, gerada por `CURRENT_TIMESTAMP`; não são usados para calcular vigência. Não reinterpretar registros antigos como UTC sem uma estratégia própria.
- Usar objetos de data/hora com fuso explícito na aplicação. Não depender do fuso padrão do servidor, do dispositivo do funcionário ou de conversões implícitas da sessão do banco.
- Não usar uma data fictícia para preencher informações desconhecidas.
- Testar início mensal intradiário (por exemplo, 10/01 às 15:00 + 1 mês = data final 10/02 e limite exclusivo 11/02 às 00:00), virada de mês, fevereiro, ano bissexto, duração mensal de vários meses, horário do cancelamento, início no mesmo instante do encerramento anterior e sequências de renovações.
- Centralizar o cálculo em uma função de domínio, coberta por testes determinísticos; não espalhar a regra entre telas ou consultas.

### 5.3 Renovação e histórico

**Decisão aprovada para renovação contínua antecipada:** a nova matrícula começa exatamente no `fim_exclusivo_em` da matrícula anterior. Para ciclos mensais, esse limite corresponde às 00:00 do dia seguinte ao último dia inclusivo; para ciclos em dias/semanas, pode preservar o horário intradiário. A renovação não consome dias já contratados nem cria sobreposição com a matrícula anterior.

Regras:

- Renovar significa criar uma nova matrícula, não sobrescrever a anterior.
- O histórico deve manter o plano, o preço, a duração e o período de cada contratação.
- Exemplo mensal: se o último dia incluído for 30/11, o limite exclusivo será 01/12 às 00:00 e a renovação contínua começa nesse mesmo instante.
- O sistema deve calcular a data de início da renovação a partir do limite seguinte do período e do dia de referência preservado, sem depender do dia em que o funcionário realizou a operação.
- A renovação contínua copia o dia de referência da matrícula anterior, inclusive quando a data anterior foi ajustada por fevereiro ou outro mês curto.
- Essa regra cobre a renovação contínua padrão, sem intervalo.
- **Decisão aprovada:** o funcionário também pode escolher manualmente uma data de início para uma nova matrícula, criando um intervalo entre contratos quando necessário. O início efetivo deve ser um instante igual ou posterior ao momento atual no fuso da academia e não pode provocar sobreposição com qualquer outra matrícula não cancelada do mesmo aluno.
- **Decisão aprovada sobre horário de início:** quando o funcionário informar apenas uma data futura, a matrícula começa às 00:00 dessa data, no fuso horário da academia.
- Quando a data escolhida for hoje, o horário padrão é o instante atual da operação. O funcionário pode escolher um horário posterior no mesmo dia, mas nunca um horário anterior ao momento atual.
- Se o funcionário informar data e horário futuros, o instante completo deve ser validado contra o momento atual e contra os períodos de outras matrículas não canceladas.
- Renovações contínuas não usam esse padrão de agendamento manual: começam automaticamente exatamente no limite exclusivo da matrícula anterior. Em durações mensais, isso corresponde à meia-noite seguinte ao último dia incluído; em dias/semanas, pode manter a hora do início.
- O intervalo é permitido, não obrigatório. A renovação contínua começa no limite exclusivo da matrícula anterior, sem deslocamento adicional de um dia.
- Uma matrícula futura pode ocupar qualquer intervalo livre entre períodos já agendados, desde que não se sobreponha a eles; não é obrigatório colocá-la sempre depois da última matrícula agendada.
- A nova matrícula continua sujeita à regra aprovada de não sobreposição de períodos.

### 5.4 Cancelamento e inativação

**Decisão aprovada:** o cancelamento tem efeito imediato no momento em que é confirmado pelo sistema. A matrícula permanece no histórico e não é apagada.

Regras aprovadas:

- O sistema registra o instante efetivo do cancelamento em `cancelada_em`, com precisão de data e horário (`DATETIME`).
- O evento deve identificar o funcionário responsável pela operação, usando o mecanismo de auditoria já existente no projeto.
- O motivo do cancelamento é opcional; sua ausência não impede a conclusão da operação.
- Depois do cancelamento efetivo, a matrícula deixa de representar uma vigência ativa.
- **Decisão aprovada — elegibilidade do cancelamento:** somente matrículas `AGENDADA` ou `ATIVA` podem ser canceladas. O serviço deve recalcular a situação no instante da confirmação, dentro da transação e sob o bloqueio da linha do aluno, para evitar que uma matrícula vença entre a exibição da tela e o envio da operação.
- Uma matrícula `AGENDADA` pode ser cancelada antes de `inicio_em`; o intervalo efetivo permanece vazio e o registro passa a ser apresentado como `CANCELADA`.
- Uma matrícula `ATIVA` pode ser cancelada; `cancelada_em` recebe o instante atual da confirmação e encerra imediatamente a vigência.
- Uma matrícula `VENCIDA` não pode ser cancelada. Seu encerramento já ocorreu naturalmente em `fim_exclusivo_em`; não preencher `cancelada_em` nem criar evento que represente um cancelamento inexistente.
- Uma matrícula já `CANCELADA` não pode ser cancelada novamente. Repetições da solicitação não devem criar eventos de cancelamento duplicados.
- **Correção administrativa de registro vencido:** quando houver erro nos dados de uma matrícula vencida, a correção deve ser uma operação separada, com trilha de auditoria que registre a alteração e sua justificativa. Ela não deve preencher `cancelada_em` nem reclassificar o vencimento natural como cancelamento.
- **Decisão aprovada — cancelamento antes do início:** se `cancelada_em` for anterior a `inicio_em`, a matrícula permanece no histórico como `CANCELADA`, mas seu intervalo efetivo é vazio. Ela nunca chegou a produzir vigência utilizada e não bloqueia uma nova matrícula.
- Se o cancelamento ocorrer exatamente em `inicio_em`, o intervalo efetivo também será vazio: a matrícula é mantida como cancelada, sem período utilizado.
- Se o cancelamento ocorrer depois de `inicio_em`, a vigência efetiva termina no próprio instante de cancelamento ou em `fim_exclusivo_em`, o que ocorrer primeiro.
- **Decisão aprovada:** uma nova matrícula pode começar no mesmo instante do cancelamento ou depois, desde que não se sobreponha a qualquer período efetivamente utilizado. Quando o cancelamento ocorrer antes ou exatamente no início, não existe período utilizado a bloquear.
- O início e o fim precisam ser comparados como instantes (`DATETIME`), não apenas como datas, para permitir essa transição sem sobreposição.
- A consulta histórica deve continuar exibindo a matrícula cancelada, seu período originalmente contratado, o momento efetivo do cancelamento e o responsável, inclusive quando foi cancelada antes de começar.
- Cancelar uma matrícula não apaga nem altera os dados cadastrais do aluno.
- Inativar o cadastro do aluno não cancela automaticamente suas matrículas; são ações diferentes e auditadas separadamente.

Trancamento, suspensão e congelamento ficam fora da primeira versão: exigem regras próprias para acesso, prazo, extensão de vigência e eventual cobrança.

### 5.5 Uma ou várias matrículas simultâneas

**Decisão aprovada:** um aluno pode possuir várias matrículas no histórico, mas não pode ter duas matrículas não canceladas com períodos de vigência sobrepostos na primeira versão.

Regras aprovadas:

- Permitir que o funcionário escolha um instante de início igual ou posterior ao momento atual no fuso da academia; quando a interface coletar somente a data futura, aplicar 00:00, e quando coletar somente a data de hoje, aplicar o instante atual, conforme a decisão aprovada na seção 5.3.
- Permitir intervalos livres entre contratos; a data escolhida pode ocupar um espaço entre matrículas já agendadas, desde que não haja sobreposição.
- Não permitir que o período de uma nova matrícula se sobreponha ao de qualquer outra matrícula não cancelada do mesmo aluno, seja vigente ou futura.
- A regra de renovação contínua permanece automática: início exatamente no `fim_exclusivo_em` da matrícula anterior, salvo quando o funcionário optar por agendar outro início futuro válido. Para durações mensais, isso ocorre à meia-noite após o último dia incluído; para dias/semanas, preserva o horário intradiário calculado.
- Preservar matrículas anteriores no histórico; renovar não significa editar ou substituir o registro anterior.
- Matrículas canceladas permanecem no histórico e não devem bloquear novos períodos depois de efetivamente canceladas.
- Enquanto existir revisão de legado com resultado `PENDENTE_REVISAO` para o aluno, nenhuma nova matrícula pode ser criada, mesmo que o sistema não consiga determinar a vigência do vínculo antigo. Esse resultado pertence ao registro de revisão legado, não à situação temporal da matrícula operacional. A liberação só ocorre após conclusão auditada como `VINCULO_CONFIRMADO` com intervalo efetivo comprovado e registrado, ou `LEGADO_SEM_VIGENCIA` com evidência confiável de ausência de contrato vigente.

A validação deve ocorrer no servidor e ser protegida contra solicitações concorrentes. Uma consulta simples antes do INSERT não é suficiente. Estratégia preferida: dentro de uma transação InnoDB, bloquear primeiro a linha do aluno com `SELECT ... FOR UPDATE`; em seguida, bloquear o plano com `SELECT ... FOR UPDATE`, verificar o estado da revisão de legado, consultar sobreposições e inserir matrícula/auditoria antes do `COMMIT`. Todas as operações que alterem matrículas ou a revisão de legado do aluno devem começar bloqueando a mesma linha-pai do aluno. Isso serializa as mudanças por aluno mesmo quando ainda não existe matrícula correspondente para bloquear. A exclusão/desativação de plano deve bloquear a linha do próprio plano e verificar os vínculos na mesma transação. Manter uma ordem de bloqueio consistente (aluno, plano, revisão) nas operações que toquem mais de uma entidade e tratar deadlocks/lock timeouts com rollback e mensagem segura. A documentação do InnoDB explica locking reads e a importância da ordem de aquisição de locks ([Locking Reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html), [Internal Locking Methods](https://dev.mysql.com/doc/refman/8.0/en/internal-locking.html)).

Com intervalos semiabertos, dois períodos não vazios se sobrepõem quando `inicio_A < fim_efetivo_B` e `inicio_B < fim_efetivo_A`. Uma matrícula cancelada em instante anterior ou igual ao início tem intervalo efetivo vazio e deve ser excluída da verificação de sobreposição, sem ser apagada do histórico. Se o início do novo contrato for exatamente igual ao limite efetivo do anterior, os períodos são adjacentes e válidos, não sobrepostos.

## 6. Migração dos alunos existentes

A migração é o ponto de maior risco do bloco, porque os registros atuais guardam apenas o texto de `alunos.plano`.

**Decisão aprovada para alunos legados:** a revisão de cada vínculo antigo deve produzir um de três resultados explícitos. Os registros originais permanecem consultáveis, e o texto de `alunos.plano` nunca é sobrescrito.

1. **Vínculo confirmado (`VINCULO_CONFIRMADO`):** o funcionário registra as condições antigas que conseguiu comprovar, incluindo plano, valor e período quando houver evidência confiável. Os dados desconhecidos continuam nulos; uma matrícula operacional só pode ser criada/liberada quando os dados necessários para determinar sua vigência estiverem comprovados. A revisão não autoriza estimativas.
2. **Sem matrícula vigente (`LEGADO_SEM_VIGENCIA`):** o funcionário confirma, com uma fonte confiável, que o aluno não possui contrato vigente. O registro legado é encerrado para fins operacionais e deixa de bloquear novas matrículas. Isso não cria uma matrícula histórica fictícia nem exige preencher preço, datas ou duração desconhecidos.
3. **Não foi possível confirmar (`PENDENTE_REVISAO`):** quando não houver evidência suficiente para concluir uma das duas situações anteriores, o vínculo continua pendente e novas matrículas permanecem bloqueadas.

Em qualquer resultado, a revisão deve registrar o funcionário responsável, o instante da decisão, o resultado, uma referência da fonte consultada e observações necessárias à rastreabilidade. A ausência de informação ou a simples existência de texto em `alunos.plano` não comprova, por si só, que exista ou não exista uma matrícula vigente. Sem fonte confiável, o resultado obrigatório é `PENDENTE_REVISAO`.

`VINCULO_CONFIRMADO` só libera o fluxo de novas matrículas quando a evidência também permite determinar o intervalo efetivo exato do contrato legado. Se não for possível comprovar `inicio_em` e o limite de término contratual/efetivo, o caso permanece `PENDENTE_REVISAO`; confirmar apenas que houve um contrato não é suficiente para liberar o aluno. Quando o intervalo estiver comprovado, a matrícula legada deve ser registrada para que a verificação de sobreposição considere a vigência real. Preço, duração original ou correspondência com o catálogo podem permanecer nulos quando não houver evidência; a vigência temporal, porém, não pode ser indeterminada.

Estratégia de migração e regularização:

**Sequência simples sugerida (arquivos ainda não criados):**

- `005_plans_enrollments_schema.sql`: DDL para `planos`, `matriculas`, `revisoes_legado` e `revisoes_legado_eventos`, índices e FKs.
- `006_import_legacy_review.sql`: somente carga DML do conjunto inicial de alunos para `revisoes_legado` e, se adotado, o evento geral de importação. Executar a carga em transação.
- Manter as migrations 001–004 intactas; README e CI devem passar a executar 005 e 006 depois da 004 quando forem implementadas.

O DDL do MySQL/MariaDB pode confirmar implicitamente; após 005, conferir as tabelas com `SHOW CREATE TABLE` e metadados. Se falhar parcialmente, inspecionar o que foi criado e corrigir manualmente antes de continuar. Não criar runner genérico para esse cenário neste portfólio.

1. Preservar integralmente a coluna `alunos.plano` durante a transição.
2. Criar as novas estruturas sem exigir que dados históricos desconhecidos sejam preenchidos.
3. Para cada aluno que compõe o conjunto inicial da migração, criar exatamente um registro em `revisoes_legado`, copiando o valor original de `alunos.plano` para `plano_texto_original` sem alterar a coluna de origem.
4. Criar a chave estrangeira de `aluno_id_origem` e a restrição `UNIQUE`. Essa chave é a identidade estável do registro migrado: uma segunda execução não pode inserir outro controle para o mesmo aluno.
5. Inicializar `resultado_atual` como `PENDENTE_REVISAO`; registrar a criação como evento de migração, não como uma decisão humana.
6. Não criar uma matrícula operacional para cada aluno apenas por ele existir na tabela antiga. Uma matrícula só nasce quando uma contratação e os dados necessários para determinar sua vigência forem comprovados.
7. Manter preço, início, término e duração desconhecidos como `NULL`; nunca inferir preço, datas ou vigência. Não criar planos de catálogo com dados inventados.
8. Disponibilizar o fluxo de revisão com os três resultados descritos acima. Para `VINCULO_CONFIRMADO`, registrar somente os dados sustentados pela evidência e criar uma matrícula operacional apenas quando as condições necessárias para determinar sua vigência estiverem comprovadas.
9. Para `LEGADO_SEM_VIGENCIA`, manter o registro de revisão consultável, sem criar matrícula operacional fictícia; liberar novas matrículas após a confirmação auditada.
10. Para `PENDENTE_REVISAO`, manter o bloqueio de novas matrículas até que uma revisão posterior baseada em evidências permita concluir o caso.
11. Executar a carga de dados em transação. Se qualquer etapa falhar, desfazer a transação; se a execução for repetida, identificar a colisão especificamente como duplicidade da chave única e preservar o resultado, a fonte e a auditoria já existentes. Outros erros não devem ser mascarados como duplicidade.
12. Tratar a carga como migration SQL numerada e de execução única sobre o conjunto inicial de alunos. Não executar essa rotina como varredura recorrente de todos os alunos, pois alunos criados posteriormente seguem o fluxo normal e não são legados da carga inicial.
13. Depois da migração, comparar o conjunto de IDs de alunos da origem com as referências em `revisoes_legado`, conferir a quantidade de registros, verificar os textos originais, confirmar ausência de duplicações e executar novamente a rotina em teste para provar que nenhum registro ou decisão existente é recriado ou sobrescrito.

A coluna antiga será mantida durante a transição. Sua remoção só poderá ser considerada em uma migration posterior, depois de todos os leitores e escritores terem sido atualizados e a preservação dos dados ter sido verificada.

## 7. Integridade, transações e segurança

- Usar InnoDB nas tabelas envolvidas, para que operações relacionadas possam ser transacionais.
- Criar FKs para aluno, plano, revisão e funcionário, usando `ON DELETE RESTRICT` nas relações históricas; não usar exclusão em cascata para matrículas, revisões ou auditoria.
- Usar `CHECK` para condições simples quando a versão efetivamente instalada suportar e aplicar a restrição. No MySQL, restrições `CHECK` passaram a ser aplicadas a partir da versão 8.0.16; versões anteriores apenas as analisavam e ignoravam.
- Repetir as validações relevantes na aplicação PHP; a validação do navegador não é uma barreira de segurança.
- Usar consultas preparadas via PDO, autenticação, autorização e CSRF nos endpoints de escrita.
- Criar ou renovar uma matrícula em transação; confirmar todas as alterações com `COMMIT` ou desfazer com `ROLLBACK` se qualquer etapa falhar.
- Avaliar `SELECT ... FOR UPDATE` para proteger verificações concorrentes, escolhendo índices e linhas de bloqueio de acordo com a regra final.
- Não confiar somente em validação de interface para impedir matrícula duplicada, plano desativado ou aluno inativo.
- Não introduzir gateway, cobrança automática ou armazenamento de dados de pagamento neste bloco.

## 8. Escopo aprovado

### Dentro do Bloco 03

- Modelagem e migration do catálogo e das matrículas.
- Migração preservadora do histórico textual existente.
- Cadastro, edição e desativação de planos.
- Criação de matrícula com condições contratadas registradas.
- Consulta do histórico de matrículas no perfil do aluno.
- Renovação sem sobrescrever o contrato anterior.
- Identificação de matrículas agendadas, vigentes, vencidas, canceladas ou pendentes de revisão.
- Testes de migração, integridade, concorrência, segurança e regressão.
- Documentação do modelo, instalação e critérios de validação.

### Fora do Bloco 03

- Registro de pagamentos e baixas financeiras.
- Cobrança recorrente, inadimplência e integração com gateways.
- Trancamento, suspensão e congelamento ficam expressamente fora da primeira versão do Bloco 03.
- Check-in, frequência, QR Code ou controle de acesso físico.
- Múltiplas modalidades/contratos simultâneos, salvo decisão explícita que amplie o escopo.

## 9. Plano de validação

- [ ] A migration funciona em banco limpo e em banco com alunos legados.
- [ ] Nenhum aluno, ID ou texto original de plano é perdido.
- [ ] A carga inicial cria exatamente um registro em `revisoes_legado` para cada aluno do conjunto inicial, com `aluno_id_origem` único e chave estrangeira válida.
- [ ] Reexecutar a carga não duplica registros, não altera resultados humanos já registrados, não sobrescreve referências de fonte nem duplica eventos de auditoria.
- [ ] Uma colisão na chave única é tratada como registro já migrado; erros diferentes de duplicidade causam rollback e não são ignorados.
- [ ] Alunos criados depois da migration inicial não são incluídos retroativamente pela reexecução da carga legada.
- [ ] Planos em rascunho ou inativos não aparecem para novas matrículas.
- [ ] Plano sem matrículas vinculadas pode ser excluído.
- [ ] Plano com pelo menos uma matrícula vinculada não pode ser excluído fisicamente e pode ser desativado.
- [ ] Plano desativado continua identificado e consultável no histórico das matrículas existentes.
- [ ] Operações concorrentes não permitem excluir um plano enquanto uma matrícula vinculada está sendo criada.
- [ ] Plano incompleto pode ser salvo como rascunho, mas não pode ser ativado nem contratado.
- [ ] Plano com preço nulo ou igual a zero não pode ser ativado nem contratado.
- [ ] Plano só pode ser ativado quando todos os campos obrigatórios estiverem preenchidos e o preço for maior que zero.
- [ ] Alterar o preço de um plano não altera matrículas antigas.
- [ ] Aluno inativo não recebe nova matrícula.
- [ ] Aluno com vínculo legado pendente de revisão continua consultável, mas não consegue receber nova matrícula.
- [ ] Revisão de legado permite concluir somente um dos três resultados: `VINCULO_CONFIRMADO`, `LEGADO_SEM_VIGENCIA` ou `PENDENTE_REVISAO`.
- [ ] `VINCULO_CONFIRMADO` exige evidência confiável, registra apenas dados comprovados e só libera matrícula operacional quando a vigência puder ser determinada.
- [ ] `LEGADO_SEM_VIGENCIA` exige evidência confiável de ausência de contrato vigente, libera nova matrícula e não inventa datas, preço, duração ou matrícula histórica.
- [ ] `PENDENTE_REVISAO` permanece como resultado da revisão legada quando não há evidência suficiente e continua bloqueando novas matrículas; não é tratado como estado temporal de matrícula operacional.
- [ ] Toda decisão humana grava evento append-only em `revisoes_legado_eventos`, com funcionário, instante, tipo, resultado anterior/novo, referência da fonte e observações.
- [ ] Uma retificação de vigência registra `matricula_id`, início/fim anteriores e início/fim novos, com fonte e justificativa; o evento anterior nunca é sobrescrito.
- [ ] A retificação só altera intervalos de matrículas `LEGADO` e exige resultado anterior/novo `VINCULO_CONFIRMADO`; não altera preço ou duração não comprovados.
- [ ] A retificação valida sobreposição e consistência temporal dentro da transação, sem comparar a matrícula contra ela própria.
- [ ] Uma evidência insuficiente após confirmação move a revisão para `PENDENTE_REVISAO`, sinaliza o registro anterior como sob revisão e bloqueia novas matrículas; não apaga nem cancela o registro.
- [ ] Uma tentativa de repetir exatamente a mesma decisão/retificação sem dado novo não duplica evento nem auditoria.
- [ ] Atualização do resultado atual, evento especializado e evento geral em `auditoria` ocorrem na mesma transação.
- [ ] A criação inicial automática como `PENDENTE_REVISAO` não inventa funcionário nem cria falsa decisão humana.
- [ ] A revisão nunca sobrescreve o texto original importado de `alunos.plano`.
- [ ] Matrícula nova exige todos os dados obrigatórios e copia nome, preço e duração do plano no instante da contratação.
- [ ] Matrículas históricas permanecem consultáveis mesmo se o aluno ou plano for posteriormente inativado.
- [ ] Datas inválidas e valores negativos são rejeitados no servidor e, quando suportado, pelo banco.
- [ ] Data final é inclusiva: uma matrícula de 01/11 até 30/11 permanece válida durante todo o dia 30/11.
- [ ] Duração mensal iniciada nos dias 29, 30 e 31 é calculada pela sequência de aniversários de calendário, com ajuste do alvo inexistente ao último dia do mês e inclusão desse dia.
- [ ] Duração mensal de dois meses iniciada em 31/01 inclui 28/02 e 31/03, com limite exclusivo em 01/04 às 00:00.
- [ ] Um legado só libera nova matrícula como `VINCULO_CONFIRMADO` quando o intervalo efetivo exato foi comprovado e registrado; confirmação genérica da existência de contrato mantém o bloqueio.
- [ ] Início em 31/01 em ano não bissexto gera limite exclusivo 01/03 00:00 e inclui 28/02; em ano bissexto gera limite exclusivo 01/03 00:00 e inclui 29/02.
- [ ] Renovação contínua em 01/03 preservando referência 31 inclui 31/03 e calcula o limite exclusivo em 01/04 às 00:00.
- [ ] Durações de vários meses repetem o algoritmo de ciclos, sem overflow de `DateTime::modify('+N months')`.
- [ ] Limites intradiários são exibidos com data e hora, sem apresentar uma data final inclusiva enganosa.
- [ ] Duração de 1 dia iniciada em 10/01 às 15:00 produz limite exclusivo em 11/01 às 15:00 no fuso local.
- [ ] Duração de 1 semana (ou 7 dias) iniciada em 10/01 às 15:00 produz limite exclusivo em 17/01 às 15:00 no fuso local.
- [ ] Dias e semanas usam calendário local e preservam a hora local, sem conversão para segundos fixos.
- [ ] Ano bissexto e conversões de fuso são tratados corretamente.
- [ ] Renovações contínuas preservam o dia de referência e não sofrem deriva involuntária após fevereiro.
- [ ] Renovação preserva o registro anterior e aplica a regra de calendário definida.
- [ ] Cancelamento encerra imediatamente a vigência de matrícula `ATIVA`, registra instante e funcionário responsável, e preserva o histórico.
- [ ] Matrícula `AGENDADA` pode ser cancelada antes do início, permanecendo no histórico como `CANCELADA` e sem intervalo efetivo utilizado.
- [ ] Matrícula `VENCIDA` não pode ser cancelada; permanece vencida, sem preenchimento de `cancelada_em` ou evento falso de cancelamento.
- [ ] Matrícula `CANCELADA` não aceita novo cancelamento nem gera evento duplicado.
- [ ] Situação é recalculada no servidor no instante da confirmação, dentro da transação, evitando cancelar como ativa uma matrícula que já venceu.
- [ ] Correção administrativa de dados de matrícula vencida é separada de cancelamento e registra alteração, funcionário e justificativa.
- [ ] Cancelamento antes do início mantém a matrícula como `CANCELADA`, com intervalo efetivo vazio, sem criar período utilizado e sem bloquear outra matrícula.
- [ ] Cancelamento exatamente no instante de início também produz intervalo efetivo vazio e não bloqueia outra matrícula.
- [ ] Cancelamento depois do início encerra a vigência no instante do cancelamento (ou no limite normal, se ocorrer primeiro) e preserva como período utilizado somente o intervalo anterior ao cancelamento.
- [ ] A consulta histórica diferencia a data/horário originalmente contratado do instante real em que a matrícula foi cancelada.
- [ ] Motivo de cancelamento é opcional e, quando informado, permanece consultável.
- [ ] Nova matrícula não começa antes do encerramento efetivo de uma matrícula cancelada nem retroage sobre período utilizado.
- [ ] Uma nova matrícula pode começar exatamente no mesmo instante do cancelamento anterior (por exemplo, às 14:00), sem sobreposição.
- [ ] Uma nova matrícula que começa um instante antes do limite efetivo é rejeitada.
- [ ] Períodos adjacentes com `fim_exclusivo_em` igual ao `inicio_em` seguinte são aceitos.
- [ ] Datas e horários informados na operação são interpretados em `America/Sao_Paulo`, e os instantes são convertidos para UTC antes de serem persistidos em `DATETIME`.
- [ ] A leitura interpreta os valores `DATETIME` como UTC e converte para `America/Sao_Paulo` na apresentação; não ocorre conversão implícita duplicada.
- [ ] Cálculos de calendário são executados no fuso `America/Sao_Paulo` antes da conversão do instante para UTC.
- [ ] Inativar o aluno não cancela automaticamente suas matrículas.
- [ ] Ao informar apenas uma data futura, a matrícula começa às 00:00 no fuso horário da academia.
- [ ] Ao informar apenas a data de hoje, o início padrão corresponde ao instante atual.
- [ ] É possível escolher um horário posterior no dia atual, mas horários anteriores ao instante atual são rejeitados.
- [ ] Data e horário futuros escolhidos manualmente são validados contra o momento atual e as demais matrículas.
- [ ] Renovações contínuas começam no limite exclusivo da matrícula anterior, sem usar o horário padrão de agendamento.
- [ ] O sistema permite inserir uma matrícula em um intervalo livre entre duas matrículas futuras já agendadas.
- [ ] O sistema rejeita datas inválidas, datas passadas e qualquer período que se sobreponha a uma matrícula não cancelada.
- [ ] Solicitações concorrentes não criam vínculos incompatíveis, usando bloqueio da linha-pai do aluno e ordem de locks consistente.
- [ ] Operações simultâneas de criação de matrícula e desativação/exclusão do plano são serializadas pelo bloqueio da linha do plano.
- [ ] Deadlock ou timeout provoca rollback integral e não deixa evento de sucesso parcial.
- [ ] As migrations SQL são numeradas e executadas na ordem documentada no XAMPP e na CI.
- [ ] O DDL das novas tabelas é separado da carga inicial DML; a carga é transacional e pode ser reexecutada em teste sem duplicar nem sobrescrever revisões.
- [ ] Em falha parcial de DDL, inspecionar a estrutura real do banco antes de corrigir/continuar; não presumir rollback automático nem executar novamente às cegas.
- [ ] Todas as operações de escrita exigem autenticação, autorização e CSRF.
- [ ] Testes automatizados e regressão dos módulos existentes passam.
- [ ] Documentação local e README são atualizados.
- [ ] Revisão humana das telas é registrada separadamente da CI.

## 10. Decisões aprovadas e pontos pendentes

As regras abaixo já foram aprovadas para orientar a implementação. O status não significa que o código esteja implementado ou testado.

| Tema | Regra registrada | Situação |
|---|---|---|
| Várias matrículas por aluno | Permitir histórico de várias matrículas, sem sobreposição de períodos não cancelados. | **Aprovada** |
| Matrículas futuras e intervalos | Permitir escolher início hoje ou em data futura válida, inclusive em intervalo entre contratos, desde que não haja sobreposição com matrícula não cancelada. | **Aprovada** |
| Renovação contínua | Começa exatamente no limite exclusivo anterior; mensal inclui o último dia de calendário e dias/semanas preservam a hora local. Não pode retroagir quando o limite já passou. | **Aprovada** |
| Durações de dias e semanas | Calendário local, preservando o horário de início: 1 dia = mesmo horário no dia seguinte; 1 semana = mesmo horário sete dias de calendário depois. | **Aprovada** |
| Data final mensal | Inclusiva; último dia calculado é coberto integralmente e o limite exclusivo é 00:00 local do dia seguinte, com ajuste ao último dia válido. | **Aprovada** |
| Cancelamento | Somente `AGENDADA` ou `ATIVA`; efeito imediato, preservar histórico, registrar instante e funcionário, motivo opcional. `VENCIDA` não pode ser cancelada; `CANCELADA` não aceita repetição. Correção de registro vencido é operação auditada separada. | **Aprovada** |
| Nova matrícula no dia do cancelamento | Permitir início no mesmo instante do cancelamento ou depois, com comparação por horário e sem sobreposição. | **Aprovada** |
| Trancamento/suspensão/congelamento | Fora do escopo aprovado da primeira versão do Bloco 03. | **Fora de escopo — aprovado** |
| Revisão de legados | Exigir um resultado auditado: `VINCULO_CONFIRMADO` com intervalo efetivo exato comprovado; `LEGADO_SEM_VIGENCIA` com evidência de ausência de contrato vigente; ou `PENDENTE_REVISAO` quando não for possível confirmar. Preservar o texto original e nunca inventar condições. | **Aprovada** |
| Retificação de vigência legada | Corrigir somente o intervalo com nova evidência, mantendo evento append-only com valores anteriores/novos. Sem intervalo novo comprovado, voltar a pendente, bloquear novas matrículas e preservar o registro anterior como provisório. | **Aprovada** |
| Fluxo de revisão | Fila administrativa com pendentes por padrão, filtros/pesquisa, formulário de decisão com fonte e histórico visível no perfil. Escritas via POST autenticado, CSRF e transação. | **Aprovada** |
| Migração de legados | Carga inicial identificada pelo aluno de origem, protegida por UNIQUE e executada em transação; reexecução não duplica nem sobrescreve revisão/auditoria. | **Aprovada** |
| Plano sem preço ou cadastro incompleto | Permitir salvar como rascunho; só ativar e disponibilizar para contratação com dados obrigatórios completos e preço maior que zero. | **Aprovada** |
| Exclusão de plano | Permitir exclusão física somente sem matrículas vinculadas; com histórico, bloquear exclusão e permitir desativação. | **Aprovada** |
| Horário padrão de início | Data futura sem horário: 00:00; hoje sem horário: instante atual; permitir horário posterior hoje, nunca anterior ao momento atual. | **Aprovada** |

As regras marcadas como aprovadas compõem a base funcional do Bloco 03. O fluxo de revisão e de retificação descrito neste documento também integra os critérios de implementação. A confirmação do ambiente local e a revisão final de compatibilidade continuam pendentes antes das migrations.

## 11. Fluxo operacional de revisão de legados

### Fila e consulta

- Criar uma área administrativa de revisão, com pendentes como filtro inicial e opções para consultar confirmados e casos sem vigência.
- Permitir pesquisa por nome, e-mail e texto original do plano; apresentar aluno, `plano_texto_original`, resultado atual e data da última revisão.
- No perfil do aluno, exibir o resultado atual, a indicação de bloqueio/liberação de novas matrículas e a linha do tempo das decisões/retificações.
- A tela de revisão apenas coleta a decisão e a evidência; a fonte deve ser uma referência verificável (documento, contrato, arquivo ou registro consultado), não uma afirmação genérica.

### Decisões disponíveis

- **`VINCULO_CONFIRMADO`:** exigir fonte e início/fim exatos da vigência. Criar ou associar uma matrícula `origem = 'LEGADO'` somente quando o intervalo efetivo estiver comprovado. Se não houver correspondência segura no catálogo, manter `plano_id` nulo; copiar o texto original para identificação histórica e deixar preço/duração nulos quando desconhecidos.
- **`LEGADO_SEM_VIGENCIA`:** exigir fonte confiável que sustente a ausência de contrato vigente. Não criar matrícula fictícia nem preencher preço, duração ou datas por estimativa; liberar novas matrículas.
- **`PENDENTE_REVISAO`:** exigir observação explicando o que falta confirmar. Não criar matrícula operacional por suposição e bloquear novas matrículas até nova decisão fundamentada.

### Segurança, histórico e reavaliação

- Implementar a fila em página autenticada e a gravação em endpoint exclusivamente POST, protegidos por `exigirAutenticacao()`, `validarToken()` e consultas preparadas.
- Obter `usuario_id` apenas da sessão autenticada; nunca aceitar autoria enviada pelo formulário.
- Exigir `fonte_referencia` em `VINCULO_CONFIRMADO` e `LEGADO_SEM_VIGENCIA`; para `PENDENTE_REVISAO`, exigir observações que expliquem a insuficiência, registrando a fonte consultada quando houver.
- Registrar resultado atual, evento especializado e auditoria geral na mesma transação. A importação inicial não gera decisão humana nem usuário fictício.
- Reavaliação com resultado igual só gera novo evento quando houver nova evidência ou retificação material; submissão idêntica é idempotente.
- Uma matrícula legada confirmada que passe a ser questionada deve ficar sinalizada como sob revisão. Enquanto o resultado for `PENDENTE_REVISAO`, nenhuma nova matrícula será permitida, ainda que o intervalo anteriormente registrado não se sobreponha ao novo período pretendido.

## 12. Revisão de consistência das decisões aprovadas

A revisão cruzada consolidada encontrou e tratou os seguintes pontos:

- Corrigida a duplicação de `cancelada_em` na lista conceitual de campos; o cancelamento é representado por um único atributo e pelo evento de auditoria.
- Alinhado o fluxo de legados: a revisão possui três resultados explícitos e auditados (`VINCULO_CONFIRMADO`, `LEGADO_SEM_VIGENCIA` e `PENDENTE_REVISAO`). Somente os dois primeiros, quando sustentados por evidência confiável, encerram o bloqueio; ausência de comprovação mantém o bloqueio.
- Alinhada a regra de início: a validação compara o instante escolhido com o momento atual no fuso da academia, e não apenas a data do calendário.
- Aprovado o padrão de horário: data futura sem horário inicia às 00:00; hoje usa o instante atual; horário posterior hoje pode ser escolhido, sem permitir retroatividade.
- Confirmado que o motivo de cancelamento é opcional, conforme decisão já aprovada.
- Aprovado que `AGENDADA`, `ATIVA` e `VENCIDA` serão derivados em tempo de consulta; o cancelamento será representado por `cancelada_em` e auditoria.
- Separados os resultados da revisão de legados dos estados da matrícula operacional; a revisão pendente bloqueia novas matrículas, mas não será armazenada como estado de contrato.
- Aprovado o cancelamento de matrículas futuras: cancelamento antes ou exatamente no início gera intervalo efetivo vazio, mantém o histórico e não bloqueia novas matrículas.
- Unificadas as durações mensais em uma regra explícita de mês-alvo, com referência original preservada e limite exclusivo à meia-noite seguinte ao último dia inclusivo.
- Aprovado que durações em dias/semanas preservam a hora local do início e somam dias de calendário, sem conversão para segundos fixos.
- Aprovada a elegibilidade de cancelamento: permitir somente para matrículas agendadas ou ativas; impedir cancelamento de vencidas e repetição em canceladas; corrigir registros vencidos por operação auditada separada.
- Alinhada a renovação contínua para começar exatamente no limite exclusivo anterior; o teste de referência 31 agora inclui 31/03 e termina em 01/04 às 00:00.
- Esclarecido que `VINCULO_CONFIRMADO` só libera novas matrículas quando o intervalo legado está comprovado e registrado; preço/duração desconhecidos podem permanecer nulos no registro legado.

### Pendências técnicas antes de considerar o Bloco 03 concluído

1. **Migrations 005 e 006:** criar e revisar o DDL e a carga inicial de revisão dos legados. A implementação das telas/serviços não alterou o esquema de dados; não executar uma migration parcial nem presumir que DDL terá rollback automático.
2. **Compatibilidade do banco local:** confirmar a versão real do MySQL/MariaDB do XAMPP, InnoDB nas tabelas envolvidas, suporte às constraints escolhidas e fuso da sessão. A matriz automatizada já cobre MySQL 8.0 e MariaDB 10.11, mas não identifica a versão instalada no computador.
3. **Validação de migração:** adicionar 005/006 à CI e cobrir banco limpo, conjunto legado existente, preservação do texto original, contagem/uniqueness, falhas parciais e reexecução sem sobrescrever decisões ou duplicar auditoria.
4. **Validação local:** somente após revisão das migrations e backup, aplicar no XAMPP; inspecionar `SHOW CREATE TABLE`, FKs, índices e mecanismos, e executar os cenários reais no navegador.
5. **Inspeção física:** testar desktop/mobile em dispositivo e navegador instalados, incluindo navegação por toque, foco/teclado, telas de revisão/retificação, mensagens de erro e histórico.
6. **Integração:** manter a PR #1 em Draft até a inspeção física e aprovação explícita; não fazer merge automaticamente.

A automação atual confirma a lógica de domínio e a interface HTTP no banco efêmero com fixtures, além da compatibilidade dos testes transacionais em MySQL 8.0 e MariaDB 10.11. Ela ainda não valida as migrations 005/006 nem a instalação local. O Bloco 03 está implementado em boa parte na branch, mas não está pronto para uso local integrado até essas pendências serem concluídas.

## 13. Dependências e limites do projeto

O Bloco 02 já implementou situação cadastral e inativação lógica, mas a inspeção em aparelho físico/navegador instalado continua pendente. A preparação deste documento não encerra formalmente o Bloco 02 e não autoriza merge da PR #1.

Este documento registra a especificação funcional, as decisões aprovadas, o estado de implementação e as pendências de validação. Os serviços e fluxos HTTP do Bloco 03 já estão implementados na branch; migrations não devem ser criadas/aplicadas sem revisão técnica, backup e validação explícita.

O FitControl permanece um projeto acadêmico/local. Não usar dados reais de alunos nem expor a aplicação publicamente.

## 14. Referências técnicas

Documentação oficial do MySQL consultada para fundamentar as recomendações de modelagem e integridade:

- [Fixed-Point Types — DECIMAL e NUMERIC](https://dev.mysql.com/doc/refman/8.0/en/fixed-point-types.html): valores numéricos exatos, apropriados para dados monetários.
- [Date and Time Data Type Syntax](https://dev.mysql.com/doc/refman/8.0/en/datetime.html): diferenças entre `DATE`, `DATETIME` e `TIMESTAMP`, incluindo a conversão de fuso aplicada a `TIMESTAMP`, mas não a `DATETIME`.
- [MySQL Server Time Zone Support](https://dev.mysql.com/doc/refman/8.0/en/time-zone-support.html): efeitos do fuso da sessão nas operações com tipos temporais.
- [FOREIGN KEY Constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html): integridade referencial e opções de exclusão/atualização.
- [CHECK Constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html): suporte e enforcement a partir do MySQL 8.0.16.
- [START TRANSACTION, COMMIT, and ROLLBACK](https://dev.mysql.com/doc/refman/8.0/en/commit.html): controle transacional.
- [InnoDB Locking Reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html): uso de leituras com bloqueio para proteger operações concorrentes.

A versão de MySQL/MariaDB usada no ambiente local deve ser confirmada antes de definir as restrições finais. A documentação MySQL não substitui a verificação de compatibilidade com a versão real do XAMPP/MariaDB.
