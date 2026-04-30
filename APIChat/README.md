# APIChat

Esta pasta concentra os endpoints para uso em **Actions de GPT Personalizado**.

## Endpoints disponíveis

### 1) Consultar capabilities da APIChat
- **Método/URL:** `GET /APIChat/ConsultarCapacidadesApiChat.php`
- **Objetivo:** retornar no início da sessão os configuradores suportados, versões de schema/endpoints, limites operacionais (`limiteMaximo`, `maxBytesLeitura`) e recursos habilitados (como `explorarSite`, `buscaTextual`, etc.).
- **Autenticação:** enviar sempre `X-Api-Key` válido (inclusive neste endpoint) para que `autenticacao.autenticado=true` e os recursos protegidos em `capabilities.recursosHabilitados` sejam liberados.

### Boas práticas de segurança operacional
- **Chave por ambiente/escopo:** priorize variáveis separadas por ambiente e escopo (`API_CHAT_KEY_PROD`, `API_CHAT_KEY_STAGING`, `API_CHAT_KEY_ACTIONS_PROD`, etc.). No fallback em arquivo `Configuracoes/GPTActionsApiKey.ini`, prefira seções por ambiente/escopo (`[production.actions]`, `[staging.actions]`).
- **Backend-only:** `X-Api-Key` deve ser injetado apenas no backend (server-to-server). Nunca exponha a chave em JavaScript do front-end.
- **Rate-limit/WAF:** mantenha proteção de borda (Cloudflare/WAF) e limite local ativo (`API_CHAT_RATE_LIMIT_PER_MINUTE`, `API_CHAT_RATE_LIMIT_WINDOW_SECONDS`, allowlist opcional via `API_CHAT_RATE_LIMIT_ALLOWLIST_IPS`).
- **Logs sem segredo:** não registre `X-Api-Key` em logs; use apenas metadados operacionais (requestId, endpoint, status, código de erro).

### 2) Montar link final do configurador
- **Método/URL:** `POST /APIChat/GerarLinkConfigurador.php`
- **Objetivo:** receber variáveis de configuração (e/ou fala livre do solicitante) e retornar o **link oficial do configurador com querystrings** (`link`) e as `variaveisIdentificadas`.
- **Importante:** esta é a ação oficial para materializar link preenchido. Não dependa da validação de referência para isso.
- **Entrada opcional adicional:**
  - `falaSolicitante` (ou `solicitacao`): texto livre; a API identifica pares `CHAVE=VALOR` ou `CHAVE:VALOR` e também extrai variáveis de URLs coladas pelo cliente.
  - identificação de produto por múltiplas chaves: além de `codigo`/`codigoProduto` e `referencia`/`referenciaEstruturada`, também aceita `DS_REFERENCIA`, `descricao`/`descricaoProduto`/`DS_PRODUTO` e `etiqueta`/`nrEtiqueta`/`NR_ETIQUETA` para resolver `CD_PRODUTO` internamente antes de gerar o link.

### 3) Consultar produtos no banco (com paginação)
- **Método/URL:** `GET /APIChat/BuscaProduto.php?acao=consultarProdutosConfigurador`
- **Objetivo:** retornar produtos pai/filho para apoiar raciocínio e sugestão de itens sem estourar limite de tamanho do conector.
- **Parâmetros opcionais:**
  - `pagina` (default `1`)
  - `limite` (default `20`, máximo `1000`)
  - `codigo` (filtra `CD_PRODUTO` exato)
  - `produtoPai` (filtra `CD_PRODCONFIG` exato)
  - `q` (alias para `produtoPai` quando `codigo` e `produtoPai` não são enviados)
  - `somenteDescricao` (booleano; com `codigo`, retorna só `codigo`, `DS_DESCRICAO` e `DS_REFERENCIA`)
  - `modoCompacto` (booleano; retorna apenas `CodigoProduto`, `DS_REFERENCIA` e `ProdutoPai`)
- **Estratégia para catálogo completo:**
  1. chamar com `pagina=1&limite=25&modoCompacto=true`;
  2. avançar usando `proximaPagina`/`temMais` no retorno;
  3. iterar até `temMais=false` para obter 100% dos itens sem estourar limite do conector.
- **Regras de limite e paginação:**
  - `limite` máximo operacional: `1000` (se enviar acima disso, o backend trunca para `1000` e devolve o valor aplicado no campo `limite`).
  - `temMais=true` significa que há mais resultados após a página atual.
  - `proximaPagina` vem preenchido apenas quando `temMais=true`; quando `temMais=false`, retorna `null`.

- **Consulta direta por código (resposta curta):**
  - Exemplo: `/APIChat/BuscaProduto.php?acao=consultarProdutosConfigurador&codigo=HY.33604480&somenteDescricao=true`
  - Retorno: `codigo`, `DS_DESCRICAO` e `DS_REFERENCIA`.
- **SQL base aplicada no endpoint:**

```sql
SELECT DISTINCT(CD_PRODUTO) AS CodigoProduto,
       DS_PRODUTO AS DescricaoProduto,
       NR_ETIQUETA AS EtiquetaProduto,
       CD_PRODCONFIG AS ProdutoPai
FROM MMPR_PRODUTO
WHERE CD_PRODCONFIG <> 'IA'
  AND CD_PRODCONFIG <> ''
  AND CD_PRODCONFIG IS NOT NULL
  AND ID_STATUS = 0
  AND DS_REFERENCIA NOT LIKE '%2.WS%'
  AND CD_PRODUTO LIKE '%.%'
```

### 4) Buscar produto por código (match exato, payload curto)
- **Método/URL:** `GET /APIChat/BuscaProduto.php?acao=buscarProdutoPorCodigo`
- **Objetivo:** retornar rapidamente dados de um único `CD_PRODUTO` com correspondência exata (`WHERE CD_PRODUTO = ?`), com opções de payload reduzido para conectores com limitação de retorno.
- **Parâmetros:**
  - `codigo` (obrigatório; ex.: `HY.33604480`)
  - `somenteDescricao` (opcional booleano; retorna só `codigo`, `DS_DESCRICAO`, `DS_REFERENCIA`)
  - `modoCompacto` (opcional booleano; remove `etiqueta` e `observacaoDatasheet` do objeto `dados`)
- **Observação de conteúdo:** na resposta padrão/compacta, o endpoint também retorna links de apoio (`manualEGarantia`, `catalogo`, `maisInfo`, `conhecaTambem`) via `LEFT JOIN` com `_USR_LINKS_PRODUTOS`.
- **Exemplos:**
  - padrão: `/APIChat/BuscaProduto.php?acao=buscarProdutoPorCodigo&codigo=HY.33604480`
  - reduzido: `/APIChat/BuscaProduto.php?acao=buscarProdutoPorCodigo&codigo=HY.33604480&modoCompacto=true`
  - mínimo: `/APIChat/BuscaProduto.php?acao=buscarProdutoPorCodigo&codigo=HY.33604480&somenteDescricao=true`

- **Fallback automático entre buscas (novo)**
  - Use o endpoint unificado `BuscaProduto.php` com a ação adequada (`buscarProdutoPorCodigo`, `buscarProdutosPorTexto` ou `consultarProdutosConfigurador`).

> **Quando usar este endpoint vs listagem paginada?**
> - Use **`ProdutoPorCodigo`** quando já tiver o `CD_PRODUTO` completo e quiser resposta curta/rápida de 1 item.
> - Use **`ProdutosConfigurador`** quando precisar navegar catálogo, filtrar por `produtoPai`, ou descobrir itens com paginação.

### 5) Buscar produtos por texto (busca aproximada com paginação)
- **Método/URL:** `GET /APIChat/BuscaProduto.php?acao=buscarProdutosPorTexto`
- **Objetivo:** busca livre textual em campos de produto sem misturar a semântica de busca exata por código.
- **Parâmetros:**
  - `q` (obrigatório, termo da busca)
  - `tipoBusca` é legado e atualmente ignorado (a busca unificada sempre usa escopo geral completo).
  - `limite` (opcional, padrão `20`, máximo `100`)
  - `pagina` (opcional, padrão `1`)
  - `somenteDescricao` / `modoCompacto` / `incluirObservacoes` (opcionais; controlam o payload do modo resolvido)
- **Regras de limite e paginação:**
  - `limite` máximo operacional: `100` (se enviar acima, o backend trunca para `100`).
  - Avance enquanto `temMais=true`, usando `proximaPagina`.
  - Se `temMais=false`, encerre a navegação (não há mais páginas).
- **Exemplos (busca aproximada):**
  - `/APIChat/BuscaProduto.php?acao=buscarProdutosPorTexto&q=HY.3360&limite=10`
  - `/APIChat/BuscaProduto.php?acao=buscarProdutosPorTexto&q=coaxial&limite=20&pagina=2`

### 5.1) Validar referência estruturada
- **Método/URL:** `GET|POST /APIChat/BuscaProduto.php?acao=validarReferenciaEstruturadaGet` (GET) ou `/APIChat/BuscaProduto.php?acao=validarReferenciaEstruturadaPost` (POST)
- **Objetivo:** validar no backend se uma referência segue o padrão estruturado esperado, evitando lógica textual solta no cliente.
- **Entrada:** `referencia` (ou aliases `valor`, `entrada`) por querystring ou JSON no POST.
- **Saída (contrato):**
  - normalização da referência (`referenciaNormalizada`);
  - validação de formato e validade (`formatoValido`, `valida`);
  - dados do produto quando existirem (`dados`).
- **Limite do contrato:** essa ação **não garante** retorno de link preenchido do configurador.
- **Regex aplicada:** `^(?=.{1,250}$)[0-9A-Z]+(\.[0-9A-Z]+){2,}$`.

#### Regra de uso para evitar bug de expectativa
- Se o cliente quiser **link preenchido**, chame `gerarLinkConfigurador` **após** a validação (quando `valida=true`).

#### Fluxo recomendado (2 passos)
1. `validarReferenciaEstruturadaGet`/`validarReferenciaEstruturadaPost` para normalizar e validar a referência.
2. `gerarLinkConfigurador` somente quando a referência estiver válida, para obter o link oficial com querystrings e `variaveisIdentificadas`.

### 5.2) Executar SELECT livre no banco (somente leitura)
- **Método/URL:** `POST /APIChat/BuscaProduto.php?acao=consultaSelect`
- **Objetivo:** permitir que o chat envie texto livre (campo `entrada`) ou SQL direto (`sql`), extraindo e executando apenas o trecho `SELECT` para localizar informações no banco.
- **Regras de segurança:**
  - aceita apenas consultas iniciando com `SELECT` ou `WITH`;
  - bloqueia comandos de escrita/DDL (`INSERT`, `UPDATE`, `DELETE`, `DROP`, etc.);
  - bloqueia múltiplas instruções (`;`) e comentários SQL (`--`, `/* */`);
  - aplica limite automático com `TOP`.
- **Body (JSON):**
  - `sql` (opcional, string): query direta;
  - `entrada` (opcional, string): texto contendo um SELECT em qualquer trecho;
  - `limite` (opcional, inteiro 1..200, padrão `50`).
- **Exemplo:**

```bash
curl -X POST "https://configurador.redutoresibr.com.br/APIChat/BuscaProduto.php?acao=consultaSelect" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: SUA_CHAVE" \
  -d '{
    "entrada": "preciso disso: SELECT CD_PRODUTO, DS_PRODUTO FROM MMPR_PRODUTO WHERE CD_PRODCONFIG = ''HY''",
    "limite": 25
  }'
```

### 5.1) Invalidar cache por domínio (administrativo)
- **Método/URL:** `POST /APIChat/Admin/CacheInvalidate.php`
- **Objetivo:** invalidar versão de cache por domínio sem gerar efeito colateral em endpoints de leitura (`GET`).
- **Autenticação e autorização:**
  - exige a API Key padrão (`X-Api-Key`);
  - exige autorização administrativa adicional por **uma** das opções:
    - tenant em allowlist via `GPT_ACTIONS_ADMIN_CACHE_TENANTS` (comparado com header `X-Tenant-Id`); ou
    - chave administrativa dedicada via `GPT_ACTIONS_ADMIN_CACHE_KEY` + header `X-Admin-Key`.
- **Body (JSON ou form-data):**
  - `dominio` (opcional, padrão `produtos`; permitidos: `produtos`, `imagens`, `explorarSite`)
- **Resposta padrão:** inclui sempre `dominio`, `versaoDominio` (sucesso) e `versaoEndpoint`.
- **Exemplo:**

```bash
curl -X POST "https://configurador.redutoresibr.com.br/APIChat/Admin/CacheInvalidate.php" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: SUA_CHAVE" \
  -H "X-Admin-Key: SUA_CHAVE_ADMIN" \
  -d '{"dominio":"produtos"}'
```



### 10) Enviar conversa atual por email via comando `.EnviarTEEC`
- **Método/URL:** `POST /APIChat/EnviarTEEC.php`
- **OperationId no OpenAPI:** `executarEnviarTEEC`
- **Objetivo:** quando a pessoa digitar `.EnviarTEEC`, disparar um email para `braian.peruzzo@redutoresibr.com.br` contendo toda a conversa atual (organizada linha a linha) e o anexo `conversa-chatgpt.txt`.
- **Comando aceito:**
  - `.EnviarTEEC`
  - `.EnviarTEEC <texto>` (o `<texto>` entra como observação no email)
- **Campos aceitos no payload:**
  - `conversaChatGPT` (obrigatório; fallback: `conversa`)
  - `comando` (opcional, para validar/interpretar `.EnviarTEEC`)
  - `observacao` (opcional; se ausente, a API tenta extrair da cauda do comando)
- **Exemplo:**

```bash
curl -X POST "https://configurador.redutoresibr.com.br/APIChat/EnviarTEEC.php" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: SUA_CHAVE" \
  -d '{
    "comando": ".EnviarTEEC Cliente pediu retorno ainda hoje",
    "conversaChatGPT": "Cliente: quero ajuda\nAssistente: claro, vamos lá"
  }'
```


### 11) Imagens oficiais IBR (integradas à ConsultaConteudo)
- **Método/URL:** `GET|POST /APIChat/ConsultaConteudo.php`
- **Objetivo:** varrer páginas oficiais, indexar imagens públicas e devolver 1..4 URLs com metadados (`type`, `tags`, `lineFamily`, `context`, `sourcePageUrl`).
- **Rotas suportadas:**
  - `GET ?rota=search&q=...&linha=...&tipo=...&codigoProduto=...&limit=...`
  - `GET ?rota=by-product&codigo=...&limit=...`
  - `POST ?rota=resolve` (body JSON flexível com `q`/`texto`, e opcional `codigoProduto`/`codigo`)
  - `GET ?rota=status`
  - `POST ?rota=crawl`
- **Observações:**
  - retorna somente domínios da allowlist oficial;
  - ranking prioriza intenção do usuário (ex.: dimensional/montagem) e diversidade de tipos.
  - para busca por código específico (ex.: `QU.28795238`), prefira `rota=by-product`; em `rota=search/resolve`, envie `codigoProduto` (ou `codigo`) para habilitar filtro por código.
  - `stale=true` com `staleReason` indica falha no recrawl e retorno do último snapshot do índice (pode haver diferença temporária entre imagens existentes no site e `images[]` retornado).
  - ao atualizar o índice, os campos `tags`, `aliases` e `searchText` são preservados do snapshot anterior (quando existir) e não são auto-preenchidos para novas entradas.

### Migração do parâmetro `invalidateCache`
- A invalidação por querystring em endpoints de leitura foi removida de:
  - `GET /APIChat/BuscaProduto.php?acao=buscarProdutosPorTexto`
  - `GET /APIChat/BuscaProduto.php?acao=consultarProdutosConfigurador`
- Fluxos existentes devem migrar para `POST /APIChat/Admin/CacheInvalidate.php`.

### 6) Diagnóstico de sessão (unificado)
- **Método/URL:** `GET /APIChat/ConsultarCapacidadesApiChat.php` (use `acao` para trocar entre capabilities/contexto/telemetria)
- **Ações:** `consultarCapacidadesApiChat`, `consultarContextoInicial`, `consultarResumoTelemetriaUso`.

### 7) Consulta de conteúdo (unificado)
- **Método/URL:** `GET|POST /APIChat/ConsultaConteudo.php`
- **Ações:** `consultarPaginasPublicasSite`, `consultarBancoSomenteLeitura` (busca/resolve de imagens oficiais via `rota=search|resolve|by-product|status|crawl`, com `codigoProduto`/`codigo` para `by-product`).

### 8) Event store (unificado)
- **Método/URL:** `GET|POST|PUT|DELETE /APIChat/OrquestrarMemoriasETelemetria.php`
- **Ações válidas para memória:** `create`, `privacy`, `write_pipeline`, `import_training`.
- **Ações válidas para telemetria:** `registrarEventoTelemetriaUso`.
- **Ação padrão sem `acao`:** `consultarMemorias` (inclusive em `POST`).
- **Registro explícito de telemetria:** para a action `registrarEventoTelemetriaUso`, enviar `acao=registrarEventoTelemetriaUso` com `payload` contendo `{ evento, origem, severidade, contexto, requestIdsRelacionados }` (além de `consentimentoIA` quando necessário).
- **Retorno esperado no registro:** `ok`, `eventId`, `storedAt`, `requestId`.
- **Observação importante:** `orquestrarMemoriasETelemetria` é o nome da ferramenta/endpoint; no payload, o campo `acao` deve receber uma ação válida (ex.: `create`) e **não** o nome da ferramenta.

### Comandos obrigatórios e prioritários (chat)
- Ao receber os comandos abaixo em qualquer momento do chat, executar as Actions mapeadas:
  - `.RegistroMemoria` → chamar `orquestrarMemoriasETelemetria` com `acao=create`, registrando no banco de memórias o texto que vier após o comando.
    - Para evitar erro de validação de argumentos, envie o texto em `payload.content` (ou `payload.conteudo`).
  - `.EnviarTEEC` → chamar `executarEnviarTEEC`.

### 9) Pipeline de configurador (unificado)
- **Método/URL:** `GET|POST /APIChat/OrquestrarConfiguradorEReferencias.php`
- **Ações:** `gerarLinkConfigurador`, `validarReferenciaEstruturadaGet`, `validarReferenciaEstruturadaPost`.

#### Troubleshooting — `valida=false` na validação estruturada
- Para as ações `validarReferenciaEstruturadaGet`/`validarReferenciaEstruturadaPost`, envie a referência em **campo de primeiro nível** com uma destas chaves aceitas pelo validador:
  - `referencia`
  - `valor`
  - `entrada`
- Evite depender de chaves alternativas (ex.: `referenciaEstruturada`) nesse passo específico de validação.
- Formato esperado para `valida=true`:
  - apenas `[A-Z0-9]` em cada bloco;
  - blocos separados por ponto (`.`);
  - no mínimo 3 blocos (ex.: `HY.3360.4480`).
- Exemplo recomendado (POST):

```json
{
  "acao": "validarReferenciaEstruturadaPost",
  "referencia": "HY.3360.4480"
}
```

- Medida de contenção sem alterar backend (lado cliente/action):
  1. normalizar para maiúsculas;
  2. remover espaços;
  3. enviar no campo `referencia`;
  4. se retornar `valida=false`, repetir 1x registrando `requestId` para rastreabilidade.

### 7) Consultar páginas públicas do site (inclui imagens oficiais)
- **Método/URL:** `GET /APIChat/ConsultaConteudo.php`
- **Objetivo:** permitir que o GPT descubra caminhos e leia conteúdo público para entender como configurar itens quando ele ainda não conhece as variáveis.
- **Parâmetros:**
  - `acao=listar` → retorna arquivos públicos permitidos.
  - `acao=ler&path=/caminho/arquivo` → lê conteúdo de um arquivo permitido.
- **Bloqueios aplicados:** preço, estoque e áreas privadas/sensíveis.

### 8) Subsistema de memória contextual (CRUD + pipelines)
- **Métodos/URL:** `GET|POST|PUT|DELETE /APIChat/GerenciarMemorias.php`
- **Objetivo:** armazenar e recuperar memórias contextuais com escopos `session`, `user`, `workspace`, TTL e políticas de atualização.
- **Recursos implementados:**
  - CRUD de memórias com `tags` e `semanticTags`;
  - pipeline de escrita (`write_pipeline`) com classificação de relevância e segurança;
  - pipeline de recuperação com ranking híbrido (`vetorial + recência + prioridadeNegocio`);
  - privacidade: opt-in por escopo e `purge` total.
- **Parâmetros relevantes:**
  - `scope` (`session|user|workspace|tenant`)
  - `memoryType` (`contextual|structural`) para separar memória contextual vs estrutural
  - `ttlSec` (TTL em segundos)
  - defaults de TTL por escopo quando `memoryType=contextual`: `session=1 dia`, `user=30 dias`, `workspace/tenant=90 dias`
  - `memoryType=structural` sem expiração por padrão (ou `ttlSec` explícito, máximo 365 dias)
  - `updatePolicy` (`overwrite|merge_tags|append_history`)
  - `businessPriority` (0..100)
  - `tags` / `semanticTags` (arrays)
  - `action=privacy` com `optIn=true|false`.

---

### 9) Abrir solicitação de cotação (integração GPT)
- **Método/URL:** `POST /APIChat/SolicitacaoCotacao.php`
- **Objetivo:** receber os dados coletados no chat e abrir uma solicitação de cotação simplificada no fluxo comercial (fila `piperun_solicitacao`), mesmo sem produto definido.
- **Campos obrigatórios no JSON enviado pelo GPT (para abrir formulário):**
  - `nome` (**Nome Completo**, nome + sobrenome)
  - `documento` (CPF/CNPJ com ou sem máscara)
  - `email` (string com um ou mais e-mails separados por `;`, ex.: `a@b.com.br;c@d.com.br`)
  - `telefoneCelular` (ou `telefone`/`celular`) com DDD
  - `quantidade` (inteiro > 0)
  - `conversaChatGPT` (texto com a conversa da pessoa + respostas do chat)
- **Campos opcionais recomendados:**
  - `empresa`, `observacoes` (máx. 250 chars), `referenciaProduto`, `codigoProduto`, `link`, `origem`
  - `referenciaProduto`/`codigoProduto` são opcionais: se vierem, entram na nota; se não vierem, a cotação é aberta mesmo assim
- **Validações aplicadas no backend:**
  - validação de nome completo (mínimo nome e sobrenome);
  - validação de CPF/CNPJ (dígitos verificadores, aceitando entrada com máscara ou só números);
  - para CNPJ, consulta automática do nome da empresa (`obter_nome_empresa`) como já ocorre no site;
  - validação de telefone/celular com DDD (aceita com/sem `+55`);
  - validação/deduplicação de e-mails recebidos no formato com `;`;
  - `observacoes` opcional com limite de 250 caracteres;
  - rate limit por contato (`20` solicitações/hora);
  - retorno de erro padronizado (`codigoErro`).

  - título da oportunidade com prefixo: `CHATGPT -` + texto padrão da cotação;
  - adiciona uma **nota adicional** na oportunidade:
    - `Conversa extraída do ChatGPT:`
    - conteúdo de `conversaChatGPT` separado por linha.
  - registra o número da oportunidade em `_USR_CONF_SITE_HISTORICO_COTACAO` para **cada e-mail** informado, no mesmo padrão do fluxo atual do site.
  - como a criação da oportunidade é assíncrona (fila), a API **não exibe protocolo fictício**: `protocolo`/`oportunidade` retornam `null` no envio inicial.
  - para evitar ruído em `gpt-chat-erros-telemetria`, o retorno de sucesso desta API não envia bloco `telemetria`.
- **Schema pronto para formulário (ChatGPT Action):** `APIChat/Schemas/SolicitacaoCotacao.form.schema.json`
- **Resposta de sucesso (`202`):** `ok`, `mensagem`, `jobId`, `oportunidade=null`, `protocolo=null`, `dadosRecebidos`.
- **Logs e rastreabilidade:**
  - erros e telemetria no pipeline padrão: `APIChat/TreinamentoErros/gpt-chat-erros-telemetria-AAAA-MM-DD.jsonl`;
  - logs específicos de falha do endpoint: `APIChat/Logs/solicitacao-cotacao-AAAA-MM-DD.jsonl`.
- **Exemplo de payload (o que o GPT deve enviar):**

```json
{
  "nome": "João da Silva",
  "documento": "123.456.789-09",
  "email": "joao@empresa.com.br;compras@empresa.com.br",
  "telefoneCelular": "+55 11 99999-9999",
  "quantidade": 120,
  "observacoes": "Necessário entrega em 15 dias.",
  "referenciaProduto": "HY 3360",
  "codigoProduto": "HY.33604480",
  "origem": "gpt_chat",
  "conversaChatGPT": "USUÁRIO: Preciso de preço para 120 peças.\nASSISTENTE: Claro, para qual aplicação?\nUSUÁRIO: Aplicação em redutor coaxial, entrega em 15 dias."
}
```

## Guia operacional para o ChatGPT (fluxo de cotação)

Para padronizar a coleta de dados, validações e envio da cotação pelo chat, consulte:

- `APIChat/GuiaFluxoCotacaoChatGPT.md`

Esse guia descreve passo a passo de abordagem, campos obrigatórios, montagem de `conversaChatGPT`, payload recomendado e tratamento de erros de API.

## Guia operacional para o ChatGPT (fluxo de solicitação de desenho)

Para padronizar a coleta de dados, validações de elegibilidade por linha, tentativa de obtenção de código e mensagem final de confirmação no fluxo de desenho, consulte:

- `APIChat/GuiaFluxoDesenhoChatGPT.md`

### 10) Abrir solicitação de desenho (integração GPT, fluxo rígido)
- **Método/URL:** `POST /APIChat/SolicitacaoDesenho.php`
- **Objetivo:** abrir solicitação de desenho técnico com validação rígida de campos e de produto ativo no banco.
- **Campos obrigatórios no JSON (formulário do GPT):**
  - `nome` (**Nome Completo**, nome + sobrenome)
  - `documento` (**CPF/CNPJ**, válido; aceita com pontuação ou só números)
  - `email` (string obrigatória com um ou mais e-mails separados por `;`, ex.: `xx@yy.com.br;xx@yy.com.br`)
  - `codigoProduto` (obrigatório, formato `XXXX.00000000`)
  - `formatoDesenho` (obrigatório, aceita número, lista de números ou descrição)
- **Lista de formatos (retorno textual exato):**

```text
1 - 3D (PARASOLID)
2 - 3D (PARASOLID BINÁRIO)
3 - PDF
4 - STEP
5 - DWG
6 - IGES
7 - DXF
8 - SAT
```

- **Como enviar `formatoDesenho`:**
  - valor único: `1`
  - múltiplos na string: `1;3;8`
  - múltiplos em array: `[1,3,8]`
  - descrição também é aceita (ex.: `PDF`)
  - a API converte automaticamente para os nomes oficiais dos formatos.

- **Validações aplicadas no backend:**
  - nome completo obrigatório;
  - CPF/CNPJ válido (dígitos verificadores), com ou sem pontuação;
  - para CNPJ, validação segue a mesma regra do validador do site (`AcessosConsultas/ValidacaoDocumento.js`) e consulta automática do nome da empresa (`obter_nome_empresa`);
  - um ou múltiplos e-mails válidos no campo `email`, separados por `;` (formato rígido com deduplicação);
  - `codigoProduto` obrigatório e validado com consulta rígida:
    - `SELECT TOP 1 CD_PRODUTO FROM MMPR_PRODUTO WHERE ID_STATUS = 0 AND CD_PRODUTO = ?`
    - se não retornar linha, a API devolve erro;
  - validação de linha do produto (`CD_PRODCONFIG`) permitida apenas para: `QU`, `QUDR`, `MO`, `PL`, `VA`, `HY`;
  - para `HY`, valida `HYLN` somente com `1.P` ou `1.M` usando:
    - `SELECT TOP 1 DS_VARIAVEL.value('(/VariavelOpcaoSimples/Valor)[1]', 'varchar(4000)') as RESPOSTA_SELETOR FROM MMPR_PRODUTOESTRUTURA WHERE CD_PRODUTO = ? AND NM_VARIAVEL = 'HYLN'`
  - se linha/HYLN não atenderem, retorna erro informando que está em desenvolvimento;
  - cria solicitação na base de projetos (`PMPR_PROJETO`, `_USR_PMPR_PROJETO`, `_USR_PMPR_PROJETO_DRIVEWORKS`) e usa o número da solicitação no retorno e rastreabilidade;
  - registra histórico na tabela `_USR_CONF_SITE_HISTORICO_DESENHO` para **cada e-mail** informado (seguindo padrão do site);
  - rate limit por contato (`20` solicitações/hora).
- **Schema pronto para formulário (ChatGPT Action):** `APIChat/Schemas/SolicitacaoDesenho.form.schema.json`
- **Resposta de sucesso (`202`):** `ok`, `mensagem`, `mensagemCliente` (texto pronto para o GPT responder na lata), `jobId`, `formatosPermitidosTexto`, `formatosPermitidos`, `dadosRecebidos` (inclui `numeroSolicitacao` e `drvwIdField`), `orientacaoGPT`.
- **Logs e rastreabilidade:**
  - erros e telemetria no pipeline padrão: `APIChat/TreinamentoErros/gpt-chat-erros-telemetria-AAAA-MM-DD.jsonl`;
  - logs específicos do endpoint (inclusive validações `4xx`): `APIChat/Logs/solicitacao-desenho-AAAA-MM-DD.jsonl`.
  - possíveis `codigoErro` registrados no log:
    - `DADOS_INCOMPLETOS`
    - `FORMATO_EMAIL_INVALIDO`
    - `NOME_COMPLETO_INVALIDO`
    - `DOCUMENTO_INVALIDO`
    - `FORMATO_DESENHO_INVALIDO`
    - `LINK_INVALIDO`
    - `CODIGO_PRODUTO_INVALIDO`
    - `PRODUTO_NAO_ENCONTRADO`
    - `LINHA_EM_DESENVOLVIMENTO`
    - `HYLN_NAO_PERMITIDO`
    - `RATE_LIMIT_EXCEDIDO`
    - `ERRO_INSERCAO_SOLICITACAO`
    - `ERRO_INTERNO_SOLICITACAO_DESENHO`

## Autenticação

### Modelo por classe de endpoint

1. **Classe `diagnóstico`**
   - Endpoint: `GET /APIChat/ConsultarCapacidadesApiChat.php`
   - Comportamento: responde mesmo sem `X-Api-Key` para diagnóstico inicial da sessão.
   - Objetivo: permitir verificação prévia do estado de autenticação antes de chamar endpoints operacionais.

2. **Classe `protegida`**
   - Endpoints: todos os demais endpoints operacionais da pasta `APIChat`.
   - Comportamento: exigem `X-Api-Key` válido.
   - Erro esperado sem chave válida: `401 NAO_AUTORIZADO` (`codigoErro=NAO_AUTORIZADO`).

Todos os endpoints da classe `protegida` usam **API Key** no header:

- `X-Api-Key: SUA_CHAVE`

A chave pode vir de:
1. Variável de ambiente `GPT_ACTIONS_API_KEY`; ou
2. Arquivo `Configuracoes/GPTActionsApiKey.ini` (copie de `Configuracoes/GPTActionsApiKey.ini.example`).

### Sessão autenticada no runtime do agente (sem enviar header em cada chamada)

Para ambientes de runtime onde o agente não consegue propagar `X-Api-Key` em todas as chamadas, é possível habilitar uma sessão autenticada por variável de ambiente:

- `API_CHAT_RUNTIME_SESSION_AUTH=1`
- `API_CHAT_SESSION_API_KEY=<mesma chave configurada em GPT_ACTIONS_API_KEY>`

Com isso, a API usa a chave de sessão como fallback apenas quando não há `X-Api-Key`/`Authorization` no request.

> ⚠️ Use esse modo somente quando necessário no runtime interno do agente. Em cenários públicos, mantenha o envio explícito de `X-Api-Key` por header.

### Checklist de prontidão

Antes de liberar fluxos de validação de código/referência (e demais operações protegidas), siga este checklist:

1. Chamar `GET /APIChat/ConsultarCapacidadesApiChat.php`.
2. Validar no retorno: `autenticacao.diagnostico.readyForProtectedActions=true`.
3. Somente então permitir fluxos de:
   - validação por código/referência;
   - busca operacional;
   - montagem de link final e demais ações protegidas.

### Troubleshooting rápido (erros comuns em produção)

- **`401 NAO_AUTORIZADO` em qualquer operação**
  - O endpoint da APIChat exige `X-Api-Key` válido em todas as operações protegidas (incluindo `gerarLinkConfigurador` e `consultaPaginasSite`).
  - Confirme se a chave foi configurada na Action e enviada no header `X-Api-Key`.
  - Exemplo de payload de erro esperado:
    ```json
    {
      "ok": false,
      "erro": "API key ausente ou inválida.",
      "codigoErro": "NAO_AUTORIZADO",
      "acaoCorretiva": "Configure a Action com o header X-Api-Key contendo a mesma chave ativa no servidor.",
      "requestId": "9f2ca5f62b834f17bb1cc8d9f5165c77"
    }
    ```
  - Ação corretiva direta na Action (ChatGPT):
    1. Abrir **Autenticação** da Action.
    2. Selecionar **Chave API** → **Personalizado**.
    3. Nome do header: `X-Api-Key`.
    4. Valor: mesma chave ativa no servidor (`GPT_ACTIONS_API_KEY`/`Configuracoes/GPTActionsApiKey.ini`).

- **`400` no `consultaPaginasSite` com `acao=ler`**
  - Rode primeiro `acao=listar` e use exatamente um caminho retornado na lista.
  - Exemplo real válido para os seletores AC:
    - ✅ `/PaginasConfiguradoresSeletores/SeletoresConfiguradorAC.html`
    - ❌ `/PaginasConfiguradores/SeletoresConfiguradorAC.html`

- **Falha do conector mesmo com HTTP `200` em `gerarLinkConfigurador`**
  - Verifique se o body foi enviado como JSON (`Content-Type: application/json`).
  - Confirme se o payload inclui ao menos um entre `variaveis`, `falaSolicitante` ou `solicitacao`.
  - Use `X-Request-Id` para rastrear a tentativa em logs e auditoria.

- **Log detalhado de erros (novo)**
  - Toda resposta com status `>= 400` agora gera um log NDJSON em `APIChat/Logs/Errors/api-chat-errors-AAAA-MM-DD.log`.
- **Treinamento de erros + telemetria do GPT chat (novo)**
  - Respostas com erro (`status >= 400`) **ou** com bloco `telemetria` passam a ser registradas em `APIChat/TreinamentoErros/gpt-chat-erros-telemetria-AAAA-MM-DD.jsonl`.
  - O registro inclui `endpoint`, `requestId`, `status`, `ok`, `erro`, `codigoErro` e `telemetria` para retroalimentar regras de conhecimento local.
  - O log inclui: `requestId`, endpoint, query, headers de diagnóstico (sem expor chave), body de entrada (com redaction de compliance), payload de resposta e guardrails.
  - Exemplo para coletar as últimas linhas: `tail -n 50 APIChat/Logs/Errors/api-chat-errors-$(date +%F).log`.


---

## Tuning de storage compartilhado (Redis/SQL Server/SQLite)

O módulo `APIChat/_shared/SharedStorage.php` suporta ajustes por variáveis de ambiente para produção.

### Backend e fallback
- `GPT_ACTIONS_STORAGE_REDIS_HOST`: habilita backend Redis quando a extensão `redis` está ativa.
- `GPT_ACTIONS_STORAGE_REDIS_PORT` (padrão `6379`)
- `GPT_ACTIONS_STORAGE_REDIS_TIMEOUT` (padrão `1.5` segundos)
- `GPT_ACTIONS_STORAGE_REDIS_AUTH` (opcional)
- `GPT_ACTIONS_STORAGE_REDIS_DB` (padrão `0`)
- `GPT_ACTIONS_STORAGE_BACKEND` (opcional): força backend `redis`, `sqlsrv`, `sqlite` ou `file`.
- `GPT_ACTIONS_STORAGE_FILE_FALLBACK`: fallback para arquivo local (uso em dev/local).

### SQL Server (PDO SQLSRV)
- Habilitado automaticamente quando a extensão `pdo_sqlsrv` está ativa e `DB_HOST` + `DB_NAME` estão definidos.
- Usa as credenciais padrão do projeto (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
- `GPT_ACTIONS_STORAGE_SQLSRV_TABLE` (opcional, padrão `ApiChatKvStore`): nome da tabela de chave/valor.

### SQLite (PRAGMA + contenção)
- `GPT_ACTIONS_STORAGE_SQLITE_JOURNAL_MODE` (padrão `WAL`)
- `GPT_ACTIONS_STORAGE_SQLITE_SYNCHRONOUS` (padrão `NORMAL`)
- `GPT_ACTIONS_STORAGE_SQLITE_BUSY_TIMEOUT_MS` (padrão `2000`)

### Limpeza periódica de expirados (SQLite)
- `GPT_ACTIONS_STORAGE_SQLITE_CLEANUP_EVERY`: executa limpeza por lote a cada N operações de storage (padrão `200`).
- `GPT_ACTIONS_STORAGE_SQLITE_CLEANUP_BATCH`: quantidade máxima de chaves expiradas removidas por ciclo (padrão `100`).

### Métricas internas de storage
- `GPT_ACTIONS_STORAGE_METRICS_ENABLED` (padrão `1`): habilita coleta de métricas internas (hit/miss e latência média por backend/operação).

As métricas ficam em memória do processo PHP e podem ser consultadas via `api_chat_storage_metrics_snapshot()` para comparar Redis, SQL Server e SQLite durante testes de carga/tuning.

---

## Como preencher cada campo no Actions do GPT (passo a passo detalhado)

Na tela **Adicionar ações** do GPT:

### Campo: Autenticação
1. Selecione **Chave API**.
2. Tipo: **Personalizado**.
3. Nome do cabeçalho personalizado: `X-Api-Key`.
4. Valor da chave: a mesma chave configurada no servidor (`GPT_ACTIONS_API_KEY` ou `Configuracoes/GPTActionsApiKey.ini`).
5. Clique em **Salvar**.

> Recomendação: não use "Nenhum". OAuth só é necessário se você quiser login por usuário final; para integração servidor-servidor, API Key é o melhor custo/segurança.

### Campo: Schema
1. Abra o arquivo `APIChat/GPTActionConfigurador.openapi.json`.
2. Copie e cole todo o JSON no campo **Schema** (ou use "Importar de URL" se publicar esse JSON em uma URL pública).
3. Confirme que `servers.url` está correto para seu domínio público:
   - `https://configurador.redutoresibr.com.br`
4. Salve.

### Campo: Exemplos de uso (alinhar com OpenAPI)
Use estes exemplos no teste da Action para validar caminho feliz e erro padronizado:

- `gerarLinkConfigurador` (runtime orientado a etapas):
  - Suporta `requires_confirmation=true` para operações sensíveis.
  - Suporta `confirmed=true` para confirmar a execução quando exigido.
  - Suporta `idempotency_key` por chamada para replay seguro.
  - Suporta `timeout_ms` para política de timeout por execução.
  - Retorna `runtime.steps` com etapas `plan`, `execute`, `verify`, `finalize`.
  - Execução interna usa contrato de ferramenta versionado (`schemaVersion`) com validação estrita de argumentos/resposta.
  - Retry policy por tipo de erro: `timeout`, `rate_limit` (429) e `schema_mismatch` (sem retry por padrão).
  - Circuit breaker por ferramenta/versão evita novas tentativas enquanto o circuito estiver aberto.
- `gerarLinkConfigurador` (sucesso):
  - body:
    ```json
    {
      "falaSolicitante": "quero IBRQ com QULN=1.Q e QUBR=075",
      "variaveis": {
        "QUCM": "90"
      }
    }
    ```
  - retorno esperado: `ok=true` + `link` amigável + `variaveisIdentificadas`.
- `buscarProdutoPorCodigo`:
  - `/APIChat/BuscaProduto.php?acao=buscarProdutoPorCodigo&codigo=HY.33604480&somenteDescricao=true`
  - retorno esperado: `codigo`, `DS_DESCRICAO`, `DS_REFERENCIA`.
- `consultarProdutosConfigurador` (paginação):
  - `/APIChat/BuscaProduto.php?acao=consultarProdutosConfigurador&produtoPai=HY&pagina=1&limite=25&modoCompacto=true`
  - retorno esperado: `temMais` + `proximaPagina` para paginação.
- `buscarProdutosPorTexto` (unificada/recomendada):
  - `/APIChat/BuscaProduto.php?acao=buscarProdutosPorTexto&q=coaxial&limite=20&pagina=1`
  - retorno esperado: `paginacao.temMais` + lista em `dados`.
- erro padronizado (`ErrorResponse`) em 4xx/5xx:
  ```json
  {
    "ok": false,
    "erro": "Parâmetros obrigatórios ausentes ou fora do formato esperado.",
    "codigoErro": "PARAMETROS_INVALIDOS",
    "requestId": "9f2ca5f62b834f17bb1cc8d9f5165c77"
  }
  ```

### Campo: Instruções do GPT (recomendado)
No prompt do seu GPT, adicione orientação para uso da Action:
- Pre-flight obrigatório (nesta ordem): `consultarCapabilitiesApiChat` -> `memoryRetrieve` -> `obterTelemetriaUso`.
- Se qualquer etapa do pre-flight falhar, bloquear recomendação técnica, registrar erro, tentar 1 retry reduzido e, se persistir, seguir em fallback manual pedindo dado mínimo crítico.
- Comandos com prioridade imediata:
  - `.RegistroMemoria <texto>` -> `orquestrarMemoriasETelemetria` com `acao=create`.
  - `.ExibeMemoria` -> `memoryRetrieve`.
  - `.ApagaMemoria <id>` -> `memoryDelete`.
- Após pre-flight, só então seguir com exploração (`consultaPaginasSite`), busca de produto (preferencialmente `buscarProdutosPorTexto`) e `gerarLinkConfigurador` quando variáveis obrigatórias estiverem completas.

#### Prompt-base sugerido (pronto para colar)

```text
Assistente Técnico e Consultivo.

Você é um assistente técnico e consultivo. Seu objetivo é tirar dúvidas técnicas, recomendar produto e configuração com base exclusivamente em dados confirmados, reduzir retrabalho e guiar o usuário até o produto correto no configurador. É proibido responder por suposição, experiência genérica ou padrão de mercado. Toda afirmação técnica deve estar validada nas fontes permitidas.

Fontes permitidas, em ordem obrigatória: conhecimento interno e documentos fornecidos pela equipe, site institucional, configurador oficial e API via Actions somente para consultar e cruzar dados que já existam nas fontes anteriores. A API não é fonte primária de verdade, ela apenas acessa e valida informações já existentes nas fontes oficiais.

Regra de verdade sem exceção: se a informação não estiver nas fontes permitidas, responder exatamente “Não encontrei essa informação nas fontes disponíveis.” Não complementar com hipóteses, estimativas ou aproximações técnicas.

É proibido inventar, assumir ou estimar especificações técnicas, dimensões, desenhos, limites mecânicos ou térmicos, códigos de produto, compatibilidade entre componentes, montagem, posição de instalação, lubrificação, tipo de óleo, potência suportada, torque suportado, relação de redução exata sem validação, prazo, preço, disponibilidade ou qualquer dado não confirmado.

Pre-flight obrigatório antes de qualquer resposta técnica:
1) consultarCapabilitiesApiChat
2) memoryRetrieve
3) obterTelemetriaUso

A conversa técnica só pode prosseguir após as três etapas retornarem sucesso com resposta válida e interpretável.

Mesmo em perguntas simples, o pre-flight é obrigatório. O resultado de memoryRetrieve deve ser usado como contexto ativo para evitar repetição e reaproveitar dados já registrados. O resultado de obterTelemetriaUso deve ser analisado para identificar falhas anteriores, gargalos e tentativas já feitas. Se houver erro recorrente ou tentativa malsucedida, mudar a abordagem e sinalizar isso em uma frase simples.

Regra de bloqueio: se qualquer etapa do pre-flight falhar por timeout, erro HTTP, resposta vazia, resposta inválida ou falha de parsing, é proibido recomendar produto, validar especificação, confirmar compatibilidade, afirmar código ou concluir seleção. Nesse caso, registrar log de erro, tentar novamente apenas uma vez com escopo reduzido e, se falhar novamente, informar que não foi possível recuperar memórias ou telemetria e solicitar um dado mínimo crítico para seguir manualmente.

Comandos de chat com prioridade total (executar imediatamente):
- .RegistroMemoria <texto> -> executar orquestrarMemoriasETelemetria com acao=create e payload.content=<texto>, retornando confirmação com id.
- .ExibeMemoria -> executar memoryRetrieve e listar registros com id e resumo curto.
- .ApagaMemoria <id> -> executar memoryDelete para o id e confirmar remoção.

Se houver conflito em .RegistroMemoria, exigir confirmação explícita:
- retornar requiresConfirmation=true e instrução para repetir com .RegistroMemoria --confirmar <texto>.

Antes de qualquer recomendação técnica, validar conteúdo no conhecimento interno. Se necessário, confirmar no site institucional e no configurador oficial. A API deve ser usada apenas para consultar/cruzar dados que já existam nessas fontes. Em caso de divergência, priorizar conhecimento interno e configurador, registrar inconsistência e não concluir recomendação sem confirmação.

Fluxo padrão após o pre-flight:
- validar conteúdos internos;
- explorar páginas públicas quando necessário;
- ler apenas o que for relevante;
- busca unificada com `buscarProdutosPorTexto` (código/etiqueta/referência exatos + descrição/observação por LIKE);
- listagem ampla com consultarProdutosConfigurador;
- gerarLinkConfigurador só com variáveis obrigatórias completas.
Se faltar variável crítica, fazer perguntas curtas e objetivas (máximo 3 por rodada).

Telemetria deve ser consultada no início da conversa e antes de repetir tentativa após falha. Registrar log quando houver timeout, erro HTTP, resposta inválida, inconsistência entre fontes, parâmetro crítico ausente, falha de parsing ou erro recorrente.

- **Fallback controlado recomendado:** quando uma Action crítica falhar ou não retornar, responder com orientação conceitual curta + solicitar **apenas 1 dado** para destravar a próxima tentativa, sem travar a conversa.
- **Telemetria obrigatória nesse fallback:** registrar de forma consistente `configuracao_incompleta` (quando faltar contexto mínimo) e `falha_falta_dado` (quando a execução falhar por ausência de dado crítico).

Responder primeiro com o que estiver confirmado nas fontes e depois pedir somente o mínimo que falta. Nunca travar a conversa. Sempre orientar próximo passo prático. Incluir ao menos um link oficial quando o assunto envolver produto/seleção/configuração/catálogo/desenho/dimensões/modelos/código; se não houver link específico confirmado, usar o link base do configurador oficial. Se o usuário pedir explicitamente sem link, não incluir URL.

Antes de recomendar produto, coletar contexto mínimo: aplicação, potência (kW/cv) e rpm de entrada, rotação de saída desejada ou relação, torque de saída ou dados para estimar, montagem/posição/tipo de eixo e regime/ambiente (máximo 3 perguntas por rodada).

Se o usuário não souber torque, usar apenas:
- T (Nm) = 9550 × P(kW) / n(rpm)
- 1 cv ≈ 0,7355 kW

Com dados suficientes e confirmação nas fontes, apresentar opção principal e alternativa (se fizer sentido), justificando por tipo/faixa/tamanho, montagem/eixo e pontos de atenção, deixando claro o que foi confirmado e o que depende de informação adicional do usuário.

Ao orientar no configurador, descrever passo a passo curto e objetivo, gerar link direto somente quando todas as variáveis obrigatórias estiverem preenchidas e nunca montar link com suposição.

Toda resposta deve terminar com próximo passo claro, solicitando variável faltante ou confirmação simples (ex.: montagem por pé ou flange / tipo de eixo).
```

##### Como forçar o pre-flight no início de toda conversa (recomendação prática)

No backend da API, não existe um evento universal de “início de chat” para obrigar isso sozinho em todos os clientes. Para forçar de forma confiável, implemente no orquestrador/cliente:

1. ao abrir nova conversa, executar automaticamente (nesta ordem):
   - `consultarCapabilitiesApiChat`
   - `memoryRetrieve`
   - `obterTelemetriaUso`
2. só liberar mensagens técnicas após sucesso das 3 chamadas;
3. em falha, aplicar a regra de bloqueio (1 retry com escopo reduzido; depois fallback manual);
4. opcionalmente, enviar um flag de sessão (ex.: `preflightOk=true`) e bloquear respostas técnicas quando ausente.

---

## OpenAPI

Arquivo pronto para Actions:

- `APIChat/GPTActionConfigurador.openapi.json`


## Middleware comum (request-id, logs e cache)

Os endpoints em `APIChat/` compartilham middleware comum com:

- **Geração de `requestId`** para rastreabilidade nas respostas JSON.
- **Log estruturado mínimo** em JSON
  - campos: `timestamp`, `endpoint`, `method`, `status`, `latencyMs`, `requestId`
  - sem payloads e sem dados sensíveis
- **Cache em disco para listagens custosas**
  - `ConsultaConteudo` mantém cache curto da listagem de páginas públicas para evitar varredura completa do disco em chamadas repetidas.
  - Variáveis de ambiente para ajuste:
    - `GPT_ACTIONS_EXPLORAR_CACHE_TTL` (padrão `120`, em segundos)

### 9) Telemetria inteligente de uso para contexto do chat
- **Método/URL:**
  - `POST /APIChat/TelemetriaUso.php` para registrar evento
  - `GET /APIChat/TelemetriaUso.php` para consultar resumo e últimos eventos
- **Objetivo:** identificar, gravar e compartilhar sinais de uso relevantes para o GPT adaptar respostas com base no comportamento real dos usuários.
- **Boas práticas implementadas:**
  - anonimização com `usuarioHash`
  - sanitização de metadados (remove campos sensíveis como `senha`, `token`, `cpf`, `cnpj`, `email`, `telefone`) e limita o tamanho dos campos
  - deduplicação por `fingerprint` em janela curta para evitar gravações repetidas
  - retenção automática por tempo (limpeza de eventos antigos) e teto máximo de eventos armazenados
  - resumo agregado por tipo de evento, origem, páginas, produtos e seletores mais usados
- **Consentimento no POST:**
  - não é mais bloqueante para registrar telemetria;
  - os campos de consentimento (`cookie lgpd-cookies-consent`, `consentimentoIA`, `X-Consentimento-IA`) continuam aceitos por compatibilidade retroativa.
- **Body mínimo no POST:**
  ```json
  {
    "evento": "configurador_aberto",
    "origem": "site",
    "sessaoId": "sessao-123",
    "metadados": {
      "configurador": "MO",
      "passo": "seletores"
    }
  }
  ```
- **Compatibilidade de payload:** caso o cliente só consiga enviar `acao`/`ação`/`event`, o backend converte automaticamente para `evento`.
- **Eventos estratégicos recomendados para contexto do chat API:**
  - `configuracao_incompleta`
  - `falha_falta_dado`
  - `aplicacao_critica`
  - `consulta_codigo_direto`
- **Normalização de nome do evento no backend:**
  - aliases em linguagem natural também são aceitos (ex.: `Configuração incompleta`, `Falha por falta de dado`, `Aplicação crítica`, `Consulta por código direto`) e convertidos para os nomes canônicos acima.

## `response_schema` no endpoint principal (`GerarLinkConfigurador`)

O endpoint `POST /APIChat/GerarLinkConfigurador.php` aceita agora o campo opcional `response_schema` (JSON Schema) e a política `response_schema_policy` para validação server-side da resposta final.

### Payload (campos novos)
- `response_schema` *(opcional)*: objeto JSON Schema.
- `response_schema_policy` *(opcional)*: `strict_fail` (padrão), `auto_repair` ou `best_effort`.

### Políticas
- `strict_fail`: se a resposta não conformar com o schema, retorna `422` com erro padronizado e caminho JSONPath (ex.: `$.items[2].price`).
- `auto_repair`: tenta reparar/normalizar a resposta para encaixar no schema e só retorna erro se ainda ficar inválida.
- `best_effort`: retorna a resposta mesmo com divergência e adiciona `_schemaAviso` com detalhes.

### Erro padronizado (`strict_fail`)
```json
{
  "ok": false,
  "erro": "Resposta não está em conformidade com response_schema.",
  "codigoErro": "RESPONSE_SCHEMA_VALIDATION_FAILED",
  "detalhes": {
    "path": "$.variaveisIdentificadas",
    "mensagem": "Campo obrigatório ausente.",
    "codigo": "SCHEMA_REQUIRED_MISSING"
  }
}
```

### Observabilidade e métricas
As métricas de conformidade por modelo e por schema são gravadas em:
- `APIChat/Logs/response-schema-metricas-AAAA-MM-DD.jsonl`

Cada linha inclui:
- `modelo` (configurador atendido, ex.: `MO`),
- `schemaHash` (hash curto do schema recebido),
- `policy`,
- `conforme` (true/false),
- `timestamp`.

## Exemplos oficiais SDK

### Python
```python
import requests

url = "https://configurador.redutoresibr.com.br/APIChat/GerarLinkConfigurador.php"
headers = {
    "Content-Type": "application/json",
    "X-Api-Key": "<SUA_API_KEY>"
}

payload = {
    "configurador": "MO",
    "variaveis": {
        "MOLN": "3.A",
        "MOTP": "TRIF"
    },
    "response_schema_policy": "strict_fail",
    "response_schema": {
        "type": "object",
        "required": ["ok", "configurador", "link"],
        "properties": {
            "ok": {"type": "boolean", "const": True},
            "configurador": {"type": "string"},
            "link": {"type": "string", "minLength": 10},
            "variaveisIdentificadas": {
                "type": "object",
                "additionalProperties": {"type": "string"}
            }
        },
        "additionalProperties": True
    }
}

resp = requests.post(url, json=payload, headers=headers, timeout=30)
print(resp.status_code)
print(resp.json())
```

### TypeScript
```ts
type ResponseSchemaPolicy = 'strict_fail' | 'auto_repair' | 'best_effort';

const url = 'https://configurador.redutoresibr.com.br/APIChat/GerarLinkConfigurador.php';

const payload = {
  configurador: 'MO',
  variaveis: {
    MOLN: '3.A',
    MOTP: 'TRIF',
  },
  response_schema_policy: 'best_effort' as ResponseSchemaPolicy,
  response_schema: {
    type: 'object',
    required: ['ok', 'configurador', 'link'],
    properties: {
      ok: { type: 'boolean', const: true },
      configurador: { type: 'string' },
      link: { type: 'string', minLength: 10 },
      variaveisIdentificadas: {
        type: 'object',
        additionalProperties: { type: 'string' },
      },
      _schemaAviso: {
        type: 'object',
        properties: {
          codigo: { type: 'string' },
          path: { type: 'string' },
          mensagem: { type: 'string' },
        },
      },
    },
  },
};

const response = await fetch(url, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Api-Key': '<SUA_API_KEY>',
  },
  body: JSON.stringify(payload),
});

console.log(response.status, await response.json());
```

## Fluxo assíncrono padrão (202 Accepted)

O endpoint `ConsultaConteudo` aceita `async=true` para retornar contrato comum:

- `202 Accepted`
- `jobId`
- `statusUrl`
- `cancelUrl`
- header `X-Job-Id`
- header `Retry-After`

### Exemplo (`ConsultaConteudo`)

```bash
curl -s "https://configurador.redutoresibr.com.br/APIChat/ConsultaConteudo.php?acao=listar&async=true" \
  -H "X-Api-Key: SUA_CHAVE"
```

Resposta inicial (202):

```json
{
  "ok": true,
  "status": "accepted",
  "jobId": "job_abc123",
  "statusUrl": "/APIChat/ConsultaConteudo.php?acao=status&jobId=job_abc123",
  "cancelUrl": "/APIChat/ConsultaConteudo.php?acao=cancel&jobId=job_abc123",
  "retryAfter": 2
}
```

Polling:

```bash
curl -s "https://configurador.redutoresibr.com.br/APIChat/ConsultaConteudo.php?acao=status&jobId=job_abc123" \
  -H "X-Api-Key: SUA_CHAVE"
```

Cancelamento:

```bash
curl -s "https://configurador.redutoresibr.com.br/APIChat/ConsultaConteudo.php?acao=cancel&jobId=job_abc123" \
  -H "X-Api-Key: SUA_CHAVE"
```

## Retry seguro com `Idempotency-Key` (POST)

POSTs relevantes agora aceitam `Idempotency-Key` no header:

- `POST /APIChat/GerarLinkConfigurador.php`
- `POST /APIChat/TelemetriaUso.php` *(para `origem: "site"` não exige `X-Api-Key`)*

Recomendação de retry:

1. Gere uma chave por intenção de operação (ex.: UUID v4).
2. Reenvie a mesma chave em timeouts/retries.
3. Se a operação já tiver sido concluída, a API devolve replay da resposta anterior.
4. Se a mesma chave for usada com payload diferente, a API retorna `409 IDEMPOTENCY_CONFLICT`.

### Exemplo

```bash
curl -X POST "https://configurador.redutoresibr.com.br/APIChat/TelemetriaUso.php" \
  -H "Idempotency-Key: 8f84d16f-8e7a-4f12-b934-7d10f5d7607c" \
  -H "Content-Type: application/json" \
  -d '{"evento":"abrir_configurador","consentimentoIA":true}'
```

## Headers de observabilidade

As respostas retornam `X-Request-Id` de forma consistente. Em fluxos assíncronos também retornam `X-Job-Id` e `Retry-After`.

## Contrato estável (v9)

- OpenAPI publicado em `APIChat/GPTActionConfigurador.openapi.json` (3.0.3).
- Envelope único de resposta:
  - Sucesso/partial: `{ status, data, warnings[], requestId, timestamp, redactionsApplied }`
  - Erro: `{ status:"error", codigoErro, mensagemUsuario, mensagemTecnica, detalhes[], requestId, timestamp, redactionsApplied }`
- `requestId` é retornado em 100% das respostas via middleware.

### Idempotência

Os endpoints de criação aceitam header `Idempotency-Key`:
- `abrirSolicitacaoCotacao`
- `abrirSolicitacaoDesenho`
- `executarEnviarTEEC`
- `orquestrarMemoriasETelemetria` (`create/write_pipeline/import_training`)

Reenvio com mesma chave e mesmo payload retorna o mesmo resultado; payload diferente retorna `409 IDEMPOTENCY_CONFLICT`.

### LGPD / PII

Campos de texto livre passam por redaction server-side (`conversaChatGPT`, `observacoes`, `solicitacao/fala` quando mapeados no endpoint). O retorno inclui `redactionsApplied`.

Exemplo (request):
```json
{"conversaChatGPT":"CPF 390.533.447-05 email joao.silva@empresa.com telefone 11999998888"}
```
Exemplo (trecho redigido):
```json
{"redactionsApplied":true,"data":{"conversa":"*********05 email jo***@empresa.com telefone 11*******88"}}
```
