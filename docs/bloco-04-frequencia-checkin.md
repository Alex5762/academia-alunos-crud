# Bloco 04 — Frequência e check-in

> **Estado:** implementação concluída e CI aprovada em MySQL 8.0 e MariaDB 10.11. Restam a aplicação controlada no XAMPP e a inspeção manual em dispositivo físico.
> **Data da validação automatizada:** 22/11/2026.
> **CI:** [#1221 — todos os sete jobs aprovados](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45027184228).

## Objetivo

Registrar a presença dos alunos por meio de check-in manual, consultar o histórico por aluno e período e oferecer indicadores simples de frequência, sem introduzir leitor, QR Code ou integração com catraca.

## Escopo fechado

1. **Check-in manual:** funcionário autenticado seleciona o aluno e confirma a entrada. O horário é obtido exclusivamente pelo servidor; não existe campo para editar data/hora do registro.
2. **Elegibilidade:** somente aluno cadastralmente ativo e com exatamente uma matrícula operacional vigente no instante do check-in pode entrar. Matrícula agendada, vencida ou cancelada não habilita presença.
3. **Duplicidade:** no máximo um check-in por aluno por dia civil no fuso `America/Sao_Paulo`. O mesmo aluno pode retornar em outro dia.
4. **Rastreabilidade:** cada check-in referencia aluno, matrícula vigente e funcionário responsável. A data civil local é persistida separadamente do instante UTC.
5. **Histórico:** consulta de presenças por intervalo de datas e, opcionalmente, por aluno.
6. **Indicadores:** total de entradas no dia e no mês corrente, além da listagem filtrável.
7. **Auditoria:** registrar `checkin_registrado` na cadeia geral, na mesma transação do check-in.

## Regras de integridade

- A camada de domínio bloqueia primeiro a linha do aluno com `SELECT ... FOR UPDATE`, seguindo a estratégia já usada pelos serviços de matrículas. Isso serializa check-ins simultâneos para o mesmo aluno e protege a verificação de elegibilidade.
- A migration cria uma restrição única em `(aluno_id, data_local)`. A regra do serviço não substitui a defesa do banco.
- O serviço consulta a matrícula usando os instantes UTC persistidos em `matriculas`; a comparação é feita no mesmo eixo temporal do contrato.
- `data_local` é calculada pelo servidor a partir do relógio no fuso da academia. `registrado_em_utc` armazena o mesmo instante convertido para UTC.
- Todas as escritas e o evento de auditoria são confirmados ou revertidos juntos.
- A autoria é obtida da sessão autenticada, nunca do formulário.

## Permissões

- `attendance.view`: consultar histórico e indicadores.
- `attendance.register`: registrar entrada.
- Administrador possui todas as permissões. Gestor e Operador podem consultar e registrar check-ins, pois isso faz parte da rotina operacional.

## Fora do escopo

- QR Code, leitor físico, biometria, catraca ou validação por dispositivo.
- Múltiplos check-ins do mesmo aluno no mesmo dia.
- Registro retroativo, agendamento de presença ou edição/exclusão de check-in.
- Cobrança, baixa de pagamento, cálculo de inadimplência ou bloqueio por financeiro.
- Integração com serviços externos e notificações.

## Critérios de aceite

- [x] Migration 009 cria a tabela de check-ins, FKs, índices e unicidade diária.
- [x] Aluno inativo, inexistente ou sem matrícula vigente é recusado; múltiplas matrículas vigentes são tratadas como estado ambíguo.
- [x] Matrícula futura, vencida ou cancelada não habilita check-in.
- [x] Check-in válido registra aluno, matrícula, funcionário, dia local e instante UTC.
- [x] Segunda tentativa do mesmo aluno no mesmo dia é recusada sem novo registro.
- [x] Um novo check-in em outro dia é permitido quando a matrícula continua vigente.
- [x] A auditoria é gravada atomicamente; a cadeia permanece íntegra após os registros e rollback testado.
- [x] Testes cobrem regras, duplicidade, rollback, virada do dia local e MySQL 8.0/MariaDB 10.11.
- [x] A interface oferece registro manual, histórico filtrável e indicadores do dia/mês.
- [x] README, matriz de permissões, roadmap, guia de migrations e PR refletem o estado verdadeiro.
- [ ] Aplicar migrations 005–009 no XAMPP após backup e inspeção da versão/mecanismo do banco.
- [ ] Concluir inspeção manual em dispositivo físico e navegador instalado.

## Relação com os blocos anteriores

As migrations 007 (RBAC) e 008 (integridade da auditoria) são fundações transversais de segurança/governança adicionadas após o desenho original do Bloco 03. Elas não substituem o Bloco 04 funcional deste roadmap, que é Frequência e check-in. A migration 009 é a primeira mudança de esquema específica deste bloco.

## Dependências e limites

- Depende das migrations 005/006 do Bloco 03, pois o check-in precisa apontar para uma matrícula real.
- A CI pode validar a aplicação das migrations e os fluxos automatizados; isso não substitui a aplicação local no XAMPP após backup, inspeção de versão e confirmação de InnoDB.
- O projeto permanece acadêmico/local. Não usar dados reais nem expor a aplicação publicamente.
