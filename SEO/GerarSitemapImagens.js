const fs = require('fs').promises;
const path = require('path');

const SITE_URL = 'https://configurador.redutoresibr.com.br';
const MANIFEST_PATH = path.join(process.cwd(), 'ImagensProdutos', 'manifest.json');
const OUTPUT_PATH = path.join(process.cwd(), 'sitemap-images.xml');

function escapeXml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
}

function normalizeUrl(pathname) {
    if (!pathname) return SITE_URL;
    if (pathname.startsWith('http')) return pathname;
    const normalized = pathname.startsWith('/') ? pathname : `/${pathname}`;
    return `${SITE_URL}${normalized}`;
}

function buildUrlEntry({ loc, imageLoc }) {
    return [
        '  <url>',
        `    <loc>${escapeXml(loc)}</loc>`,
        '    <image:image>',
        `      <image:loc>${escapeXml(imageLoc)}</image:loc>`,
        '    </image:image>',
        '  </url>'
    ].join('\n');
}

async function loadManifest() {
    const data = await fs.readFile(MANIFEST_PATH, 'utf8');
    return JSON.parse(data);
}

async function generateSitemap() {
    let manifest;
    try {
        manifest = await loadManifest();
    } catch (error) {
        if (error.code === 'ENOENT') {
            throw new Error('manifest.json não encontrado. Execute SEO/GerarManifestoImagens.js primeiro.');
        }
        throw error;
    }

    const products = Object.values(manifest.products || {});
    const urlEntries = [];

    for (const product of products) {
        if (!product || !Array.isArray(product.files) || product.files.length === 0) {
            continue;
        }
        const basePath = product.basePath || `/ImagensProdutos/${product.folder || ''}/`;
        const firstFile = product.files[0];
        const loc = normalizeUrl(basePath);
        const imageLoc = normalizeUrl(`${basePath}${firstFile}`);
        urlEntries.push(buildUrlEntry({ loc, imageLoc }));
    }

    const xml = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"',
        '  xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">',
        ...urlEntries,
        '</urlset>',
        ''
    ].join('\n');

    await fs.writeFile(OUTPUT_PATH, xml, 'utf8');
    return urlEntries.length;
}

(async () => {
    try {
        const total = await generateSitemap();
        console.log(`Sitemap de imagens gerado com ${total} entr${total === 1 ? 'ada' : 'adas'}.`);
    } catch (error) {
        console.error('Erro ao gerar sitemap de imagens:', error.message || error);
        process.exitCode = 1;
    }
})();
