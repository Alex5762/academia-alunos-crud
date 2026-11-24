# SEC-05 — Content Security Policy em modo Report-Only

## Objetivo e limite

Introduzir uma política inicial de Content Security Policy (CSP) sem bloquear recursos nem alterar o modo de execução do projeto. O cabeçalho usado é `Content-Security-Policy-Report-Only`: o navegador observa violações, mas não bloqueia os recursos por essa política. Esta etapa é um exercício de compatibilidade e defesa em profundidade para o projeto acadêmico/local; não é uma auditoria independente nem preparação de produção. A política é deliberadamente observacional: não bloqueia recursos e não deve ser interpretada como enforcement.

## Inventário da interface

Inspeção dos templates PHP de interface e dos ativos CSS/JavaScript na branch:

- Não foram encontrados blocos `<script>` inline nem atributos de evento HTML `on...`.
- Os scripts carregados pelos documentos são arquivos locais: `theme.js`, `script.js` e `sessao.js`.
- A folha de estilos é local (`style.css`). Não foram encontrados `@import`, `url(...)`, `@font-face` ou fontes/CDNs externos no CSS inspecionado.
- A comunicação identificada em JavaScript é o `fetch('renovar-sessao.php')`, no mesmo origin.
- Os formulários usam destinos locais relativos.
- Duas barras visuais usavam atributos `style=""` com valores dinâmicos. Foram substituídos por elementos HTML `<progress>` e estilos definidos em `style.css`.
- `theme.js` ajusta `document.documentElement.style.colorScheme` por propriedade CSSOM individual. A diretiva `style-src-attr 'none'` bloqueia atributos `style` e operações equivalentes a `setAttribute('style', ...)`/`style.cssText`, mas não bloqueia a atribuição de uma propriedade individual em `element.style`.

## Política inicial

O cabeçalho central é emitido por `funcoes.php` em todas as respostas PHP que inicializam a aplicação:

```http
Content-Security-Policy-Report-Only: default-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; script-src-attr 'none'; style-src 'self'; style-src-attr 'none'; img-src 'self'; font-src 'self'; connect-src 'self'; frame-src 'none'; media-src 'none'; worker-src 'none'
```

Princípios:

- `default-src 'none'` fecha categorias não explicitamente liberadas.
- Scripts, folhas de estilo, imagens, fontes e conexões são limitados à própria origem.
- `script-src-attr 'none'` e `style-src-attr 'none'` não liberam código/estilo inline.
- `object-src 'none'`, `frame-src 'none'`, `frame-ancestors 'none'` e `base-uri 'self'` reduzem superfícies que não são necessárias à interface.
- `form-action 'self'` mantém envios de formulário na própria origem.
- Não são usados curingas, `'unsafe-inline'`, `'unsafe-eval'`, `data:` nem hosts externos.

## Como a política é observada

`tests/browser-compatibility.mjs` verifica a presença das diretivas em cada navegação de documento e escuta o evento `securitypolicyviolation` nos três motores de navegador testados pela CI: Chromium, Firefox e WebKit. Uma violação com disposição `report` faz o teste falhar e apresenta a diretiva/URI bloqueada para diagnóstico.

Este incremento não configura `report-uri`, `report-to`, `Reporting-Endpoints` nem um coletor HTTP. Os eventos são observados em memória durante a execução automatizada e não são armazenados nem enviados a terceiros. Para monitoramento operacional, um endpoint precisaria ser projetado separadamente com limites de tamanho, validação, proteção contra abuso, política de retenção e minimização dos dados recebidos.

## Critérios para evoluir

1. A CI dos três motores precisa aprovar a política e registrar zero violações.
2. A inspeção manual em navegador instalado deve exercitar as páginas e os fluxos reais, inclusive os controles de tema, formulários, frequência, financeiro, relatórios e exportação.
3. Qualquer nova dependência externa deve ser inventariada e autorizada por origem específica, nunca por curingas.
4. Só depois da observação manual deve ser considerada uma política de enforcement. Essa decisão exige nova revisão e aprovação explícita.

## Limitações

- O cabeçalho está em `Report-Only`; não impede a execução de um recurso que viole a política.
- O teste em Playwright não substitui navegador/dispositivo real.
- A política é emitida por páginas PHP que carregam `funcoes.php`; recursos estáticos servidos diretamente não têm o cabeçalho, embora sua carga seja governada pela política do documento que os incorpora.
- Não foram habilitados HSTS, HTTPS de produção, coleta persistente de relatórios, Trusted Types ou mecanismos de segurança adicionais.
- A validação local no XAMPP e a inspeção física permanecem pendentes.
