#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { XMLParser } = require('fast-xml-parser');

const ROOT_DIR = path.resolve(__dirname, '..');
const WEB_CONFIG_PATH = path.join(ROOT_DIR, 'web.config');
const CANONICAL_TOKENS = ['@@CANONICAL_URL@@', '@@REQUEST_CANONICAL_URL@@'];
const BASE_URL = 'https://configurador.redutoresibr.com.br';
const SELECTORS_DIR = path.join(ROOT_DIR, 'PaginasConfiguradoresSeletores');
const FRIENDLY_CODE_SOURCE = path.join(ROOT_DIR, 'SEO', 'RegraLinksCanonicos.js');
const CANONICAL_LINK_PATTERN = /<link\s+rel=["']canonical["']/i;

function loadWebConfig() {
    try {
        const xml = fs.readFileSync(WEB_CONFIG_PATH, 'utf8');
        const parser = new XMLParser({ ignoreAttributes: false, attributeNamePrefix: '', allowBooleanAttributes: true });
        return parser.parse(xml);
    } catch (error) {
        throw new Error(`Não foi possível ler ou processar o arquivo web.config: ${error.message}`);
    }
}

function normalizeHtmlPath(actionUrl) {
    if (typeof actionUrl !== 'string') {
        return null;
    }
    const cleaned = actionUrl.trim();
    if (!cleaned.toLowerCase().endsWith('.html')) {
        return null;
    }
    return cleaned.replace(/^\/+/, '');
}

function regexPatternToPath(pattern) {
    if (typeof pattern !== 'string') {
        return null;
    }
    let clean = pattern.trim();
    if (clean.startsWith('^')) {
        clean = clean.slice(1);
    }
    if (clean.endsWith('$')) {
        clean = clean.slice(0, -1);
    }
    if (clean.endsWith('/?')) {
        clean = clean.slice(0, -2);
    }
    clean = clean.replace(/\\\//g, '/');

    const hasRegexMeta = /[.*+?^${}()|[\]\\]/.test(clean);
    if (hasRegexMeta) {
        return null;
    }
    if (!clean) {
        return '/';
    }
    return clean.startsWith('/') ? clean : `/${clean}`;
}

function loadFriendlySlugToCodeMapFromSelectors() {
    const slugMap = new Map();

    function visit(directory) {
        let entries;
        try {
            entries = fs.readdirSync(directory, { withFileTypes: true });
        } catch (error) {
            console.warn(`Aviso: não foi possível ler o diretório ${directory}: ${error.message}`);
            return;
        }

        for (const entry of entries) {
            const fullPath = path.join(directory, entry.name);
            if (entry.isDirectory()) {
                visit(fullPath);
            } else if (entry.isFile() && entry.name.toLowerCase().endsWith('.html')) {
                let content;
                try {
                    content = fs.readFileSync(fullPath, 'utf8');
                } catch (error) {
                    console.warn(`Aviso: não foi possível ler o arquivo ${fullPath}: ${error.message}`);
                    continue;
                }

                const tagRegex = /<[^>]*class=["'][^"'>]*galeria-produto[^"'>]*[^>]*>/ig;
                let tagMatch;
                while ((tagMatch = tagRegex.exec(content)) != null) {
                    const tag = tagMatch[0];
                    const folderMatch = tag.match(/data-folder\s*=\s*["']([^"']+)["']/i);
                    const visibleMatch = tag.match(/data-visible\s*=\s*["']([^"']+)["']/i);
                    if (!folderMatch || !visibleMatch) {
                        continue;
                    }
                    const slug = folderMatch[1]?.trim();
                    const code = visibleMatch[1]?.trim();
                    if (!slug || !code) {
                        continue;
                    }
                    const normalizedSlug = slug.toUpperCase();
                    const normalizedCode = code.toUpperCase();
                    if (!slugMap.has(normalizedSlug)) {
                        slugMap.set(normalizedSlug, new Set());
                    }
                    slugMap.get(normalizedSlug).add(normalizedCode);
                }
            }
        }
    }

    visit(SELECTORS_DIR);
    return slugMap;
}

function loadFriendlySlugToCodeMapFromLegacySource() {
    try {
        const content = fs.readFileSync(FRIENDLY_CODE_SOURCE, 'utf8');
        const map = new Map();
        const objectLiteralMatch = content.match(/friendlyCodeMap\s*=\s*\{([\s\S]*?)\}\s*;/);
        if (!objectLiteralMatch) {
            return map;
        }
        const literalBody = objectLiteralMatch[1];
        const pairRegex = /['"]([^'"\\]+)['"]\s*:\s*['"]([^'"\\]+)['"]/g;
        let pairMatch;
        while ((pairMatch = pairRegex.exec(literalBody)) != null) {
            const code = pairMatch[1];
            const slug = pairMatch[2];
            if (slug) {
                const normalizedSlug = slug.trim().toUpperCase();
                const normalizedCode = code.trim().toUpperCase();
                if (!map.has(normalizedSlug)) {
                    map.set(normalizedSlug, new Set());
                }
                map.get(normalizedSlug).add(normalizedCode);
            }
        }
        return map;
    } catch (error) {
        console.warn(`Não foi possível carregar o mapa legado de códigos amigáveis (${FRIENDLY_CODE_SOURCE}): ${error.message}`);
        return new Map();
    }
}

function buildFriendlySlugToCodeMap() {
    const combined = new Map();
    const selectorMap = loadFriendlySlugToCodeMapFromSelectors();
    const legacyMap = loadFriendlySlugToCodeMapFromLegacySource();

    const mergeSource = (source) => {
        for (const [slug, codes] of source.entries()) {
            if (!combined.has(slug)) {
                combined.set(slug, new Set());
            }
            const targetSet = combined.get(slug);
            if (codes instanceof Set) {
                codes.forEach((code) => targetSet.add(code));
            } else if (Array.isArray(codes)) {
                codes.forEach((code) => targetSet.add(String(code).toUpperCase()));
            } else if (typeof codes === 'string') {
                targetSet.add(codes.toUpperCase());
            }
        }
    };

    mergeSource(legacyMap);
    mergeSource(selectorMap);

    return combined;
}

function extractQueryKeyFromPattern(pattern) {
    if (typeof pattern !== 'string') {
        return null;
    }
    const cleaned = pattern.replace(/&amp;/g, '&');
    const match = cleaned.match(/\b([A-Za-z0-9_]+)=/);
    return match ? match[1] : null;
}

function pickCodeForSlug(slug, slugToCodeMap) {
    if (!slug) {
        return null;
    }
    const codes = slugToCodeMap.get(slug.toUpperCase());
    if (!codes || codes.size === 0) {
        return null;
    }
    if (codes.size > 1) {
        console.warn(`Aviso: múltiplos códigos encontrados para o slug ${slug}: ${Array.from(codes).join(', ')}`);
    }
    return Array.from(codes)[0];
}

function buildCanonicalUrl(friendlyPath, rule, slugToCodeMap) {
    if (!friendlyPath) {
        return null;
    }

    let canonical = `${BASE_URL}${friendlyPath}`;

    const conditions = rule?.conditions?.add;
    const conditionList = Array.isArray(conditions) ? conditions : conditions ? [conditions] : [];
    let queryKey = null;
    for (const condition of conditionList) {
        const input = condition?.input || condition?.['@_input'];
        if (typeof input === 'string' && input.toUpperCase().includes('{QUERY_STRING}')) {
            const pattern = condition?.pattern || condition?.['@_pattern'];
            queryKey = extractQueryKeyFromPattern(pattern);
            if (queryKey) {
                break;
            }
        }
    }

    if (queryKey) {
        const slugMatch = friendlyPath.replace(/^\/+/, '').match(/^Configurador([^/?#]+)$/i);
        if (slugMatch) {
            const slug = slugMatch[1];
            const code = pickCodeForSlug(slug, slugToCodeMap);
            if (code) {
                const separator = canonical.includes('?') ? '&' : '?';
                canonical += `${separator}${queryKey}=${encodeURIComponent(code)}`;
            }
        }
    }

    return canonical;
}

function buildCanonicalMap(webConfig, slugToCodeMap) {
    const canonicalMap = new Map();
    const rules = webConfig?.configuration?.['system.webServer']?.rewrite?.rules?.rule;
    if (Array.isArray(rules)) {
        for (const rule of rules) {
            const actionUrl = normalizeHtmlPath(rule?.action?.url);
            if (!actionUrl) {
                continue;
            }
            const matchPattern = rule?.match?.url;
            const friendlyPath = regexPatternToPath(matchPattern);
            if (!friendlyPath) {
                continue;
            }
            const canonicalUrl = buildCanonicalUrl(friendlyPath, rule, slugToCodeMap);
            if (!canonicalUrl) {
                continue;
            }
            const existing = canonicalMap.get(actionUrl);
            if (existing) {
                if (!existing.includes(canonicalUrl)) {
                    existing.push(canonicalUrl);
                }
            } else {
                canonicalMap.set(actionUrl, [canonicalUrl]);
            }
        }
    }

    const defaultDocs = webConfig?.configuration?.['system.webServer']?.defaultDocument?.files?.add;
    if (defaultDocs) {
        const entries = Array.isArray(defaultDocs) ? defaultDocs : [defaultDocs];
        for (const entry of entries) {
            const value = typeof entry === 'string' ? entry : entry?.value;
            const normalized = normalizeHtmlPath(value);
            if (normalized) {
                const canonical = `${BASE_URL.replace(/\/$/, '')}/`;
                const existing = canonicalMap.get(normalized);
                if (existing) {
                    if (!existing.includes(canonical)) {
                        existing.push(canonical);
                    }
                } else {
                    canonicalMap.set(normalized, [canonical]);
                }
            }
        }
    }

    return canonicalMap;
}

function collectHtmlFilesWithToken(canonicalMap) {
    const files = [];
    const seen = new Set();
    function walk(currentDir) {
        const dirEntries = fs.readdirSync(currentDir, { withFileTypes: true });
        for (const entry of dirEntries) {
            if (entry.name === 'node_modules' || entry.name.startsWith('.git')) {
                continue;
            }
            const fullPath = path.join(currentDir, entry.name);
            if (entry.isDirectory()) {
                walk(fullPath);
            } else if (entry.isFile() && entry.name.toLowerCase().endsWith('.html')) {
                const content = fs.readFileSync(fullPath, 'utf8');
                if (CANONICAL_TOKENS.some((token) => content.includes(token)) || CANONICAL_LINK_PATTERN.test(content)) {
                    files.push({ path: fullPath, content });
                    seen.add(fullPath);
                }
            }
        }
    }
    walk(ROOT_DIR);

    if (canonicalMap && canonicalMap.size > 0) {
        for (const relativePath of canonicalMap.keys()) {
            const absolutePath = path.join(ROOT_DIR, relativePath);
            if (seen.has(absolutePath) || !fs.existsSync(absolutePath)) {
                continue;
            }
            const content = fs.readFileSync(absolutePath, 'utf8');
            if (CANONICAL_TOKENS.some((token) => content.includes(token)) || CANONICAL_LINK_PATTERN.test(content)) {
                files.push({ path: absolutePath, content });
                seen.add(absolutePath);
            }
        }
    }
    return files;
}

function extractPreferredCanonicalFromContent(content) {
    if (typeof content !== 'string' || !content) {
        return [];
    }
    const urlRegex = /<(?:meta|link)\b[^>]*(?:property|name)=["'](?:og:url|twitter:url)["'][^>]*?(?:content|href)=["']([^"']+)["'][^>]*>/ig;
    let match;
    const results = [];
    while ((match = urlRegex.exec(content)) != null) {
        const url = match[1];
        if (url && /^https?:\/\//i.test(url)) {
            results.push(url);
        }
    }
    return results;
}

function resolveCanonicalUrl(relativePath, canonicalMap, fileContent, shouldWarn) {
    const normalizedPath = relativePath.replace(/\\/g, '/');
    const candidates = canonicalMap.get(normalizedPath);
    if (Array.isArray(candidates) && candidates.length > 0) {
        const unique = [...new Set(candidates)];
        if (unique.length === 1) {
            return unique[0];
        }
        const references = extractPreferredCanonicalFromContent(fileContent);
        if (references.length > 0) {
            for (const reference of references) {
                const directMatch = unique.find((candidate) => candidate === reference);
                if (directMatch) {
                    return directMatch;
                }
            }
            for (const reference of references) {
                const referenceBase = reference.split('?')[0];
                const baseMatch = unique.find((candidate) => candidate.split('?')[0] === referenceBase);
                if (baseMatch) {
                    return baseMatch;
                }
            }
        }
        if (shouldWarn) {
            console.warn(`Aviso: múltiplos candidatos canônicos encontrados para ${normalizedPath}. Utilizando o primeiro encontrado.`);
        }
        return unique[0];
    }
    const base = BASE_URL.endsWith('/') ? BASE_URL.slice(0, -1) : BASE_URL;
    return `${base}/${normalizedPath}`;
}

function updateMetaUrls(content, canonicalUrl) {
    if (typeof content !== 'string' || !content || !canonicalUrl) {
        return content;
    }
    const replacements = [
        { regex: /(<meta[^>]+property=["']og:url["'][^>]*content=["'])([^"']*)(["'][^>]*>)/ig },
        { regex: /(<meta[^>]+name=["']twitter:url["'][^>]*content=["'])([^"']*)(["'][^>]*>)/ig }
    ];
    let updated = content;
    for (const { regex } of replacements) {
        updated = updated.replace(regex, (match, prefix, _current, suffix) => `${prefix}${canonicalUrl}${suffix}`);
    }
    return updated;
}

function buildCodeToSlugMap(canonicalMap) {
    const result = new Map();
    const normalizeCode = (value) => (typeof value === 'string' ? value.trim().toUpperCase() : null);

    for (const urls of canonicalMap.values()) {
        if (!Array.isArray(urls)) {
            continue;
        }
        for (const candidate of urls) {
            if (typeof candidate !== 'string' || !candidate) {
                continue;
            }
            let parsed;
            try {
                parsed = new URL(candidate);
            } catch (error) {
                continue;
            }
            const slugMatch = parsed.pathname.match(/\/Configurador([^/]+)$/i);
            if (!slugMatch) {
                continue;
            }
            const slug = slugMatch[1].toUpperCase();
            const searchParams = new URLSearchParams(parsed.search.replace(/^\?/, ''));
            for (const [_key, value] of searchParams.entries()) {
                const normalized = normalizeCode(value);
                if (!normalized) {
                    continue;
                }
                if (!result.has(normalized)) {
                    result.set(normalized, slug);
                }
            }
        }
    }

    return result;
}

function formatMapEntries(entries, lineBuilder) {
    return entries.map(lineBuilder).join('\n');
}

function buildJsObjectLines(sortedEntries, indent) {
    return formatMapEntries(sortedEntries, ([code, slug], index) => {
        const suffix = index < sortedEntries.length - 1 ? ',' : '';
        return `${indent}'${code}': '${slug}'${suffix}`;
    });
}

function buildPhpArrayLines(sortedEntries, indent) {
    return formatMapEntries(sortedEntries, ([code, slug]) => `${indent}'${code}' => '${slug}',`);
}

function replaceSection(content, regex, replacementFactory) {
    if (!regex.test(content)) {
        return content;
    }
    return content.replace(regex, (_match, prefix = '') => replacementFactory(prefix));
}

function updateFileSection(filePath, regex, buildReplacement) {
    try {
        const original = fs.readFileSync(filePath, 'utf8');
        const updated = replaceSection(original, regex, buildReplacement);
        if (updated !== original) {
            fs.writeFileSync(filePath, updated, 'utf8');
            console.log(`Atualizado: ${path.relative(ROOT_DIR, filePath)}`);
        }
    } catch (error) {
        console.warn(`Aviso: não foi possível atualizar ${filePath}: ${error.message}`);
    }
}

function updateFriendlyCodeConsumers(codeToSlugMap) {
    if (!(codeToSlugMap instanceof Map) || codeToSlugMap.size === 0) {
        return;
    }

    const sortedEntries = Array.from(codeToSlugMap.entries()).sort(([a], [b]) => a.localeCompare(b));

    const linksCanonicosPath = path.join(ROOT_DIR, 'SEO', 'RegraLinksCanonicos.js');
    const layoutJsPath = path.join(ROOT_DIR, 'Layout', 'Layout.js');
    const listaConfiguradoresPath = path.join(ROOT_DIR, 'SEO', 'ListaConfiguradoresLinkAmigavel.php');

    updateFileSection(
        linksCanonicosPath,
        new RegExp('(^[ \\t]*)var\\s+friendlyCodeMap\\s*=\\s*\\{[\\s\\S]*?\\};', 'm'),
        (prefix) => {
            const entryIndent = `${prefix}    `;
            const lines = buildJsObjectLines(sortedEntries, entryIndent);
            return `${prefix}var friendlyCodeMap = {\n${lines}\n${prefix}};`;
        }
    );

    updateFileSection(
        layoutJsPath,
        new RegExp('(^[ \\t]*)var\\s+CANONICAL_CODE_MAP\\s*=\\s*\\{[\\s\\S]*?\\};', 'm'),
        (prefix) => {
            const entryIndent = `${prefix}    `;
            const lines = buildJsObjectLines(sortedEntries, entryIndent);
            return `${prefix}var CANONICAL_CODE_MAP = {\n${lines}\n${prefix}};`;
        }
    );

    updateFileSection(
        listaConfiguradoresPath,
        new RegExp('(^[ \\t]*static\\s+\\$map\\s*=\\s*\\[)[\\s\\S]*?(\\];)', 'm'),
        (prefix, _closing) => {
            const indentMatch = prefix.match(/^(\s*)/);
            const baseIndent = indentMatch ? indentMatch[1] : '';
            const lines = buildPhpArrayLines(sortedEntries, `${baseIndent}    `);
            return `${prefix}\n${lines}\n${baseIndent}];`;
        }
    );
}

function updateCanonicals() {
    const webConfig = loadWebConfig();
    const slugToCodeMap = buildFriendlySlugToCodeMap();
    const canonicalMap = buildCanonicalMap(webConfig, slugToCodeMap);
    const files = collectHtmlFilesWithToken(canonicalMap);

    let updatedCount = 0;
    for (const file of files) {
        const relative = path.relative(ROOT_DIR, file.path).replace(/\\/g, '/');
        const hasCanonicalMarker = CANONICAL_TOKENS.some((token) => file.content.includes(token))
            || CANONICAL_LINK_PATTERN.test(file.content);
        const canonicalUrl = resolveCanonicalUrl(relative, canonicalMap, file.content, hasCanonicalMarker);
        let updatedContent = file.content;
        for (const token of CANONICAL_TOKENS) {
            if (updatedContent.includes(token)) {
                updatedContent = updatedContent.split(token).join(canonicalUrl);
            }
        }
        updatedContent = updateMetaUrls(updatedContent, canonicalUrl);
        const canonicalLinkRegex = /(<link\s+rel=["']canonical["'][^>]*href=["'])([^"']*)(["'][^>]*>)/i;
        updatedContent = updatedContent.replace(canonicalLinkRegex, (match, prefix, _current, suffix) => `${prefix}${canonicalUrl}${suffix}`);
        const alternateRegex = /(<link\s+rel=["']alternate["'][^>]*href=["'])([^"']*)(["'][^>]*>)/ig;
        updatedContent = updatedContent.replace(alternateRegex, (match, prefix, _current, suffix) => `${prefix}${canonicalUrl}${suffix}`);
        if (updatedContent !== file.content) {
            fs.writeFileSync(file.path, updatedContent, 'utf8');
            updatedCount += 1;
            console.log(`Atualizado: ${relative} -> ${canonicalUrl}`);
        }
    }

    const codeToSlugMap = buildCodeToSlugMap(canonicalMap);
    updateFriendlyCodeConsumers(codeToSlugMap);

    if (updatedCount === 0) {
        console.log('Nenhum arquivo necessitou atualização.');
    } else {
        console.log(`Total de arquivos atualizados: ${updatedCount}`);
    }
}

updateCanonicals();