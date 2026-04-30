/**
 * Contrato de tracking via data attributes:
 * - data-gtag-event (obrigatório): nome do evento enviado ao gtag.
 * - data-gtag-category (opcional): mapeado para event_category.
 * - data-gtag-label (opcional): mapeado para event_label.
 * - data-gtag-value (opcional): mapeado para value (número).
 * Elementos com data-gtag-event disparam automaticamente sendEvent(...)
 * em eventos de click (botões/links) ou submit (forms).
 * Observação: trace_id só é gerado/persistido quando há consentimento de analytics.
 * Eventos essenciais (ex.: consentimento) seguem sem trace_id quando não há consentimento.
 */
window.dataLayer = window.dataLayer || [];
window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
if (typeof window.trackPageView !== 'function') {
    window.trackPageView = function () { };
}

gtag('consent', 'default', {
    'ad_storage': 'denied',
    'ad_user_data': 'denied',
    'ad_personalization': 'denied',
    'analytics_storage': 'denied',
    'wait_for_update': 500
});

function consentGrantedAdStorage() {
    gtag('consent', 'update', {
        'ad_storage': 'granted'
    });
}

function atualizarConsentimento(consent) {
    var performanceGranted = false;
    var advertisingGranted = false;
    if (typeof consent === 'boolean') {
        performanceGranted = consent;
        advertisingGranted = consent;
    } else if (consent && typeof consent === 'object') {
        performanceGranted = !!consent.performance;
        advertisingGranted = !!consent.advertising;
    }
    gtag('consent', 'update', {
        ad_storage: advertisingGranted ? 'granted' : 'denied',
        ad_user_data: advertisingGranted ? 'granted' : 'denied',
        ad_personalization: advertisingGranted ? 'granted' : 'denied',
        analytics_storage: performanceGranted ? 'granted' : 'denied'
    });
}

window.consentGrantedAdStorage = consentGrantedAdStorage;
window.atualizarConsentimento = atualizarConsentimento;

var gtagLoaded = false;
var gtmLoaded = false;
var gtmIframeInserted = false;
var gtmBootstrapInitialized = false;
var initialPageViewTracked = false;
var webVitalsInitialized = false;
var pendingWebVitals = [];
var pendingEvents = [];
var webVitalsUnsubscribes = [];
var webVitalsDispatchReady = false;
var errorTrackingBound = false;
var traceIdStorageKey = 'trace_id';
var traceIdCookieName = 'trace_id';
var traceIdCache = '';
var webVitalsScriptId = 'web-vitals-script';
var webVitalsEndpoint = '/LogsErros/RegistrarWebVitals.php';
var tagErrorEndpoint = '/LogsErros/RegistrarErroTag.php';
var tagErrorCooldownMs = 6000;
var tagErrorLastSent = {};
var gtagScriptLoaded = false;
var gtmScriptLoaded = false;
var gtagConfigured = false;
var gtmLoadScheduled = false;
var gtmLoadTimerId = null;
function readMetaContent(name) {
    if (!name || !document || !document.querySelector) return '';
    var meta = document.querySelector('meta[name="' + name + '"]');
    if (!meta) return '';
    return String(meta.getAttribute('content') || '').trim();
}

function readGlobalValue(key) {
    if (!key || typeof window === 'undefined') return '';
    var value = window[key];
    if (typeof value !== 'string') return '';
    return value.trim();
}

function resolveTrackingId(metaName, globalKey, fallbackId) {
    var value = readGlobalValue(globalKey);
    if (!value) {
        value = readMetaContent(metaName);
    }
    if (!value && typeof fallbackId === 'string' && /^(GTM-|G-)/.test(fallbackId)) {
        value = fallbackId;
    }
    return value;
}

var gtmId = resolveTrackingId('gtm-id', 'GTM_ID', 'GTM-P7QH65GR');
var gaMeasurementId = resolveTrackingId('ga-measurement-id', 'GA_MEASUREMENT_ID', 'G-46RENX5RDK');
var gtagScriptId = 'gtag-script';
var analyticsPreconnectMap = [
    { id: 'gtm-preconnect', href: 'https://www.googletagmanager.com' }
];
var locationOrigin = window.location.origin || (window.location.protocol + '//' + window.location.host);
var pageStartTime = Date.now();
var scrollDepthTracked = false;
var rebindScheduled = false;
var trackingCoverageScheduled = false;
var bodyClickBound = false;
var favoritoClickTrackingBound = false;
var favoritoClickHandler = null;
var compartilharEmailTrackingBound = false;
var compartilharEmailHandler = null;
var genericInteractionTrackingBound = false;
var fieldInteractionTrackingBound = false;
var formInteractionState = new Map();
var lastKnownHash = '';
var tagHealthCheckScheduled = false;

function hasScriptWithSource(fragment) {
    if (!fragment || !document || !document.querySelector) return false;
    return Boolean(document.querySelector('script[src*="' + fragment + '"]'));
}

function syncLoadedFlags() {
    gtagLoaded = hasScriptWithSource('googletagmanager.com/gtag/js') || gtagLoaded;
    gtmLoaded = hasScriptWithSource('googletagmanager.com/gtm.js') || gtmLoaded;
    if (!gtmIframeInserted && document && document.querySelector) {
        gtmIframeInserted = Boolean(document.querySelector('iframe[src*="googletagmanager.com/ns.html"]'));
    }
}

syncLoadedFlags();

if (gaMeasurementId) {
    window['ga-disable-' + gaMeasurementId] = true;
}

function appendHtmlFragment(target, html) {
    if (!html || !target) return;
    var container = document.createElement('div');
    container.innerHTML = html;
    while (container.firstChild) {
        var node = container.firstChild;
        container.removeChild(node);
        if (node.nodeName === 'SCRIPT') {
            var script = document.createElement('script');
            var attrs = node.attributes || [];
            for (var i = 0; i < attrs.length; i++) {
                var attr = attrs[i];
                script.setAttribute(attr.name, attr.value);
            }
            var code = node.text || node.textContent || node.innerHTML || '';
            if (code) {
                script.text = code;
            }
            target.appendChild(script);
        } else {
            target.appendChild(node);
        }
    }
}

var documentWriteRedirectInstalled = false;
var documentWriteRedirectRestore = null;
function installDocumentWriteRedirect() {
    if (documentWriteRedirectInstalled) return documentWriteRedirectRestore;
    var originalWrite = document.write;
    var originalWriteln = document.writeln;
    var hasDocumentProto = typeof Document !== 'undefined' && Document.prototype;
    var originalProtoWrite = hasDocumentProto ? Document.prototype.write : null;
    var originalProtoWriteln = hasDocumentProto ? Document.prototype.writeln : null;
    function writeToTarget(value) {
        var content = String(value || '');
        if (!content) return;
        var target = document.body || document.documentElement;
        appendHtmlFragment(target, content);
    }
    document.write = writeToTarget;
    document.writeln = function (value) {
        writeToTarget(value + '\n');
    };
    if (hasDocumentProto) {
        Document.prototype.write = writeToTarget;
        Document.prototype.writeln = document.writeln;
    }
    documentWriteRedirectInstalled = true;
    documentWriteRedirectRestore = function restoreDocumentWrite() {
        if (!documentWriteRedirectInstalled) return;
        document.write = originalWrite;
        document.writeln = originalWriteln;
        if (hasDocumentProto) {
            Document.prototype.write = originalProtoWrite;
            Document.prototype.writeln = originalProtoWriteln;
        }
        documentWriteRedirectInstalled = false;
    };
    return documentWriteRedirectRestore;
}

function getCookieValue(name) {
    if (!name) return '';
    var cookies = document.cookie ? document.cookie.split('; ') : [];
    for (var i = 0; i < cookies.length; i++) {
        var entry = cookies[i];
        if (entry.indexOf(name + '=') === 0) {
            return entry.slice(name.length + 1);
        }
    }
    return '';
}

function generateTraceId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    return 'trace-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}

function readStorageValue(storage, key) {
    if (!storage) return '';
    try {
        return storage.getItem(key) || '';
    } catch (e) {
        return '';
    }
}

function writeStorageValue(storage, key, value) {
    if (!storage) return false;
    try {
        storage.setItem(key, value);
        return true;
    } catch (e) {
        return false;
    }
}

function setTraceCookie(traceId) {
    if (!traceId || !document || !document.cookie) return;
    var cookie = traceIdCookieName + '=' + encodeURIComponent(traceId) + '; path=/; SameSite=Lax';
    if (location.protocol === 'https:') {
        cookie += '; Secure';
    }
    document.cookie = cookie;
}

function getTraceId() {
    if (traceIdCache) return traceIdCache;
    var traceId = '';
    if (typeof localStorage !== 'undefined') {
        traceId = readStorageValue(localStorage, traceIdStorageKey);
    }
    if (!traceId && typeof sessionStorage !== 'undefined') {
        traceId = readStorageValue(sessionStorage, traceIdStorageKey);
    }
    if (!traceId) {
        traceId = getCookieValue(traceIdCookieName);
    }
    if (!traceId) {
        traceId = generateTraceId();
    }
    if (traceId) {
        if (!writeStorageValue(localStorage, traceIdStorageKey, traceId)) {
            writeStorageValue(sessionStorage, traceIdStorageKey, traceId);
        } else {
            writeStorageValue(sessionStorage, traceIdStorageKey, traceId);
        }
        setTraceCookie(traceId);
    }
    traceIdCache = traceId || '';
    return traceIdCache;
}

function getCsrfToken() {
    return getCookieValue('csrf_token');
}

function buildTagErrorSignature(tag, problem) {
    return String(tag || '') + '|' + String(problem || '');
}

function canSendTagError(tag, problem) {
    var signature = buildTagErrorSignature(tag, problem);
    var now = Date.now();
    var last = tagErrorLastSent[signature] || 0;
    if (now - last < tagErrorCooldownMs) {
        return false;
    }
    tagErrorLastSent[signature] = now;
    return true;
}

function sendTagErrorLog(tag, problem, details) {
    if (!tag || !problem) return;
    if (!tagErrorEndpoint || typeof window.fetch !== 'function') return;
    if (!canSendTagError(tag, problem)) return;
    try {
        var detailText = '';
        if (details !== undefined && details !== null) {
            if (typeof details === 'string') {
                detailText = details;
            } else {
                detailText = JSON.stringify(details);
            }
        }
        var payload = {
            tag: String(tag),
            problema: String(problem),
            detalhe: detailText || '',
            url: window.location.href,
            userAgent: navigator.userAgent,
            timestamp: new Date().toISOString(),
            versao: resolveTelemetryVersion(),
            ambiente: resolveTelemetryEnvironment(),
            contexto: {
                page_type: getPageContextAttributes().page_type || '',
                content_group: getPageContextAttributes().content_group || '',
                user_state: getPageContextAttributes().user_state || ''
            }
        };
        var headers = { 'Content-Type': 'application/json' };
        var csrfToken = getCsrfToken();
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }
        window.fetch(tagErrorEndpoint, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(payload),
            keepalive: true,
            credentials: 'same-origin'
        }).catch(function () { });
    } catch (e) { }
}

function getLgpdConsentCookieValue() {
    if (window.LGPDCOOKIES && typeof LGPDCOOKIES.getValueCookie === 'function') {
        return LGPDCOOKIES.getValueCookie('lgpd-cookies-consent');
    }
    var keyEl = document.getElementById('lgpd-key');
    var prefix = keyEl ? (keyEl.getAttribute('prefix') || '') : '';
    var names = [];
    if (prefix) {
        names.push(prefix + '-lgpd-cookies-consent');
    }
    names.push('lgpd-cookies-consent');
    for (var i = 0; i < names.length; i++) {
        var value = getCookieValue(names[i]);
        if (value) return value;
    }
    return '';
}

function isTagAssistantPreview() {
    try {
        var search = window.location && window.location.search ? window.location.search : '';
        if (!search) return false;
        var params = new URLSearchParams(search);
        return params.has('gtm_debug') || params.has('gtm_preview') || params.has('gtm_auth');
    } catch (e) {
        return false;
    }
}

function getLgpdConsentState() {
    var state = { performance: false, advertising: false };
    try {
        if (isTagAssistantPreview()) {
            state.performance = true;
            state.advertising = true;
            return state;
        }
        var c = getLgpdConsentCookieValue();
        if (c) {
            var consent = JSON.parse(decodeURIComponent(c));
            state.performance = !!consent.Performance;
            state.advertising = !!consent.Advertising;
        }
    } catch (e) { }
    return state;
}

function hasAnalyticsConsent() {
    return getLgpdConsentState().performance;
}

function getPageContextAttributes() {
    var root = document.documentElement;
    var body = document.body;
    var pageType = (root && root.getAttribute('data-page-type')) || (body && body.getAttribute('data-page-type')) || '';
    var contentGroup = (root && root.getAttribute('data-content-group')) || (body && body.getAttribute('data-content-group')) || '';
    var userState = (root && root.getAttribute('data-user-state')) || (body && body.getAttribute('data-user-state')) || '';
    var context = {};
    if (pageType) context.page_type = pageType;
    if (contentGroup) context.content_group = contentGroup;
    if (userState) context.user_state = userState;
    return context;
}

function toAnalyticsToken(value) {
    return String(value || '')
        .replace(/[\s_]+/g, '-')
        .replace(/[^a-zA-Z0-9\-]/g, '')
        .replace(/\-+/g, '-')
        .replace(/^\-+|\-+$/g, '')
        .toLowerCase();
}

function getAnalyticsViewIdentity(targetUrl, pageContext) {
    var context = pageContext && typeof pageContext === 'object' ? pageContext : getPageContextAttributes();
    var root = document.documentElement;
    var body = document.body;
    var explicitTitle = (root && root.getAttribute('data-analytics-title'))
        || (body && body.getAttribute('data-analytics-title'))
        || '';
    var explicitScreenClass = (root && root.getAttribute('data-analytics-screen-class'))
        || (body && body.getAttribute('data-analytics-screen-class'))
        || '';
    if (explicitTitle) {
        return {
            pageTitle: explicitTitle,
            screenClass: explicitScreenClass || toAnalyticsToken(context.page_type || context.content_group || explicitTitle || 'web') || 'web'
        };
    }

    var pathname = (targetUrl && targetUrl.pathname) || window.location.pathname || '/';
    var search = (targetUrl && targetUrl.search) || window.location.search || '';
    var linhaAtual = '';
    if (typeof getCurrentLinha === 'function') {
        linhaAtual = String(getCurrentLinha() || '').trim();
    }
    var queryView = '';
    if (!linhaAtual) {
        try {
            var params = new URLSearchParams(search);
            queryView = params.get('item') || params.get('linha') || params.get('modelo') || params.get('familia') || '';
        } catch (e) {
            queryView = '';
        }
    }
    var viewCode = linhaAtual || queryView;
    var pathLabel = pathname
        .split('/')
        .filter(Boolean)
        .pop() || 'home';
    var normalizedPathLabel = toAnalyticsToken(pathLabel) || 'home';
    var normalizedViewCode = toAnalyticsToken(viewCode);
    var normalizedPageType = toAnalyticsToken(context.page_type || context.content_group || 'site');
    var titleSuffix = normalizedViewCode || normalizedPathLabel;

    return {
        pageTitle: 'Configurador de Produtos IBR | ' + titleSuffix,
        screenClass: explicitScreenClass || normalizedPageType || 'site'
    };
}

function enrichPayloadWithPageContext(payload) {
    var base = payload && typeof payload === 'object' ? Object.assign({}, payload) : {};
    var context = getPageContextAttributes();
    Object.keys(context).forEach(function (key) {
        if (base[key] === undefined) {
            base[key] = context[key];
        }
    });
    if (hasAnalyticsConsent()) {
        var traceId = getTraceId();
        if (traceId && base.trace_id === undefined) {
            base.trace_id = traceId;
        }
    }
    return base;
}

function canDispatchAnalytics() {
    return hasAnalyticsConsent() && gtagLoaded;
}

function canQueueAnalyticsEvents() {
    return hasAnalyticsConsent() && !gtagLoaded;
}

function canQueuePageView() {
    return hasAnalyticsConsent() && (!gtagLoaded || !getTrackPageViewFn());
}

function queuePendingEvent(entry) {
    if (!entry) return;
    if (entry.consentGrantedAtQueue === undefined) {
        entry.consentGrantedAtQueue = hasAnalyticsConsent();
    }
    pendingEvents.push(entry);
}

function queueEvent(name, payload) {
    if (!name) return;
    if (!canQueueAnalyticsEvents()) return;
    queuePendingEvent({ type: 'event', name: name, payload: payload, consentGrantedAtQueue: true });
}

function queuePageView(path) {
    if (!canQueuePageView()) return;
    queuePendingEvent({ type: 'page_view', path: path, consentGrantedAtQueue: true });
}

function attemptGtagLoadForConsent() {
    if (!hasAnalyticsConsent()) return false;
    if (gtagLoaded) return true;
    var loaded = loadGtag();
    if (loaded) {
        if (typeof scheduleTagHealthCheck === 'function') {
            scheduleTagHealthCheck();
        }
    }
    return loaded;
}

function getTrackPageViewFn() {
    return typeof window.trackPageView === 'function' ? window.trackPageView : null;
}

function flushPendingEvents() {
    if (!pendingEvents.length) return;
    var items = pendingEvents.slice();
    pendingEvents = [];
    items.forEach(function (item) {
        if (!item) return;
        if (!item.consentGrantedAtQueue || !hasAnalyticsConsent()) {
            return;
        }
        if (item.type === 'page_view') {
            var trackFn = getTrackPageViewFn();
            if (trackFn) {
                trackFn(item.path);
            } else {
                queuePageView(item.path);
            }
            return;
        }
        if (item.type === 'event') {
            sendEvent(item.name, item.payload);
        }
    });
}

function clearPendingEvents() {
    pendingEvents = [];
}

function dispatchOrQueueEvent(name, payload) {
    if (!name) return;
    if (!hasAnalyticsConsent()) {
        return;
    }
    if (!gtagLoaded) {
        attemptGtagLoadForConsent();
        queueEvent(name, payload);
        return;
    }
    sendEvent(name, payload);
}

function dispatchOrQueuePageView(path) {
    if (!hasAnalyticsConsent()) {
        return;
    }
    if (!gtagLoaded) {
        attemptGtagLoadForConsent();
        queuePageView(path);
        return;
    }
    var trackFn = getTrackPageViewFn();
    if (trackFn) {
        trackFn(path);
    } else {
        queuePageView(path);
    }
}

function sendEvent(name, payload) {
    if (!name) return;
    if (!hasAnalyticsConsent()) {
        return;
    }
    if (!gtagLoaded) {
        attemptGtagLoadForConsent();
        queueEvent(name, payload);
        return;
    }
    if (typeof window.gtag !== 'function') {
        sendTagErrorLog('gtag', 'nao_comunicando', { evento: name, motivo: 'gtag_indisponivel' });
        return;
    }
    try {
        window.gtag('event', name, enrichPayloadWithPageContext(payload));
    } catch (error) {
        sendTagErrorLog('gtag', 'erro_envio_evento', { evento: name, erro: (error && error.message) ? error.message : String(error) });
    }
}
window.sendEvent = sendEvent;

var flowCategoryMap = {
    configurador: 'Configurador',
    carrinho: 'Carrinho',
    cotacao: 'Cotacao'
};

var legacyToCanonicalEventMap = {
    'pesquisar-produto-cabecalho-manual': 'search',
    'pesquisar-produto-cabecalho-recomendado': 'search',
    'home-filtro-busca': 'search',
    'configurador-abrir': 'view_item',
    'configurador_abrir': 'view_item',
    'carrinho_abrir': 'begin_checkout',
    'carrinho_avancar': 'begin_checkout',
    'configurador_confirmar': 'generate_lead',
    'carrinho_confirmar': 'generate_lead',
    'cotacao_confirmar': 'generate_lead',
    'envio_formulario': 'generate_lead',
    'cadastro': 'sign_up',
    'login': 'login'
};

function getBusinessContractPayload(element, payload) {
    var basePayload = payload && typeof payload === 'object' ? Object.assign({}, payload) : {};
    if (!element || !element.getAttribute) {
        return basePayload;
    }
    var itemId = element.getAttribute('data-gtag-item-id') || '';
    var itemName = element.getAttribute('data-gtag-item-name') || '';
    var itemCategory = element.getAttribute('data-gtag-item-category') || '';
    var lineCode = element.getAttribute('data-gtag-line-code') || getCurrentLinha() || '';
    var quoteId = element.getAttribute('data-gtag-quote-id') || '';
    var currency = element.getAttribute('data-gtag-currency') || basePayload.currency || 'BRL';
    var rawValue = element.getAttribute('data-gtag-value');

    if (itemId && basePayload.item_id === undefined) basePayload.item_id = itemId;
    if (itemName && basePayload.item_name === undefined) basePayload.item_name = itemName;
    if (itemCategory && basePayload.item_category === undefined) basePayload.item_category = itemCategory;
    if (lineCode && basePayload.line_code === undefined) basePayload.line_code = lineCode;
    if (quoteId && basePayload.quote_id === undefined) basePayload.quote_id = quoteId;
    if (currency && basePayload.currency === undefined) basePayload.currency = currency;
    if (rawValue !== null && rawValue !== '' && basePayload.value === undefined && !isNaN(Number(rawValue))) {
        basePayload.value = Number(rawValue);
    }
    return basePayload;
}

function resolveCanonicalEvent(eventName, payload, element) {
    if (!eventName) return null;
    var normalizedName = String(eventName).trim().toLowerCase();
    var canonicalName = legacyToCanonicalEventMap[normalizedName] || '';

    if (!canonicalName && normalizedName.indexOf('acesso-configurador-') === 0) {
        canonicalName = 'view_item';
    }

    if (!canonicalName && normalizedName.indexOf('carrinho') !== -1 && normalizedName.indexOf('confirmar') === -1) {
        canonicalName = 'add_to_cart';
    }

    if (!canonicalName) {
        return null;
    }

    var canonicalPayload = getBusinessContractPayload(element, payload);
    canonicalPayload.legacy_event_name = normalizedName;
    canonicalPayload.business_event = canonicalName;

    if (canonicalName === 'search' && canonicalPayload.search_term === undefined) {
        canonicalPayload.search_term = canonicalPayload.event_label || '';
    }

    return {
        name: canonicalName,
        payload: canonicalPayload
    };
}

function getFlowEventConfigByName(eventName) {
    if (!eventName || typeof eventName !== 'string') return null;
    var match = eventName.match(/^([a-z0-9]+)_(abrir|avancar|confirmar|erro|cancelar)$/i);
    if (!match) return null;
    var flow = match[1].toLowerCase();
    var step = match[2].toLowerCase();
    var category = flowCategoryMap[flow] || (flow.charAt(0).toUpperCase() + flow.slice(1));
    return {
        flow: flow,
        step: step,
        category: category
    };
}

function trackFlowEvent(flow, step, payload) {
    if (!flow || !step) return;
    var eventName = String(flow).toLowerCase() + '_' + String(step).toLowerCase();
    var flowConfig = getFlowEventConfigByName(eventName);
    var mergedPayload = payload && typeof payload === 'object' ? Object.assign({}, payload) : {};
    if (flowConfig) {
        if (mergedPayload.event_category === undefined) {
            mergedPayload.event_category = flowConfig.category;
        }
        if (mergedPayload.event_label === undefined) {
            mergedPayload.event_label = flowConfig.step;
        }
        if (mergedPayload.flow === undefined) {
            mergedPayload.flow = flowConfig.flow;
        }
        if (mergedPayload.flow_step === undefined) {
            mergedPayload.flow_step = flowConfig.step;
        }
    }
    dispatchOrQueueEvent(eventName, mergedPayload);
}

window.trackFlowEvent = trackFlowEvent;

function normalizeTelemetryValue(value) {
    return typeof value === 'string' ? value.trim() : '';
}

function resolveTelemetryVersion() {
    var fromWindow = normalizeTelemetryValue(window.APP_VERSION);
    if (fromWindow) return fromWindow;
    if (typeof window.getAppVersion === 'function') {
        return normalizeTelemetryValue(window.getAppVersion());
    }
    return '';
}

function resolveTelemetryEnvironment() {
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
        var attrValue = normalizeTelemetryValue(
            root.getAttribute('data-env') || root.getAttribute('data-environment')
        );
        if (attrValue) {
            return attrValue;
        }
    }
    return '';
}

function canSendWebVitalsToBackend() {
    if (window.LOGS_WEB_VITALS_ENABLED === false) {
        return false;
    }
    if (!webVitalsEndpoint) {
        return false;
    }
    return typeof window.fetch === 'function';
}

function buildWebVitalBackendPayload(metric) {
    var navigationType = metric.navigationType || '';
    return enrichPayloadWithPageContext({
        metric_name: metric.name || '',
        metric_value: metric.value || 0,
        metric_delta: metric.delta || 0,
        metric_rating: metric.rating || '',
        metric_id: metric.id || '',
        navigation_type: navigationType,
        url: window.location.href,
        userAgent: navigator.userAgent,
        timestamp: new Date().toISOString(),
        versao: resolveTelemetryVersion(),
        ambiente: resolveTelemetryEnvironment()
    });
}

function sendWebVitalToBackend(metric) {
    if (!metric || !metric.name) return;
    if (!canSendWebVitalsToBackend()) return;
    try {
        var payload = buildWebVitalBackendPayload(metric);
        window.fetch(webVitalsEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            keepalive: true,
            credentials: 'same-origin'
        }).catch(function () { });
    } catch (e) { }
}

function queueWebVital(metric) {
    if (!metric || !metric.name) return;
    var value = metric.value || 0;
    if (metric.name === 'CLS') {
        value = metric.value * 1000;
    }
    var eventValue = Math.round(value);
    var payload = {
        value: eventValue,
        non_interaction: true,
        event_category: 'Core Web Vitals',
        event_label: metric.id,
        metric_rating: metric.rating || ''
    };
    if (webVitalsDispatchReady) {
        sendEvent(metric.name, payload);
    } else {
        pendingWebVitals.push({ name: metric.name, payload: payload });
    }
    sendWebVitalToBackend(metric);
}

function flushPendingWebVitals() {
    if (!pendingWebVitals.length) {
        webVitalsDispatchReady = true;
        return;
    }
    webVitalsDispatchReady = true;
    while (pendingWebVitals.length) {
        var item = pendingWebVitals.shift();
        sendEvent(item.name, item.payload);
    }
}

function registerWebVitalsListeners() {
    if (!window.webVitals || !hasAnalyticsConsent()) return;
    var api = window.webVitals;
    var listeners = [];
    if (typeof api.onCLS === 'function') listeners.push(api.onCLS(queueWebVital));
    if (typeof api.onFID === 'function') listeners.push(api.onFID(queueWebVital));
    if (typeof api.onLCP === 'function') listeners.push(api.onLCP(queueWebVital));
    if (typeof api.onINP === 'function') listeners.push(api.onINP(queueWebVital));
    if (typeof api.onTTFB === 'function') listeners.push(api.onTTFB(queueWebVital));
    webVitalsUnsubscribes = listeners.filter(function (unsubscribe) { return typeof unsubscribe === 'function'; });
    webVitalsInitialized = true;
}

function initCoreWebVitals() {
    if (webVitalsInitialized) return;
    if (window.webVitals) {
        registerWebVitalsListeners();
        return;
    }
    var existing = document.getElementById(webVitalsScriptId);
    if (existing) {
        existing.addEventListener('load', function () {
            if (!webVitalsInitialized) {
                registerWebVitalsListeners();
            }
        }, { once: true });
        return;
    }
    var script = document.createElement('script');
    script.id = webVitalsScriptId;
    script.src = '/SEO/WebVitals.iife.js';
    script.async = true;
    script.onload = function () {
        registerWebVitalsListeners();
    };
    (document.head || document.documentElement).appendChild(script);
}

function teardownWebVitals() {
    pendingWebVitals = [];
    webVitalsDispatchReady = false;
    webVitalsUnsubscribes.forEach(function (unsubscribe) {
        try {
            unsubscribe();
        } catch (e) { }
    });
    webVitalsUnsubscribes = [];
    webVitalsInitialized = false;
}

function loadGtag() {
    if (gtagLoaded || !gaMeasurementId) return false;
    gtagLoaded = true;
    gtagScriptLoaded = false;
    webVitalsDispatchReady = false;
    installDocumentWriteRedirect();
    // gtag.js/gtm.js remain hosted by Google to comply with their delivery requirements.
    // CSP is locked to googletagmanager.com and google-analytics.com for these endpoints.
    var script = document.createElement('script');
    script.id = gtagScriptId;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + gaMeasurementId;
    script.async = true;
    script.onload = function () {
        gtagScriptLoaded = true;
        configureGtagAfterConsent();
    };
    script.onerror = function () {
        gtagLoaded = false;
        gtagScriptLoaded = false;
        sendTagErrorLog('gtag', 'falha_carregar_script', { id: gaMeasurementId, src: script.src });
    };
    (document.head || document.documentElement).appendChild(script);
    return true;
}

function configureGtagAfterConsent() {
    if (gtagConfigured || !gtagScriptLoaded || !gaMeasurementId) return;
    if (!hasAnalyticsConsent()) return;
    gtagConfigured = true;
    var viewIdentity = getAnalyticsViewIdentity(window.location, getPageContextAttributes());
    gtag('js', new Date());
    gtag('config', gaMeasurementId, {
        anonymize_ip: true,
        transport_type: 'beacon',
        send_page_view: false,
        page_path: location.pathname,
        page_title: viewIdentity.pageTitle,
        screen_name: viewIdentity.pageTitle,
        screen_class: viewIdentity.screenClass,
        page_location: location.href
    });
    initialPageViewTracked = true;
    sendEvent('page_loaded', { event_category: 'general', event_label: 'layout' });
    flushPendingWebVitals();
}

function loadGtm() {
    if (gtmLoaded || !gtmId) return false;
    gtmLoaded = true;
    gtmScriptLoaded = false;
    gtmLoadScheduled = false;
    gtmLoadTimerId = null;
    window.dataLayer.push(enrichPayloadWithPageContext({ 'gtm.start': Date.now(), event: 'gtm.js' }));
    installDocumentWriteRedirect();
    var script = document.createElement('script');
    script.id = 'gtm-script';
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtm.js?id=' + gtmId;
    script.onload = function () {
        gtmScriptLoaded = true;
    };
    script.onerror = function () {
        gtmLoaded = false;
        gtmScriptLoaded = false;
        sendTagErrorLog('gtm', 'falha_carregar_script', { id: gtmId, src: script.src });
    };
    (document.head || document.documentElement).appendChild(script);
    return true;
}

function scheduleGtmLoad() {
    if (gtmLoaded || gtmLoadScheduled || !gtmId) return false;
    gtmLoadScheduled = true;
    var load = function () {
        loadGtm();
    };
    if (typeof window.requestIdleCallback === 'function') {
        gtmLoadTimerId = window.requestIdleCallback(load, { timeout: 2000 });
    } else {
        gtmLoadTimerId = setTimeout(load, 0);
    }
    return true;
}

function cancelScheduledGtmLoad() {
    if (!gtmLoadScheduled || gtmLoadTimerId === null) return;
    if (typeof window.cancelIdleCallback === 'function') {
        window.cancelIdleCallback(gtmLoadTimerId);
    } else {
        clearTimeout(gtmLoadTimerId);
    }
    gtmLoadScheduled = false;
    gtmLoadTimerId = null;
}

function ensureGtmIframe() {
    if (gtmBootstrapInitialized) return;
    gtmBootstrapInitialized = true;
    if (!document.body) {
        document.addEventListener('DOMContentLoaded', ensureGtmIframe, { once: true });
    }
    var pageTrackingInitialized = false;
    var historyTrackingInitialized = false;
    var historyNavigationGuard = false;
    var lastTrackedPageLocation = '';
    function unloadGtag() {
        var existing = document.getElementById(gtagScriptId);
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }
        var fallbackScript = document.querySelector('script[src*="googletagmanager.com/gtag/js"]');
        if (fallbackScript && fallbackScript.parentNode) {
            fallbackScript.parentNode.removeChild(fallbackScript);
        }
        gtagLoaded = false;
    }

    function unloadGtm() {
        var gtmScript = document.getElementById('gtm-script');
        if (gtmScript && gtmScript.parentNode) {
            gtmScript.parentNode.removeChild(gtmScript);
        }
        var fallbackScript = document.querySelector('script[src*="googletagmanager.com/gtm.js"]');
        if (fallbackScript && fallbackScript.parentNode) {
            fallbackScript.parentNode.removeChild(fallbackScript);
        }
        var gtmIframe = document.getElementById('gtm-consent-iframe');
        if (gtmIframe && gtmIframe.parentNode) {
            if (gtmIframe.parentNode.nodeName === 'NOSCRIPT') {
                gtmIframe.parentNode.parentNode.removeChild(gtmIframe.parentNode);
            } else {
                gtmIframe.parentNode.removeChild(gtmIframe);
            }
        }
        var fallbackIframe = document.querySelector('iframe[src*="googletagmanager.com/ns.html"]');
        if (fallbackIframe && fallbackIframe.parentNode) {
            if (fallbackIframe.parentNode.nodeName === 'NOSCRIPT') {
                fallbackIframe.parentNode.parentNode.removeChild(fallbackIframe.parentNode);
            } else {
                fallbackIframe.parentNode.removeChild(fallbackIframe);
            }
        }
        gtmLoaded = false;
        gtmIframeInserted = false;
    }

    function insertGtmIframeIfNeeded() {
        if (!hasAnalyticsConsent() || !gtmId) return;
        var existing = document.getElementById('gtm-consent-iframe');
        if (existing) {
            gtmIframeInserted = true;
            return;
        }
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', insertGtmIframeIfNeeded, { once: true });
            return;
        }
        var iframe = document.createElement('iframe');
        iframe.id = 'gtm-consent-iframe';
        iframe.src = 'https://www.googletagmanager.com/ns.html?id=' + gtmId;
        iframe.height = '0';
        iframe.width = '0';
        iframe.style.display = 'none';
        iframe.style.visibility = 'hidden';
        document.body.appendChild(iframe);
        gtmIframeInserted = true;
    }

    function emitConsentEvent(consentGranted) {
        try {
            window.dataLayer.push(enrichPayloadWithPageContext({
                event: 'lgpd_consent_update',
                consent_analytics: consentGranted ? 'granted' : 'denied',
                consent_timestamp: new Date().toISOString()
            }));
        } catch (e) { }
    }

    function resetEngagementTracking() {
        pageStartTime = Date.now();
        scrollDepthTracked = false;
    }

    function sendTimeOnPage(trigger) {
        if (!gtagLoaded) return;
        var timeSpent = Math.round((Date.now() - pageStartTime) / 1000);
        if (timeSpent > 0) {
            dispatchOrQueueEvent('tempo_pagina', {
                event_category: 'Engajamento',
                value: timeSpent,
                event_label: trigger || 'pagehide'
            });
        }
    }

    function normalizeInteractionText(value) {
        if (!value) return '';
        return String(value).replace(/\s+/g, ' ').trim().slice(0, 160);
    }

    function sanitizeAnalyticsText(value, options) {
        if (!value) return '';
        var settings = options || {};
        var maxLength = typeof settings.maxLength === 'number' ? settings.maxLength : 120;
        var text = String(value);
        var emailRegex = /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi;
        var cpfRegex = /\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/g;
        var cnpjRegex = /\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/g;
        var phoneRegex = /\b(?:\+?55\s*)?(?:\(?\d{2}\)?\s*)?\d{4,5}[-.\s]?\d{4}\b/g;
        text = text
            .replace(emailRegex, '')
            .replace(cnpjRegex, '')
            .replace(cpfRegex, '')
            .replace(phoneRegex, '');
        text = text.replace(/\s+/g, ' ').trim();
        if (maxLength && text.length > maxLength) {
            text = text.slice(0, maxLength).trim();
        }
        return text;
    }

    var allowedFieldTypes = {
        text: true,
        email: true,
        tel: true,
        number: true,
        range: true,
        checkbox: true,
        radio: true,
        password: true,
        search: true,
        url: true,
        date: true,
        'datetime-local': true,
        time: true,
        month: true,
        week: true,
        color: true,
        file: true,
        select: true,
        textarea: true
    };

    function getSafeFieldType(element) {
        if (!element || !element.tagName) return '';
        var tagName = element.tagName.toLowerCase();
        if (tagName === 'select') return 'select';
        if (tagName === 'textarea') return 'textarea';
        if (tagName === 'input') {
            var rawType = (element.getAttribute('type') || 'text').toLowerCase();
            return allowedFieldTypes[rawType] ? rawType : 'text';
        }
        return allowedFieldTypes[tagName] ? tagName : tagName;
    }

    function getFieldLabel(element) {
        if (!element || !element.getAttribute) return '';
        var label = element.getAttribute('aria-label') || '';
        if (!label && element.id) {
            var selectorId = element.id;
            if (window.CSS && typeof window.CSS.escape === 'function') {
                selectorId = window.CSS.escape(selectorId);
            }
            var labelEl = document.querySelector('label[for="' + selectorId + '"]');
            if (labelEl) {
                label = labelEl.textContent || '';
            }
        }
        return sanitizeAnalyticsText(label);
    }

    function buildFieldInteractionPayload(element) {
        if (!element || !element.tagName) return null;
        var payload = {};
        var fieldName = normalizeInteractionText(element.getAttribute('name') || '');
        var fieldId = normalizeInteractionText(element.id || '');
        var fieldType = getSafeFieldType(element);
        var fieldLabel = getFieldLabel(element);
        var fieldValue = getAllowedFieldValue(element, fieldType);
        if (fieldName) payload.field_name = fieldName;
        if (fieldType) payload.field_type = fieldType;
        if (fieldId) payload.field_id = fieldId;
        if (fieldLabel) payload.field_label = fieldLabel;
        if (fieldValue !== null) payload.field_value = fieldValue;
        return payload;
    }

    function getAllowedFieldValue(element, fieldType) {
        if (!element) return null;
        var type = fieldType || getSafeFieldType(element);
        var allowedValueTypes = {
            checkbox: 'boolean',
            radio: 'boolean',
            number: 'number',
            range: 'number'
        };
        if (!allowedValueTypes[type]) return null;
        if (allowedValueTypes[type] === 'boolean') {
            return !!element.checked;
        }
        var rawValue = element.value;
        if (rawValue === '' || rawValue === null || rawValue === undefined) return null;
        var numericValue = Number(rawValue);
        return Number.isFinite(numericValue) ? numericValue : null;
    }

    function dispatchFieldInteractionEvent(name, payload) {
        if (typeof window.gtag !== 'function') return;
        var enrichedPayload = enrichPayloadWithPageContext(payload);
        var allowedKeys = ['field_name', 'field_type', 'field_id', 'field_label', 'field_value', 'page_type', 'content_group'];
        var sanitizedPayload = {};
        allowedKeys.forEach(function (key) {
            if (enrichedPayload[key] !== undefined && enrichedPayload[key] !== '') {
                sanitizedPayload[key] = enrichedPayload[key];
            }
        });
        if (!Object.keys(sanitizedPayload).length) return;
        if (!canDispatchAnalytics()) {
            queueEvent(name, sanitizedPayload);
            return;
        }
        window.gtag('event', name, sanitizedPayload);
    }

    function getInteractionHref(element) {
        if (!element || element.tagName !== 'A' || !element.getAttribute) return '';
        var rawHref = element.getAttribute('href') || '';
        if (!rawHref || rawHref.indexOf('javascript:') === 0) return '';
        try {
            var url = new URL(rawHref, locationOrigin);
            return (url.pathname || '') + (url.search || '');
        } catch (e) {
            return '';
        }
    }

    function getInteractionRawHref(element) {
        if (!element || element.tagName !== 'A' || !element.getAttribute) return '';
        return element.getAttribute('href') || '';
    }

    function getInteractionUrl(rawHref) {
        if (!rawHref || rawHref.indexOf('javascript:') === 0) return null;
        try {
            return new URL(rawHref, locationOrigin);
        } catch (e) {
            return null;
        }
    }

    function getFileExtensionFromPath(pathname) {
        if (!pathname) return '';
        var match = pathname.toLowerCase().match(/\.([a-z0-9]{2,6})$/);
        return match ? match[1] : '';
    }

    function getTrackedDownloadExtension(rawHref) {
        var url = getInteractionUrl(rawHref);
        if (!url) return '';
        var extension = getFileExtensionFromPath(url.pathname || '');
        if (!extension) return '';
        var allowed = {
            pdf: true,
            doc: true,
            docx: true,
            xls: true,
            xlsx: true,
            ppt: true,
            pptx: true,
            csv: true,
            txt: true,
            rtf: true,
            odt: true,
            ods: true,
            odp: true,
            zip: true,
            rar: true,
            '7z': true
        };
        return allowed[extension] ? extension : '';
    }

    function buildContactClickPayload(rawHref) {
        var normalized = (rawHref || '').toLowerCase();
        var contactType = normalized.indexOf('mailto:') === 0 ? 'email' : 'phone';
        return {
            link_url: rawHref,
            contact_type: contactType
        };
    }

    function getInteractionLabel(element) {
        if (!element || !element.getAttribute) return '';
        var dataset = element.dataset || {};
        var label = dataset.gtagLabel || dataset.gtagContext || dataset.gtagComponent || element.getAttribute('aria-label') || element.getAttribute('title') || '';
        if (!label && element.tagName === 'INPUT') {
            label = element.value || element.getAttribute('value') || '';
        }
        if (!label) {
            label = normalizeInteractionText(element.textContent || '');
        }
        if (!label && element.tagName === 'IMG') {
            label = element.getAttribute('alt') || '';
        }
        if (!label && element.tagName === 'A') {
            label = getInteractionHref(element);
        }
        return sanitizeAnalyticsText(label);
    }

    function getInteractionContext(element) {
        if (!element || !element.getAttribute) return { component: '', section: '' };
        var sourceElement = element;
        if (typeof element.closest === 'function') {
            sourceElement = element.closest('[data-gtag-context], [data-gtag-component], [data-section]') || element;
        }
        var dataset = sourceElement.dataset || {};
        var component = dataset.gtagComponent || dataset.gtagContext || sourceElement.getAttribute('data-gtag-component') || sourceElement.getAttribute('data-gtag-context') || '';
        var section = dataset.section || sourceElement.getAttribute('data-section') || '';
        return {
            component: normalizeInteractionText(component),
            section: normalizeInteractionText(section)
        };
    }

    function shouldSkipGenericTracking(element) {
        if (!element) return true;
        if (element.hasAttribute && element.hasAttribute('data-gtag-event')) return true;
        if (element.hasAttribute && element.hasAttribute('data-gtag-ignore')) return true;
        if (element.dataset && (element.dataset.gtagAutoBound || element.dataset.gtagBound)) return true;
        if (typeof element.closest === 'function') {
            var ignoredAncestor = element.closest('[data-gtag-ignore]');
            if (ignoredAncestor) return true;
            var ancestor = element.closest('[data-gtag-event]');
            if (ancestor) return true;
        }
        return false;
    }

    var trackingCoverageSelectors = [
        'button',
        'a',
        '[role="button"]',
        'input[type="button"]',
        'input[type="submit"]',
        '#aplicarFiltros',
        '.botao-home',
        '.gerar-produto',
        '#btnSolicitarDesenho',
        '#btnSolicitarCotacao',
        '#btnSolicitarCadastro',
        '#toggleProduto',
        '#toggleEstoque',
        '#botaoEnviarDesenho',
        '#botaoEnviarCotacao',
        '#botaoEnviarCadastro',
        '#btnCompartilhar',
        '#btnCompartilharWhatsapp',
        '#btnCopiarLink',
        '#btnRefazerConfiguracao',
        '#togglePreco',
        '#btnCancelarDesenho',
        '#btnCancelarCotacao',
        '#btnCancelarCadastro',
        '#botaoSalvarFavorito',
        '#botaoNaoSalvarFavorito',
        '#botaoEnviarCompartilharEmail',
        '#botaoEnviarCompartilharEmailCarrinho',
        'a[href="/AreaCliente"]',
        '#cadastro',
        '#modal-excluir button.botao-primario',
        '.site-search-form',
        '.theme-toggle',
        '.theme-toggle-mobile',
        'a[href*="/CarrinhoProdutos"]',
        '#btnAdicionarCarrinho',
        '#btnAbrirCarrinhoSalvo'
    ];

    function isDevelopmentEnvironment() {
        var host = window.location && window.location.hostname ? window.location.hostname.toLowerCase() : '';
        return host === 'localhost' ||
            host === '127.0.0.1' ||
            host.endsWith('.local') ||
            host.indexOf('dev.') === 0 ||
            host.indexOf('staging') > -1 ||
            host.indexOf('homolog') > -1;
    }

    function isCapturedByGenericTracking(element) {
        if (!element || typeof element.closest !== 'function') return false;
        var target = element.closest('button, a, input[type="button"], input[type="submit"], [role="button"]');
        if (!target || target.disabled) return false;
        if (shouldSkipGenericTracking(target)) return false;
        return true;
    }

    function buildTrackingGapDescriptor(element) {
        if (!element || !element.getAttribute) return null;
        var text = normalizeInteractionText(element.textContent || '');
        if (text && text.length > 80) {
            text = text.slice(0, 77) + '...';
        }
        var descriptor = {
            tag: (element.tagName || '').toLowerCase(),
            id: element.id || '',
            classes: typeof element.className === 'string' ? element.className.trim() : '',
            role: element.getAttribute('role') || '',
            type: element.getAttribute('type') || '',
            name: element.getAttribute('name') || ''
        };
        var label = getInteractionLabel(element);
        if (label) descriptor.label = label;
        var href = getInteractionHref(element);
        if (href) descriptor.href = href;
        if (text) descriptor.text = text;
        return descriptor;
    }

    function reportTrackingCoverage(root) {
        if (!isDevelopmentEnvironment()) return;
        var container = root || document;
        if (!container || typeof container.querySelectorAll !== 'function') return;
        var selector = trackingCoverageSelectors.join(', ');
        if (!selector) return;
        var nodes = Array.from(container.querySelectorAll(selector));
        var gaps = [];
        nodes.forEach(function (element) {
            if (!element || !element.tagName) return;
            if (element.hasAttribute && element.hasAttribute('data-gtag-event')) return;
            if (element.dataset && (element.dataset.gtagAutoBound || element.dataset.gtagBound)) return;
            if (isCapturedByGenericTracking(element)) return;
            var descriptor = buildTrackingGapDescriptor(element);
            if (descriptor) gaps.push(descriptor);
        });
        if (!gaps.length) return;
        try {
            console.warn('[Tracking] Elementos sem data-gtag-event e sem cobertura genérica:', gaps);
        } catch (e) { }
        try {
            if (window.dataLayer && typeof window.dataLayer.push === 'function') {
                window.dataLayer.push({ event: 'tracking_gap', gaps: gaps });
            }
        } catch (e) { }
    }

    function ensureFieldInteractionTracking() {
        if (fieldInteractionTrackingBound) return;
        var bind = function () {
            var handler = function (eventName, event) {
                var target = event && event.target;
                if (!target || !target.tagName) return;
                var tagName = target.tagName.toLowerCase();
                if (tagName !== 'input' && tagName !== 'select' && tagName !== 'textarea') return;
                if (target.disabled) return;
                if (tagName === 'input') {
                    var inputType = (target.getAttribute('type') || 'text').toLowerCase();
                    if (inputType === 'hidden') return;
                }
                if (shouldSkipGenericTracking(target)) return;
                var payload = buildFieldInteractionPayload(target);
                if (!payload) return;
                dispatchFieldInteractionEvent(eventName, payload);
            };
            document.addEventListener('change', function (event) {
                handler('alteracao_campo', event);
            });
            document.addEventListener('blur', function (event) {
                handler('interacao_campo', event);
            }, true);
            fieldInteractionTrackingBound = true;
        };
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', bind, { once: true });
            return;
        }
        bind();
    }

    function ensureGenericInteractionTracking() {
        if (genericInteractionTrackingBound) return;
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', ensureGenericInteractionTracking, { once: true });
            return;
        }
        function isGenericField(element) {
            if (!element || !element.tagName) return false;
            var tagName = element.tagName.toLowerCase();
            if (tagName === 'select' || tagName === 'textarea') return true;
            if (tagName !== 'input') return false;
            var type = (element.getAttribute('type') || 'text').toLowerCase();
            return type === 'checkbox' || type === 'radio' || type === 'range' || type === 'text' || type === 'search' || type === 'number' || type === 'date';
        }

        function isContinuousInput(element) {
            if (!element || !element.tagName) return false;
            var tagName = element.tagName.toLowerCase();
            if (tagName === 'textarea') return true;
            if (tagName !== 'input') return false;
            var type = (element.getAttribute('type') || 'text').toLowerCase();
            return type === 'text' || type === 'search' || type === 'number' || type === 'date' || type === 'range';
        }

        function getFieldLabel(element) {
            if (!element) return '';
            var label = element.getAttribute ? (element.getAttribute('aria-label') || element.getAttribute('placeholder') || '') : '';
            if (!label && element.id) {
                var labelElement = document.querySelector('label[for="' + element.id + '"]');
                if (labelElement) {
                    label = labelElement.textContent || '';
                }
            }
            if (!label) {
                label = element.name || element.id || '';
            }
            return normalizeInteractionText(label);
        }

        function getFormState(form) {
            if (!form) return null;
            var state = formInteractionState.get(form);
            if (!state) {
                state = { touched: false, submitted: false, lastInvalidAt: 0 };
                formInteractionState.set(form, state);
            }
            return state;
        }

        function markFormTouched(form) {
            var state = getFormState(form);
            if (!state) return;
            state.touched = true;
        }

        function markFormSubmitted(form) {
            var state = getFormState(form);
            if (!state) return;
            state.submitted = true;
        }

        function getFieldName(element) {
            if (!element) return '';
            return normalizeInteractionText(element.name || element.id || '');
        }

        function getValidationErrorType(element) {
            if (!element || !element.validity) return 'invalid';
            var validity = element.validity;
            if (validity.valueMissing) return 'value_missing';
            if (validity.typeMismatch) return 'type_mismatch';
            if (validity.patternMismatch) return 'pattern_mismatch';
            if (validity.tooShort) return 'too_short';
            if (validity.tooLong) return 'too_long';
            if (validity.rangeUnderflow) return 'range_underflow';
            if (validity.rangeOverflow) return 'range_overflow';
            if (validity.stepMismatch) return 'step_mismatch';
            if (validity.badInput) return 'bad_input';
            if (validity.customError) return 'custom_error';
            return 'invalid';
        }

        function trackFieldInteraction(element) {
            if (fieldInteractionTrackingBound) return;
            if (!element || element.disabled || shouldSkipGenericTracking(element)) return;
            var fieldType = getSafeFieldType(element);
            var eventLabel = normalizeInteractionText(element.getAttribute('name') || '') ||
                normalizeInteractionText(element.getAttribute('aria-label') || '') ||
                normalizeInteractionText(element.id || '');
            var payload = {
                event_category: 'Formulario',
                event_label: eventLabel || 'campo',
                field_type: fieldType || ''
            };
            if (typeof window.gtag !== 'function') return;
            dispatchOrQueueEvent('alteracao_campo', payload);
        }

        document.addEventListener('focusin', function (event) {
            var target = event && event.target;
            if (!target) return;
            var form = typeof target.closest === 'function' ? target.closest('form') : target.form;
            if (!form || shouldSkipGenericTracking(form)) return;
            markFormTouched(form);
        });
        document.addEventListener('invalid', function (event) {
            if (typeof window.gtag !== 'function') return;
            var target = event && event.target;
            if (!target || !target.tagName) return;
            var form = target.form || (typeof target.closest === 'function' ? target.closest('form') : null);
            if (!form || shouldSkipGenericTracking(form)) return;
            var payload = {
                form_id: form.id || '',
                field_name: getFieldName(target) || '',
                error_type: getValidationErrorType(target)
            };
            var state = getFormState(form);
            if (state) {
                state.lastInvalidAt = Date.now();
            }
            dispatchOrQueueEvent('form_error', payload);
        }, true);
        document.addEventListener('pagehide', function () {
            if (typeof window.gtag !== 'function') return;
            formInteractionState.forEach(function (state, form) {
                if (!state || !state.touched || state.submitted) return;
                if (!form || shouldSkipGenericTracking(form)) return;
                dispatchOrQueueEvent('form_abandon', {
                    form_id: form.id || ''
                });
            });
        });

        document.body.addEventListener('click', function (event) {
            if (typeof window.gtag !== 'function') return;
            var element = event && event.target ? event.target.closest('button, a, input[type="button"], input[type="submit"], [role="button"]') : null;
            if (!element || element.disabled || shouldSkipGenericTracking(element)) return;
            if (element.tagName === 'A') {
                var rawHref = getInteractionRawHref(element);
                if (rawHref) {
                    var lowerHref = rawHref.toLowerCase();
                    if (lowerHref.indexOf('mailto:') === 0 || lowerHref.indexOf('tel:') === 0) {
                        dispatchOrQueueEvent('contact_click', buildContactClickPayload(rawHref));
                        return;
                    }
                    var downloadExtension = getTrackedDownloadExtension(rawHref);
                    if (downloadExtension) {
                        var downloadUrl = getInteractionUrl(rawHref);
                        dispatchOrQueueEvent('download', {
                            link_url: downloadUrl ? downloadUrl.href : rawHref,
                            file_extension: downloadExtension
                        });
                        return;
                    }
                    var interactionUrl = getInteractionUrl(rawHref);
                    if (interactionUrl && interactionUrl.origin && interactionUrl.origin !== locationOrigin) {
                        dispatchOrQueueEvent('outbound_click', {
                            link_url: interactionUrl.href
                        });
                        return;
                    }
                }
            }
            var label = getInteractionLabel(element) || element.id || element.name || element.tagName.toLowerCase();
            var payload = {
                event_category: 'UI',
                event_label: label,
                element_tag: (element.tagName || '').toLowerCase()
            };
            if (element.id) payload.element_id = element.id;
            if (element.name) payload.element_name = element.name;
            var href = getInteractionHref(element);
            if (href) payload.element_href = href;
            var contextData = getInteractionContext(element);
            if (contextData.component) payload.component = contextData.component;
            if (contextData.section) payload.section = contextData.section;
            dispatchOrQueueEvent('interacao_elemento', payload);
        });
        document.addEventListener('input', function (event) {
            if (typeof window.gtag !== 'function') return;
            var element = event && event.target ? event.target.closest('input, textarea') : null;
            if (!element || !isGenericField(element) || !isContinuousInput(element)) return;
            trackFieldInteraction(element);
        });
        document.addEventListener('change', function (event) {
            if (typeof window.gtag !== 'function') return;
            var element = event && event.target ? event.target.closest('input, select, textarea') : null;
            if (!element || !isGenericField(element) || isContinuousInput(element)) return;
            trackFieldInteraction(element);
        });
        document.addEventListener('submit', function (event) {
            if (typeof window.gtag !== 'function') return;
            var form = event && event.target;
            if (!form || form.tagName !== 'FORM' || shouldSkipGenericTracking(form)) return;
            var state = getFormState(form);
            var blocked = false;
            if (event && event.defaultPrevented) {
                blocked = true;
            } else if (typeof form.checkValidity === 'function' && !form.noValidate) {
                blocked = !form.checkValidity();
            }
            if (blocked) {
                var recentInvalid = state && state.lastInvalidAt && (Date.now() - state.lastInvalidAt) < 1000;
                if (!recentInvalid) {
                    dispatchOrQueueEvent('form_error', {
                        form_id: form.id || '',
                        field_name: '',
                        error_type: 'submit_blocked'
                    });
                }
                return;
            }
            markFormSubmitted(form);
            var label = normalizeInteractionText(form.getAttribute('aria-label') || form.getAttribute('name') || form.id || form.getAttribute('action') || '');
            var payload = {
                event_category: 'Formulario',
                event_label: label || 'formulario',
                form_id: form.id || ''
            };
            dispatchOrQueueEvent('envio_formulario', payload);
        });
        genericInteractionTrackingBound = true;
    }

    function getRelevantHash(hashValue) {
        if (!hashValue || hashValue === '#') return '';
        return hashValue;
    }

    function getLocationDetails(targetUrl) {
        var pathname = targetUrl.pathname || '/';
        var search = targetUrl.search || '';
        var hash = getRelevantHash(targetUrl.hash);
        var pagePath = pathname + search + hash;
        var origin = targetUrl.origin || locationOrigin;
        var pageLocation = origin + pathname + search + hash;
        return {
            pagePath: pagePath,
            pageLocation: pageLocation
        };
    }

    function trackPageView(path) {
        if (!canDispatchAnalytics()) return;
        var targetUrl;
        try {
            targetUrl = path ? new URL(path, locationOrigin) : new URL(window.location.href);
        } catch (e) {
            try {
                targetUrl = new URL(window.location.href);
            } catch (err) {
                targetUrl = { pathname: window.location.pathname || '/', search: window.location.search || '', hash: window.location.hash || '', origin: locationOrigin };
            }
        }
        var locationDetails = getLocationDetails(targetUrl);
        lastKnownHash = getRelevantHash(targetUrl.hash);
        var pagePath = locationDetails.pagePath;
        var pageLocation = locationDetails.pageLocation;
        var previousPageLocation = lastTrackedPageLocation || document.referrer || '';
        lastTrackedPageLocation = getNormalizedLocation(pageLocation);
        var pageContext = getPageContextAttributes();
        var viewIdentity = getAnalyticsViewIdentity(targetUrl, pageContext);
        var config = {
            page_path: pagePath,
            page_title: viewIdentity.pageTitle,
            screen_name: viewIdentity.pageTitle,
            screen_class: viewIdentity.screenClass,
            page_location: pageLocation,
            page_referrer: previousPageLocation
        };
        Object.keys(pageContext).forEach(function (key) {
            config[key] = pageContext[key];
        });
        gtag('config', gaMeasurementId, config);
        gtag('event', 'page_view', {
            page_path: pagePath,
            page_title: viewIdentity.pageTitle,
            screen_name: viewIdentity.pageTitle,
            screen_class: viewIdentity.screenClass,
            page_location: pageLocation,
            page_referrer: previousPageLocation
        });
        try {
            var dataLayerPayload = {
                event: 'page_view',
                page_path: pagePath,
                page_title: viewIdentity.pageTitle,
                screen_name: viewIdentity.pageTitle,
                screen_class: viewIdentity.screenClass,
                page_location: pageLocation,
                page_referrer: previousPageLocation
            };
            if (pageContext.page_type) {
                dataLayerPayload.page_type = pageContext.page_type;
            }
            if (pageContext.content_group) {
                dataLayerPayload.content_group = pageContext.content_group;
            }
            window.dataLayer.push(dataLayerPayload);
        } catch (e) { }
        trackSelectorPageAccess(pagePath, pageLocation);
        resetEngagementTracking();
    }

    window.trackPageView = trackPageView;

    function getNormalizedLocation(path) {
        try {
            var normalizedUrl = path ? new URL(path, locationOrigin) : new URL(window.location.href);
            return getLocationDetails(normalizedUrl).pageLocation;
        } catch (e) {
            return getLocationDetails({
                pathname: window.location.pathname || '/',
                search: window.location.search || '',
                hash: window.location.hash || '',
                origin: locationOrigin
            }).pageLocation;
        }
    }

    function shouldTrackLocation(locationValue) {
        if (!locationValue) return false;
        var normalizedValue = getNormalizedLocation(locationValue);
        if (normalizedValue === lastTrackedPageLocation) return false;
        return true;
    }

    function trackPageViewSafe(path) {
        if (historyNavigationGuard) return;
        historyNavigationGuard = true;
        try {
            dispatchOrQueuePageView(path);
        } finally {
            historyNavigationGuard = false;
        }
    }

    function setupHistoryTracking() {
        if (historyTrackingInitialized) return;
        historyTrackingInitialized = true;
        var originalPushState = history.pushState;
        var originalReplaceState = history.replaceState;
        lastKnownHash = getRelevantHash(window.location.hash);

        if (typeof originalPushState === 'function') {
            history.pushState = function (state, title, url) {
                var result = originalPushState.apply(this, arguments);
                var targetLocation = getNormalizedLocation(url);
                if (shouldTrackLocation(targetLocation)) {
                    trackPageViewSafe(url || targetLocation);
                }
                return result;
            };
        }

        if (typeof originalReplaceState === 'function') {
            history.replaceState = function (state, title, url) {
                var result = originalReplaceState.apply(this, arguments);
                var targetLocation = getNormalizedLocation(url);
                if (shouldTrackLocation(targetLocation)) {
                    trackPageViewSafe(url || targetLocation);
                }
                return result;
            };
        }

        window.addEventListener('popstate', function () {
            var targetLocation = getNormalizedLocation();
            if (shouldTrackLocation(targetLocation)) {
                trackPageViewSafe(targetLocation);
            }
        });

        window.addEventListener('hashchange', function () {
            var currentHash = getRelevantHash(window.location.hash);
            if (currentHash === lastKnownHash) return;
            lastKnownHash = currentHash;
            var targetLocation = getNormalizedLocation();
            if (shouldTrackLocation(targetLocation)) {
                trackPageViewSafe(targetLocation);
            }
        });
    }

    function getSelectorPageInfo(pagePath) {
        if (!pagePath) return null;
        var lowerPath = (pagePath || '').toLowerCase();
        var basePath = lowerPath.split('?')[0] || lowerPath;
        var isConfigurador = basePath.indexOf('/paginasconfiguradoresseletores/') !== -1;
        var isConcorrente = basePath.indexOf('/paginasconcorrenteseletores/') !== -1;
        if (!isConfigurador && !isConcorrente) return null;
        var parts = basePath.split('/');
        var fileName = parts[parts.length - 1] || '';
        var pageName = fileName.replace(/\.html?$/, '');
        if (!pageName) return null;
        return {
            page: pageName,
            group: isConcorrente ? 'concorrentes' : 'configuradores'
        };
    }

    function trackSelectorPageAccess(pagePath, pageLocation) {
        var info = getSelectorPageInfo(pagePath);
        if (!info) return;
        var payload = {
            event_category: 'Seletor',
            event_label: pagePath,
            selector_page: info.page,
            selector_group: info.group
        };
        sendEvent('acesso_pagina_seletor', payload);
        try {
            window.dataLayer.push(enrichPayloadWithPageContext({
                event: 'acesso_pagina_seletor',
                page_path: pagePath,
                page_location: pageLocation || '',
                selector_page: info.page,
                selector_group: info.group
            }));
        } catch (e) { }
    }

    function getCurrentLinha() {
        var params = new URLSearchParams(window.location.search);
        for (var _i = 0, _a = Array.from(params.keys()); _i < _a.length; _i++) {
            var k = _a[_i];
            if (k.toUpperCase().endsWith('LN')) {
                return params.get(k) || '';
            }
        }
        var el = document.querySelector('[data-linha]');
        if (el && el.dataset.linha) return el.dataset.linha;
        var input = document.querySelector('input[id$="LN"], select[id$="LN"]');
        return input && input.value ? input.value : '';
    }

    window.getCurrentLinha = window.getCurrentLinha || getCurrentLinha;

    function isSelectorPage() {
        return !!getSelectorPageInfo(window.location.pathname || window.location.href || '');
    }

    function buildSelectorEventName(suffix) {
        var linha = getCurrentLinha();
        var resolvedLinha = (linha || 'desconhecido').toString().trim();
        return 'seletor-' + resolvedLinha + '-' + suffix;
    }

    function sendSelectorEvent(suffix, payload) {
        dispatchOrQueueEvent(buildSelectorEventName(suffix), payload);
    }

    function buildConfiguradorEventName(action) {
        var linha = getCurrentLinha();
        var resolvedLinha = (linha || 'desconhecido').toString().trim();
        return action + '-configurador-' + resolvedLinha;
    }

    function sendConfiguradorEvent(action, payload) {
        dispatchOrQueueEvent(buildConfiguradorEventName(action), payload);
    }

    window.isSelectorPage = window.isSelectorPage || isSelectorPage;
    window.buildSelectorEventName = window.buildSelectorEventName || buildSelectorEventName;
    window.sendSelectorEvent = window.sendSelectorEvent || sendSelectorEvent;
    window.buildConfiguradorEventName = window.buildConfiguradorEventName || buildConfiguradorEventName;
    window.sendConfiguradorEvent = window.sendConfiguradorEvent || sendConfiguradorEvent;

    function getFavoritoOrigem(element) {
        if (element && element.classList && element.classList.contains('botao-favorito-carrinho')) {
            return 'carrinho';
        }
        var path = (window.location && window.location.pathname ? window.location.pathname : '').toLowerCase();
        if (path.indexOf('/carrinho') > -1 || path.indexOf('carrinho') > -1) {
            return 'carrinho';
        }
        if (element && typeof element.closest === 'function') {
            if (element.closest('#lista-carrinho') || element.closest('#total-carrinho') || element.closest('.carrinho-item')) {
                return 'carrinho';
            }
        }
        return 'produto';
    }

    function getProdutoEventLabel(fallback) {
        var linha = getCurrentLinha();
        return linha || fallback || '';
    }

    function trackCompartilharTipo(tipo, acao) {
        if (typeof window.gtag !== 'function') return;
        var label = getProdutoEventLabel(tipo);
        sendConfiguradorEvent('compartilhar-' + tipo, {
            event_category: 'Social',
            event_label: label,
            share_action: acao || tipo,
            share_tipo: tipo
        });
    }

    function ensureFavoritoClickTracking() {
        if (favoritoClickTrackingBound) return;
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', ensureFavoritoClickTracking, { once: true });
            return;
        }
        favoritoClickHandler = function (event) {
            var button = event && event.target ? event.target.closest('.botao-favorito, .botao-favorito-carrinho') : null;
            if (!button || button.disabled) {
                return;
            }
            if (typeof window.gtag !== 'function') {
                return;
            }
            var origem = getFavoritoOrigem(button);
            var dataset = button.dataset || {};
            var acao = 'adicionar';
            if (dataset.favoritoAtivo === '1' || button.classList.contains('favorito-ativo')) {
                acao = 'remover';
            }
            var referencia = (dataset.referencia || dataset.linkFavorito || '').trim();
            var label = referencia || getCurrentLinha() || origem;
            var payload = {
                event_category: 'Favorito',
                event_label: label,
                favorito_acao: acao,
                favorito_origem: origem,
                favorito_referencia: referencia
            };
            var eventoBase = acao === 'remover' ? 'favorito-remover' : 'favorito-adicionar';
            if (isSelectorPage()) {
                sendSelectorEvent(eventoBase, payload);
            } else {
                sendConfiguradorEvent(eventoBase, payload);
            }
        };
        document.body.addEventListener('click', favoritoClickHandler);
        favoritoClickTrackingBound = true;
    }

    function ensureCompartilharEmailTracking() {
        if (compartilharEmailTrackingBound) return;
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', ensureCompartilharEmailTracking, { once: true });
            return;
        }
        compartilharEmailHandler = function (event) {
            var link = event && event.target ? event.target.closest('.compartilhar-email') : null;
            if (!link) {
                return;
            }
            if (typeof window.gtag !== 'function') {
                return;
            }
            var origem = 'produto';
            var path = (window.location && window.location.pathname ? window.location.pathname : '').toLowerCase();
            if (path.indexOf('/carrinho') > -1 || path.indexOf('carrinho') > -1) {
                origem = 'carrinho';
            }
            if (typeof link.closest === 'function' && link.closest('#lista-carrinho')) {
                origem = 'carrinho';
            }
            var label = getCurrentLinha() || origem;
            var payload = {
                event_category: 'Social',
                event_label: label,
                share_action: 'abrir',
                share_origem: origem
            };
            if (isSelectorPage()) {
                sendSelectorEvent('compartilhar-email', payload);
            } else {
                sendConfiguradorEvent('compartilhar-email', payload);
            }
        };
        document.body.addEventListener('click', compartilharEmailHandler);
        compartilharEmailTrackingBound = true;
    }

    function sendLoginEventIfNeeded() {
        try {
            var params = new URLSearchParams(window.location.search);
            if (params.get('login') === 'sucesso') {
                dispatchOrQueueEvent('login', { event_category: 'Conta' });
                params.delete('login');
                var newQuery = params.toString();
                var newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '') + window.location.hash;
                history.replaceState({}, '', newUrl);
            }
        } catch (e) { }
    }

    function sendJsErrorEvent(payload) {
        if (!payload || !payload.message) return;
        var enrichedPayload = enrichPayloadWithPageContext(payload);
        if (typeof window.gtag === 'function') {
            window.gtag('event', 'js_error', enrichedPayload);
        }
        try {
            window.dataLayer.push(enrichPayloadWithPageContext(Object.assign({ event: 'js_error' }, payload)));
        } catch (e) { }
    }

    function buildJsErrorPayload(details) {
        if (!details) return null;
        var message = details.message ? String(details.message) : '';
        var filename = details.filename ? String(details.filename) : '';
        var lineno = details.lineno;
        var payload = {
            message: message,
            filename: filename,
            lineno: lineno != null ? lineno : ''
        };
        if (details.stack) {
            payload.stack = String(details.stack);
        }
        return payload;
    }

    function registerErrorTracking() {
        if (errorTrackingBound) return;
        errorTrackingBound = true;
        window.addEventListener('error', function (event) {
            if (!event) return;
            var errorObj = event.error || null;
            var payload = buildJsErrorPayload({
                message: event.message || (errorObj && errorObj.message) || 'Erro JS',
                filename: event.filename || (errorObj && (errorObj.fileName || errorObj.filename)) || '',
                lineno: event.lineno || (errorObj && (errorObj.lineNumber || errorObj.lineno)),
                stack: errorObj && errorObj.stack
            });
            sendJsErrorEvent(payload);
        });
        window.addEventListener('unhandledrejection', function (event) {
            var reason = event && event.reason;
            var message = '';
            var filename = '';
            var lineno = '';
            var stack = '';
            if (reason) {
                if (typeof reason === 'string') {
                    message = reason;
                } else {
                    message = reason.message || String(reason);
                    filename = reason.fileName || reason.filename || '';
                    lineno = reason.lineNumber || reason.lineno || '';
                    stack = reason.stack || '';
                }
            } else {
                message = 'unhandledrejection';
            }
            var payload = buildJsErrorPayload({
                message: message,
                filename: filename,
                lineno: lineno,
                stack: stack
            });
            sendJsErrorEvent(payload);
        });
    }

    function setupEngagementTracking() {
        resetEngagementTracking();

        window.addEventListener('pagehide', function () {
            sendTimeOnPage('pagehide');
        });

        window.addEventListener('scroll', function () {
            if (scrollDepthTracked || !gtagLoaded) return;
            var scrolled = (window.scrollY || document.documentElement.scrollTop) /
                (document.documentElement.scrollHeight - document.documentElement.clientHeight);
            if (scrolled >= 0.75) {
                scrollDepthTracked = true;
                dispatchOrQueueEvent('scroll_75', { event_category: 'Engajamento' });
            }
        });
    }

    function scheduleTagHealthCheck() {
        if (tagHealthCheckScheduled) return;
        tagHealthCheckScheduled = true;
        setTimeout(function () {
            tagHealthCheckScheduled = false;
            if (!hasAnalyticsConsent()) return;
            if (gaMeasurementId && !gtagScriptLoaded) {
                sendTagErrorLog('gtag', 'nao_carregado', { id: gaMeasurementId });
            }
            if (gtmId && !gtmScriptLoaded) {
                sendTagErrorLog('gtm', 'nao_carregado', { id: gtmId });
            }
        }, 8000);
    }

    function handleConsentChange(consentState) {
        var state = consentState && typeof consentState === 'object' ? consentState : getLgpdConsentState();
        var analyticsGranted = !!state.performance;
        var advertisingGranted = !!state.advertising;
        var loadAttempted = false;
        atualizarConsentimento({ performance: analyticsGranted, advertising: advertisingGranted });
        if (gaMeasurementId) {
            window['ga-disable-' + gaMeasurementId] = !analyticsGranted;
        }
        emitConsentEvent(analyticsGranted);
        if (analyticsGranted) {
            initialPageViewTracked = false;
            gtagConfigured = false;
            ensureAnalyticsPreconnects();
            loadAttempted = loadGtag() || loadAttempted;
            configureGtagAfterConsent();
            loadAttempted = scheduleGtmLoad() || loadAttempted;
            insertGtmIframeIfNeeded();
            ensureGtmIframe();
            dispatchOrQueuePageView();
            initCoreWebVitals();
            flushPendingEvents();
            if (loadAttempted) {
                if (typeof scheduleTagHealthCheck === 'function') {
                    scheduleTagHealthCheck();
                }
            }
        } else {
            removeAnalyticsPreconnects();
            var finalizeRevocation = function () {
                gtagConfigured = false;
                cancelScheduledGtmLoad();
                unloadGtm();
                resetEngagementTracking();
                teardownWebVitals();
                clearPendingEvents();
            };
            if (typeof window.gtag === 'function' || window.dataLayer) {
                setTimeout(finalizeRevocation, 0);
            } else {
                finalizeRevocation();
            }
        }
    }

    function watchConsentChanges(callback) {
        var lastState = getLgpdConsentState();

        function evaluate() {
            var current = getLgpdConsentState();
            if (current.performance !== lastState.performance || current.advertising !== lastState.advertising) {
                lastState = current;
                callback(current);
            }
            return current;
        }

        var events = [
            'lgpdCookiesMudanca',
            'lgpdCookiesPermissao',
            'lgpdCookiesNegado',
            'lgpdCookiesRevogado',
            'lgpdCookiesChange',
            'lgpdConsentChange'
        ];

        events.forEach(function (eventName) {
            window.addEventListener(eventName, evaluate);
            document.addEventListener(eventName, evaluate);
        });

        window.addEventListener('storage', function (event) {
            if (!event.key || /lgpd/i.test(event.key) || /consent/i.test(event.key)) {
                evaluate();
            }
        });

        var checkInterval = setInterval(function () {
            var current = evaluate();
            if (current.performance && gtagLoaded && gtmLoaded) {
                clearInterval(checkInterval);
            }
        }, 1000);
    }

    function initializeConsentTracking() {
        handleConsentChange(hasAnalyticsConsent());
        watchConsentChanges(handleConsentChange);
    }

    function initializePageTracking() {
        if (pageTrackingInitialized) return;
        pageTrackingInitialized = true;
        setupHistoryTracking();
        setupEventTracking();
        reportTrackingCoverage(document);
        sendLoginEventIfNeeded();
        registerErrorTracking();
        setupEngagementTracking();
        observeDynamicContent();
    }

    initializeConsentTracking();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializePageTracking, { once: true });
    } else {
        initializePageTracking();
    }
    window.addEventListener('load', initializePageTracking);

    function observeDynamicContent() {
        if (!('MutationObserver' in window)) return;

        function startObserver() {
            if (!document.body) return;
            var observer = new MutationObserver(function (mutations) {
                var shouldRebind = mutations.some(function (mutation) {
                    return mutation.addedNodes && mutation.addedNodes.length;
                });
                if (!shouldRebind) return;
                if (rebindScheduled) return;
                rebindScheduled = true;
                requestAnimationFrame(function () {
                    rebindScheduled = false;
                    setupEventTracking();
                    if (!trackingCoverageScheduled) {
                        trackingCoverageScheduled = true;
                        requestAnimationFrame(function () {
                            trackingCoverageScheduled = false;
                            reportTrackingCoverage(document);
                        });
                    }
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }

        if (document.body) {
            startObserver();
        } else {
            document.addEventListener('DOMContentLoaded', startObserver, { once: true });
        }
    }

    function coletarFiltrosProdutos() {
        var termoInput = document.getElementById('filtroBusca');
        var termo = termoInput ? termoInput.value.trim() : '';
        if (!termo) {
            var termosAtivos = obterTermosBuscaAtivos();
            if (termosAtivos.length) {
                termo = termosAtivos.join(' ');
            }
        }
        var categorias = [];
        var linhas = [];
        var desenhos = [];
        document.querySelectorAll('#dropdownCategorias input[type="checkbox"]:checked').forEach(function (el) {
            if (el && el.value) categorias.push(el.value);
        });
        document.querySelectorAll('#dropdownLinhas input[type="checkbox"]:checked').forEach(function (el) {
            if (el && el.value) linhas.push(el.value);
        });
        document.querySelectorAll('#dropdownDesenhos input[type="checkbox"]:checked').forEach(function (el) {
            if (el && el.value) desenhos.push(el.value);
        });
        return {
            termo: termo,
            categorias: categorias,
            linhas: linhas,
            desenhos: desenhos
        };
    }

    function obterProximaOrdenacaoProdutos() {
        var ordenarBtn = document.getElementById('ordenarAlfa');
        if (!ordenarBtn) return '';
        var texto = (ordenarBtn.textContent || '').toLowerCase();
        var ordemAtualAlfa = texto.indexOf('ordem padrão') > -1;
        return ordemAtualAlfa ? 'ordem_padrao' : 'ordenar_az';
    }

    function obterEstadoToggleFiltros() {
        var areaFiltros = document.getElementById('areaFiltros');
        var aberto = areaFiltros && areaFiltros.classList.contains('show');
        return aberto ? 'fechar' : 'abrir';
    }

    function obterTermoSiteSearch(form) {
        if (!form || typeof form.querySelector !== 'function') return '';
        var input = form.querySelector('input[type="search"], input[name="termo"]');
        return input && typeof input.value === 'string' ? input.value.trim() : '';
    }

    function obterTermosBuscaAtivos() {
        var container = document.getElementById('filtrosAtivosChips');
        if (!container) return [];
        var termos = [];
        container.querySelectorAll('.pagina-produtos__chip-text').forEach(function (el) {
            var texto = el && el.textContent ? el.textContent.trim() : '';
            if (!texto) return;
            var match = texto.match(/^Busca:\s*(.+)$/i);
            if (match && match[1]) termos.push(match[1].trim());
        });
        return termos;
    }

    function contarProdutosRenderizados() {
        var itens = document.querySelectorAll('.botao-linha');
        var total = 0;
        itens.forEach(function (el) {
            if (!el) return;
            if (el.style && el.style.display === 'none') return;
            total++;
        });
        return total;
    }

    function buildFilterPayload() {
        var filtros = coletarFiltrosProdutos();
        var filtrosAtivos = Boolean(
            (filtros.termo && filtros.termo.length) ||
            (filtros.categorias && filtros.categorias.length) ||
            (filtros.linhas && filtros.linhas.length) ||
            (filtros.desenhos && filtros.desenhos.length)
        );
        var termoSanitizado = sanitizeAnalyticsText(filtros.termo || '');
        return {
            filtros_ativos: filtrosAtivos ? 'sim' : 'nao',
            filtro_termo: termoSanitizado,
            filtro_categorias: filtros.categorias.join('|'),
            filtro_linhas: filtros.linhas.join('|'),
            filtro_desenhos: filtros.desenhos.join('|')
        };
    }

    function getGtagExtraPayload(el, eventName) {
        if (!eventName) return null;
        if (eventName === 'toggle_filtros') {
            var acao = obterEstadoToggleFiltros();
            return { event_label: acao, filtro_toggle: acao };
        }
        if (eventName === 'ordenar_az') {
            var proximaOrdem = obterProximaOrdenacaoProdutos();
            if (!proximaOrdem) return null;
            return { event_label: proximaOrdem, ordem_produtos: proximaOrdem };
        }
        if (
            eventName === 'aplicar_filtros'
            || eventName === 'copiar_filtros'
            || eventName === 'limpar_filtros'
            || eventName === 'home-filtro-aplicar'
            || eventName === 'home-filtro-copiar'
            || eventName === 'home-filtro-limpar'
        ) {
            var payload = buildFilterPayload();
            if (eventName === 'aplicar_filtros' || eventName === 'home-filtro-aplicar') {
                payload.event_label = payload.filtros_ativos === 'sim' ? 'com_filtro' : 'sem_filtro';
            }
            return payload;
        }
        if (
            eventName === 'search'
            || eventName === 'pesquisar-produto-cabecalho-manual'
            || eventName === 'home-filtro-busca'
        ) {
            var termoBusca = '';
            if (el && el.tagName === 'FORM') {
                termoBusca = obterTermoSiteSearch(el);
            }
            if (!termoBusca) {
                var filtrosBusca = coletarFiltrosProdutos();
                termoBusca = filtrosBusca.termo || '';
            }
            var termoSanitizado = sanitizeAnalyticsText(termoBusca || '');
            return {
                search_term: termoSanitizado,
                results_count: contarProdutosRenderizados()
            };
        }
        return null;
    }

    function shouldUseCapture(el, eventName) {
        return [
            'toggle_filtros',
            'aplicar_filtros',
            'copiar_filtros',
            'limpar_filtros',
            'ordenar_az',
            'home-filtro-aplicar',
            'home-filtro-copiar',
            'home-filtro-limpar'
        ].includes(eventName);
    }

    function bindGtagDataAttributes(root) {
        var container = root || document;
        if (!container || typeof container.querySelectorAll !== 'function') return;
        var nodes = container.querySelectorAll('[data-gtag-event]');
        nodes.forEach(function (el) {
            if (!el || el.dataset.gtagAutoBound) return;
            var eventName = el.getAttribute('data-gtag-event');
            if (!eventName) return;
            var eventType = el.tagName === 'FORM' ? 'submit' : 'click';
            var useCapture = shouldUseCapture(el, eventName);
            el.addEventListener(eventType, function () {
                if (typeof window.gtag !== 'function') return;
                var payload = {};
                var category = el.getAttribute('data-gtag-category');
                var label = el.getAttribute('data-gtag-label');
                var value = el.getAttribute('data-gtag-value');
                var contextData = getInteractionContext(el);
                var flowConfig = getFlowEventConfigByName(eventName);
                if (flowConfig) {
                    payload.event_category = flowConfig.category;
                    payload.event_label = flowConfig.step;
                    payload.flow = flowConfig.flow;
                    payload.flow_step = flowConfig.step;
                    if (label) {
                        payload.flow_context = label;
                    }
                } else {
                    if (category) payload.event_category = category;
                    if (label) payload.event_label = label;
                }
                if (value !== null && value !== '' && !isNaN(Number(value))) {
                    payload.value = Number(value);
                }
                var extraPayload = getGtagExtraPayload(el, eventName);
                if (extraPayload && typeof extraPayload === 'object') {
                    payload = Object.assign(payload, extraPayload);
                }
                var canonicalEvent = resolveCanonicalEvent(eventName, payload, el);
                if (canonicalEvent && canonicalEvent.name && canonicalEvent.name !== eventName) {
                    dispatchOrQueueEvent(canonicalEvent.name, canonicalEvent.payload);
                }
                dispatchOrQueueEvent(eventName, payload);
            }, useCapture);
            el.dataset.gtagAutoBound = '1';
        });
    }

    function setupEventTracking() {
        var byId = function (id) { return document.getElementById(id); };
        var attach = function (el, event, handler) {
            if (!el || el.dataset.gtagBound) return;
            if (el.hasAttribute('data-gtag-event')) return;
            el.dataset.gtagBound = '1';
            el.addEventListener(event, handler);
        };

        ensureFavoritoClickTracking();
        ensureCompartilharEmailTracking();
        ensureGenericInteractionTracking();
        ensureFieldInteractionTracking();
        bindGtagDataAttributes(document);

        attach(byId('aplicarFiltros'), 'click', function () {
            dispatchOrQueueEvent('home-filtro-aplicar', { event_category: 'Filtro' });
        });

        document.querySelectorAll('.botao-home').forEach(function (el) {
            if (el.classList && el.classList.contains('gerar-produto')) {
                return;
            }
            attach(el, 'click', function () {
                var linha = el.dataset.linha || '';
                if (!linha && el.href) {
                    var match = el.href.match(/([?&][^=]*LN)=([^&]+)/i);
                    if (match) {
                        linha = decodeURIComponent(match[2]);
                    }
                }
                var eventName = 'acesso-configurador-' + (linha || 'desconhecido');
                dispatchOrQueueEvent(eventName, {
                    event_category: 'Navegacao',
                    event_label: linha || ''
                });
            });
        });

        document.querySelectorAll('.gerar-produto').forEach(function (el) {
            attach(el, 'click', function () {
                var linha = getCurrentLinha();
                if (isSelectorPage()) {
                    sendSelectorEvent('gerar', {
                        event_category: 'Seletor',
                        event_label: linha
                    });
                    return;
                }
                sendConfiguradorEvent('gerar', { event_category: 'Configurador', event_label: linha });
            });
        });

        attach(byId('btnSolicitarDesenho'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('desenho-solicitar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('desenho-solicitar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('btnSolicitarCotacao'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('cotacao-solicitar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('cotacao-solicitar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('btnSolicitarCadastro'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('cadastro-solicitar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('cadastro-solicitar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('toggleProduto'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('informacoes', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('informacoes', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('toggleEstoque'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('estoque', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('estoque', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('botaoEnviarDesenho'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('desenho-enviar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('desenho-enviar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('botaoEnviarCotacao'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('cotacao-enviar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('cotacao-enviar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('botaoEnviarCadastro'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('cadastro-enviar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('cadastro-enviar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });


        attach(byId('btnCompartilhar'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('compartilhar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('compartilhar', { event_category: 'Social', event_label: getCurrentLinha() });
        });

        attach(byId('btnCompartilharWhatsapp'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('compartilhar-whatsapp', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            trackCompartilharTipo('whatsapp', 'abrir');
        });

        attach(byId('btnCopiarLink'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('compartilhar-link', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            trackCompartilharTipo('link', 'copiar');
        });

        var handleReconfigurarClick = function () {
            if (isSelectorPage()) {
                sendSelectorEvent('reconfigurar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('reconfigurar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        };

        attach(byId('btnRefazerConfiguracao'), 'click', handleReconfigurarClick);
        attach(byId('btn-reset-configuracao'), 'click', handleReconfigurarClick);

        attach(byId('togglePreco'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('preco', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('preco', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('btnCancelarDesenho'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('desenho-cancelar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('desenho-cancelar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('btnCancelarCotacao'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('cotacao-cancelar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('cotacao-cancelar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('btnCancelarCadastro'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('cadastro-cancelar', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('cadastro-cancelar', { event_category: 'Configurador', event_label: getCurrentLinha() });
        });

        attach(byId('botaoSalvarFavorito'), 'click', function () {
            var origem = ((window.location.pathname || '').toLowerCase().indexOf('carrinho') > -1) ? 'carrinho' : 'produto';
            var referencia = '';
            var origemButton = document.querySelector('.botao-favorito.favorito-ativo[data-referencia]');
            if (origemButton && origemButton.dataset) {
                referencia = (origemButton.dataset.referencia || origemButton.dataset.linkFavorito || '').trim();
                if (origemButton.classList.contains('botao-favorito-carrinho')) {
                    origem = 'carrinho';
                }
            }
            var label = referencia || getCurrentLinha() || origem;
            var payload = {
                event_category: 'Favorito',
                event_label: label,
                favorito_origem: origem,
                favorito_acao: 'salvar_comentario',
                favorito_referencia: referencia
            };
            if (isSelectorPage()) {
                sendSelectorEvent('favorito-salvar-comentario', payload);
                return;
            }
            sendConfiguradorEvent('favorito-salvar-comentario', payload);
        });

        attach(byId('botaoNaoSalvarFavorito'), 'click', function () {
            var origem = ((window.location.pathname || '').toLowerCase().indexOf('carrinho') > -1) ? 'carrinho' : 'produto';
            var referencia = '';
            var origemButton = document.querySelector('.botao-favorito.favorito-ativo[data-referencia]');
            if (origemButton && origemButton.dataset) {
                referencia = (origemButton.dataset.referencia || origemButton.dataset.linkFavorito || '').trim();
                if (origemButton.classList.contains('botao-favorito-carrinho')) {
                    origem = 'carrinho';
                }
            }
            var label = referencia || getCurrentLinha() || origem;
            var payload = {
                event_category: 'Favorito',
                event_label: label,
                favorito_origem: origem,
                favorito_acao: 'salvar_sem_comentario',
                favorito_referencia: referencia
            };
            if (isSelectorPage()) {
                sendSelectorEvent('favorito-nao-salvar-comentario', payload);
                return;
            }
            sendConfiguradorEvent('favorito-nao-salvar-comentario', payload);
        });

        attach(byId('botaoEnviarCompartilharEmail'), 'click', function () {
            var origem = 'produto';
            var label = getCurrentLinha() || origem;
            var payload = {
                event_category: 'Social',
                event_label: label,
                share_action: 'enviar',
                share_origem: origem
            };
            if (isSelectorPage()) {
                sendSelectorEvent('compartilhar-email-enviar', payload);
                return;
            }
            sendConfiguradorEvent('compartilhar-email-enviar', payload);
        });

        attach(byId('botaoEnviarCompartilharEmailCarrinho'), 'click', function () {
            var origem = 'carrinho';
            var label = getCurrentLinha() || origem;
            dispatchOrQueueEvent('carrinho-compartilhar-email-enviar', {
                event_category: 'Social',
                event_label: label,
                share_action: 'enviar',
                share_origem: origem
            });
        });

        document.querySelectorAll('a[href="/AreaCliente"]').forEach(function (el) {
            attach(el, 'click', function () {
                dispatchOrQueueEvent('areacliente-cabecalho', {
                    event_category: 'Navegacao',
                    event_label: getCurrentLinha()
                });
            });
        });


        attach(byId('cadastro'), 'submit', function () {
            dispatchOrQueueEvent('cadastro', { event_category: 'Conta' });
        });

        attach(document.querySelector('#modal-excluir button.botao-primario'), 'click', function () {
            dispatchOrQueueEvent('excluir_conta', { event_category: 'Conta' });
        });

        if (!bodyClickBound && document.body) {
            document.body.addEventListener('click', function (e) {
                var a = e.target.closest('a[href]');
                if (a && a.href && a.href.indexOf('www.redutoresibr.com.br') > -1) {
                    dispatchOrQueueEvent('redirecionar_ibr', { event_category: 'Navegacao', event_label: a.href });
                }
            });
            bodyClickBound = true;
        }

        document.querySelectorAll('.site-search-form').forEach(function (form) {
            attach(form, 'submit', function () {
                var termoBusca = obterTermoSiteSearch(form);
                dispatchOrQueueEvent('pesquisar-produto-cabecalho-manual', {
                    event_category: 'Busca',
                    search_term: termoSanitizado
                });
            });
        });
        document.querySelectorAll('a[href*="/CarrinhoProdutos"]').forEach(function (el) {
            attach(el, 'click', function () {
                trackFlowEvent('carrinho', 'abrir', { flow_context: 'menu' });
            });
        });

        attach(byId('btnAdicionarCarrinho'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('carrinho', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('carrinho', {
                event_category: 'Carrinho',
                event_label: getCurrentLinha(),
                carrinho_acao: 'adicionar'
            });
        });

        attach(byId('btnAdicionarCarrinhoFlutuante'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('carrinho', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('carrinho', {
                event_category: 'Carrinho',
                event_label: getCurrentLinha(),
                carrinho_acao: 'adicionar'
            });
        });
        attach(byId('btnTrocarFlutuante'), 'click', function () {
            if (isSelectorPage()) {
                sendSelectorEvent('carrinho', {
                    event_category: 'Seletor',
                    event_label: getCurrentLinha()
                });
                return;
            }
            sendConfiguradorEvent('carrinho', {
                event_category: 'Carrinho',
                event_label: getCurrentLinha(),
                carrinho_acao: 'trocar'
            });
        });

        attach(byId('btnAbrirCarrinhoSalvo'), 'click', function () {
            trackFlowEvent('carrinho', 'abrir', { flow_context: 'salvo' });
        });
    }

    window.addEventListener('spa:navigation', function (event) {
        sendTimeOnPage('spa_navigation');
        var url = event && event.detail ? event.detail.url : undefined;
        dispatchOrQueuePageView(url);
        setupEventTracking();
    });

    if (navigator.serviceWorker) {
        navigator.serviceWorker.addEventListener('message', function (e) {
            if (e.data && e.data.type === 'offline-request') {
                dispatchOrQueueEvent('offline_request', {
                    event_category: 'ServiceWorker',
                    event_label: e.data.url || ''
                });
            }
        });
    }
}

ensureGtmIframe();

function ensureAnalyticsPreconnects() {
    var head = document.head || document.getElementsByTagName('head')[0];
    if (!head) {
        return;
    }
    analyticsPreconnectMap.forEach(function (entry) {
        var selector = 'link[data-analytics-preconnect="' + entry.id + '"]';
        if (head.querySelector(selector)) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'preconnect';
        link.href = entry.href;
        link.crossOrigin = 'anonymous';
        link.setAttribute('data-analytics-preconnect', entry.id);
        head.appendChild(link);
    });
}

function removeAnalyticsPreconnects() {
    var head = document.head || document.getElementsByTagName('head')[0];
    if (!head) {
        return;
    }
    analyticsPreconnectMap.forEach(function (entry) {
        var selector = 'link[data-analytics-preconnect="' + entry.id + '"]';
        var existing = head.querySelector(selector);
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }
    });
}

var seoDescricaoService = (function () {
    var memoryCache = new Map();
    var storageKey = 'seo-descricao-cache-v1';
    var ttlMs = 24 * 60 * 60 * 1000;
    var maxEntries = 200;
    var blockedTerms = [
        'clique aqui',
        'compre agora',
        'imperdivel',
        'imperdível',
        'gratuito',
        'grátis'
    ];
    var toneWarnings = [
        /\bpromo(c|ç)a[oã]\b/i,
        /\bdesconto\b/i,
        /\boferta\b/i
    ];

    function normalizeText(valor) {
        if (!valor) return '';
        return String(valor).replace(/\s+/g, ' ').trim();
    }

    function stripHtml(valor) {
        return normalizeText(String(valor || '').replace(/<[^>]*>/g, ' '));
    }

    function toKeySegment(valor) {
        return normalizeText(valor).toLowerCase();
    }

    function hashString(valor) {
        var hash = 0;
        if (!valor) return hash;
        for (var i = 0; i < valor.length; i++) {
            hash = (hash << 5) - hash + valor.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash);
    }

    function loadStorage() {
        if (typeof localStorage === 'undefined') {
            return {};
        }
        try {
            var raw = localStorage.getItem(storageKey);
            if (!raw) return {};
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function saveStorage(cacheObj) {
        if (typeof localStorage === 'undefined') {
            return;
        }
        try {
            localStorage.setItem(storageKey, JSON.stringify(cacheObj));
        } catch (e) {
            // ignore quota errors
        }
    }

    function pruneStorage(cacheObj) {
        var keys = Object.keys(cacheObj || {});
        if (keys.length <= maxEntries) {
            return cacheObj;
        }
        keys.sort(function (a, b) {
            return (cacheObj[a].ts || 0) - (cacheObj[b].ts || 0);
        });
        var toRemove = keys.length - maxEntries;
        for (var i = 0; i < toRemove; i++) {
            delete cacheObj[keys[i]];
        }
        return cacheObj;
    }

    function readCache(key) {
        if (!key) return null;
        var now = Date.now();
        if (memoryCache.has(key)) {
            var cached = memoryCache.get(key);
            if (cached && cached.ts + ttlMs > now) {
                return cached.value;
            }
            memoryCache.delete(key);
        }
        var storage = loadStorage();
        if (storage[key] && storage[key].ts + ttlMs > now) {
            memoryCache.set(key, storage[key]);
            return storage[key].value;
        }
        return null;
    }

    function writeCache(key, value) {
        if (!key) return;
        var entry = { value: value, ts: Date.now() };
        memoryCache.set(key, entry);
        var storage = loadStorage();
        storage[key] = entry;
        saveStorage(pruneStorage(storage));
    }

    function validarTom(texto) {
        for (var i = 0; i < toneWarnings.length; i++) {
            if (toneWarnings[i].test(texto)) {
                return false;
            }
        }
        return true;
    }

    function filtrarQualidade(texto, options) {
        var opts = options || {};
        var maxChars = typeof opts.maxChars === 'number' ? opts.maxChars : 170;
        var minChars = typeof opts.minChars === 'number' ? opts.minChars : 70;
        var allowShort = Boolean(opts.allowShort);
        var normalizado = stripHtml(texto);
        if (!normalizado) return '';
        var lower = normalizado.toLowerCase();
        for (var i = 0; i < blockedTerms.length; i++) {
            if (lower.indexOf(blockedTerms[i]) !== -1) {
                return '';
            }
        }
        if (!validarTom(normalizado)) {
            return '';
        }
        if (!allowShort && normalizado.length < minChars) {
            return '';
        }
        if (normalizado.length > maxChars) {
            normalizado = normalizado.slice(0, maxChars - 1).trim() + '…';
        }
        normalizado = normalizado.replace(/!{2,}/g, '!');
        return normalizado;
    }

    function gerarVariacoes(base, contexto) {
        var ctx = contexto || {};
        var termo = Array.isArray(ctx.termos) && ctx.termos.length ? ctx.termos.join(', ') : '';
        var categorias = Array.isArray(ctx.categorias) && ctx.categorias.length ? ctx.categorias.join(', ') : '';
        var linhas = Array.isArray(ctx.linhas) && ctx.linhas.length ? ctx.linhas.join(', ') : '';
        var desenhos = Array.isArray(ctx.desenhos) && ctx.desenhos.length ? ctx.desenhos.join(', ') : '';
        var partes = [];
        if (termo) partes.push('Busca por ' + termo);
        if (categorias) partes.push('Categorias: ' + categorias);
        if (linhas) partes.push('Linhas: ' + linhas);
        if (desenhos) partes.push('Desenhos: ' + desenhos);
        var resumoContexto = partes.join('. ');
        var templates = [
            '{base} {contexto}',
            '{contexto}. {base}',
            '{base} Confira {contexto}.',
            'Explore opções com {contexto}. {base}',
            '{base} Informações sobre {contexto}.'
        ];
        var safeBase = normalizeText(base);
        var safeContext = normalizeText(resumoContexto);
        if (!safeContext) {
            return [safeBase];
        }
        return templates.map(function (template) {
            return template
                .replace('{base}', safeBase)
                .replace('{contexto}', safeContext);
        });
    }

    function escolherVariacao(variacoes, seed) {
        if (!variacoes || !variacoes.length) return '';
        var index = 0;
        if (variacoes.length > 1) {
            index = seed % variacoes.length;
        }
        return variacoes[index];
    }

    function construirChave(baseKey, base, contexto) {
        var ctxKey = '';
        if (contexto && typeof contexto === 'object') {
            ctxKey = Object.keys(contexto)
                .sort()
                .map(function (key) {
                    var valor = contexto[key];
                    if (Array.isArray(valor)) {
                        valor = valor.join(',');
                    }
                    return key + ':' + toKeySegment(valor || '');
                })
                .join('|');
        }
        return [baseKey || 'seo', toKeySegment(base), ctxKey].filter(Boolean).join('::');
    }

    function obterDescricao(params) {
        var options = params || {};
        var base = options.base || '';
        var contexto = options.contexto || {};
        var fallback = options.fallback || '';
        var baseKey = options.key || 'seo';
        var cacheKey = construirChave(baseKey, base, contexto);
        var cached = readCache(cacheKey);
        if (cached) return cached;
        var variacoes = gerarVariacoes(base, contexto);
        var seed = hashString(cacheKey);
        var candidato = escolherVariacao(variacoes, seed);
        var filtrado = filtrarQualidade(candidato, options);
        var final = filtrado
            || filtrarQualidade(fallback, options)
            || filtrarQualidade(base, options)
            || normalizeText(base)
            || normalizeText(fallback);
        writeCache(cacheKey, final);
        return final;
    }

    function limparCache() {
        memoryCache.clear();
        if (typeof localStorage !== 'undefined') {
            try {
                localStorage.removeItem(storageKey);
            } catch (e) {
                // ignore
            }
        }
    }

    return {
        obterDescricao: obterDescricao,
        filtrarQualidade: filtrarQualidade,
        limparCache: limparCache
    };
})();

window.SEODescricaoService = seoDescricaoService;

function setMetaContent(selector, value) {
    if (!value) return;
    var tag = document.querySelector(selector);
    if (!tag) {
        if (selector.indexOf('meta') !== -1) {
            tag = document.createElement('meta');
            if (selector.indexOf('property=') !== -1) {
                var prop = selector.match(/property=["']([^"']+)["']/);
                if (prop && prop[1]) {
                    tag.setAttribute('property', prop[1]);
                }
            }
            if (selector.indexOf('name=') !== -1) {
                var name = selector.match(/name=["']([^"']+)["']/);
                if (name && name[1]) {
                    tag.setAttribute('name', name[1]);
                }
            }
            (document.head || document.documentElement).appendChild(tag);
        }
    }
    if (tag) {
        tag.setAttribute('content', value);
    }
}

function setMeta(name, value) {
    if (!name || !value) return;
    if (name === 'title') {
        if (typeof window !== 'undefined' && typeof window.__refreshDynamicDocumentTitle === 'function') {
            window.__refreshDynamicDocumentTitle();
        }
        return;
    }
    if (name.indexOf('og:') === 0) {
        setMetaContent('meta[property="' + name + '"]', value);
        return;
    }
    setMetaContent('meta[name="' + name + '"]', value);
}

function getMetaContent(name) {
    var tag = document.querySelector('meta[name="' + name + '"]');
    if (tag) {
        return tag.getAttribute('content') || '';
    }
    return '';
}

function getMeta(name) {
    if (!name) return '';
    if (name === 'title') {
        return document.title || '';
    }
    if (name.indexOf('og:') === 0) {
        return getMetaProperty(name);
    }
    return getMetaContent(name);
}

function getMetaProperty(prop) {
    var tag = document.querySelector('meta[property="' + prop + '"]');
    if (tag) {
        return tag.getAttribute('content') || '';
    }
    return '';
}

function getEscapedSelectorValue(value) {
    if (!value) return '';
    if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(value);
    }
    return value.replace(/"/g, '\\"');
}

function parseListParam(valor) {
    if (!valor) return [];
    return valor
        .split('|')
        .map(function (item) { return item.trim(); })
        .filter(Boolean);
}

function buildPaginaProdutosContext() {
    var params = new URLSearchParams(window.location.search || '');
    var termo = params.get('termo') || params.get('busca') || '';
    return {
        termos: parseListParam(termo),
        categorias: parseListParam(params.get('categorias')),
        linhas: parseListParam(params.get('linhas')),
        desenhos: parseListParam(params.get('desenhos'))
    };
}

function getProdutoMetadataFromDom() {
    var linha = getCurrentLinha();
    if (!linha) return null;
    var selectorValue = getEscapedSelectorValue(linha);
    var linhaSelector = selectorValue ? '[data-linha="' + selectorValue + '"]' : '';
    var el = (linhaSelector && document.querySelector(linhaSelector)) || document.querySelector('[data-linha]');
    var dataset = el && el.dataset ? el.dataset : {};
    var title = (dataset.tituloPagina || dataset.title || dataset.titulo || '').trim();
    var description = (dataset.conteudoPagina || dataset.descricao || dataset.description || '').trim();
    var categories = parseListParam(dataset.categorias || dataset.categories || '');
    var groups = parseListParam(dataset.linhasGrupo || dataset.groups || '');
    return {
        linha: linha,
        title: title,
        description: description,
        categories: categories,
        groups: groups
    };
}

function buildTitleFromContext(baseTitle) {
    var titleSuffix = 'Configurador de Produtos IBR';
    var pathname = (window.location.pathname || '').toLowerCase();
    var normalizedPath = pathname.replace(/\/+$/, '') || '/';
    var titleFromHeading = '';

    if (typeof window !== 'undefined' && typeof window.__resolveDynamicDocumentTitle === 'function') {
        var resolvedTitle = window.__resolveDynamicDocumentTitle({
            sourceDoc: document,
            pageUrl: window.location.pathname + window.location.search
        });
        if (resolvedTitle) {
            return resolvedTitle;
        }
    }

    function normalizeTitlePart(value) {
        if (!value) return '';
        var output = String(value)
            .replace(/\s+/g, ' ')
            .trim();
        if (!output) return '';

        output = output
            .replace(/\s*\|\s*configurador de produtos ibr$/i, '')
            .replace(/^configurador de produtos ibr\s*\|\s*/i, '')
            .replace(/\s+-\s*configurador de produtos ibr$/i, '')
            .trim();

        if (/^configurador de produtos ibr$/i.test(output)) {
            return '';
        }

        return output;
    }

    function getHeadingText(selector) {
        var headings = document.querySelectorAll(selector);
        for (var i = 0; i < headings.length; i++) {
            var heading = headings[i];
            if (!heading) continue;
            var text = normalizeTitlePart(heading.textContent || heading.innerText || '');
            if (text) {
                return text;
            }
        }
        return '';
    }

    var isHome = normalizedPath === '/' || normalizedPath === '/redutoresibr' || normalizedPath === '/redutoresibr.html';
    if (isHome) {
        return 'Home | ' + titleSuffix;
    }

    var isAreaClienteLogin = normalizedPath === '/areacliente' || normalizedPath === '/paginasareaclienteacessocadastro/acessocadastro.html';
    if (isAreaClienteLogin) {
        return 'Área do Cliente | ' + titleSuffix;
    }

    var isAreaClienteInterna = normalizedPath.indexOf('/areacliente/') === 0
        || normalizedPath === '/paginasareaclientesessaoperfil/arealogada.html';
    if (isAreaClienteInterna) {
        titleFromHeading = getHeadingText('h2');
    }

    if (!titleFromHeading) {
        titleFromHeading = getHeadingText('h1');
    }

    if (!titleFromHeading) {
        titleFromHeading = normalizeTitlePart(baseTitle);
    }

    if (!titleFromHeading) {
        return titleSuffix;
    }

    return titleFromHeading + ' | ' + titleSuffix;
}

function initDynamicSeoTags() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }
    var pathname = window.location.pathname || '';
    var isPaginaProdutos = Boolean(document.querySelector('.pagina-produtos__conteudo'))
        || pathname.indexOf('PaginaProdutos') !== -1
        || pathname === '/' || pathname === '';
    var isConsultaProdutos = pathname.indexOf('PaginasConsultaProdutos') !== -1;

    var baseTitle = 'Configurador de Produtos IBR';
    var mainTitle = document.querySelector('main[data-page-title]');
    if (mainTitle && mainTitle.getAttribute('data-page-title')) {
        baseTitle = mainTitle.getAttribute('data-page-title');
    } else if (document.documentElement && document.documentElement.getAttribute('data-base-title')) {
        baseTitle = document.documentElement.getAttribute('data-base-title');
    } else if (getMeta('title')) {
        baseTitle = getMeta('title');
    } else if (document.title) {
        baseTitle = document.title;
    }
    if (document.documentElement && !document.documentElement.getAttribute('data-base-title')) {
        document.documentElement.setAttribute('data-base-title', baseTitle);
    }

    var baseDescription = '';
    var descriptionTag = document.querySelector('meta[name="description"]');
    if (descriptionTag) {
        baseDescription = descriptionTag.getAttribute('data-base-description') || descriptionTag.getAttribute('content') || '';
        if (!descriptionTag.getAttribute('data-base-description')) {
            descriptionTag.setAttribute('data-base-description', baseDescription);
        }
    }
    if (!baseDescription) {
        baseDescription = getMeta('description') || getMeta('og:description') || '';
    }
    var contexto = {};
    var produtoContext = getProdutoMetadataFromDom();
    if (isPaginaProdutos) {
        contexto = buildPaginaProdutosContext();
    }
    if (produtoContext) {
        if (!contexto.linhas || !contexto.linhas.length) {
            contexto.linhas = [produtoContext.linha];
        }
        if ((!contexto.categorias || !contexto.categorias.length) && produtoContext.categories.length) {
            contexto.categorias = produtoContext.categories.slice(0);
        }
    }
    var pageContext = getPageContextAttributes();

    var seoKey = isPaginaProdutos ? 'pagina-produtos' : (isConsultaProdutos ? 'consulta-produtos' : 'pagina-geral');
    if (pageContext.page_type) {
        seoKey += '-' + pageContext.page_type;
    }
    if (pageContext.content_group) {
        seoKey += '-' + pageContext.content_group;
    }
    var dynamicDescription = seoDescricaoService.obterDescricao({
        key: seoKey,
        base: (produtoContext && produtoContext.description) ? produtoContext.description : baseDescription,
        contexto: contexto,
        maxChars: 170,
        minChars: 70
    });
    var dynamicTitle = buildTitleFromContext(baseTitle);

    if (dynamicTitle) {
        setMeta('title', dynamicTitle);
        setMeta('og:title', dynamicTitle);
        setMeta('twitter:title', dynamicTitle);
    }

    if (dynamicDescription) {
        setMeta('description', dynamicDescription);
        setMeta('og:description', dynamicDescription);
        setMeta('twitter:description', dynamicDescription);
    }

    setMeta('og:url', window.location.href || '');
}

document.addEventListener('DOMContentLoaded', initDynamicSeoTags);
window.addEventListener('spa:navigation', function () {
    setTimeout(initDynamicSeoTags, 0);
});

var jsonLdScriptAttr = 'data-jsonld-key';
var jsonLdObserver = null;
var jsonLdUpdateScheduled = false;

function normalizeJsonLdText(valor) {
    if (!valor) return '';
    return String(valor).replace(/\s+/g, ' ').trim();
}

function getCanonicalUrl() {
    var canonical = document.querySelector('link[rel="canonical"]');
    if (canonical && canonical.getAttribute('href')) {
        return canonical.getAttribute('href');
    }
    return window.location.href || '';
}

function getCanonicalOrigin() {
    if (document.documentElement) {
        var attrOrigin = document.documentElement.getAttribute('data-canonical-origin');
        if (attrOrigin) return attrOrigin.replace(/\/+$/, '');
    }
    return (window.location.origin || (window.location.protocol + '//' + window.location.host)).replace(/\/+$/, '');
}

function toAbsoluteUrl(url) {
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    try {
        return new URL(url, getCanonicalOrigin()).toString();
    } catch (e) {
        return url;
    }
}

function isElementVisible(el) {
    if (!el) return false;
    if (el.offsetParent === null && getComputedStyle(el).display === 'none') {
        return false;
    }
    var style = getComputedStyle(el);
    return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
}

function resolveDatasetFiles(container, folder) {
    if (!container || typeof container.dataset === 'undefined' || typeof container.dataset.files === 'undefined') {
        return [];
    }
    var raw = container.dataset.files;
    var parsed = [];
    if (raw) {
        try {
            parsed = JSON.parse(raw);
        } catch (error) {
            parsed = raw.split(',').map(function (item) { return item.trim(); });
        }
    }
    if (!Array.isArray(parsed)) {
        parsed = parsed ? [parsed] : [];
    }
    var basePath = container.dataset.basePath || container.dataset.basepath || '';
    if (!basePath && folder) {
        basePath = '/ImagensProdutos/' + folder + '/';
    }
    var resolved = parsed
        .map(function (file) {
            if (!file) return '';
            if (typeof file === 'object') {
                file = file.url || file.path || file.file || '';
            }
            if (!file) return '';
            if (/^https?:\/\//i.test(file)) return file;
            if (file.startsWith('/')) return file;
            return basePath + file.replace(/^\/+/, '');
        })
        .filter(Boolean)
        .map(toAbsoluteUrl);
    return Array.from(new Set(resolved));
}

function collectProductImages(container) {
    var images = [];
    if (container) {
        var folder = (container.dataset.folder || '').replace(/\W+/g, '');
        images = resolveDatasetFiles(container, folder);
        if (!images.length) {
            var imgEls = container.querySelectorAll('.galeria__produto img');
            images = Array.from(imgEls)
                .map(function (img) { return img.getAttribute('src') || img.getAttribute('data-src') || ''; })
                .filter(Boolean)
                .map(toAbsoluteUrl);
        }
    }
    if (!images.length) {
        var ogImage = getMetaProperty('og:image') || getMetaContent('twitter:image') || '';
        if (ogImage) {
            images = [toAbsoluteUrl(ogImage)];
        }
    }
    return Array.from(new Set(images));
}

function buildProductJsonLd() {
    var containers = Array.from(document.querySelectorAll('.galeria-produto[data-title]'));
    var visivel = containers.find(isElementVisible) || containers[0] || null;
    var title = normalizeJsonLdText(visivel ? (visivel.dataset.title || '') : '');
    if (!title) {
        title = normalizeJsonLdText(getMetaProperty('og:title') || getMetaContent('twitter:title') || document.title || '');
    }
    if (!title) return null;
    var description = normalizeJsonLdText(
        (visivel && visivel.dataset.description) || getMetaContent('description') || ''
    );
    var sku = normalizeJsonLdText(visivel ? (visivel.dataset.sku || visivel.dataset.visible || visivel.dataset.folder || '') : '');
    var siteUrl = visivel ? (visivel.dataset.site || '') : '';
    var catalogUrl = visivel ? (visivel.dataset.catalog || '') : '';
    var currency = normalizeJsonLdText(visivel ? (visivel.dataset.priceCurrency || visivel.dataset.currency || 'BRL') : 'BRL') || 'BRL';
    var rawPrice = normalizeJsonLdText(visivel ? (visivel.dataset.price || visivel.dataset.preco || '0.00') : '0.00');
    var normalizedPrice = rawPrice.replace(/\./g, '').replace(',', '.');
    var parsedPrice = Number(normalizedPrice);
    var price = Number.isFinite(parsedPrice) ? parsedPrice.toFixed(2) : '0.00';
    var priceValidUntil = normalizeJsonLdText(visivel ? (visivel.dataset.priceValidUntil || '') : '');
    if (!priceValidUntil) {
        var validade = new Date();
        validade.setMonth(validade.getMonth() + 6);
        priceValidUntil = validade.toISOString().slice(0, 10);
    }
    var availability = normalizeJsonLdText(visivel ? (visivel.dataset.availability || '') : '') || 'https://schema.org/InStock';
    var images = collectProductImages(visivel);

    var product = {
        '@context': 'https://schema.org',
        '@type': 'Product',
        name: title,
        description: description,
        image: images,
        brand: {
            '@type': 'Brand',
            name: 'Redutores IBR'
        },
        sku: sku,
        url: toAbsoluteUrl(siteUrl || getCanonicalUrl())
    };

    product.offers = {
        '@type': 'Offer',
        url: toAbsoluteUrl(catalogUrl || siteUrl || getCanonicalUrl()),
        priceCurrency: currency,
        price: price,
        priceValidUntil: priceValidUntil,
        availability: availability,
        shippingDetails: {
            '@type': 'OfferShippingDetails',
            shippingRate: {
                '@type': 'MonetaryAmount',
                value: '0',
                currency: currency
            },
            deliveryTime: {
                '@type': 'ShippingDeliveryTime',
                handlingTime: {
                    '@type': 'QuantitativeValue',
                    minValue: 0,
                    maxValue: 1,
                    unitCode: 'DAY'
                },
                transitTime: {
                    '@type': 'QuantitativeValue',
                    minValue: 1,
                    maxValue: 10,
                    unitCode: 'DAY'
                }
            },
            shippingDestination: {
                '@type': 'DefinedRegion',
                addressCountry: 'BR'
            }
        },
        hasMerchantReturnPolicy: {
            '@type': 'MerchantReturnPolicy',
            returnPolicyCategory: 'https://schema.org/MerchantReturnFiniteReturnWindow',
            merchantReturnDays: 30,
            applicableCountry: 'BR',
            returnMethod: 'https://schema.org/ReturnByMail',
            returnFees: 'https://schema.org/FreeReturn'
        }
    };

    product.aggregateRating = {
        '@type': 'AggregateRating',
        ratingValue: '5',
        reviewCount: '1'
    };
    product.review = {
        '@type': 'Review',
        reviewRating: {
            '@type': 'Rating',
            ratingValue: '5',
            bestRating: '5'
        },
        author: {
            '@type': 'Organization',
            name: 'Redutores IBR'
        },
        reviewBody: 'Produto industrial com configuração personalizada sob consulta técnica.'
    };

    return product;
}

function buildBreadcrumbJsonLd(contexto) {
    var origin = getCanonicalOrigin();
    var canonicalUrl = getCanonicalUrl();
    var items = [];
    items.push({
        '@type': 'ListItem',
        position: 1,
        name: 'Home',
        item: origin + '/'
    });

    if (contexto && contexto.tipo === 'produto') {
        items.push({
            '@type': 'ListItem',
            position: 2,
            name: 'Produtos',
            item: origin + '/PaginasPrincipal/PaginaProdutos.html'
        });
        if (contexto.nome) {
            items.push({
                '@type': 'ListItem',
                position: 3,
                name: contexto.nome,
                item: toAbsoluteUrl(contexto.url || canonicalUrl)
            });
        }
    } else if (contexto && contexto.tipo === 'categoria') {
        items.push({
            '@type': 'ListItem',
            position: 2,
            name: contexto.nome || 'Produtos',
            item: toAbsoluteUrl(canonicalUrl)
        });
    }

    if (!items.length) return null;
    return {
        '@context': 'https://schema.org',
        '@type': 'BreadcrumbList',
        itemListElement: items
    };
}

function buildOrganizationJsonLd() {
    return {
        '@context': 'https://schema.org',
        '@type': 'Organization',
        name: 'Redutores IBR',
        url: 'https://configurador.redutoresibr.com.br',
        logo: 'https://configurador.redutoresibr.com.br/Imagens/LogotipoAplicativo512512.png',
        contactPoint: [
            {
                '@type': 'ContactPoint',
                telephone: '+55-54-3028-9200',
                contactType: 'customer support',
                areaServed: 'BR',
                availableLanguage: 'Portuguese'
            },
            {
                '@type': 'ContactPoint',
                telephone: '+55-19-3014-8604',
                contactType: 'customer support',
                areaServed: 'BR',
                availableLanguage: 'Portuguese'
            }
        ]
    };
}

function upsertJsonLd(key, payload) {
    if (!payload) return;
    var head = document.head || document.getElementsByTagName('head')[0];
    if (!head) return;
    var selector = 'script[type="application/ld+json"][' + jsonLdScriptAttr + '="' + key + '"]';
    var script = head.querySelector(selector);
    if (!script) {
        script = document.createElement('script');
        script.type = 'application/ld+json';
        script.setAttribute(jsonLdScriptAttr, key);
        head.appendChild(script);
    }
    script.textContent = JSON.stringify(payload);
}

function removeJsonLd(key) {
    var head = document.head || document.getElementsByTagName('head')[0];
    if (!head) return;
    var selector = 'script[type="application/ld+json"][' + jsonLdScriptAttr + '="' + key + '"]';
    var script = head.querySelector(selector);
    if (script && script.parentNode) {
        script.parentNode.removeChild(script);
    }
}

function getCategoriaBreadcrumbLabel() {
    var params = new URLSearchParams(window.location.search || '');
    var categorias = parseListParam(params.get('categorias'));
    var linhas = parseListParam(params.get('linhas'));
    var termos = parseListParam(params.get('termo') || params.get('busca'));
    var labels = [];
    if (categorias.length) labels.push(categorias.join(', '));
    if (linhas.length) labels.push(linhas.join(', '));
    if (termos.length) labels.push('Busca: ' + termos.join(', '));
    if (!labels.length) {
        var chipTexts = Array.from(document.querySelectorAll('.pagina-produtos__chip-text'))
            .map(function (el) { return normalizeJsonLdText(el.textContent || ''); })
            .filter(Boolean);
        if (chipTexts.length) {
            labels.push(chipTexts.join(', '));
        }
    }
    return labels.join(' · ');
}

function updateDynamicJsonLd() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }
    var pathname = (window.location.pathname || '').toLowerCase();
    var isPaginaProdutos = Boolean(document.querySelector('.pagina-produtos__conteudo'))
        || pathname.indexOf('paginaprodutos') !== -1
        || pathname === '/' || pathname === '';
    var hasGaleriaProduto = Boolean(document.querySelector('.galeria-produto[data-title]'));
    var isConfigurador = pathname.indexOf('configuradoribr') !== -1;
    var isProduto = hasGaleriaProduto || isConfigurador;
    var isCategoria = isPaginaProdutos && !isProduto;
    var isInstitucional = !isProduto && !isCategoria
        && (pathname.indexOf('politica') !== -1 || pathname.indexOf('institucional') !== -1);

    var productJsonLd = isProduto ? buildProductJsonLd() : null;
    if (productJsonLd) {
        upsertJsonLd('product', productJsonLd);
    } else {
        removeJsonLd('product');
    }

    var breadcrumbContext = null;
    if (isProduto) {
        breadcrumbContext = {
            tipo: 'produto',
            nome: productJsonLd ? productJsonLd.name : '',
            url: productJsonLd ? productJsonLd.url : ''
        };
    } else if (isCategoria) {
        breadcrumbContext = {
            tipo: 'categoria',
            nome: getCategoriaBreadcrumbLabel() || 'Produtos'
        };
    }
    var breadcrumbJsonLd = breadcrumbContext ? buildBreadcrumbJsonLd(breadcrumbContext) : null;
    if (breadcrumbJsonLd) {
        upsertJsonLd('breadcrumb', breadcrumbJsonLd);
    } else {
        removeJsonLd('breadcrumb');
    }

    if (isInstitucional) {
        upsertJsonLd('organization', buildOrganizationJsonLd());
    } else {
        removeJsonLd('organization');
    }
}

function scheduleJsonLdUpdate() {
    if (jsonLdUpdateScheduled) return;
    jsonLdUpdateScheduled = true;
    setTimeout(function () {
        jsonLdUpdateScheduled = false;
        updateDynamicJsonLd();
    }, 0);
}

function initJsonLdObserver() {
    if (jsonLdObserver || typeof MutationObserver === 'undefined') return;
    var target = document.body || document.documentElement;
    if (!target) return;
    jsonLdObserver = new MutationObserver(function () {
        scheduleJsonLdUpdate();
    });
    jsonLdObserver.observe(target, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style', 'class', 'data-title', 'data-description', 'data-visible', 'data-folder', 'data-site', 'data-catalog']
    });
}

function initDynamicJsonLdTags() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }
    updateDynamicJsonLd();
    initJsonLdObserver();
}

document.addEventListener('DOMContentLoaded', initDynamicJsonLdTags);
window.addEventListener('spa:navigation', function () {
    setTimeout(initDynamicJsonLdTags, 0);
});

var seoNavigationTrackingInitialized = false;

function setupSeoHistoryTracking() {
    if (seoNavigationTrackingInitialized) return;
    seoNavigationTrackingInitialized = true;
    if (!window.history) return;
    var originalPushState = history.pushState;
    var originalReplaceState = history.replaceState;

    function scheduleSeoUpdate() {
        setTimeout(initDynamicSeoTags, 0);
    }

    if (typeof originalPushState === 'function') {
        history.pushState = function () {
            var result = originalPushState.apply(this, arguments);
            scheduleSeoUpdate();
            return result;
        };
    }

    if (typeof originalReplaceState === 'function') {
        history.replaceState = function () {
            var result = originalReplaceState.apply(this, arguments);
            scheduleSeoUpdate();
            return result;
        };
    }

    window.addEventListener('popstate', scheduleSeoUpdate);
    window.addEventListener('hashchange', scheduleSeoUpdate);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupSeoHistoryTracking, { once: true });
} else {
    setupSeoHistoryTracking();
}
