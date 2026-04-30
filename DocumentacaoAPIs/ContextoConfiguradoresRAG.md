# Contexto dos configuradores para base de conhecimento (RAG)

Este documento descreve as fontes de contexto exibidas nas páginas de configuradores e quais dados podem alimentar uma base de conhecimento para consultas e respostas automáticas.

## 1) Catálogo de produtos e linhas
- **PaginasConfiguradores/InformacoesProdutos.json**: catálogo consolidado com `title`, `description`, `categories` e `groups` por linha de produto.
- **PaginasConfiguradores/InformacoesProdutosFonte.json**: base auxiliar com dados brutos (fonte) do catálogo, útil para enriquecer descrições e sinônimos.
- **PaginasConfiguradoresSeletores/SeletoresConfigurador*.html**: cada seletor possui cartões `galeria-produto` com `data-title`, `data-description`, `data-site` e `data-catalog`. Esses dados exibem a descrição pública de cada linha e URLs relevantes.

## 2) Contexto de páginas de configuradores
- **PaginasConfiguradores/Configurador*.html**: páginas de entrada que carregam o configurador principal. Contêm `<title>` e `<meta name="description">` que resumem o propósito do configurador e ajudam a contextualizar o produto.
- **PaginasConfiguradoresSeletores/** e **PaginasConfiguradoresSeletoresConsultas/**: listam opções de linhas, chamadas e descrições adicionais para seleção antes do configurador final.

## 3) Políticas e informações institucionais
- **PaginasPoliticaPrivacidade/PoliticaPrivacidadeTexto.html**: política de privacidade exibida no site. Útil para responder dúvidas sobre coleta de dados, cookies e segurança.
- **PaginasPoliticaPrivacidade/PoliticaPrivacidade.html**: versão completa com layout (pode ser usada para extração adicional se necessário).

## 4) FAQs e suporte
- Não há um arquivo de FAQ dedicado no repositório. As dúvidas mais frequentes podem ser derivadas das descrições dos produtos, políticas e textos institucionais.

## 5) Sugestão de estrutura para indexação
- **Documentos de catálogo**: um documento por linha de produto (ID, título, descrição, categorias, grupos, URL amigável/selector).
- **Documentos de página**: um documento por configurador com título e meta description.
- **Documento de política**: um documento único com o texto da política de privacidade.

## 6) Observações de atualização
- Sempre que houver alteração em `InformacoesProdutos.json`, `InformacoesProdutosFonte.json` ou nos seletores de configurador, reindexar a base para manter as respostas atualizadas.

## 7) Operação da base de conhecimento (idempotência)
- Em rotinas de gravação que usam chave de idempotência, pode ocorrer retorno temporário `IDEMPOTENCY_IN_PROGRESS`.
- Esse status indica que a mesma requisição ainda está sendo processada no backend e **não confirma falha definitiva**.
- Fluxo recomendado:
  1. Aguardar alguns segundos.
  2. Repetir a consulta de status usando a mesma chave idempotente.
  3. Só reenviar gravação com nova chave quando houver confirmação de falha/expiração da anterior.
- Para auditoria, registrar o `idempotency_key`, horário da tentativa e resposta retornada.
