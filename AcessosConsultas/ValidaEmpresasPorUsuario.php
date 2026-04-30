<?php

declare(strict_types=1);

require_once __DIR__ . '/BuscarNomeEmpresaReceitaCache.php';

function obter_empresas_usuario(PDO $pdo, string $email, array $documentos, string $baseDir): array
{
  $emailLower = strtolower(trim($email));
    $documentosUnicos = [];
    $ordemDocumentos = [];

    $primeiroCpf = null;
    $temCnpj = false;

    foreach ($documentos as $documento) {
        $docLimpo = preg_replace('/[^0-9A-Za-z]/', '', (string)$documento);
        if ($docLimpo === '') {
            continue;
        }

        $ehCpf = strlen($docLimpo) === 11;
        $ehCnpj = strlen($docLimpo) === 14;

        if ($ehCpf) {
            if ($primeiroCpf === null) {
                $primeiroCpf = $docLimpo;
            } else {
                // Impede múltiplos cadastros vinculados a CPF.
                continue;
            }
        } elseif ($ehCnpj) {
            $temCnpj = true;
        } else {
            continue;
        }

        if (!isset($documentosUnicos[$docLimpo])) {
            $documentosUnicos[$docLimpo] = true;
            $ordemDocumentos[] = $docLimpo;
        }
    }

        if ($temCnpj && $primeiroCpf !== null && count($ordemDocumentos) > 1) {
        // Remove o CPF da lista quando houver CNPJ associado, evitando multiempresa para CPF.
        unset($documentosUnicos[$primeiroCpf]);
        $ordemDocumentos = array_values(array_filter(
            $ordemDocumentos,
            function (string $doc) use ($primeiroCpf): bool {
                return $doc !== $primeiroCpf;
            }
        ));
    }
    
    if (empty($ordemDocumentos)) {
        return ['lista' => [], 'porDocumento' => []];
    }

    $mapaPessoa = [];
    $placeholders = implode(',', array_fill(0, count($ordemDocumentos), '?'));
    if ($placeholders !== '') {
        $sqlPessoa = "SELECT CD_PESSOA, NR_CPFCNPJ, NM_PESSOA\n"
            . "FROM MBAD_PESSOA\n"
            . "WHERE REPLACE(REPLACE(REPLACE(NR_CPFCNPJ, '.', ''), '-', ''), '/', '') IN ($placeholders)";
        $stmtPessoa = $pdo->prepare($sqlPessoa);
        $stmtPessoa->execute($ordemDocumentos);
        while ($row = $stmtPessoa->fetch(PDO::FETCH_ASSOC)) {
            $docBanco = preg_replace('/[^0-9A-Za-z]/', '', (string)($row['NR_CPFCNPJ'] ?? ''));
            if ($docBanco === '') {
                continue;
            }
            $nomePessoa = trim((string)($row['NM_PESSOA'] ?? ''));
            if ($nomePessoa !== '' && function_exists('mb_strtoupper')) {
                $nomePessoa = mb_strtoupper($nomePessoa, 'UTF-8');
            }
            $mapaPessoa[$docBanco] = [
                'codigo' => (string)($row['CD_PESSOA'] ?? ''),
                'nome' => $nomePessoa,
            ];
        }
    }

    $stmtPermissao = $pdo->prepare(
        "SELECT MAX(TIPO.DS_TIPO) AS PERMISSAO\n"
        . "FROM MBAD_PESSOACONTATO AS CONTATO\n"
        . "INNER JOIN MBAD_PESSOACONTATOTIPO AS TIPO ON CONTATO.CD_TIPO = TIPO.CD_TIPO\n"
        . "WHERE CONTATO.CD_PESSOA = ? AND CONTATO.CD_FUNCAO = 'SITE' AND LOWER(CONTATO.DS_EMAIL) = ?"
    );

    $stmtNiveisPermissao = $pdo->prepare(
        "SELECT TOP 1 DEPARTAMENTO.DS_DEPARTAM AS NIVEIS_PERMISSOES\n"
        . "FROM MBAD_PESSOACONTATO AS CONTATO\n"
        . "INNER JOIN MBAD_DEPARTAM AS DEPARTAMENTO ON CONTATO.CD_DEPARTAMENTO = DEPARTAMENTO.CD_DEPARTAM\n"
        . "WHERE CONTATO.CD_PESSOA = ? AND CONTATO.CD_FUNCAO = 'SITE' AND LOWER(CONTATO.DS_EMAIL) = ?"
    );

    $lista = [];
    $porDocumento = [];

    foreach ($ordemDocumentos as $doc) {
        $codigo = $mapaPessoa[$doc]['codigo'] ?? '';
        $nome = $mapaPessoa[$doc]['nome'] ?? '';

        if ($nome === '' && strlen($doc) === 14) {
            $nome = obter_nome_empresa($doc, $baseDir);
        }

        if ($nome === '') {
            $nome = $doc;
        }

        $papel = 'BRONZE';
        $niveisPermissoes = '';
        if ($codigo !== '') {
            $stmtPermissao->execute([$codigo, $emailLower]);
            $permissao = $stmtPermissao->fetchColumn();
            $papel = $permissao ? $permissao : 'PRATA';

            $stmtNiveisPermissao->execute([$codigo, $emailLower]);
            $niveisPermissoes = trim((string)$stmtNiveisPermissao->fetchColumn());
        }

        $empresa = [
            'documento' => $doc,
            'nome' => $nome,
            'papel' => $papel,
            'niveisPermissoes' => $niveisPermissoes,
            'codigo' => $codigo,
        ];
        $lista[] = $empresa;
        $porDocumento[$doc] = $empresa;
    }

    return ['lista' => $lista, 'porDocumento' => $porDocumento];
}
