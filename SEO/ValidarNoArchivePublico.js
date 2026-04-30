#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const PROD_BASE_URL = 'https://configurador.redutoresibr.com.br';
const SITEMAP_URL = `${PROD_BASE_URL}/sitemap.xml`;

function extractLocs(xml) {
  return [...xml.matchAll(/<loc>([^<]+)<\/loc>/gi)].map((m) => m[1].trim());
}

function extractMetaContent(html, name) {
  const pattern = new RegExp(`<meta[^>]*name=["']${name}["'][^>]*content=["']([^"']+)["'][^>]*>`, 'i');
  const match = html.match(pattern);
  return match ? match[1].trim() : null;
}

function hasDirective(value, directive) {
  return typeof value === 'string' && new RegExp(`(^|[\\s,])${directive}([\\s,]|$)`, 'i').test(value);
}

function pathFromUrl(rawUrl) {
  try {
    return new URL(rawUrl).pathname;
  } catch {
    return rawUrl;
  }
}

function parseRewriteRules(configXml) {
  const rules = [];
  const pattern = /<rule\b[\s\S]*?<match\s+url="([^"]+)"[^>]*>[\s\S]*?<action\s+type="Rewrite"\s+url="([^"]+)"[^>]*\/>[\s\S]*?<\/rule>/gi;
  let m;
  while ((m = pattern.exec(configXml))) {
    try {
      rules.push({ regex: new RegExp(m[1], 'i'), target: m[2] });
    } catch {
      // ignora regex inválida
    }
  }
  return rules;
}

function resolvePathToFile(routePath, rewriteRules) {
  const normalized = routePath.replace(/^\//, '');
  if (!normalized) return 'RedutoresIBR.html';

  for (const rule of rewriteRules) {
    if (rule.regex.test(normalized)) {
      return rule.target.replace(/^\//, '').split('?')[0];
    }
  }

  if (fs.existsSync(normalized)) return normalized;
  if (fs.existsSync(path.join(normalized, 'index.html'))) return path.join(normalized, 'index.html');
  return null;
}

function getHeaderPolicyForRoute(routePath, configXml) {
  const rootSystemWebServer = configXml.match(/<system.webServer>([\s\S]*?)<\/system.webServer>/i)?.[1] || '';
  const globalHeaderMatch = rootSystemWebServer.match(/<add\s+name="X-Robots-Tag"\s+value="([^"]+)"\s*\/>/i);
  if (globalHeaderMatch) return globalHeaderMatch[1];

  const locationPattern = /<location\s+path="([^"]+)">([\s\S]*?)<\/location>/gi;
  let m;
  while ((m = locationPattern.exec(configXml))) {
    const locationPath = m[1].replace(/^\//, '');
    const block = m[2];
    if (routePath.replace(/^\//, '').startsWith(locationPath.toLowerCase())) {
      const headerMatch = block.match(/<add\s+name="X-Robots-Tag"\s+value="([^"]+)"\s*\/>/i);
      if (headerMatch) return headerMatch[1];
    }
  }

  return '';
}

async function tryRemoteAudit() {
  const sitemapResp = await fetch(SITEMAP_URL);
  if (!sitemapResp.ok) throw new Error(`Falha ao baixar sitemap remoto: ${sitemapResp.status}`);

  const sitemapXml = await sitemapResp.text();
  const sitemapUrls = extractLocs(sitemapXml).filter((url) => url.startsWith(PROD_BASE_URL));
  const publicUrls = Array.from(new Set([`${PROD_BASE_URL}/`, ...sitemapUrls]));

  const report = [];
  for (const url of publicUrls) {
    const resp = await fetch(url, { redirect: 'follow' });
    const html = await resp.text();
    const xRobotsTag = resp.headers.get('x-robots-tag') || '';
    const metaRobots = extractMetaContent(html, 'robots');
    const metaBingbot = extractMetaContent(html, 'bingbot');

    const findings = [];
    if (hasDirective(xRobotsTag, 'noarchive')) findings.push(`header X-Robots-Tag="${xRobotsTag}"`);
    if (hasDirective(metaRobots, 'noarchive')) findings.push(`meta robots="${metaRobots}"`);
    if (hasDirective(metaBingbot, 'noarchive')) findings.push(`meta bingbot="${metaBingbot}"`);

    report.push({ scope: 'public', path: pathFromUrl(url), ok: findings.length === 0, findings, status: resp.status });
  }

  return { mode: 'remote', report };
}

function runLocalAudit() {
  const configXml = fs.readFileSync('web.config', 'utf8');
  const sitemapXml = fs.readFileSync('sitemap.xml', 'utf8');
  const sitemapUrls = extractLocs(sitemapXml);
  const rewriteRules = parseRewriteRules(configXml);

  const publicPaths = Array.from(new Set(['/', '/PoliticaPrivacidade', ...sitemapUrls.map(pathFromUrl)]));

  const report = [];
  for (const routePath of publicPaths) {
    const file = resolvePathToFile(routePath, rewriteRules);
    if (!file || !fs.existsSync(file)) {
      report.push({ scope: 'public', path: routePath, ok: false, findings: ['arquivo não resolvido via rewrite'], status: 'local' });
      continue;
    }

    const html = fs.readFileSync(file, 'utf8');
    const metaRobots = extractMetaContent(html, 'robots');
    const metaBingbot = extractMetaContent(html, 'bingbot');
    const policy = getHeaderPolicyForRoute(routePath.toLowerCase(), configXml);

    const findings = [];
    if (hasDirective(metaRobots, 'noarchive')) findings.push(`meta robots="${metaRobots}"`);
    if (hasDirective(metaBingbot, 'noarchive')) findings.push(`meta bingbot="${metaBingbot}"`);
    if (hasDirective(policy, 'noarchive')) findings.push(`header X-Robots-Tag="${policy}"`);

    report.push({ scope: 'public', path: routePath, ok: findings.length === 0, findings, status: `local:${file}` });
  }

  const sensitivePaths = ['/AreaCliente', '/PaginasAreaClienteAcessoCadastro', '/PaginasAreaClienteSessaoPerfil', '/PaginasCarrinhoProdutos'];
  for (const routePath of sensitivePaths) {
    const policy = getHeaderPolicyForRoute(routePath.toLowerCase(), configXml);
    report.push({
      scope: 'sensitive',
      path: routePath,
      ok: hasDirective(policy, 'noindex') && hasDirective(policy, 'noarchive'),
      findings: hasDirective(policy, 'noindex') && hasDirective(policy, 'noarchive') ? [] : [`header X-Robots-Tag ausente/incompleto: "${policy}"`],
      status: 'local:web.config',
    });
  }

  return { mode: 'local', report };
}

(function main() {
  (async () => {
    let result;
    try {
      result = await tryRemoteAudit();
      console.log('Modo de auditoria: remoto (produção).');
    } catch (err) {
      result = runLocalAudit();
      console.log(`Modo de auditoria: local (fallback). Motivo: ${err.message}`);
    }

    for (const item of result.report) {
      const prefix = item.ok ? 'OK  ' : 'ERRO';
      const detail = item.ok ? 'sem noarchive indevido' : item.findings.join(' | ');
      console.log(`${prefix} [${item.scope}] ${item.path} -> ${detail} (${item.status})`);
    }

    if (result.report.some((item) => !item.ok)) process.exitCode = 1;
  })();
})();
