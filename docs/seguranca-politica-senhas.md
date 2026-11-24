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

O login rejeita entradas acima de 72 bytes antes de consultar e verificar credenciais. Os hashes existentes não são regravados nem migrados automaticamente. Como o limite é inerente ao bcrypt, usuários com senhas antigas acima de 72 bytes podem autenticar com o prefixo efetivo de até 72 bytes; recomenda-se que alterem a senha após o acesso.

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

`tests/integration-crud.sh` também verifica que login e troca obrigatória rejeitam uma entrada de 73 bytes. A CI executa o teste unitário em PHP 8.2.

## Limitações

Esta decisão resolve o truncamento de novas senhas e limita o tamanho efetivo aceito pelo login. Ela não substitui revisão de força de senha, MFA, proteção contra credential stuffing, revogação global de sessões nem auditoria de eventos de autenticação; esses itens pertencem a outros pontos do Bloco 08.1.
