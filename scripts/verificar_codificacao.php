<?php
declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Nao foi possivel localizar a pasta dev2.1.\n");
    exit(1);
}

$extensoes = ['php', 'css', 'js', 'html', 'md', 'sql', 'json', 'yml', 'yaml', 'sh'];
$ignorarDiretorios = [
    DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR,
];

$padroesMojibake = [
    'Ã¡', 'Ã¢', 'Ã£', 'Ã©', 'Ãª', 'Ã­', 'Ã³', 'Ã´', 'Ãµ', 'Ãº', 'Ã§',
    'Ã', 'Ã‰', 'Ã“', 'Ãš', 'Ã‡', 'Â', 'â€', 'â€™', 'â€œ', 'â€', 'â€“', 'â€”', 'â€¢', 'âœ',
];

$problemas = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $arquivo) {
    if (!$arquivo instanceof SplFileInfo || !$arquivo->isFile()) {
        continue;
    }

    $caminho = $arquivo->getPathname();
    $caminhoNormalizado = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $caminho);

    if ($caminhoNormalizado === str_replace(['/', '\\'], DIRECTORY_SEPARATOR, __FILE__)) {
        continue;
    }

    foreach ($ignorarDiretorios as $diretorio) {
        if (strpos($caminhoNormalizado, $diretorio) !== false) {
            continue 2;
        }
    }

    $extensao = strtolower($arquivo->getExtension());
    if (!in_array($extensao, $extensoes, true)) {
        continue;
    }

    $conteudo = file_get_contents($caminho);
    if ($conteudo === false) {
        $problemas[] = [$caminho, 'nao foi possivel ler o arquivo'];
        continue;
    }

    if (@preg_match('//u', $conteudo) !== 1) {
        $problemas[] = [$caminho, 'arquivo nao esta em UTF-8 valido'];
        continue;
    }

    foreach ($padroesMojibake as $padrao) {
        if (strpos($conteudo, $padrao) !== false) {
            $problemas[] = [$caminho, 'possivel texto com codificacao quebrada: ' . $padrao];
            break;
        }
    }
}

if ($problemas === []) {
    echo "OK: arquivos texto em UTF-8 e sem padroes comuns de mojibake.\n";
    exit(0);
}

echo "Foram encontrados possiveis problemas de codificacao:\n";
foreach ($problemas as [$caminho, $mensagem]) {
    $relativo = ltrim(str_replace($baseDir, '', $caminho), DIRECTORY_SEPARATOR);
    echo "- {$relativo}: {$mensagem}\n";
}

exit(1);
