const fs = require('fs');
const path = require('path');

const ROOT_DIR = process.cwd();
const BASE_URL = 'https://configurador.redutoresibr.com.br';
const INDEX_PATH = path.join(ROOT_DIR, 'sitemap-index.xml');

const SITEMAPS = [
    { file: 'sitemap.xml', loc: `${BASE_URL}/sitemap.xml` },
    { file: 'sitemap-images.xml', loc: `${BASE_URL}/sitemap-images.xml` }
];

function toIsoDate(value) {
    return new Date(value).toISOString().split('T')[0];
}

function getLastMod(fileName) {
    const filePath = path.join(ROOT_DIR, fileName);
    if (!fs.existsSync(filePath)) {
        return toIsoDate(Date.now());
    }

    const stats = fs.statSync(filePath);
    return toIsoDate(stats.mtime);
}

function buildXml() {
    const nodes = SITEMAPS.map(({ file, loc }) => [
        '  <sitemap>',
        `    <loc>${loc}</loc>`,
        `    <lastmod>${getLastMod(file)}</lastmod>`,
        '  </sitemap>'
    ].join('\n')).join('\n');

    return [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        nodes,
        '</sitemapindex>',
        ''
    ].join('\n');
}

fs.writeFileSync(INDEX_PATH, buildXml(), 'utf8');
console.log('sitemap-index.xml atualizado com sucesso.');
