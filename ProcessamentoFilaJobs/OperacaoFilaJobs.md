# Operação da fila de jobs em disco

Este guia descreve como inspecionar, reprocessar e higienizar a fila baseada em arquivos JSON utilizada pelos scripts `CriaFilaProcessamento.php` e `ExecutaFilaProcessamento.php`.

## Estrutura
- Os jobs são gravados em `Tokens/FilaJOBs/*.json`. Cada arquivo contém `id`, `tipo`, `dados`, `status`, `tentativas` e, em caso de erro, campos como `mensagem` e `proximaTentativa`.
- O processamento registra logs sanitizados em `../LogsErros/site.log` via `log_event`.
- O arquivo `Tokens/FilaJOBs/monitoramento.json` guarda apenas o timestamp da última varredura da fila.

## Consultar fila rapidamente
1. **Verificar contagem por status (requer `jq`):**
   ```bash
   jq -r '.["status"] // "sem_status"' Tokens/FilaJOBs/*.json 2>/dev/null \
     | sort | uniq -c
   ```
2. **Listar próximos jobs a processar (pendentes ou já elegíveis após backoff):**
   ```bash
   php -r "require 'ProcessamentoFilaJobs/CriaFilaProcessamento.php'; \$base=__DIR__.'/..'; foreach (listar_arquivos_jobs_pendentes(\$base) as \$a){\$j=ler_job_fila(\$a); if(!\$j) continue; \$apt=job_esta_apto_para_processar(\$j,time())?'apto':'aguardando'; echo basename(\$a).' '.(\$j['status']??'pendente').' tentativas='.(\$j['tentativas']??0).' '.\$apt."\n";} }"
   ```
3. **Verificar backlog de processamento em execução:**
   ```bash
   ps -ef | grep ExecutaFilaProcessamento.php | grep -v grep
   ```

## Reprocessar manualmente um job
1. Localize o arquivo do job (`*.json`) na pasta `Tokens/FilaJOBs/`.
2. Edite o JSON redefinindo:
   - `status` para `pendente`.
   - `tentativas` para `0` (ou o valor desejado).
   - Remova `proximaTentativa` e `mensagem` para evitar atrasos ou mensagens antigas.
3. Execute o processamento imediatamente (opcional):
   ```bash
   php ProcessamentoFilaJobs/ExecutaFilaProcessamento.php
   ```

## Limpar dead-letter / jobs esgotados
- A fila para de reprocessar itens com `tentativas >= 5` (constante `FILA_JOBS_MAX_TENTATIVAS`). Esses arquivos permanecem na pasta como uma dead-letter.
- Para limpeza segura:
  1. Mova-os para um backup antes de excluir:
     ```bash
     mkdir -p Tokens/FilaJOBs/dead-letter-backup
     find Tokens/FilaJOBs -maxdepth 1 -name '*.json' \
       -exec sh -c 'jq -e "(.tentativas // 0) >= 5 or .status==\"falha_definitiva\"" "$1" >/dev/null 2>&1' _ {} \; \
       -exec mv {} Tokens/FilaJOBs/dead-letter-backup/ \;
     ```
  2. Depois de revisados, remover os arquivos do backup ou reprocessá-los manualmente conforme necessário.

## Coleta de logs
- Logs principais: `tail -n 100 ../LogsErros/site.log`
- Para extrair erros recentes da fila:
  ```bash
  grep "FilaJobs" ../LogsErros/site.log | tail -n 50
  ```

## Checklist de verificação rápida
- Pasta `Tokens/FilaJOBs` existe e possui permissões de escrita (`0700` criadas automaticamente).
- Não há `process.lock` preso (remova apenas se não houver processo ativo): `ls -l Tokens/FilaJOBs/process.lock`.
- Sem backlog crescente: contagem de `status=pendente` estabilizada após rodar `ExecutaFilaProcessamento.php`.
- Banco acessível (`PDO sqlsrv`) e tokens PipeRun disponíveis quando tipos `piperun_*` falham repetidamente.

## Healthcheck operacional
- Endpoint: `/healthz` (rewrite para `PaginasCarregarPagina/Healthcheck.php`).
- Verificações:
  - Leitura do arquivo de versão em `Versionamento/Versao.json`.
  - Acesso de leitura/escrita na fila `Tokens/FilaJOBs` (inclui gravação de arquivo temporário).
- Em caso de sucesso retorna HTTP 200 com JSON contendo `status`, `timestamp` e `diagnosticos`. Falhas retornam HTTP 500.
- Restrição por IP: a regra em `web.config` permite apenas `127.0.0.1`, `::1` e ranges privados (`10.x`, `172.16-31.x`, `192.168.x`). Ajuste o allowlist conforme necessário.

## Critérios de escalonamento
- Mais de **3 jobs** em `status=erro` com a mesma mensagem após reprocessamento manual.
- Dead-letter (tentativas >= 5) crescendo ou contendo **dados sensíveis** que não podem ser descartados sem aprovação.
- Falhas de conexão com banco ou PipeRun por mais de **15 minutos**.
- `process.lock` persiste por mais de **10 minutos** sem processo ativo (possível travamento ao adquirir lock).

## Referências
- Implementação da fila: `ProcessamentoFilaJobs/CriaFilaProcessamento.php`
- Processador de jobs: `ProcessamentoFilaJobs/ExecutaFilaProcessamento.php`
- Logger sanitizado: `LogsErros/Logs.php`
- Worker em loop: `bin/WorkerFilaJobs.php`

## Execução contínua em produção
Use o worker CLI para manter a fila ativa 24x7. Ele lê o `DOCUMENT_ROOT` e o intervalo de verificação do arquivo `Configuracoes/FilaJobsWorker.ini` e executa `verificar_fila_jobs_periodicamente` e `disparar_processamento_fila_jobs` em loop.

## Métricas Prometheus (rota e autenticação)
- **Rota amigável:** `https://<host>/metrics/fila-jobs` (rewrite para `ProcessamentoFilaJobs/MetricsFilaJobs.php`).
- **Restrição de acesso:** configure allowlist de IP no IIS para limitar o acesso a redes internas/monitoramento. O `web.config` já inclui um bloco `ipSecurity` (exemplos `10.0.0.0/8`, `192.168.0.0/16`, `127.0.0.1`); ajuste conforme seus IPs de Prometheus/Grafana.
- **Alternativa:** se preferir, aplique Basic Auth no IIS (Authentication > Basic Authentication) e armazene credenciais no cofre do time de observabilidade.

## Alertas (Prometheus/Grafana)
Use as métricas já expostas pelo endpoint para criar alertas no Prometheus/Grafana.

### Exemplo de regras Prometheus
```yaml
groups:
  - name: fila-jobs
    rules:
      - alert: FilaJobsPendentesAltos
        expr: fila_jobs_pending_total > 50
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "Fila de jobs pendentes acima do esperado"
          description: "Há {{ $value }} jobs pendentes por mais de 10 minutos."

      - alert: FilaJobsDeadLetterDetectado
        expr: increase(fila_jobs_dead_letter_total[10m]) > 0
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "Jobs em dead-letter detectados"
          description: "Novos jobs foram para dead-letter nos últimos 10 minutos."

      - alert: FilaJobsFalhaAoSpawnar
        expr: increase(fila_jobs_spawn_failures_total[5m]) > 0
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "Falha ao iniciar worker de fila"
          description: "O worker não conseguiu spawnar processos recentemente."
```
> Ajuste os thresholds (`> 50`, janelas e severidade) conforme a carga e SLOs.

### Configuração
1. Ajuste `Configuracoes/FilaJobsWorker.ini` com o caminho absoluto do projeto (`DOCUMENT_ROOT`) e o intervalo desejado (`SLEEP_SECONDS`).
2. Verifique se o caminho informado tem permissão de leitura/gravação para o usuário que rodará o serviço.

### systemd
1. Copie o unit file de exemplo:
   ```bash
   sudo cp ProcessamentoFilaJobs/WorkerFilaJobs.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now WorkerFilaJobs.service
   ```
2. Logs dedicados ficam em `/var/log/fila-jobs-worker.log` (stdout e stderr são redirecionados para esse arquivo pelo unit file).
3. Monitoramento rápido:
   ```bash
   sudo systemctl status WorkerFilaJobs.service
   sudo tail -f /var/log/fila-jobs-worker.log
   ```

### Troubleshooting quando o worker não inicia
- Verifique se o PHP tem permissões para executar comandos em background. As funções `exec` ou `popen` não podem estar na diretiva `disable_functions` do `php.ini`.
- Consulte o log estruturado da fila em `../LogsErros/site.log` procurando pelo evento `processamento_spawn_falhou`. Esse evento inclui detalhes como binário do PHP, SAPI, SO e lista de funções desabilitadas.
- Acesse `Tokens/FilaJOBs/metricas.json` e confirme se o contador `spawnFalhou` está aumentando; se sim, o worker está caindo para execução síncrona.
- Exponha as métricas Prometheus via `ProcessamentoFilaJobs/MetricsFilaJobs.php` e monitore `fila_jobs_spawn_failures_total` para alertar quando o spawn falhar repetidamente.
- Caso `disable_functions` esteja bloqueando, habilite `exec`/`popen` ou configure um wrapper (systemd/cron) que invoque diretamente `ProcessamentoFilaJobs/ExecutaFilaProcessamento.php` como fallback temporário.

### Cron (alternativa)
Se systemd não estiver disponível, adicione uma entrada ao crontab para garantir o processo em loop:
```bash
* * * * * /usr/bin/php /var/www/ConfiguradorOnline/bin/WorkerFilaJobs.php >> /var/log/fila-jobs-worker.log 2>&1
```
Mantenha o intervalo curto (ex.: 1 minuto) apenas para reviver o worker caso ele seja finalizado.
