# IndexNow (Bing) — Implantação completa

## Pré-requisitos
- A chave deve estar configurada em `Configuracoes/BingIndexNow.ini`.
- O arquivo UTF-8 da chave deve estar acessível na URL definida em `key_file_path`.
- Esta implementação está configurada no modo **Option 2** do IndexNow, com `keyLocation` explícito.

### Exemplo (Option 2)
Arquivo da chave hospedado em um caminho alternativo no mesmo host:

```
https://www.example.com/SEO/IndexNow/abc392c302734be1af94ae150423ccf7.txt
```

No envio, o payload inclui automaticamente:

```json
{
  "host": "www.example.com",
  "key": "abc392c302734be1af94ae150423ccf7",
  "keyLocation": "https://www.example.com/SEO/IndexNow/abc392c302734be1af94ae150423ccf7.txt",
  "urlList": ["https://www.example.com/product.html"]
}
```

## Fluxo recomendado (VS Code + npm)

Se você já roda o projeto com `npm run build` no terminal do VS Code, **não precisa** executar comandos manuais de IndexNow.
O `build` já chama automaticamente o envio no final do processo (`node SEO/EnviarIndexNow.js`).

```bash
npm run build
```

Se estiver sem conectividade com o endpoint no momento, você pode gerar o build sem envio:

```bash
npm run build -- --skip-indexnow
```

Para validar apenas o payload do IndexNow durante o build (sem POST):

```bash
npm run build -- --indexnow-dry-run
```

> ⚠️ Se você usa PowerShell, não cole trechos de documentação HTTP bruta (ex.: `POST /IndexNow HTTP/1.1`, `Host: ...`, `Content-Type: ...`) diretamente no terminal.
> Esses textos **não são comandos** e geram erros como `CommandNotFoundException`.

## Endpoints disponíveis

### 1) Enviar lista de URLs manualmente

**Endpoint:** `POST /SEO/BingIndexNowNotificar.php`

**Body (JSON):**
```json
{
  "urlList": [
    "https://www.example.org/url1",
    "https://www.example.org/folder/url2"
  ]
}
```

**PowerShell (Windows) — exemplo funcional:**
```powershell
$body = @{
  urlList = @(
    "https://www.example.org/url1",
    "https://www.example.org/folder/url2"
  )
} | ConvertTo-Json

Invoke-RestMethod `
  -Method Post `
  -Uri "https://www.example.org/SEO/BingIndexNowNotificar.php" `
  -ContentType "application/json; charset=utf-8" `
  -Body $body
```

**curl — exemplo funcional:**
```bash
curl -X POST "https://www.example.org/SEO/BingIndexNowNotificar.php" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '{"urlList":["https://www.example.org/url1","https://www.example.org/folder/url2"]}'
```

### 2) Enviar URLs do sitemap

**Endpoint:** `GET /SEO/BingIndexNowEnviarSitemap.php`

**Parâmetros opcionais:**
- `sitemap`: caminho relativo de um sitemap alternativo (ex.: `sitemap-images.xml`).
- `dryRun`: `true` para apenas validar o carregamento das URLs sem enviar ao IndexNow.

**Exemplo:**
```
https://www.example.org/SEO/BingIndexNowEnviarSitemap.php?dryRun=true
```

Também é possível disparar o envio localmente pelo script do projeto:

```bash
npm run submit-indexnow
```

Para apenas gerar e inspecionar o payload (sem enviar):

```bash
npm run submit-indexnow -- --dry-run
```

## Observações
- O envio é limitado a 10.000 URLs por requisição, conforme o limite do IndexNow.
- O host é obtido por `HTTP_HOST` (ou definido manualmente em `Configuracoes/BingIndexNow.ini`).
- O `keyLocation` é montado automaticamente com base no `key_file_path` configurado.
