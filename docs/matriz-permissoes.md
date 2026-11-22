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
| Preferências locais e alteração da própria senha | ✓ | ✓ | ✓ |

## Regras de segurança

- Contas existentes no momento da migration 007 recebem `ADMINISTRADOR` para preservar o acesso anterior. O administrador deve revisar e reduzir os perfis depois da atualização.
- Novas contas criadas pela interface recebem `OPERADOR` por padrão.
- O provisionamento inicial via CLI cria uma conta `ADMINISTRADOR`; o script é restrito ao terminal.
- Um funcionário não pode alterar o próprio perfil pela interface.
- Não é permitido remover o perfil do último administrador ativo.
- A desativação de contas e as alterações de perfil são registradas na auditoria.
- As operações de gestão de funcionários são exclusivas de administradores.
- O acesso negado para usuário autenticado retorna HTTP 403.
- O perfil é consultado no banco em cada requisição, portanto uma alteração de perfil passa a valer na próxima requisição autenticada.

## Limites desta versão

- Não há permissões customizadas por funcionário nem escopo por unidade/filial.
- Todos os administradores têm acesso integral.
- O operador pode criar e editar dados cadastrais de alunos, mas não alterar seu status.
- A matriz não substitui a validação de sessão, CSRF, regras de domínio ou transações.
