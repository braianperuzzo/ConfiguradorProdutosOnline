#!/usr/bin/env php
<?php
/* Este script renova o segredo usado para assinar os JWTs.
Ao gerar um novo segredo, todos os tokens existentes se tornam inválidos,
forçando que os usuários façam login novamente.
# ConfiguradorOnline

Este projeto fornece o utilitário `Scripts/ForcarLogout.php` para invalidar todos os tokens de acesso. O script gera um novo segredo para assinar JWTs, forçando que todos os usuários façam login novamente.

## Forçar logout de todos os usuários

Execute o script a partir da raiz do projeto:

```
php Scripts/ForcarLogout.php
```

Se o comando `php` não for reconhecido (por exemplo, em ambientes Windows), utilize o caminho completo para o executável do PHP. Exemplo:

```
C:\\xampp\\php\\php.exe Scripts\\ForcarLogout.php
```

Certifique-se de ter permissões de escrita no arquivo `Configuracoes/Segredo.jwt`.
*/

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado no terminal." . PHP_EOL);
    exit(1);
}

$novoSegredo = bin2hex(random_bytes(32));
$baseDir = dirname(__DIR__);
$configDir = $baseDir . '/Configuracoes';

if (!is_dir($configDir) && !mkdir($configDir, 0700, true) && !is_dir($configDir)) {
    fwrite(STDERR, "Não foi possível criar o diretório de configurações." . PHP_EOL);
    exit(1);
}

$arquivo = $configDir . '/Segredo.jwt';

if (file_put_contents($arquivo, $novoSegredo . PHP_EOL) === false) {
    fwrite(STDERR, "Não foi possível gravar o novo segredo." . PHP_EOL);
    exit(1);
}

@chmod($arquivo, 0600);

echo "Segredo JWT renovado. Todos os usuários precisarão se autenticar novamente." . PHP_EOL;