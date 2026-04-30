# Integração: Sinônimos e Significados (API Dicionário PT-BR / base Dicio)

## O que foi integrado
- Recurso **Sinônimos e Significados** no painel de acessibilidade.
- Cursor e comportamento iguais ao padrão da lupa:
  - **Vermelho** quando não há palavra válida para clicar.
  - **Azul** quando há palavra válida sob o cursor.
- Ao passar o mouse, apenas **palavras** são alvo (com sublinhado azul).
- Ao clicar, abre pop-up com:
  - **Sinônimos**
  - **Significado**
  - **Exemplos de Uso**

## Arquivos principais
- `Acessibilidade/LayoutAcessibilidade.js`
- `Acessibilidade/LayoutAcessibilidade.css`
- `Acessibilidade/ConsultarDicionario.php`

## Como funciona
1. O front captura a palavra sob o ponteiro.
2. Faz `GET /Acessibilidade/ConsultarDicionario.php?palavra=<termo>`.
3. O endpoint PHP consulta `https://www.dicio.com.br/<termo>/` e extrai conteúdo.
4. Retorna JSON padronizado para o pop-up.

## Exemplo de resposta do endpoint local
```json
{
  "ok": true,
  "palavra": "cadeira",
  "sinonimos": ["assento", "poltrona", "encosto"],
  "significado": "...",
  "exemplos": ["...", "..."],
  "fonte": "https://www.dicio.com.br/"
}
```

## O que você precisa fazer (se necessário)
- Garantir que o servidor PHP permita saída de `json` e acesso externo por `file_get_contents` (allow_url_fopen habilitado).
- Se seu ambiente bloquear acesso externo, liberar `https://www.dicio.com.br` no firewall/proxy.

## Sobre a pasta `Acessibilidade/Dicio`
A pasta contém a referência original do projeto público informado. A integração aplicada no site está centralizada nos arquivos acima para manter o padrão atual do Configurador.
