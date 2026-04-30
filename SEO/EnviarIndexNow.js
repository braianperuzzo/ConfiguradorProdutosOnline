const fs = require('fs');
const path = require('path');
const https = require('https');
const util = require('util');
const { XMLParser } = require('fast-xml-parser');

const ROOT_DIR = path.resolve(__dirname, '..');
const SITEMAP_PATH = path.join(ROOT_DIR, 'sitemap.xml');
const OUTPUT_PATH = path.join(ROOT_DIR, 'SEO', 'IndexNowSubmitPayload.json');
const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

function hasArg(flag) {
    return process.argv.slice(2).includes(flag);
}

function readSitemapUrls() {
    if (!fs.existsSync(SITEMAP_PATH)) {
        throw new Error('sitemap.xml não encontrado para envio do IndexNow.');
    }

    const xml = fs.readFileSync(SITEMAP_PATH, 'utf8');
    const parser = new XMLParser({ ignoreAttributes: false });
    const parsed = parser.parse(xml);
    const nodes = parsed.urlset?.url;
    const entries = Array.isArray(nodes) ? nodes : (nodes ? [nodes] : []);
    const urls = entries
        .map((entry) => (typeof entry.loc === 'string' ? entry.loc.trim() : ''))
        .filter(Boolean);

    if (urls.length === 0) {
        throw new Error('Nenhuma URL encontrada no sitemap.xml para envio do IndexNow.');
    }

    return urls;
}

function resolveIndexNowKey() {
    if (process.env.INDEXNOW_KEY) {
        return process.env.INDEXNOW_KEY.trim();
    }

    const files = fs.readdirSync(ROOT_DIR);
    const keyFile = files.find((file) => /^[a-f0-9]{32}\.txt$/i.test(file));

    if (!keyFile) {
        throw new Error('Arquivo de chave do IndexNow não encontrado na raiz do projeto.');
    }

    const keyPath = path.join(ROOT_DIR, keyFile);
    const keyValue = fs.readFileSync(keyPath, 'utf8').trim();
    const keyFromName = path.basename(keyFile, '.txt');

    if (!keyValue || keyValue.toLowerCase() !== keyFromName.toLowerCase()) {
        throw new Error(`A chave do arquivo ${keyFile} é inválida para o protocolo IndexNow.`);
    }

    return keyValue;
}

function createPayload(urlList) {
    const firstUrl = new URL(urlList[0]);
    const host = firstUrl.host;
    const protocol = firstUrl.protocol;
    const key = resolveIndexNowKey();

    return {
        host,
        key,
        keyLocation: `${protocol}//${host}/${key}.txt`,
        urlList
    };
}

function savePayload(payload) {
    const content = {
        generatedAt: new Date().toISOString(),
        totalUrls: payload.urlList.length,
        payload
    };

    fs.writeFileSync(OUTPUT_PATH, `${JSON.stringify(content, null, 2)}\n`, 'utf8');
}

function submitIndexNow(payload) {
    return new Promise((resolve, reject) => {
        const body = JSON.stringify(payload);
        const request = https.request(INDEXNOW_ENDPOINT, {
            method: 'POST',
            family: 4,
            headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'Content-Length': Buffer.byteLength(body)
            }
        }, (response) => {
            let responseBody = '';
            response.on('data', (chunk) => {
                responseBody += chunk;
            });
            response.on('end', () => {
                const statusCode = response.statusCode || 0;
                if (statusCode >= 200 && statusCode < 300) {
                    resolve({ statusCode, body: responseBody });
                    return;
                }

                const error = new Error(`IndexNow retornou ${statusCode}: ${responseBody || 'sem corpo de resposta'}`);
                error.statusCode = statusCode;
                error.responseBody = responseBody;
                reject(error);
            });
        });

        request.on('error', (error) => {
            reject(error);
        });

        request.write(body);
        request.end();
    });
}

function parseApiErrorBody(responseBody) {
    if (!responseBody) {
        return null;
    }

    try {
        const parsed = JSON.parse(responseBody);
        if (parsed && typeof parsed === 'object') {
            return parsed;
        }
    } catch (_parseError) {
        return null;
    }

    return null;
}


function formatSubmitError(error) {
    if (!error) {
        return 'erro desconhecido';
    }

    if (error instanceof AggregateError && Array.isArray(error.errors) && error.errors.length > 0) {
        const first = error.errors.find((entry) => entry && entry.code) || error.errors[0];
        const parts = [
            error.code || 'AggregateError',
            first && first.code ? first.code : null,
            first && first.address ? first.address : null
        ].filter(Boolean);
        return `${parts.join(' / ')}: sem conectividade para api.indexnow.org`;
    }

    if (error.code) {
        return `${error.code}: ${error.message || 'falha ao enviar para o IndexNow'}`;
    }

    if (error.statusCode) {
        const apiError = parseApiErrorBody(error.responseBody);
        if (apiError) {
            if (apiError.errorCode === 'SiteVerificationNotCompleted') {
                return `IndexNow retornou ${error.statusCode} (SiteVerificationNotCompleted). A chave ainda não foi validada pelo buscador; confirme se ${resolveIndexNowKey()}.txt está acessível no domínio e tente novamente em alguns minutos.`;
            }

            const apiMessage = apiError.message ? `: ${apiError.message}` : '';
            return `IndexNow retornou ${error.statusCode} (${apiError.errorCode || 'erro desconhecido'})${apiMessage}`;
        }
    }

    return error.message || util.inspect(error, { depth: 1 });
}

async function run() {
    const urlList = readSitemapUrls();
    const payload = createPayload(urlList);
    savePayload(payload);

    if (process.env.INDEXNOW_DRY_RUN === '1' || hasArg('--dry-run') || hasArg('--indexnow-dry-run')) {
        console.log(`[indexnow] Dry run habilitado. Payload salvo em ${path.relative(ROOT_DIR, OUTPUT_PATH)}.`);
        return;
    }

    const result = await submitIndexNow(payload);
    console.log(`[indexnow] ${payload.urlList.length} URL(s) enviada(s) com sucesso. Status HTTP: ${result.statusCode}.`);
}

run().catch((error) => {
    const message = formatSubmitError(error);
    if (process.env.INDEXNOW_STRICT === '1') {
        console.error('[indexnow] Falha no envio:', message);
        process.exit(1);
        return;
    }

    console.warn('[indexnow] Falha no envio (build seguirá):', message);
});
