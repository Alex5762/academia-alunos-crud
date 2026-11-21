# Bloco 03 — Planos e matrículas

> **Estado: pesquisa e proposta técnica.** Este documento registra o estudo inicial e recomendações para o Bloco 03. As decisões marcadas como propostas ainda precisam ser validadas; nenhuma tabela, migration, tela ou fluxo deste bloco foi implementado por este documento.

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

Essa separação evita que a edição do preço ou do nome de um plano altere retroativamente o histórico dos alunos.

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
| `ativo` | Indica se o plano pode receber novas matrículas. |
| `created_at` / `updated_at` | Datas de criação e atualização. |

Regras aprovadas e propostas:

- **Decisão aprovada:** um plano pode ser salvo como rascunho enquanto seu cadastro estiver incompleto.
- Para ser ativado e disponibilizado para novas matrículas, o plano deve ter todos os campos obrigatórios preenchidos, duração válida e preço definido maior que zero.
- Planos em rascunho ou inativos não aparecem como opção para novas matrículas.
- Um plano sem preço, com preço zero ou com dados obrigatórios incompletos não pode ser ativado nem contratado.
- Não preencher preço automaticamente e não criar preços fictícios para os planos atuais.
- Alterar o preço do catálogo afeta somente novas matrículas; matrículas existentes preservam o valor contratado.
- **Decisão aprovada:** um plano que ainda não possua nenhuma matrícula vinculada pode ser excluído fisicamente.
- Se existir ao menos uma matrícula vinculada ao plano, a exclusão física é proibida; o plano deve ser desativado.
- Planos desativados permanecem consultáveis no histórico e continuam identificando as matrículas antigas, mas não podem ser selecionados em novas contratações.
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
| `valor_contratado` | Valor registrado para aquela matrícula, independente do preço atual do catálogo. |
| `duracao_contratada_valor` / `duracao_contratada_unidade` | Cópia da duração contratada. |
| `inicio_em` | Início efetivo da vigência (`DATETIME`), incluindo horário para permitir início após cancelamento intradiário. |
| `fim_exclusivo_em` | Limite final exclusivo (`DATETIME`): a matrícula deixa de valer exatamente neste instante. Para vigência normal, corresponde à meia-noite seguinte ao último dia de calendário inclusivo. |
| `dia_referencia_calendario` | Dia original do mês usado no cálculo de durações mensais e preservado nas renovações contínuas. |
| `cancelada_em` | Instante exato em que o cancelamento produziu efeito, quando aplicável. |
| `situacao` | Estado operacional ou de revisão da matrícula. |
| `origem` | Distingue registros migrados dos criados pelo fluxo normal. |
| `cancelada_em` | Momento do cancelamento, quando aplicável. |
| `motivo_cancelamento` | Justificativa opcional, conforme regra a definir. |
| `created_at` | Momento em que o registro foi criado. |

Regras propostas:

- Matrículas novas exigem aluno, plano ativo, valor, duração, início e término válidos.
- O registro guarda uma cópia das condições contratadas. A edição posterior do catálogo não altera matrículas existentes.
- Matrículas antigas não são apagadas ao renovar ou cancelar.
- O relacionamento com aluno e plano deve usar chaves estrangeiras. A exclusão física de planos com histórico deve ser restringida; a desativação é o fluxo normal.
- O projeto já utiliza inativação lógica para alunos. A matrícula deve continuar consultável mesmo que o cadastro do aluno seja inativado.
- Uma matrícula não deve ser excluída fisicamente por uma ação comum da interface.

### 4.3 Separar situação do aluno e situação da matrícula

`alunos.ativo` descreve o cadastro da pessoa. A situação da matrícula descreve o contrato e sua vigência. São dimensões independentes.

Uma primeira proposta para a matrícula:

| Situação | Interpretação |
|---|---|
| `AGENDADA` | O início ainda está no futuro. |
| `ATIVA` | Está dentro do período de vigência e não foi cancelada. |
| `VENCIDA` | A data final já passou. |
| `CANCELADA` | O cancelamento foi registrado. |
| `PENDENTE_REVISAO` | Registro legado sem dados suficientes para determinar a vigência ou as condições. |

A decisão entre persistir todos esses estados ou derivar `AGENDADA`, `ATIVA` e `VENCIDA` a partir das datas deve ser tomada na modelagem final. Recomenda-se evitar estados derivados que precisem ser atualizados manualmente e possam ficar inconsistentes. `CANCELADA` e `PENDENTE_REVISAO` representam condições que não podem ser inferidas somente das datas.

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

**Decisão aprovada:** a data final apresentada ao usuário é inclusiva. O aluno permanece coberto durante todo o último dia de calendário, exceto quando a matrícula é cancelada antes disso; nesse caso, o cancelamento encerra a vigência no instante exato registrado.

Para suportar cancelamento e nova matrícula no mesmo dia, a recomendação técnica aprovada é armazenar os limites de vigência como instantes:

- `inicio_em DATETIME`: instante em que a matrícula passa a valer.
- `fim_exclusivo_em DATETIME`: instante em que deixa de valer. Esse instante não faz parte do período.
- `cancelada_em DATETIME`: instante efetivo do cancelamento, quando houver.

O intervalo efetivo será tratado como semiaberto: `[inicio_em, fim_exclusivo_em)`. Se houver cancelamento, o limite efetivo será o menor entre `fim_exclusivo_em` e `cancelada_em`. Assim, uma matrícula pode terminar às 14:00 e a seguinte começar exatamente às 14:00, sem sobreposição. A data final inclusiva exibida na interface será derivada do limite exclusivo da vigência normal; matrículas canceladas também exibirão o instante exato do cancelamento.

Regra de calendário aprovada para durações em meses:

- Tratar mês como unidade de calendário, nunca converter automaticamente um mês em 30 dias.
- Calcular a próxima data-limite avançando a quantidade de meses contratada a partir do dia de referência.
- Se o mês de destino não possuir o dia de referência (por exemplo, dia 29, 30 ou 31 em fevereiro), usar o último dia válido daquele mês.
- Para uma matrícula contratada por dias de calendário, o limite exclusivo normal é a meia-noite seguinte ao último dia inclusivo calculado.
- Preservar o dia de referência original nas renovações contínuas, mesmo quando ele precisou ser ajustado em um mês curto. Isso evita que uma matrícula iniciada no dia 29, 30 ou 31 passe a vencer permanentemente em outro dia depois de fevereiro.
- Guardar `dia_referencia_calendario` na matrícula e copiá-lo para renovações contínuas. Uma nova contratação sem vínculo de renovação usa o dia da sua própria data inicial.

Exemplos para matrículas iniciadas à meia-noite:

| Início / dia de referência | Próximo limite de ciclo | Último dia incluído |
|---|---|---|
| 01/11, referência 1 | 01/12 às 00:00 | 30/11 |
| 31/01, referência 31, ano não bissexto | 28/02 às 00:00 | 27/02 |
| 31/01, referência 31, ano bissexto | 29/02 às 00:00 | 28/02 |
| Renovação contínua em 28/02, preservando referência 31 | 31/03 às 00:00 | 30/03 |

Em cancelamento intradiário, o limite efetivo passa a ser o próprio `cancelada_em`, por exemplo 21/11 às 14:00. Uma nova matrícula pode começar nesse mesmo instante ou depois, nunca antes.

Para durações em dias ou semanas, calcular conforme a unidade contratada e aplicar a mesma convenção de último dia inclusivo e limite exclusivo.

- Usar `DATETIME` para os instantes de vigência e de cancelamento.
- Definir um único fuso horário da academia na configuração da aplicação e usá-lo em todos os cálculos, gravações e exibições. Não depender do fuso padrão do servidor ou da sessão do banco.
- Não usar uma data fictícia para preencher informações desconhecidas.
- Testar virada de mês, fevereiro, ano bissexto, horário do cancelamento, início no mesmo instante do encerramento anterior e sequências de renovações.
- Centralizar o cálculo em uma função de domínio, coberta por testes determinísticos; não espalhar a regra entre telas ou consultas.

### 5.3 Renovação e histórico

**Decisão aprovada para renovação antecipada:** a nova matrícula começa no dia seguinte ao último dia da matrícula vigente. A renovação não consome dias que já foram contratados e não cria sobreposição com a matrícula anterior.

Regras:

- Renovar significa criar uma nova matrícula, não sobrescrever a anterior.
- O histórico deve manter o plano, o preço, a duração e o período de cada contratação.
- Se a matrícula vigente termina em 30/11, por exemplo, a renovação começa em 01/12.
- O sistema deve calcular a data de início da renovação a partir do limite seguinte do período e do dia de referência preservado, sem depender do dia em que o funcionário realizou a operação.
- A renovação contínua copia o dia de referência da matrícula anterior, inclusive quando a data anterior foi ajustada por fevereiro ou outro mês curto.
- Essa regra cobre a renovação contínua padrão, sem intervalo.
- **Decisão aprovada:** o funcionário também pode escolher manualmente uma data de início futura para uma nova matrícula, criando um intervalo entre contratos quando necessário. A data escolhida deve ser hoje ou posterior, ser válida para o calendário e não provocar sobreposição com qualquer outra matrícula não cancelada do mesmo aluno.
- O intervalo é permitido, não obrigatório. A renovação contínua continua usando automaticamente o dia seguinte ao último dia incluído da matrícula anterior.
- Uma matrícula futura pode ocupar qualquer intervalo livre entre períodos já agendados, desde que não se sobreponha a eles; não é obrigatório colocá-la sempre depois da última matrícula agendada.
- A nova matrícula continua sujeita à regra aprovada de não sobreposição de períodos.

### 5.4 Cancelamento e inativação

**Decisão aprovada:** o cancelamento tem efeito imediato no momento em que é confirmado pelo sistema. A matrícula permanece no histórico e não é apagada.

Regras aprovadas:

- O sistema registra o instante efetivo do cancelamento em `cancelada_em`, com precisão de data e horário (`DATETIME`).
- O evento deve identificar o funcionário responsável pela operação, usando o mecanismo de auditoria já existente no projeto.
- O motivo do cancelamento pode ser registrado como justificativa opcional; não deve ser obrigatório para concluir a operação.
- Depois do cancelamento efetivo, a matrícula deixa de representar uma vigência ativa.
- **Decisão aprovada:** uma nova matrícula pode começar no mesmo dia e exatamente no instante do cancelamento, ou em instante posterior. Não pode começar antes desse instante nem retroagir sobre período utilizado.
- O início e o fim precisam ser comparados como instantes (`DATETIME`), não apenas como datas, para permitir essa transição intradiária sem sobreposição.
- A consulta histórica deve continuar exibindo a matrícula cancelada, seu período originalmente contratado, o momento efetivo do cancelamento e o responsável.
- Cancelar uma matrícula não apaga nem altera os dados cadastrais do aluno.
- Inativar o cadastro do aluno não cancela automaticamente suas matrículas; são ações diferentes e auditadas separadamente.

Trancamento, suspensão e congelamento ficam fora da primeira versão: exigem regras próprias para acesso, prazo, extensão de vigência e eventual cobrança.

### 5.5 Uma ou várias matrículas simultâneas

**Decisão aprovada:** um aluno pode possuir várias matrículas no histórico, mas não pode ter duas matrículas não canceladas com períodos de vigência sobrepostos na primeira versão.

Regras aprovadas:

- Permitir que o funcionário escolha uma data de início para uma nova matrícula, desde que seja hoje ou uma data futura válida.
- Permitir intervalos livres entre contratos; a data escolhida pode ocupar um espaço entre matrículas já agendadas, desde que não haja sobreposição.
- Não permitir que o período de uma nova matrícula se sobreponha ao de qualquer outra matrícula não cancelada do mesmo aluno, seja vigente ou futura.
- A regra de renovação contínua permanece automática: início no dia seguinte ao último dia incluído da matrícula vigente, salvo quando o funcionário optar por agendar outro início futuro válido.
- Preservar matrículas anteriores no histórico; renovar não significa editar ou substituir o registro anterior.
- Matrículas canceladas permanecem no histórico e não devem bloquear novos períodos depois de efetivamente canceladas.
- Registros legados sem datas ficam como pendentes de revisão. Como não é possível determinar sua vigência, eles não devem ser tratados automaticamente como períodos livres nem como contratos vigentes; a regra para liberar novas matrículas nesses casos precisa ser definida no tratamento dos legados.

A validação deve ocorrer no servidor e ser protegida contra solicitações concorrentes. Uma consulta simples antes do INSERT não é suficiente: a implementação deverá usar transação e estratégia de bloqueio apropriada, com testes concorrentes para garantir que duas requisições simultâneas não criem períodos incompatíveis.

Com intervalos semiabertos, dois períodos se sobrepõem quando `inicio_A < fim_efetivo_B` e `inicio_B < fim_efetivo_A`. Se o início do novo contrato for exatamente igual ao limite efetivo do anterior, os períodos são adjacentes e válidos, não sobrepostos.

## 6. Migração dos alunos existentes

A migração é o ponto de maior risco do bloco, porque os registros atuais guardam apenas o texto de `alunos.plano`.

**Decisão aprovada para alunos legados:** os registros antigos continuam acessíveis para consulta, mas um aluno com vínculo legado pendente de revisão não pode receber nova matrícula até que o vínculo anterior seja regularizado.

Estratégia de migração e regularização:

1. Preservar integralmente a coluna `alunos.plano` durante a transição.
2. Criar as novas tabelas sem exigir que dados históricos desconhecidos sejam preenchidos.
3. Para cada aluno existente, criar um registro de histórico legado, mantendo o texto original em `plano_nome_contratado`.
4. Marcar a origem como migração legada e a situação como `PENDENTE_REVISAO` quando faltarem datas, valor ou duração confiáveis.
5. Deixar `plano_id` nulo quando não houver correspondência inequívoca com um plano do catálogo.
6. Manter preço, início, término e duração desconhecidos como `NULL`; nunca inferir preço, datas ou vigência.
7. Não criar planos de catálogo com dados inventados apenas para satisfazer o relacionamento.
8. Antes de liberar uma nova matrícula, um funcionário deve regularizar o vínculo legado com informações reais e verificáveis: plano/condições contratadas, valor e período, quando esses dados puderem ser confirmados.
9. A regularização deve registrar quem a realizou, quando ocorreu e quais informações foram complementadas, por meio da auditoria. O texto original importado deve permanecer preservado para consulta.
10. Se não for possível confirmar as condições históricas, o vínculo permanece pendente e novas matrículas continuam bloqueadas. Não usar estimativas ou valores padrão para contornar a pendência.
11. Depois da migração, comparar a quantidade de alunos antes/depois, conferir IDs e texto original, identificar duplicações e executar o teste novamente para verificar a proteção contra reprocessamento.

A coluna antiga será mantida durante a transição. Sua remoção só poderá ser considerada em uma migration posterior, depois de todos os leitores e escritores terem sido atualizados e a preservação dos dados ter sido verificada.

## 7. Integridade, transações e segurança

- Usar InnoDB nas tabelas envolvidas, para que operações relacionadas possam ser transacionais.
- Criar chaves estrangeiras para aluno e plano; avaliar `ON DELETE RESTRICT` para preservar histórico.
- Usar `CHECK` para condições simples quando a versão efetivamente instalada suportar e aplicar a restrição. No MySQL, restrições `CHECK` passaram a ser aplicadas a partir da versão 8.0.16; versões anteriores apenas as analisavam e ignoravam.
- Repetir as validações relevantes na aplicação PHP; a validação do navegador não é uma barreira de segurança.
- Usar consultas preparadas via PDO, autenticação, autorização e CSRF nos endpoints de escrita.
- Criar ou renovar uma matrícula em transação; confirmar todas as alterações com `COMMIT` ou desfazer com `ROLLBACK` se qualquer etapa falhar.
- Avaliar `SELECT ... FOR UPDATE` para proteger verificações concorrentes, escolhendo índices e linhas de bloqueio de acordo com a regra final.
- Não confiar somente em validação de interface para impedir matrícula duplicada, plano desativado ou aluno inativo.
- Não introduzir gateway, cobrança automática ou armazenamento de dados de pagamento neste bloco.

## 8. Escopo proposto

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
- Trancamento, suspensão ou congelamento.
- Check-in, frequência, QR Code ou controle de acesso físico.
- Múltiplas modalidades/contratos simultâneos, salvo decisão explícita que amplie o escopo.

## 9. Plano de validação proposto

- [ ] A migration funciona em banco limpo e em banco com alunos legados.
- [ ] Nenhum aluno, ID ou texto original de plano é perdido.
- [ ] Reexecutar a rotina de migração não duplica registros.
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
- [ ] Regularização de legado exige informações reais e verificáveis, registra funcionário/data e preserva o texto original importado.
- [ ] Legado sem informações confiáveis permanece pendente e bloqueia novas matrículas, sem preenchimento por estimativa.
- [ ] Matrícula nova exige todos os dados obrigatórios.
- [ ] Datas inválidas e valores negativos são rejeitados no servidor e, quando suportado, pelo banco.
- [ ] Data final é inclusiva: uma matrícula de 01/11 até 30/11 permanece válida durante todo o dia 30/11.
- [ ] Duração mensal iniciada nos dias 29, 30 e 31 é calculada por mês de calendário, com ajuste para o último dia válido do mês de destino.
- [ ] Ano bissexto é tratado corretamente.
- [ ] Renovações contínuas preservam o dia de referência e não sofrem deriva permanente após fevereiro.
- [ ] Renovação preserva o registro anterior e aplica a regra de calendário definida.
- [ ] Cancelamento encerra imediatamente a vigência, registra instante e funcionário responsável, e preserva o histórico.
- [ ] Motivo de cancelamento é opcional e, quando informado, permanece consultável.
- [ ] Nova matrícula não começa antes do encerramento efetivo de uma matrícula cancelada nem retroage sobre período utilizado.
- [ ] Uma nova matrícula pode começar exatamente no mesmo instante do cancelamento anterior (por exemplo, às 14:00), sem sobreposição.
- [ ] Uma nova matrícula que começa um instante antes do limite efetivo é rejeitada.
- [ ] Períodos adjacentes com `fim_exclusivo_em` igual ao `inicio_em` seguinte são aceitos.
- [ ] Comparações e exibições respeitam o fuso horário configurado da academia.
- [ ] Inativar o aluno não cancela automaticamente suas matrículas.
- [ ] O sistema permite escolher uma data futura válida e não retroativa para nova matrícula.
- [ ] O sistema permite inserir uma matrícula em um intervalo livre entre duas matrículas futuras já agendadas.
- [ ] O sistema rejeita datas inválidas, datas passadas e qualquer período que se sobreponha a uma matrícula não cancelada.
- [ ] Solicitações concorrentes não criam vínculos incompatíveis.
- [ ] Todas as operações de escrita exigem autenticação, autorização e CSRF.
- [ ] Testes automatizados e regressão dos módulos existentes passam.
- [ ] Documentação local e README são atualizados.
- [ ] Revisão humana das telas é registrada separadamente da CI.

## 10. Decisões que precisam ser aprovadas antes da implementação

| Tema | Regra registrada | Situação |
|---|---|---|
| Várias matrículas por aluno | Permitir histórico de várias matrículas, sem sobreposição de períodos não cancelados. | **Aprovada** |
| Matrículas futuras e intervalos | Permitir escolher início hoje ou em data futura válida, inclusive em intervalo entre contratos, desde que não haja sobreposição com matrícula não cancelada. | **Aprovada** |
| Renovação antecipada | Nova matrícula contínua começa no dia seguinte ao último dia incluído da matrícula vigente. | **Aprovada** |
| Data final | Inclusiva; duração em meses usa calendário, ajuste ao último dia válido e preservação do dia de referência nas renovações contínuas. | **Aprovada** |
| Cancelamento | Efeito imediato; preservar histórico, registrar instante, funcionário responsável e motivo opcional. | **Aprovada** |
| Nova matrícula no dia do cancelamento | Permitir início no mesmo instante do cancelamento ou depois, com comparação por horário e sem sobreposição. | **Aprovada** |
| Trancamento/suspensão | Fora do primeiro escopo. | Proposta |
| Legados sem preço e datas | Preservar como pendentes; bloquear novas matrículas até regularização com dados reais e auditados. | **Aprovada** |
| Plano sem preço ou cadastro incompleto | Permitir salvar como rascunho; só ativar e disponibilizar para contratação com dados obrigatórios completos e preço maior que zero. | **Aprovada** |
| Exclusão de plano | Permitir exclusão física somente sem matrículas vinculadas; com histórico, bloquear exclusão e permitir desativação. | **Aprovada** |

As regras aprovadas podem ser usadas como base para a especificação. Os itens pendentes ou propostos ainda precisam ser validados antes da implementação.

## 11. Dependências e limites do projeto

O Bloco 02 já implementou situação cadastral e inativação lógica, mas a inspeção em aparelho físico/navegador instalado continua pendente. A preparação deste documento não encerra formalmente o Bloco 02 e não autoriza merge da PR #1.

Este documento registra planejamento. O Bloco 03 só deve entrar em implementação depois da revisão das regras acima, confirmação dos critérios de aceite e respeito à regra do roadmap de avançar um bloco por vez. Não executar migration nem alterar o esquema por causa desta documentação.

O FitControl permanece um projeto acadêmico/local. Não usar dados reais de alunos nem expor a aplicação publicamente.

## 12. Referências técnicas

Documentação oficial do MySQL consultada para fundamentar as recomendações de modelagem e integridade:

- [Fixed-Point Types — DECIMAL e NUMERIC](https://dev.mysql.com/doc/refman/8.0/en/fixed-point-types.html): valores numéricos exatos, apropriados para dados monetários.
- [Date and Time Data Type Syntax](https://dev.mysql.com/doc/refman/8.0/en/date-and-time-type-syntax.html): diferenças entre `DATE`, `DATETIME` e `TIMESTAMP`.
- [FOREIGN KEY Constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html): integridade referencial e opções de exclusão/atualização.
- [CHECK Constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html): suporte e enforcement a partir do MySQL 8.0.16.
- [START TRANSACTION, COMMIT, and ROLLBACK](https://dev.mysql.com/doc/refman/8.0/en/commit.html): controle transacional.
- [InnoDB Locking Reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html): uso de leituras com bloqueio para proteger operações concorrentes.

A versão de MySQL/MariaDB usada no ambiente local deve ser confirmada antes de definir as restrições finais. A documentação MySQL não substitui a verificação de compatibilidade com a versão real do XAMPP/MariaDB.
