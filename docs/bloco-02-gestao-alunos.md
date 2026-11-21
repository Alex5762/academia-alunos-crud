# Bloco 02 — Gestão de alunos

## Estado

**Em implementação — perfil individual e histórico implementados em código; aguardando CI.** A migração 004 e seu teste de preservação passaram em CI anterior. O endpoint transacional, inativação/reativação, aposentadoria da exclusão física, filtros e dashboard estão implementados. A preservação de busca e situação foi coberta por novos testes de integração. A Etapa 5 agora inclui página individual com dados atuais, situação, eventos de auditoria e ações de edição/status; esta entrega e a regressão correspondente aguardam CI.

Este documento fecha o escopo funcional e técnico do Bloco 02. A execução deve acontecer na branch `feat/dashboard-portfolio`, em etapas pequenas, com testes e documentação acompanhando o código. Não fazer merge em `main` sem revisão e aprovação explícita.

## 1. Objetivo

Evoluir o cadastro simples de alunos para uma gestão básica do ciclo de vida do cadastro, sem antecipar os módulos de planos/matrículas, financeiro ou frequência.

Ao concluir o bloco, a equipe deverá conseguir consultar os dados de um aluno, distinguir cadastros ativos e inativos, localizar registros por busca e filtro e preservar o histórico de ações relevantes.

## 2. Diagnóstico da base

Estado atual identificado antes da implementação:

- A tabela `alunos` possui `id`, `nome`, `telefone`, `email` e `plano`.
- Não existe coluna de situação do aluno.
- A pesquisa do dashboard considera apenas o nome.
- O dashboard e a distribuição por plano consideram todos os registros.
- A exclusão em `excluir.php` executa `DELETE` físico.
- A tabela `auditoria` já registra `aluno_criado`, `aluno_editado` e `aluno_excluido`, com autor e data do evento.
- Os testes de integração verificam atualmente a exclusão física; esses cenários deverão ser substituídos pelos de inativação/reativação.

A tabela `alunos` não possui atualmente unicidade declarada para telefone ou e-mail.

## 3. Decisões funcionais fechadas

### 3.1 Situação do aluno

- Adicionar o campo `ativo` em `alunos`, do tipo `TINYINT(1)`, `NOT NULL`, com padrão `1`.
- Todos os registros existentes serão considerados ativos na migração.
- A situação terá dois estados: **Ativo** e **Inativo**.
- Novos cadastros sempre começarão ativos.
- A edição dos dados cadastrais não poderá alterar a situação; ativação/inativação terá uma ação própria.

### 3.2 Inativação em lugar da exclusão

- A ação normal da interface será inativar ou reativar o cadastro, sem apagar a linha do banco.
- A operação será feita exclusivamente por POST, com autenticação e token CSRF.
- O servidor aceitará apenas os estados permitidos e identificará o aluno pelo ID validado.
- A alteração do status e o evento de auditoria serão gravados na mesma transação.
- Repetir a solicitação para o estado em que o aluno já se encontra não deverá gerar eventos duplicados.
- A exclusão física deixa de fazer parte da interface e do fluxo normal do sistema.
- O endpoint antigo `excluir.php` não poderá continuar executando `DELETE`; deverá ser aposentado de forma explícita (HTTP 410) ou removido após atualizar todas as referências e testes.

**Fora do escopo:** rotina administrativa ou ferramenta para exclusão física excepcional. Não haverá botão de exclusão definitiva neste bloco.

### 3.3 Edição e consulta

- Alunos inativos continuarão disponíveis para consulta.
- Será permitido editar os dados cadastrais de alunos ativos e inativos.
- A tela de detalhes exibirá os dados atuais e a situação do aluno.
- A tela de detalhes exibirá um histórico simples dos eventos disponíveis na auditoria: criação, edição, inativação e reativação.
- O histórico será apenas de eventos; não armazenará comparação campo a campo nem valores antigos/novos.

### 3.4 Pesquisa e filtros

- A pesquisa textual poderá localizar alunos por nome, e-mail ou telefone.
- Haverá filtro de situação com as opções **Ativos**, **Inativos** e **Todos**.
- O padrão da listagem será **Ativos**.
- A combinação de pesquisa e filtro deverá funcionar ao mesmo tempo.
- Valores de filtro serão validados por allowlist; consultas continuarão parametrizadas.
- O estado dos filtros deverá permanecer nos links de paginação, ações e retorno à listagem, caso paginação seja introduzida nesta etapa.

### 3.5 Dashboard

- O indicador principal de alunos será a quantidade de **alunos ativos**.
- Um indicador separado apresentará a quantidade de **alunos inativos**.
- A distribuição por plano e o plano em destaque considerarão somente alunos ativos.
- Os rótulos dos indicadores deverão deixar explícito quando representam alunos ativos.
- A busca/listagem não poderá alterar os totais do dashboard.

### 3.6 Validação e unicidade

- Manter as validações atuais de nome, telefone, e-mail e plano.
- Não criar restrições UNIQUE para telefone ou e-mail neste bloco.
- Não presumir que e-mail ou telefone sejam exclusivos: podem ser compartilhados por responsáveis ou familiares.
- Regras adicionais de normalização ou deduplicação dependerão de necessidade comprovada e decisão própria.

## 4. Modelo de dados e migração

Criar `database/migrations/004_student_status.sql`, executada depois das migrações 001, 002 e 003.

Alteração prevista:

```sql
ALTER TABLE alunos
    ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1;

CREATE INDEX idx_alunos_ativo_nome
    ON alunos (ativo, nome);
```

A migração deverá:

- Preservar todas as linhas e os IDs já existentes.
- Definir os registros existentes como ativos pelo valor padrão.
- Não alterar os campos atuais nem reescrever os dados cadastrais.
- Ser executada uma única vez por ambiente, conforme a sequência documentada.
- Ser validada pela CI em um banco MySQL limpo, com pelo menos um registro legado inserido antes da migração e confirmado como ativo depois dela.

Não criar tabela adicional de status nem alterar a tabela de auditoria: a estrutura atual já aceita os novos códigos de ação.

### Eventos de auditoria

Adicionar os códigos:

| Ação | Entidade | Quando |
|---|---|---|
| `aluno_inativado` | `alunos` | Quando o status muda de ativo para inativo |
| `aluno_reativado` | `alunos` | Quando o status muda de inativo para ativo |

Manter `aluno_criado` e `aluno_editado`. Eventos históricos `aluno_excluido` existentes não serão apagados nem reinterpretados.

## 5. Etapas de implementação

### Etapa 1 — Migração e camada de dados

- [x] Criar `database/migrations/004_student_status.sql` com `ativo TINYINT(1) NOT NULL DEFAULT 1` e o índice `idx_alunos_ativo_nome`.
- [x] Atualizar a CI para executar a migração após 001–003.
- [x] Criar `tests/migration-student-status.sh`: inserir fixture legado antes da migração, conferir ID e dados preservados, confirmar `ativo = 1` nos registros existentes, testar o padrão em novo registro e verificar o índice.
- [x] Validar a execução real do teste em CI (push #268 e PR #269, ambos verdes).
- [x] Ajustar consultas centrais e distribuição de planos para considerar o status.

**Entrega:** banco novo e banco com registros anteriores suportam o status do aluno. A migração e o teste estão no branch; a entrega só será considerada validada após CI verde.

### Etapa 2 — Inativar e reativar

- [x] Endpoint `alterar-status-aluno.php`, exclusivo para POST.
- [x] Autenticação, validação CSRF, ID e ações permitidas.
- [x] Bloqueio de linha com `SELECT ... FOR UPDATE`, atualização condicional e auditoria na mesma transação.
- [x] Idempotência: repetir o estado atual não gera novo evento.
- [x] Interface troca exclusão por inativação/reativação.
- [x] `excluir.php` aposentado com HTTP 410 para qualquer método.
- [ ] Validar todos os cenários novos na CI.

**Entrega:** nenhum fluxo normal da interface apaga alunos fisicamente.

### Etapa 3 — Listagem, pesquisa e filtros

- [x] Incluir situação visível na tabela/lista.
- [x] Mostrar ativos por padrão.
- [x] Adicionar filtro Ativos/Inativos/Todos.
- [x] Pesquisar por nome, e-mail e telefone.
- [x] Preservar os filtros ao abrir a edição, voltar à listagem e salvar o cadastro.
- [x] Preservar os filtros ao inativar/reativar um aluno.
- [ ] Validar todos os cenários de consulta e preservação de contexto na CI.

**Entrega:** equipe localiza registros e administra a situação. A preservação do contexto está implementada e aguarda validação na CI.

### Etapa 4 — Dashboard

- [x] Contar ativos e inativos separadamente.
- [x] Atualizar distribuição por plano para considerar somente ativos.
- [x] Calcular indicadores independentemente da pesquisa e do filtro da lista.
- [ ] Revisar textos acessíveis, estados vazios e capturas visuais após a nova tabela.
- [ ] Validar o dashboard na CI.

**Entrega:** os indicadores refletem claramente a população ativa.

### Etapa 5 — Detalhes do aluno e auditoria

- [x] Criar `detalhes-aluno.php` com ID validado e respostas 400/404.
- [x] Exibir dados atuais, situação e data de cadastro.
- [x] Exibir histórico simples de criação, edição, inativação e reativação, com data e responsável quando disponível; limitar a 50 eventos.
- [x] Oferecer atalhos de edição e ativação/inativação conforme o estado atual.
- [x] Preservar filtros ao retornar à listagem e ao alterar status pelo perfil.
- [x] Adicionar testes HTTP para acesso anônimo, IDs inválidos/inexistentes, dados, eventos e ações pelo perfil.
- [x] Adicionar captura Playwright desktop do perfil.
- [ ] Validar os novos testes na CI e revisar a captura.

**Entrega:** consulta individual e histórico operacional básico sem implementar histórico campo a campo.

### Etapa 6 — Regressão, documentação e revisão visual

- Atualizar testes HTTP e Playwright.
- Atualizar README, roadmap, relatório de testes e este documento com resultados reais.
- Revisar capturas em desktop/mobile, temas claro/escuro, foco por teclado e estados vazios.
- Executar CI de push e PR e inspecionar os resultados.
- Manter a PR em Draft até revisão manual; não fazer merge automaticamente.

## 6. Arquivos e áreas provavelmente afetados

- `database/migrations/004_student_status.sql`
- `index.php`
- `detalhes-aluno.php` (perfil individual e histórico)
- `cadastro.php`
- `salvar.php`
- `excluir.php` (aposentadoria do fluxo destrutivo)
- Novo endpoint de alteração de status.
- Nova página de detalhes do aluno.
- `funcoes.php`, se forem necessárias funções compartilhadas de consulta/status.
- `style.css` e scripts de interface relacionados.
- `tests/integration-crud.sh`
- `tests/capture-screenshots.mjs`
- `.github/workflows/ci.yml`, README, roadmap e relatório de testes.

A lista é indicativa: só alterar os arquivos cuja responsabilidade realmente for afetada. Não criar abstrações genéricas sem necessidade.

## 7. Testes e critérios de aceite

O Bloco 02 só poderá ser marcado como concluído quando os seguintes cenários forem verificados:

### Migração e preservação
- A migração 004 é aplicada depois de 001–003 em MySQL limpo.
- Um aluno inserido antes da migração continua existindo com o mesmo ID/dados e fica ativo.
- Novos registros recebem status ativo por padrão.
- A migração não altera nem apaga registros existentes.

### Autorização e mudança de status
- Visitantes não autenticados não conseguem alterar status.
- GET não altera status; somente POST é aceito.
- CSRF inválido é rejeitado.
- ID/estado inválidos são rejeitados sem alteração.
- Inativação e reativação persistem corretamente.
- A auditoria registra autor, ação, entidade e ID.
- Status e auditoria são confirmados/rollback juntos.
- Repetir a solicitação para o mesmo estado não cria evento duplicado.
- A interface não oferece exclusão física e o endpoint antigo não executa DELETE.

### Listagem e dashboard
- A listagem inicial mostra somente ativos.
- Os filtros Ativos, Inativos e Todos retornam os registros corretos.
- Pesquisa por nome, e-mail e telefone funciona com os filtros.
- Alunos inativos podem ser consultados e editados.
- O dashboard conta ativos/inativos separadamente e distribui planos somente entre ativos.
- Aplicar pesquisa/filtro não modifica os indicadores globais.

### Detalhes e auditoria
- ID inválido retorna erro apropriado e ID inexistente não expõe dados.
- A página exibe os dados e status corretos.
- O histórico mostra criação, edição, inativação e reativação, sem alegar registrar valores antigos/novos.
- O responsável aparece quando existe usuário associado.

### Regressão e interface
- Fluxos atuais de login, sessão, funcionários, CRUD, CSRF e auditoria continuam passando.
- Testes automatizados cobrem desktop/mobile, estados vazio/ativo/inativo, temas e navegação por teclado.
- CI de push e PR passa no commit que será considerado para conclusão.
- Capturas e documentação correspondem ao código efetivamente validado.

## 8. Fora de escopo

- Cadastro e gestão de planos como entidade própria.
- Matrículas/contratos, datas de vigência, renovação e cancelamento.
- Pagamentos, cobranças e inadimplência.
- Frequência, check-in e QR Code.
- Aulas, turmas, instrutores e agenda.
- Histórico de alterações campo a campo.
- Papéis/permissões diferentes para funcionários.
- Exclusão física excepcional de alunos.
- Deduplicação automática ou unicidade de e-mail/telefone.
- Publicação em produção, multiunidade ou multi-tenant.

Esses itens permanecem para blocos posteriores ou decisões específicas.

## 9. Riscos e cuidados

- A migração deve ser aplicada antes do novo código em cada ambiente; executar o código novo sem a coluna `ativo` causará erro nas consultas.
- Indicadores antigos passarem a representar somente ativos deve estar explícito na interface.
- A auditoria é uma trilha simples de eventos, não prova de histórico completo nem substitui backup.
- O projeto segue acadêmico/local. Não usar dados reais nem expor publicamente; preparação para produção pertence ao Bloco 08 e exige revisão independente.

## 10. Critério de encerramento

O bloco será considerado concluído somente quando todos os critérios de aceite forem atendidos, a documentação registrar os resultados observados, a CI estiver verde e a revisão manual de interface estiver concluída. A integração em `main` dependerá de aprovação explícita separada.
