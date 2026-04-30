const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { XMLParser, XMLBuilder } = require('fast-xml-parser');

const rootDir = path.join(__dirname, '..');
const sitemapPath = path.join(rootDir, 'sitemap.xml');
const webConfigPath = path.join(rootDir, 'web.config');
const canonicalRulesPath = path.join(rootDir, 'SEO', 'RegraLinksCanonicos.js');

// Build rewrite map from web.config friendly rules
const parser = new XMLParser({ ignoreAttributes: false });
const webConfig = fs.readFileSync(webConfigPath, 'utf8');
const webObj = parser.parse(webConfig);
const rules = webObj.configuration['system.webServer'].rewrite.rules.rule || [];
const rewriteMap = { '/': 'RedutoresIBR.html' };

for (const rule of rules) {
    if (rule['@_name'] && rule['@_name'].startsWith('Friendly')) {
        const matchUrl = rule.match['@_url']; // e.g., ^ConfiguradorIBRQ/?$
        let friendly = matchUrl
            .replace(/^\^/, '')
            .replace(/\$$/, '')
            .replace(/\/\\/g, '/')
            .replace(/\/\?$/, '');
        const target = rule.action['@_url'].replace(/^\//, '');
        rewriteMap['/' + friendly] = target;
    }
}

function loadFriendlyCodeMap() {
    try {
        const source = fs.readFileSync(canonicalRulesPath, 'utf8');
        const match = source.match(/friendlyCodeMap\s*=\s*{([\s\S]*?)}\s*;/);
        if (!match) {
            return {};
        }
        const mapBlock = match[1];
        const map = {};
        const entryRegex = /['"]([^'"]+)['"]\s*:\s*['"]([^'"]+)['"]/g;
        let entry = entryRegex.exec(mapBlock);
        while (entry) {
            map[entry[1].toUpperCase()] = entry[2];
            entry = entryRegex.exec(mapBlock);
        }
        return map;
    } catch (err) {
        console.warn('[sitemap] Friendly map not loaded:', err.message || err);
        return {};
    }
}

const friendlyCodeMap = loadFriendlyCodeMap();

function buildCanonicalQueryDefaultsFromRedirectRules() {
    const defaults = {};
    for (const rule of rules) {
        const ruleName = typeof rule['@_name'] === 'string' ? rule['@_name'] : '';
        if (!/missing\s+[A-Z0-9_]+$/i.test(ruleName)) {
            continue;
        }

        const matchPattern = rule?.match?.['@_url'];
        const actionUrl = rule?.action?.['@_url'];
        if (typeof matchPattern !== 'string' || typeof actionUrl !== 'string') {
            continue;
        }

        const normalizedPath = matchPattern
            .replace(/^\^/, '')
            .replace(/\/?\$$/, '')
            .replace(/\/\?$/, '')
            .replace(/^\/?/, '/');

        const actionQuery = actionUrl.split('?')[1] || '';
        const params = new URLSearchParams(actionQuery);
        const firstParam = params.entries().next();
        if (firstParam.done) {
            continue;
        }

        const [key, value] = firstParam.value;
        if (!key || !value) {
            continue;
        }

        defaults[normalizedPath.toUpperCase()] = {
            key: key.toUpperCase(),
            value
        };
    }
    return defaults;
}

const canonicalQueryDefaults = buildCanonicalQueryDefaultsFromRedirectRules();

function resolveFriendlySlug(search) {
    if (!search || search === '?') {
        return null;
    }
    try {
        const trimmed = search.charAt(0) === '?' ? search.slice(1) : search;
        if (!trimmed) {
            return null;
        }
        const params = new URLSearchParams(trimmed);
        const entries = params.entries();
        let current = entries.next();
        while (!current.done) {
            const value = current.value[1];
            if (value) {
                const slug = friendlyCodeMap[String(value).toUpperCase()];
                if (slug) {
                    return slug;
                }
            }
            current = entries.next();
        }
        return null;
    } catch (err) {
        return null;
    }
}

function getEnvDate(name) {
    const raw = process.env[name];
    if (!raw) {
        return null;
    }

    const parsed = new Date(raw);

    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    return parsed;
}
const deployDate = getEnvDate('SITEMAP_DEPLOY_DATE') || new Date();

function getGitLastModified(relPath) {
    try {
        const output = execSync(`git log -1 --format=%cI -- "${relPath}"`, {
            cwd: rootDir,
            stdio: ['ignore', 'pipe', 'ignore'],
            encoding: 'utf8'
        }).trim();
        return output || null;
    } catch (err) {
        return null;
    }
}

function coerceDate(value) {
    if (!value) {
        return null;
    }

    const parsed = value instanceof Date ? value : new Date(value);

    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function pickLastmod({ gitDate, statDate, fallbackDate }) {
    const capTime = deployDate.getTime();
    const git = coerceDate(gitDate);
    const stat = coerceDate(statDate);
    const fallback = coerceDate(fallbackDate);

    if (git) {
        return new Date(Math.min(git.getTime(), capTime)).toISOString();
    }

    if (stat) {
        return new Date(Math.min(stat.getTime(), capTime)).toISOString();
    }

    if (fallback) {
        return new Date(Math.min(fallback.getTime(), capTime)).toISOString();
    }

    return null;
}

// Update sitemap lastmod based on file metadata
const sitemapXml = fs.readFileSync(sitemapPath, 'utf8');
const sitemapObj = parser.parse(sitemapXml);
const urlNodes = sitemapObj.urlset?.url;
const urls = Array.isArray(urlNodes) ? urlNodes : (urlNodes ? [urlNodes] : []);

for (const entry of urls) {
    const loc = entry.loc;
    let gitDate = null;
    let statDate = null;

    if (typeof loc === 'string') {
        try {
            const url = new URL(loc);
            const pathname = url.pathname;
            const mapped = rewriteMap[pathname] || pathname.replace(/^\//, '');
            const filePath = path.join(rootDir, mapped);

            if (fs.existsSync(filePath)) {
                const relPath = path.relative(rootDir, filePath).replace(/\\/g, '/');
                gitDate = getGitLastModified(relPath);

                const stats = fs.statSync(filePath);
                statDate = stats.mtime;
            }

            const friendlySlug = resolveFriendlySlug(url.search);
            if (!friendlySlug) {
                const slugKey = (url.pathname || '').toUpperCase();
                const defaultQuery = canonicalQueryDefaults[slugKey];
                if (defaultQuery) {
                    const params = new URLSearchParams(url.search || '');
                    const hasCanonicalKey = params.has(defaultQuery.key);
                    if (!hasCanonicalKey) {
                        params.set(defaultQuery.key, defaultQuery.value);
                        const nextSearch = params.toString();
                        url.search = nextSearch ? `?${nextSearch}` : '';
                        entry.loc = url.toString();
                    }
                }
            }

            if (friendlySlug) {
                url.pathname = `/Configurador${friendlySlug}`;
                entry.loc = url.toString();
            }
        } catch (err) {
            // ignore invalid URLs
        }
    }

    const normalized = pickLastmod({
        gitDate,
        statDate,
        fallbackDate: deployDate
    });

    if (normalized) {
        entry.lastmod = normalized;
    } else {
        delete entry.lastmod;
    }
}

const builder = new XMLBuilder({ ignoreAttributes: false, format: true });
const newXml = builder.build(sitemapObj);
fs.writeFileSync(sitemapPath, `${newXml}\n`);
