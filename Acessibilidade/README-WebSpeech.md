# Leitor de Sites com Web Speech API — Recursos Assistivos

Este documento descreve a integração do **Leitor de Sites** usando a **Web Speech API** (`speechSynthesis`) no painel de acessibilidade.

## O que foi implementado

- O botão **Leitor de Sites** agora possui **6 modos de leitura** (ciclo ao clicar):
  1. normal masculina
  2. normal feminina
  3. rápida masculina
  4. rápida feminina
  5. lenta masculina
  6. lenta feminina
- Ao ativar qualquer modo, o cursor usa os SVGs solicitados:
  - **vermelho** quando não há alvo de texto válido.
  - **azul** quando encontra conteúdo para leitura.
- Ao passar o mouse sobre textos, o elemento é marcado com **sublinhado** (e desmarca ao sair/trocar de alvo), seguindo o comportamento visual da lupa.
- A leitura acontece **no hover** (sem clique), usando `SpeechSynthesisUtterance`.

## Como funciona tecnicamente

- O recurso escuta `pointermove` para detectar o elemento de texto sob o cursor.
- Quando encontra um novo alvo:
  - atualiza o destaque (atributo `data-ibr-site-reader-target="true"`),
  - atualiza o cursor ativo,
  - agenda a fala com pequeno debounce para evitar disparos excessivos.
- Ao sair da área de texto, rolar a página ou desativar o modo:
  - remove destaque,
  - retorna cursor inativo,
  - cancela falas pendentes/em execução.

## Seleção de vozes

A Web Speech API não expõe de forma padronizada um campo de gênero. Por isso:

- o sistema tenta inferir voz masculina/feminina por heurística no nome/URI da voz;
- se não encontrar correspondência, prioriza vozes `pt-*`;
- por último, usa a primeira voz disponível do navegador.

> Em navegadores/sistemas diferentes, as vozes instaladas variam. Portanto os resultados podem mudar por dispositivo.

## Dependências e requisitos

- Não requer backend novo.
- Requer navegador com suporte a `window.speechSynthesis` e `SpeechSynthesisUtterance`.
- Recomendado servir o site em contexto normal do navegador (localhost/https).

## O que você pode precisar fazer

1. **Testar em navegadores reais** (Chrome/Edge/Safari) para validar quais vozes PT-BR/PT-PT aparecem.
2. Se quiser vozes específicas por nome (ex.: uma voz corporativa), me passe os nomes exatos retornados por `speechSynthesis.getVoices()` no seu ambiente para eu fixar prioridades.
3. Se o time quiser UX diferente (ex.: falar somente após 500ms parado no texto), posso ajustar facilmente.

## Referência

- MDN Web Speech API: https://developer.mozilla.org/en-US/docs/Web/API/Web_Speech_API
