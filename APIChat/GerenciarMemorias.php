<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/NucleoApiChat.php';

function responder_memories(int $status, array $payload): void
{
    $idempotency = $GLOBALS['api_chat_memory_idempotency'] ?? null;
    if (is_array($idempotency)) {
        $scope = (string) ($idempotency['scope'] ?? '');
        $key = (string) ($idempotency['key'] ?? '');
        if ($scope !== '' && $key !== '') {
            api_chat_middleware_idempotency_finalizar($scope, $key, $status, $payload);
        }
    }
    api_chat_middleware_responder($status, $payload);
}

function memories_payload(): array
{
    return api_chat_payload_json_or_error();
}

$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

api_chat_middleware_init($baseDir, '/APIChat/GerenciarMemorias.php');
auth_api_chat_validar_ou_responder($baseDir, 'responder_memories');

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$payload = memories_payload();
if (is_array($payload['payload'] ?? null)) {
    $payloadInterno = $payload['payload'];
    if (!isset($payloadInterno['action']) && isset($payload['action']) && is_string($payload['action'])) {
        $payloadInterno['action'] = $payload['action'];
    }
    $payload = is_array($payloadInterno) ? $payloadInterno : $payload;
}
$state = api_chat_memory_carregar($baseDir);
api_chat_memory_limpar_expiradas($state);
$resultadoImportacaoBoot = api_chat_memory_importar_treinamento_unificado($state, $baseDir);
if ((int) ($resultadoImportacaoBoot['importados'] ?? 0) > 0) {
    api_chat_memory_salvar($baseDir, $state);
}

if ($metodo === 'GET') {
    $queryNormalizada = api_chat_memory_owner_payload_normalizado($_GET);

    $resultado = api_chat_memory_retrieve($state, $baseDir, array_merge($queryNormalizada, [
        'scope' => (string) ($_GET['scope'] ?? 'global'),
        'scopes' => isset($_GET['scopes']) ? explode(',', (string) $_GET['scopes']) : [],
        'namespace' => (string) ($_GET['namespace'] ?? ''),
        'query' => (string) ($_GET['query'] ?? ''),
        'limit' => (int) ($_GET['limit'] ?? 10),
        'cursor' => (string) ($_GET['cursor'] ?? '0'),
    ]));
    api_chat_registrar_evento_memory_retrieve($baseDir, [
        'tipo' => 'memory_retrieve',
        'timestamp' => gmdate('c'),
        'scope' => (string) ($resultado['scope'] ?? ''),
        'ownerKey' => (string) ($resultado['ownerKey'] ?? ''),
        'itensRetornados' => is_array($resultado['items'] ?? null) ? count($resultado['items']) : 0,
    ]);
    responder_memories(200, $resultado);
}

if ($metodo === 'POST') {
    $acao = strtolower(trim((string) ($payload['action'] ?? 'create')));
    if (in_array($acao, ['create', 'write_pipeline', 'import_training'], true)) {
        $idempotencyKey = api_chat_middleware_idempotency_header_key();
        $scope = '/APIChat/GerenciarMemorias.php:' . $acao;
        $estado = api_chat_middleware_idempotency_iniciar($scope, $idempotencyKey, $payload);
        if (($estado['status'] ?? '') === 'replay') {
            $body = is_array($estado['response']['body'] ?? null) ? $estado['response']['body'] : [];
            responder_memories((int) ($estado['response']['status'] ?? 200), $body);
        }
        if (($estado['status'] ?? '') === 'conflict') {
            responder_memories(409, ['ok' => false, 'erro' => 'Idempotency-Key já foi usado com payload diferente.', 'codigoErro' => 'IDEMPOTENCY_CONFLICT']);
        }
        if (($estado['status'] ?? '') === 'in_progress') {
            responder_memories(409, ['ok' => false, 'erro' => 'Requisição idempotente ainda em processamento.', 'codigoErro' => 'IDEMPOTENCY_IN_PROGRESS']);
        }
        $GLOBALS['api_chat_memory_idempotency'] = ['scope' => $scope, 'key' => $idempotencyKey];
    }
    $payload = api_chat_memory_owner_payload_normalizado($payload);

    if ($acao === 'privacy') {
        $scope = api_chat_memory_scope_persistencia($payload, 'global');
        $ownerKey = api_chat_memory_owner_key($baseDir, $scope, $payload);
        $optIn = (bool) ($payload['optIn'] ?? false);
        api_chat_memory_definir_privacy($state, $scope, $ownerKey, $optIn);
        api_chat_memory_salvar($baseDir, $state);

        responder_memories(200, ['ok' => true, 'scope' => $scope, 'ownerKey' => $ownerKey, 'optIn' => $optIn]);
    }

    if ($acao === 'write_pipeline' || $acao === 'create') {
        $resultado = api_chat_memory_upsert($state, $baseDir, $payload);
        $status = ($resultado['ok'] ?? false)
            ? 200
            : ((bool) ($resultado['requiresConfirmation'] ?? false) ? 409 : 422);

        if (($resultado['ok'] ?? false) === true) {
            api_chat_memory_salvar($baseDir, $state);
        }

        if (($resultado['ok'] ?? false) === true && !isset($resultado['memoriaId']) && isset($resultado['id'])) {
            $resultado['memoriaId'] = (string) $resultado['id'];
        }
        responder_memories($status, $resultado);
    }

    if ($acao === 'import_training') {
        $resultado = api_chat_memory_importar_treinamento_unificado($state, $baseDir, $payload);
        if ((int) ($resultado['importados'] ?? 0) > 0) {
            api_chat_memory_salvar($baseDir, $state);
        }

        responder_memories(200, $resultado);
    }

    responder_memories(400, [
        'ok' => false,
        'erro' => 'Ação POST inválida. Ações permitidas: privacy, create, write_pipeline, import_training.',
        'codigoErro' => 'ACAO_INVALIDA'
    ]);
}

if ($metodo === 'DELETE') {
    $queryNormalizada = api_chat_memory_owner_payload_normalizado($_GET);
    $id = trim((string) ($_GET['id'] ?? ''));
    $purge = (string) ($_GET['purge'] ?? '') === '1';
    $removidas = 0;

    if ($purge) {
        $scope = api_chat_memory_scope_persistencia($queryNormalizada + $_GET, 'global');
        $ownerKey = api_chat_memory_owner_key($baseDir, $scope, $queryNormalizada);
        $removidas = api_chat_memory_delete($state, '', $scope, $ownerKey);
        api_chat_registrar_evento_unificado($baseDir, [
            'tipo' => 'memory_delete',
            'timestamp' => gmdate('c'),
            'purge' => true,
            'scope' => $scope,
            'ownerKey' => $ownerKey,
            'removidas' => $removidas,
        ]);
    } else {
        if ($id === '') {
            responder_memories(400, ['ok' => false, 'erro' => 'Informe id ou purge=1.', 'codigoErro' => 'OPERACAO_INVALIDA']);
        }
        $removidas = api_chat_memory_delete($state, $id);

        if ($removidas < 1) {
            responder_memories(404, [
                'ok' => false,
                'erro' => 'Memória não encontrada para o id informado.',
                'codigoErro' => 'MEMORIA_NAO_ENCONTRADA',
                'id' => $id,
                'removidas' => 0,
            ]);
        }
        api_chat_registrar_evento_unificado($baseDir, [
            'tipo' => 'memory_delete',
            'timestamp' => gmdate('c'),
            'purge' => false,
            'id' => $id,
            'removidas' => $removidas,
        ]);
    }

    api_chat_memory_salvar($baseDir, $state);

    if ($purge) {
        $scope = api_chat_memory_scope_persistencia($queryNormalizada + $_GET, 'global');
        $ownerKey = api_chat_memory_owner_key($baseDir, $scope, $queryNormalizada);
        responder_memories(200, [
            'ok' => true,
            'purge' => true,
            'scope' => $scope,
            'ownerKey' => $ownerKey,
            'removidas' => $removidas,
        ]);
    }

    responder_memories(200, [
        'ok' => true,
        'id' => $id,
        'removidas' => $removidas,
    ]);
}

responder_memories(405, ['ok' => false, 'erro' => 'Método não suportado.', 'codigoErro' => 'METODO_NAO_SUPORTADO']);
