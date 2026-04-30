<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

set_time_limit(300);

$ultimoJobEmExecucao = null;
register_shutdown_function(function () use (&$ultimoJobEmExecucao) {
    $erro = error_get_last();
    if ($erro === null) {
        return;
    }

    $tiposFatais = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($erro['type'], $tiposFatais, true)) {
        return;
    }

    if (!is_array($ultimoJobEmExecucao) || empty($ultimoJobEmExecucao['__arquivo'])) {
        return;
    }

    try {
        atualizar_job_fila($ultimoJobEmExecucao, [
            'status'           => 'erro',
            'mensagem'         => sprintf('Processo encerrado abruptamente: %s', $erro['message'] ?? 'erro fatal'),
            'proximaTentativa' => calcular_proxima_tentativa((int) ($ultimoJobEmExecucao['tentativas'] ?? 0), time()),
        ]);

        log_event(sprintf(
            'FilaJobs - job %s marcado como erro após encerramento inesperado (%s:%d): %s',
            $ultimoJobEmExecucao['id'] ?? 'sem_id',
            $erro['file'] ?? 'desconhecido',
            $erro['line'] ?? 0,
            $erro['message'] ?? 'erro fatal'
        ));
    } catch (Throwable $t) {
        if (function_exists('log_event')) {
            log_event('FilaJobs - falha ao registrar erro fatal de shutdown.', [
                'erro' => $t->getMessage(),
            ]);
        } else {
            error_log('FilaJobs - falha ao registrar erro fatal de shutdown: ' . $t->getMessage());
        }
    }
});

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

try {
    require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
    require_once $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';
    require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
    require_once $baseDir . '/AcessosConsultas/CredenciaisPiperun.php';
    require_once $baseDir . '/PaginasAreaClienteAcessoCadastro/CadastroPlenusAPI.php';
} catch (Throwable $t) {
    if (function_exists('log_event')) {
        log_event('FilaJobs - não foi possível inicializar dependências: ' . $t->getMessage());
    } else {
        error_log('FilaJobs - não foi possível inicializar dependências: ' . $t->getMessage());
    }
    exit(1);
}
require_once $baseDir . '/PaginasSolicitacoes/SuporteCategoria.php';
$formatarDocumento = function (?string $valor): string {
    $digitos = preg_replace('/[^0-9A-Za-z]/', '', (string) $valor);

    if (strlen($digitos) === 14) {
        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digitos));
    }

    if (strlen($digitos) === 11) {
        return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digitos));
    }

    return trim((string) $valor);
};

function classificar_erro_job(Throwable $t): array
{
    $mensagem = mb_strtolower(trim($t->getMessage()), 'UTF-8');
    $mensagemNormalizada = preg_replace('/\s+/', ' ', $mensagem);

    $padroesTemporarios = [
        'timeout',
        'timed out',
        'temporariamente indispon',
        'connection refused',
        'could not connect',
        'unable to connect',
        'connection reset',
        'failed to connect',
        'service unavailable',
        'try again',
        'deadlock',
        'locking timeout',
        'server error 5',
        'ssl: certificate',
    ];

    foreach ($padroesTemporarios as $padrao) {
        if (mb_strpos($mensagemNormalizada, $padrao, 0, 'UTF-8') !== false) {
            return ['categoria' => 'temporario', 'descricao' => $t->getMessage()];
        }
    }

    $padroesDefinitivos = [
        'inválid',
        'invalid',
        'incomplet',
        'obrigat',
        'não informado',
        'nao informado',
        'ausente',
        'payload',
        'formato',
        'dados de oportunidade incompletos',
        'cnpj inválido',
    ];

    foreach ($padroesDefinitivos as $padrao) {
        if (mb_strpos($mensagemNormalizada, $padrao, 0, 'UTF-8') !== false) {
            return ['categoria' => 'definitivo', 'descricao' => $t->getMessage()];
        }
    }

    if ($t instanceof InvalidArgumentException || $t instanceof DomainException) {
        return ['categoria' => 'definitivo', 'descricao' => $t->getMessage()];
    }

    return ['categoria' => 'temporario', 'descricao' => $t->getMessage()];
}

function rag_limpar_html(string $html): string
{
    $texto = strip_tags($html);
    $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = preg_replace('/\s+/', ' ', $texto);
    return trim($texto);
}

function rag_normalizar_texto(string $valor): string
{
    $texto = trim($valor);
    if ($texto === '') {
        return '';
    }
    $texto = mb_strtolower($texto, 'UTF-8');
    $translit = iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
    if ($translit !== false) {
        $texto = $translit;
    }
    $texto = preg_replace('/[^a-z0-9\s.]+/', ' ', $texto);
    $texto = str_replace('.', ' ', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    return trim($texto);
}

function rag_extrair_atributo(string $tag, string $atributo): string
{
    if (preg_match('/\b' . preg_quote($atributo, '/') . '="([^"]*)"/i', $tag, $match)) {
        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return '';
}

function rag_gerar_resumo(string $texto, int $maxChars = 240): string
{
    $limpo = trim(preg_replace('/\s+/', ' ', $texto));
    if ($limpo === '') {
        return '';
    }
    if (mb_strlen($limpo, 'UTF-8') <= $maxChars) {
        return $limpo;
    }
    return mb_substr($limpo, 0, $maxChars - 1, 'UTF-8') . '…';
}

function rag_carregar_json(string $arquivo, array $padrao = []): array
{
    if (!is_readable($arquivo)) {
        return $padrao;
    }
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return $padrao;
    }
    $json = json_decode($conteudo, true);
    if (!is_array($json)) {
        return $padrao;
    }
    return $json;
}

function rag_adicionar_documento(array &$documentos, string $id, string $tipo, string $titulo, string $texto, string $url, string $fonte): void
{
    $resumo = rag_gerar_resumo($texto);
    $textoBusca = trim(rag_normalizar_texto($titulo . ' ' . $texto . ' ' . $resumo));
    if ($textoBusca !== '') {
        $textoBusca = ' ' . $textoBusca . ' ';
    }
    $documentos[] = [
        'id' => $id,
        'tipo' => $tipo,
        'titulo' => $titulo,
        'texto' => $texto,
        'resumo' => $resumo,
        'url' => $url,
        'fonte' => $fonte,
        'textoBusca' => $textoBusca,
    ];
}

function rag_processar_informacoes_produtos(string $baseDir, array &$documentos, array &$fontes): void
{
    $arquivo = $baseDir . '/PaginasConfiguradores/InformacoesProdutos.json';
    $conteudo = rag_carregar_json($arquivo);
    if (!$conteudo) {
        return;
    }
    $fontes[] = $arquivo;
    foreach ($conteudo as $linha => $info) {
        if (!is_array($info)) {
            continue;
        }
        $titulo = (string) ($info['title'] ?? $linha);
        $descricao = (string) ($info['description'] ?? '');
        $categorias = isset($info['categories']) && is_array($info['categories'])
            ? implode(', ', $info['categories'])
            : '';
        $grupos = isset($info['groups']) && is_array($info['groups'])
            ? implode(', ', $info['groups'])
            : '';
        $texto = trim($descricao . ' ' . $categorias . ' ' . $grupos);
        $id = 'catalogo_' . strtoupper((string) $linha);
        $url = '/PaginasConfiguradores/Configurador' . strtoupper((string) $linha) . '.html';
        rag_adicionar_documento($documentos, $id, 'catalogo', $titulo, $texto, $url, $arquivo);
    }
}

function rag_processar_fonte_catalogo(string $baseDir, array &$documentos, array &$fontes): void
{
    $arquivo = $baseDir . '/PaginasConfiguradores/InformacoesProdutosFonte.json';
    $conteudo = rag_carregar_json($arquivo);
    if (!$conteudo) {
        return;
    }
    $fontes[] = $arquivo;
    foreach ($conteudo as $linha => $info) {
        if (!is_array($info)) {
            continue;
        }
        $titulo = (string) ($info['title'] ?? $linha);
        $descricao = (string) ($info['description'] ?? '');
        $texto = trim($descricao);
        $id = 'catalogo_fonte_' . strtoupper((string) $linha);
        $url = '/PaginasConfiguradores/Configurador' . strtoupper((string) $linha) . '.html';
        rag_adicionar_documento($documentos, $id, 'catalogo_fonte', $titulo, $texto, $url, $arquivo);
    }
}

function rag_processar_configuradores(string $baseDir, array &$documentos, array &$fontes): void
{
    $dir = $baseDir . '/PaginasConfiguradores';
    if (!is_dir($dir)) {
        return;
    }
    $arquivos = glob($dir . '/Configurador*.html');
    if (!is_array($arquivos)) {
        return;
    }
    foreach ($arquivos as $arquivo) {
        $conteudo = file_get_contents($arquivo);
        if ($conteudo === false) {
            continue;
        }
        $fontes[] = $arquivo;
        $titulo = '';
        $descricao = '';
        if (preg_match('/<title>(.*?)<\/title>/is', $conteudo, $match)) {
            $titulo = rag_limpar_html($match[1]);
        }
        if (preg_match('/<meta\\s+name="description"\\s+content="([^"]*)"/i', $conteudo, $match)) {
            $descricao = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $basename = basename($arquivo, '.html');
        $url = '/PaginasConfiguradores/' . $basename . '.html';
        rag_adicionar_documento($documentos, 'pagina_' . $basename, 'configurador', $titulo ?: $basename, $descricao, $url, $arquivo);
    }
}

function rag_processar_seletores(string $baseDir, array &$documentos, array &$fontes, string $dirRelativo): void
{
    $dir = $baseDir . '/' . $dirRelativo;
    if (!is_dir($dir)) {
        return;
    }
    $arquivos = glob($dir . '/*.html');
    if (!is_array($arquivos)) {
        return;
    }
    foreach ($arquivos as $arquivo) {
        $conteudo = file_get_contents($arquivo);
        if ($conteudo === false) {
            continue;
        }
        $fontes[] = $arquivo;
        if (!preg_match_all('/<div[^>]*class="[^"]*galeria-produto[^"]*"[^>]*>/i', $conteudo, $matches)) {
            continue;
        }
        foreach ($matches[0] as $tag) {
            $titulo = rag_extrair_atributo($tag, 'data-title');
            $descricao = rag_extrair_atributo($tag, 'data-description');
            $site = rag_extrair_atributo($tag, 'data-site');
            $catalogo = rag_extrair_atributo($tag, 'data-catalog');
            $visivel = rag_extrair_atributo($tag, 'data-visible');
            $texto = trim($descricao . ' ' . $site . ' ' . $catalogo);
            $id = 'seletor_' . ($visivel ?: md5($titulo . $descricao));
            $url = $site ?: ('/' . $dirRelativo . '/' . basename($arquivo));
            rag_adicionar_documento($documentos, $id, 'linha_configurador', $titulo ?: $visivel, $texto, $url, $arquivo);
        }
    }
}

function rag_processar_politica_privacidade(string $baseDir, array &$documentos, array &$fontes): void
{
    $arquivo = $baseDir . '/PaginasPoliticaPrivacidade/PoliticaPrivacidadeTexto.html';
    if (!is_readable($arquivo)) {
        return;
    }
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return;
    }
    $fontes[] = $arquivo;
    $texto = rag_limpar_html($conteudo);
    rag_adicionar_documento($documentos, 'politica_privacidade', 'politica', 'Política de Privacidade', $texto, '/PaginasPoliticaPrivacidade/PoliticaPrivacidadeTexto.html', $arquivo);
}

function rag_gerar_indice_configuradores(string $baseDir, ?string $jobId = null): array
{
    $documentos = [];
    $fontes = [];

    rag_processar_informacoes_produtos($baseDir, $documentos, $fontes);
    rag_processar_fonte_catalogo($baseDir, $documentos, $fontes);
    rag_processar_configuradores($baseDir, $documentos, $fontes);
    rag_processar_seletores($baseDir, $documentos, $fontes, 'PaginasConfiguradoresSeletores');
    rag_processar_seletores($baseDir, $documentos, $fontes, 'PaginasConfiguradoresSeletoresConsultas');
    rag_processar_politica_privacidade($baseDir, $documentos, $fontes);

    $destinoDir = $baseDir . '/Tokens/Conhecimento';
    if (!is_dir($destinoDir)) {
        mkdir($destinoDir, 0700, true);
    }
    $arquivoDestino = $destinoDir . '/configuradores_index.json';

    $dados = [
        'versao' => 1,
        'geradoEm' => date(DATE_ATOM),
        'jobId' => $jobId,
        'fontes' => array_values(array_unique($fontes)),
        'totalDocumentos' => count($documentos),
        'documentos' => $documentos,
    ];

    file_put_contents(
        $arquivoDestino,
        json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
    chmod($arquivoDestino, 0600);

    log_event(json_encode([
        'timestamp' => date(DATE_ATOM),
        'componente' => 'rag_configuradores',
        'evento' => 'indice_atualizado',
        'jobId' => $jobId,
        'totalDocumentos' => count($documentos),
        'arquivo' => $arquivoDestino,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return [
        'arquivo' => $arquivoDestino,
        'totalDocumentos' => count($documentos),
    ];
}

function obter_id_pessoa_pipe(array $personPayload): ?string
{
    $id = $personPayload['person_id']
        ?? $personPayload['id']
        ?? $personPayload['data']['person_id']
        ?? $personPayload['data']['id']
        ?? null;

    if ($id === null) {
        return null;
    }

    $id = trim((string) $id);
    return $id !== '' ? $id : null;
}

function buscar_pessoa_por_external_code(string $email, string $tokenPipe): ?string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nToken: $tokenPipe\r\n",
        ],
    ]);

    $resp = @file_get_contents('https://api.pipe.run/v1/persons?external_code=' . rawurlencode($email), false, $context);
    if ($resp === false) {
        return null;
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        return null;
    }

    return obter_id_pessoa_pipe($json)
        ?? obter_id_pessoa_pipe($json['data'][0] ?? [])
        ?? obter_id_pessoa_pipe($json[0] ?? []);
}

function criar_pessoa_pipe(array $personData, string $headersPipe): ?string
{
    if (!isset($personData['contactEmails']) || !is_array($personData['contactEmails'])) {
        $personData['contactEmails'] = [];
    }

    $personData['contactEmails'] = array_values(array_unique(array_filter(array_map(function ($email) {
        $email = strtolower(trim((string) $email));
        return $email !== '' ? $email : null;
    }, $personData['contactEmails']))));

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => $headersPipe,
            'content' => json_encode($personData),
        ],
    ]);

    $resp = @file_get_contents('https://api.pipe.run/v1/persons', false, $context);
    if ($resp === false) {
        return null;
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        return null;
    }

    return obter_id_pessoa_pipe($json);
}

function obter_person_id_pipe(string $email, array $personData, string $headersPipe, string $tokenPipe): ?string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $personId = buscar_pessoa_por_external_code($email, $tokenPipe);
    if ($personId !== null) {
        return $personId;
    }

    $personData['external_code'] = $email;
    if (!isset($personData['contactEmails']) || !is_array($personData['contactEmails'])) {
        $personData['contactEmails'] = [$email];
    } elseif (!in_array($email, $personData['contactEmails'], true)) {
        $personData['contactEmails'][] = $email;
    }

    return criar_pessoa_pipe($personData, $headersPipe);
}

function criar_oportunidade_piperun(array $payload, string $tokenPipe): void
{
    $headersPipe = "Accept: application/json\r\nContent-Type: application/json\r\nToken: $tokenPipe\r\n";

    $dataHora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $tituloBase = ($payload['tituloPrefixo'] ?? 'Novo cadastro') . ' - ' . ($payload['nome'] ?? '');
    $tituloComData = $tituloBase . ' - ' . $dataHora->format('d/m/Y H:i');

    $dealData = [
        'pipeline_id' => (int) ($payload['pipelineId'] ?? 0),
        'stage_id'    => (int) ($payload['stageId'] ?? 0),
        'title'       => $tituloComData,
        'tags'        => [['id' => 375000]],
    ];

    $contextDeal = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => $headersPipe,
            'content' => json_encode($dealData),
        ],
    ]);

    $respDeal = @file_get_contents('https://api.pipe.run/v1/deals', false, $contextDeal);
    $dealJson = $respDeal !== false ? json_decode($respDeal, true) : null;
    $dealId = $dealJson['deal_id'] ?? $dealJson['id'] ?? $dealJson['data']['deal_id'] ?? $dealJson['data']['id'] ?? '';

    if (!$dealId && $respDeal !== false) {
        log_event('Retorno inesperado ao criar oportunidade em background: ' . $respDeal);
    }

    if (!$dealId) {
        return;
    }

    $linhasNota = [
        'Nome: ' . ($payload['nome'] ?? ''),
        (strlen($payload['cpfcnpj'] ?? '') === 11 ? 'CPF' : 'CNPJ') . ': ' . ($payload['cpfcnpj'] ?? ''),
        'Data/Hora da solicitação: ' . $dataHora->format('d/m/Y H:i'),
    ];

    if (!empty($payload['empresa'])) {
        $linhasNota[] = 'Empresa: ' . $payload['empresa'];
    }

    if (!empty($payload['email'])) {
        $linhasNota[] = 'Email: ' . $payload['email'];
    }

    foreach ($payload['linhasExtras'] ?? [] as $linhaExtra) {
        $linhaExtra = trim((string) $linhaExtra);
        if ($linhaExtra !== '') {
            $linhasNota[] = $linhaExtra;
        }
    }

    $textoNota = implode('<br>', $linhasNota);

    $contextNote = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => $headersPipe,
            'content' => json_encode(['deal_id' => $dealId, 'text' => $textoNota]),
        ],
    ]);

    @file_get_contents('https://api.pipe.run/v1/notes', false, $contextNote);

    $principalEmail = strtolower(trim((string) ($payload['email'] ?? '')));
    if ($principalEmail === '') {
        return;
    }

    $personData = [
        'name'          => $payload['nome'] ?? 'Não informado',
        'contactEmails' => [$principalEmail],
    ];

    $personId = obter_person_id_pipe($principalEmail, $personData, $headersPipe, $tokenPipe);

    if (!$personId) {
        return;
    }

    $contextAddPerson = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'header' => "Accept: application/json\r\nToken: $tokenPipe\r\n",
        ],
    ]);

    @file_get_contents("https://api.pipe.run/v1/deals/$dealId/persons/$personId", false, $contextAddPerson);
}

function executar_treino_modelo_abandono(array $dados, string $baseDir): void
{
    $script = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IATreinarModeloAbandono.py';
    if (!is_file($script)) {
        throw new RuntimeException('Script de treino não encontrado.');
    }

    $logsDir = $dados['logsDir'] ?? ($baseDir . '/PaginasCarrinhoProdutosRecomenda/Logs');
    if (!is_dir($logsDir)) {
        throw new RuntimeException('Diretório de logs do treino não encontrado.');
    }

    $saidaModelo = $dados['saidaModelo'] ?? ($baseDir . '/PaginasCarrinhoProdutosRecomenda/IAModeloAbandono.json');

    $comandoPython = localizar_comando_python();
    if ($comandoPython === '') {
        throw new RuntimeException('Interpreter Python não encontrado no ambiente.');
    }

    $limparLogsDias = (int) ($dados['limparLogsDias'] ?? 30);
    $argsExtras = '';
    if ($limparLogsDias > 0) {
        $argsExtras = ' --limpar-logs-dias ' . escapeshellarg((string) $limparLogsDias);
    }

    $comando = sprintf(
        '%s %s --logs-dir %s --saida-modelo %s%s',
        escapeshellcmd($comandoPython),
        escapeshellarg($script),
        escapeshellarg($logsDir),
        escapeshellarg($saidaModelo),
        $argsExtras
    );

    $saida = [];
    $retorno = 0;
    exec($comando . ' 2>&1', $saida, $retorno);

    if ($retorno !== 0) {
        $erro = trim(implode("\n", $saida));
        throw new RuntimeException('Falha ao treinar modelo de abandono: ' . ($erro !== '' ? $erro : 'erro desconhecido'));
    }
}

function executar_metricas_abandono(array $dados, string $baseDir): void
{
    $script = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IAGerarMetricasAbandono.py';
    if (!is_file($script)) {
        throw new RuntimeException('Script de métricas de abandono não encontrado.');
    }

    $logsDir = $dados['logsDir'] ?? ($baseDir . '/PaginasCarrinhoProdutosRecomenda/Logs');
    if (!is_dir($logsDir)) {
        throw new RuntimeException('Diretório de logs das métricas não encontrado.');
    }

    $saidaMetricas = $dados['saidaMetricas'] ?? ($baseDir . '/PaginasCarrinhoProdutosRecomenda/IAMetricasAbandono.json');
    $janelaHoras = $dados['janelaHoras'] ?? 24;
    $limparInferenciasDias = isset($dados['limparInferenciasDias']) ? (int) $dados['limparInferenciasDias'] : 30;

    $comandoPython = localizar_comando_python();
    if ($comandoPython === '') {
        throw new RuntimeException('Interpreter Python não encontrado no ambiente.');
    }

    $comando = sprintf(
        '%s %s --logs-dir %s --saida-metricas %s --janela-horas %s --limpar-inferencias-dias %s',
        escapeshellcmd($comandoPython),
        escapeshellarg($script),
        escapeshellarg($logsDir),
        escapeshellarg($saidaMetricas),
        escapeshellarg((string) $janelaHoras),
        escapeshellarg((string) $limparInferenciasDias)
    );

    $saida = [];
    $retorno = 0;
    exec($comando . ' 2>&1', $saida, $retorno);

    if ($retorno !== 0) {
        $erro = trim(implode("\n", $saida));
        throw new RuntimeException('Falha ao gerar métricas de abandono: ' . ($erro !== '' ? $erro : 'erro desconhecido'));
    }
}

function executar_treino_recomendacoes_coocorrencia(array $dados, string $baseDir): void
{
    $script = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IATreinarRecomendacoesCoocorrencia.py';
    if (!is_file($script)) {
        throw new RuntimeException('Script de treino de recomendações não encontrado.');
    }

    $historicoDir = $dados['historicoDir'] ?? ($baseDir . '/Tokens/HistoricoCarrinhos');
    if (!is_dir($historicoDir)) {
        throw new RuntimeException('Diretório de histórico de carrinhos não encontrado.');
    }

    $saidaModelo = $dados['saidaModelo'] ?? ($baseDir . '/PaginasCarrinhoProdutosRecomenda/IARecomendacoesCoocorrencia.json');

    $comandoPython = localizar_comando_python();
    if ($comandoPython === '') {
        throw new RuntimeException('Interpreter Python não encontrado no ambiente.');
    }

    $limparHistoricoDias = (int) ($dados['limparHistoricoDias'] ?? 90);
    $minimoCoocorrencia = (int) ($dados['minimoCoocorrencia'] ?? 2);
    $limitePorProduto = (int) ($dados['limitePorProduto'] ?? 12);
    $limiteGlobal = (int) ($dados['limiteGlobal'] ?? 24);

    $comando = sprintf(
        '%s %s --historico-dir %s --saida-modelo %s --minimo-coocorrencia %s --limite-por-produto %s --limite-global %s%s',
        escapeshellcmd($comandoPython),
        escapeshellarg($script),
        escapeshellarg($historicoDir),
        escapeshellarg($saidaModelo),
        escapeshellarg((string) $minimoCoocorrencia),
        escapeshellarg((string) $limitePorProduto),
        escapeshellarg((string) $limiteGlobal),
        $limparHistoricoDias > 0 ? ' --limpar-historico-dias ' . escapeshellarg((string) $limparHistoricoDias) : ''
    );

    $saida = [];
    $retorno = 0;
    exec($comando . ' 2>&1', $saida, $retorno);

    if ($retorno !== 0) {
        $erro = trim(implode("\n", $saida));
        throw new RuntimeException('Falha ao treinar recomendações: ' . ($erro !== '' ? $erro : 'erro desconhecido'));
    }
}

function obter_status_treino_abandono(string $baseDir): array
{
    $arquivo = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IATreinoModeloAbandono.json';
    if (!is_file($arquivo)) {
        return ['__arquivo' => $arquivo];
    }
    $json = json_decode((string) file_get_contents($arquivo), true);
    if (!is_array($json)) {
        return ['__arquivo' => $arquivo];
    }
    $json['__arquivo'] = $arquivo;
    return $json;
}

function salvar_status_treino_abandono(array $status): void
{
    $arquivo = $status['__arquivo'] ?? '';
    if ($arquivo === '') {
        return;
    }
    unset($status['__arquivo']);
    file_put_contents(
        $arquivo,
        json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function obter_status_metricas_abandono(string $baseDir): array
{
    $arquivo = $baseDir . '/PaginasCarrinhoProdutosRecomenda/IAMetricasAbandonoStatus.json';
    if (!is_file($arquivo)) {
        return ['__arquivo' => $arquivo];
    }
    $json = json_decode((string) file_get_contents($arquivo), true);
    if (!is_array($json)) {
        return ['__arquivo' => $arquivo];
    }
    $json['__arquivo'] = $arquivo;
    return $json;
}

function salvar_status_metricas_abandono(array $status): void
{
    $arquivo = $status['__arquivo'] ?? '';
    if ($arquivo === '') {
        return;
    }
    unset($status['__arquivo']);
    file_put_contents(
        $arquivo,
        json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function registrar_sucesso_treino_abandono(string $baseDir, ?string $jobId): void
{
    $status = obter_status_treino_abandono($baseDir);
    $status['ultimoTreinoEm'] = time();
    if ($jobId) {
        $status['ultimoJobId'] = $jobId;
    }
    salvar_status_treino_abandono($status);
}

function registrar_sucesso_metricas_abandono(string $baseDir, ?string $jobId): void
{
    $status = obter_status_metricas_abandono($baseDir);
    $status['ultimaExecucaoEm'] = time();
    if ($jobId) {
        $status['ultimoJobId'] = $jobId;
    }
    salvar_status_metricas_abandono($status);
}

function localizar_comando_python(): string
{
    $candidatos = ['python3', 'python'];
    $verificador = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';

    foreach ($candidatos as $candidato) {
        $saida = [];
        $retorno = 0;
        @exec($verificador . ' ' . escapeshellarg($candidato), $saida, $retorno);
        if ($retorno === 0 && !empty($saida)) {
            return $candidato;
        }
    }

    return $candidatos[0] ?? '';
}

function processar_job(array $job, PDO $pdo, string $baseDir, callable $formatarDocumento): void
{
    $dados = $job['dados'] ?? [];
    $tipo = $job['tipo'] ?? '';

    switch ($tipo) {
        case 'treinar_modelo_abandono':
            executar_treino_modelo_abandono($dados, $baseDir);
            registrar_sucesso_treino_abandono($baseDir, $job['id'] ?? null);
            atualizar_job_fila($job, [
                'status' => 'processado',
                'processadoEm' => time(),
            ]);
            break;
        case 'metricas_abandono':
            executar_metricas_abandono($dados, $baseDir);
            registrar_sucesso_metricas_abandono($baseDir, $job['id'] ?? null);
            atualizar_job_fila($job, [
                'status' => 'processado',
                'processadoEm' => time(),
            ]);
            break;
        case 'treinar_recomendacoes_coocorrencia':
            executar_treino_recomendacoes_coocorrencia($dados, $baseDir);
            atualizar_job_fila($job, [
                'status' => 'processado',
                'processadoEm' => time(),
            ]);
            break;
        case 'indexar_rag_configuradores':
            $resultado = rag_gerar_indice_configuradores($baseDir, $job['id'] ?? null);
            atualizar_job_fila($job, [
                'status' => 'processado',
                'processadoEm' => time(),
                'resultado' => $resultado,
            ]);
            break;
        case 'plenus_sincronizar_contato':
            $cnpj = preg_replace('/[^0-9A-Za-z]/', '', (string) ($dados['cnpj'] ?? ''));
            $email = strtolower(trim((string) ($dados['email'] ?? '')));
            $nome = mb_strtoupper((string) ($dados['nome'] ?? ''), 'UTF-8');

            if (strlen($cnpj) !== 14) {
                throw new RuntimeException('CNPJ inválido para sincronização do Plenus.');
            }

            $codigo = obter_codigo_cliente_plenus_por_cnpj($pdo, $cnpj);

            $sincronizacao = sincronizar_cadastro_plenus($pdo, $cnpj, $nome, $email, $baseDir, $formatarDocumento);
            if ($sincronizacao !== null) {
                $codigo = $sincronizacao['codigo'] ?? $codigo;
            }

            if ($codigo !== null && !contato_site_existe_plenus($pdo, $codigo, $email)) {
                atualizar_contato_cliente_plenus($pdo, $codigo, $nome, $email, $baseDir);
            }

            atualizar_job_fila($job, ['status' => 'processado', 'processadoEm' => time()]);
            break;

        case 'piperun_criar_oportunidade':
            $tokenPipe = require_pipe_token();
            criar_oportunidade_piperun($dados, $tokenPipe);
            atualizar_job_fila($job, ['status' => 'processado', 'processadoEm' => time()]);
            break;

        case 'indexar_catalogo_embeddings':
            require_once $baseDir . '/SEO/ListaConfiguradoresLinkAmigavel.php';
            require_once $baseDir . '/PaginasConsultaProdutos/EmbeddingsCatalogo.php';
            $resultado = gerar_index_embeddings_catalogo($baseDir, [
                'forcar' => (bool) ($dados['forcar'] ?? false),
                'origem' => $dados['origem'] ?? 'fila_jobs',
            ]);
            atualizar_job_fila($job, [
                'status' => 'processado',
                'processadoEm' => time(),
                'resultado' => $resultado,
            ]);
            break;

            case 'piperun_solicitacao':
            $tokenPipe = require_pipe_token();

            $deal = $dados['deal'] ?? [];
            $pipelineId = (int) ($deal['pipeline_id'] ?? 0);
            $stageId = (int) ($deal['stage_id'] ?? 0);
            $titulo = trim((string) ($deal['title'] ?? ''));

            if (!$pipelineId || !$stageId || $titulo === '') {
                throw new RuntimeException('Dados de oportunidade incompletos para a solicitação.');
            }

            $headersPipe = "Accept: application/json\r\nContent-Type: application/json\r\nToken: $tokenPipe\r\n";
            $companyId = null;
            $companyCnpj = preg_replace('/[^0-9A-Za-z]/', '', (string) ($deal['company_cnpj'] ?? ''));

            if ($companyCnpj !== '') {
                $contextCompany = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => "Accept: application/json\r\nToken: $tokenPipe\r\n",
                    ],
                ]);
                $respCompany = @file_get_contents("https://api.pipe.run/v1/companies?show=1&sort=ASC&cnpj={$companyCnpj}", false, $contextCompany);
                if ($respCompany !== false) {
                    $json = json_decode($respCompany, true);
                    $companyId = $json['data'][0]['id'] ?? $json[0]['id'] ?? $json['id'] ?? null;
                }
            }

            $dealPayload = [
                'pipeline_id' => $pipelineId,
                'stage_id'    => $stageId,
                'title'       => $titulo,
                'tags'        => $deal['tags'] ?? [],
            ];

            if (!empty($deal['created_at'])) {
                $dealPayload['created_at'] = $deal['created_at'];
            }

            if ($companyId) {
                $dealPayload['company_id'] = $companyId;
            }

            if (!empty($deal['custom_fields']) && is_array($deal['custom_fields'])) {
                $dealPayload['custom_fields'] = $deal['custom_fields'];
            }

            $contextDeal = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => $headersPipe,
                    'content' => json_encode($dealPayload),
                ],
            ]);

            $respDeal = @file_get_contents('https://api.pipe.run/v1/deals', false, $contextDeal);
            $dealJson = $respDeal !== false ? json_decode($respDeal, true) : null;
            $dealId = $dealJson['deal_id'] ?? $dealJson['id'] ?? $dealJson['data']['deal_id'] ?? $dealJson['data']['id'] ?? '';

            if (!$dealId) {
                throw new RuntimeException('Falha ao criar oportunidade na PipeRun.');
            }

            $historicoSuporteId = trim((string) ($dados['historico_suporte_id'] ?? ''));
            if ($historicoSuporteId !== '') {
                atualizar_historico_suporte($baseDir, $historicoSuporteId, [
                    'status' => 'enviado',
                    'dealId' => $dealId,
                    'atualizadoEm' => date(DATE_ATOM),
                ]);
            }

            $emailsAdicionados = [];
            foreach ($dados['contatos'] ?? [] as $contato) {
                $emailContato = strtolower(trim((string) ($contato['email'] ?? '')));
                if ($emailContato === '' || isset($emailsAdicionados[$emailContato])) {
                    continue;
                }
                $emailsAdicionados[$emailContato] = true;

                $personData = [
                    'name'          => $contato['nome'] ?? 'Não informado',
                    'contactEmails' => [$emailContato],
                ];

                $personId = obter_person_id_pipe($emailContato, $personData, $headersPipe, $tokenPipe);

                if ($personId) {
                    $contextAddPerson = stream_context_create([
                        'http' => [
                            'method' => 'PUT',
                            'header' => "Accept: application/json\r\nToken: $tokenPipe\r\n",
                        ],
                    ]);

                    @file_get_contents("https://api.pipe.run/v1/deals/$dealId/persons/$personId", false, $contextAddPerson);
                }
            }

            $linhasNota = [];
            foreach ($dados['linhasNota'] ?? [] as $linha) {
                $linha = trim((string) $linha);
                if ($linha !== '') {
                    $linhasNota[] = $linha;
                }
            }

            if ($linhasNota) {
                $textoNota = implode('<br>', $linhasNota);

                $contextNote = stream_context_create([
                    'http' => [
                        'method'  => 'POST',
                        'header'  => $headersPipe,
                        'content' => json_encode(['deal_id' => $dealId, 'text' => $textoNota]),
                    ],
                ]);

                @file_get_contents('https://api.pipe.run/v1/notes', false, $contextNote);
            }

            $notasAdicionais = is_array($dados['notasAdicionais'] ?? null) ? $dados['notasAdicionais'] : [];
            foreach ($notasAdicionais as $notaAdicional) {
                $linhasNotaAdicional = [];

                if (is_array($notaAdicional)) {
                    foreach ($notaAdicional as $linhaNotaAdicional) {
                        $linhaNotaAdicional = trim((string) $linhaNotaAdicional);
                        if ($linhaNotaAdicional !== '') {
                            $linhasNotaAdicional[] = $linhaNotaAdicional;
                        }
                    }
                } else {
                    $textoNotaAdicional = trim((string) $notaAdicional);
                    if ($textoNotaAdicional !== '') {
                        $linhasNotaAdicional[] = $textoNotaAdicional;
                    }
                }

                if ($linhasNotaAdicional === []) {
                    continue;
                }

                $textoNotaAdicional = implode('<br>', $linhasNotaAdicional);
                $contextNotaAdicional = stream_context_create([
                    'http' => [
                        'method'  => 'POST',
                        'header'  => $headersPipe,
                        'content' => json_encode(['deal_id' => $dealId, 'text' => $textoNotaAdicional]),
                    ],
                ]);

                @file_get_contents('https://api.pipe.run/v1/notes', false, $contextNotaAdicional);
            }

           foreach ($dados['historicos'] ?? [] as $historico) {
                $tipoHistorico = $historico['tipo'] ?? '';

                switch ($tipoHistorico) {
                    case 'cotacao_produto':
                    case 'cotacao_intercambialidade':
                        $host = trim((string) ($historico['host'] ?? ''));
                        $referencia = trim((string) ($historico['referencia'] ?? ''));
$emailsHistorico = $historico['emails'] ?? [];
                        $cnpjHistorico = preg_replace('/[^0-9A-Za-z]/', '', (string) ($historico['cnpj'] ?? ''));
                        $linkHistorico = trim((string) ($historico['link'] ?? ''));

                        $possuiDadosDiretos = $referencia !== ''
                            && $cnpjHistorico !== ''
                            && is_array($emailsHistorico)
                            && count($emailsHistorico) > 0;

                        if ($possuiDadosDiretos) {
                            $sqlHist = "INSERT INTO _USR_CONF_SITE_HISTORICO_COTACAO (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, CD_OPORTUNIDADE, DS_LINK, DT_DATA)
            SELECT ?, ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_COTACAO
                  WHERE DS_EMAIL = ?
                    AND NR_CPFCNPJ = ?
                    AND DS_REFERENCIA = ?
                    AND CD_OPORTUNIDADE = ?
                    AND CONVERT(VARCHAR(MAX), DS_LINK) = ?
             )";

                            $stmHistorico = $pdo->prepare($sqlHist);

                            foreach ($emailsHistorico as $emailHist) {
                                $emailHist = strtolower(trim((string) $emailHist));
                                if ($emailHist === '') {
                                    continue;
                                }

                                $stmHistorico->execute([
                                    $emailHist,
                                    $cnpjHistorico,
                                    strtoupper($referencia),
                                    $dealId,
                                    $linkHistorico,
                                    $emailHist,
                                    $cnpjHistorico,
                                    strtoupper($referencia),
                                    $dealId,
                                    $linkHistorico,
                                ]);
                            }
                        } elseif ($host !== '' && $referencia !== '') {
                                                            $params = http_build_query([
                                'produto' => $referencia,
                                'oportunidade' => $dealId,
                            ]);

                            @file_get_contents(
                                'https://' . $host . '/PaginasAreaClienteRegistrosHistoricos/RegistrarCotacao.php',
                                false,
                                stream_context_create([
                                    'http' => [
                                        'method'  => 'POST',
                                        'header'  => 'Content-Type: application/x-www-form-urlencoded\r\n',
                                        'content' => $params,
                                    ],
                                ])
                            );
                        }
                        break;


                    case 'cotacao_gpt_api_chat':
                        $documentoHistorico = preg_replace('/[^0-9A-Za-z]/', '', (string) ($historico['documento'] ?? $historico['cnpj'] ?? ''));
                        $emailsHistorico = is_array($historico['emails'] ?? null) ? $historico['emails'] : [];
                        $referenciaHistorico = strtoupper(trim((string) ($historico['referencia'] ?? '')));
                        $codigoHistorico = strtoupper(trim((string) ($historico['codigo'] ?? '')));
                        $linkHistorico = trim((string) ($historico['link'] ?? ''));

                        $identificadorHistorico = $referenciaHistorico !== ''
                            ? $referenciaHistorico
                            : ($codigoHistorico !== '' ? $codigoHistorico : 'CHATGPT');

                        if ($documentoHistorico !== '' && count($emailsHistorico) > 0) {
                            $sqlHist = "INSERT INTO _USR_CONF_SITE_HISTORICO_COTACAO (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, CD_OPORTUNIDADE, DS_LINK, DT_DATA)
            SELECT ?, ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_COTACAO
                  WHERE DS_EMAIL = ?
                    AND NR_CPFCNPJ = ?
                    AND DS_REFERENCIA = ?
                    AND CD_OPORTUNIDADE = ?
                    AND CONVERT(VARCHAR(MAX), DS_LINK) = ?
             )";

                            $stmHistorico = $pdo->prepare($sqlHist);
                            foreach ($emailsHistorico as $emailHist) {
                                $emailHist = strtolower(trim((string) $emailHist));
                                if ($emailHist === '') {
                                    continue;
                                }

                                $stmHistorico->execute([
                                    $emailHist,
                                    $documentoHistorico,
                                    $identificadorHistorico,
                                    $dealId,
                                    $linkHistorico,
                                    $emailHist,
                                    $documentoHistorico,
                                    $identificadorHistorico,
                                    $dealId,
                                    $linkHistorico,
                                ]);
                            }
                        }
                        break;

 case 'cotacao_carrinho':
                        $cnpj = preg_replace('/[^0-9A-Za-z]/', '', (string) ($historico['cnpj'] ?? ''));
                        $emails = $historico['emails'] ?? [];
                        $referencias = $historico['referencias'] ?? [];
                        $hostCotacao = trim((string) ($historico['host'] ?? ''));
                        $linksMap = [];
                        foreach (is_array($historico['links'] ?? null) ? $historico['links'] : [] as $refLink => $linkValor) {
                            $refNormalizada = strtoupper(trim((string) $refLink));
                            if ($refNormalizada === '') {
                                continue;
                            }
                            $linkNormalizado = trim((string) $linkValor);
                            if ($linkNormalizado !== '') {
                                $linksMap[$refNormalizada] = $linkNormalizado;
                            }
                        }

                        $stmtBuscaLinkProduto = null;
                        $obterLinkProduto = function (string $ref) use (&$linksMap, &$stmtBuscaLinkProduto, $pdo, $hostCotacao): string {
                            if (isset($linksMap[$ref]) && $linksMap[$ref] !== '') {
                                return $linksMap[$ref];
                            }

                            if ($stmtBuscaLinkProduto === null) {
                                $stmtBuscaLinkProduto = $pdo->prepare(
                                    "SELECT TOP 1 CONVERT(VARCHAR(MAX), DS_LINK) AS link
                                       FROM _USR_CONF_SITE_HISTORICO_PRODUTO
                                      WHERE DS_REFERENCIA = ?
                                        AND DS_LINK IS NOT NULL
                                        AND LTRIM(RTRIM(CONVERT(VARCHAR(MAX), DS_LINK))) <> ''
                                      ORDER BY DT_DATA DESC"
                                );
                            }

                            $stmtBuscaLinkProduto->execute([$ref]);
                            $linkDb = trim((string) $stmtBuscaLinkProduto->fetchColumn());
                            if ($linkDb !== '') {
                                $linksMap[$ref] = $linkDb;
                                return $linkDb;
                            }

                            if ($hostCotacao !== '') {
                                $hostLimpo = preg_replace('~^https?://~i', '', $hostCotacao);
                                $linkGerado = 'https://' . $hostLimpo . '/PaginasConsultaProdutos/ConsultaEstoque.php?codigo=' . rawurlencode($ref);
                                $linksMap[$ref] = $linkGerado;
                                return $linkGerado;
                            }

                            return '';
                        };

                        $sql = "INSERT INTO _USR_CONF_SITE_HISTORICO_COTACAO (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, CD_OPORTUNIDADE, DS_LINK, DT_DATA)
            SELECT ?, ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_COTACAO
                  WHERE DS_EMAIL = ?
                    AND NR_CPFCNPJ = ?
                    AND DS_REFERENCIA = ?
                    AND CD_OPORTUNIDADE = ?
                    AND CONVERT(VARCHAR(MAX), DS_LINK) = ?
             )";

                        $stmHist = $pdo->prepare($sql);
                        foreach ($referencias as $ref) {
                            $ref = strtoupper(trim((string) $ref));
                            if ($ref === '') {
                                continue;
                            }
                            foreach ($emails as $mail) {
                                $mail = strtolower(trim((string) $mail));
                                if ($mail === '') {
                                    continue;
                                 }
                                $linkReferencia = $obterLinkProduto($ref);
                                $stmHist->execute([
                                    $mail,
                                    $cnpj,
                                    $ref,
                                    $dealId,
                                    $linkReferencia,
                                    $mail,
                                    $cnpj,
                                    $ref,
                                    $dealId,
                                    $linkReferencia,
                                ]);
                            }
                        }
                        break;

                    case 'cadastro_produto':
                        $documento = preg_replace('/[^0-9A-Za-z]/', '', (string) ($historico['documento'] ?? ''));
                        $referencia = strtoupper(trim((string) ($historico['referencia'] ?? '')));
                        $emailsCadastro = $historico['emails'] ?? [];
                        $linkCadastro = trim((string) ($historico['link'] ?? ''));

                        if ($referencia === '' || !$emailsCadastro) {
                            break;
                        }

                        $sqlAtualizaCadastro = "UPDATE _USR_CONF_SITE_HISTORICO_CADASTROS
                           SET CD_OPORTUNIDADE = ?,
                               DS_LINK = CASE WHEN (DS_LINK IS NULL OR DATALENGTH(DS_LINK) = 0) AND ? <> '' THEN ? ELSE DS_LINK END
                         WHERE DS_EMAIL = ?
                           AND NR_CPFCNPJ = ?
                           AND DS_REFERENCIA = ?
                           AND (CD_OPORTUNIDADE IS NULL OR CD_OPORTUNIDADE = ?)";

                        $stmtAtualizaCadastro = $pdo->prepare($sqlAtualizaCadastro);
                        
                        $sqlCadastro = "INSERT INTO _USR_CONF_SITE_HISTORICO_CADASTROS (DS_EMAIL, NR_CPFCNPJ, DS_REFERENCIA, CD_OPORTUNIDADE, DS_LINK, DT_DATA)
            SELECT ?, ?, ?, ?, ?, CONVERT(VARCHAR(19), GETDATE(), 120)
             WHERE NOT EXISTS (
                 SELECT 1 FROM _USR_CONF_SITE_HISTORICO_CADASTROS
                  WHERE DS_EMAIL = ? AND NR_CPFCNPJ = ? AND DS_REFERENCIA = ? AND (CD_OPORTUNIDADE = ? OR CD_OPORTUNIDADE IS NULL)
             )";

                        $stmtCadastro = $pdo->prepare($sqlCadastro);
                        foreach ($emailsCadastro as $emailCad) {
                            $emailCad = strtolower(trim((string) $emailCad));
                            if ($emailCad === '') {
                                continue;
                            }

                            $stmtAtualizaCadastro->execute([
                                $dealId,
                                $linkCadastro,
                                $linkCadastro,
                                $emailCad,
                                $documento,
                                $referencia,
                                $dealId,
                            ]);

                            $stmtCadastro->execute([
                                $emailCad,
                                $documento,
                                $referencia,
                                $dealId,
                                $linkCadastro,
                                $emailCad,
                                $documento,
                                $referencia,
                                $dealId,
                                $dealId,
                            ]);
                        }

                        if ($linkCadastro !== '') {
                            $contextAtualiza = stream_context_create([
                                'http' => [
                                    'method'  => 'PUT',
                                    'header'  => $headersPipe,
                                    'content' => json_encode([
                                        'deal_id'       => $dealId,
                                        'custom_fields' => [
                                            ['id' => 259977, 'value' => $linkCadastro],
                                        ],
                                    ]),
                                ],
                            ]);

                            @file_get_contents('https://api.pipe.run/v1/deals', false, $contextAtualiza);
                        }
                        break;
                }
            }

            atualizar_job_fila($job, ['status' => 'processado', 'processadoEm' => time()]);
            break;
            
        default:
            throw new RuntimeException('Tipo de job desconhecido: ' . $tipo);
    }
}

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
    ]);
} catch (PDOException $e) {
    log_event('FilaJobs - não foi possível conectar ao banco: ' . $e->getMessage());
    exit(1);
}

$lockArquivo = obter_diretorio_fila_jobs($baseDir) . DIRECTORY_SEPARATOR . 'process.lock';
$lockHandle = fopen($lockArquivo, 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$executouAlgum = false;
$tempoMaximoEspera = 10 * 60;

while (true) {
    $arquivos = listar_arquivos_jobs_pendentes($baseDir);
    $agora = time();
    $executouAlgum = false;
    $menorProximaTentativa = null;

    foreach ($arquivos as $arquivo) {
        $job = ler_job_fila($arquivo);
        if ($job === null) {
            @unlink($arquivo);
            continue;
        }

        $statusJob = $job['status'] ?? '';
        if (in_array($statusJob, ['erro', 'reprocessamento'], true)) {
            $proximaTentativa = isset($job['proximaTentativa']) ? (int) $job['proximaTentativa'] : null;
            if ($proximaTentativa !== null && $proximaTentativa > $agora) {
                $menorProximaTentativa = $menorProximaTentativa === null
                    ? $proximaTentativa
                    : min($menorProximaTentativa, $proximaTentativa);
            }
        }

        if (!job_esta_apto_para_processar($job, $agora)) {
            continue;
        }
        $tentativaAtual = (int) ($job['tentativas'] ?? 0) + 1;
        $ultimoJobEmExecucao = $job;
        $dadosResumo = resumir_dados_job($job['dados'] ?? []);
        $inicioProcessamento = microtime(true);

        ajustar_metricas_em_processamento($baseDir, 1);

        registrar_log_job([
            'nivel'     => 'info',
            'status'    => 'inicio',
            'jobId'     => $job['id'] ?? 'sem_id',
            'tipo'      => $job['tipo'] ?? 'sem_tipo',
            'tentativa' => $tentativaAtual,
            'payload'   => $dadosResumo,
            'timestampInicio' => date(DATE_ATOM, (int) $inicioProcessamento),
        ]);

        try {
            processar_job($job, $pdo, $baseDir, $formatarDocumento);
            $duracao = microtime(true) - $inicioProcessamento;
                        $ultimoJobEmExecucao = null;
            registrar_metricas_processamento($baseDir, $duracao, true);
        } catch (Throwable $t) {
            $tentativaAtual = (int) ($job['tentativas'] ?? 0) + 1;
            $statusAtual = $job['status'] ?? 'pendente';
            $dadosJson = json_encode($job['dados'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $dadosResumo = $dadosJson !== false ? substr($dadosJson, 0, 800) : 'dados_invalidos';
            if ($dadosJson !== false && strlen($dadosJson) > 800) {
                $dadosResumo .= '... [truncado]';
            }

               $erroClassificado = classificar_erro_job($t);
            $erroTemporario = ($erroClassificado['categoria'] ?? '') === 'temporario';

            $mensagemErro = sprintf(
                'FilaJobs - falha ao processar job %s (tipo=%s, status=%s, tentativa=%d): %s | dados=%s',
                $job['id'] ?? 'sem_id',
                $job['tipo'] ?? 'sem_tipo',
                $statusAtual,
                $tentativaAtual,
                $t->getMessage(),
                $dadosResumo
            );

            $traceErro = method_exists($t, 'getTraceAsString') ? $t->getTraceAsString() : (string) $t;
            $mensagemLog = $mensagemErro . "\nTrace: " . $traceErro;

            if (function_exists('log_event')) {
                log_event($mensagemLog);
            } else {
                if (function_exists('log_event')) {
                    log_event('FilaJobs - erro não tratado.', [
                        'mensagem' => $mensagemLog,
                    ]);
                } else {
                    error_log($mensagemLog);
                }
            }

            registrar_log_job([
                'nivel'             => 'error',
                'status'            => 'falha',
                'jobId'             => $job['id'] ?? 'sem_id',
                'tipo'              => $job['tipo'] ?? 'sem_tipo',
                'tentativa'         => $tentativaAtual,
                'payload'           => $dadosResumo,
                'categoriaErro'     => $erroClassificado['categoria'] ?? 'temporario',
                'causaClassificada' => $erroClassificado['descricao'] ?? $t->getMessage(),
            ]);

            registrar_catalogo_erro(
                $baseDir,
                $erroClassificado['categoria'] ?? 'temporario',
                $erroClassificado['descricao'] ?? $t->getMessage()
            );

            $atingiuLimite = !$erroTemporario || $tentativaAtual >= FILA_JOBS_MAX_TENTATIVAS;

            $proximaTentativa = $atingiuLimite ? null : calcular_proxima_tentativa($tentativaAtual, $agora);
            $novoStatus = $atingiuLimite ? 'dead_letter' : 'reprocessamento';
            $momentoFalha = time();

      atualizar_job_fila($job, [
                'status'              => $novoStatus,
                'mensagem'            => $t->getMessage(),
                'processadoEm'        => $momentoFalha,
                'ultimaFalhaEm'       => $momentoFalha,
                'proximaTentativa'    => $proximaTentativa,
                'ultimoErroDetalhado' => [
                    'mensagem'       => $t->getMessage(),
                    'trace'          => $traceErro,
                    'dadosResumo'    => $dadosResumo,
                    'tentativa'      => $tentativaAtual,
                    'statusAnterior' => $statusAtual,
                    'categoriaErro'  => $erroClassificado['categoria'] ?? 'temporario',
                    'causaClassificada' => $erroClassificado['descricao'] ?? $t->getMessage(),
                    'ocorridoEm'     => $momentoFalha,
                ],
                'deadLetter'         => $atingiuLimite,
                'categoriaErro'      => $erroClassificado['categoria'] ?? 'temporario',
                'causaClassificada'  => $erroClassificado['descricao'] ?? $t->getMessage(),
            ]);

            if ($novoStatus === 'dead_letter') {
                mover_job_para_dead_letter($job, $baseDir);
            }

            registrar_historico_reprocessamento($baseDir, [
                'jobId'          => $job['id'] ?? 'sem_id',
                'tipo'           => $job['tipo'] ?? 'sem_tipo',
                'mensagem'       => $t->getMessage(),
                'tentativa'      => $tentativaAtual,
                'statusAnterior' => $statusAtual,
                'deadLetter'     => $atingiuLimite,
                'categoriaErro'  => $erroClassificado['categoria'] ?? 'temporario',
                'causaClassificada' => $erroClassificado['descricao'] ?? $t->getMessage(),
                'ocorridoEm'     => date(DATE_ATOM, $momentoFalha),
            ]);

            $duracao = microtime(true) - $inicioProcessamento;
            registrar_metricas_processamento($baseDir, $duracao, false);

                        $ultimoJobEmExecucao = null;
            continue;
        } finally {
            ajustar_metricas_em_processamento($baseDir, -1);
        }

        $jobAtualizado = ler_job_fila($arquivo);
        if (($jobAtualizado['status'] ?? '') === 'processado') {
            remover_job_fila($jobAtualizado);

             } elseif (
            $jobAtualizado !== null
            && ($jobAtualizado['status'] ?? 'pendente') === 'pendente'
            && (int) ($jobAtualizado['tentativas'] ?? 0) === (int) ($job['tentativas'] ?? 0)
        ) {
            $mensagemPendencia = 'FilaJobs - job permaneceu pendente após tentativa de processamento; enviando para dead letter';
            if (function_exists('log_event')) {
                log_event($mensagemPendencia . ' | job=' . ($job['id'] ?? 'sem_id'));
            }

            atualizar_job_fila($job, [
                'status'           => 'dead_letter',
                'mensagem'         => 'Job movido automaticamente após permanecer pendente',
                'deadLetter'       => true,
                'processadoEm'     => time(),
                'ultimaFalhaEm'    => time(),
                'proximaTentativa' => null,
            ]);

            mover_job_para_dead_letter($job, $baseDir);
        }

        $executouAlgum = true;
    }
if ($executouAlgum) {
        continue;
    }

    if ($menorProximaTentativa !== null) {
        $tempoEspera = $menorProximaTentativa - time();
        if ($tempoEspera > 0) {
            $tempoEspera = (int) min($tempoMaximoEspera, $tempoEspera);
                       sleep($tempoEspera);
            continue;
        }
    }

    break;
}

if (is_resource($lockHandle)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
