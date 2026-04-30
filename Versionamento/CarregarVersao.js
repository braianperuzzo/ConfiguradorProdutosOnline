(function () {
    if (typeof window === 'undefined') {
        return;
    }

    const VERSION_CONFIG_URL = '/Versionamento/Versao.json';
    const FALLBACK_VERSION = 'versao.desconhecida';
    const processed = new WeakSet();
    let resolvedAppVersion = '';
    let appVersion = '';
    let resolvingPromise = null;
    let processingInitialized = false;
    let resolveVersionedAssetsReady = null;
    const versionedAssetsReadyPromise = new Promise(resolve => {
        resolveVersionedAssetsReady = resolve;
    });
    let versionedAssetsReadyResolved = false;

    function markVersionedAssetsReady() {
        if (versionedAssetsReadyResolved) {
            return;
        }
        versionedAssetsReadyResolved = true;
        resolveVersionedAssetsReady();
        window.dispatchEvent(new Event('versionedassetsready'));
    }

    window.__VERSIONED_ASSETS_READY__ = versionedAssetsReadyPromise;
    function safeTrim(value) {
        return typeof value === 'string' ? value.trim() : '';
    }

    function extractVersion(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }
        const direct = safeTrim(payload.version);
        if (direct) {
            return direct;
        }
        const legacy = safeTrim(payload.appVersion);
        if (legacy) {
            return legacy;
        }
        return null;
    }

    function normalizeVersion(value) {
        const trimmed = safeTrim(value);
        return trimmed ? trimmed : '';
    }

    function ensureGetAppVersion() {
        if (typeof window.getAppVersion !== 'function') {
            window.getAppVersion = function getAppVersion() {
                return window.APP_VERSION;
            };
        }
    }

    function adoptEmbeddedAppVersion(existingValue, sourceValue) {
        const existing = normalizeVersion(existingValue);
        if (!existing) {
            return false;
        }

        const source = safeTrim(sourceValue);
        if (source === 'fallback' || source === 'fallback-pending') {
            return false;
        }

        appVersion = existing;
        resolvedAppVersion = existing;
        window.APP_VERSION = existing;
        window.__APP_VERSION_SOURCE__ = source || 'embedded';
        ensureGetAppVersion();
        return true;
    }

    function ensureInitialAppVersion() {
        if (adoptEmbeddedAppVersion(window.APP_VERSION, window.__APP_VERSION_SOURCE__)) {
            return true;
        }

        resolvedAppVersion = '';
        const existing = normalizeVersion(window.APP_VERSION);
        if (existing) {
            appVersion = existing;
            window.APP_VERSION = existing;
        } else {
            appVersion = FALLBACK_VERSION;
            window.APP_VERSION = appVersion;
        }
        window.__APP_VERSION_SOURCE__ = 'fallback-pending';
        ensureGetAppVersion();
        return false;
    }

    function resolveVersionFromConfigAsync() {
        if (resolvingPromise) {
            return resolvingPromise;
        }

        const useFetch = typeof window.fetch === 'function';
        if (useFetch) {
            resolvingPromise = window.fetch(VERSION_CONFIG_URL, {
                headers: { Accept: 'application/json' },
                cache: 'no-cache'
            })
                .then(response => {
                    if (!response.ok) {
                        console.warn('VersaoLoader: failed to load version config.', response.status, response.statusText);
                        return null;
                    }
                    return response.text();
                })
                .then(text => {
                    if (!text) {
                        return null;
                    }
                    try {
                        const parsed = JSON.parse(text);
                        return extractVersion(parsed);
                    } catch (error) {
                        console.warn('VersaoLoader: invalid JSON from version config.', error);
                        return null;
                    }
                })
                .catch(error => {
                    console.warn('VersaoLoader: error while loading version config.', error);
                    return null;
                });
        } else if (typeof window.XMLHttpRequest === 'function') {
            resolvingPromise = new Promise(resolve => {
                try {
                    const request = new XMLHttpRequest();
                    request.open('GET', VERSION_CONFIG_URL, true);
                    request.setRequestHeader('Accept', 'application/json');
                    request.onreadystatechange = function handleReadyStateChange() {
                        if (request.readyState !== 4) {
                            return;
                        }
                        if (request.status >= 200 && request.status < 300) {
                            const responseText = request.responseText;
                            if (responseText) {
                                try {
                                    const parsed = JSON.parse(responseText);
                                    resolve(extractVersion(parsed));
                                    return;
                                } catch (error) {
                                    console.warn('VersaoLoader: invalid JSON from version config.', error);
                                }
                            }
                            resolve(null);
                        } else {
                            console.warn('VersaoLoader: failed to load version config.', request.status, request.statusText);
                            resolve(null);
                        }
                    };
                    request.onerror = function handleRequestError(event) {
                        console.warn('VersaoLoader: error while loading version config.', event);
                        resolve(null);
                    };
                    request.send(null);
                } catch (error) {
                    console.warn('VersaoLoader: error while initializing version request.', error);
                    resolve(null);
                }
            });
        } else {
            resolvingPromise = Promise.resolve(null);
        }

        resolvingPromise.then(
            () => {
                resolvingPromise = null;
            },
            () => {
                resolvingPromise = null;
            }
        );

        return resolvingPromise;
    }

    function updateAppVersionFromConfig(versionCandidate) {
        const normalized = normalizeVersion(versionCandidate);
        if (normalized) {
            resolvedAppVersion = normalized;
            appVersion = normalized;
            window.APP_VERSION = normalized;
            window.__APP_VERSION_SOURCE__ = 'versao.json';
        } else {
            resolvedAppVersion = '';
            appVersion = FALLBACK_VERSION;
            window.APP_VERSION = appVersion;
            window.__APP_VERSION_SOURCE__ = 'fallback';
        }
        ensureGetAppVersion();
    }

    window.resolveAppVersionFromConfigSync = function resolveAppVersionFromConfigSync() {
        return normalizeVersion(resolvedAppVersion);
    };

    function buildVersionedUrl(baseUrl) {
        const trimmed = safeTrim(baseUrl);
        if (!trimmed) {
            return '';
        }
        if (trimmed.includes('versao=')) {
            return trimmed;
        }
        const hashIndex = trimmed.indexOf('#');
        const hash = hashIndex >= 0 ? trimmed.slice(hashIndex) : '';
        const withoutHash = hashIndex >= 0 ? trimmed.slice(0, hashIndex) : trimmed;
        const separator = withoutHash.includes('?') ? '&' : '?';
        return `${withoutHash}${separator}versao=${encodeURIComponent(appVersion)}${hash}`;
    }

    window.buildVersionedAssetUrl = function buildVersionedAssetUrl(inputUrl) {
        return buildVersionedUrl(inputUrl);
    };

    function applyVersionToLink(link) {
        if (processed.has(link)) {
            return;
        }
        const dataHref = safeTrim(link.getAttribute('data-versioned-href'));
        if (!dataHref) {
            return;
        }
        link.setAttribute('href', buildVersionedUrl(dataHref));
        link.removeAttribute('data-versioned-href');
        processed.add(link);
    }

    function cloneAttributes(source, target, skipAttribute) {
        const attributes = source.attributes;
        for (let i = 0; i < attributes.length; i++) {
            const attr = attributes[i];
            if (attr && attr.name !== skipAttribute) {
                if (attr.value === '' && (attr.name === 'defer' || attr.name === 'async' || attr.name === 'nomodule')) {
                    target.setAttribute(attr.name, '');
                } else {
                    target.setAttribute(attr.name, attr.value);
                }
            }
        }
    }

    function applyVersionToScriptPlaceholder(placeholder) {
        if (processed.has(placeholder)) {
            return;
        }

        const dataSrc = safeTrim(placeholder.getAttribute('data-versioned-src'));
        const existingSrc = safeTrim(placeholder.getAttribute('src'));
        const baseSrc = dataSrc || existingSrc;

        if (!baseSrc) {
            placeholder.remove();
            return;
        }

        const versionedSrc = buildVersionedUrl(baseSrc);

        if (existingSrc) {
            if (versionedSrc && existingSrc !== versionedSrc) {
                placeholder.setAttribute('src', versionedSrc);
            }
            if (dataSrc) {
                placeholder.removeAttribute('data-versioned-src');
            }
            processed.add(placeholder);
            return;
        }

        const parent = placeholder.parentNode;
        if (!parent) {
            return;
        }

        const script = document.createElement('script');
        cloneAttributes(placeholder, script, 'data-versioned-src');
        script.setAttribute('src', versionedSrc);
        parent.insertBefore(script, placeholder);
        placeholder.remove();
        processed.add(script);
    }

    function processNode(node) {
        if (!node || node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }
        if (node.hasAttribute('data-versioned-href')) {
            applyVersionToLink(node);
        }
        if (node.tagName === 'SCRIPT' && node.hasAttribute('data-versioned-src')) {
            applyVersionToScriptPlaceholder(node);
        }
        const children = node.querySelectorAll('[data-versioned-href], script[data-versioned-src]');
        children.forEach(child => {
            if (child.tagName === 'SCRIPT' && child.hasAttribute('data-versioned-src')) {
                applyVersionToScriptPlaceholder(child);
            } else {
                applyVersionToLink(child);
            }
        });
    }

    function startProcessing() {
        if (processingInitialized) {
            return;
        }
        processingInitialized = true;
        processNode(document.documentElement);
        markVersionedAssetsReady();
        const observer = new MutationObserver(mutations => {
            for (const mutation of mutations) {
                mutation.addedNodes.forEach(appended => {
                    processNode(appended);
                });
            }
        });
        observer.observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }

    if (!ensureInitialAppVersion()) {
        resolveVersionFromConfigAsync()
            .then(version => {
                updateAppVersionFromConfig(version);
            })
            .then(() => {
                startProcessing();
            });
    } else {
        startProcessing();
    }
})();
