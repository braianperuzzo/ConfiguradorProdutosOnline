# Pipeline de IA: previsão de abandono

Este diretório contém o pipeline mínimo para treinar um modelo simples de previsão de abandono usando os eventos
coletados em `PaginasCarrinhoProdutosRecomenda/Logs`. O modelo é treinado com regressão logística (implementação
própria em Python, sem dependências externas) e exportado para JSON, que é consumido pelo endpoint de inferência.

## Passos do pipeline

1) **Coleta de dados**  
   Os eventos são gravados como JSONL em `PaginasCarrinhoProdutosRecomenda/Logs/eventos-abandono-AAAA-MM-DD.jsonl`.
   Para treinamento supervisionado, eventos de conversão (`conversao_*`) são usados para indicar sessões que
   converteram, enquanto eventos de abandono (`tentativa_saida`, `aba_oculta`, `inatividade`) são usados como
   amostras de risco. O rastreio inclui `sessaoId` para agrupar eventos por sessão.
   Além disso, inferências são registradas em `Logs/inferencias-abandono-AAAA-MM-DD.jsonl` com `sessaoId`,
   `score` e `acao`, e quando ocorre o evento final (`conversao` ou `abandono`) é emitido um log dedicado em
   `Logs/inferencias-abandono-final-AAAA-MM-DD.jsonl`, consolidando o resultado final da sessão.

2) **Treino do modelo**  
   O treinamento é agendado automaticamente pela aplicação sempre que novos eventos são registrados, respeitando um
   intervalo mínimo (atualmente 6 horas) entre execuções. O job é enfileirado na fila de jobs (`treinar_modelo_abandono`)
   e processado pelo worker descrito em `ProcessamentoFilaJobs/OperacaoFilaJobs.md`. Após o treino, logs antigos são
   removidos (padrão: 30 dias de retenção) para evitar crescimento contínuo do diretório.

   O dataset é separado em treino/validação (80/20, ordem temporal), e as métricas são calculadas na validação
   (AUC/ROC, precisão, recall, F1 e accuracy). Essas métricas são exportadas para o JSON do modelo e usadas para
   habilitar as ações do endpoint de inferência.

   Para executar manualmente, use o script abaixo para ler os logs, gerar features e treinar o modelo:

   ```bash
   # Execução local (a partir da raiz do repositório)
   # Linux/macOS
   python3 PaginasCarrinhoProdutosRecomenda/IATreinarModeloAbandono.py \
     --logs-dir PaginasCarrinhoProdutosRecomenda/Logs \
     --saida-modelo PaginasCarrinhoProdutosRecomenda/IAModeloAbandono.json \
     --limpar-logs-dias 30

   # Windows
   python PaginasCarrinhoProdutosRecomenda/IATreinarModeloAbandono.py \
     --logs-dir PaginasCarrinhoProdutosRecomenda/Logs \
     --saida-modelo PaginasCarrinhoProdutosRecomenda/IAModeloAbandono.json \
     --limpar-logs-dias 30

   # Execução em produção (exemplo com caminho absoluto)
   python \\app-ibr\AvantIBR\web\ConfiguradorOnline\PaginasCarrinhoProdutosRecomenda/IATreinarModeloAbandono.py \
     --logs-dir \\app-ibr\AvantIBR\web\ConfiguradorOnline\PaginasCarrinhoProdutosRecomenda/Logs \
     --saida-modelo \\app-ibr\AvantIBR\web\ConfiguradorOnline\PaginasCarrinhoProdutosRecomenda/IAModeloAbandono.json \
     --limpar-logs-dias 30
   ```

3) **Inferência**  
   O endpoint `PaginasCarrinhoProdutosRecomenda/InferirAbandono.php` lê o JSON do modelo e retorna o score. Para
   proteger contra modelos com pouca amostragem e baixa performance, o endpoint força `acao: "neutro"` quando:
   - o modelo tiver menos de 200 amostras (`motivo: "amostras_insuficientes"`); ou
   - a métrica de validação estiver abaixo do mínimo (ex.: `AUC < 0,70`, `motivo: "metricas_insuficientes"`).

4) **Métricas semanais, drift e alertas**  
   O job `metricas_abandono` (fila de jobs) executa `PaginasCarrinhoProdutosRecomenda/IAGerarMetricasAbandono.py`,
   que consolida métricas globais e semanais (ex.: taxa de acerto, AUC, precisão/recall) e calcula drift usando
   PSI na distribuição de scores. Quando há queda significativa de métricas ou mudança brusca na distribuição,
   um alerta é gravado em `Logs/alertas-abandono-AAAA-MM-DD.jsonl`, facilitando o monitoramento.

   Para rodar manualmente:

   ```bash
   # Execução local (a partir da raiz do repositório)
   python3 PaginasCarrinhoProdutosRecomenda/IAGerarMetricasAbandono.py \
     --logs-dir PaginasCarrinhoProdutosRecomenda/Logs \
     --saida-metricas PaginasCarrinhoProdutosRecomenda/IAMetricasAbandono.json \
     --janela-horas 24 \
     --limpar-inferencias-dias 30
   ```

## Modelo treinado

- **Arquivo:** `PaginasCarrinhoProdutosRecomenda/IA/IAModeloAbandono.json`  
- **Uso:** carregado em tempo real pelo endpoint PHP de inferência.
- **Campos principais exportados no JSON:**
  - `versao_modelo`
  - `data_treino`
  - `num_amostras`
  - `metricas` (AUC/ROC, precisão, recall, F1, accuracy)
  - `features_utilizadas`
