# Roadmap do FitControl

## Objetivo

Evoluir o CRUD acadêmico de alunos para um painel de gestão de academia, mantendo entregas pequenas, testáveis e documentadas. O roadmap é uma direção de trabalho, não uma promessa de que todos os módulos serão implementados. O escopo de cada bloco será confirmado antes de começar.

## Ponto de partida

**Estado atual:** aplicação acadêmica/local em PHP com PDO e MySQL, executada com XAMPP.

Já existe:
- Dashboard com indicadores básicos, distribuição por plano e listagem pesquisável.
- Cadastro, consulta, edição e exclusão de alunos, com validação no servidor e consultas preparadas.
- Proteção CSRF e verificação de método HTTP nas operações de escrita.
- Layout responsivo e preferência de tema claro/escuro persistida no navegador.
- Contas individuais, login/logout, proteção centralizada de rotas e gestão básica de funcionários.
- Troca obrigatória de senha temporária, desativação/reativação de contas e auditoria.
- Limitação persistente de tentativas de login.
- Sessões com 30 minutos de inatividade, duração absoluta de 8 horas, aviso antecipado e renovação autenticada durante interação.
- CI com MySQL, testes HTTP de integração, Playwright e capturas automatizadas.

Limites conhecidos:
- Todas as contas ativas têm a mesma permissão administrativa; não existe separação por cargo.
- Não há recuperação de senha por e-mail nem MFA.
- - O campo `alunos.plano` permanece preservado como texto legado; o catálogo e as matrículas estão implementados na branch, aguardando migrations 005/006 para integração local.
- Pagamentos, check-in, aulas, agenda e relatórios operacionais continuam fora dos blocos já implementados.
- A aplicação permanece acadêmica/local; não está preparada para uso público ou dados reais.

## Regras de execução

1. **Um bloco por vez.** Não começar o próximo módulo enquanto os critérios de aceite do bloco atual não forem verificados.
2. **Escopo fechado.** Cada bloco define o que entra, o que fica de fora e quais decisões precisam ser tomadas.
3. **Preservar a base existente.** Manter PHP, PDO e MySQL enquanto atenderem ao projeto. Não reescrever a aplicação nem introduzir camadas, frameworks ou serviços sem necessidade demonstrada.
4. **Banco com cuidado.** Mudanças de estrutura devem ter plano de migração, preservação dos dados existentes e instruções para o ambiente local.
5. **Segurança desde o início.** Validar no servidor, usar consultas preparadas, proteger operações de escrita, controlar acesso e evitar credenciais ou dados pessoais reais no repositório.
6. **Testes proporcionais.** Executar verificações de sintaxe, testes de integração e regressão dos fluxos afetados. Capturas automatizadas ajudam na revisão, mas não substituem inspeção visual humana.
7. **Documentação acompanha o código.** Atualizar README, instruções locais, decisões técnicas e relatório de testes no mesmo bloco.
8. **Integração controlada.** Trabalhar na branch de desenvolvimento e em PRs com escopo claro. Não fazer merge em `main` sem revisão e aprovação explícita.

## Integridade e recuperação da auditoria

**Implementado na branch `feat/dashboard-portfolio`; ainda aguardando integração revisada com `main`.**

- A migration 008 cria o estado da integridade e os mecanismos append-only da cadeia de auditoria.
- `AuditIntegrityChain` mantém hashes encadeados SHA-256 dos eventos posteriores ao marco histórico.
- `AuditIntegrityCheckpoint` exporta checkpoints HMAC externos, verifica assinaturas e presença do marco na cadeia, e compara checkpoints da mesma linhagem.
- `AuditRecoveryDiagnostic` executa diagnóstico somente leitura e distingue `consistent_through_checkpoint`, `possible_older_restore`, `chain_divergence`, `chain_invalid` e `insufficient_evidence`.
- O relatório v2 inclui o estado restaurado, checkpoints correspondentes, posteriores, divergentes ou rejeitados, resumo das evidências e uma janela potencial de perda delimitada por IDs quando existe checkpoint assinado posterior.
- A contagem exata de eventos potencialmente perdidos permanece explicitamente desconhecida: lacunas de identificadores e ausência de inventário completo impedem inferir quantidade pela diferença entre IDs. O diagnóstico não restaura backups, não repara cadeias e não altera auditoria.
- O CLI exige uma chave HMAC com pelo menos 64 caracteres hexadecimais, equivalente a 32 bytes de entropia; checkpoints e relatórios devem ficar fora da raiz do projeto.

**Validação:** as CI #1153–#1158 aprovaram o teste da camada de checkpoints na execução MySQL e as matrizes de compatibilidade/migrations. O workflow foi ampliado para executar também `tests/database-audit-checkpoint.php` nos jobs MySQL 8.0 e MariaDB 10.11; a confirmação dos dois motores depende da próxima CI desta alteração.

**Pendência:** completar essa matriz, inspecionar o relatório gerado em cenário controlado e reconciliar a revisão da PR sem merge automático.

## Blocos planejados

### Bloco 00 — Consolidar a base atual

**Objetivo:** deixar o estado presente coerente, testado e documentado antes de ampliar o sistema.

Entregas:
- Conferir README, estrutura e relatório de testes.
- Registrar o resultado real da CI e os limites da automação.
- Revisar as capturas desktop/mobile e os temas.
- Resolver pendências visuais ou documentá-las com clareza.
- Atualizar a descrição da PR para refletir o estado real.

**Estado:** base funcional, autenticação, gestão de funcionários, auditoria, política de sessão e alteração voluntária de senha implementadas. A CI foi ampliada para verificar Chromium, Firefox e WebKit, viewports desktop/mobile estreito, overflow, rolagem interna, teclado, foco visível e validação nativa de campos obrigatórios. Os workflows de push #245 e PR #244 passaram, e os logs confirmam aprovação nos três motores. Permanece pendente apenas a inspeção humana em dispositivo físico e a avaliação de percepção visual/uso real, incluindo gesto de toque e tecnologias assistivas.

### Bloco 01 — Autenticação e proteção do painel

**Estado: implementado e validado pela CI em 20/11/2026.**

Entregas:
- Login/logout com senha armazenada por hash e mensagens genéricas.
- Sessões protegidas, CSRF e proteção centralizada das páginas e endpoints administrativos.
- Contas individuais, provisionamento seguro, troca obrigatória da senha temporária e gestão de funcionários.
- Desativação/reativação sem apagar histórico; bloqueio de autodesativação e da desativação da última conta ativa.
- Alteração voluntária de senha após o primeiro acesso, com verificação da senha atual e auditoria.
- Auditoria de login/logout, operações de alunos e operações de contas.
- Limitação de tentativas de login: cinco falhas por combinação de e-mail/origem em 15 minutos geram bloqueio temporário de 15 minutos.
- Expiração por 30 minutos de inatividade ou 8 horas absolutas, aviso antecipado e renovação autenticada durante interação.

**Decisão funcional:** todas as contas ativas possuem a mesma permissão administrativa nesta versão. Perfis diferentes ficam fora do escopo até existir uma necessidade concreta.

**Pendências que não impedem a continuidade acadêmica/local, mas impedem considerar o sistema pronto para produção:** MFA, recuperação de senha por e-mail, revisão independente de segurança, HTTPS de produção, backups, monitoramento e revisão de privacidade.

**Estado: implementação, regressão automatizada, revisão das capturas, matriz multibrowser e estados vazios aprovados.** As CI [#359](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44876515363), [#375](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44877943853), [#387](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44880764637) e [#395](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44882082854) passaram. A #395 confirmou nos logs Chromium, Firefox e WebKit para estados vazios da listagem, perfil vazio/preenchido, histórico, foco, teclado e responsividade. As quatro capturas desktop/mobile do perfil nos temas claro/escuro estão versionadas e revisadas. Falta somente inspeção em aparelho físico/navegador instalado. A PR #1 permanece aberta em Draft e não foi integrada à `main`.

**Estado: implementação, regressão automatizada, revisão visual das capturas e matriz multibrowser aprovadas.** As CI [#359](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44876515363), [#373](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44877723150), [#375](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44877943853) e [#387](https://github.com/Alex5762/academia-alunos-crud/actions/runs/44880764637) passaram. Os logs da CI #387 confirmam Chromium, Firefox e WebKit, incluindo perfil vazio/preenchido, foco visível, teclado, links de contato e overflow mobile em 390px/320px. As quatro capturas do perfil estão versionadas para desktop/mobile nos temas claro/escuro. Falta inspeção em aparelho físico/navegador instalado. A PR #1 permanece aberta em Draft e não foi integrada à `main`.

Objetivo: evoluir o cadastro básico para gestão do ciclo de vida dos alunos, preservando registros e permitindo consulta, pesquisa, filtros e histórico operacional.

Decisões fechadas:
- Adicionar alunos.ativo, com padrão 1; todos os registros existentes serão considerados ativos.
- Substituir a exclusão física pela inativação/reativação lógica.
- Alterações de status serão feitas por POST autenticado, com CSRF, transação e auditoria.
- Pesquisa por nome, e-mail e telefone; filtro Ativos/Inativos/Todos, com Ativos como padrão.
- Alunos inativos continuarão consultáveis e editáveis.
- Dashboard contará ativos/inativos separadamente; distribuição de planos considerará somente ativos.
- Detalhes do aluno terão histórico simples dos eventos registrados na auditoria.
- Não haverá UNIQUE novo para telefone ou e-mail nesta etapa.
- Exclusão física excepcional, histórico campo a campo, planos/matrículas e financeiro ficam fora do escopo.

Etapas:
1. [Aprovada] Migração 004 e camada de dados, com teste de preservação de registros legados.
2. [Aprovada pela CI #359] Endpoint transacional de ativação/inativação, auditoria e aposentadoria do fluxo de exclusão física.
3. [Aprovada pela CI #359] Situação, filtros e pesquisa, com contexto preservado na edição, retorno e alteração de status.
4. [Aprovada pela CI #359] Indicadores de ativos/inativos e distribuição de planos somente para ativos.
5. [Aprovada pelas CI #359/#373/#375] Detalhes do aluno e histórico simples de auditoria; testes HTTP e capturas Playwright desktop/mobile nos temas claro e escuro.
6. [Quase concluída] Regressão PHP/MySQL, Playwright, Chromium/Firefox/WebKit, estados vazios, documentação e revisão das capturas aprovadas; falta inspeção em aparelho físico/navegador instalado.

O encerramento formal depende da inspeção em aparelho físico/navegador instalado e da aprovação explícita para integrar a PR #1 em `main`. Não fazer merge automaticamente.

### Bloco 03 — Planos e matrículas

**Estado: implementação funcional avançada na branch; CI #741 (push) e #742 (PR) aprovadas em MySQL 8.0, MariaDB 10.11 e testes PHP/HTTP. Ainda não concluído para uso local: migrations 005/006, validação no XAMPP e inspeção física permanecem pendentes.**

Documento de referência: [Especificação, estado de implementação e pendências do Bloco 03](bloco-03-planos-matriculas.md).

**Objetivo:** deixar de tratar o plano como texto livre e representar separadamente o catálogo de planos e os vínculos contratuais dos alunos.

Escopo candidato:
- Catálogo de planos com duração, valor e situação.
- Matrículas com condições contratadas, período e histórico.
- Renovação sem sobrescrever matrículas anteriores.
- Consulta de vencimentos e histórico no perfil do aluno.
- Migração preservadora dos valores atuais de `alunos.plano`.

**Implementado e coberto pela CI na branch:** catálogo de planos; contratação com snapshot das condições; histórico e cancelamento de matrículas; fila e decisão auditada de revisão de legados; retificação auditada de vigência com preservação dos intervalos anterior/novo e idempotência. A validação HTTP usa fixtures em banco efêmero, não migrations reais.

**Próximas entregas obrigatórias:** migrations 005/006, testes de carga/reexecução/preservação, confirmação do ambiente XAMPP, execução local após backup e inspeção física. A PR #1 continua Draft; não integrar automaticamente.

**Regras aprovadas:**
- Um aluno pode ter várias matrículas no histórico, mas não pode ter períodos sobrepostos em matrículas não canceladas.
- Na renovação contínua antecipada, a nova matrícula começa exatamente no limite exclusivo da matrícula vigente, preservando todos os dias/horários contratados; o início não pode ser retroativo se esse limite já passou.
- Durações mensais incluem integralmente o último dia calculado e terminam à 00:00 local do dia seguinte; datas inexistentes são ajustadas ao último dia válido, preservando a referência original nas renovações contínuas.
- Durações em dias/semanas preservam a hora local de início e somam dias de calendário, sem converter para segundos fixos.
- O cancelamento é permitido somente para matrículas `AGENDADA` ou `ATIVA`: a primeira pode ser cancelada antes do início; a segunda encerra imediatamente no instante da confirmação. Matrículas `VENCIDA` não podem ser canceladas e `CANCELADA` não aceita repetição. A operação registra instante e funcionário responsável; o motivo é opcional. Correções administrativas de registros vencidos são operações separadas e auditadas.
- Uma nova matrícula pode começar exatamente no mesmo instante do cancelamento anterior ou depois, nunca antes, desde que não haja sobreposição com o período efetivamente utilizado.
- A revisão de cada legado deve resultar em uma das três conclusões auditadas: vínculo confirmado (`VINCULO_CONFIRMADO`), ausência comprovada de matrícula vigente (`LEGADO_SEM_VIGENCIA`) ou impossibilidade de confirmar (`PENDENTE_REVISAO`). `VINCULO_CONFIRMADO` só libera novas matrículas quando o intervalo efetivo exato estiver comprovado e registrado para a verificação de sobreposição; preço, duração original ou correspondência com o catálogo podem permanecer desconhecidos. `LEGADO_SEM_VIGENCIA` libera novas matrículas com evidência confiável; `PENDENTE_REVISAO` mantém o bloqueio. A revisão registra funcionário, instante, conclusão e referência da fonte, sem inventar dados ou sobrescrever o texto original.
- O funcionário pode escolher início hoje ou em data futura válida, inclusive criando intervalo entre contratos. A data escolhida não pode ser passada nem sobrepor qualquer matrícula não cancelada do mesmo aluno. A renovação contínua automática permanece como padrão quando não se escolhe outro início.
- Um plano incompleto pode ser salvo como rascunho, mas só pode ser ativado e disponibilizado para novas matrículas quando todos os campos obrigatórios estiverem preenchidos e o preço for maior que zero. Planos em rascunho/inativos não podem ser contratados; nenhum preço é inventado.
- Plano sem matrícula vinculada pode ser excluído; plano com histórico não pode ser apagado e deve ser desativado. O catálogo distingue `RASCUNHO`, `ATIVO` e `INATIVO`; planos inativos continuam consultáveis no histórico, mas não recebem novas matrículas.

**Situação e diretriz temporal aprovadas:** `AGENDADA`, `ATIVA` e `VENCIDA` serão calculadas em tempo de consulta; `CANCELADA` deriva de `cancelada_em` e do evento de auditoria; somente matrículas `AGENDADA` ou `ATIVA` podem receber cancelamento, e uma `VENCIDA` mantém seu encerramento natural. Uma correção de registro vencido é separada e auditada. Os estados da revisão de legados (`VINCULO_CONFIRMADO`, `LEGADO_SEM_VIGENCIA`, `PENDENTE_REVISAO`) permanecem separados das situações da matrícula. Os períodos usam intervalos semiabertos `[início, fim exclusivo)`, e cancelamento antes ou exatamente no início gera intervalo efetivo vazio. **Durações mensais:** o último dia calculado pela referência original é integralmente incluído; o limite exclusivo ocorre às 00:00 locais do dia seguinte, mesmo com início intradiário. Exemplo: 10/01 às 15:00 + 1 mês inclui 10/02 inteiro e termina em 11/02 às 00:00. Início em 31/01 + 1 mês inclui 28/02 (ou 29/02 em ano bissexto), terminando em 01/03. Duração de dois meses a partir de 31/01 inclui 28/02 e 31/03, terminando em 01/04 às 00:00. Renovações contínuas preservam a referência original. **Durações em dias/semanas:** preservam a hora local e somam dias de calendário; 10/01 às 15:00 + 1 dia termina em 11/01 às 15:00; + 1 semana termina em 17/01 às 15:00. A renovação contínua começa exatamente no limite exclusivo anterior e não pode retroagir se esse limite já estiver no passado. Limites intradiários e cancelamentos são exibidos com data e hora exatas.

**Fuso e persistência aprovados:** `America/Sao_Paulo` será o fuso oficial da academia. Os instantes `inicio_em`, `fim_exclusivo_em` e `cancelada_em` serão armazenados em UTC nos campos `DATETIME`, com conversão explícita na aplicação: entradas e cálculos de calendário no fuso local, conversão para UTC antes da gravação e conversão de UTC para o fuso local na leitura/apresentação. O MySQL não converte automaticamente valores `DATETIME` por fuso; essa diferença em relação a `TIMESTAMP` está descrita na [documentação oficial de tipos temporais](https://dev.mysql.com/doc/refman/8.0/en/datetime.html).

A implementação deverá proteger a regra de não sobreposição contra solicitações concorrentes bloqueando a linha-pai do aluno com `SELECT ... FOR UPDATE`; criação/exclusão de matrícula também bloqueará a linha do plano. A ordem de locks deve ser consistente. O cálculo de calendário ficará centralizado em função testável, sem usar soma de meses suscetível a overflow. A renovação contínua começa exatamente no limite exclusivo anterior e não pode ser criada retroativamente quando esse limite já passou.

**Horário de início aprovado:** quando for informada apenas uma data futura, o início será às 00:00 no fuso da academia; para hoje, o padrão será o instante atual, permitindo escolher horário posterior, nunca anterior. A renovação contínua começa automaticamente no limite exclusivo anterior.

**Migração e auditoria aprovadas:** cada aluno do conjunto inicial terá um único registro de revisão legado, identificado por FK com restrição `UNIQUE`. Decisões humanas terão histórico próprio append-only e evento geral na auditoria, gravados na mesma transação. A carga DML será transacional; o DDL será verificado após execução, pois MySQL/MariaDB podem confirmar DDL implicitamente. As migrations serão arquivos SQL numerados e executados explicitamente na ordem documentada no README/CI; não haverá runner genérico nem tabela de checksums nesta versão de portfólio. A proposta de sequência é `005_plans_enrollments_schema.sql` para DDL e `006_import_legacy_review.sql` para a carga inicial. Alunos criados depois da carga inicial seguem o fluxo normal.

**Fora do escopo aprovado:** trancamento, suspensão e congelamento.

**Revisão técnica:** corrigidos os exemplos de fim de fevereiro; definidos estados distintos para rascunho/ativo/inativo; especificado histórico append-only das revisões; simplificada a estratégia de migrations para arquivos SQL numerados; recomendada proteção contra sobreposição concorrente com bloqueio da linha-pai do aluno. Fuso `America/Sao_Paulo`, persistência UTC dos instantes contratuais em `DATETIME`, cancelamento antes do início e inclusão integral do último dia de calendário nas durações mensais — inclusive para inícios intradiários — também estão aprovados. Os metadados administrativos seguem a convenção atual de `criado_em`/`atualizado_em` e não participam dos cálculos de vigência. Antes das migrations, confirmar a versão do MySQL/MariaDB e o uso de InnoDB na tabela `alunos`; depois implementar e testar os fluxos e as migrations numeradas.

Não iniciar migrations ou implementação até revisar as decisões restantes e confirmar os critérios de aceite.

**Dependência:** Bloco 02. A inspeção física do Bloco 02 continua pendente; este planejamento não o encerra e não autoriza merge da PR #1 em `main`.

### Bloco 04 — Frequência e check-in

**Estado: implementação inicial em andamento na branch `feat/dashboard-portfolio`.** A especificação fechada está em [Bloco 04 — Frequência e check-in](bloco-04-frequencia-checkin.md).

**Objetivo:** registrar entradas e consultar a frequência dos alunos.

Escopo aprovado:
- Check-in manual, com instante fornecido pelo servidor.
- Apenas aluno ativo com exatamente uma matrícula vigente pode registrar presença.
- No máximo um check-in por aluno por dia civil em `America/Sao_Paulo`.
- Histórico filtrável por aluno e período.
- Indicadores básicos do dia e do mês.
- Auditoria transacional e permissões RBAC específicas.
- QR Code, catraca, registro retroativo, edição/exclusão e financeiro ficam fora do escopo.

A migration 009 cria a estrutura de check-ins vinculada a alunos, matrículas e funcionários. A validação automatizada será executada em MySQL 8.0 e MariaDB 10.11. A aplicação no XAMPP continua dependendo de backup, inspeção da versão/engine e execução manual controlada.

### Bloco 05 — Financeiro

**Objetivo:** acompanhar cobranças e pagamentos sem misturar o financeiro com o simples cadastro de alunos.

Possíveis entregas:
- Lançamentos vinculados a matrículas.
- Situação de cobrança e pagamento.
- Registro de pagamentos e inadimplência.
- Resumo financeiro por período.

**Dependência:** planos e matrículas definidos no Bloco 03. Integrações com gateways e cobranças automáticas dependem de decisão separada.

### Bloco 06 — Aulas e agenda

**Objetivo:** organizar horários, turmas e reservas, caso façam parte do escopo desejado.

Possíveis entregas:
- Cadastro de modalidades e turmas.
- Instrutores e horários.
- Capacidade e reservas.
- Consulta da agenda.

### Bloco 07 — Relatórios e experiência

**Objetivo:** melhorar a leitura operacional dos dados e a usabilidade.

Possíveis entregas:
- Relatórios por período.
- Indicadores de evolução.
- Exportação controlada.
- Melhorias de acessibilidade e navegação.

### Bloco 08 — Preparação para produção

**Objetivo:** avaliar o que seria necessário antes de qualquer implantação real.

Possíveis entregas:
- Revisão independente de segurança.
- HTTPS e gestão de segredos.
- Backups e teste de restauração.
- Monitoramento e logs.
- Revisão de privacidade/LGPD.
- Estratégia de hospedagem, atualização e recuperação.

**Regra:** o projeto não deve receber dados reais nem ser exposto publicamente antes desta etapa e de uma avaliação específica.
