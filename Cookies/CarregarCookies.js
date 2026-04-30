(function initLayoutLoaderModule() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    const loaderElement = document.getElementById('loader');
    if (!loaderElement) {
        return;
    }

    const IS_AREA_CLIENTE_PATH = /^\/(Paginas\/)?AreaCliente/.test(window.location.pathname);

    const DOM_READY_GUARD_TIME = 250;
    const KEEP_LOADING_RELEASE_DELAY = 350;
    const INITIAL_CONTENT_READY_EVENT = 'page-shell-ready';
    const INITIAL_CONTENT_FALLBACK_DELAY = 5000;
    const INITIAL_LOADER_HIDDEN_EVENT = 'initial-loader-hidden';
    const INITIAL_LOADER_COOKIE_READY_EVENT = 'initial-loader-cookie-ui-ready';
    const COOKIE_UI_READY_MAX_WAIT = 10000;
    const COOKIE_UI_FALLBACK_DELAY = 1500;
    let loaderStartTime = performance.now();
    let loaderHideTimeout;
    let loaderHiddenNotified = false;
    let shouldWaitForCookieUI = false;
    let cookieUIReady = false;
    let cookieUIReadinessPending = false;
    let pendingLoaderHideOptions = null;
    let cookieUIFallbackTimer = null;
    let domContentLoaded = document.readyState !== 'loading';
    let initialContentReadyHandled = false;
    let initialContentFallbackTimer = null;
    let keepLoadingRecentlyCleared = false;
    let consentAreaWaitHandle = null;
    let consentAreaWaitUsingAnimationFrame = false;

    function ensureCookieUIFallbackReady() {
        if (typeof document === 'undefined') {
            return Promise.resolve();
        }

        if (isLgpdCookiesDisabled() || IS_AREA_CLIENTE_PATH) {
            return Promise.resolve();
        }

        const lgpd = typeof window !== 'undefined' ? window.LGPDCOOKIES : undefined;
        if (!lgpd || typeof lgpd.loadTemplate !== 'function' || typeof lgpd.writeContent !== 'function') {
            return Promise.resolve();
        }

        const footer = document.getElementById('lgpdCookieFooter');
        const shield = document.getElementById('lgpdShieldFooter');
        if (footer || shield) {
            return Promise.resolve();
        }

        let lastUpdate = '';
        if (typeof lgpd.getValueCookie === 'function') {
            try {
                lastUpdate = lgpd.getValueCookie('lgpd-cookies-last-update-date') || '';
            } catch (error) {
                console.warn('LGPD cookies: falha ao obter data de atualização do fallback', error);
            }
        }

        return Promise.resolve(lgpd.loadTemplate()).then(template => {
            if (!template || !template.trim()) {
                return;
            }

            if (document.getElementById('lgpdCookieFooter') || document.getElementById('lgpdShieldFooter')) {
                return;
            }

            return lgpd.writeContent(template, lastUpdate || '');
        }).catch(error => {
            console.warn('LGPD cookies: falha ao renderizar fallback do banner', error);
        });
    }

    function isLgpdCookiesDisabled() {
        if (typeof window === 'undefined' || typeof document === 'undefined') {
            return true;
        }

        if (window.disableLgpdCookies === true || window.LGPD_DISABLE === true) {
            return true;
        }

        const docEl = document.documentElement;
        if (docEl) {
            if (docEl.classList && docEl.classList.contains('lgpd-disabled')) {
                return true;
            }
            if (docEl.dataset && docEl.dataset.lgpdDisabled === 'true') {
                return true;
            }
        }

        const body = document.body;
        if (body) {
            if (body.classList && body.classList.contains('lgpd-disabled')) {
                return true;
            }
            if (body.dataset && body.dataset.lgpdDisabled === 'true') {
                return true;
            }
        }

        const keyEl = document.getElementById('lgpd-key');
        const remoteFlag = typeof window.__LGPD_ENABLE_REMOTE_ENDPOINTS === 'boolean'
            ? window.__LGPD_ENABLE_REMOTE_ENDPOINTS
            : Boolean(keyEl && (keyEl.getAttribute('data-lgpd-remote-endpoints') || '').toLowerCase() === 'true');

        if (!keyEl && !remoteFlag) {
            return true;
        }

        if (keyEl && (keyEl.getAttribute('data-disable-lgpd') === 'true' || keyEl.hasAttribute('data-disable-lgpd'))) {
            return true;
        }

        return false;
    }

    function hasCookieLoaderWaitOptIn() {
        if (typeof window !== 'undefined') {
            const flagValues = [
                window.initialLoaderCookieWait,
                window.enableInitialLoaderCookieWait,
                window.INITIAL_LOADER_COOKIE_WAIT
            ];
            if (flagValues.some(value => value === true)) {
                return true;
            }
        }

        if (typeof document !== 'undefined') {
            const candidates = [document.documentElement, document.body].filter(Boolean);
            const normalizedValues = candidates
                .map(element => {
                    if (!element) {
                        return '';
                    }
                    const directAttr = element.getAttribute('data-initial-loader-cookie-wait');
                    if (typeof directAttr === 'string' && directAttr.trim()) {
                        return directAttr.trim();
                    }
                    if (element.dataset && typeof element.dataset.initialLoaderCookieWait === 'string') {
                        return element.dataset.initialLoaderCookieWait.trim();
                    }
                    return '';
                })
                .map(value => value.toLowerCase())
                .filter(Boolean);

            if (normalizedValues.some(value => value === 'true' || value === '1' || value === 'yes')) {
                return true;
            }
        }

        return false;
    }

    function isCookieElementVisible(element) {
        if (!element) {
            return false;
        }

        const ariaHiddenValue = element.getAttribute ? element.getAttribute('aria-hidden') : null;
        if (ariaHiddenValue === 'true') {
            return false;
        }

        if (element.hasAttribute && element.hasAttribute('hidden') && element.getAttribute('hidden') !== 'false') {
            return false;
        }

        let style = null;
        if (typeof window !== 'undefined' && window.getComputedStyle) {
            style = window.getComputedStyle(element);
        } else if (element.currentStyle) {
            style = element.currentStyle;
        }

        let skipVisibilityOpacityChecks = false;
        if (typeof document !== 'undefined') {
            const html = document.documentElement;
            if (html) {
                const htmlIsLoading = Boolean(
                    (html.classList && html.classList.contains('is-initial-loading')) ||
                    (html.dataset && html.dataset.loading === 'true') ||
                    (html.getAttribute && html.getAttribute('data-loading') === 'true')
                );
                if (htmlIsLoading) {
                    const displayValue = style && style.display
                        ? style.display
                        : (element.style && element.style.display);
                    if (ariaHiddenValue === 'false' && displayValue && displayValue !== 'none') {
                        skipVisibilityOpacityChecks = true;
                    }
                }
            }
        }

        if (!skipVisibilityOpacityChecks) {
            if (style) {
                if (style.display === 'none' || style.visibility === 'hidden') {
                    return false;
                }
                const opacity = parseFloat(style.opacity);
                if (!Number.isNaN(opacity) && opacity === 0) {
                    return false;
                }
            } else if (element.style && element.style.display === 'none') {
                return false;
            }
        }

        if (typeof element.getClientRects === 'function') {
            return element.getClientRects().length > 0;
        }

        return Boolean(element.offsetWidth || element.offsetHeight);
    }

    function getCookieConsentElements() {
        if (typeof document === 'undefined') {
            return { bannerElements: [], consentAreas: [] };
        }

        const ids = ['lgpdCookieFooter', 'lgpdShieldFooter', 'lgpdManageLinkBanner'];
        const bannerElements = ids
            .map(id => document.getElementById(id))
            .filter(Boolean);

        const consentAreas = Array.from(document.querySelectorAll('[data-lgpd-consent-area]'))
            .filter(element => element.getAttribute('data-loader-blocking') !== 'false');

        return { bannerElements, consentAreas };
    }

    function shouldDeferLoaderForCookieConsent() {
        if (!shouldWaitForCookieUI) {
            return false;
        }

        const { consentAreas } = getCookieConsentElements();
        if (!consentAreas.length) {
            return false;
        }

        return consentAreas.some(element => !isCookieElementVisible(element));
    }

    function stopConsentAreaWait() {
        if (!consentAreaWaitHandle) {
            return;
        }

        if (consentAreaWaitUsingAnimationFrame && typeof cancelAnimationFrame === 'function') {
            cancelAnimationFrame(consentAreaWaitHandle);
        } else {
            clearTimeout(consentAreaWaitHandle);
        }

        consentAreaWaitHandle = null;
        consentAreaWaitUsingAnimationFrame = false;
    }

    function ensureConsentAreaWait() {
        if (consentAreaWaitHandle) {
            return;
        }

        const scheduleNextCheck = (callback) => {
            if (typeof requestAnimationFrame === 'function') {
                consentAreaWaitUsingAnimationFrame = true;
                consentAreaWaitHandle = requestAnimationFrame(callback);
            } else {
                consentAreaWaitUsingAnimationFrame = false;
                consentAreaWaitHandle = setTimeout(callback, 100);
            }
        };

        const checkConsentAreas = () => {
            if (!shouldDeferLoaderForCookieConsent()) {
                stopConsentAreaWait();
                const optionsToUse = pendingLoaderHideOptions || { allowDelays: true };
                pendingLoaderHideOptions = null;
                scheduleLoaderHide(optionsToUse);
                return;
            }

            scheduleNextCheck(checkConsentAreas);
        };

        scheduleNextCheck(checkConsentAreas);
    }

    function isCookieUIVisuallyReady() {
        if (typeof document === 'undefined') {
            return true;
        }

        if (isLgpdCookiesDisabled()) {
            return true;
        }

        if (IS_AREA_CLIENTE_PATH) {
            return true;
        }

        const footer = document.getElementById('lgpdCookieFooter');
        const shield = document.getElementById('lgpdShieldFooter');
        const manageBanner = document.getElementById('lgpdManageLinkBanner');

        const primaryVisible = [footer, shield].some(isCookieElementVisible);
        if (!primaryVisible) {
            return false;
        }

        const shouldValidateManageBanner = Boolean(
            manageBanner && manageBanner.classList && manageBanner.classList.contains('is-visible')
        );

        if (shouldValidateManageBanner) {
            return isCookieElementVisible(manageBanner);
        }

        return true;
    }

    function waitForCookieUIReady(maxWait = COOKIE_UI_READY_MAX_WAIT) {
        if (isCookieUIVisuallyReady()) {
            return Promise.resolve();
        }

        const start = typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now();

        return new Promise(resolve => {
            const check = () => {
                if (isCookieUIVisuallyReady()) {
                    resolve();
                    return;
                }

                const elapsed = (typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now()) - start;
                if (elapsed >= maxWait) {
                    resolve();
                    return;
                }

                requestAnimationFrame(check);
            };

            check();
        });
    }

    function markCookieUIReady() {
        if (cookieUIReadinessPending) {
            return;
        }

        cookieUIReadinessPending = true;

        waitForCookieUIReady().then(() => {
            cookieUIReady = true;
            cookieUIReadinessPending = false;
            window.dispatchEvent(new CustomEvent(INITIAL_LOADER_COOKIE_READY_EVENT));
        }).catch(() => {
            cookieUIReady = true;
            cookieUIReadinessPending = false;
            window.dispatchEvent(new CustomEvent(INITIAL_LOADER_COOKIE_READY_EVENT));
        });
    }

    function handleCookieUILoadedEvent() {
        if (cookieUIReady) {
            return;
        }

        cookieUIReady = true;
        window.dispatchEvent(new CustomEvent(INITIAL_LOADER_COOKIE_READY_EVENT));
    }

    if (typeof window !== 'undefined') {
        window.addEventListener('lgpdCookieUIReady', handleCookieUILoadedEvent);
        window.addEventListener('lgpdCookieUIDisplayed', handleCookieUILoadedEvent);
        window.addEventListener('lgpdCookieUIRendered', handleCookieUILoadedEvent);
    }

    function dispatchLoaderHiddenEvent() {
        if (loaderHiddenNotified) {
            return;
        }

        loaderHiddenNotified = true;
        window.dispatchEvent(new CustomEvent(INITIAL_LOADER_HIDDEN_EVENT));
    }

    function hideInitialLoaderElements() {
        const loader = document.getElementById('loader');
        if (loader) {
            loader.style.opacity = '0';
            loader.style.visibility = 'hidden';
            loader.style.pointerEvents = 'none';
        }


        const html = document.documentElement;
        if (html) {
            const previousBg = html.getAttribute('data-initial-loader-bg');
            const previousAria = html.getAttribute('data-initial-loader-aria');
            if (previousBg != null) {
                html.style.backgroundColor = previousBg;
            } else {
                html.style.removeProperty('background-color');
            }
            if (previousAria != null) {
                html.setAttribute('aria-busy', previousAria || 'false');
            } else {
                html.removeAttribute('aria-busy');
            }
            html.removeAttribute('data-loading');
            html.classList.remove('is-initial-loading');
        }
    }

    function performLoaderHide() {
        hideInitialLoaderElements();
        dispatchLoaderHiddenEvent();
        keepLoadingRecentlyCleared = false;
    }

    function scheduleLoaderHide(options = true) {
        const normalizedOptions = typeof options === 'object'
            ? options
            : { allowDelays: options };
        const allowDelays = normalizedOptions.allowDelays !== false;
        const mustWaitForConsentArea = shouldDeferLoaderForCookieConsent();

        if (mustWaitForConsentArea) {
            pendingLoaderHideOptions = { ...normalizedOptions };
            ensureConsentAreaWait();
            return;
        }

        stopConsentAreaWait();
        pendingLoaderHideOptions = null;

        if (loaderHideTimeout) {
            clearTimeout(loaderHideTimeout);
            loaderHideTimeout = undefined;
        }

        const shouldGuardDomReady = allowDelays && !domContentLoaded;
        if (shouldGuardDomReady) {
            const elapsed = performance.now() - loaderStartTime;
            const delay = Math.max(DOM_READY_GUARD_TIME - elapsed, 0);

            if (delay > 0) {
                loaderHideTimeout = setTimeout(() => {
                    if (!Boolean(window.keepLoading)) {
                        performLoaderHide();
                    }
                }, delay);
                return;
            }
        }

        if (allowDelays && keepLoadingRecentlyCleared) {
            loaderHideTimeout = setTimeout(() => {
                keepLoadingRecentlyCleared = false;
                if (!Boolean(window.keepLoading)) {
                    performLoaderHide();
                }
            }, KEEP_LOADING_RELEASE_DELAY);
            return;
        }

        performLoaderHide();
    }

    window.hideLoadingScreen = function (forceImmediate = false) {
        if (forceImmediate) {
            scheduleLoaderHide(false);
            return;
        }

        if (Boolean(window.keepLoading)) {
            return;
        }

        scheduleLoaderHide(true);
    };

    function requestLoaderHide() {
        if (Boolean(window.keepLoading)) {
            return;
        }

        scheduleLoaderHide(true);
    }

    if (typeof window.requestLoaderHide !== 'function') {
        window.requestLoaderHide = requestLoaderHide;
    }

    function handleInitialContentReady() {
        if (initialContentReadyHandled) {
            domContentLoaded = true;
            return;
        }

        initialContentReadyHandled = true;
        domContentLoaded = true;

        if (initialContentFallbackTimer) {
            clearTimeout(initialContentFallbackTimer);
            initialContentFallbackTimer = null;
        }

        if (!Boolean(window.keepLoading) && typeof window.hideLoadingScreen === 'function') {
            window.hideLoadingScreen(true);
        } else {
            requestLoaderHide();
        }
    }

    function setupInitialContentReadyListeners() {
        const onceOptions = { once: true };
        document.addEventListener(INITIAL_CONTENT_READY_EVENT, handleInitialContentReady, onceOptions);
        document.addEventListener('DOMContentLoaded', handleInitialContentReady, onceOptions);

        if (document.readyState !== 'loading') {
            handleInitialContentReady();
        }

        initialContentFallbackTimer = setTimeout(handleInitialContentReady, INITIAL_CONTENT_FALLBACK_DELAY);
    }

    setupInitialContentReadyListeners();

    let keepLoadingInternal = typeof window.keepLoading === 'undefined' ? false : Boolean(window.keepLoading);

    if (keepLoadingInternal) {
        loaderStartTime = performance.now();
    }

    Object.defineProperty(window, 'keepLoading', {
        configurable: true,
        enumerable: true,
        get() {
            return keepLoadingInternal;
        },
        set(value) {
            const nextValue = Boolean(value);
            if (keepLoadingInternal === nextValue) {
                return;
            }

            keepLoadingInternal = nextValue;

            if (nextValue) {
                keepLoadingRecentlyCleared = false;
                loaderStartTime = performance.now();
                if (loaderHideTimeout) {
                    clearTimeout(loaderHideTimeout);
                    loaderHideTimeout = undefined;
                }

                const loader = document.getElementById('loader');
                if (loader) {
                    loader.style.removeProperty('opacity');
                    loader.style.removeProperty('visibility');
                    loader.style.removeProperty('pointer-events');
                }

                const root = document.documentElement;
                if (root) {
                    if (!root.hasAttribute('data-initial-loader-bg')) {
                        root.setAttribute('data-initial-loader-bg', root.style.backgroundColor || '');
                    }
                    if (!root.hasAttribute('data-initial-loader-aria')) {
                        const previousAriaBusy = root.getAttribute('aria-busy');
                        root.setAttribute('data-initial-loader-aria', previousAriaBusy == null ? '' : previousAriaBusy);
                    }
                    root.style.backgroundColor = '#ec4115';
                    root.classList.add('is-initial-loading');
                    root.setAttribute('data-loading', 'true');
                    root.setAttribute('aria-busy', 'true');
                }
            } else {
                keepLoadingRecentlyCleared = true;
                scheduleLoaderHide(true);
            }
        }
    });

    const initialConsentElements = getCookieConsentElements();
    const hasBlockingConsentAreas = initialConsentElements.consentAreas.length > 0;
    const cookieWaitOptIn = hasCookieLoaderWaitOptIn() || hasBlockingConsentAreas;
    shouldWaitForCookieUI = cookieWaitOptIn && !isLgpdCookiesDisabled() && !IS_AREA_CLIENTE_PATH;

    if (!shouldWaitForCookieUI && typeof window.signalInitialLoaderCookiesReady === 'function') {
        window.signalInitialLoaderCookiesReady();
    }

    if (!shouldWaitForCookieUI) {
        window.addEventListener('lgpdCookieUILoaded', handleCookieUILoadedEvent, { once: true });
    } else {
        cookieUIFallbackTimer = setTimeout(() => {
            if (!cookieUIReady) {
                handleCookieUILoadedEvent();
            }
        }, COOKIE_UI_FALLBACK_DELAY);

        ensureCookieUIFallbackReady().then(() => {
            markCookieUIReady();
        });
    }
})();