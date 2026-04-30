const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO_ROOT = path.resolve(__dirname, '..');
const MIN_WORDS_DEFAULT = 150;
const MIN_WORDS_SHORT = 80;

const routeToFile = {
  '/': 'RedutoresIBR.html',
  '/PoliticaPrivacidade': 'PaginasPoliticaPrivacidade/PoliticaPrivacidade.html',
  '/Intercambialidade': 'PaginasConcorrentes/ConfiguradorConcorrentes.html',
  '/ConfiguradorIBRQ': 'PaginasConfiguradores/ConfiguradorQU.html',
  '/ConfiguradorIBRQDR': 'PaginasConfiguradores/ConfiguradorQUDR.html',
  '/ConfiguradorIBRQP': 'PaginasConfiguradores/ConfiguradorQUDR.html',
  '/ConfiguradorIBRC': 'PaginasConfiguradores/ConfiguradorHY.html',
  '/ConfiguradorIBRH': 'PaginasConfiguradores/ConfiguradorHY.html',
  '/ConfiguradorIBRM': 'PaginasConfiguradores/ConfiguradorHY.html',
  '/ConfiguradorIBRP': 'PaginasConfiguradores/ConfiguradorHY.html',
  '/ConfiguradorIBRR': 'PaginasConfiguradores/ConfiguradorHY.html',
  '/ConfiguradorIBRX': 'PaginasConfiguradores/ConfiguradorHY.html',
  '/ConfiguradorIBRFFA': 'PaginasConfiguradores/ConfiguradorFX.html',
  '/ConfiguradorIBRFKA': 'PaginasConfiguradores/ConfiguradorFX.html',
  '/ConfiguradorIBRFR': 'PaginasConfiguradores/ConfiguradorFX.html',
  '/ConfiguradorIBRMSI': 'PaginasConfiguradores/ConfiguradorMO.html',
  '/ConfiguradorIBRMI': 'PaginasConfiguradores/ConfiguradorMO.html',
  '/ConfiguradorIBRMW': 'PaginasConfiguradores/ConfiguradorMO.html',
  '/ConfiguradorIBRMAPM': 'PaginasConfiguradores/ConfiguradorMO.html',
  '/ConfiguradorIBRMSPM': 'PaginasConfiguradores/ConfiguradorMO.html',
  '/ConfiguradorIBRPB': 'PaginasConfiguradores/ConfiguradorPL.html',
  '/ConfiguradorIBRPBL': 'PaginasConfiguradores/ConfiguradorPL.html',
  '/ConfiguradorIBRSA': 'PaginasConfiguradores/ConfiguradorPL.html',
  '/ConfiguradorIBRSB': 'PaginasConfiguradores/ConfiguradorPL.html',
  '/ConfiguradorIBRSBL': 'PaginasConfiguradores/ConfiguradorPL.html',
  '/ConfiguradorIBRSD': 'PaginasConfiguradores/ConfiguradorPL.html',
  '/ConfiguradorIBRV': 'PaginasConfiguradores/ConfiguradorVA.html',
  '/ConfiguradorIBRZ': 'PaginasConfiguradores/ConfiguradorAC.html',
  '/ConfiguradorIBRVFN': 'PaginasConfiguradores/ConfiguradorAC.html',
  '/ConfiguradorIBRGR': 'PaginasConfiguradores/ConfiguradorAE.html',
  '/ConfiguradorIBRGS': 'PaginasConfiguradores/ConfiguradorAE.html',
  '/ConfiguradorIBRRIC': 'PaginasConfiguradores/ConfiguradorAE.html',
  '/ConfiguradorIBRK': 'PaginasConfiguradores/ConfiguradorIN.html',

  '/ConfiguradorANTICORROSIVOSAPM': 'PaginasConfiguradores/ConfiguradorMO.html',
  '/ConfiguradorANTICORROSIVOSSPM': 'PaginasConfiguradores/ConfiguradorMO.html',
  '/ConfiguradorIBRCFR': 'PaginasConfiguradores/ConfiguradorFX.html',
  '/ConfiguradorIBRMSML': 'PaginasConfiguradores/ConfiguradorMO.html',
  '/ConfiguradorIBRPFFA': 'PaginasConfiguradores/ConfiguradorFX.html',
  '/ConfiguradorIBRT3AT3C': 'PaginasConfiguradores/ConfiguradorMO.html',
  '/ConfiguradorIBRXFKA': 'PaginasConfiguradores/ConfiguradorFX.html',
  '/ConfiguradorWEGALTORENDIMENTO': 'PaginasConfiguradores/ConfiguradorMO.html',
};

const shortRoutes = new Set(['/PoliticaPrivacidade']);

function getSitemapRoutes() {
  const xml = fs.readFileSync(path.join(REPO_ROOT, 'sitemap.xml'), 'utf8');
  const matches = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)];
  return matches
    .map((m) => {
      const url = new URL(m[1]);
      return `${url.pathname}${url.search}`;
    })
    .sort((a, b) => a.localeCompare(b));
}

function renderPhpFile(filePath, route) {
  const [pathname, query = ''] = route.split('?');
  const absoluteFile = path.join(REPO_ROOT, filePath);
  const script = `if (!function_exists('csp_nonce')) { function csp_nonce() { return 'audit-nonce'; } }
$_SERVER['HTTP_HOST'] = 'configurador.redutoresibr.com.br';
$_SERVER['REQUEST_URI'] = ${JSON.stringify(pathname + (query ? '?' + query : ''))};
$_SERVER['QUERY_STRING'] = ${JSON.stringify(query)};
parse_str($_SERVER['QUERY_STRING'], $_GET);
ob_start();
include ${JSON.stringify(absoluteFile)};
echo ob_get_clean();`;

  return execFileSync('php', ['-r', script], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    maxBuffer: 1024 * 1024 * 10
  });
}

function countIndexableWords(html) {
  let cleaned = html
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<noscript[\s\S]*?<\/noscript>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&[a-zA-Z0-9#]+;/g, ' ');

  const words = cleaned.match(/[\p{L}\p{N}][\p{L}\p{N}\-]*/gu) || [];
  return words.length;
}

function resolveRouteFile(route) {
  const [pathname] = route.split('?');
  return routeToFile[pathname] || null;
}

function buildRouteSet() {
  const fromSitemap = getSitemapRoutes();
  const extras = ['/Intercambialidade'];
  const all = new Set([...fromSitemap, ...extras]);
  return [...all].sort((a, b) => a.localeCompare(b));
}

function run() {
  const routes = buildRouteSet();
  const results = [];

  for (const route of routes) {
    const file = resolveRouteFile(route);
    if (!file) {
      results.push({ route, file: '-', words: 0, min: MIN_WORDS_DEFAULT, status: 'SEM_MAPEAMENTO' });
      continue;
    }

    const html = renderPhpFile(file, route);
    const words = countIndexableWords(html);
    const [pathname] = route.split('?');
    const min = shortRoutes.has(pathname) ? MIN_WORDS_SHORT : MIN_WORDS_DEFAULT;
    const status = words >= min ? 'OK' : 'BAIXO';
    results.push({ route, file, words, min, status });
  }

  const lines = [];
  lines.push('ROTA | ARQUIVO | PALAVRAS | MÍNIMO | STATUS');
  lines.push('--- | --- | ---: | ---: | ---');
  for (const row of results) {
    lines.push(`${row.route} | ${row.file} | ${row.words} | ${row.min} | ${row.status}`);
  }

  const low = results.filter((row) => row.status !== 'OK');
  lines.push('');
  lines.push(`Resumo: ${results.length} rotas avaliadas, ${low.length} com conteúdo insuficiente.`);

  const output = lines.join('\n') + '\n';
  const reportPath = path.join(REPO_ROOT, 'SEO', 'RelatorioConteudoSeo.md');
  fs.writeFileSync(reportPath, output, 'utf8');
  console.log(output);

  if (low.length > 0) {
    process.exitCode = 1;
  }
}

run();
