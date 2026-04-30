const path = require('path');
const fs = require('fs').promises;

const SUPPORTED_EXTENSIONS = new Set(['.webp', '.avif', '.jpg', '.jpeg', '.png']);

function isSupportedFile(fileName) {
    return SUPPORTED_EXTENSIONS.has(path.extname(fileName).toLowerCase());
}

function parseSequenceParts(fileName) {
    const match = fileName.match(/^(\d+)([^/.]+)\.[^.]+$/);
    if (!match) return null;
    return {
        index: parseInt(match[1], 10),
        code: match[2],
    };
}

async function buildManifest(baseDir) {
    const manifest = {
        generatedAt: new Date().toISOString(),
        products: {},
    };

    let entries = [];
    try {
        entries = await fs.readdir(baseDir, { withFileTypes: true });
    } catch (error) {
        if (error.code === 'ENOENT') {
            await fs.mkdir(baseDir, { recursive: true });
        } else {
            throw error;
        }
    }

    if (!entries.length) {
        await fs.writeFile(path.join(baseDir, 'manifest.json'), JSON.stringify(manifest, null, 2));
        return manifest;
    }

    for (const entry of entries) {
        if (!entry.isDirectory()) continue;
        const folderName = entry.name;
        const folderPath = path.join(baseDir, folderName);
        let files = [];
        try {
            files = await fs.readdir(folderPath);
        } catch (error) {
            console.warn(`Não foi possível ler o diretório ${folderName}:`, error.message);
            continue;
        }

        const sequences = [];
        for (const file of files) {
            if (!isSupportedFile(file)) continue;
            const parts = parseSequenceParts(file);
            if (!parts) continue;
            sequences.push({ file, index: parts.index });
        }

        if (!sequences.length) continue;

        sequences.sort((a, b) => a.index - b.index);

        manifest.products[folderName] = {
            folder: folderName,
            basePath: `/ImagensProdutos/${folderName}/`,
            files: sequences.map(sequence => sequence.file),
            count: sequences.length,
        };
    }

    await fs.writeFile(path.join(baseDir, 'manifest.json'), JSON.stringify(manifest, null, 2));
    return manifest;
}

(async () => {
    const baseDir = path.join(process.cwd(), 'ImagensProdutos');
    try {
        const manifest = await buildManifest(baseDir);
        const total = Object.keys(manifest.products).length;
        console.log(`Manifesto de imagens gerado com ${total} entr${total === 1 ? 'ada' : 'adas'}.`);
    } catch (error) {
        console.error('Erro ao gerar manifesto de imagens:', error);
        process.exitCode = 1;
    }
})();