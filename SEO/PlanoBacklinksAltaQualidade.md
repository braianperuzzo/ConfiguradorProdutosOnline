# Plano de correção: Links de entrada de domínios de alta qualidade inadequados

## Contexto do alerta
- **Severidade:** Moderado.
- **Sintoma:** baixa quantidade de backlinks vindos de domínios relevantes e confiáveis para o nicho.
- **Impacto esperado:** menor autoridade de domínio, dificuldade de ganho de posições orgânicas e menos tráfego de referência.

## Objetivo
Aumentar, em ciclos mensais, a quantidade e a qualidade de links de entrada de forma natural, rastreável e sem risco de penalização por práticas artificiais.


## O que está sendo feito, de fato (execução prática)
1. Exportar o CSV de backlinks do Bing Webmaster Tools (domínio, origem, destino e âncora).
2. Rodar a auditoria automatizada:
   ```bash
   npm run seo:auditar-backlinks -- --input ./SEO/backlinks-bing.csv --min-score 60 --top 50
   ```
3. Para comparar com concorrente e encontrar domínios que ainda não apontam para o site:
   ```bash
   npm run seo:auditar-backlinks -- --input ./SEO/backlinks-bing.csv --competitor ./SEO/backlinks-concorrente.csv --gap-output ./SEO/BacklinksGapConcorrente.csv
   ```
4. O script gera `SEO/BacklinksPriorizados.csv` com:
   - score (0 a 100);
   - classificação (alta/média/baixa);
   - ação recomendada por backlink.
5. O time executa outreach focando primeiro nos domínios de maior score e substitui fontes fracas.
6. No fechamento do ciclo mensal, repete a exportação do Bing e compara evolução do score médio, dos domínios qualificados e do gap em relação aos concorrentes.

## Processo recomendado (Bing Webmaster Tools)
1. Acesse **Bing Webmaster Tools** e abra o relatório de backlinks do domínio.
2. Exporte os dados de:
   - domínios de referência;
   - páginas de origem;
   - páginas de destino;
   - texto âncora.
3. Classifique cada domínio em três níveis:
   - **Alta qualidade:** tema relacionado, domínio ativo, conteúdo editorial real, bom histórico;
   - **Média qualidade:** parcialmente relacionado ou com baixa frequência de atualização;
   - **Baixa qualidade:** agregadores suspeitos, diretórios fracos, PBNs, spam.
4. Compare com 2–3 concorrentes diretos para identificar gaps de domínios que já citam concorrentes e ainda não citam este site.

## Critérios de qualidade de backlinks
Considere um backlink de alta qualidade quando houver, em conjunto:
- relação temática com produtos/mercado do site;
- página indexável e com conteúdo original;
- contexto editorial (link inserido em conteúdo útil, não rodapé em massa);
- domínio com sinais de confiabilidade (histórico, presença orgânica, sem padrão de spam);
- âncora natural (marca, URL, variações sem sobre-otimização).

## Plano de aquisição (90 dias)

### Fase 1 — Preparação (semanas 1–2)
- Definir páginas prioritárias para receber links (categorias e páginas estratégicas).
- Criar 2–4 ativos linkáveis:
  - guia técnico;
  - estudo comparativo;
  - FAQ aprofundado;
  - material com dados de mercado.
- Padronizar UTM para tráfego de referência em campanhas de outreach.

### Fase 2 — Prospecção (semanas 3–6)
- Montar lista de parceiros por cluster:
  - portais do setor;
  - blogs técnicos;
  - associações;
  - fornecedores/complementares.
- Priorizar domínios com alta aderência temática e histórico editorial.
- Preparar abordagem personalizada por segmento.

### Fase 3 — Outreach e publicação (semanas 7–10)
- Executar contatos semanais com pauta de valor (não compra de links).
- Propor formatos:
  - artigo colaborativo;
  - citação de especialista;
  - case técnico;
  - inclusão em páginas de recursos.
- Acompanhar status por domínio: contato, negociação, publicado, recusado.

### Fase 4 — Medição e ajuste (semanas 11–12)
- Recoletar backlinks no Bing Webmaster Tools.
- Medir:
  - novos domínios de referência qualificados;
  - tráfego de referência por domínio;
  - crescimento de impressões e cliques orgânicos em páginas destino.
- Remover foco de canais de baixa performance e reforçar os que geraram domínios qualificados.

## Métricas de acompanhamento (mensal)
- Número de **novos domínios de alta qualidade**.
- Percentual de backlinks em páginas realmente indexadas.
- Distribuição de âncora (marca x genérica x exata).
- Tráfego de referência e engajamento por domínio de origem.
- Evolução de rankings das páginas priorizadas.

## Checklist operacional
- [ ] Exportar dados de backlinks no Bing.
- [ ] Classificar qualidade de domínios.
- [ ] Comparar com concorrentes e fechar gap de oportunidades.
- [ ] Definir ativos linkáveis do mês.
- [ ] Executar outreach com registro de status.
- [ ] Atualizar métricas e revisar estratégia.

## Boas práticas e riscos
**Faça:**
- focar em relevância temática;
- priorizar menções editoriais genuínas;
- diversificar textos âncora e páginas de destino.

**Evite:**
- compra de links em escala;
- redes privadas de blogs (PBN) e diretórios de spam;
- excesso de âncora exata repetida.

---

### Modelo de registro rápido (exemplo)
| Domínio | Qualidade | Tema aderente | Tipo de link | Página destino | Status |
|---|---|---|---|---|---|
| exemplo.com | Alta | Sim | Artigo editorial | /configuradores | Publicado |
| portal-setor.com | Média | Parcial | Página de recursos | /consulta-produtos | Em negociação |

> Este plano atende diretamente o alerta de “links de entrada de domínios de alta qualidade inadequados”, transformando-o em rotina contínua de melhoria com base em dados do Bing Webmaster Tools.
