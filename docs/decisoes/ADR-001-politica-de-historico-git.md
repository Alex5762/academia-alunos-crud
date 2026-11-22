# ADR-001 — Política de histórico e commits Git

- **Status:** aceita para o trabalho futuro
- **Data:** 22/11/2026
- **Projeto:** FitControl

## Contexto

O repositório acumulou um histórico extenso. A contagem de commits, isoladamente, não demonstra qualidade, produtividade ou domínio técnico. O valor do histórico está em permitir entender a evolução, localizar mudanças, revisar decisões e investigar regressões.

Na comparação atual da PR #1, a branch `feat/dashboard-portfolio` está 705 commits à frente de `main`. Esse número é específico da comparação entre essas duas referências; não deve ser confundido com uma contagem global de todos os commits do repositório, nem interpretado sem considerar commits automatizados.

## Decisão

1. **Não estabelecer meta de quantidade.** Não criar commits apenas para aumentar o contador e não reescrever o histórico antigo apenas para reduzi-lo ou deixá-lo visualmente mais curto.
2. **Um commit deve representar uma unidade lógica.** A mudança precisa ter intenção identificável e ser compreensível em revisão. Não é necessário criar um commit para cada edição pequena; mudanças relacionadas podem permanecer juntas quando formam uma entrega coerente.
3. **Usar mensagens consistentes**, preferencialmente no formato `tipo(escopo): descrição`, com tipos como:
   - `feat`: funcionalidade;
   - `fix`: correção;
   - `refactor`: reorganização sem mudança funcional pretendida;
   - `test`: testes;
   - `docs`: documentação;
   - `ci`: pipeline e automação;
   - `build`: dependências e construção;
   - `chore`: manutenção que não se encaixa melhor nos tipos anteriores.
4. **Descrever a intenção, não apenas a atividade.** Exemplos: `fix(auth): rejeita sessão expirada`, `test(enrollment): cobre cancelamento concorrente` e `docs(setup): esclarece migrations locais`.
5. **Manter automação identificável.** Commits de bots, capturas geradas e atualizações automáticas devem continuar distinguíveis dos commits de implementação humana. Ao avaliar o ritmo de trabalho, separar essas categorias.
6. **Não usar atalhos para mascarar falhas.** Marcadores como `[skip ci]` só devem ser usados quando a alteração for realmente restrita a artefatos gerados e a validação de código não for necessária; nunca para ocultar uma CI quebrada.
7. **Preferir PRs com escopo revisável.** Próximas entregas devem agrupar uma finalidade clara, explicitar riscos e registrar testes executados. O tamanho e a quantidade de commits devem ser avaliados junto com os arquivos e o diff.
8. **Preservar o histórico publicado.** Não fazer rebase destrutivo, force-push ou squash retroativo do histórico existente sem uma necessidade concreta, backup e decisão explícita.

## Critérios de revisão

Ao revisar um commit ou PR, priorizar:

- objetivo e justificativa da mudança;
- coerência entre código, testes e documentação;
- resultado da CI e dos testes relevantes;
- impacto em banco, compatibilidade e segurança;
- facilidade de compreender o diff e identificar regressões.

A quantidade de commits é apenas contexto. Não é critério isolado de aprovação.

## Consequências

- O histórico existente será mantido.
- A convenção será aplicada gradualmente aos próximos commits.
- A avaliação do projeto vai considerar qualidade técnica, testes, documentação e capacidade de explicar as decisões — não o número bruto de commits.
- A PR #1 continuará em Draft até cumprir suas pendências de revisão e validação manual; esta decisão não autoriza merge.

## Alternativas rejeitadas

- Reduzir o contador por squash geral ou reescrita retroativa.
- Criar commits artificiais só para produzir um gráfico mais movimentado.
- Tratar cada microedição como uma unidade de commit.
- Considerar todos os commits automatizados como evidência de produtividade individual.
