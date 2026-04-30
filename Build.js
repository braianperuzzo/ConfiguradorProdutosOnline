const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const browserslist = require('browserslist');
const browserslistToEsbuild = require('browserslist-to-esbuild').default;
const esbuild = require('esbuild');
const workboxBuild = require('workbox-build');

const ROOT_DIR = path.resolve(__dirname);

const STATIC_PRECACHE_ENTRIES = [
    { path: '/PaginaErros/PaginaErros.html' },
    { path: '/RedutoresIBR.html' },
    { path: '/PaginasPrincipal/PaginaProdutos.html' },
    { path: '/Manifest.json' },
    { path: '/Imagens/LogotipoAplicativo192192.png' },
    { path: '/Imagens/LogotipoAplicativo512512.png' },
    { path: '/Imagens/Logotipo.svg' },
    { path: '/Imagens/Icone.ico' },
    { path: '/Fontes/Sarabun-Bold.woff2' },
    { path: '/Fontes/Sarabun-Regular.woff2' },
    { path: '/Fontes/Sarabun-SemiBold.woff2' },
    { path: '/Fontes/Sora-Bold.woff2' }
];

const ESBUILD_TARGETS = browserslistToEsbuild(
    browserslist(undefined, { path: ROOT_DIR })
);

const JS_ENTRIES = [
    { input: 'PaginasNavegacaoCompartilhada/ConfiguracaoGuiada.js', output: 'PaginasNavegacaoCompartilhada/ConfiguracaoGuiada.min.js' },
    { input: 'Layout/LayoutAjudaSeletores.js', output: 'Layout/LayoutAjudaSeletores.min.js' },
    { input: 'Layout/Layout.js', output: 'Layout/Layout.min.js' },
    { input: 'PaginasCarregarPagina/CarregamentoInicial.js', output: 'PaginasCarregarPagina/CarregamentoInicial.min.js' },
    { input: 'Service-Worker.js', output: 'Service-Worker.min.js' },
    { input: 'PaginasCarregarPagina/CarregarPagina.js', output: 'PaginasCarregarPagina/CarregarPagina.min.js' },
    { input: 'Layout/Galeria.js', output: 'Layout/Galeria.min.js' },
    { input: 'Cookies/CarregarCookies.js', output: 'Cookies/CarregarCookies.min.js' },
    { input: 'Cookies/LayoutCookies.js', output: 'Cookies/LayoutCookies.min.js' },
    { input: 'Layout/FiltrarPorURL.js', output: 'Layout/FiltrarPorURL.min.js' },
    { input: 'SEO/TagsGoogle.js', output: 'SEO/TagsGoogle.min.js' },
    { input: 'Layout/TemasCor.js', output: 'Layout/TemasCor.min.js' },
    { input: 'Layout/AreaClienteLayout.js', output: 'Layout/AreaClienteLayout.min.js' },
    { input: 'LogsErros/MonitoramentoErros.js', output: 'LogsErros/MonitoramentoErros.min.js' },
    { input: 'PaginasPrincipal/PaginaProdutos.js', output: 'PaginasPrincipal/PaginaProdutos.min.js' },
    { input: 'Cookies/Cookies.js', output: 'Cookies/Cookies.min.js' },
    { input: 'AcessosConsultas/ValidacaoDocumento.js', output: 'AcessosConsultas/ValidacaoDocumento.min.js' }
];

const CSS_ENTRIES = [
    { input: 'Layout/Galeria.css', output: 'Layout/Galeria.min.css' },
    { input: 'Layout/LayoutAjudaSeletores.css', output: 'Layout/LayoutAjudaSeletores.min.css' },
    { input: 'Fontes/Fontes.css', output: 'Fontes/Fontes.min.css' },
    { input: 'Layout/PadraoLayout.css', output: 'Layout/PadraoLayout.min.css' },
    { input: 'Layout/LayoutAreaCliente.css', output: 'Layout/LayoutAreaCliente.min.css' },
    { input: 'Layout/LayoutCadastroAreaCliente.css', output: 'Layout/LayoutCadastroAreaCliente.min.css' },
    { input: 'Layout/TipografiaAreaCliente.css', output: 'Layout/TipografiaAreaCliente.min.css' }
];

const COMBINED_CSS = {
    inputs: ['Layout/PadraoLayout.css', 'Layout/Layout.css'],
    output: 'Layout/Layout.min.css'
};


function run(command) {
    execSync(command, { stdio: 'inherit', cwd: ROOT_DIR });
}

function isCommandAvailable(command) {
    const probe = process.platform === 'win32' ? `where ${command}` : `command -v ${command}`;
    try {
        execSync(probe, { stdio: 'ignore', cwd: ROOT_DIR });
        return true;
    } catch {
        return false;
    }
}

function runOptionalPython(script, args) {
    const commands = ['python3', 'python', 'py'];
    const availableCommand = commands.find((cmd) => isCommandAvailable(cmd));
    if (!availableCommand) {
        throw new Error('Nenhum interpretador Python disponível (python3, python ou py).');
    }
    const quotedArgs = args.map((arg) => `"${arg.replace(/"/g, '\\"')}"`).join(' ');
    execSync(`${availableCommand} "${script}" ${quotedArgs}`, { stdio: 'inherit', cwd: ROOT_DIR });
}


function runOptionalNodeScript(scriptPath, extraEnv) {
    const fullPath = resolvePath(scriptPath);
    execSync(`node "${fullPath}"`, {
        stdio: 'inherit',
        cwd: ROOT_DIR,
        env: { ...process.env, ...extraEnv }
    });
}

function resolvePath(relativePath) {
    return path.join(ROOT_DIR, relativePath);
}

function parseBuildOptions(argv) {
    const args = Array.isArray(argv) ? argv.slice(2) : [];
    return {
        skipAbandonmentTraining: args.includes('--skip-abandonment-training')
    };
}

async function minifyFile(inputPath, outputPath, loader) {
    const source = fs.readFileSync(resolvePath(inputPath), 'utf8');
    const result = await esbuild.transform(source, {
        loader,
        minify: true,
        target: ESBUILD_TARGETS,
        charset: 'utf8'
    });
    fs.writeFileSync(resolvePath(outputPath), result.code, 'utf8');
}

function getMinifiedOutputs() {
    const jsOutputs = JS_ENTRIES
        .map((entry) => entry.output)
        .filter((output) => output !== 'Service-Worker.min.js');
    const cssOutputs = CSS_ENTRIES.map((entry) => entry.output);
    return [
        ...jsOutputs,
        ...cssOutputs,
        COMBINED_CSS.output,
        'Layout/BibliotecasBootstrap.min.css'
    ];
}
function getPrecacheGlobPatterns() {
    const entries = [
        ...getMinifiedOutputs(),
        ...STATIC_PRECACHE_ENTRIES.map((entry) => entry.path)
    ];
    return entries.map((entry) => entry.replace(/^\//, ''));
}

async function buildAssets() {
    const serviceWorkerEntry = JS_ENTRIES.find((entry) => entry.input === 'Service-Worker.js');
    const jsEntries = JS_ENTRIES.filter((entry) => entry.input !== 'Service-Worker.js');

    for (const entry of jsEntries) {
        await minifyFile(entry.input, entry.output, 'js');
    }

    for (const entry of CSS_ENTRIES) {
        await minifyFile(entry.input, entry.output, 'css');
    }

    const combinedSource = COMBINED_CSS.inputs
        .map((input) => fs.readFileSync(resolvePath(input), 'utf8'))
        .join('\n');

    const combinedResult = await esbuild.transform(combinedSource, {
        loader: 'css',
        minify: true,
        target: ESBUILD_TARGETS,
        charset: 'utf8'
    });

    fs.writeFileSync(resolvePath(COMBINED_CSS.output), combinedResult.code, 'utf8');

    if (serviceWorkerEntry) {
        const swSrc = resolvePath(serviceWorkerEntry.input);
        const swTemp = resolvePath('Service-Worker.workbox.js');
        await workboxBuild.injectManifest({
            swSrc,
            swDest: swTemp,
            globDirectory: ROOT_DIR,
            globPatterns: getPrecacheGlobPatterns(),
            globIgnores: ['Service-Worker.min.js', 'Service-Worker.workbox.js', '**/*.map']
        });
        const workboxSource = fs.readFileSync(swTemp, 'utf8');
        const result = await esbuild.transform(workboxSource, {
            loader: 'js',
            minify: true,
            target: 'es2017',
            charset: 'utf8'
        });
        fs.writeFileSync(resolvePath(serviceWorkerEntry.output), result.code, 'utf8');
        fs.unlinkSync(swTemp);
    }
}

function validateOutputs() {
    const outputs = [
        ...JS_ENTRIES.map((entry) => entry.output),
        ...CSS_ENTRIES.map((entry) => entry.output),
        COMBINED_CSS.output,
        'Layout/BibliotecasBootstrap.min.css'
    ];

    const missing = outputs.filter((output) => !fs.existsSync(resolvePath(output)));

    if (missing.length > 0) {
        throw new Error(`Build outputs missing: ${missing.join(', ')}`);
    }
}

async function runBuild(options = {}) {
    run('npm run update-version');
    run('npm run generate-image-manifest');
    run('npm run generate-image-sitemap');
    run('npm run build-bootstrap');
    run('npm run postcss-styles');
    run('node SEO/GerarInformacoesProdutos.js');

    await buildAssets();

    const treinoScript = resolvePath('PaginasCarrinhoProdutosRecomenda/IATreinarModeloAbandono.py');
    const logsDir = resolvePath('PaginasCarrinhoProdutosRecomenda/Logs');
    const saidaModelo = resolvePath('PaginasCarrinhoProdutosRecomenda/IAModeloAbandono.json');
    if (options.skipAbandonmentTraining) {
        console.warn('[build] Treino de abandono ignorado por opção de linha de comando.');
    } else if (fs.existsSync(treinoScript) && fs.existsSync(logsDir)) {
        runOptionalPython(treinoScript, [
            '--logs-dir',
            logsDir,
            '--saida-modelo',
            saidaModelo,
            '--limpar-logs-dias',
            '30',
            '--permitir-sem-dados'
        ]);
    } else {
        console.warn('[build] Treino de abandono ignorado: logs ou script indisponíveis.');
    }

    run('npm run update-sitemap-lastmod');

    validateOutputs();
}

const buildOptions = parseBuildOptions(process.argv);

runBuild(buildOptions).catch((error) => {
    console.error('Build failed:', error);
    process.exit(1);
});
