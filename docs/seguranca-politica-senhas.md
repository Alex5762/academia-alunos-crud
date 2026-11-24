# SEC-01 — Política de tamanho e hashing de senhas

## Decisão

O FitControl usa `PASSWORD_BCRYPT` explicitamente para gerar novos hashes enquanto a aplicação mantiver o limite operacional de 72 bytes. O bcrypt considera no máximo os primeiros 72 bytes da senha; por isso, a aplicação rejeita entradas maiores antes de gerar o hash, evitando que duas entradas diferentes sejam tratadas como a mesma senha por truncamento.

A política central está em `src/Security/PasswordPolicy.php`:

- mínimo de 12 grafemas Unicode;
- máximo de 72 bytes UTF-8;
- rejeição de byte NUL;
- rejeição de sequências UTF-8 inválidas;
- geração do hash somente após a validação.

O limite é medido em bytes, não em caracteres. Por exemplo, 36 caracteres `á` ocupam 72 bytes em UTF-8 e podem ser aceitos; 37 ocupam 74 bytes e são rejeitados.

## Fluxos cobertos

A política é aplicada ao criar novas credenciais e ao definir novas senhas em:

- provisionamento de usuário por CLI;
- criação de funcionário pelo painel;
- troca obrigatória no primeiro acesso;
- alteração voluntária de senha.

A política de 72 bytes é aplicada ao definir novas senhas, não ao login. O login preserva o limite de entrada anterior (4.096 bytes) e continua passando a senha informada para `password_verify`, mantendo compatibilidade com credenciais bcrypt antigas que tenham sido criadas com entradas maiores que 72 bytes. Os hashes existentes não são regravados nem migrados automaticamente.

## Compatibilidade e evolução

Esta alteração não exige migration, alteração de schema ou operação no banco local. Os hashes existentes continuam armazenados como estão e permanecem verificáveis por `password_verify`.

Argon2id não foi ativado nesta etapa. A adoção exige confirmar a disponibilidade da extensão/algoritmo em todos os ambientes suportados (incluindo XAMPP e CI), definir parâmetros de custo e validar o comportamento de rehash. O uso de Argon2id pode ser avaliado em uma decisão separada.

## Validação automatizada

`tests/password-policy.php` cobre:

- senha com tamanho mínimo e abaixo do mínimo;
- exatamente 72 bytes e 73 bytes;
- caracteres Unicode multibyte no limite e acima dele;
- grafemas Unicode;
- byte NUL e UTF-8 inválido;
- geração e verificação do hash;
- rejeição de hashing acima do limite.

`tests/integration-crud.sh` verifica o limite histórico de entrada no login (4.096 bytes) e a rejeição de uma nova senha acima de 72 bytes no fluxo de troca obrigatória. A CI executa o teste unitário em PHP 8.2.

## Limitações

Esta decisão evita truncamento silencioso ao criar novas senhas sem bloquear o login de contas legadas. Ela não substitui revisão de força de senha, MFA, proteção contra credential stuffing, revogação global de sessões nem auditoria de eventos de autenticação; esses itens pertencem a outros pontos do Bloco 08.1.
