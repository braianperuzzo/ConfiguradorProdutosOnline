# ConfiguradorOnline

Plataforma web para configuração, consulta e navegação de produtos industriais, com suporte a dados técnicos e comerciais, autenticação de usuários, otimização para publicação e rotinas operacionais de processamento assíncrono.

## Objetivo

O **ConfiguradorOnline** centraliza a experiência de seleção e consulta de produtos em uma aplicação web única. O projeto combina recursos de catálogo, configuradores por linha, integração com dados técnicos e comerciais, área do cliente e mecanismos de distribuição otimizados para produção.

## Escopo da aplicação

O repositório reúne frontend e backend da solução e contempla os seguintes grupos funcionais:

* configuradores de produtos por linha ou família,
* navegação de catálogo e páginas auxiliares,
* consultas técnicas e comerciais,
* busca semântica e recursos baseados em RAG,
* área do cliente com autenticação e recuperação de acesso,
* rotinas de SEO técnico,
* pipeline de build e minificação,
* processamento assíncrono com fila de jobs.

## Funcionalidades implantadas

### Catálogo e configuradores

A plataforma já possui configuradores para diferentes linhas de produto, incluindo:

* AC
* AE
* FX
* HY
* IN
* MO
* PL
* QU
* QUDR
* VA

Além das páginas de configuração, o sistema inclui:

* página principal de produtos,
* navegação compartilhada entre linhas,
* recursos de equivalência,
* sugestões e recomendações relacionadas,
* apoio à comparação com concorrentes.

### Consultas técnicas e inteligência de dados

O sistema expõe endpoints e módulos para:

* consulta de estrutura de produto,
* consulta de estoque,
* comparação de datasheets,
* consulta de carcaça,
* sugestões de configuração,
* busca semântica,
* consultas baseadas em RAG.

Também existem artefatos de embeddings utilizados como suporte às consultas semânticas dos configuradores.

### Área do cliente

A área logada contempla o fluxo completo de acesso:

* cadastro de usuário,
* confirmação de conta,
* login,
* recuperação de senha,
* redefinição de senha,
* validações de autenticação,
* histórico de ações do cliente.

O sistema também suporta autenticação social com:

* Google,
* Microsoft.

### Segurança e resiliência

A aplicação possui mecanismos voltados à segurança de sessão e execução, incluindo:

* proteção CSRF em frontend e backend,
* política de CSP com uso de nonce para scripts inline,
* Service Worker para cache e disponibilidade parcial offline.

### SEO técnico e distribuição

O projeto inclui rotinas automatizadas para:

* geração de sitemap de páginas,
* geração de sitemap de imagens,
* atualização de `canonical`,
* atualização de `lastmod`,
* submissão via IndexNow,
* validações técnicas de indexação.

### Operação assíncrona

Existe uma estrutura dedicada para execução assíncrona de tarefas com:

* criação de jobs,
* execução de jobs,
* worker dedicado,
* monitoramento,
* métricas operacionais.

## Arquitetura de build

O processo de build é centralizado em `Build.js` e complementado por scripts NPM. Em termos gerais, o pipeline executa as seguintes etapas:

1. atualização da versão da aplicação,
2. geração do manifesto de imagens,
3. geração do sitemap de imagens,
4. compilação de Bootstrap SCSS para CSS minificado,
5. processamento de estilos com PostCSS,
6. geração de dados de produtos consumidos no frontend,
7. minificação de JavaScript e CSS com `esbuild`,
8. geração e injeção de precache no Service Worker com `workbox-build`,
9. atualização de `lastmod` nos sitemaps,
10. validação da existência dos artefatos de saída.

Em alguns cenários, o pipeline também pode executar treinamento de modelo relacionado a abandono de carrinho via Python, desde que os scripts e logs necessários estejam disponíveis.

## Stack e recursos técnicos

### Frontend e entrega

* arquivos `.min.js` e `.min.css` gerados para publicação,
* caminhos previsíveis para assets distribuídos,
* fontes locais com preload,
* cache estratégico com Service Worker,
* manifesto de aplicação para apoio à distribuição e performance.

### Backend e integração

* endpoints para consulta técnica e comercial,
* integração com serviços externos,
* módulos internos de acesso a dados do catálogo,
* geração e validação de tokens.

### Busca e inteligência

* busca semântica,
* suporte a RAG,
* embeddings para recuperação de contexto técnico.

### Operação

* versionamento interno da aplicação,
* estrutura de fila de jobs,
* monitoramento e métricas,
* documentação operacional de execução contínua.

## Estrutura de diretórios

A organização principal do projeto é a seguinte:

```text
PaginasConfiguradores/               Páginas dos configuradores por linha
PaginasPrincipal/                    Página principal e navegação de catálogo
PaginasConsultaProdutos/             Consultas técnicas, comerciais e busca semântica
PaginasAreaClienteAcessoCadastro/    Login, cadastro e recuperação de acesso
PaginasNavegacaoCompartilhada/       Componentes e páginas reutilizáveis
Layout/                              Estrutura visual e elementos de interface
Fontes/                              Assets, estilos e scripts visuais
SEO/                                 Rotinas e validações de SEO técnico
ProcessamentoFilaJobs/               Worker, monitor, execução e métricas
Versionamento/                       Controle de versão para build e cache
AcessosConsultas/                    Acesso a dados e integrações
TokensGeradores/                     Geração e validação de tokens
```

## Rotinas principais

### Build completo

```bash
npm run build
```

### Rotinas de SEO

```bash
npm run lint:tags
npm run test:h1
npm run sitemap-images.xml
npm run sitemap-index.xml
npm run submit-indexnow
```

### Auditoria de backlinks

```bash
npm run seo:auditar-backlinks -- --input ./SEO/backlinks-bing.csv --min-score 60 --top 50
```

## Requisitos de manutenção

Ao realizar alterações no projeto, recomenda-se observar os seguintes pontos:

* manter compatibilidade com o pipeline de minificação,
* revisar o Service Worker sempre que novos arquivos críticos forem adicionados,
* validar o precache quando houver mudança em assets essenciais,
* executar rotinas de SEO antes da publicação quando houver alteração de indexação,
* revisar fluxos de sessão, CSRF e recuperação de senha em qualquer mudança na área logada,
* validar corretamente os artefatos gerados no build antes de promover publicação.

## Boas práticas de evolução

Para ampliar a previsibilidade operacional e facilitar manutenção futura, este README pode evoluir com os seguintes complementos:

* diagrama de arquitetura da aplicação,
* mapa de dependências entre módulos,
* matriz de responsabilidade por pasta,
* checklist de release por ambiente,
* runbook de incidentes,
* instruções de observabilidade e troubleshooting.

## Observações finais

Este repositório concentra tanto a camada de experiência do usuário quanto serviços de apoio à consulta, autenticação, SEO técnico e processamento operacional. Por isso, alterações em qualquer módulo devem considerar impactos cruzados em build, cache, segurança, indexação e integrações.