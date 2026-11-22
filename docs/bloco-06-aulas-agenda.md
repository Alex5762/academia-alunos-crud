# Bloco 06 — Aulas e agenda

> **Estado:** especificação inicial proposta; implementação não iniciada.
> **Dependências:** Blocos 01–05 (autenticação/RBAC, alunos, matrículas, frequência e financeiro).
> **Ambiente:** continuar na branch `feat/dashboard-portfolio`; a validação XAMPP permanece adiada e não bloqueia a especificação nem a implementação automatizada.

## 1. Objetivo

Permitir que a equipe da academia organize aulas coletivas, mantenha horários recorrentes, gere sessões concretas na agenda, controle vagas e reservas e registre a presença específica em cada aula.

O módulo deve reutilizar alunos, matrículas, funcionários, fuso horário, transações e auditoria existentes. Não deve duplicar o check-in geral da academia nem criar um sistema de cobrança paralelo.

## 2. Escopo proposto para a primeira versão

### Incluído

1. **Modalidades:** cadastro, edição e ativação/inativação de modalidades (por exemplo: funcional, spinning, yoga ou dança).
2. **Turmas recorrentes:** nome da turma, modalidade, instrutor, capacidade, duração e situação.
3. **Horários semanais:** um ou mais horários por turma, com dia da semana, hora local e período de vigência.
4. **Geração explícita de sessões:** ação autenticada gera as próximas sessões dentro de uma janela limitada (proposta inicial: 30 dias). Não haverá cron, worker ou geração em background. A operação será idempotente: executar novamente não cria sessões duplicadas.
5. **Agenda:** visão diária/semanal, filtros por modalidade, turma e instrutor, com estados de sessão programada/cancelada.
6. **Reservas internas:** funcionário reserva uma vaga para aluno elegível; cancelamento da reserva preserva o registro histórico e libera capacidade.
7. **Capacidade:** nunca permitir que reservas ativas excedam a capacidade da sessão, mesmo sob requisições concorrentes.
8. **Conflitos:** impedir que o mesmo instrutor seja escalado para duas sessões sobrepostas e que o mesmo aluno mantenha reservas sobrepostas.
9. **Presença na aula:** lista de participantes e marcação individual como presente/ausente depois do início da sessão. Essa presença é diferente do check-in geral da academia.
10. **Cancelamento de sessão:** Gestor/Admin pode cancelar uma sessão futura com justificativa; reservas permanecem consultáveis e não são apagadas.
11. **Auditoria:** criação/alteração de modalidade, turma, horário, sessão, reserva, cancelamento e marcação de presença gera evento na cadeia geral, dentro da mesma transação da escrita.
12. **Acessibilidade e responsividade:** agenda e lista de participantes utilizáveis em desktop, mobile e teclado.

### Fora do escopo inicial

- Aplicativo ou portal de autoatendimento para o aluno.
- QR Code, catraca, biometria ou check-in automático.
- Lista de espera automática e promoção automática de vagas.
- Cobrança por aula, pacotes de aulas, créditos ou integração com o financeiro.
- Gestão de salas, quadras, equipamentos ou recursos físicos.
- Notificações por e-mail, WhatsApp ou push.
- Aplicativo nativo, sincronização com Google/Outlook ou integrações externas.
- Multiunidade/multitenancy.
- Relatórios avançados (Bloco 07).

## 3. Modelo de domínio proposto

| Entidade | Responsabilidade |
|---|---|
| `modalidades` | Catálogo de tipos de aula; inativar em vez de excluir quando houver histórico. |
| `turmas` | Definição da atividade recorrente, instrutor, capacidade padrão e duração. |
| `horarios_turma` | Regra semanal: dia da semana, hora local e vigência da recorrência. |
| `aulas` | Ocorrência concreta com início/fim UTC, capacidade e instrutor preservados como snapshot operacional. |
| `reservas_aulas` | Vínculo aluno–sessão, estado da reserva e autoria. |

**Distinção essencial:** turma/horário é a regra recorrente; aula é uma ocorrência datada. Reservas e presenças pertencem à ocorrência, nunca diretamente à regra semanal.

### Geração de sessões

- A geração será acionada por POST autenticado e protegido por CSRF.
- A janela padrão proposta é de 30 dias, com limite máximo configurável/validado pelo serviço.
- A geração converte data/hora de `America/Sao_Paulo` para UTC antes da persistência.
- Uma chave única por horário recorrente e instante da ocorrência impede duplicidade em reexecuções.
- Sessões já geradas não serão reescritas automaticamente quando a turma ou o horário semanal mudar. A alteração valerá para ocorrências ainda não geradas; sessões existentes exigem ajuste/cancelamento explícito e auditado.
- A geração deve informar quantas sessões foram criadas e quantas já existiam, sem tratar duplicidade idempotente como erro.

## 4. Regras de elegibilidade e reserva

- Somente aluno cadastralmente ativo pode reservar.
- O aluno precisa ter exatamente uma matrícula válida que cubra **todo o intervalo** da sessão: início da matrícula <= início da aula e fim efetivo da matrícula >= fim da aula. Matrículas canceladas, agendadas depois da aula, vencidas antes do término ou ambíguas não habilitam reserva.
- A elegibilidade será revalidada no serviço de domínio; não confiar apenas nas opções mostradas na tela.
- O primeiro corte não bloqueará reserva por inadimplência: financeiro e reservas permanecem desacoplados até uma decisão explícita posterior.
- Uma turma/sessão cancelada ou iniciada/concluída não aceita nova reserva.
- Uma reserva ativa ocupa uma vaga; reserva cancelada não ocupa vaga.
- Uma pessoa não pode possuir duas reservas ativas em sessões cujos intervalos se sobreponham.
- O mesmo aluno não pode ter duas reservas para a mesma sessão. Se cancelar e voltar atrás antes do início, o serviço poderá reativar o registro existente em vez de inserir duplicata, mantendo a trilha de auditoria.
- A capacidade é conferida dentro da transação, após bloquear a linha da sessão. O índice único continua protegendo a duplicidade por aluno/sessão.
- Cancelamento de reserva é permitido antes do início da sessão. Não haverá multa nem cobrança por falta nesta versão.
- Presença (presente/ausente) só pode ser marcada a partir do início da sessão e não pode ser marcada em sessão cancelada.
- A sessão cancelada não apaga reservas nem registros de presença anteriores; o estado da sessão prevalece para a apresentação da agenda.

## 5. Instrutores e funcionários

**Proposta inicial:** reutilizar `usuarios` como cadastro de funcionários/instrutores; não criar uma segunda tabela de pessoas nem duplicar nome/e-mail. Somente usuário ativo pode ser atribuído como instrutor. A primeira versão não terá portal de instrutor nem permissões individuais adicionais.

Isso significa que um instrutor precisa ter uma conta de funcionário no FitControl, mesmo que utilize o sistema apenas para constar na agenda. Caso a academia precise cadastrar instrutores sem conta de acesso, essa regra deverá ser revista antes da migration.

## 6. Permissões RBAC propostas

| Permissão | Administrador | Gestor | Operador |
|---|---:|---:|---:|
| `classes.view` | Sim | Sim | Sim |
| `classes.manage` (modalidades, turmas e horários) | Sim | Sim | Não |
| `classes.generate` (gerar sessões) | Sim | Sim | Não |
| `classes.reserve` | Sim | Sim | Sim |
| `classes.cancel_reservation` | Sim | Sim | Sim |
| `classes.cancel_session` | Sim | Sim | Não |
| `classes.attendance` (marcar presença/ausência) | Sim | Sim | Sim |

O servidor deve verificar cada permissão em toda página e endpoint. Ocultar controles na interface não é autorização.

## 7. Integridade, transações e concorrência

### Ordem de bloqueios proposta

- **Reservar vaga:** iniciar transação; bloquear aluno; bloquear sessão; validar status/capacidade; validar matrícula para o intervalo completo; verificar sobreposição de reservas; inserir/reativar reserva; registrar auditoria; commit.
- **Gerar sessões:** bloquear turma/horários em ordem determinística; verificar conflitos do instrutor; inserir ocorrências com chave única; registrar auditoria; commit.
- **Cancelar sessão:** bloquear sessão; validar que ainda é futura; registrar justificativa, estado e auditoria na mesma transação.
- **Marcar presença:** bloquear sessão e reserva em ordem fixa; validar início/status; alterar estado da presença e registrar auditoria.

Todas as operações que bloqueiam mais de uma entidade devem manter ordem consistente para reduzir deadlocks. A aplicação deve tratar deadlock/lock timeout de forma segura, com rollback e mensagem operacional; não repetir automaticamente uma escrita sem estratégia idempotente.

MySQL/InnoDB libera locks de `SELECT ... FOR UPDATE` no commit/rollback e usa essas leituras para proteger alterações concorrentes. A CI deve provar a capacidade com duas conexões independentes, tentando ocupar a última vaga ao mesmo tempo: exatamente uma reserva deve ser aceita.

### Integridade no schema

A migration específica será a próxima sequência disponível (proposta: `014_aulas_agenda.sql`), após conferir novamente o histórico de migrations. Deve incluir FKs com `ON DELETE RESTRICT`, índices para consultas da agenda, reservas e conflitos, constraints positivas para capacidade/duração e chave única para impedir reserva duplicada e geração duplicada da mesma ocorrência.

Não implementar a migration antes de fechar o modelo e os critérios de aceite.

## 8. Datas e fuso horário

- Fuso oficial: `America/Sao_Paulo`, consistente com matrículas, check-in e financeiro.
- Formulários recebem data/hora local; o serviço valida a data civil e converte explicitamente para UTC.
- Persistir instantes em UTC (`DATETIME`) e converter para o fuso da academia somente na apresentação.
- `fim_em_utc` deve ser posterior a `inicio_em_utc`; duração é positiva e limitada pelo domínio.
- Não aceitar sessões no passado ao criar/gerar agenda.
- Testar mudança de dia local, virada UTC/local, limites de intervalo e horários inválidos.

## 9. Critérios de aceite propostos

- [ ] Modalidades podem ser criadas, editadas e inativadas; registros com histórico não são apagados.
- [ ] Turmas e horários semanais podem ser criados e validados.
- [ ] Geração explícita de sessões é idempotente e limitada à janela permitida.
- [ ] Sessões geradas preservam os valores operacionais da ocorrência.
- [ ] Instrutor inativo ou com conflito de horário não pode ser escalado.
- [ ] Aluno inativo, sem matrícula válida ou com matrícula que não cobre toda a sessão não pode reservar.
- [ ] Capacidade nunca é excedida sob concorrência.
- [ ] Não há reservas duplicadas nem sobreposição de reservas para o mesmo aluno.
- [ ] Cancelamento libera vaga e preserva auditoria/histórico.
- [ ] Sessão cancelada não aceita reserva nem marcação de presença.
- [ ] Presença/ausência só pode ser marcada a partir do início e fica atribuída ao funcionário.
- [ ] Cada operação relevante e sua auditoria são atômicas.
- [ ] RBAC é validado em testes unitários e HTTP para os três perfis.
- [ ] Testes de domínio, integração MySQL 8.0 e MariaDB 10.11, concorrência e Playwright desktop/mobile passam.
- [ ] README, matriz RBAC, roadmap, guia de migrations e documentação do bloco refletem o estado real.

## 10. Decisões que precisam de confirmação antes da implementação

1. **Instrutor:** confirmar a proposta de usar um funcionário/conta já existente em `usuarios`, sem cadastro separado.
2. **Janela de geração:** confirmar 30 dias como horizonte padrão de criação de sessões.
3. **Reserva:** confirmar que a equipe fará reservas no painel interno; aluno não terá login/autoatendimento.
4. **Lista de espera:** manter fora da primeira versão.
5. **Presença por aula:** confirmar que será um registro separado do check-in geral da academia.

A especificação recomenda essas escolhas por reduzir duplicação e escopo, mas elas ainda não devem ser tratadas como decisões definitivas até revisão do projeto.

## 11. Referências técnicas

- MySQL 8.0 — [InnoDB Locking Reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html): `SELECT ... FOR UPDATE`, bloqueios e transações.
- MySQL 8.0 — [Internal Locking Methods](https://dev.mysql.com/doc/refman/8.0/en/internal-locking.html): locks por linha e práticas para evitar deadlocks.
- Scraler (11/11/2026) — [Class Booking Software: 6 Tools Compared](https://scraler.com/blog/class-booking-software): capacidades, horários recorrentes, reservas, lista de espera e políticas de cancelamento como dimensões comuns de sistemas de aulas. Referência de mercado, não norma técnica.

