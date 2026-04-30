# Política de Content Security Policy (CSP)

Esta aplicação aplica CSP por requisição a partir do PHP, garantindo **nonce único por request** para scripts inline.

## Onde o CSP é aplicado

- O header `Content-Security-Policy` é emitido em `Seguranca/MetodosSegurancao.php` junto com o `nonce` de scripts.
- O helper `csp_nonce()` deve ser usado nos `<script>` inline para reutilizar o nonce gerado no servidor.

## Diretrizes atuais (resumo)

- `default-src 'self'`
- `script-src 'self' 'nonce-<dinâmico>' https://cdn.jsdelivr.net https://www.googletagmanager.com https://www.google-analytics.com`
- `style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com`
- `img-src 'self' data: https://www.googletagmanager.com https://www.google-analytics.com https://www.g.doubleclick.net https://www.google.com.br`
- `font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com`
- `connect-src 'self' https://cdn.jsdelivr.net https://www.google-analytics.com https://www.googletagmanager.com https://www.g.doubleclick.net https://analytics.google.com https://www.google.com.br https://fonts.googleapis.com https://fonts.gstatic.com`
- `frame-src 'self' https://www.googletagmanager.com`
- `frame-ancestors 'self' https://redutoresibr.com.br https://www.redutoresibr.com.br`
- `upgrade-insecure-requests` + `block-all-mixed-content`

## Uso de nonce em scripts inline

Sempre que houver `<script>` inline, use o nonce da requisição:

```html
<script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
  // conteúdo inline
</script>
```

> Evite `unsafe-inline`/`unsafe-eval` para scripts. Se precisar de inline, use o nonce.

## SRI (Subresource Integrity) para assets externos

Para qualquer asset externo (ex.: `cdn.jsdelivr.net`), incluir `integrity` + `crossorigin="anonymous"`.

Exemplo (Bootstrap):

```html
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css"
      integrity="sha384-4bw+/aepP/YC94hEpVNVgiZdgIC5+VKNBQNGCHeKRQN+PtmoHDEXuppvnDJzQIu9"
      crossorigin="anonymous">

<script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>"
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-HwwvtgBNo3bZJJLYd8oVXjrBZt8cqVSpeBNS5n7C8IVInixGAoxmnlMuBnhbgrkm"
        crossorigin="anonymous"></script>
```

## IIS / `web.config` (quando aplicável)

Se for necessário aplicar CSP diretamente no IIS para conteúdo **estático** (sem PHP), utilize o `web.config` **apenas quando não houver necessidade de nonce**.
Quando a página usa scripts inline, o CSP deve permanecer sendo gerado dinamicamente no PHP para incluir o nonce da requisição.

Se você precisar colocar o CSP no `web.config`, garanta que:

1. **Não existam** scripts inline nessas rotas, ou
2. Você configure o servidor para injetar um nonce dinâmico equivalente ao usado na resposta.

Isso evita múltiplos headers CSP conflitantes e bloqueios de script.
