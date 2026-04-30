<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

require_once __DIR__ . '/NucleoApiChat.php';
require_once $baseDir . '/TokensGeradores/TokensLimiteSolicitacoes.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
require_once $baseDir . '/PaginasSolicitacoes/SuporteCategoria.php';
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';

const API_CHAT_SOLICITACAO_COTACAO_ENDPOINT = '/APIChat/SolicitacaoCotacao.php';

api_chat_middleware_init($baseDir, API_CHAT_SOLICITACAO_COTACAO_ENDPOINT);
auth_api_chat_validar_ou_responder($baseDir, 'responder_solicitacao_cotacao_api_chat');

function responder_solicitacao_cotacao_api_chat(int $status, array $payload): void
{
    $idempotencyKey = api_chat_middleware_idempotency_header_key();
    if ($idempotencyKey !== '') {
        api_chat_middleware_idempotency_finalizar(API_CHAT_SOLICITACAO_COTACAO_ENDPOINT, $idempotencyKey, $status, $payload);
    }
    api_chat_middleware_responder($status, $payload);
}

function api_chat_solicitacao_cotacao_log(string $baseDir, string $tipo, array $dados): void
{
    $arquivo = rtrim($baseDir, '/\\') . '/APIChat/Logs/solicitacao-cotacao-' . date('Y-m-d') . '.jsonl';
    $registro = [
        'timestamp' => gmdate('c'),
        'tipo' => $tipo,
        'requestId' => api_chat_middleware_request_id_atual(),
        'dados' => $dados,
    ];

    api_chat_append_jsonl($arquivo, $registro);
}

function api_chat_validar_nome_completo(string $nome): bool
{
    return api_chat_validar_nome_completo_padrao($nome);
}

function api_chat_validar_telefone_com_ddd(string $telefone): bool
{
    $digitos = api_chat_documento_somente_digitos($telefone);
    if (str_starts_with($digitos, '55') && api_chat_strlen($digitos) >= 12) {
        $digitos = api_chat_substr($digitos, 2);
    }

    $len = api_chat_strlen($digitos);
    if ($len !== 10 && $len !== 11) {
        return false;
    }

    $ddd = (int) api_chat_substr($digitos, 0, 2);
    return $ddd >= 11 && $ddd <= 99;
}

function api_chat_normalizar_conversa_chatgpt($conversa): string
{
    if (is_array($conversa)) {
        $partes = [];
        foreach ($conversa as $item) {
            if (is_array($item)) {
                $papel = trim((string) ($item['role'] ?? $item['papel'] ?? ''));
                $conteudo = trim((string) ($item['content'] ?? $item['conteudo'] ?? ''));
                $linha = trim(($papel !== '' ? strtoupper($papel) . ': ' : '') . $conteudo);
                if ($linha !== '') {
                    $partes[] = $linha;
                }
                continue;
            }

            $texto = trim((string) $item);
            if ($texto !== '') {
                $partes[] = $texto;
            }
        }

        $conversa = implode(PHP_EOL, $partes);
    }

    $texto = trim((string) $conversa);
    if ($texto === '') {
        return '';
    }

    if (api_chat_strlen($texto) > 10000) {
        return api_chat_substr($texto, 0, 10000);
    }

    return $texto;
}

function api_chat_normalizar_emails($emails): array
{
    $colecao = [];

    if (is_string($emails)) {
        $colecao = preg_split('/\s*;\s*/', trim($emails)) ?: [];
    } elseif (is_array($emails)) {
        $colecao = $emails;
    }

    $saida = [];
    $map = [];
    foreach ($colecao as $email) {
        $atual = strtolower(trim((string) $email));
        if ($atual === '' || !filter_var($atual, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (isset($map[$atual])) {
            continue;
        }

        $map[$atual] = true;
        $saida[] = $atual;
    }

    return $saida;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    responder_solicitacao_cotacao_api_chat(405, [
        'ok' => false,
        'erro' => 'Use POST para abrir solicitação de cotação.',
        'codigoErro' => 'METODO_NAO_PERMITIDO',
    ]);
}

$input = api_chat_payload_json_or_error();
if (!is_array($input)) {
    $input = [];
}

$idempotencyKey = api_chat_middleware_idempotency_header_key();
$idempotencyState = api_chat_middleware_idempotency_iniciar(API_CHAT_SOLICITACAO_COTACAO_ENDPOINT, $idempotencyKey, $input);
if (($idempotencyState['status'] ?? '') === 'replay') {
    $resposta = is_array($idempotencyState['response']['body'] ?? null) ? $idempotencyState['response']['body'] : [];
    responder_solicitacao_cotacao_api_chat((int) ($idempotencyState['response']['status'] ?? 200), $resposta);
}
if (($idempotencyState['status'] ?? '') === 'conflict') {
    responder_solicitacao_cotacao_api_chat(409, ['ok' => false, 'erro' => 'Idempotency-Key já foi usado com payload diferente.', 'codigoErro' => 'IDEMPOTENCY_CONFLICT']);
}
if (($idempotencyState['status'] ?? '') === 'in_progress') {
    responder_solicitacao_cotacao_api_chat(409, ['ok' => false, 'erro' => 'Requisição idempotente ainda em processamento.', 'codigoErro' => 'IDEMPOTENCY_IN_PROGRESS']);
}

$nome = strtoupper(trim((string) ($input['nome'] ?? '')));
$empresaInformada = strtoupper(trim((string) ($input['empresa'] ?? '')));
$documento = api_chat_documento_somente_digitos((string) ($input['documento'] ?? $input['cpfCnpj'] ?? $input['cnpj'] ?? ''));
$emailsRaw = $input['email'] ?? $input['emails'] ?? '';
$emails = api_chat_normalizar_emails($emailsRaw);
$telefone = trim((string) ($input['telefoneCelular'] ?? $input['telefone'] ?? $input['celular'] ?? ''));
$quantidade = (int) ($input['quantidade'] ?? 0);
$observacoes = strtoupper(trim((string) ($input['observacoes'] ?? $input['observacao'] ?? '')));
$observacoes = api_chat_privacy_redact_text($observacoes);
$referencia = strtoupper(trim((string) ($input['referenciaProduto'] ?? $input['referencia'] ?? '')));
$codigo = strtoupper(trim((string) ($input['codigoProduto'] ?? $input['codigo'] ?? '')));
$link = trim((string) ($input['link'] ?? ''));
$origem = trim((string) ($input['origem'] ?? 'gpt_api_chat'));
$conversaChatGPT = api_chat_normalizar_conversa_chatgpt($input['conversaChatGPT'] ?? $input['conversa'] ?? $input['chatHistorico'] ?? '');
$conversaChatGPT = api_chat_privacy_redact_text($conversaChatGPT);

if ($nome === '' || $documento === '' || $emails === [] || $telefone === '' || $quantidade <= 0 || $conversaChatGPT === '') {
    responder_solicitacao_cotacao_api_chat(400, [
        'ok' => false,
        'erro' => 'Campos obrigatórios ausentes: nome completo, documento, email, telefone/celular com DDD, quantidade e conversaChatGPT.',
        'codigoErro' => 'DADOS_INCOMPLETOS',
    ]);
}

if (!api_chat_validar_nome_completo($nome)) {
    responder_solicitacao_cotacao_api_chat(400, [
        'ok' => false,
        'erro' => 'Informe o nome completo (nome e sobrenome).',
        'codigoErro' => 'NOME_COMPLETO_INVALIDO',
    ]);
}

if (!api_chat_documento_validar_generico($documento)) {
    responder_solicitacao_cotacao_api_chat(400, [
        'ok' => false,
        'erro' => 'Documento inválido. Envie CPF (11 dígitos) ou CNPJ (14 dígitos) válido.',
        'codigoErro' => 'DOCUMENTO_INVALIDO',
    ]);
}

if (!api_chat_validar_telefone_com_ddd($telefone)) {
    responder_solicitacao_cotacao_api_chat(400, [
        'ok' => false,
        'erro' => 'Telefone/Celular inválido. Informe número com DDD.',
        'codigoErro' => 'TELEFONE_INVALIDO',
    ]);
}

if (api_chat_strlen($observacoes) > 250) {
    responder_solicitacao_cotacao_api_chat(400, [
        'ok' => false,
        'erro' => 'Observações deve ter no máximo 250 caracteres.',
        'codigoErro' => 'OBSERVACAO_MAXIMO_EXCEDIDO',
    ]);
}

$empresa = $empresaInformada;
if (strlen($documento) === 14) {
    $empresaConsultada = trim((string) obter_nome_empresa($documento, $baseDir));
    if ($empresaConsultada !== '') {
        $empresa = strtoupper($empresaConsultada);
    }
}

if (!check_rate_limit('solicitar_cotacao_api_chat', 20, 3600, $emails[0])) {
    responder_solicitacao_cotacao_api_chat(429, [
        'ok' => false,
        'erro' => 'Limite de solicitações excedido para este contato. Tente novamente em 1 hora.',
        'codigoErro' => 'RATE_LIMIT_EXCEDIDO',
    ]);
}

try {
    registrar_verificacao_periodica_fila_jobs($baseDir);

    $agora = new DateTime('now');
    $created = $agora->format('Y-m-d H:i:s');
    $dataTitulo = $agora->format('d/m/Y H:i');
    $nomeEmpresaOuPessoa = strlen($documento) === 11 ? $nome : ($empresa !== '' ? $empresa : $nome);
    $titulo = "CHATGPT - Solicitação de Cotação Configurador - {$documento} - {$nomeEmpresaOuPessoa} - {$dataTitulo}";

    $linhasNota = [
        "Quantidade: {$quantidade}",
        "Nome: {$nome}",
        (strlen($documento) === 11 ? 'CPF' : 'CNPJ') . ": {$documento}",
        'Email: ' . strtoupper(implode(';', $emails)),
    ];

    if ($referencia !== '') {
        $linhasNota[] = "Referência: {$referencia}";
    }
    if ($codigo !== '') {
        $linhasNota[] = 'Código: ' . $codigo;
    }

    if (strlen($documento) === 14 && $empresa !== '') {
        $linhasNota[] = "Empresa: {$empresa}";
    }
    if ($telefone !== '') {
        $linhasNota[] = "Telefone: {$telefone}";
    }
    if ($observacoes !== '') {
        $linhasNota[] = "Observação: {$observacoes}";
    }

    $textoClassificacao = trim("cotacao orcamento preco {$observacoes} referencia {$referencia}");
    $classificacao = classificar_categoria_suporte($textoClassificacao, 'cobranca');
    $categoriaSugerida = $classificacao['categoria'];
    $filaSugerida = obter_fila_suporte($categoriaSugerida);
    $rotuloCategoria = obter_rotulo_categoria_suporte($categoriaSugerida);

    $linhasNota[] = "Origem: APIChat ({$origem})";

    $linhasNotaConversa = array_values(array_filter(array_map(
        static function (string $linha): string {
            return trim($linha);
        },
        preg_split('/\r\n|\r|\n/', $conversaChatGPT) ?: []
    ), static function (string $linha): bool {
        return $linha !== '';
    }));

    $notaConversa = array_merge(['Conversa extraída do ChatGPT:'], $linhasNotaConversa !== [] ? $linhasNotaConversa : [$conversaChatGPT]);

    $contatos = array_map(static function (string $email) use ($nome): array {
        return ['nome' => $nome, 'email' => $email];
    }, $emails);

    $historicoId = registrar_historico_solicitacao_suporte($baseDir, [
        'tipo' => 'cotacao_gpt_api_chat',
        'categoriaSugerida' => $categoriaSugerida,
        'filaSugerida' => $filaSugerida,
        'confianca' => $classificacao['confianca'],
        'solicitante' => [
            'nome' => $nome,
            'email' => $emails[0],
            'documento' => $documento,
        ],
        'referencia' => $referencia,
        'codigo' => $codigo,
        'observacao' => $observacoes,
        'origem' => 'APIChat/SolicitacaoCotacao.php',
    ]);

    $deal = aplicar_configuracao_fila_suporte([
        'pipeline_id' => 90783,
        'stage_id' => 574348,
        'title' => $titulo,
        'created_at' => $created,
        'tags' => [['id' => 359705], ['id' => 362614]],
        'company_cnpj' => $documento,
    ], $categoriaSugerida, $baseDir);

    $jobIdSolicitacao = registrar_job_fila('piperun_solicitacao', [
        'deal' => $deal,
        'linhasNota' => $linhasNota,
        'notasAdicionais' => [$notaConversa],
        'contatos' => $contatos,
        'historicos' => [[
            'tipo' => 'cotacao_gpt_api_chat',
            'host' => $_SERVER['HTTP_HOST'] ?? '',
            'origem' => $origem,
            'referencia' => $referencia,
            'codigo' => $codigo,
            'emails' => $emails,
            'documento' => $documento,
            'link' => $link,
            'quantidade' => $quantidade,
            'conversaChatGPT' => $conversaChatGPT,
        ]],
        'categoria_suporte' => [
            'sugerida' => $categoriaSugerida,
            'fila' => $filaSugerida,
            'confianca' => $classificacao['confianca'],
            'rotulo' => $rotuloCategoria,
        ],
        'historico_suporte_id' => $historicoId,
    ], $baseDir);

    responder_solicitacao_cotacao_api_chat(202, [
        'ok' => true,
        'solicitacaoId' => (string) $jobIdSolicitacao,
        'redactionsApplied' => true,
        'mensagem' => 'Solicitação recebida e enfileirada para criação da oportunidade comercial.',
        'oportunidade' => null,
        'protocolo' => null,
        'jobId' => $jobIdSolicitacao,
        'dadosRecebidos' => [
            'nome' => $nome,
            'documentoTipo' => strlen($documento) === 11 ? 'CPF' : 'CNPJ',
            'emailsQuantidade' => count($emails),
            'empresa' => $empresa,
            'telefoneInformado' => $telefone !== '',
            'quantidade' => $quantidade,
            'referencia' => $referencia,
            'codigo' => $codigo,
            'origem' => $origem,
            'conversaInformada' => $conversaChatGPT !== '',
            'statusSolicitacao' => 'enfileirada',
        ],
    ]);
} catch (Throwable $e) {
    api_chat_solicitacao_cotacao_log($baseDir, 'erro', [
        'codigoErro' => 'ERRO_INTERNO_SOLICITACAO_COTACAO',
        'mensagem' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
    ]);

    responder_solicitacao_cotacao_api_chat(500, [
        'ok' => false,
        'erro' => 'Erro interno ao enviar solicitação de cotação.',
        'codigoErro' => 'ERRO_INTERNO_SOLICITACAO_COTACAO',
    ]);
}
