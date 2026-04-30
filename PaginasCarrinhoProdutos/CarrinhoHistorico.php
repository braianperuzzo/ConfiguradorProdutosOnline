<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}
$galeriaHelperFile = $baseDir . '/PaginasCarrinhoProdutos/ImagensProdutosHelper.php';
if (is_file($galeriaHelperFile)) {
    require_once $galeriaHelperFile;
}
$seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
$cartDir = $baseDir . '/Tokens/HistoricoCarrinhos';
if (!is_dir($cartDir)) {
    mkdir($cartDir, 0775, true);
}
$identifier = '';
$guestId = preg_replace('/[^a-zA-Z0-9_@.\-]/', '', $_COOKIE['cart_id'] ?? '');
$token = $_COOKIE['auth_token'] ?? '';
if ($token) {
    require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
    require_once $baseDir . '/TokensGeradores/TokenInvalidacao.php';
    $segredo = getenv('JWT_SECRET');
    if ($segredo === false || $segredo === '') {
        $segredoFile = $baseDir . '/Configuracoes/Segredo.jwt';
        if (is_file($segredoFile)) {
            $segredo = trim((string) file_get_contents($segredoFile));
        }
    }
    $dados = null;
    if ($segredo) {
        $dados = JWTHelper::decode($token, $segredo);
    }
    if ($dados && !is_token_blacklisted($token) && (!isset($dados['maxExp']) || time() < $dados['maxExp'])) {
        $identifier = preg_replace('/[^a-zA-Z0-9_@.\-]/', '', $dados['email'] ?? '');
    }
    if ($identifier && $guestId && $guestId !== $identifier) {
        $userFile = $cartDir . '/' . $identifier . '.json';
        $guestFile = $cartDir . '/' . $guestId . '.json';
  if (file_exists($guestFile)) {
            if (file_exists($userFile)) {
                $suffix = date('YmdHis');
                try {
                    $suffix .= '_' . bin2hex(random_bytes(4));
                } catch (Exception $e) {
                    $suffix .= '_' . uniqid();
                }
                $backupFile = $cartDir . '/' . $identifier . '_' . $suffix . '.json';
                if (!@copy($userFile, $backupFile)) {
                    $conteudoOriginal = @file_get_contents($userFile);
                    if ($conteudoOriginal !== false) {
                        @file_put_contents($backupFile, $conteudoOriginal);
                    }
                }
            }
            if (!@copy($guestFile, $userFile)) {
                $conteudoGuest = @file_get_contents($guestFile);
                if ($conteudoGuest !== false) {
                    file_put_contents($userFile, $conteudoGuest);
                }
            }
        }
               setcookie('cart_id', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => $seguro ? 'None' : 'Lax',
        ]);
     }
}
if (!$identifier) {
    $identifier = $guestId;
    if (!$identifier) {
        $identifier = bin2hex(random_bytes(16));
           setcookie('cart_id', $identifier, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => $seguro ? 'None' : 'Lax',
        ]);
    }
}
$file = $cartDir . '/' . $identifier . '.json';
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $items = $input['items'] ?? [];
        if (!is_array($items) || !$items) {
        if (file_exists($file)) {
            @unlink($file);
        }
        echo json_encode(['sucesso' => true, 'limpo' => true]);
        exit;
    }
    file_put_contents($file, json_encode($items));
    echo json_encode(['sucesso' => true]);
    exit;
}
if (file_exists($file)) {
    $items = json_decode(file_get_contents($file), true);
    if (!$items) $items = [];
    if (function_exists('enriquecer_itens_com_galeria')) {
        $items = enriquecer_itens_com_galeria($items, $baseDir);
    }
    echo json_encode($items);
} else {
    echo json_encode([]);
}
?>
