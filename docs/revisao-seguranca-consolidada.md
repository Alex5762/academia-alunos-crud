# Revisão consolidada de segurança — FitControl

**Data:** 24/11/2026  
**Branch avaliada:** `feat/dashboard-portfolio`  
**Snapshot de referência:** `54f05f6ff2439dc4c24bb3b91460007b16344c88`  
**PR:** #1 — aberta em Draft, base `main`

## Escopo e método

Revisão estática direcionada das áreas de autenticação, sessões, autorização RBAC, endpoints mutáveis, configuração PDO, consultas e validação de entradas, saída HTML/CSV, auditoria/integridade, finanças, CSP e exposição do webroot. Foram confrontados os pontos de código com a matriz de permissões, documentos SEC-01 a SEC-05 e os testes existentes.

Esta é uma revisão interna de código e evidências da CI. Não é pentest, auditoria independente ou certificação de segurança. Não foram acessados o XAMPP/dispositivo do usuário, um ambiente HTTPS real, infraestrutura de produção, logs externos ou segredos de implantação.

## Resultado executivo

Não foi identificado, nos fluxos inspecionados, um bypass direto e reproduzível de autenticação/RBAC, falha evidente de SQL injection ou saída HTML dinâmica de usuário claramente sem escape. Os endpoints mutáveis examinados usam POST, token CSRF e autorização no servidor; consultas que receberam atenção usam parâmetros PDO; a matriz RBAC possui testes positivos e negativos; e os templates amostrados escapam os valores textuais dinâmicos.

A CI #1708 passou no snapshot documental de referência, com sete jobs funcionais aprovados. A CI #1707 validou a política CSP Report-Only em Chromium, Firefox e WebKit, exigindo o cabeçalho completo e zero eventos de violação nos fluxos automatizados.

**Conclusão:** a base atual é coerente para o escopo acadêmico/local documentado, mas não deve ser exposta à internet nem declarada pronta para produção. Permanecem dependências operacionais e algumas melhorias de endurecimento abaixo.

## Controles verificados

### Autenticação, sessões e senhas

- A sessão usa modo estrito, somente cookies, sem SID em URL, cookie HttpOnly/SameSite=Lax e atributo Secure quando o PHP identifica HTTPS.
- Há limites de 30 minutos de inatividade e oito horas absolutas, com validação do estado ativo da conta a cada requisição autenticada.
- O login regenera o ID de sessão e o token CSRF; logout exige POST e token; renovação de sessão também exige POST e token.
- A política de novas senhas centraliza o mínimo de 12 grafemas e limite de 72 bytes UTF-8 para bcrypt; não há truncamento silencioso de senhas novas.
- A troca obrigatória de senha no primeiro acesso e a alteração voluntária verificam a senha atual e registram auditoria.
- A limitação atual bloqueia a quinta falha por combinação de e-mail normalizado e IP de origem por 15 minutos.

### Autorização e endpoints

- A autorização é baseada no perfil carregado do banco, não em campos GET/POST.
- Os endpoints inspecionados de cadastro, status, matrículas, check-in, planos, funcionários, gestão de aulas, retificação/revisão legada e finanças verificam permissão no servidor.
- A central e os detalhes de auditoria exigem `audit.view`; o CSV valida o tipo de relatório e a permissão correspondente.
- As operações de escrita analisadas exigem POST e token CSRF. O logout também é POST.
- Os testes incluem bloqueio do perfil OPERADOR em áreas financeiras e matriz de permissões positiva/negativa.

### Banco, dados e saída

- O PDO usa exceções, UTF-8 e prepared statements nativos (`ATTR_EMULATE_PREPARES=false`).
- A configuração de produção exige explicitamente `APP_ENV=production` e as variáveis de conexão; o arquivo local é ignorado pelo Git e a pasta `config/` é bloqueada no Apache.
- Nos templates amostrados, valores textuais dinâmicos são escapados; as barras dinâmicas foram migradas para `<progress>` para evitar estilos inline.
- O exportador CSV valida UTF-8, aplica escaping CSV e neutraliza prefixos de fórmula conhecidos.
- A exportação de relatórios e a tela financeira aplicam autorização por tipo/ação no servidor.

### Auditoria, CSP e webroot

- A trilha geral usa encadeamento SHA-256 transacional, triggers append-only e verificação por CLI; a documentação reconhece que um administrador que controle o banco pode alterar a própria cadeia.
- Os pagamentos são históricos/idempotentes e têm controles append-only no banco.
- A CSP atual está em `Content-Security-Policy-Report-Only`; não bloqueia recursos e não é apresentada como enforcement.
- A proteção de arquivos internos foi testada em Apache 2.4 real no container; XAMPP local ainda não foi inspecionado.

## Achados e limites que merecem acompanhamento

### R-01 — Diferença de tempo no login para conta inexistente

**Prioridade:** baixa / endurecimento.

Em `login.php`, `password_verify()` só é executado quando o e-mail corresponde a uma conta ativa. A resposta textual é genérica, mas a diferença de trabalho entre conta inexistente/inativa e conta existente com senha errada pode permitir inferência por tempo em condições de medição adequadas.

**Recomendação:** verificar também uma hash bcrypt fictícia, válida e de custo equivalente quando a conta não existir ou estiver inativa; manter a mesma resposta externa e os limites de entrada. Adicionar teste que confirme que o caminho de verificação fictícia é exercitado. A proteção temporal não deve ser tratada como garantia absoluta.

### R-02 — Limitação de login é por par e não por dimensão global

**Prioridade:** média se o painel vier a ser compartilhado ou exposto.

A tabela `login_tentativas` limita por hash do e-mail + hash do IP. Isso controla repetição na mesma combinação, mas não constitui limite agregado por IP ou por conta contra tentativas distribuídas. O registro antigo é removido durante requisições de login, não por rotina operacional dedicada; um fluxo de muitas combinações distintas pode aumentar a tabela dentro da janela de retenção.

**Recomendação:** antes de qualquer exposição, combinar controles de borda/WAF com limites por origem e por conta, rotina de limpeza limitada e monitoramento de volume. Não confiar somente no limite atual como proteção contra credential stuffing ou DoS.

### R-03 — Preservação do último administrador na desativação

**Prioridade:** baixa / defesa em profundidade.

`alterar-perfil-usuario.php` conta explicitamente administradores ativos e impede rebaixar o último. `alterar-status-usuario.php` conta todas as contas ativas, não especificamente administradores. O fluxo serial normal também impede que o administrador desative a própria conta e exige `users.manage`, então a revisão não confirmou uma forma sequencial de remover o último administrador. Ainda assim, o endpoint não expressa a mesma invariável de forma explícita e os testes de integração não cobrem o limite nem condições concorrentes.

**Recomendação:** incluir o perfil no bloqueio transacional, revalidar o ator administrativo no snapshot bloqueado e testar o comportamento para desativação/alteração concorrente. Registrar esta observação como robustez pendente, não como vulnerabilidade explorável já demonstrada.

### R-04 — Migration 007 promove contas legadas a ADMINISTRADOR

**Prioridade:** alta para o procedimento de implantação, não um bypass automático de código.

A migration 007 atribui `ADMINISTRADOR` às contas existentes para preservar o acesso anterior; novas contas recebem `OPERADOR`. Se houver várias contas antigas, todas passam a ter privilégios administrativos até que os perfis sejam revisados.

**Recomendação obrigatória antes de qualquer instância compartilhada:** inventariar as contas existentes, definir nominalmente os administradores necessários, reduzir as demais para Gestor/Operador e verificar a lista de administradores ativos após a migration. Não aplicar esta etapa em base real sem backup, revisão e plano de reversão.

### R-05 — Triggers usam variáveis de sessão MySQL como autorização de escrita

**Prioridade:** limite arquitetural / esclarecer confiança.

Os triggers de auditoria e pagamentos liberam determinadas gravações quando a conexão define variáveis como `@fitcontrol_audit_chain_write` e `@fitcontrol_payment_write`. Qualquer cliente que já possua as credenciais do banco pode definir variáveis de sessão com esses nomes. Portanto, os triggers ajudam a impedir escrita direta acidental por consultas comuns, mas não são uma fronteira de segurança contra comprometimento das credenciais do banco ou de um cliente autorizado.

**Recomendação:** manter a documentação explícita sobre esse limite; usar usuário de aplicação dedicado com privilégios mínimos, restringir o acesso direto ao banco e manter checkpoints de integridade fora do servidor/banco, com administração e credenciais separadas. Uma garantia mais forte exigiria rever o modelo de privilégios/procedimentos, não apenas renomear as variáveis.

### R-06 — Checkpoints externos e operação de recuperação não são automáticos

**Prioridade:** operacional.

A cadeia SHA-256 permite detectar alterações que não atualizem corretamente os hashes/estado. O próprio documento de permissões reconhece que um administrador com controle do banco pode alterar os dados e a cadeia. A exportação/verificação de checkpoints externos depende de operação e armazenamento fora do banco; não foi validado um serviço de armazenamento imutável externo nesta revisão.

**Recomendação:** antes de uso compartilhado, definir responsável, frequência, destino externo, retenção, restauração e teste de recuperação, com credenciais separadas. Não anunciar a auditoria como inviolável.

## Pendências antes de qualquer uso compartilhado/produção

1. Confirmar e documentar todos os perfis após migration 007.
2. Definir HTTPS real, proxy confiável quando aplicável e cookie Secure no servidor de destino.
3. Inspecionar Apache/XAMPP local e confirmar as regras `.htaccess`; em outro servidor, aplicar controles equivalentes na configuração dele.
4. Testar CSP manualmente em navegador instalado; manter Report-Only até nova aprovação explícita para enforcement.
5. Criar plano de backups/restauração e checkpoints externos de auditoria.
6. Adicionar controles agregados de tentativas de login e monitoramento.
7. Decidir sobre MFA, recuperação de senha, revogação global de sessões e revisão independente.
8. Rever privilégios efetivos do usuário MySQL e armazenar/rotacionar segredos fora do webroot.

## Evidências automatizadas relevantes

- [CI #1708 — snapshot documental consolidado](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45333835005): sete jobs funcionais aprovados.
- [CI #1707 — CSP Report-Only em Chromium, Firefox e WebKit](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45332698092): sete jobs funcionais aprovados; logs confirmam os três motores e zero violações CSP reportadas.
- [CI #1699 — sessões, cookies e cabeçalhos](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45327940726): sete jobs funcionais aprovados.
- [CI #1695 — proteção Apache do webroot](https://github.com/Alex5762/academia-alunos-crud/actions/runs/45326659392): sete jobs funcionais aprovados.

## Decisão desta revisão

Não foi realizado merge, rebase, cherry-pick, alteração da `main`, migration local ou alteração de segredos. A PR #1 permanece aberta em Draft. A revisão consolida evidências e limites; não declara o FitControl pronto para produção.
