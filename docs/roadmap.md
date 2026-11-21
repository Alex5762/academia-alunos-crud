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
- O campo `alunos.plano` ainda é texto, sem relação com uma tabela de planos.
- Não existem módulos de matrículas/contratos, pagamentos, check-in, aulas, agenda ou relatórios operacionais.
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

### Bloco 02 — Gestão de alunos

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
6. [Quase concluída] Regressão PHP/MySQL, Playwright, Chromium/Firefox/WebKit, documentação e revisão das capturas aprovadas; falta inspeção em aparelho físico/navegador instalado.

O encerramento formal depende da inspeção em aparelho físico/navegador instalado e da aprovação explícita para integrar a PR #1 em `main`. Não fazer merge automaticamente.

### Bloco 03 — Planos e matrículas

**Objetivo:** deixar de tratar o plano como texto livre e representar a relação do aluno com um plano.

Possíveis entregas:
- Cadastro e edição de planos.
- Valor, duração e situação do plano.
- Matrículas/contratos com início, término e status.
- Renovação e consulta de vencimentos.
- Migração dos valores atuais de `alunos.plano`, sem perder registros.

**Dependência:** decisões do modelo de alunos do Bloco 02. As regras de renovação e cancelamento devem ser definidas antes da implementação.

### Bloco 04 — Frequência e check-in

**Objetivo:** registrar entradas e consultar a frequência dos alunos.

Possíveis entregas:
- Check-in manual.
- Histórico de presenças por aluno e período.
- Regras para impedir registros inválidos ou duplicados.
- Indicadores básicos de frequência.

Leitor/QR Code fica fora do primeiro escopo, até existir necessidade real.

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
