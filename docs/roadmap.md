# Roadmap do FitControl

## Objetivo

Evoluir o CRUD acadêmico de alunos para um painel de gestão de academia, mantendo entregas pequenas, testáveis e documentadas. O roadmap é uma direção de trabalho, não uma promessa de que todos os módulos serão implementados. O escopo de cada bloco será confirmado antes de começar.

## Ponto de partida

**Estado atual:** aplicação PHP com PDO e MySQL, executada localmente com XAMPP.

Já existe:
- Dashboard com indicadores básicos, distribuição por plano e listagem pesquisável.
- Cadastro, consulta, edição e exclusão de alunos.
- Validação de e-mail e telefone no servidor.
- Consultas preparadas com PDO e proteção CSRF nos formulários de escrita.
- Verificação de método HTTP nos endpoints de escrita.
- Layout responsivo e preferência de tema claro/escuro persistida no navegador.
- Integração contínua com testes HTTP reais e captura automatizada de telas.

Limites conhecidos:
- Não existe login, autenticação ou controle de acesso por usuário.
- O banco possui somente a tabela `alunos`; o campo `plano` é texto, não uma relação com uma tabela de planos.
- Não existem contratos/matrículas, pagamentos, check-in, aulas, agenda ou relatórios operacionais.
- Os indicadores do dashboard refletem apenas os dados disponíveis na tabela atual.
- A aplicação permanece acadêmica/local e não deve ser exposta como painel administrativo público.

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

**Estado:** em andamento. A CI e a captura automatizada passaram; a inspeção visual manual em navegador/dispositivo ainda precisa ser concluída.

### Bloco 01 — Autenticação e proteção do painel

**Objetivo:** impedir acesso administrativo sem autenticação.

Escopo inicial:
- Login e logout.
- Armazenamento seguro de senha com as funções apropriadas do PHP.
- Sessão com configurações seguras e renovação do identificador após autenticação.
- Proteção das páginas e endpoints administrativos.
- Mensagens de erro que não revelem detalhes internos.
- Testes para acesso sem sessão, login válido/inválido e logout.

**Decisão antes de implementar:** confirmar se o projeto precisa somente de um perfil administrador ou de vários níveis de permissão. Não criar um sistema complexo de papéis sem requisito.

### Bloco 02 — Gestão de alunos

**Objetivo:** transformar o cadastro básico em um registro útil para a operação da academia.

Possíveis entregas:
- Perfil/detalhes do aluno.
- Situação do cadastro (por exemplo, ativo ou inativo), com regras explícitas.
- Histórico de alterações ou eventos relevantes, se necessário.
- Filtros e pesquisa mais úteis.
- Validação e unicidade dos campos conforme regras acordadas.

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
- Cadastro de modalidades/turmas.
- Instrutores.
- Grade de horários e capacidade.
- Reservas/cancelamentos e regras de lotação.

### Bloco 07 — Relatórios e indicadores

**Objetivo:** transformar dados dos módulos anteriores em informação útil.

Possíveis entregas:
- Indicadores de alunos ativos.
- Frequência por período.
- Matrículas e vencimentos.
- Resumo financeiro.
- Filtros e exportação, se necessários.

**Dependência:** construir os relatórios depois que os dados e as regras dos módulos correspondentes estiverem estáveis.

### Bloco 08 — Preparação para disponibilização

**Objetivo:** preparar uma versão confiável para o ambiente escolhido, somente quando houver decisão de publicar ou usar com dados reais.

Possíveis entregas:
- Configuração de hospedagem e ambiente.
- Segredos fora do repositório.
- Backups e teste de restauração.
- Logs, monitoramento e tratamento de erros.
- Revisão de privacidade e LGPD.
- Atualização do banco por migrações e plano de recuperação.

**Decisão separada:** multiunidade/multi-tenant só entra se o FitControl for efetivamente direcionado a múltiplas academias como produto SaaS. Não faz parte do escopo presumido.

## Critério de conclusão de cada bloco

Um bloco só é considerado concluído quando:
- O escopo e os critérios de aceite foram registrados.
- O código está implementado sem quebrar os fluxos anteriores.
- Os testes relevantes foram executados e seus resultados registrados.
- A documentação de uso/configuração foi atualizada.
- As limitações restantes estão explícitas.
- A PR foi revisada; o merge depende de aprovação explícita.

## Modelo de planejamento por bloco

Para cada novo bloco, manter um registro com:
- **Objetivo e problema:** por que a funcionalidade existe.
- **Escopo:** entregas incluídas e itens explicitamente adiados.
- **Impacto técnico:** arquivos, rotas, banco e comportamento atual afetados.
- **Modelo de dados:** tabelas, relações, migração e estratégia de preservação dos dados.
- **Segurança e privacidade:** validações, permissões e dados tratados.
- **Tarefas:** etapas pequenas e verificáveis.
- **Testes:** cenários esperados, regressão e evidências.
- **Critérios de aceite:** o que precisa funcionar para encerrar o bloco.
- **Decisões pendentes:** pontos que não devem ser presumidos.
