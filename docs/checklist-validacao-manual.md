# Checklist de validação manual — FitControl

**Status geral:** pendente de execução em dispositivo físico.  
**Objetivo:** registrar os testes manuais que ainda precisam ser feitos depois, quando houver acesso a um celular e tempo para uma conferência completa.  
**Importante:** os itens abaixo não foram executados nesta etapa. Os testes automatizados da CI em Chromium, Firefox e WebKit com viewports simulados não substituem esta validação física.

## 1. Preparação do ambiente

- [ ] Atualizar a cópia local para o commit mais recente da branch que está sendo avaliada.
- [ ] Executar o projeto somente em ambiente local/de desenvolvimento (por exemplo, XAMPP).
- [ ] Usar exclusivamente contas e alunos fictícios; não cadastrar dados pessoais ou dados reais de uma academia.
- [ ] Confirmar que o banco local está atualizado com as migrações necessárias.
- [ ] Anotar sistema operacional, modelo do aparelho, navegador e versão.
- [ ] Anotar a data da validação e manter uma lista dos problemas encontrados.

**Registro do teste**

| Campo | Preencher durante a validação |
|---|---|
| Data | — |
| Aparelho / modelo | — |
| Sistema operacional / versão | — |
| Navegador / versão | — |
| Resolução ou orientação | — |
| Resultado geral | Pendente |

## 2. Celular real — layout e navegação

- [ ] Abrir o login no celular e conferir se o conteúdo cabe na tela sem cortes.
- [ ] Entrar no dashboard e verificar se cabeçalho, menu, indicadores e gráficos permanecem legíveis.
- [ ] Abrir e fechar a navegação mobile; confirmar que todos os atalhos podem ser alcançados.
- [ ] Deslizar a navegação horizontal até o último atalho e confirmar que o movimento é previsível.
- [ ] Na listagem de alunos, testar a rolagem horizontal da tabela com o dedo.
- [ ] Confirmar que a rolagem da tabela não desloca a página inteira de forma inesperada.
- [ ] Abrir o cadastro/edição e conferir se campos, mensagens e botões não ficam cortados.
- [ ] Abrir o perfil de um aluno e conferir dados, histórico e ações.
- [ ] Repetir os fluxos principais em orientação retrato e paisagem.
- [ ] Verificar o comportamento com a barra do navegador visível e após ela recolher.
- [ ] Testar zoom do navegador (até 200%, quando disponível) e confirmar que ainda é possível ler e operar as telas sem perda de conteúdo essencial.

## 3. Toque e interação

- [ ] Tocar em todos os itens principais do menu e confirmar que abrem a tela correta.
- [ ] Testar botões de cadastrar, salvar, voltar, pesquisar, filtrar, editar, inativar e reativar.
- [ ] Confirmar que os alvos de toque são confortáveis e não exigem precisão excessiva.
- [ ] Verificar se tocar rapidamente duas vezes em uma ação não provoca duplicação indevida.
- [ ] Confirmar que campos de texto recebem foco e que o teclado virtual não esconde o campo ativo nem o botão de envio.
- [ ] Verificar se links de telefone e e-mail do perfil abrem os aplicativos apropriados, quando configurados no aparelho.

## 4. Legibilidade e temas

- [ ] Conferir textos pequenos, rótulos, indicadores, gráficos e mensagens no tema claro.
- [ ] Repetir a conferência no tema escuro.
- [ ] Verificar se textos secundários, bordas, estados desabilitados e foco continuam distinguíveis nos dois temas.
- [ ] Confirmar que a preferência de tema permanece após navegar entre dashboard, listagem, perfil e configurações.
- [ ] Conferir se não há sobreposição, corte, quebra estranha de palavras ou conteúdo encoberto.
- [ ] Avaliar se mensagens de sucesso, erro e estado vazio são fáceis de localizar e compreender.

## 5. Login, senha e sessão

Use uma conta fictícia de teste. Não registre nem compartilhe senhas temporárias ou pessoais nas evidências.

- [ ] Tentar login com credenciais inválidas e confirmar que a mensagem é compreensível e não revela se a conta existe.
- [ ] Entrar com uma conta de primeiro acesso e confirmar o direcionamento para a troca obrigatória da senha.
- [ ] Conferir no celular se os campos de senha, confirmação e mensagens de validação são utilizáveis.
- [ ] Alterar a senha voluntariamente pela área de segurança e confirmar a mensagem de sucesso.
- [ ] Fazer logout e confirmar que o usuário retorna à tela de login.
- [ ] Usar voltar/avançar do navegador depois do logout e confirmar que páginas protegidas não ficam acessíveis.
- [ ] Deixar a sessão sem atividade e observar o aviso antecipado de expiração e o encerramento ao atingir o limite.
- [ ] Quando o aviso permitir, tocar em **Continuar conectado** e confirmar que a sessão é renovada sem retornar ao login.
- [ ] Conferir que uma sessão de conta desativada deixa de acessar as áreas protegidas, quando esse cenário puder ser preparado com segurança.
- [ ] Não tentar provocar bloqueios repetidos de login em conta real; para validar o rate limit, usar apenas conta fictícia e respeitar o intervalo de bloqueio.

> A expiração por inatividade e o limite absoluto de duração exigem tempo de observação. Não marcar esses itens como aprovados apenas porque os avisos aparecem nas capturas da CI.

## 6. Dashboard, alunos e estados vazios

- [ ] Conferir se os indicadores do dashboard correspondem aos registros fictícios existentes.
- [ ] Abrir a listagem e pesquisar por nome, e-mail e telefone.
- [ ] Aplicar os filtros **Ativos**, **Inativos** e **Todos** e conferir os resultados.
- [ ] Fazer uma pesquisa sem correspondência e conferir a mensagem de estado vazio e o caminho para limpar a pesquisa.
- [ ] Exibir o filtro de inativos sem registros e conferir a mensagem e o caminho para voltar à listagem completa.
- [ ] Cadastrar um aluno fictício e confirmar que ele aparece na listagem e nos indicadores esperados.
- [ ] Editar o aluno e conferir se os dados atualizados aparecem na listagem e no perfil.
- [ ] Inativar e reativar o aluno pela listagem; conferir a mudança de status.
- [ ] Repetir inativação/reativação pelo perfil individual.
- [ ] Confirmar que a busca e o filtro continuam coerentes ao voltar, editar, salvar e alterar o status.
- [ ] Abrir um perfil sem histórico e conferir a mensagem de histórico vazio.
- [ ] Abrir um perfil com histórico e verificar ordem, data/hora, tipo de evento e funcionário responsável.
- [ ] Conferir os links de contato do perfil (telefone e e-mail).

## 7. Gestão de funcionários

- [ ] Abrir a área de funcionários e conferir a legibilidade da listagem no celular.
- [ ] Criar uma conta fictícia e observar se a senha temporária é apresentada uma única vez.
- [ ] Confirmar o fluxo de primeiro acesso dessa conta fictícia e a troca obrigatória da senha.
- [ ] Desativar e reativar uma conta de teste, verificando o estado exibido.
- [ ] Confirmar que a própria conta não pode ser desativada.
- [ ] Confirmar que o sistema não permite desativar a última conta ativa.
- [ ] Verificar se a interface explica claramente o resultado de cada operação.

## 8. Teclado, foco e recursos de acessibilidade

- [ ] Em um computador ou com teclado físico conectado ao celular, percorrer login, menu, listagem, formulários e perfil usando Tab e Shift+Tab.
- [ ] Confirmar que o foco permanece visível e segue uma ordem lógica.
- [ ] Acionar links e botões usando Enter ou Espaço, conforme o tipo de controle.
- [ ] Verificar se rótulos e mensagens de erro são associados aos campos correspondentes.
- [ ] No Android, testar opcionalmente com TalkBack; no iOS, com VoiceOver, caso haja aparelho disponível.
- [ ] Conferir se nome e estado dos controles são anunciados de forma compreensível pelo leitor de tela.
- [ ] Verificar se nenhuma informação depende exclusivamente de cor para ser entendida.

## 9. Navegador adicional em dispositivo real

- [ ] Repetir login, dashboard, listagem, perfil e formulários em outro navegador instalado no aparelho, se disponível.
- [ ] Registrar diferenças de fonte, espaçamento, rolagem, teclado virtual, foco e comportamento dos controles.
- [ ] Não considerar Playwright/WebKit emulado como substituto de Safari instalado no iPhone ou de um navegador real no celular.

## 10. Bloco 03 — planos, matrículas e revisão de legados

Execute esta seção somente depois da aplicação local das migrations 005/006 pelo procedimento em `docs/guia-migrations-xampp.md`, com backup conferido e exclusivamente com alunos/funcionários fictícios.

### Banco e migração

- [ ] Registrar versão e distribuição retornadas por `SELECT VERSION(), @@version_comment`.
- [ ] Confirmar InnoDB em `alunos`, `usuarios`, `auditoria` e nas cinco tabelas novas.
- [ ] Conferir as definições com `SHOW CREATE TABLE` e verificar FKs/índices.
- [ ] Confirmar que todos os alunos até o corte em `controle_importacao_legado` possuem exatamente uma revisão.
- [ ] Conferir que `plano_texto_original` corresponde byte a byte ao valor original de `alunos.plano`.
- [ ] Confirmar que a carga inicial não criou matrículas nem decisões humanas fictícias.
- [ ] Registrar contagens antes/depois e guardar evidências sem dados pessoais.
- [ ] Não reexecutar a migration 005. A 006 só pode ser reexecutada conforme o guia e depois de confirmar o estado da carga.

### Catálogo e contratação

- [ ] Criar um rascunho incompleto e confirmar que não pode ser ativado.
- [ ] Completar dados e preço, ativar e conferir situação/auditoria.
- [ ] Inativar e reativar um plano válido.
- [ ] Confirmar que plano vinculado a matrícula não pode ser excluído.
- [ ] Tentar contratar com plano rascunho/inativo e confirmar bloqueio.
- [ ] Contratar com plano ativo para aluno fictício.
- [ ] Conferir snapshot de nome, preço e duração após alterar os dados atuais do catálogo.
- [ ] Testar início hoje sem horário, hoje com horário futuro e data futura sem horário.
- [ ] Confirmar rejeição de início no passado e de sobreposição de períodos.
- [ ] Confirmar que aluno inativo ou com revisão pendente não pode receber nova matrícula.

### Histórico, revisão e retificação

- [ ] Conferir estados derivados `AGENDADA`, `ATIVA`, `VENCIDA` e `CANCELADA` em dados fictícios com datas controladas.
- [ ] Cancelar uma matrícula agendada e outra ativa, conferindo motivo opcional, funcionário e horário.
- [ ] Confirmar bloqueio de cancelamento repetido e de matrícula vencida.
- [ ] Abrir a fila de revisão e conferir busca, filtros e pendentes por padrão.
- [ ] Decidir um caso como `LEGADO_SEM_VIGENCIA` com evidência de teste e confirmar liberação de nova matrícula.
- [ ] Decidir outro caso como `VINCULO_CONFIRMADO`, informando intervalo exato; conferir criação/associação da matrícula `LEGADO`.
- [ ] Manter um terceiro caso `PENDENTE_REVISAO` e conferir bloqueio de contratação.
- [ ] Retificar a vigência de um vínculo confirmado com nova fonte e justificativa.
- [ ] Conferir no histórico os instantes anterior e novo, o funcionário, a fonte e as observações.
- [ ] Repetir exatamente a mesma retificação e confirmar que não duplica evento/auditoria.
- [ ] Tentar intervalo inválido ou sobreposto e confirmar rollback sem alteração parcial.
- [ ] Reabrir/reavaliar revisão e confirmar que decisões antigas continuam visíveis.

### Responsividade do Bloco 03

- [ ] Repetir catálogo, contratação, cancelamento, revisão e retificação em celular real.
- [ ] Conferir formulários, campos de data/hora, teclado virtual, botões e mensagens.
- [ ] Verificar tabelas/históricos sem rolagem horizontal inesperada na página.
- [ ] Testar foco por teclado e leitura de rótulos nos formulários de revisão e retificação.

**Registro específico do Bloco 03**

| Campo | Preencher durante a validação |
|---|---|
| Versão do banco | — |
| Backup conferido | Pendente |
| Migrations 005/006 | Pendente |
| Contagens/constraints | Pendente |
| Fluxos funcionais | Pendente |
| Dispositivo/navegador | — |
| Resultado | Pendente |

## 11. Encerramento e registro de defeitos

Para cada problema encontrado, registrar:
- tela e caminho para reproduzir;
- aparelho, sistema operacional e navegador;
- passos executados;
- resultado esperado e resultado observado;
- captura de tela sem dados pessoais, quando útil;
- gravidade e se impede a continuidade do fluxo.

**Resultado final da inspeção:** manter como pendente até que os itens aplicáveis tenham sido executados. Itens não testados devem permanecer desmarcados; não presumir aprovação.

### Critério para concluir

A revisão manual pode ser considerada concluída quando:
1. os fluxos principais forem exercitados em ao menos um celular real;
2. login, dashboard, gestão de alunos, perfil e navegação forem utilizáveis por toque;
3. os temas claro/escuro e a legibilidade forem conferidos;
4. os itens de sessão e acessibilidade disponíveis forem verificados ou tiverem suas limitações registradas;
5. os problemas encontrados forem corrigidos ou explicitamente aceitos e documentados.

Esta checklist é uma validação exploratória do projeto acadêmico/local. Ela não substitui auditoria independente de segurança, testes completos de acessibilidade, avaliação de privacidade/LGPD ou preparação para produção.
