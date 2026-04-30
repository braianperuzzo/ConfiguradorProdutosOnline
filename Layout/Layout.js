var APP_VERSION = (function resolveLayoutAppVersion() {
    var FALLBACK_APP_VERSION = 'versao.desconhecida';

    function normalizeCandidate(candidate) {
        return typeof candidate === 'string' && candidate.trim() ? candidate.trim() : '';
    }

    try {
        if (typeof window !== 'undefined') {
            if (typeof window.getAppVersion === 'function') {
                var loadedVersion = window.getAppVersion();
                var normalizedLoaded = normalizeCandidate(loadedVersion);
                if (normalizedLoaded) {
                    window.APP_VERSION = normalizedLoaded;
                    return normalizedLoaded;
                }
            }

            var existing = normalizeCandidate(window.APP_VERSION);
            if (existing) {
                return existing;
            }

            if (typeof window.resolveAppVersionFromConfigSync === 'function') {
                var resolved = normalizeCandidate(window.resolveAppVersionFromConfigSync());
                if (resolved) {
                    window.APP_VERSION = resolved;
                    return resolved;
                }
            }

            window.APP_VERSION = FALLBACK_APP_VERSION;
        }
    } catch (err) {
        console.warn('APP_VERSION: failed to resolve version inside layout.', err);
    }

    return FALLBACK_APP_VERSION;
})();

var ERROR_SDK_LOADED = false;
var ERROR_SDK_CONFIG = null;

function reportBasicError(payload) {
    if (typeof window === 'undefined' || typeof window.registrarErroBasico !== 'function') {
        return;
    }
    try {
        window.registrarErroBasico(payload);
    } catch (error) {
        console.warn('Falha ao registrar erro básico.', error);
    }
}

function normalizeTelemetryValue(value) {
    return typeof value === 'string' ? value.trim() : '';
}

function resolveErrorSdkVersion() {
    var version = normalizeTelemetryValue(APP_VERSION);
    if (!version && typeof window !== 'undefined') {
        version = normalizeTelemetryValue(window.APP_VERSION);
    }
    if (!version && typeof window !== 'undefined' && typeof window.getAppVersion === 'function') {
        version = normalizeTelemetryValue(window.getAppVersion());
    }
    return version || 'versao.desconhecida';
}

function resolveErrorSdkEnvironment() {
    if (typeof window === 'undefined') {
        return 'desconhecido';
    }
    var candidates = [
        normalizeTelemetryValue(window.APP_ENV),
        normalizeTelemetryValue(window.AMBIENTE),
        normalizeTelemetryValue(window.ENVIRONMENT),
        normalizeTelemetryValue(window.__APP_ENV__)
    ];
    for (var i = 0; i < candidates.length; i++) {
        if (candidates[i]) {
            return candidates[i];
        }
    }
    var root = document.documentElement;
    if (root) {
        var fromAttribute = normalizeTelemetryValue(
            root.getAttribute('data-env') || root.getAttribute('data-environment')
        );
        if (fromAttribute) {
            return fromAttribute;
        }
    }
    return 'desconhecido';
}

function initErrorSdk() {
    if (ERROR_SDK_LOADED) {
        return;
    }
    ERROR_SDK_LOADED = true;
    ERROR_SDK_CONFIG = {
        release: resolveErrorSdkVersion(),
        environment: resolveErrorSdkEnvironment(),
        tags: {
            app_version: resolveErrorSdkVersion(),
            environment: resolveErrorSdkEnvironment()
        }
    };
    if (typeof window !== 'undefined') {
        window.ERROR_SDK_CONFIG = ERROR_SDK_CONFIG;
    }
    if (!document || !document.createElement) {
        return;
    }
    var existing = document.querySelector('script[data-error-sdk="monitoramento"]');
    if (existing) {
        return;
    }
    var script = document.createElement('script');
    script.src = '/LogsErros/MonitoramentoErros.js';
    script.async = true;
    script.defer = true;
    script.dataset.errorSdk = 'monitoramento';
    (document.head || document.documentElement).appendChild(script);
}

try {
    initErrorSdk();
} catch (err) {
    console.warn('Error SDK: falha ao iniciar monitoramento.', err);
    reportBasicError({
        tipo: 'layout_error_sdk_init',
        mensagem: 'Falha ao iniciar monitoramento de erros no layout.',
        stack: err && err.stack ? err.stack : String(err)
    });
}

var CANONICAL_ORIGIN_FALLBACK = 'https://configurador.redutoresibr.com.br/';
var documentElement = document.documentElement || null;
var initialCanonicalApplied = false;
var scrollResetScheduled = false;

if ("scrollRestoration" in history) {
    history.scrollRestoration = "manual";
}

function resetInitialScrollPosition() {
    if (scrollResetScheduled) {
        return;
    }
    scrollResetScheduled = true;
    requestAnimationFrame(function () {
        scrollResetScheduled = false;
        if (window.location.hash) {
            return;
        }
        var docEl = document.documentElement;
        var body = document.body;
        if (window.scrollY === 0 && (!docEl || docEl.scrollTop === 0) && (!body || body.scrollTop === 0)) {
            return;
        }
        if (docEl) {
            docEl.scrollTop = 0;
        }
        if (body) {
            body.scrollTop = 0;
        }
        window.scrollTo(0, 0);
        setTimeout(function () {
            if (window.location.hash) {
                return;
            }
            var stillOffset = window.scrollY !== 0 ||
                (docEl && docEl.scrollTop !== 0) ||
                (body && body.scrollTop !== 0);
            if (!stillOffset) {
                return;
            }
            if (docEl) {
                docEl.scrollTop = 0;
            }
            if (body) {
                body.scrollTop = 0;
            }
            window.scrollTo(0, 0);
        }, 80);
    });
}

window.addEventListener("pageshow", function () {
    resetInitialScrollPosition();
});

document.addEventListener("DOMContentLoaded", function () {
    resetInitialScrollPosition();
    hideEmptyTitleBlock();
});

window.addEventListener("load", function () {
    resetInitialScrollPosition();
});

function hideEmptyTitleBlock() {
    var tituloBloco = document.querySelector(".pagina__titulo--bloco");
    if (!tituloBloco) {
        return;
    }

    var titulo = tituloBloco.querySelector(".titulo-pagina");
    var texto = tituloBloco.textContent ? tituloBloco.textContent.trim() : "";
    if (titulo || texto) {
        return;
    }

    tituloBloco.style.display = "none";
}

function readDataAttribute(name) {
    if (!documentElement || !name) {
        return null;
    }
    var value = documentElement.getAttribute(name);
    return value === null ? null : value;
}
function readGlobalString(names, options) {
    if (!names || !names.length) {
        return null;
    }
    var allowEmpty = options && options.allowEmpty;
    for (var i = 0; i < names.length; i += 1) {
        var candidate = window[names[i]];
        if (typeof candidate === 'string' && (candidate || allowEmpty)) {
            return candidate;
        }
    }
    return null;
}

function createInitialOverride(globalNames, attrName) {
    var globalValue = readGlobalString(globalNames, { allowEmpty: true });
    if (globalValue !== null) {
        return { hasOverride: true, value: globalValue };
    }
    var attrValue = readDataAttribute(attrName);
    if (attrValue !== null) {
        return { hasOverride: true, value: attrValue };
    }
    return { hasOverride: false, value: null };
}

function consumeInitialOverride(override, fallback) {
    if (override && override.hasOverride) {
        var value = override.value;
        override.hasOverride = false;
        override.value = null;
        return typeof value === 'string' ? value : fallback;
    }
    return fallback;
}

var initialPathnameOverride = createInitialOverride(['__CANONICAL_PATHNAME__', '__canonicalPathname__'], 'data-canonical-pathname');
var initialSearchOverride = createInitialOverride(['__CANONICAL_SEARCH__', '__canonicalSearch__'], 'data-canonical-search');

function resolveCanonicalOrigin(defaultOrigin) {
    var globalOrigin = readGlobalString(['__CANONICAL_ORIGIN__', '__canonicalOrigin__']);
    if (globalOrigin) {
        return globalOrigin;
    }
    var dataOrigin = readDataAttribute('data-canonical-origin');
    if (dataOrigin) {
        return dataOrigin;
    }
    return defaultOrigin;
}
var canonicalUpdateScheduled = false;
var pendingCanonicalUrl = null;
var shouldEmitSpaNavigation = false;

const IS_AREA_CLIENTE_PATH = /^\/(Paginas\/)?AreaCliente/.test(
    window.location.pathname
);
const IS_CARRINHO_PATH = /^\/(Paginas\/)?CarrinhoProdutos/.test(
    window.location.pathname
);

var CANONICAL_CODE_MAP = {
    '1.C': 'IBRC',
    '1.FFA': 'IBRPFFA',
    '1.FKA': 'IBRXFKA',
    '1.FR': 'IBRCFR',
    '1.H': 'IBRH',
    '1.M': 'IBRM',
    '1.P': 'IBRP',
    '1.Q': 'IBRQ',
    '1.QDR': 'IBRQDR',
    '1.QP': 'IBRQP',
    '1.R': 'IBRR',
    '1.V': 'IBRV',
    '1.VFN': 'IBRVFN',
    '1.X': 'IBRX',
    '1.Z': 'IBRZ',
    '2.I': 'IBRMSML',
    '3.APM': 'ANTICORROSIVOSAPM',
    '3.GR': 'IBRGR',
    '3.GS': 'IBRGS',
    '3.I': 'IBRT3AT3C',
    '3.PB': 'IBRPB',
    '3.PBL': 'IBRPBL',
    '3.RIC': 'IBRRIC',
    '3.SA': 'IBRSA',
    '3.SB': 'IBRSB',
    '3.SBL': 'IBRSBL',
    '3.SD': 'IBRSD',
    '3.SPM': 'ANTICORROSIVOSSPM',
    '3.W': 'WEGALTORENDIMENTO',
    '4.K': 'IBRK'
};

(function suppressExtensionMessagingNoise() {
    if (typeof window === 'undefined' || typeof window.addEventListener !== 'function') {
        return;
    }

    var KNOWN_ERROR_FRAGMENTS = [
        'A listener indicated an asynchronous response by returning true, but the message channel closed before a response was received',
        'The message port closed before a response was received',
        'Could not establish connection. Receiving end does not exist'
    ];
    var KNOWN_ERROR_MATCHER_PROPS = ['message', 'stack', 'reason', 'error', 'cause'];
    var KNOWN_VLIBRAS_NOISE_FRAGMENTS = [
        '[UnityCache]',
        'Loading player data from data.unity3d',
        'Initialize engine version:',
        'Creating WebGL 2.0 context.',
        'OPENGL LOG:',
        'UnloadTime:',
        'The AnimationClip',
        'Default clip could not be found in attached animations list.',
        '[AnimatedObjects] Not found',
        'Domain is invalid or config is null',
        'Base Url is now https://dicionario2.vlibras.gov.br/',
        'SET BASE URL TO https://dicionario2.vlibras.gov.br/'
    ];
    var warningLogged = false;

    function containsKnownFragment(value) {
        if (!value) {
            return false;
        }

        function hasFragment(candidate) {
            if (typeof candidate !== 'string') {
                return false;
            }

            for (var i = 0; i < KNOWN_ERROR_FRAGMENTS.length; i += 1) {
                if (candidate.indexOf(KNOWN_ERROR_FRAGMENTS[i]) !== -1) {
                    return true;
                }
            }

            return false;
        }

        if (hasFragment(value)) {
            return true;
        }

        for (var i = 0; i < KNOWN_ERROR_MATCHER_PROPS.length; i += 1) {
            var propName = KNOWN_ERROR_MATCHER_PROPS[i];
            var propValue = value && value[propName];
            if (hasFragment(propValue)) {
                return true;
            }
        }

        if (value && typeof value.toString === 'function' && value.toString !== Object.prototype.toString) {
            var asString = value.toString();
            if (hasFragment(asString)) {
                return true;
            }
        }

        return false;
    }

    function blockNoise(event) {
        if (!event || typeof event.preventDefault !== 'function') {
            return false;
        }

        if (!warningLogged && typeof console !== 'undefined' && typeof console.warn === 'function') {
            console.warn('Ignorando erro de extensão do navegador relacionado a mensagens assíncronas.');
            warningLogged = true;
        }

        event.preventDefault();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }

        return true;
    }

    function shouldIgnoreConsoleMessage(args) {
        if (!args || !args.length) {
            return false;
        }

        for (var i = 0; i < args.length; i += 1) {
            if (containsKnownFragment(args[i])) {
                return true;
            }
        }

        return false;
    }

    function shouldIgnoreVlibrasUnityNoise(args) {
        if (!args || !args.length) {
            return false;
        }

        for (var argIndex = 0; argIndex < args.length; argIndex += 1) {
            var value = args[argIndex];
            var candidate = typeof value === 'string'
                ? value
                : value && typeof value.toString === 'function'
                    ? value.toString()
                    : '';

            if (!candidate) {
                continue;
            }

            for (var i = 0; i < KNOWN_VLIBRAS_NOISE_FRAGMENTS.length; i += 1) {
                if (candidate.indexOf(KNOWN_VLIBRAS_NOISE_FRAGMENTS[i]) !== -1) {
                    return true;
                }
            }
        }

        return false;
    }

    function wrapConsoleMethod(methodName) {
        if (typeof console === 'undefined' || typeof console[methodName] !== 'function') {
            return;
        }

        var originalMethod = console[methodName];
        console[methodName] = function () {
            if (shouldIgnoreConsoleMessage(arguments)) {
                if (!warningLogged && typeof console.warn === 'function') {
                    console.warn('Ignorando erro de extensão do navegador relacionado a mensagens assíncronas.');
                    warningLogged = true;
                }
                return;
            }

            return originalMethod.apply(console, arguments);
        };
    }

    function wrapConsoleLogForVlibrasNoise() {
        if (typeof console === 'undefined' || typeof console.log !== 'function') {
            return;
        }

        var originalLog = console.log;
        console.log = function () {
            if (shouldIgnoreVlibrasUnityNoise(arguments)) {
                return;
            }

            return originalLog.apply(console, arguments);
        };
    }

    wrapConsoleMethod('error');
    wrapConsoleLogForVlibrasNoise();

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event && event.reason;

        if (!containsKnownFragment(reason)) {
            return;
        }

        blockNoise(event);
    }, true);

    window.addEventListener('error', function (event) {
        var message = event && event.message;
        var error = event && event.error;

        if (!containsKnownFragment(message) && !containsKnownFragment(error)) {
            return;
        }

        blockNoise(event);
    });
})();

(function ensureInitialLoaderFallback() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    var INITIAL_LOADER_HIDDEN_EVENT = 'initial-loader-hidden';
    var AREA_CLIENTE_NAV_LOADER_STORAGE_KEY = 'areaClienteNavLoaderStartedAt';
    var AREA_CLIENTE_NAV_LOADER_MIN_MS = 2000;
    var areaClienteNavLoaderDelayTimer = null;
    var loaderHiddenNotified = false;

    function restoreRootState(root) {
        if (!root) {
            return;
        }

        var previousBg = root.getAttribute('data-initial-loader-bg');
        if (previousBg != null) {
            root.style.backgroundColor = previousBg;
        } else {
            root.style.removeProperty('background-color');
        }

        var previousAria = root.getAttribute('data-initial-loader-aria');
        if (previousAria != null) {
            if (previousAria) {
                root.setAttribute('aria-busy', previousAria);
            } else {
                root.removeAttribute('aria-busy');
            }
        } else {
            root.removeAttribute('aria-busy');
        }

        root.removeAttribute('data-loading');
        root.classList.remove('is-initial-loading');
    }


    function resolveAreaClienteNavLoaderRemainingMs() {
        if (typeof sessionStorage === 'undefined') {
            return 0;
        }

        try {
            var rawStartedAt = sessionStorage.getItem(AREA_CLIENTE_NAV_LOADER_STORAGE_KEY);
            if (!rawStartedAt) {
                return 0;
            }

            sessionStorage.removeItem(AREA_CLIENTE_NAV_LOADER_STORAGE_KEY);

            var startedAt = Number(rawStartedAt);
            if (!Number.isFinite(startedAt) || startedAt <= 0) {
                return 0;
            }

            var elapsed = Date.now() - startedAt;
            return Math.max(0, AREA_CLIENTE_NAV_LOADER_MIN_MS - elapsed);
        } catch (error) {
            console.warn('Loader fallback: falha ao calcular atraso mínimo da Área do Cliente.', error);
            return 0;
        }
    }

    function dispatchLoaderHiddenEvent() {
        if (loaderHiddenNotified) {
            return;
        }

        loaderHiddenNotified = true;

        try {
            var event;
            if (typeof window.CustomEvent === 'function') {
                event = new CustomEvent(INITIAL_LOADER_HIDDEN_EVENT);
            } else if (document.createEvent) {
                event = document.createEvent('CustomEvent');
                if (event && event.initCustomEvent) {
                    event.initCustomEvent(INITIAL_LOADER_HIDDEN_EVENT, false, false, undefined);
                }
            }

            if (event) {
                window.dispatchEvent(event);
            }
        } catch (error) {
            console.warn('Loader fallback: falha ao disparar evento de ocultação.', error);
            reportBasicError({
                tipo: 'layout_loader_event_error',
                mensagem: 'Falha ao disparar evento de ocultação do loader.',
                stack: error && error.stack ? error.stack : String(error)
            });
        }
    }

    function hideLoaderElements(forceImmediate) {
        if (!forceImmediate && Boolean(window.keepLoading)) {
            return;
        }

        var remainingMs = resolveAreaClienteNavLoaderRemainingMs();
        if (remainingMs > 0) {
            if (areaClienteNavLoaderDelayTimer) {
                clearTimeout(areaClienteNavLoaderDelayTimer);
            }

            areaClienteNavLoaderDelayTimer = setTimeout(function () {
                areaClienteNavLoaderDelayTimer = null;
                hideLoaderElements(forceImmediate === true);
            }, remainingMs);
            return;
        }

        var loader = document.getElementById('loader');
        if (loader) {
            if (!loader.style.transition) {
                loader.style.transition = 'opacity 240ms ease';
            }
            loader.style.opacity = '0';
            loader.style.visibility = 'hidden';
            loader.style.pointerEvents = 'none';
        }

        restoreRootState(document.documentElement);
        dispatchLoaderHiddenEvent();
    }

    if (typeof window.hideLoadingScreen !== 'function') {
        window.hideLoadingScreen = function hideLoadingScreenFallback(forceImmediate) {
            hideLoaderElements(forceImmediate === true);
        };
    }

    if (typeof window.requestLoaderHide !== 'function') {
        window.requestLoaderHide = function requestLoaderHideFallback() {
            hideLoaderElements(false);
        };
    }
})();

function ensureLeadingSlash(pathname) {
    if (!pathname) {
        return '/';
    }
    return pathname.charAt(0) === '/' ? pathname : '/' + pathname;
}

function fallbackSanitizeCanonicalSearch(search) {
    if (!search || search === '?') {
        return '';
    }
    var trimmed = search.charAt(0) === '?' ? search.slice(1) : search;
    if (!trimmed) {
        return '';
    }
    var params = new URLSearchParams(trimmed);
    var entries = [];
    params.forEach(function (value, key) {
        if (!key) {
            return;
        }
        entries.push({ key: key, value: value });
    });
    entries.sort(function (a, b) {
        var keyCompare = a.key.localeCompare(b.key);
        if (keyCompare !== 0) {
            return keyCompare;
        }
        return (a.value || '').localeCompare(b.value || '');
    });
    var seen = Object.create(null);
    var sanitized = new URLSearchParams();
    entries.forEach(function (entry) {
        var dedupeKey = (entry.key || '').toUpperCase() + '=' + (entry.value || '').toUpperCase();
        if (seen[dedupeKey]) {
            return;
        }
        seen[dedupeKey] = true;
        sanitized.append(entry.key, entry.value);
    });
    var result = sanitized.toString();
    return result ? '?' + result : '';
}

function removeSearchParameter(search, key) {
    if (!key) {
        return search || '';
    }
    try {
        var raw = typeof search === 'string' ? search : '';
        var normalized = raw && raw.charAt(0) === '?' ? raw.slice(1) : raw;
        if (!normalized) {
            return '';
        }
        var params = new URLSearchParams(normalized);
        params.delete(key);
        var result = params.toString();
        return result ? '?' + result : '';
    } catch (e) {
        return search || '';
    }
}

function extractConfiguradorSlug(search) {
    if (!search) {
        return null;
    }
    try {
        var raw = search.charAt(0) === '?' ? search.slice(1) : search;
        if (!raw) {
            return null;
        }
        var params = new URLSearchParams(raw);
        var iterator = params.entries();
        var current = iterator.next();
        while (!current.done) {
            var key = current.value[0];
            var value = current.value[1];
            if (value) {
                var slug = CANONICAL_CODE_MAP[value.toUpperCase()];
                if (slug) {
                    return { slug: slug, key: key };
                }
            }
            current = iterator.next();
        }
    } catch (e) {
        // ignore lookup errors
    }
    return null;
}

function fallbackResolveConfiguradorPath(pathname, search) {
    var extracted = extractConfiguradorSlug(search);
    if (extracted && extracted.slug) {
        return {
            pathname: '/Configurador' + extracted.slug,
            search: ''
        };
    }
    return {
        pathname: pathname,
        search: typeof search === 'string' ? search : ''
    };
}

var sanitizeCanonicalSearch = typeof window.__sanitizeCanonicalSearch === 'function'
    ? window.__sanitizeCanonicalSearch
    : fallbackSanitizeCanonicalSearch;

var resolveConfiguradorCanonicalPath = typeof window.__resolveConfiguradorCanonicalPath === 'function'
    ? window.__resolveConfiguradorCanonicalPath
    : fallbackResolveConfiguradorPath;

function removeTrackingParamsFromSearch(search) {
    if (!search) {
        return '';
    }
    var params = new URLSearchParams(search);
    var keysToRemove = [];
    params.forEach(function (_value, key) {
        var lowerKey = key.toLowerCase();
        if (lowerKey.indexOf('utm_') === 0 || lowerKey === 'gclid' || lowerKey === 'fbclid' || lowerKey === 'igshid') {
            keysToRemove.push(key);
        }
    });
    keysToRemove.forEach(function (key) {
        params.delete(key);
    });
    var serialized = params.toString();
    return serialized ? '?' + serialized : '';
}

function shouldUseInitialCanonicalOverrides(targetUrl) {
    if (initialCanonicalApplied) {
        return false;
    }
    if (!targetUrl) {
        return true;
    }
    if (typeof targetUrl === 'string') {
        try {
            return targetUrl === window.location.href;
        } catch (err) {
            return false;
        }
    }
    return false;
}

function resolveCanonicalUrl(targetUrl) {
    try {
        var base = new URL(resolveCanonicalOrigin(CANONICAL_ORIGIN_FALLBACK));
        var resolved = targetUrl ? new URL(targetUrl, base) : new URL(window.location.href);
        resolved.hash = '';
        var sanitizedSearch = sanitizeCanonicalSearch(resolved.search);
        resolved.search = sanitizedSearch;
        if (shouldUseInitialCanonicalOverrides(targetUrl)) {
            resolved.pathname = ensureLeadingSlash(consumeInitialOverride(initialPathnameOverride, resolved.pathname));
            resolved.search = consumeInitialOverride(initialSearchOverride, resolved.search);
            initialCanonicalApplied = true;
        }
        var adjustedPath = resolveConfiguradorCanonicalPath(resolved.pathname, sanitizedSearch);
        if (adjustedPath) {
            if (typeof adjustedPath === 'string') {
                resolved.pathname = ensureLeadingSlash(adjustedPath);
            } else {
                if (adjustedPath.pathname) {
                    resolved.pathname = ensureLeadingSlash(adjustedPath.pathname);
                }
                if (typeof adjustedPath.search === 'string') {
                    resolved.search = adjustedPath.search;
                }
            }
        }
        if (resolved.origin !== base.origin) {
            resolved.protocol = base.protocol;
            resolved.host = base.host;
        }
        resolved.search = removeTrackingParamsFromSearch(resolved.search);
        return resolved.href;
    } catch (e) {
        return resolveCanonicalOrigin(CANONICAL_ORIGIN_FALLBACK);
    }
}

function dispatchSpaNavigation(url) {
    var detail = { url: url };
    var eventName = 'spa:navigation';
    var event;
    if (typeof window.CustomEvent === 'function') {
        event = new CustomEvent(eventName, { detail: detail });
    } else {
        event = document.createEvent('CustomEvent');
        event.initCustomEvent(eventName, false, false, detail);
    }
    window.dispatchEvent(event);
}

function applyCanonical(url, emitNavigationEvent) {
    var canonicalHref = resolveCanonicalUrl(url);
    var head = document.head || document.getElementsByTagName('head')[0];
    if (head) {
        var canonicalLink = head.querySelector('link[rel="canonical"]');
        if (!canonicalLink) {
            canonicalLink = document.createElement('link');
            canonicalLink.setAttribute('rel', 'canonical');
            head.appendChild(canonicalLink);
        }
        canonicalLink.setAttribute('href', canonicalHref);

        var ogUrlMeta = head.querySelector('meta[property="og:url"]');
        if (ogUrlMeta) {
            ogUrlMeta.setAttribute('content', canonicalHref);
        }

        var twitterUrlMeta = head.querySelector('meta[name="twitter:url"]');
        if (twitterUrlMeta) {
            twitterUrlMeta.setAttribute('content', canonicalHref);
        }

        var alternateLinks = head.querySelectorAll('link[rel="alternate"][hreflang]');
        if (alternateLinks.length) {
            alternateLinks.forEach(function (link) {
                link.setAttribute('href', canonicalHref);
            });
        }
    }
    if (emitNavigationEvent) {
        dispatchSpaNavigation(url || canonicalHref);
    }
}

function scheduleCanonicalUpdate(url, emitNavigationEvent) {
    pendingCanonicalUrl = url;
    shouldEmitSpaNavigation = shouldEmitSpaNavigation || !!emitNavigationEvent;
    if (canonicalUpdateScheduled) {
        return;
    }
    canonicalUpdateScheduled = true;
    requestAnimationFrame(function () {
        canonicalUpdateScheduled = false;
        var urlToApply = pendingCanonicalUrl;
        var emitEvent = shouldEmitSpaNavigation;
        pendingCanonicalUrl = null;
        shouldEmitSpaNavigation = false;
        applyCanonical(urlToApply, emitEvent);
    });
}

['pushState', 'replaceState'].forEach(function (method) {
    if (typeof history[method] !== 'function') {
        return;
    }
    var original = history[method];
    history[method] = function () {
        var result = original.apply(this, arguments);
        var url = arguments.length > 2 ? arguments[2] : undefined;
        scheduleCanonicalUpdate(url, true);
        return result;
    };
});

window.addEventListener('popstate', function () {
    scheduleCanonicalUpdate(window.location.href, true);
    salvarConfiguradorAtualSeNecessario();
});

window.addEventListener('hashchange', function () {
    scheduleCanonicalUpdate(window.location.href, false);
});

window.addEventListener('spa:navigation', function (event) {
    var url = event && event.detail ? event.detail.url : undefined;
    salvarConfiguradorAtualSeNecessario(url);
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        scheduleCanonicalUpdate(window.location.href, false);
    });
} else {
    scheduleCanonicalUpdate(window.location.href, false);
}

const APP_VERSION_FALLBACK = 'versao.desconhecida';

let cacheRefreshInProgress = sessionStorage.getItem('cacheRefreshInProgress') === '1';
var AUTH_CALLBACK_PARAMS = window.AUTH_CALLBACK_PARAMS || ['code', 'state', 'id_token', 'access_token', 'error', 'session_state'];
window.AUTH_CALLBACK_PARAMS = AUTH_CALLBACK_PARAMS;

function isAuthCallbackInProgress() {
    try {
        const params = new URLSearchParams(window.location.search || '');
        return AUTH_CALLBACK_PARAMS.some(param => params.has(param));
    } catch (error) {
        return false;
    }
}

async function clearCachesAndReload() {
    if (cacheRefreshInProgress) {
        return;
    }
    if (isAuthCallbackInProgress()) {
        return;
    }
    cacheRefreshInProgress = true;
    sessionStorage.setItem('cacheRefreshInProgress', '1');
    try {
        const keys = await caches.keys();
        await Promise.all(keys.map(k => caches.delete(k)));
    } finally {
        location.reload();
    }
}

window.addEventListener('load', () => {
    sessionStorage.removeItem('cacheRefreshInProgress');
    cacheRefreshInProgress = false;
});

localStorage.getItem("app_version") || localStorage.setItem("app_version", APP_VERSION);
var storedVersion = localStorage.getItem("app_version");
if (storedVersion && storedVersion !== APP_VERSION) {
    localStorage.setItem("app_version", APP_VERSION);
    if (APP_VERSION !== APP_VERSION_FALLBACK) {
        clearCachesAndReload();
    }
}

function updateAppVersion() {
    const el = document.getElementById('app-version');
    if (!el) return;
    const version = localStorage.getItem('app_version') || APP_VERSION;
    el.textContent = `Versão: ${version.replace(/^versao\./, '')}`;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateAppVersion);
} else {
    updateAppVersion();
}

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && (e.key || '').toLowerCase() === 'a') {
        e.stopPropagation();
    }
}, true);

const CONFIGURADOR_STORAGE_KEY = 'ultimoConfiguradorCompleto';

function normalizarDestinoConfigurador(valor) {
    if (!valor || typeof valor !== 'string') {
        return null;
    }

    const texto = valor.trim();
    if (!texto) {
        return null;
    }

    try {
        const url = new URL(texto, window.location.origin);
        if (url.origin !== window.location.origin) {
            return null;
        }
        return `${url.pathname}${url.search}${url.hash}`;
    } catch (err) {
        if (!texto.startsWith('/')) {
            return null;
        }
        return texto;
    }
}

function persistirConfiguradorDestino(caminho) {
    const normalizado = normalizarDestinoConfigurador(caminho);
    if (!normalizado) {
        return;
    }

    try {
        sessionStorage.setItem(CONFIGURADOR_STORAGE_KEY, normalizado);
    } catch (err) { }

    try {
        localStorage.setItem(CONFIGURADOR_STORAGE_KEY, normalizado);
    } catch (err) { }
}

function ehPaginaAreaCliente(pathname = '') {
    if (typeof pathname !== 'string') {
        return false;
    }

    return pathname.trim().toLowerCase().startsWith('/areacliente');
}

function ehPaginaSiteForaAreaCliente(pathname = '') {
    if (!pathname || typeof pathname !== 'string') {
        return false;
    }

    const texto = pathname.trim();
    if (!texto || !texto.startsWith('/')) {
        return false;
    }

    return !ehPaginaAreaCliente(texto);
}

function ehPaginaConfigurador(pathname = '', search = '') {
    if (!ehPaginaSiteForaAreaCliente(pathname)) {
        return false;
    }

    const possuiIndicadorConfigurador = /Configurador/i.test(pathname);
    const possuiParametroConfigurador = typeof search === 'string' && search.includes('QULN=');

    if (possuiIndicadorConfigurador || possuiParametroConfigurador) {
        return true;
    }

    return true;
}

function obterDadosUrlParaConfigurador(targetUrl) {
    function adicionarOrigin(origens, origin) {
        if (!origin || typeof origin !== 'string') {
            return;
        }
        if (origens.indexOf(origin) === -1) {
            origens.push(origin);
        }
    }

    function obterOriginsPermitidos() {
        const origens = [];
        try {
            if (window.location && window.location.origin) {
                adicionarOrigin(origens, window.location.origin);
            }
        } catch (err) { }

        try {
            if (window.location && window.location.protocol && window.location.host) {
                adicionarOrigin(origens, window.location.protocol + '//' + window.location.host);
            }
        } catch (err) { }

        try {
            adicionarOrigin(origens, new URL(resolveCanonicalOrigin(CANONICAL_ORIGIN_FALLBACK)).origin);
        } catch (err) { }

        return origens;
    }

    function origemEhPermitida(origin, permitidas) {
        if (!origin) {
            return true;
        }
        return permitidas.indexOf(origin) !== -1;
    }

    function dadosDaLocalizacaoAtual() {
        try {
            const atual = window.location || {};
            const pathname = typeof atual.pathname === 'string' ? atual.pathname : '';
            if (!pathname) {
                return null;
            }
            return {
                pathname: pathname,
                search: typeof atual.search === 'string' ? atual.search : '',
                hash: typeof atual.hash === 'string' ? atual.hash : ''
            };
        } catch (err) {
            return null;
        }
    }

    const permitidas = obterOriginsPermitidos();
    const baseOrigin = permitidas.length ? permitidas[0] : undefined;

    if (!targetUrl) {
        return dadosDaLocalizacaoAtual();
    }

    if (typeof targetUrl === 'string') {
        try {
            const url = new URL(targetUrl, baseOrigin);
            if (!origemEhPermitida(url.origin, permitidas)) {
                return dadosDaLocalizacaoAtual();
            }
            return {
                pathname: url.pathname,
                search: url.search,
                hash: url.hash
            };
        } catch (err) {
            return dadosDaLocalizacaoAtual();
        }
    }

    if (typeof targetUrl === 'object' && targetUrl !== null) {
        const caminho = typeof targetUrl.pathname === 'string' ? targetUrl.pathname : '';
        if (caminho) {
            return {
                pathname: caminho,
                search: typeof targetUrl.search === 'string' ? targetUrl.search : '',
                hash: typeof targetUrl.hash === 'string' ? targetUrl.hash : ''
            };
        }
        if (typeof targetUrl.href === 'string') {
            return obterDadosUrlParaConfigurador(targetUrl.href);
        }
    }

    return dadosDaLocalizacaoAtual();
}

function sincronizarRetornoPosLogin(destino) {
    if (!destino) {
        return;
    }
    try {
        sessionStorage.setItem('retornoPosLogin', destino);
    } catch (err) { }
}

function salvarConfiguradorAtualSeNecessario(targetUrl) {
    try {
        let dados = obterDadosUrlParaConfigurador(targetUrl);
        const dadosAtuais = obterDadosUrlParaConfigurador();
        if (dadosAtuais && dados && dadosAtuais.pathname === dados.pathname) {
            const searchAtual = typeof dadosAtuais.search === 'string' ? dadosAtuais.search : '';
            const searchInformado = typeof dados.search === 'string' ? dados.search : '';
            if (searchAtual && searchAtual !== searchInformado) {
                dados = dadosAtuais;
            }
        }
        if (!dados || !dados.pathname) {
            return;
        }

        const pathname = dados.pathname;
        const search = typeof dados.search === 'string' ? dados.search : '';
        const hash = typeof dados.hash === 'string' ? dados.hash : '';
        if (!pathname || ehPaginaAreaCliente(pathname)) {
            return;
        }

        const caminhoNormalizado = pathname.replace(/\/+$/g, '') || '/';
        if (caminhoNormalizado === '/' || caminhoNormalizado === '/RedutoresIBR.html') {
            return;
        }

        if (!ehPaginaConfigurador(pathname, search)) {
            return;
        }

        const atual = pathname + search + hash;
        persistirConfiguradorDestino(atual);
        sincronizarRetornoPosLogin(atual);
    } catch (err) {
        // Ignora erros de acesso ao sessionStorage
    }
}

function buildVersionedChunkUrl(path) {
    if (typeof window !== 'undefined' && typeof window.buildVersionedAssetUrl === 'function') {
        return window.buildVersionedAssetUrl(path);
    }
    return path;
}

function loadOptionalLayoutChunk(path, attributes = {}) {
    if (typeof document === 'undefined') {
        return null;
    }

    const script = document.createElement('script');
    script.async = attributes.async !== false;
    script.defer = attributes.defer !== false;
    if (attributes.id) {
        script.id = attributes.id;
    }
    if (attributes.crossorigin) {
        script.crossOrigin = attributes.crossorigin;
    }
    script.src = buildVersionedChunkUrl(path);
    (document.head || document.body || document.documentElement).appendChild(script);
    return script;
}

function shouldLoadLoaderChunk() {
    if (typeof document === 'undefined') {
        return false;
    }
    return Boolean(document.getElementById('loader'));
}

function shouldLoadLgpdChunk() {
    if (typeof document === 'undefined') {
        return false;
    }

    if (window.disableLgpdCookies === true || window.LGPD_DISABLE === true) {
        return false;
    }

    const keyEl = document.getElementById('lgpd-key');
    const remoteFlag = typeof window.__LGPD_ENABLE_REMOTE_ENDPOINTS === 'boolean'
        ? window.__LGPD_ENABLE_REMOTE_ENDPOINTS
        : Boolean(keyEl && (keyEl.getAttribute('data-lgpd-remote-endpoints') || '').toLowerCase() === 'true');

    if (!keyEl && !remoteFlag) {
        return false;
    }

    const docEl = document.documentElement;
    if (docEl) {
        if (docEl.classList && docEl.classList.contains('lgpd-disabled')) {
            return false;
        }
        if (docEl.dataset && docEl.dataset.lgpdDisabled === 'true') {
            return false;
        }
    }

    const body = document.body;
    if (body) {
        if (body.classList && body.classList.contains('lgpd-disabled')) {
            return false;
        }
        if (body.dataset && body.dataset.lgpdDisabled === 'true') {
            return false;
        }
    }

    if (keyEl && (keyEl.getAttribute('data-disable-lgpd') === 'true' || keyEl.hasAttribute('data-disable-lgpd'))) {
        return false;
    }

    return true;
}

function bootstrapOptionalLayoutChunks() {
    if (shouldLoadLoaderChunk()) {
        loadOptionalLayoutChunk('/Cookies/CarregarCookies.min.js', { id: 'layout-loader-module' });
    }

    const loadLgpdChunk = () => {
        if (!shouldLoadLgpdChunk()) {
            return;
        }
        loadOptionalLayoutChunk('/Cookies/LayoutCookies.min.js', { id: 'layout-lgpd-module' });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadLgpdChunk, { once: true });
    } else {
        loadLgpdChunk();
    }
}

bootstrapOptionalLayoutChunks();

(function setupFirstAccessContentPriority() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    function getCookieValue(name) {
        if (!name) {
            return '';
        }
        var cookies = document.cookie ? document.cookie.split('; ') : [];
        for (var i = 0; i < cookies.length; i += 1) {
            var entry = cookies[i];
            if (entry.startsWith(name + '=')) {
                return entry.slice(name.length + 1);
            }
        }
        return '';
    }

    function getLgpdConsentCookieName() {
        var keyEl = document.getElementById('lgpd-key');
        var prefix = keyEl ? keyEl.getAttribute('prefix') : '';
        var normalizedPrefix = prefix ? prefix + '-' : '';
        return normalizedPrefix + 'lgpd-cookies-consent';
    }

    function isFirstAccessConsentState() {
        if (typeof window.LGPDCOOKIES !== 'undefined' && typeof window.LGPDCOOKIES.cookiesConsent === 'string') {
            return window.LGPDCOOKIES.cookiesConsent === '';
        }
        return getCookieValue(getLgpdConsentCookieName()) === '';
    }

    function runAfterInitialLoaderHidden(callback) {
        if (typeof callback !== 'function') {
            return;
        }

        var root = document.documentElement;
        var loaderAlreadyHidden = !root
            || (root.getAttribute('data-loading') !== 'true'
                && !root.classList.contains('is-initial-loading'));

        if (loaderAlreadyHidden) {
            callback();
            return;
        }

        window.addEventListener('initial-loader-hidden', callback, { once: true });
    }

    var LGPD_FIRST_ACCESS_ATTR = 'data-lgpd-first-access';
    var LGPD_FIRST_ACCESS_PREMEASURE_CLASS = 'lgpd-cookie-footer--premeasure';
    var LGPD_FIRST_ACCESS_MIN_HEIGHT_TOKEN = 'var(--lgpd-cookie-banner-min-height-first-access, 176px)';

    function setLgpdFirstAccessState(isFirstAccess) {
        var root = document.documentElement;
        if (!root) {
            return;
        }

        if (isFirstAccess) {
            root.setAttribute(LGPD_FIRST_ACCESS_ATTR, 'true');
            if (!root.style.getPropertyValue('--lgpd-cookie-banner-height')) {
                root.style.setProperty('--lgpd-cookie-banner-height', LGPD_FIRST_ACCESS_MIN_HEIGHT_TOKEN);
            }
            return;
        }

        root.removeAttribute(LGPD_FIRST_ACCESS_ATTR);
    }

    function ensureFirstAccessBannerPostLoader() {
        if (!shouldLoadLgpdChunk() || IS_AREA_CLIENTE_PATH) {
            setLgpdFirstAccessState(false);
            return;
        }

        if (!isFirstAccessConsentState()) {
            setLgpdFirstAccessState(false);
            return;
        }

        setLgpdFirstAccessState(true);

        if (window.LGPDCOOKIES && typeof window.LGPDCOOKIES.openFooter === 'function') {
            window.LGPDCOOKIES.openFooter();
            return;
        }

        var footer = document.getElementById('lgpdCookieFooter');
        var shield = document.getElementById('lgpdShieldFooter');
        if (footer) {
            footer.removeAttribute('hidden');
            footer.setAttribute('aria-hidden', 'false');
            footer.classList.add(LGPD_FIRST_ACCESS_PREMEASURE_CLASS);
        }
        if (shield) {
            shield.setAttribute('hidden', 'true');
            shield.setAttribute('aria-hidden', 'true');
        }
        updateLgpdCookieBannerSpacing(footer, true, { isFirstAccess: true });
    }

    var lgpdBannerMeasureHandle = null;

    function updateLgpdCookieBannerSpacing(footer, shouldShow, options) {
        var root = document.documentElement;
        if (!root) {
            return;
        }

        var isFirstAccess = Boolean(options && options.isFirstAccess);

        if (!footer || !shouldShow) {
            root.style.setProperty('--lgpd-cookie-banner-height', '0');
            setLgpdFirstAccessState(false);
            if (footer && footer.classList) {
                footer.classList.remove(LGPD_FIRST_ACCESS_PREMEASURE_CLASS);
            }
            return;
        }

        if (isFirstAccess) {
            setLgpdFirstAccessState(true);
            root.style.setProperty('--lgpd-cookie-banner-height', LGPD_FIRST_ACCESS_MIN_HEIGHT_TOKEN);
        }

        var schedule = (typeof requestAnimationFrame === 'function')
            ? requestAnimationFrame
            : function (callback) { return setTimeout(callback, 0); };

        if (lgpdBannerMeasureHandle) {
            if (typeof cancelAnimationFrame === 'function') {
                cancelAnimationFrame(lgpdBannerMeasureHandle);
            } else {
                clearTimeout(lgpdBannerMeasureHandle);
            }
            lgpdBannerMeasureHandle = null;
        }

        lgpdBannerMeasureHandle = schedule(function () {
            lgpdBannerMeasureHandle = null;
            var height = 0;
            if (typeof footer.getBoundingClientRect === 'function') {
                var rect = footer.getBoundingClientRect();
                height = Math.max(0, Math.ceil(rect.height || footer.offsetHeight || 0));
            }

            var resolvedHeight = height ? height + 'px' : (isFirstAccess ? LGPD_FIRST_ACCESS_MIN_HEIGHT_TOKEN : '0');
            root.style.setProperty('--lgpd-cookie-banner-height', resolvedHeight);

            if (footer.classList) {
                if (isFirstAccess) {
                    schedule(function () {
                        footer.classList.remove(LGPD_FIRST_ACCESS_PREMEASURE_CLASS);
                    });
                } else {
                    footer.classList.remove(LGPD_FIRST_ACCESS_PREMEASURE_CLASS);
                }
            }
        });
    }

    function injectCriticalFirstAccessStyles() {
        if (document.querySelector('style[data-first-access-critical]')) {
            return;
        }

        var style = document.createElement('style');
        style.setAttribute('data-first-access-critical', 'true');
        style.textContent = '#main-content{display:block;min-height:5vh;}';
        (document.head || document.documentElement).appendChild(style);
    }

    function prioritizeAboveFoldImages() {
        var main = document.getElementById('main-content') || document.querySelector('main');
        if (!main) {
            return;
        }

        var viewportHeight = window.innerHeight || 0;
        var images = Array.from(main.querySelectorAll('img'));
        var criticalImages = images.filter(function (img) {
            var rect = img.getBoundingClientRect();
            return rect.top >= 0 && rect.top < viewportHeight;
        });

        criticalImages.slice(0, 2).forEach(function (img) {
            if (!img.getAttribute('fetchpriority')) {
                img.setAttribute('fetchpriority', 'high');
            }
            if (img.getAttribute('loading') === 'lazy') {
                img.setAttribute('loading', 'eager');
            }
        });
    }

    function applyFirstAccessPriority() {
        if (!isFirstAccessConsentState()) {
            setLgpdFirstAccessState(false);
            return;
        }

        setLgpdFirstAccessState(true);
        injectCriticalFirstAccessStyles();
        prioritizeAboveFoldImages();
    }

    runAfterInitialLoaderHidden(ensureFirstAccessBannerPostLoader);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyFirstAccessPriority, { once: true });
    } else {
        applyFirstAccessPriority();
    }
})();

(function setupInitialLoaderReadyListeners() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    var SHELL_READY_EVENT = 'page-shell-ready';
    var AREA_CLIENTE_LOADER_MIN_MS = 5000;
    var INITIAL_CONTENT_FALLBACK_DELAY = 2500;
    var INITIAL_CONTENT_HARD_TIMEOUT = 8000;
    var INITIAL_CONTENT_QUERYSTRING_MAX_WAIT = 45000;
    var handled = false;
    var shellReadyEmitted = false;
    var fallbackTimer = null;
    var hardTimeoutTimer = null;
    var isAreaClienteRoute = false;
    var initialLoaderStartTimestamp = (typeof performance !== 'undefined' && typeof performance.now === 'function')
        ? performance.now()
        : Date.now();

    function getTimestampNow() {
        return (typeof performance !== 'undefined' && typeof performance.now === 'function')
            ? performance.now()
            : Date.now();
    }

    function isAreaClientePath() {
        var pathname = (window.location && typeof window.location.pathname === 'string')
            ? window.location.pathname
            : '';
        return pathname === '/AreaCliente' || pathname.indexOf('/AreaCliente/') === 0;
    }

    isAreaClienteRoute = isAreaClientePath();

    function finalizeInitialContentReady() {
        if (!Boolean(window.keepLoading) && typeof window.hideLoadingScreen === 'function') {
            window.hideLoadingScreen(true);
        } else if (typeof window.requestLoaderHide === 'function') {
            window.requestLoaderHide();


        }
    }

    function clearFallbackTimer() {
        if (fallbackTimer) {
            clearTimeout(fallbackTimer);
            fallbackTimer = null;
        }

        if (hardTimeoutTimer) {
            clearTimeout(hardTimeoutTimer);
            hardTimeoutTimer = null;
        }
    }

    function handleInitialContentReady(source) {
        var readySource = source || 'unknown';

        if (isAreaClienteRoute && readySource !== 'shell-ready' && readySource !== 'hard-timeout') {
            return;
        }

        if (handled) {
            return;
        }
        handled = true;
        clearFallbackTimer();

        if (isAreaClienteRoute) {
            var elapsed = getTimestampNow() - initialLoaderStartTimestamp;
            var remaining = Math.max(0, AREA_CLIENTE_LOADER_MIN_MS - elapsed);
            if (remaining > 0) {
                window.setTimeout(finalizeInitialContentReady, remaining);
                return;
            }
        }

        finalizeInitialContentReady();
    }

    function emitShellReadyEvent(detailSource) {
        if (shellReadyEmitted) {
            return;
        }
        shellReadyEmitted = true;

        try {
            var event;
            if (typeof window.CustomEvent === 'function') {
                event = new CustomEvent(SHELL_READY_EVENT, { detail: { source: detailSource } });
            } else if (document.createEvent) {
                event = document.createEvent('CustomEvent');
                if (event && event.initCustomEvent) {
                    event.initCustomEvent(SHELL_READY_EVENT, false, false, { source: detailSource });
                }
            }

            if (event) {
                window.dispatchEvent(event);
            }
        } catch (error) {
            console.warn('Initial loader: falha ao disparar evento de shell pronto.', error);
        }
    }

    var onceOptions = { once: true };
    document.addEventListener(SHELL_READY_EVENT, function () { handleInitialContentReady('shell-ready'); }, onceOptions);

    if (!isAreaClienteRoute) {
        document.addEventListener('DOMContentLoaded', function () { handleInitialContentReady('dom-content-loaded'); }, onceOptions);
    }

    function scheduleShellReadyFallback() {
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            requestAnimationFrame(function () { emitShellReadyEvent('layout-fallback'); });
            return;
        }

        document.addEventListener(
            'DOMContentLoaded',
            function () { emitShellReadyEvent('layout-dom-content-loaded'); },
            onceOptions
        );
    }


    if (!isAreaClienteRoute && document.readyState !== 'loading') {
        handleInitialContentReady('document-ready');
    }

    if (!isAreaClienteRoute) {
        scheduleShellReadyFallback();
        fallbackTimer = window.setTimeout(function () { handleInitialContentReady('fallback-timer'); }, INITIAL_CONTENT_FALLBACK_DELAY);
    }

    hardTimeoutTimer = window.setTimeout(function forceClearInitialLoadingState() {
        var root = document.documentElement;
        var stillWaitingLoader = Boolean(
            root
            && root.classList
            && root.classList.contains('is-initial-loading')
        );

        if (!stillWaitingLoader) {
            return;
        }

        if (Boolean(window.keepLoading)) {
            hardTimeoutTimer = window.setTimeout(function forceClearInitialLoadingStateAfterQueryWait() {
                var rootAfterQueryWait = document.documentElement;
                var stillWaitingAfterQueryWait = Boolean(
                    rootAfterQueryWait
                    && rootAfterQueryWait.classList
                    && rootAfterQueryWait.classList.contains('is-initial-loading')
                );

                if (!stillWaitingAfterQueryWait) {
                    return;
                }

                try {
                    window.keepLoading = false;
                } catch (error) {
                    console.warn('Initial loader: falha ao limpar keepLoading no timeout estendido.', error);
                }

                handleInitialContentReady('hard-timeout');
            }, Math.max(0, INITIAL_CONTENT_QUERYSTRING_MAX_WAIT - INITIAL_CONTENT_HARD_TIMEOUT));
            return;
        }

        try {
            window.keepLoading = false;
        } catch (error) {
            console.warn('Initial loader: falha ao limpar keepLoading no timeout de contingência.', error);
        }

        handleInitialContentReady('hard-timeout');
    }, INITIAL_CONTENT_HARD_TIMEOUT);
})();

document.addEventListener("DOMContentLoaded", () => {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function validarEmail(email) {
        return emailRegex.test(email);
    }

    document.querySelectorAll('input[type="email"]').forEach((original) => {
        const wrapper = document.createElement("div");
        const originalClasses = original.className || "";
        wrapper.className = `${originalClasses} email-multi-wrapper`.trim();
        const id = original.id;
        const placeholder = original.getAttribute("placeholder") || "";
        const required = original.hasAttribute("required");
        const initialValue = original.value || "";
        const originalAutocomplete = original.getAttribute("autocomplete");
        const originalInputMode = original.getAttribute("inputmode");

        original.type = "hidden";
        original.id = id ? `${id}-hidden` : "";
        original.className = "";
        original.value = "";
        if (required) original.removeAttribute("required");

        const input = document.createElement("input");
        input.type = "text";
        if (id) input.id = id;
        if (required) input.setAttribute("required", "");
        input.placeholder = placeholder;
        input.className = `${originalClasses} email-multi-input`.trim();
        if (originalAutocomplete) input.setAttribute("autocomplete", originalAutocomplete);
        else input.setAttribute("autocomplete", "email");
        if (originalInputMode) input.setAttribute("inputmode", originalInputMode);
        else input.setAttribute("inputmode", "email");

        original.parentNode.insertBefore(wrapper, original);
        wrapper.appendChild(original);
        wrapper.appendChild(input);

        const valueDescriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value");
        const getValor = () => valueDescriptor.get.call(input);
        const setValor = (v) => valueDescriptor.set.call(input, v);

        function atualizarValor(wrapper, lista) {
            const hidden = wrapper.querySelector("input[type='hidden']");
            if (hidden) hidden.value = lista.join(";");

            const hasConteudo = lista.length > 0 || getValor().trim() !== "";
            wrapper.classList.toggle("email-has-value", hasConteudo);
        }

        function destacar(wrapper) {
            wrapper.classList.add("email-added");
            setTimeout(() => wrapper.classList.remove("email-added"), 200);
        }

        function criarBlocoEmail(email, wrapper, lista) {
            const bloco = document.createElement("span");
            bloco.className = "email-chip";
            bloco.title = email;

            const texto = document.createElement("span");
            texto.className = "email-chip-text";
            texto.textContent = email;
            bloco.appendChild(texto);

            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "email-chip-remove";
            btn.textContent = "×";
            btn.addEventListener("click", () => {
                const idx = lista.indexOf(email);
                if (idx > -1) lista.splice(idx, 1);
                bloco.remove();
                atualizarValor(wrapper, lista);
                destacar(wrapper);
            });

            bloco.appendChild(btn);
            wrapper.insertBefore(bloco, input);
            destacar(wrapper);
        }

        const emails = [];

        function sincronizarEmails(valor) {
            const listaNormalizada = (valor || "")
                .split(/[;,]/)
                .map((parte) => parte.toLowerCase().trim())
                .filter(Boolean);

            if (emails.join(";") === listaNormalizada.join(";")) {
                atualizarValor(wrapper, emails);
                return;
            }

            wrapper.querySelectorAll(".email-chip").forEach((chip) => chip.remove());
            emails.length = 0;
            listaNormalizada.forEach((email) => {
                emails.push(email);
                criarBlocoEmail(email, wrapper, emails);
            });
            setValor("");
            atualizarValor(wrapper, emails);
        }

        function adicionarEmail(valor, wrapper, lista) {
            const email = valor.toLowerCase().trim();
            if (!email) return;
            if (!validarEmail(email)) {
                exibirAlerta(`❌ E-mail inválido: ${email}`);
                return;
            }
            if (lista.includes(email)) return;
            lista.push(email);
            criarBlocoEmail(email, wrapper, lista);
            setValor("");
            atualizarValor(wrapper, lista);
        }

        function processarEntrada(wrapper, lista) {
            const partes = getValor().split(";");
            if (partes.length > 1) {
                partes.slice(0, -1).forEach((p) => adicionarEmail(p, wrapper, lista));
                setValor(partes[partes.length - 1]);
            }
        }

        wrapper.addEventListener("click", () => input.focus());

        sincronizarEmails(initialValue);
        setTimeout(() => sincronizarEmails(original.value), 0);

        input.addEventListener("input", () => {
            setValor(getValor().toLowerCase().replace(/\s+/g, ""));
            processarEntrada(wrapper, emails);
            atualizarValor(wrapper, emails);
        });

        input.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === ";") {
                e.preventDefault();
                processarEntrada(wrapper, emails);
                adicionarEmail(getValor(), wrapper, emails);
            } else if (e.key === "Backspace" && getValor() === "" && emails.length) {
                e.preventDefault();
                emails.pop();
                const chip = wrapper.querySelectorAll(".email-chip");
                if (chip.length) chip[chip.length - 1].remove();
                atualizarValor(wrapper, emails);
            }
        });

        input.addEventListener("blur", () => {
            processarEntrada(wrapper, emails);
            adicionarEmail(getValor(), wrapper, emails);
        });

        Object.defineProperty(input, "value", {
            get() { return original.value; },
            set(v) {
                setValor(v);
                original.value = v;
                if (document.activeElement !== input) {
                    sincronizarEmails(v);
                } else {
                    atualizarValor(wrapper, emails);
                }
            },
        });


        const form = original.form;
        if (form) {
            form.addEventListener("submit", () => {
                processarEntrada(wrapper, emails);
                adicionarEmail(getValor(), wrapper, emails);
                atualizarValor(wrapper, emails);
            });
        }
    });
});

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".site-search-input").forEach((input) => {
        input.addEventListener("input", () => {
            input.value = input.value.toUpperCase();
        });
    });
});

document.addEventListener("DOMContentLoaded", () => {
    const codigoRegex = /^[A-Z]{2,4}\.[0-9]{8}$/;
    const referenciaRegex = /^(?=.{1,250}$)[0-9A-Z]+(\.[0-9A-Z]+){2,}$/;
    const sugestaoEndpoint = "/PaginasConsultaProdutos/SugestoesProduto.php";
    const permitidoRegex = /^[0-9A-ZÀ-ÖØ-öø-ÿ.\s-]+$/i;
    const minSugestaoChars = 2;
    const sugestaoDebounceMs = 80;

    async function parseJSON(response) {
        try {
            const text = await response.text();
            return text ? JSON.parse(text) : null;
        } catch {
            return null;
        }
    }

    function registrarEventoBusca(nome, detalhes = {}) {
        if (typeof window.sendEvent !== "function") return;
        window.sendEvent(nome, detalhes);
    }

    function validarPesquisaProduto(valor) {
        if (codigoRegex.test(valor)) return "codigo";
        if (referenciaRegex.test(valor)) return "referencia";
        return null;
    }

    function tratarBuscaProduto(input) {
        const valor = input.value.trim();
        if (valor && !permitidoRegex.test(valor)) {
            input.classList.add("campo-invalido");
            exibirAlerta(
                "❌ Use apenas letras, números, espaços, pontos ou hífens.",
            );
            return { valido: false, termo: valor, tipo: null };
        }
        const tipo = validarPesquisaProduto(valor);
        input.classList.remove("campo-invalido");
        return { valido: true, termo: valor, tipo };
    }
    function registrarProdutoBuscado(produto, link) {
        const referencia = (produto || "").trim();
        if (!referencia || !link) return;
        try {
            fetch(
                "/PaginasAreaClienteSessaoPerfil/RegistrarProduto.php",
                {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: new URLSearchParams({ produto: referencia, link }),
                    keepalive: true,
                },
            ).catch(() => { });
        } catch { }
    }
    async function buscarProdutoSemantico(valor) {
        const termo = (valor || "").trim();
        if (!termo) return null;
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 1500);
            const r = await fetch(`/PaginasPrincipal/BuscarSemantico?termo=${encodeURIComponent(termo)}`, { signal: controller.signal });
            clearTimeout(timeoutId);
            if (!r.ok) return null;
            const d = await parseJSON(r);
            if (!d || !Array.isArray(d.resultados) || !d.resultados.length) return null;
            return d.resultados[0];
        } catch {
            return null;
        }
    } function exibirMensagemNaoEncontrado() {
        exibirAlerta(
            "Ops! 😔 Não encontramos esse produto ou o código está inativo.<br>" +
            "Mas não se preocupe! Você pode configurar um novo produto aqui mesmo e solicitar o cadastro, " +
            "ou então gerar um novo código rapidinho. Se precisar de ajuda, estamos à disposição! 😊"
        );
    }
    async function buscarProdutoPorTrecho(valor) {
        const termo = (valor || "").trim();
        if (!termo) return null;
        try {
            const resposta = await fetch(
                `${sugestaoEndpoint}?termo=${encodeURIComponent(termo)}`,
            );
            if (!resposta.ok) return null;
            const dados = await parseJSON(resposta);
            const itens = Array.isArray(dados?.resultados) ? dados.resultados : [];
            if (!itens.length) return null;
            const codigo = (itens[0]?.codigo || "").trim();
            return codigo || null;
        } catch {
            return null;
        }
    }
    async function buscarProduto(valor) {
        valor = (valor || "").trim();
        if (!valor) return;
        try {
            const r = await fetch(
                "/PaginasConsultaProdutos/EstruturaProduto.php",
                {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: new URLSearchParams({ valor }),
                },
            );
            const d = await parseJSON(r) || {};
            if (d.url) {
                const referencia = (d.referencia || valor || "").trim();
                registrarProdutoBuscado(referencia, d.url);
                location.href = d.url;
            } else if (d.notFound || d.erro) {
                const texto = (d.erro || "").trim().toLowerCase();
                const ehNaoEncontrado = d.notFound || (texto.includes("produto") && texto.includes("n\u00e3o encontrado"));
                if (ehNaoEncontrado) {
                    const resultadoSemantico = await buscarProdutoSemantico(valor);
                    if (resultadoSemantico && resultadoSemantico.url) {
                        const referencia = (resultadoSemantico.id || valor || "").trim();
                        registrarProdutoBuscado(referencia, resultadoSemantico.url);
                        location.href = resultadoSemantico.url;
                        return;
                    }
                } else if (d.erro) {
                    exibirAlerta(d.erro);
                }
            }
        } catch (e) {
            console.error(e);
        }
    }
    async function executarBusca(input) {
        if (!input) return;
        const resultado = tratarBuscaProduto(input);
        if (!resultado.valido || !resultado.termo) return;
        if (resultado.tipo) {
            buscarProduto(resultado.termo);
            return;
        }
        const codigoTrecho = await buscarProdutoPorTrecho(resultado.termo);
        if (codigoTrecho) {
            buscarProduto(codigoTrecho);
            return;
        }
        const resultadoSemantico = await buscarProdutoSemantico(resultado.termo);
        if (resultadoSemantico && resultadoSemantico.url) {
            registrarProdutoBuscado(resultadoSemantico.id || resultado.termo, resultadoSemantico.url);
            location.href = resultadoSemantico.url;
            return;
        }
        exibirMensagemNaoEncontrado();
    }

    document.querySelectorAll(".site-search-submit").forEach((btn) => {
        btn.addEventListener("click", (e) => {
            e.preventDefault();
            const inp = btn.closest("form")?.querySelector(".site-search-input");
            if (inp) executarBusca(inp);
        });
    });

    document.querySelectorAll(".site-search-form").forEach((form) => {
        form.addEventListener("submit", (e) => {
            e.preventDefault();
            const inp = form.querySelector(".site-search-input");
            if (inp) executarBusca(inp);
        });
    });

    const estadosSugestoes = new Map();

    function obterEstadoSugestao(input) {
        if (estadosSugestoes.has(input)) return estadosSugestoes.get(input);
        const container = input.closest(".header__menu__search")?.querySelector(".site-search-autocomplete");
        const estado = {
            container,
            itens: [],
            selecionado: -1,
            ultimoTermo: "",
            ultimoTermoCarregado: "",
            timerId: null,
            controller: null,
            cache: new Map(),
        };
        estadosSugestoes.set(input, estado);
        return estado;
    }

    function limparSugestoes(estado, input) {
        if (!estado?.container) return;
        estado.itens = [];
        estado.selecionado = -1;
        estado.container.innerHTML = "";
        estado.container.classList.remove("show");
        if (input) {
            input.removeAttribute("aria-activedescendant");
            input.setAttribute("aria-expanded", "false");
        }
    }

    function atualizarSelecaoSugestao(estado, input) {
        if (!estado?.container) return;
        const opcoes = Array.from(estado.container.querySelectorAll("[role=\"option\"]"));
        opcoes.forEach((opcao, index) => {
            opcao.classList.toggle("active", index === estado.selecionado);
            opcao.setAttribute("aria-selected", index === estado.selecionado ? "true" : "false");
        });
        if (input) {
            if (estado.selecionado >= 0 && opcoes[estado.selecionado]) {
                input.setAttribute("aria-activedescendant", opcoes[estado.selecionado].id);
            } else {
                input.removeAttribute("aria-activedescendant");
            }
        }
    }

    function selecionarSugestao(estado, input, index) {
        if (!estado || !input || !estado.itens[index]) return;
        const item = estado.itens[index];
        input.value = item.codigo || "";
        registrarEventoBusca("pesquisar-produto-cabecalho-recomendado", {
            event_category: "Busca",
            event_label: "recomendacao",
        });
        limparSugestoes(estado, input);
        executarBusca(input);
    }

    function montarSugestoes(estado, input, itens) {
        if (!estado?.container) return;
        estado.itens = itens;
        estado.selecionado = -1;
        estado.container.innerHTML = "";
        if (!itens.length) {
            estado.container.classList.remove("show");
            return;
        }
        const ul = document.createElement("ul");
        ul.setAttribute("role", "presentation");
        itens.forEach((item, index) => {
            const li = document.createElement("li");
            const a = document.createElement("a");
            a.href = "#";
            a.id = `site-search-sugestao-${input.id}-${index}`;
            a.setAttribute("role", "option");
            a.setAttribute("aria-selected", "false");
            a.textContent = item.rotulo;
            a.addEventListener("mousedown", (e) => {
                e.preventDefault();
                selecionarSugestao(estado, input, index);
            });
            a.addEventListener("mouseenter", () => {
                estado.selecionado = index;
                atualizarSelecaoSugestao(estado, input);
            });
            li.appendChild(a);
            ul.appendChild(li);
        });
        estado.container.appendChild(ul);
        estado.container.classList.add("show");
        if (input) input.setAttribute("aria-expanded", "true");
    }

    function construirRotuloSugestao(item) {
        const partes = [];
        if (item.codigo) partes.push(item.codigo);
        if (item.descricao) partes.push(item.descricao);
        if (item.referencia) partes.push(item.referencia);
        return partes.join(" - ");
    }

    async function carregarSugestoes(termo, estado, input) {
        if (!estado?.container) return;
        if (estado.cache.has(termo)) {
            montarSugestoes(estado, input, estado.cache.get(termo));
            estado.ultimoTermoCarregado = termo;
            return;
        }
        if (estado.controller) {
            estado.controller.abort();
        }
        estado.controller = new AbortController();
        try {
            const resposta = await fetch(
                `${sugestaoEndpoint}?termo=${encodeURIComponent(termo)}`,
                { signal: estado.controller.signal }
            );
            if (!resposta.ok) {
                montarSugestoes(estado, input, []);
                return;
            }
            const dados = await resposta.json();
            const itens = Array.isArray(dados?.resultados) ? dados.resultados : [];
            const formatados = itens.map((item) => ({
                codigo: (item.codigo || "").trim(),
                descricao: (item.descricao || "").trim(),
                referencia: (item.referencia || "").trim(),
                rotulo: construirRotuloSugestao(item),
            })).filter((item) => item.rotulo);
            estado.cache.set(termo, formatados);
            estado.ultimoTermoCarregado = termo;
            montarSugestoes(estado, input, formatados);
        } catch (error) {
            if (error?.name !== "AbortError") {
                montarSugestoes(estado, input, []);
            }
        }
    }

    function agendarSugestoes(input) {
        const estado = obterEstadoSugestao(input);
        if (!estado?.container) return;
        const termo = (input.value || "").trim();
        estado.ultimoTermo = termo;
        if (estado.timerId) {
            clearTimeout(estado.timerId);
        }
        if (!termo || termo.length < minSugestaoChars) {
            limparSugestoes(estado, input);
            return;
        }
        if (estado.cache.has(termo)) {
            montarSugestoes(estado, input, estado.cache.get(termo));
            return;
        }
        estado.timerId = window.setTimeout(() => {
            if (estado.ultimoTermo !== termo) return;
            carregarSugestoes(termo, estado, input);
        }, sugestaoDebounceMs);
    }

    document.querySelectorAll(".site-search-input").forEach((input) => {
        const estado = obterEstadoSugestao(input);
        const containerId = estado?.container?.id;
        if (containerId) {
            if (input.getAttribute("role") === "combobox") {
                input.setAttribute("aria-autocomplete", "list");
            } else {
                input.removeAttribute("aria-autocomplete");
            }
            input.setAttribute("aria-controls", containerId);
            input.setAttribute("aria-expanded", "false");
            input.setAttribute("aria-haspopup", "listbox");
        }
        input.addEventListener("input", () => {
            agendarSugestoes(input);
        });
        input.addEventListener("focus", () => {
            agendarSugestoes(input);
        });
        input.addEventListener("keydown", (e) => {
            if (!estado?.container || !estado.container.classList.contains("show")) return;
            if (["ArrowDown", "ArrowUp"].includes(e.key)) {
                e.preventDefault();
                if (!estado.itens.length) return;
                const delta = e.key === "ArrowDown" ? 1 : -1;
                estado.selecionado += delta;
                if (estado.selecionado >= estado.itens.length) {
                    estado.selecionado = 0;
                }
                if (estado.selecionado < 0) {
                    estado.selecionado = estado.itens.length - 1;
                }
                atualizarSelecaoSugestao(estado, input);
            } else if (e.key === "Enter") {
                if (estado.selecionado >= 0) {
                    e.preventDefault();
                    selecionarSugestao(estado, input, estado.selecionado);
                } else {
                    e.preventDefault();
                    limparSugestoes(estado, input);
                    executarBusca(input);
                }
            } else if (e.key === "Escape") {
                limparSugestoes(estado, input);
            }
        });
        input.addEventListener("blur", () => {
            window.setTimeout(() => {
                limparSugestoes(estado, input);
            }, 150);
        });
    });

    document.addEventListener("click", (e) => {
        document.querySelectorAll(".site-search-input").forEach((input) => {
            const estado = obterEstadoSugestao(input);
            if (!estado?.container) return;
            if (estado.container.contains(e.target) || input === e.target) return;
            limparSugestoes(estado, input);
        });
    });
});

document.addEventListener("DOMContentLoaded", () => {
    document
        .querySelectorAll(".menu-icons-desktop a, .menu__block a")
        .forEach((link) => {
            const href = link.href.replace(/\/$/, "");
            const loc = location.href.replace(/\/$/, "");
            if (href === loc) link.setAttribute("aria-current", "page");
        });
});

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll('input[type="password"]').forEach((input) => {
        const warn = document.createElement("div");
        warn.className = "caps-warning mt-1 d-none";
        warn.style.color = "#ec4115";
        warn.style.fontSize = "0.875rem";
        warn.textContent = "Caps Lock ativado";
        input.insertAdjacentElement("afterend", warn);
        const toggle = (e) => {
            const caps = e.getModifierState && e.getModifierState("CapsLock");
            warn.classList.toggle("d-none", !caps);
        };
        input.addEventListener("keydown", toggle);
        input.addEventListener("keyup", toggle);
        input.addEventListener("focus", toggle);
        input.addEventListener("blur", () => warn.classList.add("d-none"));
    });
});

var THEME_COOKIE_NAME = "modoEscuro";
var THEME_COOKIE_MAX_AGE = 60 * 60 * 24 * 365;
var THEME_COOKIE_DOMAIN = ".redutoresibr.com.br";

function getThemeCookie() {
    if (typeof document === "undefined" || typeof document.cookie !== "string") {
        return null;
    }

    const cookies = document.cookie.split(";").map((row) => row.trim()).filter(Boolean);
    for (const cookie of cookies) {
        if (!cookie.startsWith(`${THEME_COOKIE_NAME}=`)) continue;
        const value = cookie.substring(THEME_COOKIE_NAME.length + 1);
        try {
            return decodeURIComponent(value);
        } catch {
            return value;
        }
    }
    return null;
}

function setThemeCookie(value) {
    if (typeof document === "undefined") {
        return;
    }

    const encodedValue = encodeURIComponent(value);
    const baseAttributes = `; path=/; max-age=${THEME_COOKIE_MAX_AGE}; SameSite=Lax`;
    const domainCookie = `${THEME_COOKIE_NAME}=${encodedValue}${baseAttributes}; domain=${THEME_COOKIE_DOMAIN}`;

    try {
        document.cookie = domainCookie;
        if (getThemeCookie() !== value) {
            document.cookie = `${THEME_COOKIE_NAME}=${encodedValue}${baseAttributes}`;
        }
    } catch {
        document.cookie = `${THEME_COOKIE_NAME}=${encodedValue}${baseAttributes}`;
    }
}

function prefersSystemDarkMode() {
    const source = typeof window !== "undefined"
        ? window
        : typeof globalThis !== "undefined"
            ? globalThis
            : null;
    if (source && typeof source.matchMedia === "function") {
        try {
            return source.matchMedia("(prefers-color-scheme: dark)").matches;
        } catch { }
    }
    return false;
}

function applyColorScheme(isDark) {
    const meta = document.querySelector('meta[name="color-scheme"]');
    if (meta) meta.setAttribute('content', isDark ? 'dark' : 'light');
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
}

function ensureLgpdPrefix() {
    if (window.LGPDCOOKIES && !LGPDCOOKIES.lgpdPrefix) {
        const el = document.getElementById("lgpd-key");
        if (el) {
            LGPDCOOKIES.lgpdPrefix = el.getAttribute("prefix") || "";
        }
    }
}

function setThemePreference(isDark) {
    const value = isDark ? "1" : "0";
    setThemeCookie(value);
    if (window.LGPDCOOKIES && typeof LGPDCOOKIES.setCookie === "function") {
        ensureLgpdPrefix();
        LGPDCOOKIES.setCookie("modoEscuro", value);
    }
    if (typeof localStorage !== "undefined") {
        localStorage.setItem("modoEscuro", value);
    }
}

function withThemeTransitionDisabled(callback) {
    const root = document.documentElement;
    const body = document.body;

    root.classList.add("theme-transitioning");
    if (body) {
        body.classList.add("theme-transitioning");
    }

    callback();

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            root.classList.remove("theme-transitioning");
            if (body) {
                body.classList.remove("theme-transitioning");
            }
        });
    });
}

function getThemePreference() {
    const cookieValue = getThemeCookie();
    if (cookieValue !== null) {
        return cookieValue;
    }

    let storedValue = null;
    if (window.LGPDCOOKIES && typeof LGPDCOOKIES.getValueCookie === "function") {
        storedValue = LGPDCOOKIES.getValueCookie("modoEscuro");
    } else if (typeof localStorage !== "undefined") {
        storedValue = localStorage.getItem("modoEscuro");
    }

    if (storedValue != null) {
        setThemeCookie(storedValue);
    }

    return storedValue;
}

function toggleDarkMode() {
    withThemeTransitionDisabled(() => {
        const root = document.documentElement;
        const isDark = !root.classList.contains("dark-mode");
        root.classList.toggle("dark-mode", isDark);
        document.body.classList.toggle("dark-mode", isDark);
        applyColorScheme(isDark);
        document
            .querySelectorAll(".theme-toggle,.theme-toggle-mobile")
            .forEach((btn) => {
                const span = btn.querySelector("span");
                const texto = isDark ? "Modo Claro" : "Modo Escuro";
                if (span) span.innerText = texto;
                btn.title = texto;
            });
        setThemePreference(isDark);
        const logo = document.getElementById("logo");
        if (logo) {
            logo.classList.remove("animate-logo");
            requestAnimationFrame(() => {
                logo.classList.add("animate-logo");
            });
        }
        const modoNovo = isDark ? "escuro" : "claro";
        const modoAnterior = isDark ? "claro" : "escuro";
        const modoEvento = isDark ? "modocor-escuro" : "modocor-claro";
        const payload = {
            event_category: "Personalizacao",
            event_label: `${modoAnterior}_para_${modoNovo}`,
            modo_anterior: modoAnterior,
            modo_novo: modoNovo,
        };
        if (typeof window.sendEvent === "function") {
            window.sendEvent(modoEvento, payload);
        } else if (typeof window.gtag === "function") {
            window.gtag("event", modoEvento, payload);
        }
    });
}

function applyThemePreference() {
    withThemeTransitionDisabled(() => {
        const temaSalvo = getThemePreference();
        const isDark = temaSalvo === "1";
        document.documentElement.classList.toggle("dark-mode", isDark);
        document.body.classList.toggle("dark-mode", isDark);
        applyColorScheme(isDark);
        document
            .querySelectorAll(".theme-toggle,.theme-toggle-mobile")
            .forEach((btn) => {
                const span = btn.querySelector("span");
                const texto = isDark ? "Modo Claro" : "Modo Escuro";
                if (span) span.innerText = texto;
                btn.title = texto;
            });
    });
}

const dropdownControllers = [];
let dropdownDocumentHandlersAttached = false;

function setDropdownState(controller, expanded) {
    if (!controller) {
        return;
    }
    controller.button.setAttribute("aria-expanded", expanded);
    controller.menu.classList.toggle("open", expanded);
    controller.menu.classList.toggle("show", expanded);
}

function closeAllDropdowns(exceptController = null) {
    dropdownControllers.forEach((controller) => {
        if (controller !== exceptController) {
            setDropdownState(controller, false);
        }
    });
}

function handleDropdownDismiss(event) {
    if (
        dropdownControllers.some(({ button, menu }) => {
            return button.contains(event.target) || menu.contains(event.target);
        })
    ) {
        return;
    }
    closeAllDropdowns();
}

function handleDropdownKeydown(event) {
    if (event.key === "Escape") {
        closeAllDropdowns();
    }
}

function attachDropdownDocumentHandlers() {
    if (dropdownDocumentHandlersAttached) {
        return;
    }
    dropdownDocumentHandlersAttached = true;
    document.addEventListener("click", handleDropdownDismiss);
    document.addEventListener("focusin", handleDropdownDismiss);
    document.addEventListener("keydown", handleDropdownKeydown);
}

function focusFirstDropdownItem(menu) {
    if (!menu) {
        return;
    }
    const focusable = menu.querySelector("a, button, [tabindex]");
    if (focusable) {
        requestAnimationFrame(() => {
            focusable.focus();
        });
    }
}

function initMenuDropdowns() {
    const toggles = document.querySelectorAll("[data-submenu-toggle]");
    if (!toggles.length) {
        return;
    }
    attachDropdownDocumentHandlers();
    toggles.forEach((button) => {
        if (button.dataset.submenuInitialized === "1") {
            return;
        }
        const menuId = button.getAttribute("data-submenu-toggle");
        if (!menuId) {
            return;
        }
        const menu = document.getElementById(menuId);
        if (!menu) {
            return;
        }
        button.dataset.submenuInitialized = "1";
        const controller = { button, menu };
        dropdownControllers.push(controller);
        button.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            const expanded = button.getAttribute("aria-expanded") === "true";
            if (expanded) {
                setDropdownState(controller, false);
            } else {
                closeAllDropdowns(controller);
                setDropdownState(controller, true);
                if (event.detail === 0) {
                    focusFirstDropdownItem(menu);
                }
            }
        });
        button.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closeAllDropdowns();
                button.focus();
                return;
            }
            if ((event.key === "Enter" || event.key === " ") && button.getAttribute("aria-expanded") !== "true") {
                event.preventDefault();
                closeAllDropdowns(controller);
                setDropdownState(controller, true);
                focusFirstDropdownItem(menu);
            }
            if (event.key === "ArrowDown" && button.getAttribute("aria-expanded") !== "true") {
                event.preventDefault();
                closeAllDropdowns(controller);
                setDropdownState(controller, true);
                focusFirstDropdownItem(menu);
            }
        });
        menu.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closeAllDropdowns();
                button.focus();
            }
        });
    });
}

document.addEventListener("DOMContentLoaded", initMenuDropdowns);

function toggleMenu() {
    const menu = document.getElementById("mobileMenu");
    const hamburger = document.querySelector(".btn__menu");
    const aberto = menu.classList.toggle("active");
    document.body.classList.toggle("no-scroll", aberto);
    if (hamburger) {
        hamburger.setAttribute("aria-expanded", aberto);
        hamburger.classList.toggle("show", aberto);
    }
    const cookieBtn = document.getElementById("lgpdShieldFooter");
    if (cookieBtn) {
        if (aberto) {
            cookieBtn.dataset.prevDisplay = cookieBtn.style.display;
            cookieBtn.style.display = "none";
        } else {
            cookieBtn.style.display = cookieBtn.dataset.prevDisplay || "";
            delete cookieBtn.dataset.prevDisplay;
            handleScroll();
        }
    }
    if (!aberto) {
        closeAllDropdowns();
    }
}
["click", "touchstart"].forEach((evt) => {
    document.addEventListener(evt, (e) => {
        const menu = document.getElementById("mobileMenu");
        const hamburger = document.querySelector(".btn__menu");
        const content = menu ? menu.querySelector(".menu__block") : null;
        if (
            menu &&
            menu.classList.contains("active") &&
            content &&
            !content.contains(e.target) &&
            !(hamburger && hamburger.contains(e.target))
        ) {
            menu.classList.remove("active");
            document.body.classList.remove("no-scroll");
            if (hamburger) {
                hamburger.setAttribute("aria-expanded", false);
                hamburger.classList.remove("show");
            }
            const cookieBtn = document.getElementById("lgpdShieldFooter");
            if (cookieBtn) {
                cookieBtn.style.display = cookieBtn.dataset.prevDisplay || "";
                delete cookieBtn.dataset.prevDisplay;
                handleScroll();
            }
        }
    }, { passive: true });
});

applyThemePreference();
window.addEventListener("pageshow", applyThemePreference);

let headerEl = null;
let headerMetricsFrameId = null;

function updateHeaderMetrics() {
    const header = getHeaderElement();
    if (!header) {
        return;
    }

    const root = document.documentElement;
    if (!root) {
        return;
    }

    const { height } = header.getBoundingClientRect();
    if (!Number.isFinite(height)) {
        return;
    }

    const heightPx = `${Math.round(height)}px`;
    root.style.setProperty("--header-spacing", heightPx);
    root.style.setProperty("--header-height", heightPx);
}

function scheduleHeaderMetricsUpdate() {
    if (headerMetricsFrameId !== null) {
        if (typeof cancelAnimationFrame === "function") {
            cancelAnimationFrame(headerMetricsFrameId);
        } else {
            clearTimeout(headerMetricsFrameId);
        }
    }

    const run = () => {
        headerMetricsFrameId = null;
        updateHeaderMetrics();
    };

    if (typeof requestAnimationFrame === "function") {
        headerMetricsFrameId = requestAnimationFrame(run);
    } else {
        headerMetricsFrameId = setTimeout(run, 0);
    }
}

function restartAnimation(element, className, onApplied) {
    if (!element) {
        if (typeof onApplied === "function") {
            onApplied();
        }
        return;
    }

    element.classList.remove(className);
    if (typeof element.getAnimations === "function") {
        try {
            element.getAnimations().forEach(function (animation) {
                if (animation && typeof animation.cancel === "function") {
                    animation.cancel();
                }
            });
        } catch (err) {
        }
    }

    const applyClass = () => {
        element.classList.add(className);
        if (typeof onApplied === "function") {
            onApplied();
        }
    };

    if (typeof requestAnimationFrame === "function") {
        requestAnimationFrame(applyClass);
    } else {
        setTimeout(applyClass, 0);
    }
}

function animateHeaderAndLogo() {
    const logo = document.getElementById("logo");
    if (logo) {
        restartAnimation(logo, "animate-logo");
    }

    const header = getHeaderElement();
    if (header) {
        restartAnimation(header, "fade-in");
    }
}

function runAfterInitialLoader(callback) {
    if (typeof callback !== "function") {
        return;
    }

    const root = document.documentElement;
    if (!root || root.getAttribute("data-loading") !== "true") {
        callback();
        return;
    }

    let finished = false;
    const finish = () => {
        if (finished) {
            return;
        }
        finished = true;
        callback();
    };

    if (typeof MutationObserver === "function") {
        const observer = new MutationObserver(() => {
            if (root.getAttribute("data-loading") !== "true") {
                observer.disconnect();
                finish();
            }
        });
        observer.observe(root, { attributes: true, attributeFilter: ["data-loading"] });
        return;
    }

    const intervalId = setInterval(() => {
        if (!root || root.getAttribute("data-loading") !== "true") {
            clearInterval(intervalId);
            finish();
        }
    }, 50);
}

function getHeaderElement() {
    if (!headerEl || !headerEl.isConnected) {
        headerEl = document.querySelector(".header");
    }
    return headerEl;
}

window.addEventListener("load", () => {
    if (typeof window !== "undefined" && typeof window.requestLoaderHide === "function") {
        window.requestLoaderHide();
    }

    runAfterInitialLoader(animateHeaderAndLogo);

    const logo = document.getElementById("logo");
    if (logo) {
        logo.classList.add("animate-logo");
    }
    const header = getHeaderElement();
    if (header) {
        header.classList.add("fade-in");
        scheduleHeaderMetricsUpdate();
    }
    const origem = document.getElementById("titulo-estrutural");
    if (origem) {
        origem.remove();
    }
    const botao = document.getElementById("voltar-topo");
    if (botao) {
        if (IS_CARRINHO_PATH) {
            botao.style.display = "none";
        } else {
            botao.addEventListener("click", () => {
                window.scrollTo({ top: 0, behavior: "smooth" });
            });
        }
    }
});
let cookieHideTimer;
let lgpdVisibilityReady = false;
let lgpdFormFocusActive = false;

function ensureMainContentLayoutReady() {
    const main = document.getElementById("main-content");
    if (!main) {
        return;
    }

    if (!main.dataset.layoutReady) {
        const mainRect = main.getBoundingClientRect();
        if (mainRect.width) {
            main.style.minWidth = `${Math.ceil(mainRect.width)}px`;
        }
        main.style.minHeight = "5vh";
        main.style.visibility = "visible";
        main.dataset.layoutReady = "true";
    }

    const lcpCandidate = main.querySelector(".titulo-pagina") || main.querySelector("h1");
    if (lcpCandidate && !lcpCandidate.dataset.layoutReady) {
        const lcpRect = lcpCandidate.getBoundingClientRect();
        if (lcpRect.width) {
            lcpCandidate.style.minWidth = `${Math.ceil(lcpRect.width)}px`;
        }
        if (lcpRect.height) {
            lcpCandidate.style.minHeight = `${Math.ceil(lcpRect.height)}px`;
        }
        lcpCandidate.dataset.layoutReady = "true";
    }
}

const LGPD_VISIBILITY_EVENT = "main-content-first-paint";

function setLgpdVisibilityReady() {
    if (lgpdVisibilityReady) {
        return;
    }
    lgpdVisibilityReady = true;
    const lgpdContainer = document.getElementById("lgpdDivFooter");
    if (lgpdContainer) {
        lgpdContainer.removeAttribute("hidden");
        lgpdContainer.setAttribute("aria-hidden", "false");
    }
    requestLgpdScrollUpdate();
}

function requestLgpdScrollUpdate() {
    if (typeof requestAnimationFrame === "function") {
        requestAnimationFrame(handleScroll);
    } else {
        setTimeout(handleScroll, 0);
    }
}

function waitForLgpdVisibilityGate() {
    return new Promise((resolve) => {
        let resolved = false;
        let rafId = null;

        const finish = () => {
            if (resolved) {
                return;
            }
            resolved = true;
            if (rafId !== null && typeof cancelAnimationFrame === "function") {
                cancelAnimationFrame(rafId);
            }
            document.removeEventListener(LGPD_VISIBILITY_EVENT, onGateEvent);
            resolve();
        };

        const onGateEvent = () => finish();

        if (typeof document !== "undefined" && document.addEventListener) {
            document.addEventListener(LGPD_VISIBILITY_EVENT, onGateEvent, { once: true });
        }

        if (typeof requestAnimationFrame === "function") {
            rafId = requestAnimationFrame(() => {
                requestAnimationFrame(finish);
            });
        } else {
            setTimeout(finish, 0);
        }
    });
}

function scheduleLgpdVisibility() {
    const schedule = () => {
        waitForLgpdVisibilityGate().then(() => {
            if (typeof requestIdleCallback === "function") {
                requestIdleCallback(() => {
                    setTimeout(setLgpdVisibilityReady, 0);
                }, { timeout: 1500 });
            } else {
                setTimeout(setLgpdVisibilityReady, 0);
            }
        });
    };

    const finalizeAfterLoader = () => {
        ensureMainContentLayoutReady();
        if (typeof requestAnimationFrame === "function") {
            requestAnimationFrame(() => {
                ensureMainContentLayoutReady();
                schedule();
            });
        } else {
            schedule();
        }
    };

    runAfterInitialLoader(() => {
        if (document.readyState === "complete") {
            finalizeAfterLoader();
        } else {
            window.addEventListener("load", finalizeAfterLoader, { once: true });
        }
    });
}

scheduleLgpdVisibility();

const LGPD_FORM_FOCUS_SELECTOR = "input, select, textarea, [contenteditable=\"true\"]";

function isElementVisible(element) {
    if (!element) {
        return false;
    }
    return Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
}

function hasVisibleFormOverlay() {
    const overlays = document.querySelectorAll(".form-desenho-container, [role=\"dialog\"][aria-modal=\"true\"]");
    return Array.from(overlays).some(isElementVisible);
}

function getCookieVisibilityState(currentScroll) {
    const mobileMenu = document.getElementById("mobileMenu");
    const mobileMenuActive = Boolean(mobileMenu && mobileMenu.classList.contains("active"));
    const visibleFormOverlay = hasVisibleFormOverlay();
    return {
        currentScroll,
        mobileMenuActive,
        visibleFormOverlay
    };
}

function updateBackToTopButtonBottomAlignment() {
    const botaoVoltarTopo = document.getElementById("voltar-topo");
    if (!botaoVoltarTopo) {
        return;
    }

    botaoVoltarTopo.style.removeProperty("bottom");
}

function alignChatButtonWithCookieShield() {
    const root = document.documentElement;
    const botaoChatEspecialista = document.getElementById("chat-especialista");
    const cookieButton = document.getElementById("lgpdShieldFooter");
    const cookieShieldAnchor = document.getElementById("openModalLgpdShield");

    if (!root || !botaoChatEspecialista || !cookieButton) {
        return;
    }

    const estiloCookie = window.getComputedStyle(cookieButton);
    const cookieVisivel = isElementVisible(cookieButton) &&
        estiloCookie.display !== "none" &&
        estiloCookie.visibility !== "hidden";

    if (!cookieVisivel) {
        return;
    }

    const referenciaCookie = isElementVisible(cookieShieldAnchor) ? cookieShieldAnchor : cookieButton;
    const cookieRect = referenciaCookie.getBoundingClientRect();
    const chatHeight = botaoChatEspecialista.offsetHeight || botaoChatEspecialista.clientHeight || 0;
    if (!cookieRect.height || !chatHeight) {
        return;
    }

    const centroCookieDistanteDoRodape = window.innerHeight - (cookieRect.top + cookieRect.height / 2);
    const proximoBottomChat = Math.max(0, centroCookieDistanteDoRodape - chatHeight / 2);
    root.style.setProperty("--chat-especialista-bottom", `${Math.round(proximoBottomChat)}px`);
}

function updateChatButtonBottomAlignment() {
    const botaoChatEspecialista = document.getElementById("chat-especialista");
    if (botaoChatEspecialista) {
        botaoChatEspecialista.style.removeProperty("bottom");
    }
    alignChatButtonWithCookieShield();
    updateBackToTopButtonBottomAlignment();
}

function shouldHideCookieButton(visibilityState) {
    const currentScroll = visibilityState && Number.isFinite(visibilityState.currentScroll)
        ? visibilityState.currentScroll
        : window.scrollY;
    if (!lgpdVisibilityReady) {
        return true;
    }
    const hasConsent = window.LGPDCOOKIES && LGPDCOOKIES.cookiesConsent !== "";
    if (!hasConsent || IS_AREA_CLIENTE_PATH || IS_CARRINHO_PATH) {
        return true;
    }
    if (visibilityState && visibilityState.mobileMenuActive) {
        return true;
    }
    if (lgpdFormFocusActive || (visibilityState && visibilityState.visibleFormOverlay)) {
        return true;
    }
    return currentScroll > 0;
}

function updateCookieButtonVisibility(currentScroll = window.scrollY) {
    const cookieButton = document.getElementById("lgpdShieldFooter");
    if (!cookieButton) {
        return;
    }

    const visibilityState = getCookieVisibilityState(currentScroll);
    cookieButton.style.display = shouldHideCookieButton(visibilityState) ? "none" : "flex";
    updateChatButtonBottomAlignment();
}

function applyScrollState(visibilityState) {
    const currentScroll = visibilityState && Number.isFinite(visibilityState.currentScroll)
        ? visibilityState.currentScroll
        : window.scrollY;
    const header = getHeaderElement();
    if (header) {
        const shouldBeScrolled = currentScroll > 100;
        header.classList.toggle("scrolled", shouldBeScrolled);
    }
    const botao = document.getElementById("voltar-topo");
    if (botao) {
        if (IS_CARRINHO_PATH) {
            botao.style.display = "none";
        } else {
            botao.style.display = currentScroll > 100 ? "block" : "none";
        }
    }

    const cookieButton = document.getElementById("lgpdShieldFooter");
    if (cookieButton) {
        cookieButton.style.display = shouldHideCookieButton(visibilityState) ? "none" : "flex";
    }
    updateChatButtonBottomAlignment();
}

let scrollFrameHandle = null;
let lastKnownScrollY = window.scrollY;

function scheduleScrollStateUpdate() {
    if (scrollFrameHandle !== null) {
        return;
    }

    const run = () => {
        scrollFrameHandle = null;
        const visibilityState = getCookieVisibilityState(lastKnownScrollY);
        applyScrollState(visibilityState);
    };

    if (typeof requestAnimationFrame === "function") {
        scrollFrameHandle = requestAnimationFrame(run);
    } else {
        scrollFrameHandle = setTimeout(run, 0);
    }
}

function handleScroll() {
    lastKnownScrollY = window.scrollY;
    scheduleScrollStateUpdate();
}

window.addEventListener("scroll", handleScroll, { passive: true });
window.addEventListener("resize", updateChatButtonBottomAlignment);
updateChatButtonBottomAlignment();
document.addEventListener("DOMContentLoaded", () => {
    const botao = document.getElementById("voltar-topo");
    if (botao) {
        if (IS_CARRINHO_PATH) {
            botao.style.display = "none";
        } else {
            botao.addEventListener("click", () => {
                window.scrollTo({ top: 0, behavior: "smooth" });
            });
        }
    }

    const CHAT_ESPECIALISTA_URL = "https://chatgpt.com/g/g-698e3ae4bd2481919e8673777a2f0edd-especialista-tecnico-ibr";
    const botaoChatEspecialista = document.getElementById("chat-especialista");

    if (botaoChatEspecialista) {
        botaoChatEspecialista.addEventListener("click", (event) => {
            event.preventDefault();
            if (typeof window.sendEvent === "function") {
                window.sendEvent("chat-especialista-clique-icone", {
                    event_category: "ChatEspecialista",
                    event_label: "nova_aba",
                    flow_context: "icone_fixo"
                });
            }
            window.open(CHAT_ESPECIALISTA_URL, "_blank", "noopener,noreferrer");
        });
    }

    lastKnownScrollY = window.scrollY;
    applyScrollState(getCookieVisibilityState(lastKnownScrollY));
    updateChatButtonBottomAlignment();
});

document.addEventListener("focusin", (event) => {
    const target = event.target;
    lgpdFormFocusActive = Boolean(
        target &&
        (target.matches(LGPD_FORM_FOCUS_SELECTOR) ||
            target.closest("form") ||
            target.closest(".form-desenho-container"))
    );
    updateCookieButtonVisibility();
});

document.addEventListener("focusout", () => {
    setTimeout(() => {
        const active = document.activeElement;
        lgpdFormFocusActive = Boolean(
            active &&
            (active.matches(LGPD_FORM_FOCUS_SELECTOR) ||
                active.closest("form") ||
                active.closest(".form-desenho-container"))
        );
        updateCookieButtonVisibility();
    }, 0);
});

document.addEventListener("click", () => {
    updateCookieButtonVisibility();
});

document.addEventListener("DOMContentLoaded", () => {
    const isMobile = window.matchMedia("(max-width:768px)").matches;
    if (isMobile) {
    }
});
function getGrupoUsuario() {
    const token = document.cookie
        .split(";")
        .find((row) => row.startsWith("auth_token="))
        ?.split("=")[1];
    if (!token) return null;
    const [payload] = token.split(".");
    try {
        const dados = JSON.parse(atob(payload));
        if (Date.now() / 1000 > dados.exp) return null;
        return dados.grupo;
    } catch {
        return null;
    }
}
function fecharAlerta() {
    const alerta = document.getElementById("alerta-validacao");
    if (!alerta) return;
    alerta.classList.remove("alerta-visivel");
    setTimeout(() => (alerta.style.display = "none"), 500);
}
function exibirAlerta(msg) {
    const alerta = document.getElementById("alerta-validacao");
    const texto = document.getElementById("alerta-texto");
    if (!alerta || !texto) return;
    texto.innerHTML = msg;
    alerta.style.display = "block";
    setTimeout(() => {
        alerta.classList.add("alerta-visivel");
        try {
            alerta.focus({ preventScroll: true });
        } catch (e) {
            alerta.focus();
        }
    }, 10);
    setTimeout(() => fecharAlerta(), 30000);
}

let autoScrollAtivo = false;

function smoothScrollParaCentro(el, dur = 250) {
    const inicio = window.pageYOffset;
    const rect = el.getBoundingClientRect();
    const alvo = rect.top + inicio - (window.innerHeight / 2 - rect.height / 2);
    const dist = alvo - inicio;
    const comeco = performance.now();
    function passo(agora) {
        const t = Math.min((agora - comeco) / dur, 1);
        const ease = t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
        window.scrollTo(0, inicio + dist * ease);
        if (t < 1) requestAnimationFrame(passo);
    }
    requestAnimationFrame(passo);
}

function scrollParaCentro(el) {
    if (!el) return;
    smoothScrollParaCentro(el, 250);
}

function scrollParaCabecalho(el) {
    if (!el) return;

    const executarScroll = () => {
        const header = getHeaderElement();
        const headerOffset = header ? header.getBoundingClientRect().height : 0;
        const destino = el.getBoundingClientRect().top + window.scrollY - headerOffset;
        const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
        window.scrollTo({ top: Math.min(destino, maxScroll), behavior: 'smooth' });
    };

    if (typeof requestAnimationFrame === "function") {
        requestAnimationFrame(executarScroll);
    } else {
        setTimeout(executarScroll, 0);
    }
}

function scrollParaProximoSelect(atual) {
    const todos = Array.from(document.querySelectorAll("select"));
    const idx = todos.indexOf(atual);
    if (idx === -1) return;
    const scrollAntes = window.scrollY;
    let encontrado = false;
    for (let i = idx + 1; i < todos.length; i++) {
        const proximo = todos[i];
        const estilo = window.getComputedStyle(proximo);
        if (
            estilo.display !== "none" &&
            estilo.visibility !== "hidden" &&
            proximo.offsetParent !== null &&
            (!proximo.value || proximo.value.trim() === "")
        ) {
            encontrado = true;
            smoothScrollParaCentro(proximo, 250);
            setTimeout(() => {
                try {
                    proximo.focus({ preventScroll: true });
                } catch (e) {
                    proximo.focus();
                }
            }, 260);
            break;
        }
    }
    if (!encontrado) {
        autoScrollAtivo = false;
    }
}

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("select").forEach((select) => {
        select.dataset.inicialVazio =
            !select.value || select.value.trim() === "" ? "1" : "0";
        select.addEventListener("change", (e) => {
            if (!e.isTrusted) return;
            const inicialmenteVazio = select.dataset.inicialVazio === "1";
            if (inicialmenteVazio && select.value && !autoScrollAtivo) {
                autoScrollAtivo = true;
            }
            if (autoScrollAtivo && select.value) {
                scrollParaProximoSelect(select);
            }
        });
    });
});

document.addEventListener("DOMContentLoaded", () => {
    document
        .querySelector(".gerar-produto")
        ?.addEventListener("click", function (e) {
            e.preventDefault();
            const todosSelects = Array.from(document.querySelectorAll("select"));
            const selectsVisiveis = todosSelects.filter((select) => {
                const style = window.getComputedStyle(select);
                return (
                    style.display !== "none" &&
                    style.visibility !== "hidden" &&
                    select.offsetParent !== null
                );
            });
            const naoPreenchidos = selectsVisiveis.filter(
                (select) => !select.value || select.value.trim() === "",
            );
            todosSelects.forEach((select) => {
                select.classList.remove("campo-invalido");
            });
            if (naoPreenchidos.length > 0) {
                const campos = naoPreenchidos.map((select) => {
                    const label = document.querySelector(`label[for="${select.id}"]`);
                    return label
                        ? label.innerText.trim().replace(":", "")
                        : select.name || select.id;
                });
                naoPreenchidos.forEach((select, index) => {
                    select.classList.add("campo-invalido");
                    select.addEventListener("change", function handleChange() {
                        if (select.value && select.value.trim() !== "") {
                            select.classList.remove("campo-invalido");
                            select.removeEventListener("change", handleChange);
                        }
                    });
                    if (index === 0) {
                        setTimeout(() => {
                            try {
                                select.focus({ preventScroll: true });
                            } catch (e) {
                                select.focus();
                            }
                        }, 300);
                    }
                });
                const lista = campos.map((c) => `• ${c}`).join("<br>");
                exibirAlerta(
                    "⚠️ Preencha os seguintes campos obrigatórios:<br><br>" + lista,
                );
            } else {
                const evento = new CustomEvent("todosCamposPreenchidos", {
                    detail: { manual: true }
                });
                document.dispatchEvent(evento);
                const botao = document.querySelector('.gerar-produto-wrapper');
                if (botao) scrollParaCentro(botao);
            }
        });
});
function normalizeVersion(value) {
    return (value || "")
        .toString()
        .replace(/^versao\./i, "")
        .trim();
}

if ("serviceWorker" in navigator && (location.protocol === "https:" || location.hostname === "localhost")) {
    const normalizedAppVersion = normalizeVersion(APP_VERSION);
    const versionParam = normalizedAppVersion ? `?versao=${encodeURIComponent(normalizedAppVersion)}` : "";
    const serviceWorkerCandidates = [
        versionParam ? "/Service-Worker.min.js" + versionParam : null,
        "/Service-Worker.min.js",
        versionParam ? "/Service-Worker.js" + versionParam : null,
        "/Service-Worker.js"
    ].filter(Boolean);

    async function registerServiceWorker() {
        const options = { updateViaCache: 'none', scope: '/' };

        function requestSkipWaiting(registration) {
            if (!registration || !registration.waiting) {
                return;
            }
            try {
                registration.waiting.postMessage({ type: 'skipWaiting' });
            } catch (error) {
                console.warn('Service worker skipWaiting request failed:', error);
            }
        }

        async function tryRegister(url) {
            try {
                const response = await fetch(url, { cache: 'no-store' });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
            } catch (error) {
                console.warn(`Service worker script fetch failed for ${url}:`, error);
                return null;
            }

            try {
                return await navigator.serviceWorker.register(url, options);
            } catch (error) {
                console.warn(`Service worker registration failed for ${url}:`, error);
                return null;
            }
        }

        for (const url of serviceWorkerCandidates) {
            const registration = await tryRegister(url);
            if (registration) {
                const attemptUpdate = () => registration.update().catch(error => {
                    console.warn(`Service worker update failed for ${url}:`, error);
                });

                if (navigator.onLine) {
                    attemptUpdate();
                } else {
                    window.addEventListener('online', attemptUpdate, { once: true });
                }
                registration.addEventListener("updatefound", () => {
                    const newWorker = registration.installing;
                    if (newWorker) {
                        newWorker.addEventListener("statechange", () => {
                            if (newWorker.state === "installed") {
                                requestSkipWaiting(registration);
                            }
                            if (newWorker.state === "activated") {
                                let storedVersion = null;
                                try {
                                    storedVersion = localStorage.getItem('app_version');
                                } catch (error) {
                                    storedVersion = null;
                                }

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
                return;
            }
        }
    }

    window.addEventListener("load", () => {
        registerServiceWorker().catch(err => console.warn('Service worker registration sequence failed:', err));
    });
    navigator.serviceWorker.addEventListener('message', e => {
        const t = e.data;
        if (t && (t === 'updated' || t.type === 'updated')) {
            const newVersion = t.version || APP_VERSION;
            const storedVersion = localStorage.getItem('app_version');
            if (newVersion !== storedVersion) {
                localStorage.setItem('app_version', newVersion);
                clearCachesAndReload();
            }
        }
    });

} else {
    console.warn("Service worker requer HTTPS para funcionar corretamente.");
}

(function setupOfflineStatusBanner() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    var banner = document.getElementById('offline-status');
    if (!banner) {
        return;
    }

    var messageEl = banner.querySelector('[data-offline-status-message]');
    var countEl = banner.querySelector('[data-offline-status-count]');
    var previousCount = null;
    var hideTimeout = null;
    var replayCheckTimer = null;
    var replayCheckAttempts = 0;
    var maxReplayChecks = 4;

    function clearHideTimeout() {
        if (hideTimeout) {
            clearTimeout(hideTimeout);
            hideTimeout = null;
        }
    }

    function setBannerVisible(visible) {
        banner.classList.toggle('is-visible', visible);
        banner.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function formatPendingText(count) {
        if (count === 1) {
            return '1 ação pendente';
        }
        return count + ' ações pendentes';
    }

    function updateCountLabel(count) {
        if (!countEl) {
            return;
        }
        if (count && count > 0) {
            countEl.textContent = formatPendingText(count) + '.';
        } else {
            countEl.textContent = '';
        }
    }

    function setBannerMessage(text, count, options) {
        if (messageEl) {
            messageEl.textContent = text;
        } else {
            banner.textContent = text;
        }
        updateCountLabel(count);
        clearHideTimeout();

        var shouldShow = !options || options.show !== false;
        setBannerVisible(shouldShow);

        if (options && options.autoHideMs) {
            hideTimeout = setTimeout(function () {
                setBannerVisible(false);
            }, options.autoHideMs);
        }
    }

    function openOfflineDatabase() {
        return new Promise(function (resolve, reject) {
            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB not supported.'));
                return;
            }

            function openDatabase(attempt) {
                var request = indexedDB.open('pwa-sync', 2);
                request.onupgradeneeded = function () {
                    var db = request.result;
                    if (!db.objectStoreNames.contains('requests')) {
                        db.createObjectStore('requests', { autoIncrement: true });
                    }
                };
                request.onsuccess = function () {
                    var db = request.result;
                    if (!db.objectStoreNames.contains('requests')) {
                        db.close();
                        if (attempt >= 1) {
                            reject(new Error('Offline queue store is missing.'));
                            return;
                        }
                        var deleteRequest = indexedDB.deleteDatabase('pwa-sync');
                        deleteRequest.onsuccess = function () {
                            openDatabase(attempt + 1);
                        };
                        deleteRequest.onerror = function () {
                            reject(deleteRequest.error || new Error('Failed to reset offline sync database.'));
                        };
                        return;
                    }
                    resolve(db);
                };
                request.onerror = function () {
                    reject(request.error || new Error('Failed to open offline sync database.'));
                };
            }

            openDatabase(0);
        });
    }

    function readPendingCount() {
        return openOfflineDatabase().then(function (db) {
            return new Promise(function (resolve, reject) {
                try {
                    var transaction = db.transaction('requests', 'readonly');
                    var store = transaction.objectStore('requests');
                    var countRequest = store.count();
                    countRequest.onsuccess = function () {
                        resolve(countRequest.result || 0);
                    };
                    countRequest.onerror = function () {
                        reject(countRequest.error || new Error('Failed to count queued requests.'));
                    };
                } catch (error) {
                    reject(error);
                }
            });
        });
    }

    function stopReplayChecks() {
        if (replayCheckTimer) {
            clearInterval(replayCheckTimer);
            replayCheckTimer = null;
        }
        replayCheckAttempts = 0;
    }

    function scheduleReplayChecks() {
        if (!navigator.onLine) {
            stopReplayChecks();
            return;
        }
        if (replayCheckTimer) {
            return;
        }
        replayCheckAttempts = 0;
        replayCheckTimer = setInterval(function () {
            replayCheckAttempts += 1;
            updateBannerState('replay');
            if (replayCheckAttempts >= maxReplayChecks) {
                stopReplayChecks();
            }
        }, 5000);
    }

    function updateBannerState(source) {
        readPendingCount()
            .then(function (count) {
                var hasPending = count > 0;
                var hadPending = previousCount !== null && previousCount > 0;
                previousCount = count;

                if (!navigator.onLine) {
                    setBannerMessage(
                        'Você está offline. Suas ações serão enviadas quando a conexão voltar.',
                        count,
                        { show: true }
                    );
                    stopReplayChecks();
                    return;
                }

                if (hasPending) {
                    setBannerMessage(
                        'Conexão restabelecida. Suas ações aguardam envio.',
                        count,
                        { show: true }
                    );
                    if (source === 'online' || source === 'replay') {
                        scheduleReplayChecks();
                    }
                    return;
                }

                stopReplayChecks();

                if (hadPending) {
                    setBannerMessage(
                        'Conexão restabelecida. Ações pendentes reenviadas com sucesso.',
                        0,
                        { show: true, autoHideMs: 6000 }
                    );
                } else {
                    setBannerVisible(false);
                }
            })
            .catch(function (error) {
                console.warn('Falha ao ler a fila offline:', error);
            });
    }

    function handleOnline() {
        updateBannerState('online');
        scheduleReplayChecks();
    }

    function handleOffline() {
        updateBannerState('offline');
    }

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    if (navigator.serviceWorker && navigator.serviceWorker.addEventListener) {
        navigator.serviceWorker.addEventListener('message', function (event) {
            var data = event && event.data;
            if (!data) {
                return;
            }
            if (data.type === 'offline-request') {
                updateBannerState('queued');
            }
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            updateBannerState('visibility');
        }
    });

    updateBannerState('initial');
})();

function toggleGrupoDetalhes(grupo) {
    const detalhes = document.getElementById("detalhes" + grupo);
    const seta = document.getElementById("setaToggle" + grupo);
    if (!detalhes) return;
    detalhes.classList.toggle("expandido");
    detalhes.classList.toggle("recolhido");
    if (seta) seta.classList.toggle("aberta");
    if (detalhes.classList.contains("expandido")) {
        scrollParaCabecalho(detalhes);
    }
}

document.addEventListener("DOMContentLoaded", () => {
    if (!window.bootstrap) {
        console.error("Bootstrap JS not loaded");
        return;
    }
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.forEach(el => {
        if (el.classList.contains('help-icon')) return;
        const dataTitle = el.getAttribute('data-bs-title');
        const attrTitle = el.getAttribute('title');
        const resolvedTitle = dataTitle !== null ? dataTitle : (attrTitle !== null ? attrTitle : '');
        if (dataTitle === null && typeof resolvedTitle === 'string') {
            el.setAttribute('data-bs-title', resolvedTitle);
        }
        new bootstrap.Popover(el, { title: resolvedTitle });
    });
});
document.addEventListener("DOMContentLoaded", () => {
    const updateSelectTitle = (sel) => {
        const opt = sel.selectedOptions && sel.selectedOptions[0];
        sel.title = opt ? opt.textContent.trim() : '';
    };
    document.querySelectorAll('select').forEach(sel => {
        updateSelectTitle(sel);
        sel.addEventListener('change', () => updateSelectTitle(sel));
    });

    const updateButtonTitle = (btn) => {
        btn.title = btn.textContent.trim();
    };
    document.querySelectorAll('.dropdown-button').forEach(btn => {
        updateButtonTitle(btn);
        new MutationObserver(() => updateButtonTitle(btn))
            .observe(btn, { childList: true, characterData: true, subtree: true });
    });
});

function resolveAreaClienteElements() {
    return Array.from(document.querySelectorAll('#areaClienteDesktop, #areaClienteMobile'));
}

function removeAreaClienteLoading(elements) {
    elements.forEach((element) => element.classList.remove('area-cliente-loading'));
}

const AREA_CLIENTE_MODULE_MIN_PATH = '/Layout/AreaClienteLayout.min.js';
const AREA_CLIENTE_MODULE_FALLBACK_PATH = '/Layout/AreaClienteLayout.js';

function setupAreaClienteModule() {
    const areaClienteElements = resolveAreaClienteElements();
    const shouldLoadAreaClienteModule = IS_AREA_CLIENTE_PATH || areaClienteElements.length > 0;

    if (areaClienteElements.length) {
        removeAreaClienteLoading(areaClienteElements);
    }

    if (!shouldLoadAreaClienteModule) {
        return;
    }

    const loadAreaClienteModule = () =>
        import(AREA_CLIENTE_MODULE_MIN_PATH)
            .catch((error) => {
                console.warn('Área do Cliente: falha ao carregar versão minificada, tentando alternativa não minificada.', error);
                return import(AREA_CLIENTE_MODULE_FALLBACK_PATH);
            });

    loadAreaClienteModule()
        .then((module) => {
            if (module && typeof module.initAreaCliente === 'function') {
                try {
                    module.initAreaCliente();
                    return;
                } catch (error) {
                    console.warn('Área do Cliente: falha ao inicializar módulo.', error);
                }
            } else {
                console.warn('Área do Cliente: módulo carregado sem função initAreaCliente.', module);
            }

            removeAreaClienteLoading(resolveAreaClienteElements());
        })
        .catch((error) => {
            console.warn('Área do Cliente: falha ao carregar módulo.', error);
            removeAreaClienteLoading(resolveAreaClienteElements());
        });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupAreaClienteModule);
} else {
    setupAreaClienteModule();
}
