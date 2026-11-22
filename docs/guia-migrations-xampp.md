# Procedimento local — migrations 005 a 009 (FitControl)

> **Escopo:** ambiente acadêmico/local com XAMPP. Este procedimento não autoriza uso em produção nem aplicação automática em banco com dados reais. A CI está configurada para validar as migrations 005, 006, 007, 008 e 009 em MySQL 8.0 e MariaDB 10.11; isso não identifica nem valida a versão instalada no computador.

## 1. Antes de começar

1. Confirme que está na branch `feat/dashboard-portfolio` e que a árvore de trabalho está atualizada.
2. Confirme que o projeto é o FitControl local e que o banco selecionado é `academia_alunos`.
3. Não prossiga se houver dúvida sobre qual banco está conectado.
4. Confirme que as migrations 001–004 já foram aplicadas. Não execute novamente migrations antigas às cegas: a 004 contém `ALTER TABLE` e não é uma operação de reexecução livre.
5. Confirme que as tabelas `alunos`, `usuarios` e `auditoria` usam InnoDB. O bloqueio de linhas e a atomicidade dependem desse mecanismo.

Abra o **Prompt de Comando (CMD)** do Windows e entre na pasta do projeto:

```bat
cd /d C:\xampp\htdocs\academia-alunos-crud
```

Se o projeto estiver em outro local, ajuste o caminho. Os comandos abaixo usam os executáveis padrão do XAMPP.

## 2. Inspecionar a versão e o mecanismo do banco

Execute:

```bat
"C:\xampp\mysql\bin\mysql.exe" --host=127.0.0.1 --port=3306 --user=root academia_alunos -e "SELECT VERSION() AS versao, @@version_comment AS distribuicao; SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('alunos','usuarios','auditoria');"
```

Se o usuário root tiver senha, acrescente `--password` ao comando; o cliente solicitará a senha. Não coloque a senha diretamente em arquivos ou no repositório.

**Interrompa** se alguma tabela-base não existir, se alguma das tabelas acima não usar InnoDB ou se a versão/mecanismo não corresponder ao ambiente que você pretende validar. A CI cobre MySQL 8.0 e MariaDB 10.11, mas não substitui essa inspeção local.

Para conferir se as colunas de autenticação e status esperadas estão presentes:

```sql
SHOW CREATE TABLE alunos;
SHOW CREATE TABLE usuarios;
SHOW CREATE TABLE auditoria;
```

Verifique também se `alunos.ativo` existe antes de continuar.

## 3. Criar e conferir o backup

Crie uma pasta fora de `htdocs`, para que o arquivo SQL não fique acessível pelo servidor web:

```bat
mkdir C:\xampp\backups
```

Gere o backup completo:

```bat
"C:\xampp\mysql\bin\mysqldump.exe" --host=127.0.0.1 --port=3306 --user=root --single-transaction --routines --triggers --events academia_alunos > "C:\xampp\backups\academia_alunos_pre_bloco03.sql"
```

Se houver senha no root, acrescente `--password` e informe-a quando solicitado.

Confira se o arquivo foi criado e se não está vazio:

```bat
dir C:\xampp\backups\academia_alunos_pre_bloco03.sql
```

O backup só é útil se puder ser lido/restaurado. Para uma validação rigorosa, restaure-o em um banco descartável separado antes de alterar o banco de trabalho. Não teste a restauração sobre o banco original.

## 4. Aplicar as migrations na ordem correta

**Atenção:** as migrations 005, 007, 008 e 009 criam/alteram estrutura (DDL). MySQL/MariaDB podem confirmar instruções DDL implicitamente; não conte com rollback transacional de toda a migration. Execute cada uma somente uma vez, após o backup.

No CMD, a partir da raiz do projeto:

```bat
"C:\xampp\mysql\bin\mysql.exe" --host=127.0.0.1 --port=3306 --user=root academia_alunos < database\migrations\005_plans_enrollments_schema.sql
```

Se o comando terminar sem erro, execute a carga inicial:

```bat
"C:\xampp\mysql\bin\mysql.exe" --host=127.0.0.1 --port=3306 --user=root academia_alunos < database\migrations\006_import_legacy_review.sql
```

Depois da 006, aplique os perfis de acesso:

```bat
"C:\xampp\mysql\bin\mysql.exe" --host=127.0.0.1 --port=3306 --user=root academia_alunos < database\migrations\007_administrative_roles.sql
```

A migration 007 preserva o acesso das contas existentes atribuindo-lhes `ADMINISTRADOR`. Contas criadas pela interface passam a receber `OPERADOR`; o provisionamento inicial via CLI cria um administrador. Revise os perfis depois da migração e reduza os acessos conforme a responsabilidade de cada funcionário.

Por último, aplique a migration 008, que cria o estado da cadeia SHA-256 e os gatilhos append-only:

```bat
"C:\\xampp\\mysql\\bin\\mysql.exe" --host=127.0.0.1 --port=3306 --user=root academia_alunos < database\\migrations\\008_audit_integrity_chain.sql
```

Depois de confirmar que a migration terminou sem erro, inicialize o marco dos eventos históricos com o PHP CLI do XAMPP:

```bat
"C:\\xampp\\php\\php.exe" scripts\\inicializar-integridade-auditoria.php
```

O comando calcula um marco SHA-256 sobre os eventos já existentes e é idempotente: uma segunda execução não substitui o marco. **A aplicação não deve voltar a receber tráfego entre a migration 008 e a inicialização do marco**, pois novas gravações são recusadas enquanto a cadeia não estiver inicializada.

Aplique então a migration 009, específica do Bloco 04, uma única vez:

```bat
"C:\\xampp\\mysql\\bin\\mysql.exe" --host=127.0.0.1 --port=3306 --user=root academia_alunos < database\\migrations\\009_attendance_checkins.sql
```

Ela cria a tabela `checkins`, com chaves estrangeiras para aluno, matrícula e funcionário e uma restrição única por aluno/dia local. A data civil é calculada pela aplicação no fuso `America/Sao_Paulo`; o instante correspondente é armazenado em UTC. Não edite a tabela manualmente para simular presenças.

Não execute a próxima migration se a anterior tiver apresentado qualquer erro. Se houver senha no root, acrescente `--password` às chamadas e informe-a quando solicitado.

### O que cada arquivo faz

- **005 — DDL:** cria `planos`, `matriculas`, `revisoes_legado`, `controle_importacao_legado` e `revisoes_legado_eventos`, com InnoDB, índices e chaves estrangeiras.
- **006 — DML transacional:** registra o maior ID de aluno existente no momento da primeira execução e cria uma revisão `PENDENTE_REVISAO` para cada aluno até esse limite. Não cria matrículas, não decide se havia contrato vigente e não inventa evidências. Reexecuções não sobrescrevem decisões humanas nem incluem alunos criados depois do corte.
- **007 — DDL de acesso:** adiciona o perfil administrativo em `usuarios`. Contas anteriores à migração recebem `ADMINISTRADOR` para não perder acesso; o valor padrão de novas contas passa a ser `OPERADOR`. Atribuições podem ser ajustadas na tela Funcionários por um administrador. A migration também cria `usuarios_perfis_eventos`, que preserva perfil anterior, perfil novo, responsável e instante de cada alteração.
- **008 — DDL de integridade:** cria o marco histórico, a cadeia SHA-256 dos eventos posteriores e gatilhos que impedem UPDATE/DELETE na trilha geral e nos hashes. Depois de aplicar, execute o inicializador CLI uma única vez para resumir os eventos anteriores. As novas gravações devem passar pelo registrador central `registrarAuditoria()`.
- **009 — DDL de frequência:** cria `checkins`, vinculando cada presença ao aluno, à matrícula e ao funcionário. A chave única `(aluno_id, data_local)` impede duplicidade diária. Depende das migrations 005–008 e da cadeia inicializada.

A 005 não é idempotente: se ela já tiver criado uma ou mais tabelas e falhar antes de terminar, **não a execute novamente às cegas**. Inspecione a estrutura e compare com o arquivo antes de qualquer reparo.

## 5. Verificações depois da aplicação

Execute estas consultas no banco `academia_alunos`:

```sql
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'planos',
    'matriculas',
    'revisoes_legado',
    'controle_importacao_legado',
    'revisoes_legado_eventos'
  )
ORDER BY TABLE_NAME;

SELECT
    (SELECT COUNT(*) FROM alunos) AS total_alunos,
    (SELECT COUNT(*) FROM revisoes_legado) AS total_revisoes,
    (SELECT ultimo_aluno_id FROM controle_importacao_legado WHERE id = 1) AS corte_importacao;

SELECT COUNT(*) AS alunos_do_corte_sem_revisao
FROM alunos a
INNER JOIN controle_importacao_legado c
    ON c.id = 1 AND a.id <= c.ultimo_aluno_id
LEFT JOIN revisoes_legado r
    ON r.aluno_id_origem = a.id
WHERE r.id IS NULL;

SELECT COUNT(*) AS revisoes_com_texto_original_divergente
FROM revisoes_legado r
INNER JOIN alunos a ON a.id = r.aluno_id_origem
WHERE BINARY r.plano_texto_original <> BINARY a.plano;

SELECT COUNT(*) AS matriculas_importadas_automaticamente
FROM matriculas;
```

Critérios de conferência imediata:

- As cinco tabelas novas devem existir e usar InnoDB.
- `alunos_do_corte_sem_revisao` deve ser zero.
- `revisoes_com_texto_original_divergente` deve ser zero.
- `matriculas_importadas_automaticamente` deve ser zero logo após a primeira carga, pois nenhum contrato é inferido do texto livre.
- Antes de novos cadastros, o total de revisões deve corresponder ao total de alunos incluídos no corte. Depois, alunos criados posteriormente ficam fora da carga inicial, por projeto.

Inspecione as constraints e índices:

```sql
SHOW CREATE TABLE planos;
SHOW CREATE TABLE matriculas;
SHOW CREATE TABLE revisoes_legado;
SHOW CREATE TABLE controle_importacao_legado;
SHOW CREATE TABLE revisoes_legado_eventos;
SHOW CREATE TABLE auditoria_integridade_estado;
SHOW CREATE TABLE auditoria_integridade_eventos;
SHOW TRIGGERS LIKE 'auditoria%';
```

Guarde os resultados da versão do banco, engines, contagens e definições das tabelas junto ao registro da validação local. Não inclua dados pessoais reais ou senhas nas evidências.

## 6. Se alguma etapa falhar

- **Falha na 005:** pare imediatamente. Não rode a 006 e não repita a 005. Colete a mensagem de erro e a saída de `SHOW CREATE TABLE`/metadados das tabelas que chegaram a ser criadas. Como DDL pode confirmar parcialmente, o estado precisa ser inspecionado.
- **Falha na 006:** não presuma que a carga foi concluída. A parte DML está dentro de transação, mas confirme o estado da linha de controle e das revisões antes de repetir. Preserve qualquer decisão humana já existente.
- **Necessidade de restaurar:** restaure o backup somente após decidir conscientemente que as alterações feitas depois dele podem ser descartadas. A restauração do dump substitui o estado do banco; não é um rollback seletivo das migrations.
- Não apague manualmente tabelas novas para “tentar de novo” sem análise do estado e das FKs.

## 7. Smoke test da aplicação

Somente depois de confirmar as tabelas e constraints:

1. Entre com uma conta fictícia já provisionada.
2. Abra Planos; crie um rascunho e confirme que ele não pode ser contratado antes de estar completo e ativo.
3. Complete os campos, ative o plano e confira a auditoria.
4. Contrate para um aluno fictício e confira snapshot, período e histórico.
5. Teste cancelamento de uma matrícula agendada e de uma ativa, sem usar registros reais.
6. Abra Revisão de legados; valide a fila pendente e registre uma decisão somente com evidência fictícia/adequada ao exercício.
7. Em um vínculo confirmado de teste, verifique a tela de retificação e confira que intervalo anterior e novo aparecem no histórico.

Registre separadamente o que foi executado, o que passou e o que não foi testado. A CI verde não equivale à validação no XAMPP nem à inspeção em celular real.

## 8. Verificar a integridade da auditoria

Depois de inicializar o marco e iniciar a aplicação, execute:

```bat
"C:\\xampp\\php\\php.exe" scripts\\verificar-integridade-auditoria.php
```

O verificador deve informar `Integridade confirmada.`, a quantidade de eventos incluídos no marco e a quantidade de eventos encadeados depois dele. Execute novamente depois dos testes funcionais.

A cadeia usa SHA-256 sobre uma representação canônica do evento e o hash anterior. O bloqueio transacional da linha de estado serializa gravações concorrentes; o evento e seu hash são confirmados ou revertidos juntos. Gatilhos impedem inserções fora do registrador e alteração/exclusão direta dos eventos, hashes e estado final.

### 8.1 Checkpoint externo assinado

O checkpoint adiciona uma referência independente por HMAC-SHA-256. A chave não fica no banco nem no repositório. Gere uma chave aleatória uma única vez e guarde-a em um gerenciador de senhas ou cofre offline, separado dos arquivos de checkpoint. Não a coloque no Git, no banco, em capturas de tela ou em logs.

No PowerShell, abra uma sessão local e carregue a chave (guarde o valor produzido em local seguro; não gere outra chave para verificar checkpoints antigos):

```powershell
$env:FITCONTROL_AUDIT_CHECKPOINT_SECRET = & "C:\xampp\php\php.exe" -r "echo bin2hex(random_bytes(32));"
```

Crie previamente uma pasta de backup fora do projeto, por exemplo `D:\FitControl-Secure-Backups`. Exporte usando um nome novo a cada execução:

```powershell
& "C:\xampp\php\php.exe" scripts\exportar-checkpoint-auditoria.php "D:\FitControl-Secure-Backups\checkpoint-20261122-0800.json"
```

Copie o arquivo para armazenamento independente com controle de acesso e, se possível, retenção imutável. Mantenha a chave HMAC em local separado. O script não sobrescreve arquivos existentes e restringe permissões no sistema operacional quando suportado.

Para verificar o checkpoint:

```powershell
& "C:\xampp\php\php.exe" scripts\verificar-checkpoint-auditoria.php "D:\FitControl-Secure-Backups\checkpoint-20261122-0800.json"
```

A verificação confirma a assinatura, a identidade configurada do banco, a cadeia atual e a presença do hash assinado na sequência. Eventos legítimos posteriores ao checkpoint são aceitos e contabilizados. Se o banco estiver atrás do identificador assinado, o verificador acusa possível restauração de backup antigo. Um checkpoint deve ser verificado com a mesma chave secreta usada na exportação.

**Limites:** HMAC prova que o arquivo foi produzido por alguém com a chave; não prova sozinho quando foi produzido. A identidade usa o nome configurado do banco, portanto clones com o mesmo nome e histórico podem compartilhar esse contexto. O checkpoint não detecta restauração para um estado exatamente igual ao checkpoint nem substitui backup, logs externos ou controle de acesso. Proteja a chave e armazene cópias dos checkpoints fora do servidor do banco. No Windows, valide manualmente as ACLs da pasta, pois o PHP não aplica o mesmo modo POSIX de permissões.

### 8.2 Listar e comparar checkpoints

Liste e valide todos os arquivos JSON da pasta externa:

```powershell
& "C:\xampp\php\php.exe" scripts\listar-checkpoints-auditoria.php "D:\FitControl-Secure-Backups\checkpoints"
```

Compare dois arquivos assinados, informando primeiro o mais antigo:

```powershell
& "C:\xampp\php\php.exe" scripts\comparar-checkpoints-auditoria.php "D:\FitControl-Secure-Backups\checkpoints\checkpoint-antigo.json" "D:\FitControl-Secure-Backups\checkpoints\checkpoint-novo.json"
```

A comparação valida as duas assinaturas, a mesma identidade de banco, o mesmo marco histórico, a ordem dos identificadores e a presença dos dois pontos na cadeia atual. A diferença informada é de identificadores, não uma contagem de eventos garantida quando há lacunas na sequência.

### 8.3 Diagnóstico de recuperação após restaurar um backup

O diagnóstico abaixo é somente leitura. Ele compara o estado do banco restaurado com todos os checkpoints JSON encontrados na pasta externa, valida as assinaturas HMAC e verifica a cadeia atual. Não insere eventos, não repara hashes, não reaplica migrations e não restaura dados.

Configure a mesma chave HMAC original e execute:

```powershell
$env:FITCONTROL_AUDIT_CHECKPOINT_SECRET = "CHAVE_HEXADECIMAL_ORIGINAL"
& "C:\xampp\php\php.exe" scripts\diagnosticar-recuperacao-auditoria.php "D:\FitControl-Secure-Backups\checkpoints" "D:\FitControl-Secure-Backups\relatorio-recuperacao-20261122.json"
```

O relatório é salvo com nome novo, fora do projeto e sem incluir a chave secreta. O comando também imprime o JSON no terminal. Código de saída 0 significa que o estado atual contém o checkpoint compatível mais recente encontrado; 2 indica cadeia inválida, divergência ou checkpoint posterior ao banco restaurado; 3 indica evidência insuficiente; 1 indica erro de configuração/execução.

Estados possíveis:

- `consistent_through_checkpoint`: cadeia íntegra e checkpoint compatível encontrado. Isso comprova consistência somente até aquele ponto, não a ausência de eventos perdidos depois dele.
- `possible_older_restore`: há checkpoint HMAC assinado posterior ao último evento do banco restaurado, compatível com a mesma linhagem. É evidência de possível restauração antiga, não prova da causa ou do horário.
- `chain_divergence`: checkpoint assinado não corresponde ao hash encontrado no banco.
- `chain_invalid`: a verificação da cadeia atual falhou.
- `insufficient_evidence`: nenhum checkpoint externo confiável e compatível foi encontrado.
- `diagnostic_error`: não foi possível executar a análise.

O relatório inclui o último checkpoint correspondente, checkpoints posteriores ao estado restaurado, arquivos rejeitados e limitações. Preserve o relatório e os arquivos originais como evidência; não substitua checkpoints antigos. Se houver suspeita de perda, interrompa alterações no banco e decida manualmente a recuperação com base em backups, logs e evidências externas. Nunca reconstrua eventos de auditoria por estimativa.


### 8.4 Agendamento diário no Windows

Os scripts PowerShell automatizam a exportação e a verificação pós-exportação. O instalador cria uma chave HMAC aleatória e guarda a configuração (chave e credenciais do banco) protegida pelo DPAPI do usuário Windows atual. A tarefa usa privilégio limitado e roda diariamente enquanto esse usuário estiver conectado.

Abra o PowerShell na raiz do repositório e execute:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\windows\instalar-checkpoint-auditoria.ps1 -ProjectRoot (Get-Location).Path -PhpPath "C:\xampp\php\php.exe" -DailyAt "08:00"
```

Para exibir a chave uma única vez e copiá-la diretamente para um cofre de senhas, acrescente `-ShowRecoveryKey`. Faça isso somente em uma sessão privada, sem gravação de terminal/transcrição, e não cole a chave em chats, e-mails ou no repositório. Sem essa opção, a chave permanece apenas na configuração DPAPI, que só pode ser descriptografada pelo mesmo usuário Windows no mesmo computador.

Ao reinstalar em outro computador ou perfil, recupere a chave do cofre e acrescente `-UseExistingRecoveryKey`; o instalador solicitará a chave em entrada segura e criará uma nova configuração DPAPI local com a mesma chave HMAC. Não use uma chave nova se precisar continuar verificando checkpoints antigos.
```

O instalador solicita host, porta, banco, usuário e senha do MySQL; a senha pode ficar vazia no XAMPP padrão. Os arquivos são armazenados por padrão em `%LOCALAPPDATA%\FitControl\AuditCheckpoints`, fora do repositório. A pasta recebe ACL restrita ao usuário atual. Cada execução cria um JSON novo e um log; não há limpeza automática nem sobrescrita.

Para executar manualmente a tarefa após instalá-la:

```powershell
Start-ScheduledTask -TaskName "FitControl - Checkpoint externo da auditoria"
Get-ScheduledTaskInfo -TaskName "FitControl - Checkpoint externo da auditoria"

Para removê-la sem apagar os checkpoints:

```powershell
Unregister-ScheduledTask -TaskName "FitControl - Checkpoint externo da auditoria" -Confirm:$false
```

**Operação e recuperação:** a tarefa usa DPAPI ligado ao usuário e ao computador Windows que a instalou. Não copie `config.dpapi` para outro perfil/computador esperando que funcione; preserve a chave em um cofre seguro e faça um procedimento de recuperação separado. A tarefa com logon interativo não roda enquanto o usuário estiver desconectado. A pasta local não é armazenamento externo imutável: copie periodicamente os JSON para outro dispositivo/conta com acesso separado. Não exclua checkpoints antigos automaticamente. Se a pasta local ou o perfil forem perdidos, os arquivos remotos e a chave recuperada são necessários para validar a cadeia.

**Limite de segurança da cadeia:** alguém com privilégios suficientes para alterar simultaneamente dados, hashes, estado e gatilhos do próprio banco pode reconstruir o histórico. A referência HMAC externa dificulta essa substituição sem a chave.

Não aplique a migration 008 em banco de produção ou com dados reais sem revisão, backup testado e janela de manutenção. A PR permanece em Draft e a aplicação local ainda precisa ser validada separadamente.

### 9. Migration 010 — Financeiro

Após aplicar e validar as migrations 001–009, faça backup do banco e execute uma única vez:

\`\`\`bash
mysql -u root -p academia_alunos < database/migrations/010_financeiro.sql
\`\`\`

No XAMPP padrão, se o usuário root não tiver senha, remova \`-p\`:

\`\`\`bash
mysql -u root academia_alunos < database/migrations/010_financeiro.sql
\`\`\`

A migration cria \`cobrancas\` e \`pagamentos\`; não cria cobranças retroativas para matrículas antigas. Confirme as duas tabelas e suas chaves no phpMyAdmin ou no cliente SQL. Não execute novamente uma migration de criação de tabelas já aplicada. A CI valida MySQL 8.0 e MariaDB 10.11, mas isso não substitui backup nem validação local.

## Estado e cautelas

A CI valida as migrations 005/006/007/008 em MySQL 8.0 e MariaDB 10.11, incluindo preservação do texto original, reexecução segura da carga inicial, atribuição compatível dos perfis e cadeia de integridade. Isso não significa que tenham sido aplicadas no computador.

A PR #1 deve permanecer em Draft até a validação local, a inspeção física e a revisão final. Não fazer merge para `main` automaticamente.
