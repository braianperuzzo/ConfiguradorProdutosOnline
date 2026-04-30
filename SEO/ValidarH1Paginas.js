const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const webConfigPath = path.join(root, 'web.config');

function read(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

function normalizeTarget(rawUrl) {
  const clean = rawUrl.split('?')[0].trim();
  if (!clean.endsWith('.html')) return null;
  return clean.replace(/^\/+/, '');
}

function getRouteTargetsFromWebConfig() {
  const xml = read(webConfigPath);
  const matches = [...xml.matchAll(/<action\s+type="Rewrite"\s+url="([^"]+)"/gi)];
  const targets = new Set();
  for (const match of matches) {
    const target = normalizeTarget(match[1]);
    if (!target) continue;
    if (!fs.existsSync(path.join(root, target))) continue;
    targets.add(target);
  }
  targets.add('RedutoresIBR.html');
  return [...targets].sort();
}

function countH1InBody(html) {
  const body = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
  const scope = body ? body[1] : html;
  const totalH1 = (scope.match(/<h1\b/gi) || []).length;
  const h1InsideMain = (() => {
    const mainBlocks = [...scope.matchAll(/<main\b[^>]*>([\s\S]*?)<\/main>/gi)];
    return mainBlocks.reduce((sum, block) => sum + ((block[1].match(/<h1\b/gi) || []).length), 0);
  })();
  return { totalH1, h1InsideMain, hasBody: Boolean(body) };
}

function extractLoadedHtmlTargets(html) {
  const targets = [];
  const matches = [...html.matchAll(/carregarPagina\(\s*['"]([^'"]+\.html)['"]/g)];
  for (const match of matches) {
    const normalized = match[1].replace(/^\/+/, '');
    const full = path.join(root, normalized);
    if (fs.existsSync(full)) targets.push(normalized);
  }
  return targets;
}

function validate() {
  const routeTargets = getRouteTargetsFromWebConfig();
  const errors = [];

  for (const routeTarget of routeTargets) {
    const routeHtml = read(path.join(root, routeTarget));
    const base = countH1InBody(routeHtml);

    if (base.totalH1 !== 1) {
      errors.push(`${routeTarget}: possui ${base.totalH1} <h1> no HTML base (esperado: 1).`);
    }

    const loadedTargets = extractLoadedHtmlTargets(routeHtml);
    for (const loadedTarget of loadedTargets) {
      const loadedHtml = read(path.join(root, loadedTarget));
      const loaded = countH1InBody(loadedHtml);
      const combinedH1 = base.totalH1 + loaded.totalH1;
      const hasRuntimeDedupe = routeHtml.includes('dedupeHomeH1') && routeHtml.includes('seo-h1-home');
      if (combinedH1 !== 1 && !hasRuntimeDedupe) {
        errors.push(`${routeTarget} + ${loadedTarget}: composição final com ${combinedH1} <h1> (esperado: 1).`);
      }
    }

    if (base.hasBody && base.h1InsideMain === 0) {
      errors.push(`${routeTarget}: o <h1> não está dentro de <main> no HTML base.`);
    }
  }

  if (errors.length) {
    console.error('Falha na validação de <h1>:\n- ' + errors.join('\n- '));
    process.exit(1);
  }

  console.log(`Validação de <h1> concluída com sucesso em ${routeTargets.length} rotas HTML.`);
}

validate();
