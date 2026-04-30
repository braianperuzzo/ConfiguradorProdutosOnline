const VERSION_CONFIG_URL = '/Versionamento/Versao.json';
const CACHE_VERSION = 'v1';
const CACHE_NAME_PREFIX = 'configuradoronline';
const OFFLINE_URL = '/PaginaErros/PaginaErros.html';
const FALLBACK_APP_VERSION = 'versao.desconhecida';
let resolvedAppVersion = FALLBACK_APP_VERSION;
let resolvedVersionPromise = null;

async function fetchVersionFromConfig() {
    try {
        const response = await fetch(VERSION_CONFIG_URL, { cache: 'no-store' });
        if (!response.ok) {
            console.warn('ServiceWorker: failed to load version config.', response.status, response.statusText);
            return FALLBACK_APP_VERSION;
        }

        const data = await response.json();
        const candidate = data && (data.version || data.appVersion);
        if (typeof candidate === 'string' && candidate.trim()) {
            return candidate.trim();
        }
        console.warn('ServiceWorker: invalid version data received from config.');
    } catch (error) {
        console.warn('ServiceWorker: error while fetching version config.', error);
    }

    return FALLBACK_APP_VERSION;
}

function setResolvedAppVersion(version) {
    const normalized = typeof version === 'string' && version.trim() ? version.trim() : FALLBACK_APP_VERSION;
    resolvedAppVersion = normalized;
    return normalized;
}

function ensureResolvedVersion() {
    if (!resolvedVersionPromise) {
        resolvedVersionPromise = fetchVersionFromConfig()
            .then(setResolvedAppVersion)
            .catch(error => {
                console.warn('ServiceWorker: using fallback version due to error.', error);
                return setResolvedAppVersion(FALLBACK_APP_VERSION);
            });
    }
    return resolvedVersionPromise;
}

importScripts('https://storage.googleapis.com/workbox-cdn/releases/6.5.4/workbox-sw.js');

const RUNTIME_CACHE_NAMES = {
    images: `${CACHE_NAME_PREFIX}-${CACHE_VERSION}-images`,
    assets: `${CACHE_NAME_PREFIX}-${CACHE_VERSION}-assets`,
    pages: `${CACHE_NAME_PREFIX}-${CACHE_VERSION}-pages`
};

const SENSITIVE_PATH_PREFIXES = [
    '/areacliente',
    '/paginasareacliente',
    '/paginasareaclienteacessocadastro',
    '/paginasareaclientesessaoperfil',
    '/paginasareaclienteregistroshistoricos',
    '/tokensgeradores'
];

const SENSITIVE_PATHS = [
    '/csrfpega.php'
];

const OAUTH_QUERY_PARAMS = ['code', 'state', 'id_token', 'access_token', 'error'];

function hasOAuthCallbackParams(url) {
    if (!url || !url.search) {
        return false;
    }
    try {
        const params = new URLSearchParams(url.search);
        return OAUTH_QUERY_PARAMS.some(param => params.has(param));
    } catch (error) {
        return false;
    }
}

function isSensitiveAuthPath(pathname) {
    if (typeof pathname !== 'string') {
        return false;
    }
    const lowerPath = pathname.toLowerCase();
    if (SENSITIVE_PATHS.includes(lowerPath)) {
        return true;
    }
    return SENSITIVE_PATH_PREFIXES.some(prefix => lowerPath.startsWith(prefix));
}

function isSensitiveAuthRequest(request, url) {
    if (!request || !url) {
        return false;
    }
    if (isSensitiveAuthPath(url.pathname)) {
        return true;
    }
    if (hasOAuthCallbackParams(url)) {
        return true;
    }
    return false;
}

function isSensitiveAuthReferrer(request) {
    if (!request || !request.referrer) {
        return false;
    }
    try {
        const referrerUrl = new URL(request.referrer);
        return isSensitiveAuthPath(referrerUrl.pathname);
    } catch (error) {
        return false;
    }
}

function fetchNoStore(request, options = {}) {
    const redirectMode = options.redirect || request.redirect;
    return fetch(new Request(request, { cache: 'no-store', redirect: redirectMode, ...options }));
}

function isSessionValidationPath(pathname) {
    if (typeof pathname !== 'string') {
        return false;
    }
    const lowerPath = pathname.toLowerCase();
    return lowerPath === '/paginasareaclienteacessocadastro/validarsessao.php';
}

function shouldBypassCacheForConfigurator(url) {
    if (!url) {
        return false;
    }
    const pathname = url.pathname.toLowerCase();
    if (!pathname.startsWith('/configuradoribr')) {
        return false;
    }
    if (!url.search) {
        return false;
    }
    const search = url.search.toLowerCase();
    return (
        search.includes('carrinhoorigem') ||
        search.includes('focoacessorio') ||
        search.includes('focovaloracessorio') ||
        search.includes('embed=1')
    );
}

function isVersionedAssetRequest(request, url) {
    if (!request || !url) {
        return false;
    }
    if (!['style', 'script', 'font'].includes(request.destination)) {
        return false;
    }
    if (url.searchParams.has('versao') || url.searchParams.has('rev')) {
        return true;
    }
    return /\.(min\.(css|js)|css|js|woff2?|ttf|otf)$/.test(url.pathname);
}

if (self.workbox) {
    const { core, precaching, routing, strategies, expiration, cacheableResponse } = self.workbox;
    core.setCacheNameDetails({
        prefix: CACHE_NAME_PREFIX,
        suffix: CACHE_VERSION
    });
    core.clientsClaim();

    precaching.precacheAndRoute(self.__WB_MANIFEST, {
        ignoreURLParametersMatching: [/^utm_/, /^fbclid$/]
    });

    const { CacheFirst, StaleWhileRevalidate, NetworkFirst, NetworkOnly } = strategies;
    const { ExpirationPlugin } = expiration;
    const { CacheableResponsePlugin } = cacheableResponse;

    const assetPlugins = [
        new CacheableResponsePlugin({ statuses: [0, 200] }),
        new ExpirationPlugin({ maxEntries: 50, purgeOnQuotaError: true })
    ];

    const imagePlugins = [
        new CacheableResponsePlugin({ statuses: [0, 200] }),
        new ExpirationPlugin({ maxEntries: 60, purgeOnQuotaError: true })
    ];

    const pagePlugins = [
        new CacheableResponsePlugin({ statuses: [0, 200] }),
        new ExpirationPlugin({ maxEntries: 30, purgeOnQuotaError: true })
    ];

    routing.registerRoute(
        ({ request, url }) => (
            request.method === 'GET' &&
            url.origin === self.location.origin &&
            isSensitiveAuthRequest(request, url)
        ),
        new NetworkOnly()
    );

    routing.registerRoute(
        ({ request, url }) => (
            request.method === 'GET' &&
            url.origin === self.location.origin &&
            isSessionValidationPath(url.pathname)
        ),
        new NetworkOnly()
    );

    routing.registerRoute(
        ({ request }) => (
            request.method === 'GET' &&
            ['style', 'script'].includes(request.destination) &&
            isSensitiveAuthReferrer(request)
        ),
        new NetworkOnly()
    );

    routing.registerRoute(
        ({ request, url }) => (
            request.method === 'GET' &&
            request.mode === 'navigate' &&
            url.origin === self.location.origin &&
            shouldBypassCacheForConfigurator(url)
        ),
        new NetworkOnly()
    );

    routing.registerRoute(
        ({ request, url }) => (
            request.method === 'GET' &&
            request.mode === 'navigate' &&
            url.origin === self.location.origin
        ),
        new NetworkFirst({
            cacheName: RUNTIME_CACHE_NAMES.pages,
            plugins: pagePlugins
        })
    );

    routing.registerRoute(
        ({ request, url }) => (
            request.method === 'GET' &&
            url.origin === self.location.origin &&
            url.pathname.startsWith('/PaginasConfiguradoresSeletores/')
        ),
        new StaleWhileRevalidate({
            cacheName: RUNTIME_CACHE_NAMES.pages,
            plugins: pagePlugins
        })
    );

    routing.registerRoute(
        ({ request, url }) => (
            request.method === 'GET' &&
            url.origin === self.location.origin &&
            isVersionedAssetRequest(request, url)
        ),
        new CacheFirst({
            cacheName: RUNTIME_CACHE_NAMES.assets,
            plugins: assetPlugins
        })
    );

    routing.registerRoute(
        ({ request, url }) => (
            request.method === 'GET' &&
            url.origin === self.location.origin &&
            request.destination === 'image'
        ),
        new StaleWhileRevalidate({
            cacheName: RUNTIME_CACHE_NAMES.images,
            plugins: imagePlugins
        })
    );

    routing.setCatchHandler(async ({ event }) => {
        if (event.request && event.request.mode === 'navigate') {
            const cached = await precaching.matchPrecache(OFFLINE_URL);
            if (cached) {
                return cached;
            }
        }
        return Response.error();
    });
} else {
    console.warn('ServiceWorker: Workbox failed to load, runtime caching disabled.');
}

const DB_NAME = 'pwa-sync';
const STORE_NAME = 'requests';
const CRYPTO_STORE_NAME = 'offline-crypto';
const ACTIVE_KEY_ID = 'active-key-version';
const KEY_ID_PREFIX = 'offline-key-';
const MAX_QUEUE_LENGTH = 50;
const MAX_REPLAY_ATTEMPTS = 5;
const MAX_REQUEST_AGE_MS = 7 * 24 * 60 * 60 * 1000;

let openDatabasePromise = null;

function openDatabase() {
    if (typeof indexedDB === 'undefined') {
        return Promise.reject(new Error('IndexedDB is not supported in this environment.'));
    }

    if (openDatabasePromise) {
        return openDatabasePromise;
    }

    openDatabasePromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 2);

        request.onupgradeneeded = event => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { autoIncrement: true });
            }
            if (!db.objectStoreNames.contains(CRYPTO_STORE_NAME)) {
                db.createObjectStore(CRYPTO_STORE_NAME, { keyPath: 'id' });
            }
        };

        request.onsuccess = () => {
            const db = request.result;
            db.onversionchange = () => {
                db.close();
                openDatabasePromise = null;
            };
            resolve(db);
        };

        request.onerror = () => {
            openDatabasePromise = null;
            reject(request.error || new Error('Failed to open IndexedDB.'));
        };

        request.onblocked = () => {
            console.warn('ServiceWorker: IndexedDB open request blocked.');
        };
    });

    return openDatabasePromise;
}

const OFFLINE_QUEUE_HEADER = 'x-offline-queue';
const OFFLINE_QUEUE_HEADER_VALUE = 'allow';
const OFFLINE_QUEUE_REFERENCE_HEADER = 'x-offline-queue-ref';
const OFFLINE_QUEUE_ALLOWED_PATHS = [
    '/PaginasSolicitacoes/',
    '/PaginasAreaClienteSessaoPerfil/',
    '/PaginasConfiguradoresSeletoresConsultas/'
];
const SENSITIVE_HEADERS = ['authorization', 'cookie', 'set-cookie'];

function isAllowListedPath(requestUrl) {
    try {
        const { pathname } = new URL(requestUrl, self.location.origin);
        return OFFLINE_QUEUE_ALLOWED_PATHS.some(path => pathname.startsWith(path));
    } catch (error) {
        console.warn('ServiceWorker: failed to parse url while validating offline queue.', error);
        return false;
    }
}

function isBodyPersistable(request, body) {
    if (!body) {
        return false;
    }
    try {
        const contentType = request.headers.get('Content-Type') || '';
        if (contentType.includes('application/json')) {
            const parsed = JSON.parse(body);
            return !containsSensitiveKeys(parsed);
        }
    } catch (err) {
        return false;
    }
    return false;
}

function isRequestAllowedToPersist(request, body) {
    const headerValue = request.headers.get(OFFLINE_QUEUE_HEADER);
    if (!headerValue || headerValue.toLowerCase() !== OFFLINE_QUEUE_HEADER_VALUE) {
        return false;
    }

    if (!isAllowListedPath(request.url)) {
        return false;
    }

    const hasReferenceHeader = Boolean(request.headers.get(OFFLINE_QUEUE_REFERENCE_HEADER));
    if (hasReferenceHeader) {
        return true;
    }

    return isBodyPersistable(request, body);
}

function containsSensitiveKeys(payload) {
    const sensitiveKeys = ['password', 'senha', 'cpf', 'cnpj', 'token', 'secret', 'authorization'];

    if (payload && typeof payload === 'object') {
        return Object.keys(payload).some(key => {
            const lowerKey = key.toLowerCase();
            if (sensitiveKeys.includes(lowerKey)) {
                return true;
            }
            const value = payload[key];
            if (value && typeof value === 'object') {
                return containsSensitiveKeys(value);
            }
            if (typeof value === 'string') {
                return containsSensitiveValues(value);
            }
            return false;
        });
    }

    return false;
}

function containsSensitiveValues(value) {
    const sensitiveMarkers = ['senha', 'password', 'cpf', 'cnpj', 'token', 'secret'];
    const lowerValue = value.toLowerCase();
    return sensitiveMarkers.some(marker => lowerValue.includes(marker));
}

let encryptionContextPromise = null;

function createQueueClearedError(message) {
    const error = new Error(message);
    error.queueCleared = true;
    return error;
}

async function readFromStore(db, storeName, key) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, 'readonly');
        const store = tx.objectStore(storeName);
        const request = store.get(key);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function writeToStore(db, storeName, records) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, 'readwrite');
        const store = tx.objectStore(storeName);
        records.forEach(record => store.put(record));
        tx.oncomplete = () => resolve();
        tx.onabort = () => reject(tx.error || new Error('Transaction aborted'));
        tx.onerror = () => reject(tx.error);
    });
}

async function clearQueuedRequests(reason) {
    const db = await openDatabase();
    await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        store.clear();
        tx.oncomplete = () => resolve();
        tx.onabort = () => reject(tx.error || new Error('Transaction aborted'));
        tx.onerror = () => reject(tx.error);
    });
    console.warn('ServiceWorker: offline queue cleared.', { reason });
}

async function generateNewKey(version) {
    const key = await self.crypto.subtle.generateKey(
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
    );
    return {
        key,
        version
    };
}

async function storeActiveKey(db, version, key) {
    const now = Date.now();
    await writeToStore(db, CRYPTO_STORE_NAME, [
        { id: ACTIVE_KEY_ID, version, updatedAt: now },
        { id: `${KEY_ID_PREFIX}${version}`, version, key, createdAt: now }
    ]);
}

async function loadActiveKeyRecord(db) {
    return readFromStore(db, CRYPTO_STORE_NAME, ACTIVE_KEY_ID);
}

async function loadKeyByVersion(db, version) {
    if (!version) {
        return null;
    }
    const record = await readFromStore(db, CRYPTO_STORE_NAME, `${KEY_ID_PREFIX}${version}`);
    return record ? record.key : null;
}

async function getActiveEncryptionContext() {
    if (!self.crypto || !self.crypto.subtle) {
        throw new Error('ServiceWorker: Web Crypto API is not available.');
    }
    if (encryptionContextPromise) {
        return encryptionContextPromise;
    }
    encryptionContextPromise = (async () => {
        const db = await openDatabase();
        const activeRecord = await loadActiveKeyRecord(db);
        if (activeRecord && activeRecord.version) {
            const key = await loadKeyByVersion(db, activeRecord.version);
            if (key) {
                return { key, version: activeRecord.version };
            }
            await clearQueuedRequests('missing encryption key material');
            const nextVersion = Number(activeRecord.version) + 1;
            const generated = await generateNewKey(nextVersion);
            await storeActiveKey(db, generated.version, generated.key);
            return { key: generated.key, version: generated.version };
        }
        const generated = await generateNewKey(1);
        await storeActiveKey(db, generated.version, generated.key);
        return { key: generated.key, version: generated.version };
    })();
    return encryptionContextPromise;
}

async function rotateEncryptionKey(reason) {
    const db = await openDatabase();
    const activeRecord = await loadActiveKeyRecord(db);
    const currentVersion = activeRecord && activeRecord.version ? Number(activeRecord.version) : 0;
    const newVersion = currentVersion + 1;
    const generated = await generateNewKey(newVersion);
    await storeActiveKey(db, generated.version, generated.key);
    encryptionContextPromise = Promise.resolve({ key: generated.key, version: generated.version });
    if (currentVersion > 0) {
        await migrateQueuedRequests(currentVersion, newVersion, reason || 'rotation');
    }
}

function arrayBufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
}

function base64ToArrayBuffer(base64) {
    const binaryString = atob(base64);
    const bytes = new Uint8Array(binaryString.length);
    for (let i = 0; i < binaryString.length; i++) {
        bytes[i] = binaryString.charCodeAt(i);
    }
    return bytes.buffer;
}

async function encryptRequestBody(body, key) {
    if (!body) {
        return null;
    }
    const encoder = new TextEncoder();
    const data = encoder.encode(body);
    const iv = self.crypto.getRandomValues(new Uint8Array(12));
    const encrypted = await self.crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, data);
    return `${arrayBufferToBase64(iv.buffer)}:${arrayBufferToBase64(encrypted)}`;
}

async function decryptRequestBody(persistedBody, key) {
    if (!persistedBody) {
        return null;
    }
    const [ivPart, payloadPart] = persistedBody.split(':');
    if (!ivPart || !payloadPart) {
        throw new Error('Invalid persisted payload format.');
    }
    const iv = new Uint8Array(base64ToArrayBuffer(ivPart));
    const payload = base64ToArrayBuffer(payloadPart);
    const decrypted = await self.crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, payload);
    const decoder = new TextDecoder();
    return decoder.decode(decrypted);
}
function promisifyRequest(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function createTransactionCompletionPromise(tx) {
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onabort = () => reject(tx.error || new Error('Transaction aborted'));
        tx.onerror = () => reject(tx.error);
    });
}

async function getAllKeysFromStore(store) {
    if (typeof store.getAllKeys === 'function') {
        return promisifyRequest(store.getAllKeys());
    }

    return new Promise((resolve, reject) => {
        const keys = [];
        const cursorRequest = typeof store.openKeyCursor === 'function' ? store.openKeyCursor() : store.openCursor();
        cursorRequest.onsuccess = event => {
            const cursor = event.target.result;
            if (!cursor) {
                resolve(keys);
                return;
            }
            keys.push(cursor.primaryKey);
            cursor.continue();
        };
        cursorRequest.onerror = () => reject(cursorRequest.error);
    });
}

async function buildQueuedRequestInit(record) {
    const headers = new Headers(record.headers || []);
    const metadata = record.metadata || {};
    let body = record.body;

    if (metadata.bodyEncoding === 'reference' && metadata.payloadReference) {
        headers.set(OFFLINE_QUEUE_REFERENCE_HEADER, metadata.payloadReference);
        body = null;
    } else if (metadata.bodyEncoding === 'aes-gcm' && record.body) {
        const version = metadata.keyVersion;
        const db = await openDatabase();
        const key = await loadKeyByVersion(db, version);
        if (!key) {
            await clearQueuedRequests('missing encryption key for queued payload');
            throw createQueueClearedError('Missing encryption key for queued payload.');
        }
        try {
            body = await decryptRequestBody(record.body, key);
        } catch (error) {
            await clearQueuedRequests('decryption failed for queued payload');
            throw createQueueClearedError('Unable to decrypt queued payload.');
        }
    }

    return { headers, body };
}

async function createPersistedBodyPayload(request, rawBody) {
    const referenceHeader = request.headers.get(OFFLINE_QUEUE_REFERENCE_HEADER);
    if (referenceHeader) {
        return {
            body: null,
            metadata: {
                bodyEncoding: 'reference',
                payloadReference: referenceHeader.trim()
            }
        };
    }

    const context = await getActiveEncryptionContext();
    const encryptedBody = await encryptRequestBody(rawBody, context.key);
    return {
        body: encryptedBody,
        metadata: encryptedBody ? { bodyEncoding: 'aes-gcm', keyVersion: context.version } : {}
    };
}

async function savePostRequest(request) {
    const db = await openDatabase();
    const tx = db.transaction(STORE_NAME, 'readwrite');
    const store = tx.objectStore(STORE_NAME);
    const completionPromise = createTransactionCompletionPromise(tx);
    const requestClone = request.clone();
    const body = await requestClone.text();

    if (!isRequestAllowedToPersist(request, body)) {
        throw new Error('Request contains sensitive information and cannot be queued for offline replay.');
    }

    const { body: persistedBody, metadata } = await createPersistedBodyPayload(request, body);

    const headers = [];
    for (const [key, value] of request.headers.entries()) {
        if (!SENSITIVE_HEADERS.includes(key.toLowerCase())) {
            headers.push([key, value]);
        }
    }

    const keys = await getAllKeysFromStore(store);
    const trimCount = Math.max(0, keys.length + 1 - MAX_QUEUE_LENGTH);
    if (trimCount > 0) {
        const keysToDelete = keys.slice().sort((a, b) => a - b).slice(0, trimCount);
        keysToDelete.forEach(key => store.delete(key));
        console.warn('ServiceWorker: trimming offline queue before storing new request.', {
            removed: trimCount,
            limit: MAX_QUEUE_LENGTH
        });
    }

    store.add({
        url: request.url,
        method: request.method,
        headers,
        body: persistedBody,
        metadata,
        createdAt: Date.now(),
        attempts: 0,
        lastAttemptAt: null
    });

    return completionPromise.then(() => {
        const currentSize = Math.min(MAX_QUEUE_LENGTH, keys.length - trimCount + 1);
    });
}

async function listQueuedRequests() {
    const db = await openDatabase();
    const tx = db.transaction(STORE_NAME, 'readonly');
    const store = tx.objectStore(STORE_NAME);
    const getAll = (req) => new Promise((res, rej) => { req.onsuccess = () => res(req.result); req.onerror = () => rej(req.error); });
    const requests = await getAll(store.getAll());
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve(requests);
        tx.onerror = () => reject(tx.error);
    });
}

async function replayQueuedRequests() {
    const db = await openDatabase();
    const readTx = db.transaction(STORE_NAME, 'readonly');
    const readStore = readTx.objectStore(STORE_NAME);
    const completionPromise = createTransactionCompletionPromise(readTx);
    const requestsPromise = promisifyRequest(readStore.getAll());
    const keysPromise = getAllKeysFromStore(readStore);
    const [requests, keys] = await Promise.all([requestsPromise, keysPromise]);
    await completionPromise;

    if (!requests.length) {
        return;
    }

    const now = Date.now();
    const keysToDelete = [];
    const updates = [];

    for (let i = 0; i < requests.length; i++) {
        const record = requests[i];
        const key = keys[i];
        if (!record) {
            continue;
        }

        const createdAt = typeof record.createdAt === 'number' ? record.createdAt : 0;
        const attempts = typeof record.attempts === 'number' ? record.attempts : 0;
        const age = now - createdAt;

        if (age > MAX_REQUEST_AGE_MS) {
            keysToDelete.push(key);
            console.warn('ServiceWorker: dropping stale offline request.', {
                url: record.url,
                age
            });
            continue;
        }

        if (attempts >= MAX_REPLAY_ATTEMPTS) {
            keysToDelete.push(key);
            console.warn('ServiceWorker: dropping offline request after too many attempts.', {
                url: record.url,
                attempts
            });
            continue;
        }

        try {
            const requestInit = await buildQueuedRequestInit(record);
            await fetch(record.url, {
                method: record.method,
                headers: requestInit.headers,
                body: record.method && record.method.toUpperCase() === 'GET' ? undefined : requestInit.body
            });
            keysToDelete.push(key);
        } catch (error) {
            if (error && error.queueCleared) {
                return;
            }
            const updatedAttempts = attempts + 1;
            const lastError = error && (error.message || String(error));
            if (updatedAttempts >= MAX_REPLAY_ATTEMPTS) {
                keysToDelete.push(key);
                console.warn('ServiceWorker: removing offline request after repeated failures.', {
                    url: record.url,
                    attempts: updatedAttempts,
                    error: lastError
                });
            } else {
                updates.push({
                    key,
                    record: {
                        ...record,
                        attempts: updatedAttempts,
                        lastAttemptAt: now,
                        lastError: lastError ? lastError.slice(0, 500) : undefined
                    }
                });
                console.warn('ServiceWorker: offline request replay failed, will retry later.', {
                    url: record.url,
                    attempts: updatedAttempts,
                    error: lastError
                });
            }
        }
    }

    if (!keysToDelete.length && !updates.length) {
        return;
    }

    await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        updates.forEach(({ key, record }) => store.put(record, key));
        keysToDelete.forEach(key => store.delete(key));
        tx.oncomplete = () => resolve();
        tx.onabort = () => reject(tx.error || new Error('Transaction aborted'));
        tx.onerror = () => reject(tx.error);
    });
}

async function migrateQueuedRequests(oldVersion, newVersion, reason) {
    const db = await openDatabase();
    const oldKey = await loadKeyByVersion(db, oldVersion);
    if (!oldKey) {
        await clearQueuedRequests('missing key during migration');
        return;
    }
    const newKey = await loadKeyByVersion(db, newVersion);
    if (!newKey) {
        await clearQueuedRequests('missing new key during migration');
        return;
    }

    const readTx = db.transaction(STORE_NAME, 'readonly');
    const readStore = readTx.objectStore(STORE_NAME);
    const requests = await promisifyRequest(readStore.getAll());
    const keys = await getAllKeysFromStore(readStore);
    await createTransactionCompletionPromise(readTx);

    const updates = [];
    for (let i = 0; i < requests.length; i++) {
        const record = requests[i];
        const key = keys[i];
        if (!record) {
            continue;
        }
        if (!record.body || !record.metadata || record.metadata.bodyEncoding !== 'aes-gcm') {
            if (record.metadata) {
                updates.push({
                    key,
                    record: {
                        ...record,
                        metadata: { ...record.metadata, keyVersion: newVersion }
                    }
                });
            }
            continue;
        }
        try {
            const decrypted = await decryptRequestBody(record.body, oldKey);
            const reencrypted = await encryptRequestBody(decrypted, newKey);
            updates.push({
                key,
                record: {
                    ...record,
                    body: reencrypted,
                    metadata: { ...record.metadata, keyVersion: newVersion }
                }
            });
        } catch (error) {
            await clearQueuedRequests('failed to migrate queued payloads');
            throw error;
        }
    }

    if (updates.length) {
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            updates.forEach(({ key, record }) => store.put(record, key));
            tx.oncomplete = () => resolve();
            tx.onabort = () => reject(tx.error || new Error('Transaction aborted'));
            tx.onerror = () => reject(tx.error);
        });
    }
}

self.addEventListener('install', event => {
    event.waitUntil((async () => {
        const appVersion = await ensureResolvedVersion();
        await broadcastMessage({
            type: 'sw:installed',
            version: appVersion,
            cacheVersion: CACHE_VERSION,
            action: self.registration && self.registration.active ? 'waiting' : 'skipWaiting'
        });
    })());
    if (!(self.registration && self.registration.active)) {
        self.skipWaiting();
    }
});

async function broadcastMessage(message) {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    clients.forEach(client => client.postMessage(message));
}

function createMessageResponder(event) {
    if (event.source && typeof event.source.postMessage === 'function') {
        return message => event.source.postMessage(message);
    }
    const [port] = event.ports || [];
    if (port && typeof port.postMessage === 'function') {
        return message => port.postMessage(message);
    }
    return null;
}

function safelySendResponse(responder, message) {
    if (!responder) {
        return false;
    }
    try {
        responder(message);
        return true;
    } catch (err) {
        console.warn('Service worker message response failed:', err);
        return false;
    }
}

self.addEventListener('message', event => {
    const data = event.data;
    const responder = createMessageResponder(event);

    if (data && (data === 'skipWaiting' || data.type === 'skipWaiting')) {
        event.waitUntil((async () => {
            try {
                self.skipWaiting();
                safelySendResponse(responder, { type: 'skipWaitingAck', success: true });
            } catch (err) {
                safelySendResponse(responder, { type: 'skipWaitingAck', success: false, error: err.message });
            }
        })());
    } else if (data && data.type === 'rotateEncryptionKey') {
        event.waitUntil((async () => {
            try {
                await rotateEncryptionKey(data.reason || 'manual');
                safelySendResponse(responder, { type: 'rotateEncryptionKeyAck', success: true });
            } catch (err) {
                safelySendResponse(responder, { type: 'rotateEncryptionKeyAck', success: false, error: err.message });
            }
        })());
    } else if (data && data.type === 'getQueuedRequests') {
        if (!responder) {
            return;
        }
        event.waitUntil(
            (async () => {
                try {
                    const requests = await listQueuedRequests();
                    safelySendResponse(responder, { type: 'queuedRequests', requests });
                } catch (err) {
                    safelySendResponse(responder, { type: 'queuedRequests', requests: [], error: err.message });
                }
            })()
        );
    }
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        if ('navigationPreload' in self.registration) {
            try {
                await self.registration.navigationPreload.disable();
            } catch (error) {
                console.warn('ServiceWorker: failed to disable navigation preload.', error);
            }
        }
        const expectedCaches = new Set(Object.values(RUNTIME_CACHE_NAMES));
        if (self.workbox && self.workbox.core && self.workbox.core.cacheNames) {
            expectedCaches.add(self.workbox.core.cacheNames.precache);
            expectedCaches.add(self.workbox.core.cacheNames.runtime);
        }
        const keys = await caches.keys();
        await Promise.all(
            keys
                .filter(key => key.startsWith(CACHE_NAME_PREFIX) && !expectedCaches.has(key))
                .map(key => caches.delete(key))
        );
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        if (clients.length) {
            await self.clients.claim();
        }
        await broadcastMessage({
            type: 'sw:activated',
            version: resolvedAppVersion,
            cacheVersion: CACHE_VERSION,
            action: 'refresh'
        });
    })());
});

self.addEventListener('fetch', event => {
    const { request } = event;
    if (request.method === 'POST') {
        event.respondWith(
            fetchNoStore(request.clone()).catch(() =>
                savePostRequest(request)
                    .then(() => {
                        if ('sync' in self.registration) {
                            self.registration.sync.register('sync-requests').catch(() => { });
                        }
                        self.clients.matchAll({ type: 'window' }).then(clients => {
                            clients.forEach(c => c.postMessage({ type: 'offline-request', url: request.url }));
                        });
                        return new Response(JSON.stringify({ offline: true, queued: true }), {
                            headers: { 'Content-Type': 'application/json' }
                        });
                    })
                    .catch(() => new Response(JSON.stringify({ offline: true, queued: false }), {
                        headers: { 'Content-Type': 'application/json' },
                        status: 503
                    }))
            )
        );
    }
});

self.addEventListener('sync', event => {
    if (event.tag === 'sync-requests') {
        event.waitUntil(replayQueuedRequests());
    }
});

self.addEventListener('periodicsync', event => {
    if (event.tag === 'update-content') {
        event.waitUntil((async () => {
            try {
                const response = await fetch('/');
                if (response && response.ok) {
                    const cache = await caches.open(RUNTIME_CACHE_NAMES.pages);
                    await cache.put('/', response.clone());
                }
            } catch (error) {
                // Silent failure: background update is optional.
            }
        })());
    }
});
