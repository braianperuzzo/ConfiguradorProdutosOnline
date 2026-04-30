# Integração VLibras — Recursos Assistivos

Este projeto agora integra o **VLibras** no atalho de acessibilidade **Tradutor de Libras** (e no botão flutuante **Acessível em Libras**).

## O que foi implementado

- Carregamento dinâmico do script oficial: `https://vlibras.gov.br/app/vlibras-plugin.js`.
- Inicialização automática do widget `new VLibras.Widget('https://vlibras.gov.br/app')`.
- Modo de interação por cursor:
  - **Vermelho (grande)** quando não existe conteúdo textual válido para tradução.
  - **Azul** quando existe conteúdo textual clicável/traduzível.
- Ao passar o mouse em conteúdos de texto (parágrafos, títulos, etc.), o elemento é sublinhado.
- Ao clicar no texto destacado:
  - o texto é selecionado via `Selection API`,
  - o VLibras usa essa seleção para tradução na janela do plugin.

## Fluxo de uso

1. Clique em **Tradutor de Libras** no painel, ou no botão flutuante **Acessível em Libras**.
2. Aguarde o carregamento do VLibras (primeiro uso).
3. Passe o mouse sobre textos da página para destacar.
4. Clique no trecho desejado para traduzir no VLibras.

## Requisitos externos

Como o VLibras é carregado por script remoto, é necessário:

- Acesso de rede ao domínio `vlibras.gov.br`.
- Não bloquear o script por extensões (adblock/privacidade) ou políticas de segurança.

## Se você precisar fazer algo do seu lado

Verifique/ajuste a política de segurança do site (CSP), permitindo os domínios do VLibras. Exemplo de diretiva mínima:

```http
Content-Security-Policy:
  script-src 'self' https://vlibras.gov.br;
  connect-src 'self' https://vlibras.gov.br;
  frame-src https://vlibras.gov.br;
  img-src 'self' data: https://vlibras.gov.br;
  style-src 'self' 'unsafe-inline';
```

> Obs.: ajuste a CSP final conforme a política da sua aplicação e demais integrações existentes.

## Mensagens de erro previstas

Caso o script não carregue, o sistema exibe aviso no painel:

- "Não foi possível carregar o VLibras. Verifique conexão, CSP e bloqueios de conteúdo externo."

Isso normalmente indica bloqueio de rede, CSP ou extensão do navegador.
