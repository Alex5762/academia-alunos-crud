# Matriz de permissões — FitControl

A autorização é aplicada no servidor por `exigirPermissao()`; ocultar um link ou botão na interface não substitui essa validação. O perfil é carregado da tabela `usuarios` em cada requisição autenticada e nunca é aceito de campos GET/POST.

| Permissão | Administrador | Gestor | Operador |
|---|:---:|:---:|:---:|
| Visão geral e consulta de alunos | ✓ | ✓ | ✓ |
| Criar e editar cadastro de aluno | ✓ | ✓ | ✓ |
| Ativar/inativar aluno | ✓ | ✓ | — |
| Consultar catálogo de planos | ✓ | ✓ | ✓ |
| Criar, editar, ativar e inativar planos | ✓ | ✓ | — |
| Criar e consultar matrículas | ✓ | ✓ | ✓ |
| Cancelar matrícula | ✓ | ✓ | ✓ |
| Consultar revisão de vínculos legados | ✓ | ✓ | — |
| Decidir revisão e retificar vigência legada | ✓ | ✓ | — |
| Criar/desativar funcionários e alterar perfis | ✓ | — | — |
| Consultar central de auditoria | ✓ | ✓ | — |
| Preferências locais e alteração da própria senha | ✓ | ✓ | ✓ |

## Regras de segurança

- Contas existentes no momento da migration 007 recebem `ADMINISTRADOR` para preservar o acesso anterior. O administrador deve revisar e reduzir os perfis depois da atualização.
- Novas contas criadas pela interface recebem `OPERADOR` por padrão.
- O provisionamento inicial via CLI cria uma conta `ADMINISTRADOR`; o script é restrito ao terminal.
- Um funcionário não pode alterar o próprio perfil pela interface.
- Não é permitido remover o perfil do último administrador ativo.
- A desativação de contas é registrada na auditoria; alterações de perfil também preservam perfil anterior, perfil novo e responsável em `usuarios_perfis_eventos`.
- As operações de gestão de funcionários são exclusivas de administradores.
- O acesso negado para usuário autenticado retorna HTTP 403.
- O perfil é consultado no banco em cada requisição, portanto uma alteração de perfil passa a valer na próxima requisição autenticada.

## Limites desta versão

- Não há permissões customizadas por funcionário nem escopo por unidade/filial.
- Todos os administradores têm acesso integral.
- O operador pode criar e editar dados cadastrais de alunos, mas não alterar seu status.
- A matriz não substitui a validação de sessão, CSRF, regras de domínio ou transações.


## Central de auditoria

A página `auditoria.php` reúne eventos gerais e alterações de perfil, incluindo responsável, data/hora, ação, entidade, identificador do registro e detalhe do perfil anterior e novo. Permite filtrar por funcionário/e-mail/ID, ação, entidade e período local da academia, com paginação. O acesso é restrito a Administrador e Gestor; Operador recebe HTTP 403.

A página `detalhes-auditoria.php` consolida a linha do tempo por registro para alunos, funcionários, planos, matrículas e revisões legadas. Ela combina a trilha geral com eventos especializados de perfil e de revisão/retificação, quando aplicáveis. Os parâmetros de entidade são allowlisted, o identificador precisa ser inteiro positivo, e a página é somente leitura. Administrador e Gestor podem consultar; Operador recebe HTTP 403.


## Detalhes por registro

A partir da central, eventos vinculados a alunos, funcionários, planos, matrículas e revisões legadas abrem `detalhes-auditoria.php`. A página consulta somente entidades permitidas, valida o identificador positivo, exige a permissão `audit.view` no servidor e combina a trilha geral com os históricos especializados de perfil e revisão legada. A linha do tempo é somente leitura e apresenta responsável, data/hora, ação e detalhes operacionais, sem expor hashes de senha, tokens ou dados de autenticação.
