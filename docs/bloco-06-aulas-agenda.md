# Bloco 06 — Grade fixa de aulas

> **Estado:** schema, serviços de domínio, consulta filtrada e primeira tela web implementados; CRUD administrativo ainda pendente.
> **Branch de trabalho:** `feat/dashboard-portfolio`.
> **Dependências:** autenticação/RBAC, cadastro de funcionários e auditoria existentes. A validação manual pelo XAMPP continua adiada e não bloqueia a especificação nem os testes automatizados.
>
> **Diretriz central:** começar com uma grade semanal fixa. A academia informa quais aulas oferece, em quais dias e horários e com quais instrutores. O aluno consulta a programação e comparece; o MVP não exige reserva nem check-in específico de aula.

## 1. Objetivo

Disponibilizar no FitControl uma forma simples de cadastrar e consultar a programação recorrente de aulas coletivas de uma academia.

O projeto é um sistema web acadêmico/de portfólio. O objetivo deste bloco é demonstrar modelagem de dados, CRUD, regras de consistência, autorização, auditoria e interface responsiva — sem presumir que toda academia precise de reservas ou de integração com equipamentos de acesso.

## 2. Escopo do MVP

### Incluído

1. **Modalidades:** cadastrar, editar, ativar e inativar modalidades, como funcional, spinning, yoga ou dança.
2. **Instrutores:** manter cadastro profissional independente, com vínculo opcional a uma conta de funcionário já existente.
3. **Horários fixos:** cadastrar cada ocorrência semanal da grade com modalidade, dia da semana, hora de início, hora de término e instrutor responsável, quando definido.
4. **Visualização da grade:** apresentar a programação organizada por dia e horário, com filtros simples por modalidade e instrutor.
5. **Manutenção da programação:** permitir editar e inativar horários, preservando a trilha de auditoria.
6. **Consistência:** validar horários, impedir término anterior ou igual ao início e impedir conflito de horários do mesmo instrutor no mesmo dia.
7. **Permissões:** restringir alterações a Administrador/Gestor; permitir consulta aos perfis autorizados.
8. **Acessibilidade e responsividade:** a grade e os formulários devem funcionar em desktop, mobile e navegação por teclado.

### Como a grade funciona

- Cada registro representa uma regra recorrente semanal, não uma aula em uma data específica.
- Para representar uma aula que ocorre em mais de um dia, cadastrar um horário por dia. Exemplo: Funcional às segundas às 19h e Funcional às quartas às 19h são dois horários recorrentes.
- Os horários são horários civis locais da academia, usando inicialmente o fuso `America/Sao_Paulo`.
- O horário pode ter instrutor não definido, caso a academia ainda não tenha atribuído um profissional.
- Alterar um horário altera a programação recorrente vigente e gera auditoria. O MVP não mantém histórico de cada ocorrência semanal.
- A grade é informativa. Ela não representa matrícula individual do aluno nem garante vaga em uma sessão.

### Fora do MVP

- Reservas, cancelamentos de reserva e controle de vagas.
- Lista de espera ou promoção automática de vagas.
- Registro de presença por aula ou lista de alunos esperados.
- Histórico individual de participação em aulas.
- QR Code, catraca, biometria ou integração com sistemas de acesso.
- Conta, portal ou autoatendimento do aluno.
- Geração de sessões datadas, calendário de ocorrências e exceções por feriados.
- Notificações, push, aplicativo nativo ou PWA.
- Cobrança por aula, créditos ou integração com o financeiro.
- Gestão de salas, quadras, equipamentos, multiunidade ou relatórios avançados.

O check-in geral já existente no Bloco 04 continua independente. Uma entrada registrada na academia não será interpretada como presença em uma modalidade ou aula específica.

## 3. Modelo de domínio inicial

O modelo deve permanecer pequeno e refletir a operação de grade aberta.

| Entidade | Responsabilidade |
|---|---|
| `modalidades` | Catálogo de atividades, com nome, descrição opcional e situação ativa/inativa. |
| `instrutores` | Cadastro profissional independente, com dados de contato e vínculo opcional a `usuarios`. |
| `horarios_aula` | Regra semanal com modalidade, dia da semana, hora inicial/final, instrutor opcional e situação. |

**Não criar no MVP** tabelas de reservas, lista de espera, presenças por sessão, tokens de confirmação ou ocorrências datadas de aulas.

A tabela `horarios_aula` pode usar uma linha por combinação de dia/horário. Não é necessário introduzir uma entidade de turma/coorte ou uma tabela de sessões concretas enquanto a operação for de participação livre.

Os nomes e tipos finais das colunas, chaves estrangeiras, índices e restrições serão confirmados após inspeção do schema real e antes de escrever a migration. A migration 014 e os serviços de domínio iniciais foram implementados na branch. A validação automatizada da migration passou em MySQL 8.0 e MariaDB 10.11. A aplicação local no XAMPP continua pendente.

## 4. Regras de negócio

- Modalidade inativa não pode receber novos horários ativos.
- Instrutor inativo não pode ser atribuído a novos horários ativos.
- Instrutor pode existir sem conta de funcionário.
- Uma conta de funcionário pode estar vinculada a no máximo um cadastro de instrutor.
- O vínculo opcional não cria credenciais, não concede acesso e não altera o RBAC.
- O mesmo instrutor não pode ter dois horários ativos sobrepostos no mesmo dia.
- Horários devem ter início e término válidos; não aceitar duração zero ou negativa.
- A inativação de modalidade ou instrutor não apaga horários ou registros existentes. A interface deve indicar vínculos que precisam ser revisados.
- Mudanças na programação devem ser auditadas com o funcionário responsável.
- Não validar elegibilidade de aluno, matrícula, inadimplência ou capacidade neste bloco, pois a grade não contém reservas nem inscrições individuais.

## 5. Instrutores — decisão confirmada

**Decisão:** cadastro independente com vínculo opcional a uma conta existente em `usuarios`.

O instrutor pode aparecer na grade sem possuir login no FitControl. Caso também seja funcionário, os dois cadastros podem ser vinculados, sem que isso conceda permissões adicionais.

Modelo conceitual proposto:

| Campo | Regra |
|---|---|
| `id` | Chave primária, tipo compatível com o padrão do projeto. |
| `nome` | Obrigatório. |
| `email` | Opcional. |
| `telefone` | Opcional. |
| `usuario_id` | Opcional, FK para `usuarios.id`, UNIQUE quando não nulo. |
| `ativo` | Situação do cadastro; inativar em vez de excluir. |
| timestamps | Datas de criação e atualização. |

Antes da migration, verificar o tipo exato de `usuarios.id`, incluindo sinalização UNSIGNED, engine e regras de exclusão. Não presumir o tipo da chave estrangeira.

## 6. Permissões RBAC propostas

| Permissão | Administrador | Gestor | Operador |
|---|---:|---:|---:|
| `classes.view` — consultar grade e modalidades | Sim | Sim | Sim |
| `classes.manage` — criar/editar/inativar modalidades e horários | Sim | Sim | Não |
| `instructors.view` — consultar instrutores | Sim | Sim | Sim |
| `instructors.manage` — criar/editar/inativar e vincular instrutores | Sim | Sim | Não |

A autorização deve ser verificada no servidor em toda página e endpoint. Ocultar controles na interface não substitui RBAC.

## 7. Critérios de aceite

- A equipe consegue cadastrar, editar e inativar uma modalidade.
- A equipe consegue cadastrar e inativar um instrutor sem criar conta de acesso.
- Um instrutor existente pode ser vinculado opcionalmente a um funcionário.
- A equipe consegue cadastrar mais de um horário para a mesma modalidade em dias diferentes.
- A grade pode ser consultada por dia e filtrada por modalidade/instrutor.
- Horários inválidos e conflitos de um mesmo instrutor são rejeitados no servidor.
- Operadores conseguem consultar, mas não alterar a programação.
- Alterações relevantes geram eventos na auditoria.
- A interface funciona nos viewports mobile e desktop cobertos pela CI.
- Nenhuma funcionalidade de reserva, presença por aula, QR Code ou catraca é necessária para considerar o MVP concluído.

## 8. Evoluções possíveis, sem compromisso para o MVP

Se o projeto precisar demonstrar uma operação mais avançada, a grade pode evoluir em etapas independentes:

1. **Ocorrências datadas:** gerar sessões para datas específicas, permitindo feriados, cancelamentos pontuais e substituição de instrutor sem alterar a regra semanal.
2. **Presença por aula:** registrar participantes de uma sessão específica, sem confundir com o check-in geral da academia.
3. **Confirmação por QR Code:** oferecer uma forma opcional de confirmar a presença, com identificação segura e prevenção de duplicidade.
4. **Integração com catracas:** estudar integração apenas quando houver equipamento e API/documentação compatíveis.
5. **Reservas:** adicionar somente se o modelo de operação de uma academia realmente exigir controle antecipado de vagas.

Essas evoluções não devem criar dependências no primeiro MVP. Em particular, não se deve inferir presença em aula a partir do check-in geral da academia.

## 9. Decisões registradas

### Confirmadas

- [x] **Grade semanal fixa como núcleo do MVP:** modalidades, instrutores e horários recorrentes.
- [x] **Participação livre:** o aluno consulta os horários e comparece; não precisa reservar vaga.
- [x] **Sem controle de vagas ou lista de espera no MVP.**
- [x] **Sem histórico de presença por aula no MVP:** o histórico de participação fica em aberto para uma evolução posterior.
- [x] **Check-in geral separado:** o registro de entrada do Bloco 04 não será automaticamente associado a uma aula.
- [x] **QR Code e catracas fora do MVP:** podem ser avaliados como evoluções independentes.
- [x] **Instrutores:** cadastro independente, com vínculo opcional a uma conta existente em `usuarios`.
- [x] **Web-first:** painel responsivo no navegador, sem aplicativo nativo.

### Implementação técnica registrada

- [x] Migration `014_grade_fixa_aulas.sql` cria `modalidades`, `instrutores` e `horarios_aula`.
- [x] `ClassCatalogService` implementa criação, edição e inativação de modalidades e instrutores.
- [x] `ClassScheduleService` implementa criação, edição e inativação de horários recorrentes.
- [x] `ClassScheduleQuery` centraliza a leitura da grade com filtros por dia, modalidade e instrutor.
- [x] `aulas.php` apresenta a grade semanal, indicadores e filtros, com acesso controlado por `classes.view`.
- [x] A página foi adicionada à navegação do painel e possui layout responsivo.
- [x] Alterações e eventos da cadeia de auditoria são confirmados na mesma transação.
- [x] Inativação é lógica; modalidade/instrutor com horários ativos precisa ter a grade revisada antes.
- [x] Horários ativos exigem modalidade ativa e, quando atribuído, instrutor ativo.
- [x] Teste com duas conexões independentes comprova a serialização e rejeição de sobreposição concorrente; executado na CI em MySQL e MariaDB.
- [x] Testes da consulta verificam filtros, horários sem instrutor e exclusão de itens inativos.
- [x] O serviço serializa gravações por instrutor e rejeita sobreposições no mesmo dia; horários adjacentes são permitidos.
- [x] RBAC inicial permite consulta aos três perfis e gestão somente a Administrador/Gestor.
- [x] CI #1450 aprovou a migration nos dois motores; nova suíte de serviços está sendo integrada à CI.

### Próximas etapas

1. Concluir a CI da consulta filtrada e corrigir eventuais falhas.
2. Implementar CRUD administrativo de modalidades e instrutores.
3. Implementar formulários de criação, edição e inativação de horários.
4. Cobrir os fluxos HTTP com Playwright e revisar acessibilidade/responsividade.

## 10. Referências e fronteiras com os outros blocos

- **Bloco 04 — Frequência e check-in:** continua responsável pelo check-in geral da academia. Este Bloco 06 não reaproveita esse registro como presença em aula.
- **Bloco 05 — Financeiro:** não há cobrança por aula nem bloqueio financeiro associado à grade.
- **Bloco 07 — Relatórios:** relatórios de participação ou ocupação podem ser avaliados quando houver dados de sessões e presenças.
- **Bloco 08 — Preparação para produção:** continua dependendo de validações e revisão próprias.

**Estado técnico:** migration, serviços de domínio, consulta filtrada e tela de leitura da grade estão implementados na branch. O CRUD administrativo e a validação local/XAMPP continuam pendentes.
