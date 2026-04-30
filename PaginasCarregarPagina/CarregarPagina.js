const VERSION_CONFIG_URL = '/Versionamento/Versao.json';
const FALLBACK_APP_VERSION = 'versao.desconhecida';

let cachedAppVersion = null;
let resolvingAppVersionPromise = null;

function normalizeVersionCandidate(candidate) {
    if (typeof candidate !== 'string') {
        return null;
    }

    const trimmed = candidate.trim();
    return trimmed ? trimmed : null;
}

function extractVersionFromPayload(payload) {
    if (!payload) {
        return null;
    }

    try {
        const data = JSON.parse(payload);
        const candidate = data && (data.version || data.appVersion);
        return normalizeVersionCandidate(candidate);
    } catch (parseError) {
        console.warn('APP_VERSION: invalid JSON in version config.', parseError);
    }

    return null;
}

function ensureGlobalAppVersion(version, source) {
    if (typeof window !== 'undefined') {
        window.APP_VERSION = version;
        if (source) {
            window.__APP_VERSION_SOURCE__ = source;
        }
        if (typeof window.getAppVersion !== 'function') {
            window.getAppVersion = function getAppVersion() {
                return window.APP_VERSION;
            };
        }
    } else if (typeof globalThis !== 'undefined') {
        globalThis.APP_VERSION = version;
    }
}

function loadAppVersionFromConfig() {
    if (typeof window === 'undefined') {
        if (typeof globalThis !== 'undefined' && typeof globalThis.resolveAppVersionFromConfigSync === 'function') {
            try {
                return Promise.resolve(globalThis.resolveAppVersionFromConfigSync());
            } catch (error) {
                console.warn('APP_VERSION: error resolving version from global resolver.', error);
            }
        }
        return Promise.resolve(null);
    }

    if (typeof window.resolveAppVersionFromConfigSync === 'function') {
        try {
            const resolved = window.resolveAppVersionFromConfigSync();
            if (resolved) {
                return Promise.resolve(resolved);
            }
        } catch (error) {
            console.warn('APP_VERSION: error calling window.resolveAppVersionFromConfigSync.', error);
        }
    }

    const useFetch = typeof window.fetch === 'function';
    if (useFetch) {
        return window.fetch(VERSION_CONFIG_URL, {
            headers: { Accept: 'application/json' },
            cache: 'no-cache'
        })
            .then((response) => {
                if (!response.ok) {
                    console.warn('APP_VERSION: failed to load version config.', response.status, response.statusText);
                    return null;
                }
                return response.text();
            })
            .then((payload) => extractVersionFromPayload(payload))
            .catch((error) => {
                console.warn('APP_VERSION: error loading version config.', error);
                return null;
            });
    }

    if (typeof window.XMLHttpRequest === 'function') {
        return new Promise((resolve) => {
            try {
                const request = new window.XMLHttpRequest();
                request.open('GET', VERSION_CONFIG_URL, true);
                request.setRequestHeader('Accept', 'application/json');
                request.onreadystatechange = function handleReadyStateChange() {
                    if (request.readyState !== 4) {
                        return;
                    }

                    if (request.status >= 200 && request.status < 300) {
                        resolve(extractVersionFromPayload(request.responseText));
                    } else {
                        console.warn('APP_VERSION: failed to load version config.', request.status, request.statusText);
                        resolve(null);
                    }
                };
                request.onerror = function handleRequestError(event) {
                    console.warn('APP_VERSION: error loading version config.', event);
                    resolve(null);
                };
                request.send(null);
            } catch (error) {
                console.warn('APP_VERSION: error initializing version request.', error);
                resolve(null);
            }
        });
    }

    return Promise.resolve(null);
}

function resolveAppVersion() {
    if (cachedAppVersion) {
        return Promise.resolve(cachedAppVersion);
    }

    if (typeof window === 'undefined') {
        if (typeof globalThis !== 'undefined') {
            const fromGlobal = normalizeVersionCandidate(globalThis.APP_VERSION);
            if (fromGlobal) {
                cachedAppVersion = fromGlobal;
                return Promise.resolve(fromGlobal);
            }

            if (typeof globalThis.resolveAppVersionFromConfigSync === 'function') {
                try {
                    const resolved = normalizeVersionCandidate(globalThis.resolveAppVersionFromConfigSync());
                    if (resolved) {
                        cachedAppVersion = resolved;
                        return Promise.resolve(resolved);
                    }
                } catch (error) {
                    console.warn('APP_VERSION: error resolving version from global resolver.', error);
                }
            }
        }

        cachedAppVersion = FALLBACK_APP_VERSION;
        return Promise.resolve(FALLBACK_APP_VERSION);
    }

    const existing = normalizeVersionCandidate(window.APP_VERSION);
    const existingSource = typeof window.__APP_VERSION_SOURCE__ === 'string' ? window.__APP_VERSION_SOURCE__ : null;
    const canReuseExisting = existing && (!existingSource || (existingSource !== 'bootstrap' && existingSource !== 'fallback-pending'));

    if (canReuseExisting) {
        cachedAppVersion = existing;
        ensureGlobalAppVersion(existing, existingSource || 'preloaded');
        return Promise.resolve(existing);
    }

    if (!resolvingAppVersionPromise) {
        ensureGlobalAppVersion(FALLBACK_APP_VERSION, 'fallback-pending');

        resolvingAppVersionPromise = loadAppVersionFromConfig()
            .then((loadedVersion) => {
                const normalizedLoaded = normalizeVersionCandidate(loadedVersion);
                const versionToUse = normalizedLoaded || FALLBACK_APP_VERSION;
                const source = normalizedLoaded ? 'versao.json' : 'fallback';
                cachedAppVersion = versionToUse;
                ensureGlobalAppVersion(versionToUse, source);
                return versionToUse;
            })
            .catch((error) => {
                console.warn('APP_VERSION: error resolving version.', error);
                cachedAppVersion = FALLBACK_APP_VERSION;
                ensureGlobalAppVersion(FALLBACK_APP_VERSION, 'fallback-error');
                return FALLBACK_APP_VERSION;
            });
    }

    return resolvingAppVersionPromise;
}

const hasWindow = typeof window !== 'undefined';

const preloadedAppVersion = normalizeVersionCandidate(
    hasWindow ? window.APP_VERSION : (typeof globalThis !== 'undefined' ? globalThis.APP_VERSION : null)
);

const initialAppVersion = preloadedAppVersion || FALLBACK_APP_VERSION;

const initialSource = preloadedAppVersion
    ? (hasWindow ? window.__APP_VERSION_SOURCE__ || 'preloaded' : 'preloaded')
    : 'bootstrap';

ensureGlobalAppVersion(initialAppVersion, initialSource);

var APP_VERSION = initialAppVersion;

resolveAppVersion()
    .then((resolvedVersion) => {
        APP_VERSION = resolvedVersion;
    })
    .catch((error) => {
        console.warn('APP_VERSION: error finalizing async version resolution.', error);
    });

function ensureInitialLoaderFallback() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    var root = document.documentElement;
    if (!root) {
        return;
    }

    var LOADER_ID = 'loader';
    var STYLE_ATTR = 'data-initial-loader-style';
    var BG_ATTR = 'data-initial-loader-bg';
    var ARIA_ATTR = 'data-initial-loader-aria';

    function applyRootLoadingState() {
        if (!root) {
            return;
        }

        if (!root.hasAttribute(BG_ATTR)) {
            root.setAttribute(BG_ATTR, root.style.backgroundColor || '');
        }

        if (!root.hasAttribute(ARIA_ATTR)) {
            var previousAria = root.getAttribute('aria-busy');
            root.setAttribute(ARIA_ATTR, previousAria == null ? '' : previousAria);
        }

        root.style.backgroundColor = '#ec4115';
        root.classList.add('is-initial-loading');
        root.setAttribute('data-loading', 'true');
        root.setAttribute('aria-busy', 'true');
    }

    function ensureLoaderStyles() {
        var head = document.head || document.getElementsByTagName('head')[0];
        if (!head || head.querySelector('style[' + STYLE_ATTR + "='true']")) {
            return;
        }

        var style = document.createElement('style');
        style.setAttribute(STYLE_ATTR, 'true');
        style.textContent = [
            '#loader{position:fixed;z-index:1000000;inset:0;display:flex;align-items:center;justify-content:center;background:#ec4115;}',
            '#loader .spinner{width:40px;height:40px;background-color:#fff;animation:loader-rotate 1.2s infinite ease-in-out;}',
            '@keyframes loader-rotate{0%{transform:perspective(120px) rotateX(0deg) rotateY(0deg);}50%{transform:perspective(120px) rotateX(-180.1deg) rotateY(0deg);}100%{transform:perspective(120px) rotateX(-180deg) rotateY(-179.9deg);}}',
            '@-webkit-keyframes loader-rotate{0%{-webkit-transform:perspective(120px) rotateX(0deg) rotateY(0deg);}50%{-webkit-transform:perspective(120px) rotateX(-180.1deg) rotateY(0deg);}100%{-webkit-transform:perspective(120px) rotateX(-180deg) rotateY(-179.9deg);}}'
        ].join('');
        head.appendChild(style);
    }

    function createLoader() {
        if (document.getElementById(LOADER_ID)) {
            return;
        }

        var loader = document.createElement('div');
        loader.id = LOADER_ID;
        loader.className = 'loader initial-loader';
        loader.setAttribute('role', 'status');
        loader.setAttribute('aria-live', 'polite');
        loader.setAttribute('aria-busy', 'true');
        loader.dataset.initialLoader = 'true';
        loader.style.cssText = [
            'position:fixed',
            'z-index:1000000',
            'inset:0',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'background:#ec4115'
        ].join(';');

        var spinner = document.createElement('div');
        spinner.className = 'spinner';
        spinner.setAttribute('aria-hidden', 'true');
        spinner.style.cssText = [
            'width:40px',
            'height:40px',
            'background-color:#fff',
            'animation:loader-rotate 1.2s infinite ease-in-out'
        ].join(';');

        loader.appendChild(spinner);

        if (document.body) {
            document.body.appendChild(loader);
        } else {
            document.addEventListener('DOMContentLoaded', function handleReady() {
                document.removeEventListener('DOMContentLoaded', handleReady);
                if (!document.getElementById(LOADER_ID)) {
                    document.body.appendChild(loader);
                }
            });
        }
    }

    applyRootLoadingState();
    ensureLoaderStyles();
    createLoader();
}

const ensureInitialLoader =
    (typeof window !== 'undefined' && typeof window.ensureInitialLoader === 'function')
        ? window.ensureInitialLoader
        : function ensureInitialLoaderFallbackWrapper() {
            ensureInitialLoaderFallback();
        };

if (typeof window !== 'undefined' && typeof window.ensureInitialLoader !== 'function') {
    window.ensureInitialLoader = ensureInitialLoader;
}

ensureInitialLoader();

const originalFetch = window.fetch.bind(window);

const DEFAULT_IDLE_TIMEOUT = 600;

function isDocumentHidden() {
    return typeof document !== 'undefined' && document.visibilityState === 'hidden';
}

function runWhenIdle(callback, timeout = DEFAULT_IDLE_TIMEOUT) {
    if (typeof callback !== 'function') {
        return null;
    }

    if (!isDocumentHidden() && typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
        return window.requestIdleCallback((deadline) => {
            try {
                const result = callback(deadline);
                if (result && typeof result.then === 'function') {
                    result.catch(err => console.error('Idle task failed:', err));
                }
            } catch (err) {
                console.error('Idle task failed:', err);
            }
        }, { timeout });
    }

    return window.setTimeout(() => {
        try {
            const result = callback({ didTimeout: true, timeRemaining: () => 0 });
            if (result && typeof result.then === 'function') {
                result.catch(err => console.error('Timeout idle task failed:', err));
            }
        } catch (err) {
            console.error('Timeout idle task failed:', err);
        }
    }, Math.min(timeout, 50));
}

function waitForIdle(timeout = DEFAULT_IDLE_TIMEOUT) {
    if (isDocumentHidden()) {
        return Promise.resolve();
    }
    if (typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
        return new Promise(resolve => window.requestIdleCallback(() => resolve(), { timeout }));
    }
    return new Promise(resolve => window.setTimeout(resolve, 16));
}

async function yieldToMainThread(timeout = 120) {
    await waitForIdle(timeout);
}

const DOM_PARSER = typeof DOMParser !== 'undefined' ? new DOMParser() : null;


function legacyParseHTMLDocument(markup) {
    if (typeof document === 'undefined' || !document.implementation ||
        typeof document.implementation.createHTMLDocument !== 'function') {
        return null;
    }

    try {
        const doc = document.implementation.createHTMLDocument('');
        doc.open();
        doc.write(markup);
        doc.close();
        return doc;
    } catch {
        return null;
    }
}

function parseHTMLDocument(markup) {
    const fallbackMarkup = '<!DOCTYPE html><html><head></head><body></body></html>';
    const content = markup || fallbackMarkup;

    if (DOM_PARSER) {
        try {
            return DOM_PARSER.parseFromString(content, 'text/html');
        } catch {
            // fallback handled below
        }
    }

    const legacyDoc = legacyParseHTMLDocument(content);
    if (legacyDoc) {
        return legacyDoc;
    }

    if (DOM_PARSER) {
        try {
            return DOM_PARSER.parseFromString(fallbackMarkup, 'text/html');
        } catch { }
    }

    const fallbackDoc = legacyParseHTMLDocument(fallbackMarkup);
    return fallbackDoc || null;
}

function safeLocalStorageGetItem(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function safeLocalStorageSetItem(key, value) {
    try {
        localStorage.setItem(key, value);
        return true;
    } catch {
        return false;
    }
}

function safeLocalStorageRemoveItem(key) {
    try {
        localStorage.removeItem(key);
    } catch { }
}

const themeCookieName = (typeof window !== "undefined" && window.THEME_COOKIE_NAME) || "modoEscuro";
const themeCookieMaxAge = (typeof window !== "undefined" && typeof window.THEME_COOKIE_MAX_AGE === "number")
    ? window.THEME_COOKIE_MAX_AGE
    : 60 * 60 * 24 * 365;
const themeCookieDomain = (typeof window !== "undefined" && window.THEME_COOKIE_DOMAIN) || ".redutoresibr.com.br";

if (typeof window !== "undefined") {
    window.THEME_COOKIE_NAME = themeCookieName;
    window.THEME_COOKIE_MAX_AGE = themeCookieMaxAge;
    window.THEME_COOKIE_DOMAIN = themeCookieDomain;
}

function fallbackGetThemeCookie() {
    if (typeof document === 'undefined' || typeof document.cookie !== 'string') {
        return null;
    }

    const cookies = document.cookie.split(';').map(row => row.trim()).filter(Boolean);
    for (const cookie of cookies) {
        if (!cookie.startsWith(`${themeCookieName}=`)) continue;
        const value = cookie.substring(themeCookieName.length + 1);
        try {
            return decodeURIComponent(value);
        } catch {
            return value;
        }
    }
    return null;
}

function fallbackSetThemeCookie(value) {
    if (typeof document === 'undefined') {
        return;
    }

    const encodedValue = encodeURIComponent(value);
    const baseAttributes = `; path=/; max-age=${themeCookieMaxAge}; SameSite=Lax`;
    const domainCookie = `${themeCookieName}=${encodedValue}${baseAttributes}; domain=${themeCookieDomain}`;

    try {
        document.cookie = domainCookie;
        if (fallbackGetThemeCookie() !== value) {
            document.cookie = `${themeCookieName}=${encodedValue}${baseAttributes}`;
        }
    } catch {
        document.cookie = `${themeCookieName}=${encodedValue}${baseAttributes}`;
    }
}

const getThemeCookieValue =
    typeof window !== "undefined" && typeof window.getThemeCookie === "function"
        ? window.getThemeCookie
        : fallbackGetThemeCookie;

const setThemeCookieValue =
    typeof window !== "undefined" && typeof window.setThemeCookie === "function"
        ? window.setThemeCookie
        : fallbackSetThemeCookie;

function prefersSystemDarkMode() {
    const target = typeof window !== "undefined" && typeof window.matchMedia === "function"
        ? window
        : typeof globalThis !== "undefined" && typeof globalThis.matchMedia === "function"
            ? globalThis
            : null;
    if (target) {
        try {
            return target.matchMedia("(prefers-color-scheme: dark)").matches;
        } catch { }
    }
    return false;
}

if (typeof window !== "undefined") {
    if (typeof window.getThemeCookie !== "function") {
        window.getThemeCookie = getThemeCookieValue;
    }
    if (typeof window.setThemeCookie !== "function") {
        window.setThemeCookie = setThemeCookieValue;
    }
}

(function setupBootstrapFallback() {
    if (Object.prototype.hasOwnProperty.call(window, 'bootstrap')) {
        const existing = window.bootstrap;
        if (existing) {
            try {
                document.dispatchEvent(new CustomEvent('bootstrap:ready', { detail: existing }));
            } catch { }
            return;
        }
        delete window.bootstrap;
    }

    let actualBootstrap = null;
    const popoverInstances = new WeakMap();

    class NoopPopover {
        constructor(element) {
            this._element = element;
        }
        static getOrCreateInstance(element) {
            if (!popoverInstances.has(element)) {
                popoverInstances.set(element, new NoopPopover(element));
            }
            return popoverInstances.get(element);
        }
        static getInstance(element) {
            return popoverInstances.get(element) || null;
        }
        setContent() { }
        hide() { }
        show() { }
        toggle() { }
        dispose() {
            if (this._element) {
                popoverInstances.delete(this._element);
            }
        }
    }

    class NoopTooltip {
        constructor(element) {
            this._element = element;
        }
        static getOrCreateInstance(element) {
            return new NoopTooltip(element);
        }
        static getInstance() {
            return null;
        }
        hide() { }
        show() { }
        dispose() { }
    }

    const fallbackBootstrap = {
        Popover: NoopPopover,
        Tooltip: NoopTooltip,
        __isFallback: true
    };

    const notifyReady = (value) => {
        try {
            document.dispatchEvent(new CustomEvent('bootstrap:ready', { detail: value }));
        } catch { }
    };

    Object.defineProperty(window, 'bootstrap', {
        configurable: true,
        enumerable: true,
        get() {
            return actualBootstrap || fallbackBootstrap;
        },
        set(value) {
            actualBootstrap = value;
            notifyReady(value);
        }
    });
})();


(function iniciarTelemetriaUsoSite() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    if (window.__ENABLE_USAGE_TELEMETRY__ !== true) {
        return;
    }

    const ENDPOINT = '/APIChat/TelemetriaUso.php';
    const dedupeLocal = new Map();

    function obterConsentimentoIA() {
        try {
            if (window.LGPDCOOKIES && typeof window.LGPDCOOKIES.getConsentWithDefaults === 'function') {
                return Boolean(window.LGPDCOOKIES.getConsentWithDefaults().AITraining);
            }

            const match = document.cookie.match(/(?:^|; )lgpd-cookies-consent=([^;]*)/);
            if (!match || !match[1]) {
                return false;
            }
            const parsed = JSON.parse(decodeURIComponent(match[1]));
            return Boolean(parsed && parsed.AITraining);
        } catch {
            return false;
        }
    }

    function enviarEvento(evento, metadados) {
        if (!evento || !obterConsentimentoIA()) {
            return;
        }

        const origem = 'site';
        const payload = {
            evento,
            origem,
            consentimentoIA: true,
            sessaoId: sessionStorage.getItem('api_chat_telemetria_sessao') || '',
            metadados: metadados || {}
        };

        try {
            if (!payload.sessaoId) {
                payload.sessaoId = (window.crypto && typeof window.crypto.randomUUID === 'function')
                    ? window.crypto.randomUUID()
                    : 'sessao-' + Date.now().toString(36) + '-' + Math.random().toString(16).slice(2);
                sessionStorage.setItem('api_chat_telemetria_sessao', payload.sessaoId);
            }
        } catch { }

        const chaveDedupe = JSON.stringify([evento, payload.sessaoId, payload.metadados]);
        const agora = Date.now();
        const ultimo = dedupeLocal.get(chaveDedupe) || 0;
        if (agora - ultimo < 30000) {
            return;
        }
        dedupeLocal.set(chaveDedupe, agora);

        const body = JSON.stringify(payload);
        if (navigator.sendBeacon && body.length < 64000) {
            const blob = new Blob([body], { type: 'application/json' });
            navigator.sendBeacon(ENDPOINT, blob);
            return;
        }

        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body,
            keepalive: true
        }).catch(() => { });
    }

    window.apiChatRegistrarTelemetriaUso = enviarEvento;

    document.addEventListener('change', function (event) {
        const el = event && event.target;
        if (!(el instanceof HTMLSelectElement)) {
            return;
        }

        const selecionado = el.selectedOptions && el.selectedOptions[0]
            ? (el.selectedOptions[0].textContent || '').trim()
            : '';

        enviarEvento('seletor_alterado', {
            pagina: window.location.pathname,
            seletor: el.id || el.name || 'select_sem_identificador',
            valorSelecionado: (el.value || '').toString().slice(0, 80),
            textoSelecionado: selecionado.slice(0, 120),
            totalOpcoes: el.options ? el.options.length : null
        });
    }, true);

    document.addEventListener('click', function (event) {
        const alvo = event && event.target;
        if (!alvo || typeof alvo.closest !== 'function') {
            return;
        }

        const ancora = alvo.closest('a[href]');
        if (!ancora) {
            return;
        }

        const href = ancora.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#') {
            return;
        }

        enviarEvento('navegacao_link', {
            pagina: window.location.pathname,
            destino: href.slice(0, 180),
            produto: (ancora.getAttribute('title') || ancora.textContent || '').trim().slice(0, 120)
        });
    }, true);
})();

(function persistirSeletoresEntrePaginas() {
    const STORAGE_KEY = 'ibr_seletores_persistidos_v1';

    function lerEstado() {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) {
                return {};
            }
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch {
            return {};
        }
    }

    function salvarEstado(estado) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(estado || {}));
        } catch { }
    }

    function obterChaveSeletor(select) {
        if (!(select instanceof HTMLSelectElement)) {
            return '';
        }

        const chaveExplicita = (select.getAttribute('data-persist-key') || '').trim();
        if (chaveExplicita) {
            return chaveExplicita;
        }

        const id = (select.id || '').trim();
        if (id) {
            return 'id:' + id;
        }

        const nome = (select.name || '').trim();
        if (nome) {
            return 'name:' + nome;
        }

        return '';
    }

    function persistirSeletor(select) {
        const chave = obterChaveSeletor(select);
        if (!chave) {
            return;
        }

        const estado = lerEstado();
        const valor = String(select.value || '');

        if (!valor) {
            delete estado[chave];
        } else {
            estado[chave] = valor;
        }

        salvarEstado(estado);
    }

    function restaurarSeletores() {
        const estado = lerEstado();
        const chaves = Object.keys(estado);
        if (!chaves.length) {
            return;
        }

        const seletores = document.querySelectorAll('select');
        seletores.forEach((select) => {
            const chave = obterChaveSeletor(select);
            if (!chave || !(chave in estado)) {
                return;
            }

            const valor = String(estado[chave] || '');
            if (!valor) {
                return;
            }

            const possuiOpcao = Array.from(select.options || []).some((option) => option.value === valor);
            if (!possuiOpcao) {
                return;
            }

            if (select.value !== valor) {
                select.value = valor;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    document.addEventListener('change', function (event) {
        const alvo = event && event.target;
        if (!(alvo instanceof HTMLSelectElement)) {
            return;
        }

        persistirSeletor(alvo);
    }, true);

    function agendarRestauracao() {
        restaurarSeletores();
        setTimeout(restaurarSeletores, 250);
        setTimeout(restaurarSeletores, 1000);
        setTimeout(restaurarSeletores, 2000);
    }

    document.addEventListener('DOMContentLoaded', agendarRestauracao);
    document.addEventListener('page-shell-ready', agendarRestauracao);
    window.addEventListener('load', agendarRestauracao);
})();

document.addEventListener('bootstrap:ready', event => {
    const lib = event?.detail || window.bootstrap;
    if (!lib || !lib.Popover || lib.__isFallback) {
        return;
    }
    document.querySelectorAll('button.help-icon[data-help-text]').forEach(btn => {
        try {
            const pop = lib.Popover.getOrCreateInstance(btn);
            if (typeof pop.setContent === 'function') {
                pop.setContent({
                    '.popover-header': btn.getAttribute('data-bs-title') || '',
                    '.popover-body': btn.getAttribute('data-bs-content') || ''
                });
            }
        } catch { }
    });
});

if ("scrollRestoration" in history) {
    history.scrollRestoration = "manual";
}

function getSavedTheme() {
    const cookieValue = getThemeCookieValue();
    if (cookieValue !== null) {
        return cookieValue;
    }

    let storedValue = null;
    try {
        if (window.LGPDCOOKIES && typeof LGPDCOOKIES.getValueCookie === "function") {
            storedValue = LGPDCOOKIES.getValueCookie(themeCookieName);
        }
    } catch { }

    if (storedValue == null) {
        storedValue = safeLocalStorageGetItem(themeCookieName);
    }

    if (storedValue != null) {
        setThemeCookieValue(storedValue);
    }

    return storedValue;

}

function applySavedTheme(doc) {
    const pref = getSavedTheme();
    doc = doc || document;
    const meta = doc.querySelector('meta[name="color-scheme"]');
    const isDark = pref === "1" || (pref == null && prefersSystemDarkMode());

    doc.documentElement.classList.toggle("dark-mode", isDark);
    if (doc.body) doc.body.classList.toggle("dark-mode", isDark);
    if (meta) meta.setAttribute("content", isDark ? "dark" : "light");
}

if (typeof window !== "undefined") {
    window.themePreference = window.themePreference || {};
    if (typeof window.themePreference.getSavedTheme !== "function") {
        window.themePreference.getSavedTheme = getSavedTheme;
    }
    if (typeof window.themePreference.applySavedTheme !== "function") {
        window.themePreference.applySavedTheme = applySavedTheme;
    }
    if (typeof window.getSavedTheme !== "function") {
        window.getSavedTheme = getSavedTheme;
    }
    if (typeof window.applySavedTheme !== "function") {
        window.applySavedTheme = applySavedTheme;
    }
}

window.addEventListener("pageshow", () => applySavedTheme(document));
async function parseJSON(r) {
    try {
        const text = await r.text();
        return text ? JSON.parse(text) : null;
    } catch {
        return null;
    }
}

function resolveFetchUrl(input) {
    if (typeof input === "string") {
        return input;
    }
    if (input && typeof input.url === "string") {
        return input.url;
    }
    return "";
}

function isSameOriginRequest(input) {
    if (typeof location === "undefined") {
        return false;
    }
    const url = resolveFetchUrl(input);
    if (!url) {
        return false;
    }
    try {
        return new URL(url, location.origin).origin === location.origin;
    } catch {
        return false;
    }
}

function isNetworkError(err) {
    if (!err) {
        return false;
    }
    if (err.name === "TypeError" || err instanceof TypeError) {
        return true;
    }
    const message = err.message || String(err);
    return /Failed to fetch/i.test(message);
}

function isNonCriticalTelemetryRequest(input) {
    const url = resolveFetchUrl(input);
    if (!url) {
        return false;
    }
    return /https:\/\/(analytics\.google\.com\/g\/collect|www\.google-analytics\.com\/g\/collect|www\.googletagmanager\.com)/i.test(url);
}

async function fetchWithRetry(url, options = {}, retries = 3, backoff = 500, fetchFn = originalFetch, logError = true) {
    try {
        return await fetchFn(url, options);
    } catch (err) {
        if (options && options.signal && options.signal.aborted) {
            throw err;
        }
        if (err.name === 'AbortError') {
            throw err;
        }
        if (typeof navigator !== "undefined" && navigator.onLine === false) {
            if (typeof redirecionarSeOffline === "function") {
                redirecionarSeOffline();
                logError = false;
            }
            throw err;
        }
        if (retries > 0) {
            await new Promise(r => setTimeout(r, backoff));
            return fetchWithRetry(url, options, retries - 1, backoff * 2, fetchFn, logError);
        }
        if (isNetworkError(err) && isSameOriginRequest(url) && typeof redirecionarSeOffline === "function") {
            const deveForcarRedirect = (typeof navigator === "undefined") || navigator.onLine === false;
            if (deveForcarRedirect && redirecionarSeOffline(true)) {
                logError = false;
            }
        }
        if (logError) {
            console.error('Fetch failed:', err);
            registrarErroBasicoSite({
                tipo: 'fetch_retry_failed',
                mensagem: 'Falha de rede após tentativas de retry.',
                url: typeof url === 'string' ? url : resolveFetchUrl(url),
                stack: err && err.stack ? err.stack : String(err)
            });
        }
        throw err;
    }
}

if (!window.jsonSafePatched) {
    window.jsonSafePatched = true;
    const originalJson = Response.prototype.json;
    Response.prototype.json = function () {
        return originalJson.call(this).catch(() => null);
    };
}

if (window.scriptSources = window.scriptSources || [], applySavedTheme(document), window.csrfSetup === undefined) {
    window.csrfSetup = true;
    window.csrfToken = null;

    let e = fetchWithRetry("/CSRFPega.php")
        .then(parseJSON)
        .then(e => {
            if (e && e.token) {
                window.csrfToken = e.token;
            }
        })
        .catch(() => { });

    const t = originalFetch;

    const MAX_CONCURRENT_FETCHES = 32;

    let activeFetches = 0;
    const fetchQueue = [];
    const MAX_ERROR_LOGS = 5;
    let fetchErrorLogs = 0;

    async function dequeue() {
        activeFetches--;
        if (fetchQueue.length) {
            const next = fetchQueue.shift();
            next();
        }
    }

    function isSameOriginRequest(resource) {
        try {
            const url = resource instanceof Request ? resource.url : resource;
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch {
            return false;
        }
    }

    function resolveMethod(resource, options) {
        if (options && options.method) {
            return String(options.method).toUpperCase();
        }
        if (resource instanceof Request && resource.method) {
            return String(resource.method).toUpperCase();
        }
        return 'GET';
    }

    window.fetch = async function (o, n = {}) {
        const url = typeof o === "string" ? o : (o && o.url) || "";
        const nonCriticalTelemetryRequest = isNonCriticalTelemetryRequest(o);
        if (activeFetches >= MAX_CONCURRENT_FETCHES) {
            await new Promise(resolve => fetchQueue.push(resolve));
        }
        activeFetches++;
        const method = resolveMethod(o, n);
        if (n && method === "POST" && isSameOriginRequest(o)) {
            n.headers = n.headers || {};
            if (!window.csrfToken) {
                try {
                    await e;
                } catch { }
            }

            if (window.csrfToken) {
                if (n.headers instanceof Headers) {
                    n.headers.append("X-CSRF-Token", window.csrfToken);
                } else {
                    n.headers["X-CSRF-Token"] = window.csrfToken;
                }
            }
        }
        try {
            const resp = await fetchWithRetry(o, n, 3, 500, originalFetch, false);
            return resp;
        } catch (err) {
            if (nonCriticalTelemetryRequest && isNetworkError(err)) {
                return new Response(null, { status: 204, statusText: 'No Content' });
            }
            if (n && n.signal && n.signal.aborted) {
                throw err;
            }
            if (err.name === 'AbortError') {
                throw err;
            }
            const redirected = typeof redirecionarSeOffline === "function" ? redirecionarSeOffline() : false;
            if (!redirected) {
                console.error('Fetch failed:', err);
                registrarErroBasicoSite({
                    tipo: 'fetch_wrapper_failed',
                    mensagem: 'Falha no fetch global da aplicação.',
                    url,
                    stack: err && err.stack ? err.stack : String(err)
                });
            }
            if (fetchErrorLogs < MAX_ERROR_LOGS && navigator.onLine && /Mobi|Android|iPhone|iPad|iPod|Windows Phone/i.test(navigator.userAgent || "")) {
                try {
                    if (!window.csrfToken) {
                        try { await e; } catch { }
                    }
                    const headers = { 'Content-Type': 'application/json' };
                    if (window.csrfToken) headers['X-CSRF-Token'] = window.csrfToken;
                    t('/LogsErros/RegistrarErroJS.php', {
                        method: 'POST',
                        headers,
                        body: JSON.stringify({
                            msg: `Fetch failed: ${err.message || err}`,
                            url,
                            stack: `online:${navigator.onLine} page:${location.href}`
                        })
                    });
                    fetchErrorLogs++;
                } catch { }
            }
            if (!redirected && typeof redirecionarSeOffline === "function") {
                redirecionarSeOffline();
            }
            throw err;
        } finally {
            dequeue();
        }
    };
}
let reloadScheduled = false;
const CACHE_REFRESH_KEY = "cacheRefreshInProgress";

function registrarErroBasicoSite(payload) {
    if (typeof window === 'undefined' || typeof window.registrarErroBasico !== 'function') {
        return;
    }
    try {
        window.registrarErroBasico(payload);
    } catch { }
}

function isReloadInProgress() {
    if (reloadScheduled) {
        return true;
    }
    try {
        return sessionStorage.getItem(CACHE_REFRESH_KEY) === "1";
    } catch {
        return false;
    }
}

function markReloadScheduled() {
    reloadScheduled = true;
    try {
        sessionStorage.setItem(CACHE_REFRESH_KEY, "1");
    } catch { }
}

function resetReloadSchedule() {
    reloadScheduled = false;
    try {
        sessionStorage.removeItem(CACHE_REFRESH_KEY);
    } catch { }
}

var AUTH_CALLBACK_PARAMS = window.AUTH_CALLBACK_PARAMS || ["code", "state", "id_token", "access_token", "error", "session_state"];
window.AUTH_CALLBACK_PARAMS = AUTH_CALLBACK_PARAMS;

function isAuthCallbackInProgress() {
    try {
        const params = new URLSearchParams(window.location.search || "");
        return AUTH_CALLBACK_PARAMS.some(param => params.has(param));
    } catch {
        return false;
    }
}

async function clearCachesAndReload() {
    if (isReloadInProgress()) {
        return;
    }
    if (isAuthCallbackInProgress()) {
        return;
    }
    markReloadScheduled();
    try {
        if ("caches" in window) {
            const keys = await caches.keys();
            await Promise.all(keys.map(key => caches.delete(key)));
        }
    } catch { }
    finally {
        location.reload();
    }
}

window.addEventListener("pageshow", resetReloadSchedule);
window.addEventListener("load", resetReloadSchedule);

const storedAppVersion = safeLocalStorageGetItem("app_version");
if (!storedAppVersion) {
    safeLocalStorageSetItem("app_version", APP_VERSION);
}

const currentStoredVersion = safeLocalStorageGetItem("app_version");

if (currentStoredVersion && currentStoredVersion !== APP_VERSION) {
    if (safeLocalStorageSetItem("app_version", APP_VERSION)) {
        clearCachesAndReload();
    }
}

const redirectParam = new URLSearchParams(location.search).get("url");
if (redirectParam) {
    try {
        const redirectUrl = new URL(redirectParam, location.origin);
        if (redirectUrl.origin === location.origin) {
            location.href = redirectUrl.href;
        }
    } catch { }
}

const OFFLINE_PAGE = "/PaginaErros/PaginaErros.html";
let offlineRedirectTriggered = false;

function normalizeVersion(value) {
    return (value || "")
        .toString()
        .replace(/^versao\./i, "")
        .trim();
}

function redirecionarSeOffline(forceRedirect = false) {
    if (offlineRedirectTriggered) {
        return true;
    }
    if ((!navigator.onLine || forceRedirect) && location.pathname !== OFFLINE_PAGE) {
        offlineRedirectTriggered = true;
        try {
            sessionStorage.setItem("offline-redirect", location.href);
        } catch { }
        location.replace(`${OFFLINE_PAGE}?code=offline`);
        return true;
    }
    return false;
}

if (typeof window !== "undefined") {
    if (!navigator.onLine) {
        redirecionarSeOffline();
    }

    window.addEventListener("offline", redirecionarSeOffline);
}

function getAppShellContainer() {
    if (typeof document === "undefined") {
        return null;
    }

    return document.getElementById("app-shell") || document.body;
}

function markAppShellReady(container) {
    if (!container) {
        return;
    }

    container.removeAttribute("data-placeholder");
    container.removeAttribute("aria-busy");
}

function resolveSpaNavigationUrl(pageDoc, fallbackUrl) {
    const candidates = [];
    const isFallbackFragment = (() => {
        if (typeof fallbackUrl !== "string" || !fallbackUrl.trim()) {
            return false;
        }
        try {
            const resolved = new URL(fallbackUrl, window.location.origin);
            return resolved.pathname.startsWith("/Paginas");
        } catch {
            return false;
        }
    })();

    if (pageDoc) {
        const canonicalLink = pageDoc.querySelector('link[rel="canonical"]');
        if (canonicalLink && canonicalLink.getAttribute("href")) {
            candidates.push(canonicalLink.getAttribute("href"));
        }
        const ogUrl = pageDoc.querySelector('meta[property="og:url"]');
        if (ogUrl && ogUrl.getAttribute("content")) {
            candidates.push(ogUrl.getAttribute("content"));
        }
        const twitterUrl = pageDoc.querySelector('meta[name="twitter:url"]');
        if (twitterUrl && twitterUrl.getAttribute("content")) {
            candidates.push(twitterUrl.getAttribute("content"));
        }
    }
    if (typeof window !== "undefined" && window.location) {
        if (isFallbackFragment) {
            candidates.push(window.location.href);
        }
    }
    if (typeof fallbackUrl === "string" && fallbackUrl.trim()) {
        candidates.push(fallbackUrl);
    }
    if (typeof window !== "undefined" && window.location && !isFallbackFragment) {
        candidates.push(window.location.href);
    }

    for (const candidate of candidates) {
        try {
            const resolved = new URL(candidate, window.location.origin);
            if (resolved && resolved.href) {
                return resolved.href;
            }
        } catch { }
    }
    return null;
}

function dispatchSpaNavigation(url) {
    const detail = { url };
    const eventName = "spa:navigation";
    let event;
    if (typeof window.CustomEvent === "function") {
        event = new CustomEvent(eventName, { detail });
    } else {
        event = document.createEvent("CustomEvent");
        event.initCustomEvent(eventName, false, false, detail);
    }
    window.dispatchEvent(event);
}

async function waitForCriticalLayoutStyles() {
    const state = window.__layoutStylesState;
    if (!state || typeof state.loadStyles !== "function") {
        return;
    }
    try {
        await state.loadStyles({ includeOptional: false });
    } catch { }
}

function applyTemporaryShellMinHeight(container) {
    if (!container || container === document.body) {
        return null;
    }

    const rect = container.getBoundingClientRect();
    const height = rect && rect.height ? Math.ceil(rect.height) : 0;
    if (!height) {
        return null;
    }

    const previousMinHeight = container.style.minHeight;
    container.style.minHeight = `${height}px`;
    container.setAttribute("data-shell-min-height-lock", "true");
    return previousMinHeight;
}

function clearTemporaryShellMinHeight(container, previousMinHeight) {
    if (!container || container === document.body) {
        return;
    }

    if (!container.hasAttribute("data-shell-min-height-lock")) {
        return;
    }

    if (previousMinHeight) {
        container.style.minHeight = previousMinHeight;
    } else {
        container.style.removeProperty("min-height");
    }
    container.removeAttribute("data-shell-min-height-lock");
}

function mostrarAvisoAtualizacao(callback) {
    if (typeof callback === "function") {
        callback();
    }
}

async function carregarPagina(e, t = true) {
    const refreshDocumentTitle = options => {
        if (typeof window.__refreshDynamicDocumentTitle === "function") {
            window.__refreshDynamicDocumentTitle(options || {});
            return true;
        }
        return false;
    };

    const appShell = getAppShellContainer() || document.body;
    const previousShellMinHeight = applyTemporaryShellMinHeight(appShell);

    try {
        const o = fetchWithRetry(`/Layout/Layout.html?v=${APP_VERSION}`, { cache: 'no-store' }).then(e => e.text());
        const n = fetchWithRetry(`${e}?v=${APP_VERSION}`, { cache: 'no-store' }).then(e => e.text());

        let a = Promise.resolve("");
        let r = Promise.resolve(null);

        if (t) {
            a = fetchWithRetry(`/PaginasNavegacaoCompartilhada/GerarProdutoeInformacoesProduto.html?v=${APP_VERSION}`, { cache: 'no-store' }).then(e => e.text());
            r = fetchWithRetry(`/PaginasAreaClienteAcessoCadastro/ValidarSessao.php?v=${APP_VERSION}`, {
                credentials: "include",
                cache: "no-store"
            })
                .then(parseJSON)
                .catch(() => null);
        }

        let [c, i, s, d] = await Promise.all([o, n, a, r]);
        let l = "";
        let pHtml = "";

        let pageDoc = null;
        try {
            pageDoc = parseHTMLDocument(i);
        } catch { }
        if (pageDoc) {
            refreshDocumentTitle({
                sourceDoc: pageDoc,
                pageUrl: e
            });
        }

        if (t && d) {
            if (d.senhaExpirada) {
                try {
                    const destino = window.location.pathname + window.location.search + window.location.hash;
                    sessionStorage.setItem('retornoPosLogin', destino);
                    location.href = `/AreaCliente?senhaExpirada=1&retorno=${encodeURIComponent(destino)}`;
                } catch {
                    location.href = '/AreaCliente?senhaExpirada=1';
                }
                return;
            }
            if (!d.sucesso) {
                try {
                    const destino = window.location.pathname + window.location.search + window.location.hash;
                    sessionStorage.setItem('retornoPosLogin', destino);
                    location.href = `/AreaCliente?retorno=${encodeURIComponent(destino)}`;
                } catch {
                    location.href = '/AreaCliente';
                }
                return;
            }
            const grupo = (d.grupo?.toUpperCase()) || "";
            if (grupo === "OURO" || grupo === "DIAMANTE") {
                l = await fetchWithRetry(`/PaginasNavegacaoCompartilhada/EstoqueExterno.html?v=${APP_VERSION}`, { cache: 'no-store' }).then(e => e.text());
                pHtml = await fetchWithRetry(`/PaginasNavegacaoCompartilhada/PrecoExterno.html?v=${APP_VERSION}`, { cache: 'no-store' }).then(e => e.text());
            } else if (grupo === "ADMINISTRADOR" || grupo === "INTERNO") {
                l = await fetchWithRetry(`/PaginasNavegacaoCompartilhada/EstoqueInterno.html?v=${APP_VERSION}`, { cache: 'no-store' }).then(e => e.text());
                pHtml = await fetchWithRetry(`/PaginasNavegacaoCompartilhada/PrecoInterno.html?v=${APP_VERSION}`, { cache: 'no-store' }).then(e => e.text());
            }
        }

        const u = window.scriptSources;
        u.length = 0;

        c = c.replace(/<script\b([^>]*)\bsrc="([^"]+)"([^>]*)><\/script>/gi, (e, t, o, n) => {
            const attrsString = `${t} ${n}`.trim();
            const attrs = {};
            attrsString.replace(/([\w-:]+)(?:=(["'])(.*?)\2)?/g, (e, t, o, n) => {
                attrs[t] = n === undefined ? true : n;
            });
            u.push({ src: o, attrs });
            return "";
        });

        const f = i + s + l + pHtml;
        const m = parseHTMLDocument(c);

        if (appShell !== document.body) {
            const layoutLoader = m.getElementById("loader");
            if (layoutLoader) {
                layoutLoader.remove();
            }
        }


        applySavedTheme(m);

        const h = m.querySelector("footer");
        if (h) {
            h.insertAdjacentHTML("beforebegin", f);
        } else {
            m.body.insertAdjacentHTML("beforeend", f);
        }

        await yieldToMainThread();

        const docAttributes = Array.from(m.documentElement.attributes);
        for (let idx = 0; idx < docAttributes.length; idx++) {
            const attr = docAttributes[idx];
            document.documentElement.setAttribute(attr.name, attr.value);
            if (idx % 5 === 4) {
                await yieldToMainThread();
            }
        }
        const p = [];

        const v = async (target, source) => {
            if (!target || !source) return;
            const isHeadTarget = target === document.head;
            const fragment = document.createDocumentFragment();
            const scriptPlaceholders = [];
            let placeholderTitle = null;

            if (isHeadTarget) {
                placeholderTitle = document.createElement("title");
                placeholderTitle.dataset.placeholderTitle = "true";
                let stableTitle = "";
                if (typeof window.__resolveDynamicDocumentTitle === "function") {
                    stableTitle = window.__resolveDynamicDocumentTitle({
                        sourceDoc: pageDoc || document,
                        pageUrl: e
                    }) || "";
                }
                placeholderTitle.textContent = stableTitle || document.title || "";
                fragment.appendChild(placeholderTitle);
            }
            let processed = 0;
            for (const node of Array.from(source.childNodes)) {
                processed++;
                if (node.tagName === "SCRIPT") {
                    const script = document.createElement("script");
                    for (const attr of Array.from(node.attributes)) {
                        script.setAttribute(attr.name, attr.value);
                    }
                    script.textContent = node.textContent;
                    if (script.src) {
                        p.push(new Promise(resolve => {
                            script.onload = resolve;
                            script.onerror = resolve;
                        }));
                    }
                    const marker = document.createComment("script-placeholder");
                    fragment.appendChild(marker);
                    scriptPlaceholders.push({ marker, script });
                } else {
                    if (node.tagName === "TITLE") {
                        continue;
                    }
                    fragment.appendChild(node.cloneNode(true));
                }
                if (processed % 20 === 0) {
                    await yieldToMainThread();
                }
            }

            try {
                target.replaceChildren(fragment);
            } catch (replaceChildrenError) {
                console.warn("replaceChildren failed, falling back to manual DOM update.", replaceChildrenError);
                while (target.firstChild) {
                    target.removeChild(target.firstChild);
                }
                target.appendChild(fragment);
            }

            for (let idx = 0; idx < scriptPlaceholders.length; idx++) {
                const scriptEntry = scriptPlaceholders[idx];
                try {
                    scriptEntry.marker.replaceWith(scriptEntry.script);
                } catch (scriptInsertionError) {
                    console.error("Failed to insert script while updating page shell.", scriptInsertionError);
                }
                if (idx % 10 === 9) {
                    await yieldToMainThread();
                }
            }

            if (isHeadTarget && placeholderTitle) {
                refreshDocumentTitle({
                    sourceDoc: pageDoc,
                    pageUrl: e
                });
            }
        };
        await v(document.head, m.head);
        await waitForCriticalLayoutStyles();
        await v(appShell, m.body);
        markAppShellReady(appShell);
        clearTemporaryShellMinHeight(appShell, previousShellMinHeight);

        const shellReadyDetail = {
            layoutLoaded: true,
            pageUrl: e,
            timestamp: Date.now()
        };
        try {
            document.dispatchEvent(new CustomEvent('page-shell-ready', { detail: shellReadyDetail }));
        } catch (customEventError) {
            console.warn('Fallback page-shell-ready event dispatch.', customEventError);
            document.dispatchEvent(new Event('page-shell-ready'));
        }

        if (!pageDoc) {
            pageDoc = parseHTMLDocument(i);
        }
        refreshDocumentTitle({
            sourceDoc: pageDoc,
            pageUrl: e
        });

        const metaNodes = pageDoc.head ? Array.from(pageDoc.head.querySelectorAll("meta[name], meta[property]")) : [];
        for (let idx = 0; idx < metaNodes.length; idx++) {
            const meta = metaNodes[idx];
            let selector = meta.hasAttribute("name")
                ? `meta[name="${meta.getAttribute("name")}"]`
                : `meta[property="${meta.getAttribute("property")}"]`;
            let existing = document.head.querySelector(selector);
            if (!existing) {
                existing = document.createElement("meta");
                if (meta.hasAttribute("name")) existing.setAttribute("name", meta.getAttribute("name"));
                if (meta.hasAttribute("property")) existing.setAttribute("property", meta.getAttribute("property"));
                document.head.appendChild(existing);
            }
            const content = meta.getAttribute("content");
            if (content !== null) existing.setAttribute("content", content);
            if (idx % 6 === 5) {
                await yieldToMainThread();
            }
        }

        await Promise.all(p);

        const E = ({ src, attrs }) => new Promise(resolve => {
            const script = document.createElement("script");
            script.src = src;
            if (attrs) {
                for (const [key, value] of Object.entries(attrs)) {
                    if (key !== "src") {
                        if (value === true) {
                            script.setAttribute(key, "");
                        } else {
                            script.setAttribute(key, value);
                        }
                    }
                }
            }
            script.onload = resolve;
            script.onerror = resolve;
            document.head.appendChild(script);
        });

        for (const e of u) {
            await yieldToMainThread();
            await E(e);
        }

        await yieldToMainThread();
        document.dispatchEvent(new Event("DOMContentLoaded"));
        window.dispatchEvent(new Event("load"));
        window.scrollTo(0, 0);

        let navigationUrl = resolveSpaNavigationUrl(pageDoc, e);
        let navigationApplied = false;
        if (navigationUrl && typeof history !== "undefined" && typeof history.replaceState === "function") {
            try {
                history.replaceState(history.state || {}, "", navigationUrl);
                navigationApplied = true;
            } catch (historyError) {
                console.warn("Falha ao atualizar URL canônica no histórico.", historyError);
            }
        }
        if (!navigationApplied && navigationUrl) {
            dispatchSpaNavigation(navigationUrl);
        }

        if (typeof window.trackPageView === "function") {
            window.trackPageView(location.pathname + location.search);
        }

        if (typeof window.apiChatRegistrarTelemetriaUso === 'function') {
            window.apiChatRegistrarTelemetriaUso('pagina_acessada', {
                pagina: location.pathname,
                query: location.search || '',
                titulo: (document && document.title ? document.title : '').slice(0, 120)
            });
        }

        const titulo = document.getElementById("titulo-estrutural");
        if (titulo) titulo.remove();

    } catch (e) {
        markAppShellReady(appShell);
        clearTemporaryShellMinHeight(appShell, previousShellMinHeight);
        if (navigator.onLine) {
            document.body.innerHTML = `<p style="color:red">⚠️ Erro ao Carregar a Página: ${e.message}</p>`;
        } else {
            location.replace(`${OFFLINE_PAGE}?code=offline`);
        }
    }
}

if ("serviceWorker" in navigator && (location.protocol === "https:" || location.hostname === "localhost")) {
    const normalizedAppVersion = normalizeVersion(APP_VERSION);
    const versionParam = normalizedAppVersion ? `?versao=${encodeURIComponent(normalizedAppVersion)}` : "";

    runWhenIdle(async () => {
        const serviceWorkerCandidates = [
            versionParam ? "/Service-Worker.min.js" + versionParam : null,
            "/Service-Worker.min.js",
            versionParam ? "/Service-Worker.js" + versionParam : null,
            "/Service-Worker.js"
        ].filter(Boolean);
        const options = { updateViaCache: "none", scope: "/" };

        async function tryRegister(url) {
            try {
                const response = await originalFetch(url, { cache: "no-store" });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
            } catch (err) {
                console.warn(`Service worker script fetch failed for ${url}:`, err);
                return null;
            }

            try {
                return await navigator.serviceWorker.register(url, options);
            } catch (err) {
                console.warn(`Service worker registration failed for ${url}:`, err);
                return null;
            }
        }

        let reg = null;
        for (const url of serviceWorkerCandidates) {
            reg = await tryRegister(url);
            if (reg) {
                break;
            }
        }

        if (!reg) {
            return;
        }

        try {
            if (navigator.onLine !== false) {
                await reg.update();
            }
        } catch (err) {
            console.warn("Service worker manual update failed:", err);
        }

        reg.addEventListener("updatefound", () => {
            const newWorker = reg.installing;
            if (newWorker) {
                newWorker.addEventListener("statechange", () => {
                    if (navigator.serviceWorker.controller && newWorker.state === "activated") {
                        const storedVersion = safeLocalStorageGetItem("app_version");


                        const normalizedStoredVersion = normalizeVersion(storedVersion);
                        const shouldSkipReload = Boolean(storedVersion) && (
                            storedVersion === APP_VERSION ||
                            (normalizedStoredVersion && normalizedStoredVersion === normalizedAppVersion)
                        );

                        if (!shouldSkipReload) {
                            clearCachesAndReload();
                        }
                    }
                });
            }
        });
        if ("periodicSync" in reg) {
            reg.periodicSync.register("update-content", { minInterval: 86400000 }).catch(() => { });
        }
        if ("sync" in reg) {
            reg.sync.register("sync-requests").catch(() => { });
        }
    }, 2500);

    navigator.serviceWorker.addEventListener("message", e => {
        const t = e.data;
        if (t && (t === "updated" || t.type === "updated")) {
            const e = t.version || APP_VERSION;
            const o = safeLocalStorageGetItem("app_version");
            if (e !== o) {
                mostrarAvisoAtualizacao(() => {
                    if (safeLocalStorageSetItem("app_version", e)) {
                        clearCachesAndReload();
                    }
                });
            }
        }
    });
}
