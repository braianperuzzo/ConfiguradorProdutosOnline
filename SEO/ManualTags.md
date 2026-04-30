# Manual de Tags (Google Tag Manager / GA4)

Manual de referência para eventos e tags enviados ao Google Tag Manager/GA4. Use este documento para consultar nomes padronizados, seus significados e a taxonomia de eventos.

## Comando obrigatório

**Ao adicionar recursos novos no site, é obrigatório:**
1. Criar tags no padrão de nome descrito neste manual para **todas** as ações disponíveis do recurso.
2. Atualizar este manual com as novas tags e/ou eventos.

## Configuração de IDs por ambiente (GTM / GA4)

Os IDs do Google Tag Manager e do GA4 são lidos dinamicamente pelo `SEO/TagsGoogle.js`. Configure-os por ambiente usando **uma** das opções abaixo (a ordem de leitura é: variável global, depois meta tags).

### Opção 1: Meta tags no HTML

Inclua no `<head>` do layout:

```html
<meta name="gtm-id" content="GTM-XXXXXXX">
<meta name="ga-measurement-id" content="G-YYYYYYYYYY">
```

### Opção 2: Variáveis globais no layout/servidor

Antes de carregar `SEO/TagsGoogle.js`, defina as variáveis globais:

```html
<script>
  window.GTM_ID = "GTM-XXXXXXX";
  window.GA_MEASUREMENT_ID = "G-YYYYYYYYYY";
</script>
```

> Observação: se nenhum ID for informado, os scripts do GTM/GA4 não serão carregados.

## Padrão de nomenclatura

- Use nomes em minúsculas e separados por hífen (`-`).
- Evite palavras coladas; se houver verbo + substantivo, separe por hífen (ex.: `exibir-senha`, `reset-senha`).
- Identifique o contexto e o local do evento (ex.: `cabecalho`, `home`, `institucional`).
- Prefixe com o contexto logo no início do nome (ex.: `carrinho-...`, `home-...`, `areacliente-...`) para evitar sufixos soltos.
- Eventos de fluxo (taxonomia) usam underscore no **event_name** (`<fluxo>_<etapa>`).
- Eventos automáticos/técnicos seguem a convenção GA4 com underscore (ex.: `page_loaded`, `scroll_75`, `js_error`).
- Para placeholders, use **&lt;linha&gt;** (ex.: `acesso-configurador-<linha>`), substituindo pelo valor dinâmico.

## Taxonomia de eventos (Gtag)

## Contrato canônico orientado a negócio (funil comercial)

Além das tags legadas, o rastreamento deve priorizar os eventos canônicos GA4 abaixo. Sempre que aplicável, preencher os parâmetros obrigatórios.

### Parâmetros obrigatórios por contexto

- **Produto/configurador:** `item_id`, `item_name`, `item_category`, `line_code`.
- **Cotação/lead/comercial:** `quote_id`, `value`, `currency`.
- **Navegação SPA:** `page_location`, `page_referrer`, `page_title`.

### Eventos canônicos (GA4 recomendado)

| Etapa do funil | Evento canônico | Uso principal | Parâmetros obrigatórios |
| --- | --- | --- | --- |
| Descoberta de produto | `view_item` | Abertura de produto/configurador | `item_id`, `item_name`, `item_category`, `line_code` |
| Interesse/adição | `add_to_cart` | Ação de adicionar item ao carrinho | `item_id`, `item_name`, `item_category`, `line_code`, `value`, `currency` |
| Intenção de compra | `begin_checkout` | Abertura/avanço no fluxo de carrinho/cotação | `quote_id`, `value`, `currency` |
| Geração de lead | `generate_lead` | Confirmação de cotação/formulário comercial | `quote_id`, `value`, `currency` |
| Conversão | `purchase` | Pedido finalizado | `quote_id`, `value`, `currency` |
| Busca interna | `search` | Busca por produto/filtro | `search_term` |
| Conta | `sign_up` / `login` | Cadastro e autenticação | `method` (quando disponível) |
| Navegação | `page_view` | Mudança de rota em SPA e carregamento inicial | `page_location`, `page_referrer`, `page_title` |

### Compatibilidade com eventos legados

- Eventos antigos em `data-gtag-event` continuam sendo enviados para preservar histórico.
- Sempre que houver mapeamento para evento canônico, enviar **ambos**:
  1. evento canônico (prioritário para análises de negócio);
  2. evento legado (retrocompatibilidade de relatórios).
- Incluir `legacy_event_name` no payload do evento canônico para facilitar reconciliação.

### Regras gerais

- **event_name** segue o padrão `<fluxo>_<etapa>`.
- **event_category** é o nome do fluxo (ex.: `Configurador`, `Carrinho`, `Cotacao`).
- **event_label** é sempre a etapa (`abrir`, `avancar`, `confirmar`, `erro`, `cancelar`).
- **flow_context** deve receber a origem ou contexto (ex.: `carrinho`, `produto`, `menu`, `salvar`).

### Fluxos padronizados

#### Configurador

| Etapa | event_name | event_category | event_label |
| --- | --- | --- | --- |
| abrir | `configurador_abrir` | Configurador | abrir |
| avancar | `configurador_avancar` | Configurador | avancar |
| confirmar | `configurador_confirmar` | Configurador | confirmar |
| erro | `configurador_erro` | Configurador | erro |
| cancelar | `configurador_cancelar` | Configurador | cancelar |

#### Carrinho

| Etapa | event_name | event_category | event_label |
| --- | --- | --- | --- |
| abrir | `carrinho_abrir` | Carrinho | abrir |
| avancar | `carrinho_avancar` | Carrinho | avancar |
| confirmar | `carrinho_confirmar` | Carrinho | confirmar |
| erro | `carrinho_erro` | Carrinho | erro |
| cancelar | `carrinho_cancelar` | Carrinho | cancelar |

#### Cotacao

| Etapa | event_name | event_category | event_label |
| --- | --- | --- | --- |
| abrir | `cotacao_abrir` | Cotacao | abrir |
| avancar | `cotacao_avancar` | Cotacao | avancar |
| confirmar | `cotacao_confirmar` | Cotacao | confirmar |
| erro | `cotacao_erro` | Cotacao | erro |
| cancelar | `cotacao_cancelar` | Cotacao | cancelar |

### Observações

- Eventos disparados via `data-gtag-event` seguem a taxonomia acima; `data-gtag-label` é reaproveitado como `flow_context`.
- Eventos explícitos com `data-gtag-event` também aceitam `data-gtag-component`, `data-gtag-context` e `data-section` para informar `component`/`section` no payload.
- Para disparos manuais, prefira `trackFlowEvent(flow, step, payload)` quando disponível.
- Helpers internos geram tags dinamicamente e já aplicam o padrão de nomenclatura:
  - `sendSelectorEvent(<acao>)` → `seletor-<linha>-<acao>`.
  - `sendConfiguradorEvent(<acao>)` → `<acao>-configurador-<linha>`.
  - `dispararTagCarrinhoLinha(prefixo, ...)` / `dispararTagCarrinhoLinhaValor(prefixo, ...)` → **prefixo-&lt;linha&gt;**.
  - `dispararTagGtag(tag, payload)` / `dispatchOrQueueEvent(tag, payload)` → tag literal informada.

## Eventos automáticos (sistema e monitoramento)

### Navegação e desempenho

| Tag | Quando dispara |
| --- | --- |
| `page_loaded` | Conclusão do carregamento inicial do layout. |
| `tempo_pagina` | Tempo de permanência na página (intervalos periódicos). |
| `scroll_75` | Rolagem atingiu 75% da página. |
| `offline_request` | Tentativa de requisição enquanto o navegador está offline. |
| `acesso_pagina_seletor` | Acesso a páginas de seletores (configuradores/concorrentes). |
| `redirecionar_ibr` | Clique em links externos para `www.redutoresibr.com.br`. |

### Interação genérica e formulários

| Tag | Quando dispara |
| --- | --- |
| `interacao_elemento` | Interações genéricas sem tag específica (cliques/inputs rastreados automaticamente). |
| `alteracao_campo` | Alteração de campo de formulário (input/select/textarea). |
| `form_error` | Erro de validação em formulário. |
| `form_abandon` | Abandono de formulário após início de preenchimento. |
| `envio_formulario` | Envio genérico de formulário. |

### Links e downloads

| Tag | Quando dispara |
| --- | --- |
| `contact_click` | Clique em links de contato (tel/mailto/whatsapp). |
| `download` | Download de arquivos (extensões monitoradas automaticamente). |
| `outbound_click` | Clique em links externos. |

### Conta (eventos automáticos)

| Tag | Quando dispara |
| --- | --- |
| `login` | Login confirmado via parâmetro `?login=sucesso`. |
| `cadastro` | Envio do formulário de cadastro da área do cliente. |
| `excluir_conta` | Confirmação da exclusão de conta no modal. |

### Erros e monitoramento

| Tag | Quando dispara |
| --- | --- |
| `js_error` | Erros JavaScript capturados no cliente. |
| `pagina_erro` | Renderização de página de erro (HTTP/servidor). |

## Tags padronizadas

### Tema (modo de cor)

| Tag | Quando dispara |
| --- | --- |
| `modocor-claro` | Usuário alterna o tema para o modo claro. |
| `modocor-escuro` | Usuário alterna o tema para o modo escuro. |

### Chat Especialista

| Tag | Quando dispara |
| --- | --- |
| `chat-especialista-abrir` | Clique no botão flutuante **Especialista Técnico IBR** (tag legada de abertura do recurso). |
| `chat-especialista-clique-icone` | Clique no ícone do chat quando o link é aberto diretamente em nova aba. |

> Observação: o chat não utiliza mais modal/iframe. Os eventos `chat-especialista-tela-cheia`, `chat-especialista-limpar` e `chat-especialista-fechar` foram descontinuados.


### Recursos Assistivos (botão flutuante e Libras)

| Tag | Quando dispara |
| --- | --- |
| `acessibilidade-flutuante-abrir` | Clique no botão flutuante **Recursos Assistivos** para abrir o painel. |
| `acessibilidade-flutuante-fechar` | Fechamento do painel flutuante (botão principal, botão fechar, clique fora ou tecla ESC). |
| `acessibilidade-flutuante-expandir` | Clique para expandir o painel de recursos assistivos. |
| `acessibilidade-flutuante-reduzir` | Clique para reduzir/voltar do modo expandido no painel de recursos assistivos. |
| `acessibilidade-libras-flutuante-ativar` | Ativação do botão flutuante **Acessível em Libras** (ou card **Tradutor de Libras**) para habilitar a tradução. |
| `acessibilidade-libras-flutuante-desativar` | Desativação do botão flutuante **Acessível em Libras** (ou card **Tradutor de Libras**). |
| `acessibilidade-libras-traduzir-texto` | Clique em um texto da página com o modo Libras ativo para enviar conteúdo ao VLibras. |
| `acessibilidade-libras-flutuante-erro` | Falha ao carregar o plugin do VLibras (bloqueio, CSP ou indisponibilidade externa). |
| `acessibilidade-recurso-acionar` | Clique em qualquer card/recurso dentro do painel assistivo (identificado por `feature`). |
| `acessibilidade-estrutura-aba` | Clique nas abas **Títulos**, **Links** ou **Regiões** dentro da Estrutura da Página. |
| `acessibilidade-estrutura-voltar` | Clique no botão de voltar da visualização **Estrutura da Página** para retornar aos recursos assistivos. |

> Parâmetros auxiliares enviados no payload: `feature`, `tab_name`, `section`, `action_origin`, `flow_context=recursos_assistivos` e `component=painel_flutuante`.

### Busca de produtos (cabeçalho)

| Tag | Quando dispara |
| --- | --- |
| `pesquisar-produto-cabecalho` | Clique no campo **Pesquise um Produto**. |
| `pesquisar-produto-cabecalho-manual` | Busca feita digitando o produto e confirmando (lupa/enter). |
| `pesquisar-produto-cabecalho-recomendado` | Busca feita selecionando uma recomendação. |

### Cabeçalho (menu)

| Tag | Quando dispara |
| --- | --- |
| `logo-cabecalho` | Clique no logo. |
| `home-cabecalho` | Clique em **Home**. |
| `institucional-cabecalho` | Clique em **Institucional** (botão do menu). |
| `quem-somos-cabecalho` | Clique em **Quem Somos**. |
| `qualidade-cabecalho` | Clique em **Qualidade**. |
| `politica-privacidade-cabecalho` | Clique em **Política de Privacidade**. |
| `pedidos-financeiro-cabecalho` | Clique em **Cliente IBR**. |
| `aplicativo-cabecalho` | Clique em **Aplicativos** (botão do menu). |
| `aplicativo-configurador-cabecalho` | Clique em **Aplicativo - Configurador**. |
| `aplicativo-financeiro-cabecalho` | Clique em **Aplicativo - Financeiro**. |
| `ferramentas-auxiliares-cabecalho` | Clique em **Ferramentas Auxiliares** (botão do menu). |
| `ferramentas-auxiliares-guia-intercambialidade-cabecalho` | Clique em **Guia de Intercambialidade IBR** no menu Ferramentas Auxiliares. |
| `ferramentas-auxiliares-simulador-consumo-cabecalho` | Clique em **Simulador de Consumo** no menu Ferramentas Auxiliares. |
| `areacliente-cabecalho` | Clique em **Área do Cliente**. |
| `carrinho-cabecalho` | Clique em **Carrinho de Produtos** no cabeçalho. |

### Rodapé (seção "Navegue")

| Tag | Quando dispara |
| --- | --- |
| `home-rodape` | Clique em **Navegue - Home**. |
| `institucional-rodape` | Clique em **Navegue - Institucional** (botão do menu). |
| `quem-somos-rodape` | Clique em **Navegue - Quem Somos**. |
| `qualidade-rodape` | Clique em **Navegue - Qualidade**. |
| `politica-privacidade-rodape` | Clique em **Navegue - Política de Privacidade**. |
| `aplicativo-rodape` | Clique em **Navegue - Aplicativos** (botão do menu). |
| `aplicativo-configurador-rodape` | Clique em **Navegue - Aplicativo - Configurador**. |
| `aplicativo-financeiro-rodape` | Clique em **Navegue - Aplicativo - Financeiro**. |
| `ferramentas-auxiliares-rodape` | Clique em **Navegue - Ferramentas Auxiliares** (botão do menu). |
| `ferramentas-auxiliares-guia-intercambialidade-rodape` | Clique em **Guia de Intercambialidade IBR** no menu Ferramentas Auxiliares do rodapé. |
| `ferramentas-auxiliares-simulador-consumo-rodape` | Clique em **Simulador de Consumo** no menu Ferramentas Auxiliares do rodapé. |
| `pedidos-financeiro-rodape` | Clique em **Navegue - Cliente IBR**. |
| `areacliente-rodape` | Clique em **Navegue - Área do Cliente**. |
| `carrinho-rodape` | Clique em **Carrinho de Produtos** no rodapé. |

### Rodapé (contatos, redes e assinatura)

| Tag | Quando dispara |
| --- | --- |
| `endereco-rs-rodape` | Clique no endereço **RS**. |
| `endereco-sp-rodape` | Clique no endereço **SP**. |
| `telefone-rs-rodape` | Clique no telefone **RS**. |
| `telefone-sp-rodape` | Clique no telefone **SP**. |
| `whatsapp-rodape` | Clique no **WhatsApp**. |
| `social-linkedin-rodape` | Clique no **LinkedIn**. |
| `social-facebook-rodape` | Clique no **Facebook**. |
| `social-instagram-rodape` | Clique no **Instagram**. |
| `social-youtube-rodape` | Clique no **YouTube**. |
| `desenvolvido-por-rodape` | Clique em **Desenvolvido por**. |
| `seta-topo` | Clique na seta de **voltar ao topo**. |

### Cookies e LGPD

| Tag | Quando dispara |
| --- | --- |
| `acesso-politica-cookies` | Clique em **Política de Cookies e Privacidade** (banner ou modal). |
| `lgpd-abrir-config` | Clique em **Configurar**/abrir configurações (banner, link ou shield). |
| `lgpd-aceitar-todos` | Clique em **Aceitar Todos** (banner ou modal). |
| `lgpd-fechar-modal` | Fechamento do modal de cookies. |
| `lgpd-tab-configuracoes` | Clique na aba **Configurações** do modal. |
| `lgpd-tab-dados` | Clique na aba **Como Usamos os Dados** do modal. |
| `lgpd-tab-politica` | Clique na aba **Política de Cookies** do modal. |
| `lgpd-toggle-necessario` | Clique no toggle de **Cookies necessários**. |
| `lgpd-toggle-crm-erp` | Clique no toggle de **CRM/ERP**. |
| `lgpd-toggle-funcional` | Clique no toggle de **Cookies funcionais**. |
| `lgpd-toggle-desempenho` | Clique no toggle de **Desempenho/Analytics**. |
| `lgpd-toggle-marketing` | Clique no toggle de **Marketing/Publicidade**. |
| `lgpd-salvar-config` | Clique em **Salvar Configurações** (modal). |

### Política de Privacidade

| Tag | Quando dispara |
| --- | --- |
| `politica-privacidade-download` | Clique para **baixar** a Política de Privacidade. |
| `politica-privacidade-secao-atendimento` | Acesso à seção **Atendimento**. |
| `politica-privacidade-secao-atualizacoes` | Acesso à seção **Atualizações**. |
| `politica-privacidade-secao-compartilhamento` | Acesso à seção **Compartilhamento**. |
| `politica-privacidade-secao-cookies` | Acesso à seção **Cookies**. |
| `politica-privacidade-secao-dados-finalidades` | Acesso à seção **Dados e Finalidades**. |
| `politica-privacidade-secao-definicoes` | Acesso à seção **Definições**. |
| `politica-privacidade-secao-direitos-titular` | Acesso à seção **Direitos do Titular**. |
| `politica-privacidade-secao-gerenciamento-cookies` | Acesso à seção **Gerenciamento de Cookies**. |
| `politica-privacidade-secao-manutencao-dados` | Acesso à seção **Manutenção de Dados**. |
| `politica-privacidade-secao-politica` | Acesso à seção **Política de Privacidade**. |
| `politica-privacidade-secao-seguranca` | Acesso à seção **Segurança**. |
| `politica-privacidade-secao-transferencia` | Acesso à seção **Transferência**. |
| `politica-privacidade-secao-tratamento-dados` | Acesso à seção **Tratamento de Dados**. |

### Home (filtros e acesso ao configurador)

| Tag | Quando dispara |
| --- | --- |
| `home-filtro-produtos-abrir` | Clique para **abrir** o filtro de produtos. |
| `home-filtro-produtos-fechar` | Clique para **ocultar** o filtro de produtos. |
| `home-filtro-busca` | Busca digitando um termo no filtro da home. |
| `home-filtro-campo-produtos` | Seleção no campo **Produtos** do filtro. |
| `home-filtro-campo-linhas` | Seleção no campo **Linhas** do filtro. |
| `home-filtro-campo-desenhos` | Seleção no campo **Desenhos** do filtro. |
| `home-filtro-aplicar` | Clique em **Aplicar Filtros**. |
| `home-filtro-copiar` | Clique em **Copiar Filtros** (link com filtros). |
| `home-filtro-limpar` | Clique em **Limpar Filtros**. |
| `home-filtro-ordenar-az` | Clique para ordenar os produtos **A-Z**. |
| `home-filtro-ordenar-padrao` | Clique para voltar à **ordem padrão**. |
| `acesso-configurador-<linha>` | Clique no card para acessar o configurador da linha (`<linha>` vem do parâmetro `*LN`, ex.: `1.Q`). |
| `home-galeria-<linha>` | Troca de imagem na galeria do card (`<linha>` vem do parâmetro `*LN`, ex.: `1.Q`). |

### Seletores (configurador)

Os seletores seguem o padrão `seletor-<linha>-<acao>` (`<linha>` vem do seletor, ex.: `1.Q`).

| Tag | Quando dispara |
| --- | --- |
| `seletor-<linha>-galeria-navegar` | Navegação da galeria (passar imagens). |
| `seletor-<linha>-galeria-expandir` | Expansão da galeria (lightbox ao clicar na imagem). |
| `seletor-<linha>-galeria-clique-imagem` | Clique na imagem da galeria (antes de expandir). |
| `seletor-<linha>-produto-site` | Clique em **Site do Produto**. |
| `seletor-<linha>-produto-catalogo` | Clique em **Catálogo do Produto**. |
| `seletor-<linha>-gerar` | Clique em **Gerar Produto**. |
| `seletor-<linha>-informacoes` | Clique em **Informações do Produto**. |
| `seletor-<linha>-estoque` | Clique em **Estoque**. |
| `seletor-<linha>-preco` | Clique em **Preço de Venda**. |
| `seletor-<linha>-compartilhar` | Clique em **Compartilhar**. |
| `seletor-<linha>-compartilhar-whatsapp` | Clique em **Compartilhar via WhatsApp**. |
| `seletor-<linha>-compartilhar-link` | Clique em **Copiar Link**. |
| `seletor-<linha>-compartilhar-email` | Clique em **Compartilhar por Email**. |
| `seletor-<linha>-compartilhar-email-enviar` | Envio do formulário **Compartilhar por Email**. |
| `seletor-<linha>-favorito-adicionar` | Clique no botão **Favorito** para adicionar. |
| `seletor-<linha>-favorito-remover` | Clique no botão **Favorito** para remover. |
| `seletor-<linha>-favorito-salvar-comentario` | Clique em **Salvar Comentário** nos favoritos. |
| `seletor-<linha>-favorito-nao-salvar-comentario` | Clique em **Não Salvar Comentários** nos favoritos. |
| `seletor-<linha>-favorito-salvo-comentario` | Favorito salvo **com comentário**. |
| `seletor-<linha>-favorito-salvo-sem-comentario` | Favorito salvo **sem comentário**. |
| `seletor-<linha>-desenho-solicitar` | Clique em **Solicitar Desenho**. |
| `seletor-<linha>-desenho-enviar` | Envio do formulário **Solicitar Desenho**. |
| `seletor-<linha>-desenho-cancelar` | Cancelamento/fechamento do formulário **Solicitar Desenho**. |
| `seletor-<linha>-cadastro-solicitar` | Clique em **Solicitar Cadastro do Produto**. |
| `seletor-<linha>-cadastro-enviar` | Envio do formulário **Solicitar Cadastro do Produto**. |
| `seletor-<linha>-cadastro-cancelar` | Cancelamento/fechamento do formulário **Solicitar Cadastro do Produto**. |
| `seletor-<linha>-cotacao-solicitar` | Clique em **Solicitar Cotação**. |
| `seletor-<linha>-cotacao-enviar` | Envio do formulário **Solicitar Cotação**. |
| `seletor-<linha>-cotacao-cancelar` | Cancelamento/fechamento do formulário **Solicitar Cotação**. |
| `seletor-<linha>-reconfigurar` | Clique em **Reconfigurar**. |
| `seletor-<linha>-carrinho` | Clique em **Carrinho** (adicionar produto). |

### Produto (links externos)

Quando o botão de **Site do Produto** ou **Catálogo do Produto** não está dentro de um seletor, utilize as tags abaixo.

| Tag | Quando dispara |
| --- | --- |
| `acesso-site-produto` | Clique no botão **Site do Produto** fora do seletor. |
| `acesso-catalogo-produto` | Clique no botão **Catálogo do Produto** fora do seletor. |

### Intercambialidade (Guia de Intercambialidade IBR)

A página também segue o padrão de seletores (`seletor-<linha>-<acao>`), incluindo as ações abaixo.

| Tag | Quando dispara |
| --- | --- |
| `seletor-<linha>-acessar-pagina` | Acesso inicial ao Guia de Intercambialidade. |
| `seletor-<linha>-produto-site` | Clique em **Site de Produtos IBR**. |
| `seletor-<linha>-produto-catalogo` | Clique em **Catálogo de Produtos IBR**. |
| `seletor-<linha>-gerar-equivalente` | Clique em **Gerar Equivalente IBR**. |
| `seletor-<linha>-compartilhar` | Clique em **Compartilhar** ao lado de **Reconfigurar**. |
| `seletor-<linha>-compartilhar-whatsapp` | Clique em **WhatsApp** no submenu de compartilhamento. |
| `seletor-<linha>-compartilhar-link` | Clique em **Copiar Link** no submenu de compartilhamento. |
| `seletor-<linha>-compartilhar-email` | Clique em **Compartilhar por Email** no submenu de compartilhamento. |
| `seletor-<linha>-compartilhar-email-enviar` | Clique em **Enviar** no formulário de compartilhar por email da intercambialidade. |
| `seletor-<linha>-compartilhar-email-cancelar` | Clique em **Cancelar** ou fechamento do formulário de compartilhar por email da intercambialidade. |
| `seletor-<linha>-reconfigurar` | Clique em **Reconfigurar** na área de ações dos equivalentes. |
| `seletor-<linha>-equivalente-copiar` | Clique em **⧉ Copiar** no card de equivalente. |
| `seletor-<linha>-equivalente-galeria-expandir` | Clique para expandir a galeria do card de equivalente. |
| `seletor-<linha>-equivalente-galeria-navegar` | Navegação lateral da galeria do card de equivalente. |
| `seletor-<linha>-equivalente-catalogo` | Clique em **Catálogo do Produto** no card de equivalente. |
| `seletor-<linha>-equivalente-site` | Clique em **Site do Produto** no card de equivalente. |
| `seletor-<linha>-equivalente-configurar` | Clique em **Configure o Produto** no card de equivalente. |
| `seletor-<linha>-equivalente-ver-recomendados-expandir` | Clique em **Ver Produtos Recomendados** para expandir a área de recomendados. |
| `seletor-<linha>-equivalente-ver-recomendados-recolher` | Clique em **Ver Produtos Recomendados** para recolher a área de recomendados. |
| `seletor-<linha>-equivalente-recomendado-acessar-produto` | Clique em **Acessar o Produto** dentro de Produtos Recomendados. |
| `seletor-<linha>-seletor-coma-alterar` | Alteração do campo **Marca do Concorrente**. |
| `seletor-<linha>-marca-coma-selecionada` | Marca COMA selecionada (evento dedicado). |
| `seletor-<linha>-seletor-coln-alterar` | Alteração do campo **Linha do Produto Concorrente**. |
| `seletor-<linha>-seletor-cobr-alterar` | Alteração do campo **Tamanho do Produto Concorrente**. |
| `seletor-<linha>-seletor-cord-alterar` | Alteração do campo **Redução do Produto Concorrente**. |
| `seletor-<linha>-configuracoes-avancadas-abrir` | Abertura de **Configurações Avançadas**. |
| `seletor-<linha>-configuracoes-avancadas-fechar` | Fechamento de **Configurações Avançadas**. |
| `seletor-<linha>-configuracoes-avancadas-criterio-reducao-min-alterar` | Alteração da faixa mínima de redução. |
| `seletor-<linha>-configuracoes-avancadas-criterio-reducao-max-alterar` | Alteração da faixa máxima de redução. |
| `seletor-<linha>-configuracoes-avancadas-criterio-torque-min-alterar` | Alteração da faixa mínima de torque. |
| `seletor-<linha>-configuracoes-avancadas-criterio-torque-max-alterar` | Alteração da faixa máxima de torque. |
| `seletor-<linha>-configuracoes-avancadas-criterio-reducao-min-input-alterar` | Alteração do input numérico mínimo de redução. |
| `seletor-<linha>-configuracoes-avancadas-criterio-reducao-max-input-alterar` | Alteração do input numérico máximo de redução. |
| `seletor-<linha>-configuracoes-avancadas-criterio-torque-min-input-alterar` | Alteração do input numérico mínimo de torque. |
| `seletor-<linha>-configuracoes-avancadas-criterio-torque-max-input-alterar` | Alteração do input numérico máximo de torque. |
| `seletor-<linha>-configuracoes-avancadas-restaurar-padrao` | Clique em **Restaurar padrão** dos critérios avançados. |
| `seletor-<linha>-resetar-configuracao` | Clique em **Reconfigurar** para limpar os parâmetros do guia. |

## Carrinho de Produtos

### Ações gerais da página

| Tag | Quando dispara |
| --- | --- |
| `carrinho-voltar` | Clique em **Voltar** no carrinho. |
| `carrinho-gerar-orcamento` | Clique em **Gerar Orçamento**. |
| `carrinho-expandir-todos` | Clique em **Expandir Todos**. |
| `carrinho-remover-todos` | Clique em **Remover Todos**. |
| `carrinho-recarregar` | Clique em **Recarregar Carrinho**. |

### Ações gerais

| Tag | Quando dispara |
| --- | --- |
| `galeria-navegar-configurador-<linha>` | Navegação da galeria do configurador (passar imagens). |
| `galeria-expandir-configurador-<linha>` | Expansão da galeria do configurador (lightbox ao clicar na imagem). |
| `galeria-clique-imagem-configurador-<linha>` | Clique na imagem da galeria do configurador (antes de expandir). |
| `gerar-configurador-<linha>` | Clique em **Gerar Produto**. |
| `informacoes-configurador-<linha>` | Clique em **Informações do Produto**. |
| `estoque-configurador-<linha>` | Clique em **Estoque**. |
| `preco-configurador-<linha>` | Clique em **Preço de Venda**. |
| `compartilhar-configurador-<linha>` | Clique em **Compartilhar**. |
| `compartilhar-link-configurador-<linha>` | Clique em **Copiar Link**. |
| `compartilhar-whatsapp-configurador-<linha>` | Clique em **Compartilhar via WhatsApp**. |
| `compartilhar-email-configurador-<linha>` | Clique em **Compartilhar por Email**. |
| `compartilhar-email-enviar-configurador-<linha>` | Envio do formulário **Compartilhar por Email**. |
| `favorito-adicionar-configurador-<linha>` | Clique no botão **Favorito** para adicionar. |
| `favorito-remover-configurador-<linha>` | Clique no botão **Favorito** para remover. |
| `favorito-salvar-comentario-configurador-<linha>` | Clique em **Salvar Comentário** nos favoritos. |
| `favorito-nao-salvar-comentario-configurador-<linha>` | Clique em **Não Salvar Comentários** nos favoritos. |
| `favorito-salvo-comentario-configurador-<linha>` | Favorito salvo **com comentário**. |
| `favorito-salvo-sem-comentario-configurador-<linha>` | Favorito salvo **sem comentário**. |
| `desenho-solicitar-configurador-<linha>` | Clique em **Solicitar Desenho**. |
| `desenho-enviar-configurador-<linha>` | Envio do formulário **Solicitar Desenho**. |
| `desenho-cancelar-configurador-<linha>` | Cancelamento/fechamento do formulário **Solicitar Desenho**. |
| `cadastro-solicitar-configurador-<linha>` | Clique em **Solicitar Cadastro do Produto**. |
| `cadastro-enviar-configurador-<linha>` | Envio do formulário **Solicitar Cadastro do Produto**. |
| `cadastro-cancelar-configurador-<linha>` | Cancelamento/fechamento do formulário **Solicitar Cadastro do Produto**. |
| `cotacao-solicitar-configurador-<linha>` | Clique em **Solicitar Cotação**. |
| `cotacao-enviar-configurador-<linha>` | Envio do formulário **Solicitar Cotação**. |
| `cotacao-cancelar-configurador-<linha>` | Cancelamento/fechamento do formulário **Solicitar Cotação**. |
| `reconfigurar-configurador-<linha>` | Clique em **Reconfigurar**. |
| `carrinho-configurador-<linha>` | Clique em **Carrinho** (adicionar produto). |

### Ajuda (help-box/popover)

| Tag | Quando dispara |
| --- | --- |
| `helpbox-<titulo-normalizado>` | Clique no ícone de ajuda (`button.help-icon`) com título dinâmico normalizado. |
| `helpbox-popover-expandir` | Popover de ajuda exibido (`shown.bs.popover`). |
| `helpbox-popover-abandono` | Popover de ajuda fechado por abandono (clique fora ou botão de fechar). |
| `helpbox-popover-imagem-expandir` | Clique em imagem dentro do popover de ajuda para expandir/abrir visualização. |
| `seletor-<linha>-help-icon-clique` | Clique no ícone de ajuda em páginas de seletor. |
| `seletor-<linha>-help-icon-expandir` | Exibição do popover de ajuda em páginas de seletor. |
| `seletor-<linha>-help-icon-abandono` | Fechamento/abandono do popover de ajuda em páginas de seletor. |

#### Normalização de `<titulo-normalizado>`

O sufixo dinâmico das tags `helpbox-<titulo-normalizado>` segue o mesmo padrão implementado no layout:

- remove acentos e diacríticos;
- converte para minúsculas;
- substitui sequências não alfanuméricas por hífen (`-`);
- remove hífens no início/fim;
- usa `sem-titulo` quando o título está vazio.

Exemplos práticos (úteis para GA/BI e alinhamento entre Produto e Marketing):

| Título original | Tag enviada |
| --- | --- |
| `Como selecionar a relação?` | `helpbox-como-selecionar-a-relacao` |
| `Óleo / Lubrificação` | `helpbox-oleo-lubrificacao` |
| `Série TX-500 (IP55)` | `helpbox-serie-tx-500-ip55` |
| `(vazio)` | `helpbox-sem-titulo` |

### Compartilhar por email (carrinho)

| Tag | Quando dispara |
| --- | --- |
| `carrinho-compartilhar-email-enviar` | Clique em **Enviar** no formulário de email. |
| `carrinho-compartilhar-email-cancelar` | Clique em **Cancelar** no formulário de email. |
| `carrinho-compartilhar-email-sucesso` | Compartilhamento por email concluído com sucesso. |

### Carrinhos salvos

| Tag | Quando dispara |
| --- | --- |
| `carrinho-carrinhos-salvos-abrir-modal` | Clique em **Abrir Carrinho** (abrir modal). |
| `carrinho-carrinhos-salvos-abrir` | Clique em **Abrir Carrinho** (abrir item selecionado). |
| `carrinho-carrinhos-salvos-renomear` | Clique em **Renomear Carrinho**. |
| `carrinho-carrinhos-salvos-editar-comentario` | Clique em **Editar Comentário**. |
| `carrinho-carrinhos-salvos-excluir` | Clique em **Excluir Carrinho**. |
| `carrinho-carrinhos-salvos-pesquisar` | Digitação no campo de busca de carrinhos salvos. |
| `carrinho-carrinhos-salvos-abandono` | Fechamento/cancelamento do modal de carrinhos salvos. |
| `carrinho-salvar-abrir` | Clique em **Salvar Carrinho** (abrir modal). |
| `carrinho-salvar-confirmar` | Clique em **Salvar Carrinho** (confirmar). |
| `carrinho-salvar-abandono` | Fechamento/cancelamento do modal de salvar carrinho. |
| `carrinho-salvar-comentario` | Salvamento de carrinho **com comentário**. |
| `carrinho-salvar-sem-comentario` | Salvamento de carrinho **sem comentário**. |

### Carrinhos salvos (confirmações e edição)

| Tag | Quando dispara |
| --- | --- |
| `salvar-nome-carrinho` | Clique em **Salvar Nome** no modal de edição. |
| `cancelar-editar-nome-carrinho` | Clique em **Cancelar** no modal de edição de nome. |
| `salvar-comentario-carrinho` | Clique em **Salvar Comentário** no modal de edição. |
| `cancelar-editar-comentario-carrinho` | Clique em **Cancelar** no modal de edição de comentário. |
| `confirmar-substituicao-carrinho` | Confirmação para **substituir** carrinho salvo. |
| `cancelar-substituicao-carrinho` | Cancelamento da substituição de carrinho salvo. |
| `confirmar-excluir-carrinho` | Confirmação para **excluir** carrinho salvo. |
| `cancelar-excluir-carrinho` | Cancelamento da exclusão de carrinho salvo. |
| `carrinho-salvo-atualizado` | Carrinho salvo atualizado com sucesso. |
| `carrinho-salvo-excluido` | Carrinho salvo excluído com sucesso. |

### Solicitações (cadastro, cotação e desenho)

| Tag | Quando dispara |
| --- | --- |
| `carrinho-cadastro-abrir` | Clique em **Solicitar Cadastro** (abrir formulário). |
| `carrinho-cadastro-abandono` | Fechamento/cancelamento do formulário de cadastro. |
| `carrinho-cadastro-solicitar` | Envio do formulário de cadastro. |
| `carrinho-cotacao-abrir` | Clique em **Solicitar Cotação** (abrir formulário). |
| `carrinho-cotacao-abandono` | Fechamento/cancelamento do formulário de cotação. |
| `carrinho-cotacao-solicitar` | Envio do formulário de cotação. |
| `carrinho-desenho-abrir` | Clique em **Solicitar Desenho** (abrir formulário). |
| `carrinho-desenho-abandono` | Fechamento/cancelamento do formulário de desenho. |
| `carrinho-desenho-solicitar` | Envio do formulário de desenho. |
| `solicitacao-desenho-sucesso` | Solicitação de desenho enviada com sucesso. |
| `solicitacao-cadastro-sucesso` | Solicitação de cadastro enviada com sucesso. |

### Comparação de produtos

| Tag | Quando dispara |
| --- | --- |
| `carrinho-comparar-abrir` | Clique em **Comparar Produtos** (abrir modal). |
| `carrinho-comparar-compartilhar` | Clique em **Compartilhar** na comparação. |
| `carrinho-comparar-destacar` | Clique em **Destacar Diferenças**. |
| `carrinho-comparar-aplicar` | Clique em **Aplicar ao Carrinho**. |
| `confirmar-aplicar-comparacao` | Confirmação para aplicar a comparação ao carrinho. |
| `cancelar-aplicar-comparacao` | Cancelamento da aplicação da comparação. |
| `carrinho-comparar-mover-galeria` | Reordenação de itens na comparação (mover). |
| `carrinho-comparar-remover-item` | Remoção de item da comparação. |
| `carrinho-comparar-usar-galeria` | Interação com a galeria de imagens da comparação. |
| `carrinho-comparar-abandono` | Fechamento/cancelamento do modal de comparação. |

### Itens do carrinho (com linha)

| Tag | Quando dispara |
| --- | --- |
| `carrinho-adicionar-<linha>` | Adição de produto ao carrinho. |
| `carrinho-remover-<linha>` | Remoção de produto do carrinho. |
| `carrinho-favoritar-<linha>` | Clique em **Favoritar** o produto do carrinho. |
| `carrinho-favorito-abandono-<linha>` | Abandono/fechamento do modal de favorito. |
| `carrinho-favorito-salvar-<linha>` | Salvamento do favorito. |
| `carrinho-favorito-salvar-comentario-<linha>` | Salvamento do favorito **com comentário**. |
| `carrinho-favorito-salvar-sem-comentario-<linha>` | Salvamento do favorito **sem comentário**. |
| `carrinho-favorito-remover-<linha>` | Remoção do favorito do carrinho. |
| `carrinho-quantidade-<linha>` | Alteração de quantidade do produto. |
| `carrinho-expandir-item-<linha>` | Expansão dos detalhes do produto. |

### Acessórios recomendados (com linha)

| Tag | Quando dispara |
| --- | --- |
| `carrinho-acessorios-abrir-<linha>` | Clique em **Acessórios Recomendados**. |
| `carrinho-acessorios-adicao-<linha>` | Ação de **adicionar** acessório recomendado. |
| `carrinho-acessorios-remocao-<linha>` | Ação de **remover** acessório recomendado. |
| `carrinho-acessorios-troca-<linha>` | Ação de **trocar** acessório recomendado. |
| `carrinho-acessorios-concluir-<linha>` | Conclusão do ajuste de acessório (aplicar/alterar). |
| `carrinho-acessorios-abandono-<linha>` | Abandono/fechamento do ajuste de acessório. |

### Recomendações de produtos

| Tag | Quando dispara |
| --- | --- |
| `carrinho-recomendacao-adicionar-<linha>` | Adição de produto recomendado ao carrinho. |
| `carrinho-recomendacao-ver-mais` | Clique em **Ver mais recomendações**. |
| `carrinho-recomendacao-nav-anterior` | Navegação para recomendações anteriores. |

### Detalhes do produto

| Tag | Quando dispara |
| --- | --- |
| `carrinho-produto-informacoes` | Clique em **Informações do Produto**. |
| `carrinho-produto-estoque` | Clique em **Estoque do Produto**. |
| `carrinho-produto-preco` | Clique em **Preço do Produto**. |

### Abandono de carrinho

| Tag | Quando dispara |
| --- | --- |
| `carrinho-abandono-exibido` | Exibição do popup de abandono. |
| `carrinho-abandono-abandonar` | Fechar popup/continuar no carrinho (abandono). |
| `carrinho-abandono-formulario` | Clique para abrir o formulário de ajuda. |
| `carrinho-abandono-whatsapp` | Clique para solicitar ajuda via WhatsApp. |

## Área do Cliente

### Eventos de senha (exibir/ocultar)

| Tag | Quando dispara |
| --- | --- |
| `exibir-senha-login` | Usuário exibe a senha no formulário de login. |
| `ocultar-senha-login` | Usuário oculta a senha no formulário de login. |
| `exibir-senha-cadastro` | Usuário exibe a senha no formulário de cadastro. |
| `ocultar-senha-cadastro` | Usuário oculta a senha no formulário de cadastro. |
| `exibir-senha-cadastro-repetir` | Usuário exibe a senha repetida no cadastro. |
| `ocultar-senha-cadastro-repetir` | Usuário oculta a senha repetida no cadastro. |
| `exibir-senha-redefinir` | Usuário exibe a senha na redefinição. |
| `ocultar-senha-redefinir` | Usuário oculta a senha na redefinição. |
| `exibir-senha-redefinir-repetir` | Usuário exibe a senha repetida na redefinição. |
| `ocultar-senha-redefinir-repetir` | Usuário oculta a senha repetida na redefinição. |

### Eventos de login, cadastro e senha

| Tag | Quando dispara |
| --- | --- |
| `esquecerme-area-cliente` | Usuário desmarca o campo “Lembrar-me”. |
| `lembrarme-area-cliente` | Usuário marca o campo “Lembrar-me”. |
| `esqueceusenha-area-cliente` | Clique em **Esqueci minha senha** (abrir recuperação). |
| `erro-login-area-cliente` | Ocorre erro ao autenticar no login. |
| `erro-cadastro-area-cliente` | Ocorre erro ao enviar o cadastro. |
| `confirmacao-cadastro-area-cliente` | Confirmação de cadastro realizada com sucesso. |
| `reset-senha-solicitacao-area-cliente` | Envio do formulário de recuperação de senha. |
| `reset-senha-tempo-area-cliente` | Senha expirada exige redefinição por tempo. |
| `limite-tentativas-area-cliente` | Usuário atinge limite de tentativas (rate limit). |
| `confirmacao-troca-senha-area-cliente` | Troca de senha confirmada com sucesso. |
| `cadastro-cpf-area-cliente` | Cadastro iniciado com **Pessoa Física** selecionado. |
| `cadastro-cnpj-area-cliente` | Cadastro iniciado com **Pessoa Jurídica** selecionado. |
| `entrar-<provedor>-area-cliente` | Clique para login social (ex.: entrar-google-area-cliente, entrar-microsoft-area-cliente). |

### Acesso e validações (Área do Cliente)

| Tag | Quando dispara |
| --- | --- |
| `entrar-area-cliente` | Clique em **Entrar** no formulário de login. |
| `cadastro-area-cliente` | Clique em **Criar conta** no formulário de cadastro. |
| `areacliente-validar-dispositivo` | Clique em **Validar dispositivo**. |
| `areacliente-reenviar-codigo` | Clique em **Reenviar código** de validação. |
| `areacliente-alterar-senha-tempo` | Clique para **alterar senha** por expiração. |

### Navegação no banner (usuário logado)

| Tag | Quando dispara |
| --- | --- |
| `bannercliente-abrir` | Clique no botão “Olá, Nome” (menu do usuário logado). |
| `bannercliente-empresa` | Clique no seletor/ícone de empresa do banner. |
| `bannercliente-dadoscadastrais` | Clique em “Dados Cadastrais”. |
| `bannercliente-produtos` | Clique em “Meus Produtos”. |
| `bannercliente-favoritos` | Clique em “Meus Favoritos”. |
| `bannercliente-carrinhos` | Clique em “Meus Carrinhos”. |
| `bannercliente-desenhos` | Clique em “Meus Desenhos”. |
| `bannercliente-cotacoes` | Clique em “Minhas Cotações”. |
| `bannercliente-cadastros` | Clique em “Meus Cadastros”. |
| `bannercliente-sair` | Clique em “Sair”. |

### Menu (Área do Cliente)

| Tag | Quando dispara |
| --- | --- |
| `areacliente-menu-abrir` | Clique para **abrir** o menu (mobile). |
| `areacliente-menu-fechar` | Clique para **fechar** o menu (mobile). |

### Contato (Área do Cliente)

| Tag | Quando dispara |
| --- | --- |
| `contato-telefone` | Clique no telefone da área do cliente. |
| `contato-whatsapp` | Clique no WhatsApp da área do cliente. |
| `contato-email` | Clique no email da área do cliente. |
| `areacliente-vendedor-responsavel-telefone` | Clique no telefone do vendedor responsável. |
| `areacliente-vendedor-responsavel-whatsapp` | Clique no WhatsApp do vendedor responsável. |
| `areacliente-vendedor-responsavel-email` | Clique no email do vendedor responsável. |

### Navegação e ações na Área do Cliente

| Tag | Quando dispara |
| --- | --- |
| `areacliente-voltar-configurador` | Clique em “Voltar Para o Configurador de Produtos”. |
| `areacliente-voltar` | Clique em “Voltar” dentro da Área do Cliente. |
| `areacliente-pedidos-financeiro` | Clique no link “Cliente IBR”. |
| `areacliente-dadoscadastrais` | Acesso a “Dados Cadastrais”. |
| `areacliente-produtos` | Acesso a “Meus Produtos Gerados”. |
| `areacliente-favoritos` | Acesso a “Meus Produtos Favoritos”. |
| `areacliente-carrinhos` | Acesso a “Meus Carrinhos Salvos”. |
| `areacliente-desenhos` | Acesso a “Meus Desenhos Solicitados”. |
| `areacliente-cotacoes` | Acesso a “Minhas Cotações Solicitadas”. |
| `areacliente-cadastros` | Acesso a “Meus Cadastros de Produtos Solicitados”. |
| `areacliente-sair` | Clique em “Sair”. |
| `areacliente-editar-dados` | Clique em “Editar Dados”. |
| `areacliente-modificar-senha` | Clique em “Modificar Senha”. |
| `areacliente-confirmar-dados` | Confirmação da edição de dados. |
| `areacliente-confirmar-senha` | Confirmação da alteração de senha. |
| `areacliente-confirmar-excluir-empresa` | Confirmação da exclusão de empresa. |
| `areacliente-confirmar-excluir-conta` | Confirmação da exclusão de conta. |

### Solicitar Permissões (Área do Cliente)

| Tag | Quando dispara |
| --- | --- |
| `areacliente-permissao-solicitar` | Clique no botão **Solicitar Permissões**. |
| `areacliente-permissao-mim` | Clique em **Para Mim**. |
| `areacliente-permissao-mim-enviar` | Clique em **Para Mim > Enviar Solicitação**. |
| `areacliente-permissao-equipe` | Clique em **Para Alguém da Minha Equipe**. |
| `areacliente-permissao-continuar` | Clique em **Continuar** (etapa de e-mails da equipe). |
| `areacliente-permissao-equipe-email-unico-acesso` | Acesso automático à página de e-mail único após clicar em **Continuar** com um único e-mail. |
| `areacliente-permissao-equipe-email-unico-enviar` | Clique em **Para Alguém da Minha Equipe (e-mail único) > Enviar Solicitação**. |
| `areacliente-permissao-equipe-mesma` | Clique em **A Permissão de Todos Será a Mesma**. |
| `areacliente-permissao-equipe-diferentes` | Clique em **Selecionar Permissões Diferentes**. |
| `areacliente-permissao-equipe-diferentes-enviar` | Clique em **Selecionar Permissões Diferentes > Enviar Solicitação**. |
| `areacliente-permissao-voltar-mim` | Clique em **Voltar** na etapa **Para Mim**. |
| `areacliente-permissao-voltar-equipe` | Clique em **Voltar** na etapa de e-mails da equipe. |
| `areacliente-permissao-voltar-equipe-email-unico` | Clique em **Voltar** na etapa de e-mail único. |
| `areacliente-permissao-voltar-equipe-multiplos` | Clique em **Voltar** na etapa de múltiplos e-mails. |
| `areacliente-permissao-voltar-equipe-diferente` | Clique em **Voltar** na etapa **Selecionar Permissões Diferentes**. |
| `areacliente-permissao-abandono-escolha` | Abandono/cancelamento na etapa de escolha **Para quem solicitar** (inclui fechar/cancelar). |
| `areacliente-permissao-abandono-mim` | Abandono/cancelamento na etapa **Para Mim** (inclui fechar/cancelar). |
| `areacliente-permissao-abandono-equipe` | Abandono/cancelamento na etapa de e-mails da equipe (inclui fechar/cancelar). |
| `areacliente-permissao-abandono-equipe-email-unico` | Abandono/cancelamento na etapa de e-mail único (inclui fechar/cancelar). |
| `areacliente-permissao-abandono-equipe-multiplos` | Abandono/cancelamento na etapa de múltiplos e-mails (inclui fechar/cancelar). |
| `areacliente-permissao-abandono-equipe-diferente` | Abandono/cancelamento na etapa **Selecionar Permissões Diferentes** (inclui fechar/cancelar). |
| `areacliente-permissao-sem-cadastro-enviar` | Envio de solicitação quando há pessoas sem cadastro (convites de cadastro enviados). |

### Confirmações e sucesso (Área do Cliente)

| Tag | Quando dispara |
| --- | --- |
| `alteracao-senha-sucesso` | Alteração de senha concluída com sucesso. |
| `atualizar-dados-sucesso` | Atualização de dados concluída com sucesso. |
| `solicitar-exclusao-sucesso` | Solicitação de exclusão concluída com sucesso. |

### Empresa (troca e cadastro)

| Tag | Quando dispara |
| --- | --- |
| `areacliente-empresa-abrir` | Clique no seletor de empresa (ícone/trigger). |
| `areacliente-empresa-meta` | Clique nos metadados da empresa ativa. |
| `areacliente-empresa-trocar` | Troca de empresa confirmada. |
| `areacliente-empresa-adicionar` | Clique em “Adicionar Empresa”. |
| `areacliente-empresa-adicionar-cancelar` | Cancelamento da adição de empresa. |
| `areacliente-empresa-adicionada` | Empresa adicionada (evento de retorno). |
| `areacliente-empresa-adicionada-sucesso` | Confirmação de empresa adicionada com sucesso. |
| `areacliente-empresa-excluir` | Exclusão de empresa solicitada. |
| `areacliente-conta-excluir` | Exclusão de conta solicitada. |
| `areacliente-exclusao-cancelar` | Cancelamento da exclusão (conta ou empresa). |

### Filtros e ordenação (Área do Cliente)

| Tag | Quando dispara |
| --- | --- |
| `limpar-filtros-produtos` | Clique em **Limpar Filtros** (Meus Produtos). |
| `limpar-filtros-favoritos` | Clique em **Limpar Filtros** (Meus Favoritos). |
| `limpar-filtros-carrinhos` | Clique em **Limpar Filtros** (Meus Carrinhos). |
| `limpar-filtros-desenhos` | Clique em **Limpar Filtros** (Meus Desenhos). |
| `limpar-filtros-cotacoes` | Clique em **Limpar Filtros** (Minhas Cotações). |
| `limpar-filtros-cadastros` | Clique em **Limpar Filtros** (Meus Cadastros). |
| `ordenar-produtos` | Clique em **Ordenar de A-Z/Ordem Padrão** (Meus Produtos). |
| `ordenar-favoritos` | Clique em **Ordenar de A-Z/Ordem Padrão** (Meus Favoritos). |
| `ordenar-carrinhos` | Clique em **Ordenar de A-Z/Ordem Padrão** (Meus Carrinhos). |
| `ordenar-desenhos` | Clique em **Ordenar de A-Z/Ordem Padrão** (Meus Desenhos). |
| `ordenar-cotacoes` | Clique em **Ordenar de A-Z/Ordem Padrão** (Minhas Cotações). |
| `ordenar-cadastros` | Clique em **Ordenar de A-Z/Ordem Padrão** (Meus Cadastros). |
| `ordenar-az` | Ordenação A-Z aplicada (usa `event_label` para a seção). |
| `ordem-padrao` | Ordenação padrão aplicada (usa `event_label` para a seção). |

### Filtros e acessos por seção

| Tag | Quando dispara |
| --- | --- |
| `areacliente-busca-produtos` | Busca em “Meus Produtos Gerados” (digitação + Enter ou botão). |
| `areacliente-produtos-abrir` | Acesso a produto via “Meus Produtos Gerados”. |
| `areacliente-busca-favoritos` | Busca em “Meus Produtos Favoritos” (digitação + Enter ou botão). |
| `areacliente-favoritos-abrir` | Acesso a produto via “Meus Produtos Favoritos”. |
| `areacliente-favoritos-comentario-salvar` | Clique em “Salvar Comentário” nos favoritos. |
| `areacliente-favoritos-comentario-salvo` | Comentário salvo nos favoritos. |
| `areacliente-favoritos-comentario-adicionado` | Comentário de favorito adicionado. |
| `areacliente-favoritos-comentario-removido` | Comentário de favorito removido. |
| `areacliente-favoritos-remover` | Clique em “Remover Favorito”. |
| `areacliente-favoritos-remover-sucesso` | Favorito removido com sucesso. |
| `areacliente-busca-carrinhos` | Busca em “Meus Carrinhos Salvos” (digitação + Enter ou botão). |
| `areacliente-carrinhos-abrir` | Acesso a carrinho via “Meus Carrinhos Salvos”. |
| `areacliente-carrinhos-comentario-salvar` | Clique em “Salvar Comentário” nos carrinhos. |
| `areacliente-carrinhos-comentario-salvo` | Comentário salvo nos carrinhos. |
| `areacliente-carrinhos-comentario-adicionado` | Comentário de carrinho adicionado. |
| `areacliente-carrinhos-comentario-removido` | Comentário de carrinho removido. |
| `areacliente-carrinhos-remover` | Clique em “Excluir Carrinho”. |
| `areacliente-carrinhos-removido` | Carrinho removido. |
| `areacliente-busca-desenhos` | Busca em “Meus Desenhos Solicitados” (digitação + Enter ou botão). |
| `areacliente-desenhos-abrir` | Acesso a desenho via “Meus Desenhos Solicitados”. |
| `areacliente-busca-cotacoes` | Busca em “Minhas Cotações Solicitadas” (digitação + Enter ou botão). |
| `areacliente-cotacoes-abrir` | Acesso a cotação via “Minhas Cotações Solicitadas”. |
| `areacliente-busca-cadastros` | Busca em “Meus Cadastros de Produtos Solicitados” (digitação + Enter ou botão). |
| `areacliente-cadastros-abrir` | Acesso a cadastro via “Meus Cadastros de Produtos Solicitados”. |
