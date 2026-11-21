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

Regras propostas:

- Desativar um plano em vez de apagá-lo quando já houver matrículas associadas.
- Um plano desativado permanece visível no histórico, mas não pode ser escolhido em uma nova matrícula.
- Alterar o preço do catálogo afeta somente novas matrículas.
- A aplicação deve validar duração positiva e valor não negativo; um plano sem preço definido não deve ser usado para contratar.
- Não criar automaticamente preços fictícios para os planos atuais.

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
| `data_inicio` / `data_fim` | Período de vigência; valores ausentes nos registros legados não devem ser inventados. |
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
- O valor contratado fica registrado na matrícula.
- Duração também deve ser copiada para a matrícula, para que uma edição posterior do plano não modifique contratos históricos.
- Para dinheiro, usar `DECIMAL` em vez de `FLOAT` ou `DOUBLE`. `DECIMAL(10,2)` é uma candidata inicial, sujeita à validação da faixa de valores necessária ao projeto.
- Não presumir que todo mês possui 30 dias. A unidade de duração deve ser explícita.

### 5.2 Datas

- Usar `DATE` para início e término se a regra comercial trabalhar com dias de calendário.
- Usar `DATETIME` para registrar eventos que exigem data e horário, como criação e cancelamento.
- Não usar uma data fictícia para preencher informações desconhecidas.
- Definir se a data final é inclusiva e qual regra de calendário será usada em casos como uma matrícula iniciada no dia 29, 30 ou 31.
- Testar virada de mês, fevereiro e ano bissexto.
- O cálculo da data final deve ficar centralizado na aplicação, coberto por testes e reproduzível.

### 5.3 Renovação e histórico

- Renovar significa criar uma nova matrícula, não sobrescrever a anterior.
- O histórico deve manter o plano, o preço, a duração e o período de cada contratação.
- Uma renovação antecipada precisa de regra explícita para decidir se começa imediatamente ou no dia seguinte ao término vigente.
- Não permitir sobreposição de períodos sem uma regra de negócio que a justifique.

### 5.4 Cancelamento e inativação

- Cancelar uma matrícula não deve apagar o registro.
- O sistema deve registrar quando ocorreu o cancelamento e, se aprovado, o motivo.
- Ainda precisa ser definido se o cancelamento encerra a vigência imediatamente ou se pode ter efeito futuro.
- Inativar o cadastro do aluno não deve apagar nem cancelar automaticamente suas matrículas.
- Trancamento, suspensão e congelamento ficam fora da primeira versão: exigem regras próprias para acesso, prazo, extensão de vigência e eventual cobrança.

### 5.5 Uma ou várias matrículas simultâneas

Recomendação inicial: impedir períodos sobrepostos para um mesmo aluno na primeira versão, até existir uma necessidade real de múltiplos contratos simultâneos.

Essa regra precisa ser validada como requisito de negócio. Uma verificação feita apenas com uma consulta simples pode sofrer corrida entre solicitações concorrentes; a implementação deve proteger a operação com transação e estratégia de bloqueio apropriada, além de testes concorrentes.

## 6. Migração dos alunos existentes

A migração é o ponto de maior risco do bloco, porque os registros atuais guardam apenas o texto de `alunos.plano`.

Estratégia recomendada:

1. Preservar integralmente a coluna `alunos.plano` durante a transição.
2. Criar as novas tabelas sem exigir que dados históricos desconhecidos sejam preenchidos.
3. Para cada aluno existente, criar um registro de histórico legado, mantendo o texto original em `plano_nome_contratado`.
4. Marcar sua origem como migração legada e sua situação como pendente de revisão quando faltarem datas, valor ou duração confiáveis.
5. Deixar `plano_id` nulo quando não houver correspondência inequívoca com um plano do catálogo.
6. Manter preço, início, término e duração desconhecidos como `NULL`; nunca inferir preço ou vigência.
7. Não criar planos de catálogo com dados inventados apenas para satisfazer o relacionamento.
8. Depois da migração, comparar a quantidade de alunos antes/depois, conferir IDs e texto original, identificar duplicações e executar o teste novamente para verificar a proteção contra reprocessamento.

A estratégia definitiva deve decidir se a coluna antiga será mantida por um período de compatibilidade ou removida em uma migration posterior, somente depois de todos os leitores e escritores terem sido atualizados.

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
- [ ] Planos desativados não aparecem para novas matrículas.
- [ ] Alterar o preço de um plano não altera matrículas antigas.
- [ ] Aluno inativo não recebe nova matrícula.
- [ ] Matrícula nova exige todos os dados obrigatórios.
- [ ] Datas inválidas e valores negativos são rejeitados no servidor e, quando suportado, pelo banco.
- [ ] Renovação preserva o registro anterior e aplica a regra de calendário definida.
- [ ] Cancelamento preserva o histórico e respeita a regra de efeito aprovada.
- [ ] Solicitações concorrentes não criam vínculos incompatíveis.
- [ ] Todas as operações de escrita exigem autenticação, autorização e CSRF.
- [ ] Testes automatizados e regressão dos módulos existentes passam.
- [ ] Documentação local e README são atualizados.
- [ ] Revisão humana das telas é registrada separadamente da CI.

## 10. Decisões que precisam ser aprovadas antes da implementação

| Tema | Recomendação inicial | Situação |
|---|---|---|
| Vários planos simultâneos por aluno | Não permitir sobreposição na primeira versão. | Pendente |
| Renovação antecipada | Iniciar no dia seguinte ao fim da matrícula vigente. | Pendente |
| Data final | Inclusiva; definir regra de calendário para fins de mês. | Pendente |
| Cancelamento | Registrar evento e preservar o contrato; definir efeito na vigência. | Pendente |
| Trancamento/suspensão | Fora do primeiro escopo. | Pendente |
| Legados sem preço e datas | Preservar como pendentes de revisão, sem inventar dados. | Pendente |
| Plano sem preço definido | Não permitir nova contratação até completar o cadastro. | Pendente |
| Exclusão de plano | Desativar; impedir exclusão quando houver histórico. | Pendente |

Estas recomendações são uma base para discussão, não decisões de negócio já aprovadas.

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
