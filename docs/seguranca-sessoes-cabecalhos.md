# SEC-04 — Sessões, cookies e cabeçalhos HTTP

## Objetivo

Registrar e testar os controles de sessão e de resposta HTTP já presentes na aplicação. Esta revisão não declara o FitControl pronto para produção nem substitui uma revisão independente.

## Controles existentes

A inicialização compartilhada em `funcoes.php`:

- ativa `session.use_strict_mode=1`, `session.use_only_cookies=1` e desativa `session.use_trans_sid`;
- usa o cookie `FITCONTROLSESSID`, sem duração persistente, com caminho raiz, `HttpOnly` e `SameSite=Lax`;
- define `Secure` quando o PHP identifica a requisição como HTTPS;
- envia `Cache-Control: no-store, private, max-age=0` e `Pragma: no-cache`;
- envia `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin` e uma `Permissions-Policy` restritiva.

O login bem-sucedido regenera o identificador da sessão e substitui o token CSRF. A troca de senha também regenera o identificador. O encerramento de sessão limpa o estado, expira o cookie com os mesmos atributos e destrói a sessão no servidor.

## Contrato de implantação HTTPS

O atributo `Secure` depende de `$_SERVER['HTTPS']` estar corretamente definido pelo servidor que executa o PHP.

- Em HTTPS direto, confirme que o servidor/FastCGI informa HTTPS ao PHP.
- Se TLS terminar em proxy reverso, configure o servidor de aplicação para marcar a conexão como HTTPS somente quando ela vier do proxy confiável e a conexão externa realmente usar TLS.
- Não altere a aplicação para confiar indiscriminadamente em `X-Forwarded-Proto` recebido do cliente. Esse cabeçalho só pode ser considerado se o proxy de borda remover o valor enviado pelo cliente e escrever um valor próprio, e se o backend não puder ser acessado diretamente por origens não confiáveis.
- Em HTTP local com XAMPP, o cookie não recebe `Secure`; isso é esperado para desenvolvimento em localhost. Não use esse modo para tráfego público.

## Validação automatizada

O teste de integração HTTP verifica os cabeçalhos emitidos na tela de login, os atributos do cookie em HTTP e a rotação do identificador e do token CSRF após autenticação.

O mesmo teste executa uma verificação isolada da inicialização de sessão com os cenários HTTP e HTTPS simulados, conferindo:

- nome fixo do cookie;
- modo estrito e transporte somente por cookie;
- ausência de session ID em URL;
- cookie não persistente, caminho raiz, `HttpOnly` e `SameSite=Lax`;
- atributo `Secure` desativado em HTTP e ativado em HTTPS.

A CI executa a integração HTTP no ambiente efêmero. O cenário HTTPS isolado valida a configuração do PHP, mas não simula um proxy real, certificado TLS ou navegador.

## Decisões que permanecem fora desta revisão

- **HSTS:** não é emitido por padrão. Só deve ser habilitado quando todo o domínio público estiver permanentemente disponível por HTTPS, incluindo subdomínios se `includeSubDomains` for considerado. Não aplicar HSTS ao ambiente local HTTP.
- **Content-Security-Policy:** não foi adicionada nesta etapa. O projeto possui scripts/estilos inline e a política precisa ser construída a partir de inventário e testes, evitando liberar `unsafe-inline` sem análise ou quebrar funcionalidades.
- **CSP report-only, Trusted Types e política de origem:** podem ser avaliadas em revisão específica.
- **MFA, recuperação de senha, revogação global de sessões e revisão independente:** permanecem pendências do planejamento de segurança/produção.

## Limites e validação manual

Os testes automatizados não substituem a inspeção em navegador instalado, dispositivo físico, XAMPP nem a validação de TLS/proxy no ambiente real. Nenhuma configuração do servidor local ou segredo foi alterado por esta revisão.