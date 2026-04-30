<?php
function limpar_arquivos_expirados(string $dir, int $maxAge): void {
    $agora = time();
    foreach (glob($dir . '/*.json') as $arquivo) {
        $modificado = @filemtime($arquivo);
        if ($modificado !== false && ($agora - $modificado) > $maxAge) {
            @unlink($arquivo);
        }
    }
}

function check_rate_limit(string $context, int $maxAttempts = 5, int $window = 60, ?string $email = null): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = dirname(__DIR__) . '/Tokens/LimitesSolicitacoes';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
        if (!file_exists($dir . '/web.config')) {
            file_put_contents($dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>");
        }
    }

    limpar_arquivos_expirados($dir, max($window, 3600));

    $key = $context . '-' . $ip;
    if ($email !== null && $email !== '') {
        $key .= '-' . strtolower($email);
    }
    $hash = md5($key);

$file = $dir . '/' . $hash . '.json';
    $now = time();
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data) || !isset($data['start'])) {
            $data = ['count' => 0, 'start' => $now];
        } elseif ($now - (int) $data['start'] > $window) {
            $data = ['count' => 0, 'start' => $now];
        }
    } else {
        $data = ['count' => 0, 'start' => $now];
    }

    $data['count'] = (int) ($data['count'] ?? 0) + 1;

    file_put_contents($file, json_encode($data));
    chmod($file, 0600);
    if ($data['count'] > $maxAttempts) {
        if (function_exists('log_event')) {
            $emailInfo = $email ? " e email $email" : '';
        }
        return false;
    }
    return true;
}
?>