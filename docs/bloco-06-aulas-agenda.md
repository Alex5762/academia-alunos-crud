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
6. **Reservas internas:** somente funcionário reserva uma vaga para aluno elegível no MVP; cancelamento da reserva preserva o registro histórico e libera capacidade.
7. **Capacidade:** nunca permitir que reservas ativas excedam a capacidade da sessão, mesmo sob requisições concorrentes.
8. **Conflitos:** impedir que o mesmo instrutor seja escalado para duas sessões sobrepostas e que o mesmo aluno mantenha reservas sobrepostas.
9. **Presença na aula:** lista de participantes e marcação individual como presente/ausente depois do início da sessão. Essa presença é diferente do check-in geral da academia.
10. **Cancelamento de sessão:** Gestor/Admin pode cancelar uma sessão futura com justificativa; reservas permanecem consultáveis e não são apagadas.
11. **Auditoria:** criação/alteração de modalidade, turma, horário, sessão, reserva, cancelamento e marcação de presença gera evento na cadeia geral, dentro da mesma transação da escrita.
12. **Acessibilidade e responsividade:** agenda e lista de participantes utilizáveis em desktop, mobile e teclado.

### Fora do escopo inicial

- Portal completo de autoatendimento, escolha de aulas, criação e cancelamento de reservas pelo aluno (fora do MVP).
- QR Code, catraca, biometria ou check-in automático.
- Portal de autoatendimento do aluno, criação/cancelamento de reservas pelo aluno e lista de espera autoatendida/automática.
- Promoção automática de vagas, notificações automáticas e push no MVP.
- Cobrança por aula, pacotes de aulas, créditos ou integração com o financeiro.
- Gestão de salas, quadras, equipamentos ou recursos físicos.
- Notificações automáticas por e-mail, WhatsApp ou push.
- Aplicativo nativo, sincronização com Google/Outlook ou integrações externas.
- Multiunidade/multitenancy.
- Relatórios avançados (Bloco 07).

### Pesquisa aplicada: lista de espera sem aplicativo nativo

A pesquisa de soluções de agendamento para academias/estúdios encontrou três padrões relevantes:

1. **Fila com oferta da próxima vaga:** GettingBooked descreve que, quando uma pessoa cancela, a vaga é oferecida à próxima da fila; Luma descreve oferta automática antes de um horário-limite; Glofox descreve notificação e promoção conforme as políticas do estúdio. Avana descreve notificação ao primeiro da fila e passagem ao próximo quando a vaga não é aceita. São descrições dos próprios fornecedores sobre seus produtos, úteis como referência de mercado, não normas técnicas.
2. **Acesso por navegador:** GettingBooked oferece página de reservas no browser sem aplicativo; Luma compartilha uma página/URL ou widget incorporável; Glofox oferece app e web portal; Avana declara que o membro pode usar o browser do celular e código de acesso por e-mail, sem instalar app.
3. **PWA como aprimoramento opcional:** uma Progressive Web App usa tecnologias web e uma base de código para várias plataformas. O usuário pode abrir por URL no navegador e, se desejar, instalar um ícone na tela inicial e abrir em modo standalone. Isso não exige publicar nem manter um aplicativo nativo separado. Push é um recurso posterior, condicionado a suporte do navegador, HTTPS, service worker e consentimento explícito; no iOS/iPadOS, Web Push exige que a web app esteja adicionada à tela inicial e que o usuário autorize.

**Recomendação para o FitControl: web-first, sem app nativo.** O painel da equipe permanece no próprio sistema web responsivo. Para o MVP, a lista de espera pode ser interna e operada por funcionário; a equipe comunica a oferta pelo canal já usado pela academia (telefone/WhatsApp/e-mail) e registra a resposta no painel. Não precisamos criar portal de reservas do aluno, app Android/iOS, integração de notificações ou PWA agora.

### Lista de espera interna — proposta recomendada para o MVP (aguarda confirmação)

- Quando a sessão estiver cheia, funcionário autorizado pode adicionar aluno elegível à fila.
- A ordem é FIFO por `criado_em`, com `id` como desempate estável.
- A fila não ocupa vaga e não altera a capacidade. Só uma reserva ativa confirmada ocupa a vaga.
- Quando surge uma vaga, o painel sinaliza o próximo aluno elegível. Um funcionário entra em contato pelo canal habitual e registra: ofertada, aceita, recusada ou sem resposta.
- A aceitação só vira reserva quando um funcionário executa a operação normal de reservar; a transação de reserva revalida matrícula, conflito e capacidade. Se outra pessoa ocupar a vaga antes, a operação informa que não há mais disponibilidade e a equipe oferece a próxima posição.
- Recusa ou desistência encerra aquela entrada e libera a próxima posição. Não há promoção automática, bloqueio temporário de vaga ou expiração automática no MVP.
- Um aluno não pode estar duas vezes na fila da mesma sessão nem manter simultaneamente reserva ativa e entrada ativa na fila da mesma sessão.
- Toda inclusão, oferta, recusa, conversão em reserva e remoção é auditada. A fila preserva histórico em vez de apagar registros.

Esse desenho entrega o benefício operacional de uma waitlist sem obrigar o aluno a instalar um app nem exigir automação de mensagens. A promoção automática pode ser reavaliada após observar uso real.

### Acesso futuro do aluno por web link — sem aplicativo nativo

A confirmação seletiva de intenção de comparecer pode ser implementada como uma página mobile-first no próprio FitControl, aberta por link em navegador. Não requer app nativo nem PWA. A PWA seria opcional apenas se, mais tarde, houver valor em ícone de tela inicial ou push.

Para essa fase futura:
- O link deve dar acesso a uma única ação e a uma única reserva; nunca abrir agenda, dados financeiros, cadastro completo ou outras reservas.
- Usar token aleatório de alta entropia, armazenado somente como hash, associado à reserva e com expiração/revogação. O token não deve conter aluno_id ou outros dados pessoais legíveis.
- GET apenas apresenta a página; a confirmação ocorre em POST explícito para evitar que pré-visualizadores de e-mail/WhatsApp consumam o token.
- A resposta registra intenção de comparecer, instante e origem; não altera presença real, não cria/cancela reserva nem libera vaga.
- A funcionalidade fica desabilitada por padrão e é habilitada por reserva/aluno escolhido pela equipe. Não há conta de aluno no MVP.


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
- A lista de espera interna proposta não ocupa vaga; a posição é ordenada por `criado_em` e `id` e só funcionário autorizado administra a fila.
- Uma vaga aberta apenas sinaliza o próximo aluno elegível à equipe. Não há promoção automática, reserva temporária nem liberação automática baseada em resposta do aluno no MVP.
- A entrada de espera só vira reserva após confirmação operacional e nova validação transacional de capacidade, elegibilidade e conflito.
- Uma pessoa não pode possuir duas reservas ativas em sessões cujos intervalos se sobreponham.
- O mesmo aluno não pode ter duas reservas para a mesma sessão. Se cancelar e voltar atrás antes do início, o serviço poderá reativar o registro existente em vez de inserir duplicata, mantendo a trilha de auditoria.
- A capacidade é conferida dentro da transação, após bloquear a linha da sessão. O índice único continua protegendo a duplicidade por aluno/sessão.
- Cancelamento de reserva é permitido antes do início da sessão. Não haverá multa nem cobrança por falta nesta versão.
- Presença (presente/ausente) só pode ser marcada a partir do início da sessão e não pode ser marcada em sessão cancelada.
- A sessão cancelada não apaga reservas nem registros de presença anteriores; o estado da sessão prevalece para a apresentação da agenda.

### Confirmação opcional de participação pelo aluno — fase futura

- Não faz parte do MVP: reservas e cancelamentos são operados por funcionários.
- Em uma fase posterior e habilitada seletivamente, o aluno poderá responder somente sobre uma reserva que já esteja vinculada a ele.
- A resposta será armazenada separadamente da presença efetiva. Estados candidatos: pendente, confirmou que pretende comparecer e informou que não pretende comparecer; o vocabulário final será definido com o fluxo.
- Uma resposta do aluno não altera a presença, não cancela a reserva nem libera vaga automaticamente. Qualquer liberação continuará sob regra explícita e/ou ação autorizada da equipe.
- O evento de confirmação deve registrar instante e origem, sem permitir que o cliente envie livremente aluno_id, presença final ou ator administrativo.

## 5. Instrutores — decisão C confirmada

**Decisão confirmada:** cadastro independente de instrutores, com vínculo opcional a uma conta existente em `usuarios`. O instrutor pode constar na agenda sem receber credenciais ou acesso ao FitControl.

### Modelo proposto

A tabela `instrutores` será o cadastro profissional e operacional, independente do cadastro de autenticação:

| Campo | Regra |
|---|---|
| `id` | Chave primária `BIGINT UNSIGNED` |
| `nome` | Obrigatório, nome de exibição |
| `email` | Opcional |
| `telefone` | Opcional |
| `usuario_id` | Opcional, FK para `usuarios.id`, com índice UNIQUE |
| `ativo` | Obrigatório, inicia ativo; inativar em vez de excluir |
| `criado_em` / `atualizado_em` | Datas de controle |

- `usuario_id = NULL` significa instrutor sem conta de acesso.
- O índice UNIQUE permite que uma conta seja vinculada a no máximo um cadastro de instrutor, preservando múltiplos registros sem vínculo.
- O vínculo não cria conta, não concede permissões e não altera o perfil RBAC. Ele apenas relaciona o cadastro profissional a uma identidade já existente.
- Qualquer perfil atual (ADMINISTRADOR, GESTOR ou OPERADOR) pode ser vinculado, desde que a conta esteja ativa. Não será criado um papel novo de instrutor nesta versão.
- Um instrutor inativo não pode ser atribuído a novas turmas nem receber novas sessões. Inativar um instrutor não apaga nem cancela sessões futuras automaticamente: a agenda deve sinalizar o conflito para resolução explícita.
- Uma conta vinculada que seja desativada não apaga o cadastro profissional, mas impede novas atribuições enquanto a conta estiver inativa. O vínculo pode ser removido ou trocado por um administrador/gestor autorizado.
- Nome, e-mail e telefone do instrutor pertencem ao cadastro profissional e não são sincronizados automaticamente com `usuarios`. Alterações de conta não devem sobrescrever os dados profissionais.
- A primeira versão não terá login, painel ou permissões próprias para instrutores. Toda gestão é feita por Administrador/Gestor; o vínculo opcional não transforma o instrutor em usuário do módulo.

### Histórico e integridade

- Instrutores com turmas, sessões ou presenças associadas não podem ser excluídos fisicamente; devem ser inativados.
- A FK `instrutores.usuario_id` usa `ON DELETE RESTRICT`; o projeto já evita exclusão física de contas e mantém histórico de auditoria.
- Cada sessão gerada preserva `instrutor_id` e `instrutor_nome_snapshot`, para que renomear o cadastro profissional não reescreva a informação histórica da aula já programada.
- Criar, editar, inativar e vincular/desvincular conta gera evento na cadeia geral de auditoria, com o funcionário autenticado como responsável.
- A migration futura deve validar que `usuario_id` tem exatamente o mesmo tipo/unsigned da PK real de `usuarios.id`; confirmar isso na inspeção do schema de autenticação antes de escrever DDL.

## 6. Permissões RBAC propostas

| Permissão | Administrador | Gestor | Operador |
|---|---:|---:|---:|
| `classes.view` | Sim | Sim | Sim |
| `classes.manage` (modalidades, turmas e horários) | Sim | Sim | Não |
| `instructors.view` | Sim | Sim | Sim |
| `instructors.manage` (cadastro, edição, inativação e vínculo opcional) | Sim | Sim | Não |
| `classes.generate` (gerar sessões) | Sim | Sim | Não |
| `classes.reserve` | Sim | Sim | Sim |
| `classes.cancel_reservation` | Sim | Sim | Sim |
| `classes.cancel_session` | Sim | Sim | Não |
| `classes.attendance` (marcar presença/ausência) | Sim | Sim | Sim |

O servidor deve verificar cada permissão em toda página e endpoint. Ocultar controles na interface não é autorização.

## 10. Decisões confirmadas e decisões ainda abertas

### Confirmadas

- [x] **Instrutores:** opção C — cadastro profissional independente, com vínculo opcional a uma conta existente em `usuarios`. O cadastro não concede acesso ao sistema.
- [x] **Reservas no MVP:** opção A — somente Administrador/Gestor/Operador autorizado faz reservas e cancelamentos pelo painel interno. O aluno não terá conta ou portal de autoatendimento na primeira versão.
- [x] **Evolução limitada:** considerar futuramente a opção C apenas para alunos selecionados confirmarem intenção de participar de reservas já feitas pela equipe. Essa confirmação não será presença real, não cria reserva e não libera vaga automaticamente.

### Ainda abertas antes da implementação

1. **Janela de geração:** confirmar 30 dias como horizonte padrão de criação de sessões.
2. **Lista de espera:** decidir se aprovamos a fila interna operada por funcionários no MVP. A recomendação da pesquisa é incluir essa versão simples e deixar promoção automática fora.
3. **Presença por aula:** confirmar que será um registro separado do check-in geral da academia (a especificação recomenda separação).
4. **Confirmação seletiva futura:** a recomendação é uma página web mobile-first com token individual e limitado à reserva; decidir canal de entrega e política de expiração antes de implementar.
5. **PWA:** não faz parte do MVP. Reavaliar somente se houver necessidade real de instalação em tela inicial ou push; não criar app nativo dedicado.

A decisão sobre reservas internas define o MVP. As evoluções futuras não devem aumentar o escopo inicial nem bloquear sua implementação.

## 11. Referências técnicas e pesquisa de mercado

### Produto e operação de aulas

- [GettingBooked — booking software](https://gettingbooked.app/): página pública no navegador; a documentação comercial descreve fila de espera em que a vaga é oferecida à próxima pessoa quando há cancelamento.
- [Luma — gym scheduling](https://www.lumaos.app/gym-scheduling-software): página de agenda compartilhável/incorporável e waitlist com oferta da vaga antes de um cutoff.
- [ABC Glofox — FAQ](https://www.glofox.com/faq/): app e web portal; descreve notificação da próxima pessoa na fila, promoção conforme políticas e confirmação da vaga.
- [Avana — member app and browser](https://www.getavana.com/studio-software/app-voor-leden): descreve reserva pelo browser do celular com código por e-mail, sem download, além de lista de espera e notificação. As descrições são material dos fornecedores e não auditoria independente dos produtos.

### Plataforma web

- MDN — [Progressive Web Apps](https://developer.mozilla.org/pt-BR/docs/Web/Progressive_web_apps): tecnologias web e base de código compartilhada entre plataformas.
- MDN — [Making PWAs installable](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable): manifest, instalação e modo standalone.
- WebKit — [Web Push for Web Apps on iOS and iPadOS](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/): Web Push para web apps adicionadas à tela inicial no iOS/iPadOS 16.4+, mediante permissão do usuário.
- MDN — [Notifications API](https://developer.mozilla.org/en-US/docs/Web/API/Notifications_API): permissão para notificações deve ser solicitada em resposta a gesto do usuário.

### Concorrência e banco

- MySQL 8.0 — [InnoDB Locking Reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html): `SELECT ... FOR UPDATE`, bloqueios e transações.
- MySQL 8.0 — [Internal Locking Methods](https://dev.mysql.com/doc/refman/8.0/en/internal-locking.html): locks por linha e práticas para evitar deadlocks.
- MySQL 8.0 — [InnoDB Best Practices](https://dev.mysql.com/doc/refman/8.0/en/innodb-best-practices.html): PKs, FKs, índices e transações curtas.

