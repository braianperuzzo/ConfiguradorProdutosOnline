<?php
ini_set('display_errors', 0);
error_reporting(0);
$baseDir = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($baseDir === '') {
    $baseDir = dirname(__DIR__);
}

require_once $baseDir . '/Seguranca/MetodosSegurancao.php';
require_once $baseDir . '/LogsErros/Logs.php';
require_once $baseDir . '/TokensGeradores/TokenPadraoJson.php';
require_once $baseDir . '/ProcessamentoFilaJobs/CriaFilaProcessamento.php';
registrar_verificacao_periodica_fila_jobs($baseDir);
require_once $baseDir . '/AcessosConsultas/CredenciaisPiperun.php';
require_once $baseDir . '/PaginasAreaClienteAcessoCadastro/CadastroPlenusAPI.php';


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

$montarSaudacao = function (?string $nome, ?string $documento, ?string $empresa) use ($formatarDocumento): string {
    $componentes = [];
    $nome = trim((string) $nome);
    if ($nome !== '') {
        $componentes[] = $nome;
    }

    $documentoLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string) $documento);
    if (strlen($documentoLimpo) === 14) {
        $componentes[] = $formatarDocumento($documentoLimpo);
        $empresa = trim((string) $empresa);
        if ($empresa !== '') {
            $componentes[] = $empresa;
        }
    }

    if (empty($componentes) && $nome !== '') {
        $componentes[] = $nome;
    }

    return implode(' - ', $componentes);
};

$token = trim(filter_input(INPUT_GET, 'token', FILTER_UNSAFE_RAW) ?? '');
$token = preg_replace('/[^A-Fa-f0-9]/', '', $token);
if (strlen($token) !== 64 || !preg_match('/^[A-Fa-f0-9]{64}$/', $token)) {
     header('Location: /AreaCliente?cadastro=token_invalido');
    exit;
}

$arquivo = $baseDir . '/Tokens/TokensCadastro/' . $token . '.json';
if (!file_exists($arquivo)) {
    header('Location: /AreaCliente?cadastro=token_invalido');
    exit;
}

$dados = json_decode(file_get_contents($arquivo), true);
$status = $dados['status'] ?? 'pendente';
$confirmado = $status === 'confirmado';
$codigoEnviadoEm = (int)($dados['codigoEnviadoEm'] ?? 0);
$criado = $dados['criadoEm'] ?? 0;
if (time() - $criado > 24*60*60) {
    unlink($arquivo);
    header('Location: /AreaCliente?cadastro=token_expirado');
    exit;
}

if ($confirmado) {
    header('Location: /AreaCliente?cadastro=ja_realizado');
    exit;
}

if ($status === 'codigo_enviado' && $codigoEnviadoEm > 0 && (time() - $codigoEnviadoEm) < 300) {
    header('Location: /AreaCliente?validacao=1&email=' . urlencode($dados['email'] ?? ''));
    exit;
}

$nome = mb_strtoupper($dados['nome'] ?? '', 'UTF-8');
$email = strtolower(trim($dados['email'] ?? ''));
$cpfcnpj = preg_replace('/[^0-9A-Za-z]/', '', $dados['cpfcnpj'] ?? '');
$hashSenha = $dados['senha'] ?? '';
$novidades = strtoupper(trim((string) ($dados['novidades'] ?? 'NAO')));
if ($novidades !== 'SIM') {
    $novidades = 'NAO';
}
$dtSenha = $dados['dtSenha'] ?? time();
require_once $baseDir . '/AcessosConsultas/BuscarNomeEmpresaReceitaCache.php';
$empresa = obter_nome_empresa($cpfcnpj, $baseDir);
$empresa = trim((string) $empresa);

require $baseDir . '/AcessosConsultas/CredenciaisBancoDados.php';

try {
    $pdo = new PDO("sqlsrv:server=$dbhost;Database=$db", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);

      if (!$confirmado && strlen($cpfcnpj) === 11) {
        $stmtEmail = $pdo->prepare("SELECT 1 FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ?");
        $stmtEmail->execute([strtolower($email)]);
        if ($stmtEmail->fetch()) {
            echo json_encode([
                'sucesso' => false,
                'mensagem' => '⚠️ Identificamos um cadastro ativo para este e-mail. Por favor, efetue o login ou utilize a opção de recuperação de senha.'
            ]);
            exit;
        }
    }
    
    $stmt = $pdo->prepare("SELECT 1 FROM _USR_CONF_SITE_CADASTROS WHERE LOWER(DS_EMAIL) = ? AND NR_CPFCNPJ = ?");
    $stmt->execute([$email, $cpfcnpj]);
    if ($stmt->fetch()) {
        $sql = "UPDATE _USR_CONF_SITE_CADASTROS SET DS_NOME = ?, DS_SENHA = ?, NR_CPFCNPJ = ?, DS_NOVIDADES = ? WHERE LOWER(DS_EMAIL) = ? AND NR_CPFCNPJ = ?";
        $upd = $pdo->prepare($sql);
        $upd->execute([$nome, $hashSenha, $cpfcnpj, $novidades, $email, $cpfcnpj]);
    } else {
        $sql = "INSERT INTO _USR_CONF_SITE_CADASTROS (DS_NOME, DS_EMAIL, NR_CPFCNPJ, DS_SENHA, DS_NOVIDADES) VALUES (?, ?, ?, ?, ?)";
        $ins = $pdo->prepare($sql);
        $ins->execute([$nome, $email, $cpfcnpj, $hashSenha, $novidades]);
    }

    $cpfcnpjNumeros = preg_replace('/[^0-9A-Za-z]/', '', $cpfcnpj);
    $codigo = null;
    $grupo = 'BRONZE';
    $outrosContatos = [];

    try {
        $codStmt = $pdo->prepare(
                       "SELECT TOP 1 CD_PESSOA\n"
            . "  FROM MBAD_PESSOA\n"
            . " WHERE REPLACE(REPLACE(REPLACE(REPLACE(NR_CPFCNPJ, '.', ''), '-', ''), '/', ''), ' ', '') = ?"
        );
        $codStmt->execute([$cpfcnpjNumeros]);
        $codigoRetornado = $codStmt->fetchColumn();
        if ($codigoRetornado !== false && $codigoRetornado !== null) {
            $codigo = trim((string) $codigoRetornado);
        }

        if ($codigo !== null && $codigo !== '') {
            $permStmt = $pdo->prepare(
                "SELECT MAX(TIPO.DS_TIPO) AS PERMISSAO\n" .
                "FROM MBAD_PESSOACONTATO AS CONTATO\n" .
                "INNER JOIN MBAD_PESSOACONTATOTIPO AS TIPO ON CONTATO.CD_TIPO = TIPO.CD_TIPO\n" .
                "WHERE CONTATO.CD_PESSOA = ? AND CONTATO.CD_FUNCAO = 'SITE' AND LOWER(CONTATO.DS_EMAIL) = ?"
            );
            $permStmt->execute([$codigo, strtolower(trim($email))]);
            $permissao = $permStmt->fetchColumn();
            $grupo = $permissao ? $permissao : 'PRATA';

            if (strlen($cpfcnpjNumeros) === 14) {
                $temColunaDsNome = false;
                try {
                    $colunaStmt = $pdo->query("SELECT COL_LENGTH('MBAD_PESSOACONTATO', 'DS_NOME')");
                    $temColunaDsNome = (int) $colunaStmt->fetchColumn() > 0;
                } catch (PDOException $e) {
                }

                $selectNome = $temColunaDsNome
                    ? "       LTRIM(RTRIM(COALESCE(CONTATO.DS_NOME, ''))) AS DS_NOME,\n"
                    : "       '' AS DS_NOME,\n";

                $sqlContatos =
                    "SELECT DISTINCT\n" .
                    $selectNome .
                    "       LOWER(LTRIM(RTRIM(COALESCE(CONTATO.DS_EMAIL, '')))) AS DS_EMAIL\n" .
                    "  FROM MBAD_PESSOACONTATO AS CONTATO\n" .
                    " WHERE CONTATO.CD_PESSOA = ?\n" .
                    "   AND CONTATO.DS_EMAIL IS NOT NULL\n" .
                    "   AND LTRIM(RTRIM(CONTATO.DS_EMAIL)) <> ''";

                $contatosStmt = $pdo->prepare($sqlContatos);
                $contatosStmt->execute([$codigo]);
                while ($linhaContato = $contatosStmt->fetch(PDO::FETCH_ASSOC)) {
                    $emailContato = strtolower(trim($linhaContato['DS_EMAIL'] ?? ''));
                    if (!$emailContato || $emailContato === strtolower(trim($email))) {
                        continue;
                    }
                    $nomeContato = trim($linhaContato['DS_NOME'] ?? '');
                    if ($nomeContato !== '') {
                        $nomeContato = mb_strtoupper($nomeContato, 'UTF-8');
                    } else {
                        $nomeContato = 'Não informado';
                    }
                    $outrosContatos[$emailContato] = [
                        'nome'  => $nomeContato,
                        'email' => $emailContato
                    ];
                }
            }
        } else {
            $codigo = null;
        }
    } catch (PDOException $e) {
    }

    $dados['status'] = 'codigo_enviado';
    $dados['codigoEnviadoEm'] = time();
    file_put_contents($arquivo, json_encode($dados));
    chmod($arquivo, 0600);
    require_once $baseDir . '/TokensGeradores/TokensDataSenha.php';
    set_password_timestamp($email, $dtSenha, $nome);

    $linhasExtrasPadrao = [];
    if ($codigo !== null && $codigo !== '') {
        $linhasExtrasPadrao[] = 'Observação: cadastro já existente no Plenus. Código: ' . $codigo;
    }

    if (strlen($cpfcnpjNumeros) === 14) {
        $dadosPlenus = [
            'cnpj' => $cpfcnpjNumeros,
            'nome' => $nome,
            'email' => $email,
        ];
        registrar_job_fila('plenus_sincronizar_contato', $dadosPlenus, $baseDir);
    }

    $dadosPipeRun = [
        'tituloPrefixo' => 'Realização de Novo Cadastro no Configurador',
        'pipelineId'    => 78334,
        'stageId'       => 483644,
        'linhasExtras'  => $linhasExtrasPadrao,
        'nome'          => $nome,
        'cpfcnpj'       => $cpfcnpj,
        'empresa'       => $empresa,
        'email'         => $email,
        'outrosContatos' => $outrosContatos,
    ];

    registrar_job_fila('piperun_criar_oportunidade', $dadosPipeRun, $baseDir);

$segredo = getenv('JWT_SECRET');
    if ($segredo === false) {
        $segredo = trim(file_get_contents($baseDir . '/Configuracoes/Segredo.jwt'));
    }

    $ttl = getenv('JWT_TTL');
    if ($ttl === false) {
        $ttl = 604800; // 7 dias por inatividade
    } else {
        $ttl = (int)$ttl;
    }
    if ($ttl <= 0) {
        $ttl = 604800;
    }
    $maxTime = 2592000; // 30 dias
    $ttl = min($ttl, $maxTime);
    $agora = time();

    $payload = [
        'email' => strtolower($email),
        'grupo' => $grupo,
        'nome' => $nome,
        'cpfcnpj' => $cpfcnpj,
        'empresa' => $empresa,
        'codigo' => $codigo,
        'exp' => $agora + $ttl,
        'maxExp' => $agora + $maxTime
    ];

    $deviceHash = hash('sha256', strtolower($email) . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $dirDevices = $baseDir . '/Tokens/TokensDispositivos';
    if (!is_dir($dirDevices)) {
        mkdir($dirDevices, 0700, true);
        file_put_contents(
            $dirDevices . '/web.config',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>" .
            "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>"
        );
    }

       $deviceFile = $dirDevices . '/' . $deviceHash . '.json';
    $codigoLogin = random_int(100000, 999999);

    require $baseDir . '/PHPMailer/src/PHPMailer.php';
    require $baseDir . '/PHPMailer/src/SMTP.php';
    require $baseDir . '/PHPMailer/src/Exception.php';
    require $baseDir . '/AcessosConsultas/CredenciaisAcessoEmail.php';

    $template = file_get_contents(__DIR__ . '/EmailCodigoLogin.html');
    $cpfcnpjLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string) $cpfcnpj);
    $saudacaoConteudo = $montarSaudacao($nome, $cpfcnpjLimpo, $empresa);
    $saudacaoHtml = htmlspecialchars($saudacaoConteudo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = str_replace(
        ['SAUDACAO_USUARIO', 'CODIGO_LOGIN'],
        [$saudacaoHtml, $codigoLogin],
        $template
    );
    
    try {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = $smtpSecure;
        $mail->Port = $smtpPort;
        $mail->setFrom($smtpUser, 'Não Responder - Comunicação Redutores IBR');
        $mail->addAddress($email);
        $mail->addEmbeddedImage($baseDir . '/Imagens/Logotipo.png', 'logo_ibr');
        $mail->Subject = 'Código de Acesso - Configurador de Produtos IBR';
        $mail->isHTML(true);
        $mail->Body = $html;
        $saudacaoTexto = 'Olá, ' . ($saudacaoConteudo !== '' ? $saudacaoConteudo : $nome) . ',';
        $mail->AltBody = $saudacaoTexto . "\n\nSeu código de acesso é: $codigoLogin";
        
        if (!$mail->send()) {
            throw new Exception($mail->ErrorInfo);
        }
    } catch (Exception $e) {
        header('Location: /AreaCliente?cadastro=erro');
        exit;
    }

    $info = [
        'code' => password_hash($codigoLogin, PASSWORD_DEFAULT),
        'payload' => $payload,
        'ttl' => $ttl,
        'generatedAt' => time(),
        'email' => strtolower($email),
        'nome' => $nome,
        'criadoEm' => time(),
        'dispositivo' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    file_put_contents($deviceFile, json_encode($info));
    chmod($deviceFile, 0600);

    try {
        disparar_sincronizacao_cliente_background();
    } catch (Throwable $t) {
        log_event('ConfirmacaoCadastro - falha ao disparar sincronizacao do cliente: ' . $t->getMessage());
    }

    header('Location: /AreaCliente?validacao=1&email=' . urlencode($email));
    exit;
} catch (PDOException $e) {
    header('Location: /AreaCliente?cadastro=erro');
    exit;
}
?>
