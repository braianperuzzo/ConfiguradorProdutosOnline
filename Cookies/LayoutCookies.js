(function initLgpdCookiesModule() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    const IS_AREA_CLIENTE_PATH = /^\/(Paginas\/)?AreaCliente/.test(window.location.pathname);
    const ELEMENT_NODE = (typeof Node !== 'undefined' && Node.ELEMENT_NODE) || 1;
    const TEXT_NODE = (typeof Node !== 'undefined' && Node.TEXT_NODE) || 3;
    const lgpdKeyElement = document.getElementById('lgpd-key');
    const remoteEndpointsFlag = typeof window.__LGPD_ENABLE_REMOTE_ENDPOINTS === 'boolean'
        ? window.__LGPD_ENABLE_REMOTE_ENDPOINTS
        : Boolean(lgpdKeyElement && (lgpdKeyElement.getAttribute('data-lgpd-remote-endpoints') || '').toLowerCase() === 'true');

    if (!lgpdKeyElement && !remoteEndpointsFlag) {
        if (typeof window.signalInitialLoaderCookiesReady === 'function') {
            window.signalInitialLoaderCookiesReady();
        }
        return;
    }

    function isLgpdCookiesDisabled() {
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

        const bodyEl = document.body;
        if (bodyEl) {
            if (bodyEl.classList && bodyEl.classList.contains('lgpd-disabled')) {
                return true;
            }
            if (bodyEl.dataset && bodyEl.dataset.lgpdDisabled === 'true') {
                return true;
            }
        }

        if (!lgpdKeyElement && !remoteEndpointsFlag) {
            return true;
        }

        if (lgpdKeyElement && (lgpdKeyElement.getAttribute('data-disable-lgpd') === 'true' || lgpdKeyElement.hasAttribute('data-disable-lgpd'))) {
            return true;
        }

        return false;
    }

    (function () {
        var shouldDisableLgpd = function () {
            return isLgpdCookiesDisabled();
        };

        if (typeof window.delayInitialLoaderForCookies === 'function') {
            if (shouldDisableLgpd()) {
                if (typeof window.signalInitialLoaderCookiesReady === 'function') {
                    window.signalInitialLoaderCookiesReady();
                }
            } else {
                window.delayInitialLoaderForCookies();
            }
        } else if (shouldDisableLgpd() && typeof window.signalInitialLoaderCookiesReady === 'function') {
            window.signalInitialLoaderCookiesReady();
        }

        var initLgpdCookies = function () {
            if (shouldDisableLgpd()) {
                return true;
            }
            var lgpd = window.LGPDCOOKIES;
            if (lgpd && typeof lgpd.onReady === 'function') {
                lgpd.onReady();
                return true;
            }
            return false;
        };

        var scheduleInitialization = function () {
            if (shouldDisableLgpd()) {
                return;
            }
            if (!initLgpdCookies()) {
                window.setTimeout(scheduleInitialization, 50);
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', scheduleInitialization, { once: true });
        } else {
            scheduleInitialization();
        }
    })();

    window.LGPDCOOKIES = {
        baseUrl: (function () {
            const el = document.getElementById('lgpd-key');
            var base = '';
            if (el) base = el.getAttribute('urlBase') || '';
            base = (base || '').trim();
            if (base && base.toLowerCase().startsWith('http')) base = '';
            if (!base) {
                return '/';
            }
            if (base.charAt(0) !== '/') {
                base = '/' + base;
            }
            base = base.replace(/\/+$/, '');
            if (!base) {
                return '/';
            }
            return base === '/' ? '/' : base + '/';
        })(),
        remoteEndpointsEnabled: (function () {
            if (typeof window !== 'undefined' && typeof window.__LGPD_ENABLE_REMOTE_ENDPOINTS === 'boolean') {
                return window.__LGPD_ENABLE_REMOTE_ENDPOINTS;
            }

            if (typeof document !== 'undefined') {
                var el = document.getElementById('lgpd-key');
                if (el) {
                    var attribute = el.getAttribute('data-lgpd-remote-endpoints');
                    if (attribute) {
                        return attribute.toLowerCase() === 'true';
                    }
                }
            }

            return false;
        })(),
        lgpdKey: '',
        lgpdPrefix: '',
        cookiesConsent: '',
        templateContent: '',
        templatePromise: null,
        loadTemplate: function () {
            if (!this.templateContent) {
                var inlineTemplate = document.getElementById('lgpd-consent-template');
                if (inlineTemplate) {
                    var html = inlineTemplate.innerHTML || '';
                    if (html.trim()) {
                        this.templateContent = '<body>' + html + '</body>';
                    }
                }
                if (!this.templateContent) {
                    var inlineContainer = document.getElementById('lgpdDivFooter');
                    if (inlineContainer) {
                        var scriptMarkup = '';
                        var inlineScript = document.getElementById('lgpd-inline-consent-script');
                        if (inlineScript) {
                            var clonedScript = inlineScript.cloneNode(true);
                            if (clonedScript.removeAttribute) {
                                clonedScript.removeAttribute('id');
                            }
                            var temp = document.createElement('div');
                            temp.appendChild(clonedScript);
                            scriptMarkup = temp.innerHTML;
                        }
                        this.templateContent = '<body>' + inlineContainer.innerHTML + scriptMarkup + '</body>';
                    }
                }
            }
            if (this.templateContent) {
                return Promise.resolve(this.templateContent);
            }
            if (this.templatePromise) {
                return this.templatePromise;
            }

            var url = this.templateUrl;
            var fallback = '<body></body>';
            this.templatePromise = new Promise(function (resolve) {
                var finalize = function (html) {
                    LGPDCOOKIES.templateContent = html || fallback;
                    resolve(LGPDCOOKIES.templateContent);
                };

                if (window.fetch) {
                    fetch(url, { credentials: 'same-origin' })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('HTTP ' + response.status);
                            }
                            return response.text();
                        })
                        .then(finalize)
                        .catch(function (error) {
                            console.error('LGPDCOOKIES: falha ao carregar template', error);
                            finalize(fallback);
                        });
                } else {
                    try {
                        var xhr = new XMLHttpRequest();
                        xhr.open('GET', url, true);
                        xhr.onreadystatechange = function () {
                            if (xhr.readyState === 4) {
                                if (xhr.status >= 200 && xhr.status < 300) {
                                    finalize(xhr.responseText);
                                } else {
                                    console.error('LGPDCOOKIES: falha ao carregar template', xhr.status);
                                    finalize(fallback);
                                }
                            }
                        };
                        xhr.send();
                    } catch (error) {
                        console.error('LGPDCOOKIES: falha ao carregar template', error);
                        finalize(fallback);
                    }
                }
            });

            return this.templatePromise;
        },
        getDefaultConsentState: function () {
            return {
                'Necessary': false,
                'Functional': false,
                'Advertising': false,
                'Performance': false,
                'CRMDataSharing': false,
                'AITraining': false
            };
        },
        parseConsentCookie: function () {
            if (!this.cookiesConsent) {
                return null;
            }
            try {
                return JSON.parse(decodeURIComponent(this.cookiesConsent));
            } catch (error) {
                return null;
            }
        },
        getConsentWithDefaults: function () {
            var defaults = this.getDefaultConsentState();
            var normalized = {};
            for (var key in defaults) {
                if (Object.prototype.hasOwnProperty.call(defaults, key)) {
                    normalized[key] = defaults[key];
                }
            }

            var parsed = this.parseConsentCookie();
            if (parsed && typeof parsed === 'object') {
                for (var prop in parsed) {
                    if (Object.prototype.hasOwnProperty.call(parsed, prop)) {
                        normalized[prop] = parsed[prop];
                    }
                }
            }

            for (var category in defaults) {
                if (Object.prototype.hasOwnProperty.call(defaults, category)) {
                    if (typeof normalized[category] !== 'boolean') {
                        normalized[category] = defaults[category];
                    }
                }
            }

            if (normalized.Necessary !== true) {
                normalized.Necessary = true;
            }
            normalized.CRMDataSharing = true;

            return normalized;
        },
        ensureDefaultConsent: function () {
            if (this.cookiesConsent === '') {
                if (IS_AREA_CLIENTE_PATH) {
                    var timestamp = new Date().toISOString();
                    var defaultConsent = this.getDefaultConsentState();
                    defaultConsent.Location = window.location.origin;
                    defaultConsent.ConsentTimestamp = timestamp;
                    this.setCookie('lgpd-cookies-consent', encodeURIComponent(JSON.stringify(defaultConsent)));
                    this.cookiesConsent = this.getValueCookie('lgpd-cookies-consent');
                    return defaultConsent;
                }
                return this.getConsentWithDefaults();
            }

            var parsed = this.parseConsentCookie() || {};
            var defaults = this.getDefaultConsentState();
            var changed = false;

            for (var key in defaults) {
                if (Object.prototype.hasOwnProperty.call(defaults, key)) {
                    if (typeof parsed[key] !== 'boolean') {
                        parsed[key] = defaults[key];
                        changed = true;
                    }
                }
            }

            if (parsed.Necessary !== true) {
                parsed.Necessary = true;
                changed = true;
            }

            if (parsed.CRMDataSharing !== true) {
                parsed.CRMDataSharing = true;
                changed = true;
            }

            if (!parsed.ConsentTimestamp) {
                parsed.ConsentTimestamp = new Date().toISOString();
                changed = true;
            }

            if (!parsed.Location) {
                parsed.Location = window.location.origin;
                changed = true;
            }

            if (changed) {
                this.setCookie('lgpd-cookies-consent', encodeURIComponent(JSON.stringify(parsed)));
                this.cookiesConsent = this.getValueCookie('lgpd-cookies-consent');
            }

            return parsed;
        },

        loadAndWriteTemplate: function (lastUpdateDate) {
            return this.loadTemplate().then(function (html) {
                LGPDCOOKIES.writeContent(html, lastUpdateDate || '');
            });
        },
        formatLastUpdateDate: function (lastUpdateDate) {
            if (!lastUpdateDate) {
                return 'Não informado';
            }

            try {
                var parsedDate = new Date(lastUpdateDate);
                if (!isNaN(parsedDate.getTime())) {
                    return parsedDate.toLocaleDateString('pt-BR', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit'
                    });
                }
            } catch (e) { }

            return 'Não informado';
        },

        onReady: function () {
            const keyEl = document.getElementById('lgpd-key');
            LGPDCOOKIES.lgpdPrefix = keyEl ? keyEl.getAttribute('prefix') : '';
            LGPDCOOKIES.cookiesConsent = LGPDCOOKIES.getValueCookie('lgpd-cookies-consent');
            LGPDCOOKIES.ensureDefaultConsent();

            LGPDCOOKIES.loadAndWriteTemplate('');
        },
        sendCookies: async function () {
            const consentState = this.getConsentWithDefaults ? this.getConsentWithDefaults() : null;
            if (consentState && !consentState.Functional && !consentState.Performance && !consentState.Advertising) {
                return;
            }
            const identifiers = [];
            const includeIdentifier = function (name, type) {
                if (!LGPDCOOKIES.shouldIncludeIdentifier(name)) return;

                const alreadyRegistered = identifiers.some(function (item) {
                    return item.name === name && item.type === type;
                });

                if (!alreadyRegistered) {
                    identifiers.push({ name: name, type: type });
                }
            };

            if (document.cookie.length > 0) {
                const allCookies = document.cookie.split(/;\s*/);

                allCookies.forEach(function (cookie) {
                    const cookieParts = cookie.split('=');
                    includeIdentifier(cookieParts[0], 'Cookie');
                });
            }

            if (typeof (Storage) !== "undefined") {
                for (var i = 0; i < localStorage.length; i++) {
                    includeIdentifier(localStorage.key(i), 'LocalStorage');
                }

                for (var j = 0; j < sessionStorage.length; j++) {
                    includeIdentifier(sessionStorage.key(j), 'SessionStorage');
                }
            }

            if (identifiers.length === 0) {
                return;
            }

            const hashedIdentifiers = [];
            for (var k = 0; k < identifiers.length; k++) {
                var hashedName = await LGPDCOOKIES.hashIdentifier(identifiers[k].name);
                if (hashedName) {
                    hashedIdentifiers.push({
                        'Hash': hashedName,
                        'Type': identifiers[k].type
                    });
                }
            }

            if (hashedIdentifiers.length === 0) {
                return;
            }

            const modelSendCookies = {
                'Identifiers': hashedIdentifiers,
                'Location': window.location.origin,
                'SchemaVersion': 2
            };

            LGPDCOOKIES.sendPost(
                'Cookie/SendCookies',
                JSON.stringify(modelSendCookies),
                null);
        },
        shouldIncludeIdentifier: function (name) {
            if (!name || typeof name !== 'string') return false;

            var trimmed = name.trim();
            if (trimmed === '') return false;

            var prefixes = [];
            if (LGPDCOOKIES.lgpdPrefix) {
                prefixes.push(LGPDCOOKIES.lgpdPrefix + '-');
            }
            prefixes.push('lgpd-');

            var lowerName = trimmed.toLowerCase();
            return prefixes.some(function (prefix) {
                return prefix && lowerName.indexOf(prefix.toLowerCase()) === 0;
            });
        },
        hashIdentifier: async function (value) {
            if (!value || typeof value !== 'string') return null;
            if (!window.crypto || !window.crypto.subtle || typeof TextEncoder === 'undefined') {
                return null;
            }

            try {
                var encoder = new TextEncoder();
                var data = encoder.encode(value);
                var digest = await window.crypto.subtle.digest('SHA-256', data);
                var hashArray = Array.from(new Uint8Array(digest));
                return hashArray.map(function (b) {
                    return b.toString(16).padStart(2, '0');
                }).join('');
            } catch (error) {
                console.error('LGPDCOOKIES: falha ao hashear identificador', error);
                return null;
            }
        },
        loadLocalContent: function (lastUpdateDate) {
            var normalizedLastUpdate = lastUpdateDate || '';
            var storageKey = (LGPDCOOKIES.lgpdPrefix ? LGPDCOOKIES.lgpdPrefix + '-' : '') + 'lgpd-cookies-content';

            if (typeof (Storage) !== "undefined") {
                var storedValue = localStorage.getItem(storageKey);
                if (storedValue) {
                    try {
                        var decoded = decodeURIComponent(storedValue);
                        if (decoded && decoded.trim()) {
                            return LGPDCOOKIES.writeContent(decoded, normalizedLastUpdate);
                        }
                    } catch (error) {
                        console.warn('LGPDCOOKIES: falha ao restaurar conteúdo local armazenado', error);
                        try {
                            localStorage.removeItem(storageKey);
                        } catch (removeError) {
                            console.warn('LGPDCOOKIES: falha ao limpar conteúdo local inválido', removeError);
                        }
                    }
                }
            }

            return LGPDCOOKIES.loadAndWriteTemplate(normalizedLastUpdate).then(function () {
                if (typeof (Storage) === "undefined") {
                    return;
                }

                var template = LGPDCOOKIES.templateContent || '';
                if (!template || !template.trim()) {
                    return;
                }

                try {
                    localStorage.setItem(storageKey, encodeURIComponent(template));
                } catch (error) {
                    console.warn('LGPDCOOKIES: falha ao armazenar template local do banner', error);
                }
            }).catch(function (error) {
                console.error('LGPDCOOKIES: falha ao aplicar conteúdo local do banner LGPD', error);
                return LGPDCOOKIES.writeContent('', normalizedLastUpdate);
            });
        },
        getConfig: function (lastUpdateDate) {
            if (!this.remoteEndpointsEnabled) {
                return this.loadLocalContent(lastUpdateDate);
            }
            const modelGetConfig = {
                'LastUpdateDate': lastUpdateDate == '' ? null : lastUpdateDate,
                'Location': window.location.origin
            };

            LGPDCOOKIES.sendPost(
                'Config/ObterConfig',
                JSON.stringify(modelGetConfig),
                function (response) {
                    var config = null;
                    try {
                        config = JSON.parse(response || '{}');
                    } catch (error) {
                        console.error('LGPDCOOKIES: falha ao interpretar configuração remota', error);
                        LGPDCOOKIES.loadLocalContent(lastUpdateDate);
                        return;
                    }

                    if (!config || config.Error) {
                        return;
                    }

                    try {
                        if (config.GetDataLocally && typeof (Storage) !== "undefined") {
                            console.log('search locally');
                            LGPDCOOKIES.writeContent(decodeURIComponent(localStorage.getItem(LGPDCOOKIES.lgpdPrefix + '-' + 'lgpd-cookies-content')), config.LastUpdateDate)
                        }
                        else {
                            console.log('search server')
                            LGPDCOOKIES.getWriteContent(config.LastUpdateDate);
                        }

                        if (config.Sweep)
                            setTimeout(function () { LGPDCOOKIES.sendCookies(); }, 10000);
                    } catch (error) {
                        console.error('LGPDCOOKIES: falha ao aplicar configuração remota', error);
                        LGPDCOOKIES.loadLocalContent(lastUpdateDate);
                    }
                });
        },
        getWriteContent: function (lastUpdateDate) {
            if (!this.remoteEndpointsEnabled) {
                return this.loadLocalContent(lastUpdateDate);
            }

            var consentState = LGPDCOOKIES.getConsentWithDefaults();
            const modelGetContent = {
                'Necessary': consentState.Necessary,
                'Functional': consentState.Functional,
                'Advertising': consentState.Advertising,
                'Performance': consentState.Performance,
                'CRMDataSharing': consentState.CRMDataSharing,
                'AITraining': consentState.AITraining,
                'Location': window.location.origin
            };

            LGPDCOOKIES.sendPost(
                'Content/Content',
                JSON.stringify(modelGetContent),
                function (response) {
                    if (typeof (Storage) !== "undefined") {
                        localStorage.setItem(LGPDCOOKIES.lgpdPrefix + '-' + 'lgpd-cookies-content', encodeURIComponent(response));
                    }
                    LGPDCOOKIES.writeContent(response, lastUpdateDate);
                });
        },
        writeContent: function (content, lastUpdateDate) {
            lastUpdateDate = lastUpdateDate || '';
            var d = document;
            var applyTemplate = function (template) {
                var sanitized = template || '';
                var bodyMatch = /<body.*?>([\s\S]*)<\/body>/i.exec(sanitized);
                var scriptMatch = /<script\b([^>]*)>([\s\S]*?)<\/script>/i.exec(sanitized);
                var scriptAttributes = scriptMatch ? scriptMatch[1] : '';
                var scriptContent = scriptMatch ? scriptMatch[2] : '';
                var htmlContent = bodyMatch ? bodyMatch[1] : sanitized;

                var existingContainer = d.getElementById('lgpdDivFooter');
                var hasInlineMarkup = existingContainer && existingContainer.children && existingContainer.children.length > 0 &&
                    existingContainer.getAttribute('data-lgpd-inline') === 'true';

                if (htmlContent && htmlContent.trim()) {
                    if (hasInlineMarkup) {
                        try {
                            LGPDCOOKIES.hydrateInlineContent(existingContainer, htmlContent);
                        } catch (error) {
                            console.error('LGPDCOOKIES: falha ao atualizar conteúdo inline do banner', error);
                        }
                    } else {
                        var container = existingContainer;
                        if (!container) {
                            container = d.createElement('div');
                            container.id = 'lgpdDivFooter';
                            d.body.appendChild(container);
                        }
                        container.setAttribute('data-lgpd-inline', 'false');
                        container.innerHTML = htmlContent;
                    }
                }

                try {
                    LGPDCOOKIES.setupCookieModalOutsideClick();
                } catch (error) {
                    console.error('LGPDCOOKIES: falha ao configurar fechamento externo do modal', error);
                }

                var existingScript = d.querySelector('script[data-lgpd-script="true"]');
                if (existingScript && existingScript.parentNode) {
                    existingScript.parentNode.removeChild(existingScript);
                }

                if (scriptMatch) {
                    var sanitizedAttributes = scriptAttributes || '';
                    if (/<\?/.test(sanitizedAttributes)) {
                        sanitizedAttributes = sanitizedAttributes.replace(/<\?[\s\S]*?\?>/g, '').trim();
                    }

                    var attributes = {};
                    var attrRegex = /([^\s=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>`]+)))?/g;
                    var match;
                    while ((match = attrRegex.exec(sanitizedAttributes))) {
                        var name = match[1];
                        var value = match[2];
                        if (value == null) { value = match[3]; }
                        if (value == null) { value = match[4]; }
                        if (value == null) { value = ''; }
                        if (/<\?/.test(name) || /<\?/.test(value)) { continue; }
                        attributes[name] = value;
                    }

                    var typeAttribute = '';
                    Object.keys(attributes).forEach(function (key) {
                        if (key && key.toLowerCase() === 'type') {
                            typeAttribute = attributes[key];
                        }
                    });

                    var normalizedType = (typeAttribute || '').trim().toLowerCase();
                    var isModuleType = normalizedType === 'module';
                    var isJavascriptType = !normalizedType || /^(?:text|application)\/(?:java|ecma)script$/i.test(normalizedType);
                    var trimmedContent = (scriptContent || '').trim();
                    var inferredModuleType = !isModuleType && isJavascriptType &&
                        /(^|[\r\n])\s*(import|export)\b/.test(trimmedContent);

                    if (inferredModuleType) {
                        isModuleType = true;
                    }

                    var startsWithMarkup = trimmedContent && trimmedContent.charAt(0) === '<' &&
                        !/^<\!--/.test(trimmedContent) &&
                        !/^<!\[CDATA\[/i.test(trimmedContent);

                    if (!isModuleType && !isJavascriptType) {
                        if (startsWithMarkup) {
                            console.warn('LGPDCOOKIES: ignorando script do template com conteúdo não JavaScript');
                        } else {
                            console.warn('LGPDCOOKIES: ignorando script do template com tipo não suportado', normalizedType || '(sem tipo)');
                        }
                    } else if (isModuleType || isJavascriptType) {
                        if (startsWithMarkup) {
                            console.warn('LGPDCOOKIES: ignorando script do template com conteúdo tratado como marcação');
                        } else {
                            var s = d.createElement('script');
                            s.setAttribute('data-lgpd-script', 'true');

                            Object.keys(attributes).forEach(function (key) {
                                var value = attributes[key];
                                if (key.toLowerCase() === 'type') {
                                    if (isModuleType) {
                                        s.setAttribute('type', 'module');
                                    } else if (isJavascriptType && value) {
                                        s.setAttribute('type', value);
                                    }
                                    return;
                                }
                                if (value === '' && /^(?:async|defer|nomodule)$/i.test(key)) {
                                    s.setAttribute(key, '');
                                    return;
                                }
                                s.setAttribute(key, value);
                            });

                            if (!s.hasAttribute('type')) {
                                if (isModuleType) {
                                    s.setAttribute('type', 'module');
                                } else {
                                    s.setAttribute('type', 'text/javascript');
                                }
                            }

                            if (trimmedContent) {
                                var sanitizedScriptContent = scriptContent
                                    .replace(/\u2028/g, '\\u2028')
                                    .replace(/\u2029/g, '\\u2029');
                                if (/<\?/.test(sanitizedScriptContent)) {
                                    console.error('LGPDCOOKIES: script do template ignorado por conter marcação PHP');
                                    return;
                                }

                                var hasExternalSource = Object.prototype.hasOwnProperty.call(attributes, 'src');
                                if (!isModuleType && !hasExternalSource) {
                                    try {
                                        // Valida scripts clássicos antes de injetar no DOM para evitar
                                        // SyntaxError assíncrono no appendChild quando o template vier com conteúdo inválido.
                                        // eslint-disable-next-line no-new-func
                                        new Function(sanitizedScriptContent);
                                    } catch (validationError) {
                                        var shouldLogValidationError = !IS_AREA_CLIENTE_PATH || window.__LGPD_DEBUG === true;
                                        if (shouldLogValidationError) {
                                            console.error('LGPDCOOKIES: script do template ignorado por conteúdo inválido', validationError);
                                        }
                                        return;
                                    }
                                }

                                try {
                                    s.text = sanitizedScriptContent;
                                } catch (error) {
                                    try {
                                        s.textContent = sanitizedScriptContent;
                                    } catch (innerError) {
                                        console.error('LGPDCOOKIES: falha ao aplicar conteúdo do script do template', innerError);
                                    }
                                }
                            }

                            try {
                                d.body.appendChild(s);
                            } catch (error) {
                                console.error('LGPDCOOKIES: falha ao inserir script do template', error);
                            }
                        }
                    }
                }

                var lastUpdateElement = d.getElementById('lgpdCookiesLastUpdate');
                if (lastUpdateElement) {
                    lastUpdateElement.textContent = LGPDCOOKIES.formatLastUpdateDate(lastUpdateDate);
                }

                window.requestAnimationFrame(function () {
                    LGPDCOOKIES.setCookie('lgpd-cookies-last-update-date', lastUpdateDate);
                    LGPDCOOKIES.openFooter();
                    LGPDCOOKIES.applyConsentState();
                    handleScroll();
                });
            };

            if (content && content.trim()) {
                applyTemplate(content);
                return Promise.resolve();
            }

            return this.loadTemplate().then(function (template) {
                applyTemplate(template);
            });
        },
        hydrateInlineContent: function (container, htmlContent) {
            if (!container || !htmlContent) {
                return;
            }

            var wrapper = document.createElement('div');
            wrapper.innerHTML = htmlContent;

            var scripts = wrapper.querySelectorAll('script');
            Array.prototype.forEach.call(scripts, function (node) {
                if (node && node.parentNode) {
                    node.parentNode.removeChild(node);
                }
            });

            LGPDCOOKIES.syncChildList(container, wrapper);
        },
        shouldPreserveVisibility: function (node) {
            if (!node || node.nodeType !== ELEMENT_NODE) {
                return false;
            }
            var id = node.id || '';
            return id === 'lgpdCookieFooter' || id === 'lgpdShieldFooter';
        },
        syncChildList: function (targetContainer, sourceContainer) {
            if (!targetContainer || !sourceContainer) {
                return;
            }

            var targetChildren = Array.prototype.slice.call(targetContainer.children || []);
            var sourceChildren = Array.prototype.slice.call(sourceContainer.children || []);
            var limit = Math.min(targetChildren.length, sourceChildren.length);

            for (var i = 0; i < limit; i++) {
                var targetChild = targetChildren[i];
                var sourceChild = sourceChildren[i];
                var preserveVisibility = LGPDCOOKIES.shouldPreserveVisibility(targetChild);
                LGPDCOOKIES.syncNodes(targetChild, sourceChild, { preserveVisibility: preserveVisibility });
            }

            for (var j = limit; j < sourceChildren.length; j++) {
                var node = sourceChildren[j];
                if (node && node.tagName && node.tagName.toLowerCase() === 'script') {
                    continue;
                }
                targetContainer.appendChild(node.cloneNode(true));
            }
        },
        syncNodes: function (targetNode, sourceNode, options) {
            if (!targetNode || !sourceNode) {
                return;
            }

            if (targetNode.nodeType !== sourceNode.nodeType) {
                if (targetNode.parentNode) {
                    targetNode.parentNode.replaceChild(sourceNode.cloneNode(true), targetNode);
                }
                return;
            }

            if (targetNode.nodeType === TEXT_NODE) {
                var newValue = sourceNode.nodeValue;
                if (targetNode.nodeValue !== newValue) {
                    targetNode.nodeValue = newValue;
                }
                return;
            }

            if (targetNode.nodeType !== ELEMENT_NODE) {
                return;
            }

            var preserveVisibility = !!(options && options.preserveVisibility);
            LGPDCOOKIES.syncAttributes(targetNode, sourceNode, preserveVisibility);

            var targetChildren = Array.prototype.slice.call(targetNode.childNodes || []);
            var sourceChildren = Array.prototype.slice.call(sourceNode.childNodes || []);
            var max = Math.min(targetChildren.length, sourceChildren.length);

            for (var i = 0; i < max; i++) {
                LGPDCOOKIES.syncNodes(targetChildren[i], sourceChildren[i], { preserveVisibility: false });
            }

            for (var j = max; j < sourceChildren.length; j++) {
                targetNode.appendChild(sourceChildren[j].cloneNode(true));
            }
        },
        syncAttributes: function (targetNode, sourceNode, preserveVisibility) {
            if (!targetNode || !sourceNode || targetNode.nodeType !== ELEMENT_NODE || sourceNode.nodeType !== ELEMENT_NODE) {
                return;
            }

            var preserved = { 'id': true };
            var visibilityAttributes = preserveVisibility ? { 'hidden': true, 'aria-hidden': true, 'style': true } : {};

            Array.prototype.slice.call(targetNode.attributes).forEach(function (attr) {
                var name = attr.name;
                if (preserved[name]) {
                    return;
                }
                if (visibilityAttributes[name]) {
                    return;
                }
                if (!sourceNode.hasAttribute(name)) {
                    targetNode.removeAttribute(name);
                }
            });

            Array.prototype.slice.call(sourceNode.attributes).forEach(function (attr) {
                var name = attr.name;
                if (preserved[name]) {
                    return;
                }
                if (visibilityAttributes[name]) {
                    return;
                }
                var value = attr.value;
                if (targetNode.getAttribute(name) !== value) {
                    targetNode.setAttribute(name, value);
                }
            });
        },
        setupCookieModalOutsideClick: function () {
            var overlay = document.getElementById('modalLgpdCookies');
            var container = document.getElementById('modalLgpdCookiesContainer');

            if (!overlay || !container) {
                return;
            }

            if (overlay.dataset && overlay.dataset.lgpdOutsideClick === 'true') {
                return;
            }

            var closeModal = function () {
                var closeButton = overlay.querySelector('#modalLgpdCookiesFechar [data-lgpd-action], #modalLgpdCookiesFechar button, #modalLgpdCookiesFechar');
                if (closeButton) {
                    try {
                        closeButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));
                        return;
                    } catch (error) { }
                }

                overlay.style.display = 'none';
                overlay.setAttribute('hidden', 'true');
                overlay.setAttribute('aria-hidden', 'true');

                var html = document.documentElement;
                if (html && html.classList) {
                    html.classList.remove('lgpd-modal-open');
                }
                if (document.body && document.body.classList) {
                    document.body.classList.remove('lgpd-modal-open');
                }
            };

            var handler = function (event) {
                if (!container.contains(event.target)) {
                    closeModal();
                }
            };

            overlay.addEventListener('click', handler);
            overlay.addEventListener('touchstart', handler, { passive: true });

            if (overlay.dataset) {
                overlay.dataset.lgpdOutsideClick = 'true';
            }
        },

        getValueCookie: function (cname) {
            var cname = LGPDCOOKIES.lgpdPrefix + '-' + cname;
            var name = cname + "=";
            var rawCookieString = document.cookie || '';
            var decodedCookie;
            var shouldDecodeIndividually = false;
            try {
                decodedCookie = decodeURIComponent(rawCookieString);
            } catch (error) {
                if (error instanceof URIError) {
                    decodedCookie = rawCookieString;
                    shouldDecodeIndividually = true;
                } else {
                    throw error;
                }
            }
            var ca = decodedCookie.split(';');
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i];
                if (shouldDecodeIndividually) {
                    try {
                        c = decodeURIComponent(c);
                    } catch (error) {
                        if (!(error instanceof URIError)) {
                            throw error;
                        }
                    }
                }
                while (c.charAt(0) == ' ') {
                    c = c.substring(1);
                }
                if (c.indexOf(name) == 0) {
                    return c.substring(name.length, c.length);
                }
            }
            return "";
        },
        setCookie: function (cname, cvalue) {
            var cname = LGPDCOOKIES.lgpdPrefix + '-' + cname;
            var d = new Date();
            d.setTime(d.getTime() + (365 * 24 * 60 * 60 * 1000));
            var expires = "expires=" + d.toUTCString();
            var attributes = ";" + expires + ";path=/;SameSite=Lax";
            if (window.location && window.location.protocol === 'https:') {
                attributes += ';Secure';
            }
            document.cookie = cname + "=" + cvalue + attributes;
        },
        sendPost: function (endpoint, request, callback) {
            var normalizedEndpoint = (endpoint || '').replace(/^\/+/, '');
            var url = LGPDCOOKIES.baseUrl + normalizedEndpoint, xmlhttp; if ("XMLHttpRequest" in window) xmlhttp = new XMLHttpRequest();
            if ("ActiveXObject" in window) xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
            xmlhttp.open('POST', url, true);
            xmlhttp.setRequestHeader("Authorization", LGPDCOOKIES.lgpdKey);
            xmlhttp.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
            xmlhttp.onreadystatechange = function () {
                if (xmlhttp.readyState == 4 && callback != null) {
                    callback(xmlhttp.responseText);
                }
            };
            xmlhttp.send(request);
        },

        openFooter: function () {
            var footer = document.getElementById('lgpdCookieFooter');
            var shield = document.getElementById('lgpdShieldFooter');
            var spacingFrame = null;
            var cancelScheduledSpacing = function () {
                if (spacingFrame === null) {
                    return;
                }
                if (typeof cancelAnimationFrame === 'function') {
                    cancelAnimationFrame(spacingFrame);
                } else {
                    clearTimeout(spacingFrame);
                }
                spacingFrame = null;
            };
            var schedule = function (callback) {
                if (typeof requestAnimationFrame === 'function') {
                    return requestAnimationFrame(callback);
                }
                return setTimeout(callback, 0);
            };
            var updateSpacing = function (shouldShowFooter, options) {
                var root = document.documentElement;
                if (!root) {
                    return;
                }

                var immediateHeight = options && typeof options.immediateHeight === 'string'
                    ? options.immediateHeight
                    : null;

                if (!shouldShowFooter) {
                    cancelScheduledSpacing();
                    root.style.setProperty('--lgpd-cookie-banner-height', '0');
                    return;
                }

                if (immediateHeight) {
                    root.style.setProperty('--lgpd-cookie-banner-height', immediateHeight);
                }

                cancelScheduledSpacing();
                spacingFrame = schedule(function () {
                    spacingFrame = null;
                    var height = 0;
                    if (footer && typeof footer.getBoundingClientRect === 'function') {
                        var rect = footer.getBoundingClientRect();
                        height = Math.max(0, Math.ceil(rect.height || footer.offsetHeight || 0));
                    }
                    root.style.setProperty('--lgpd-cookie-banner-height', height ? height + 'px' : '0');
                });
            };
            var toggleVisibility = function (element, shouldShow) {
                if (!element) {
                    return;
                }
                if (element.style && element.style.display) {
                    element.style.removeProperty('display');
                }
                if (shouldShow) {
                    element.removeAttribute('hidden');
                    element.setAttribute('aria-hidden', 'false');
                } else {
                    element.setAttribute('hidden', 'true');
                    element.setAttribute('aria-hidden', 'true');
                }
            };
            var notifyReady = function () {
                if (typeof window.signalInitialLoaderCookiesReady === 'function') {
                    window.signalInitialLoaderCookiesReady();
                }
            };

            if (!footer && !shield) {
                updateSpacing(false);
                notifyReady();
                return;
            }
            if (IS_AREA_CLIENTE_PATH) {
                toggleVisibility(footer, false);
                toggleVisibility(shield, false);
                updateSpacing(false);
                notifyReady();
                return;
            }
            if (LGPDCOOKIES.cookiesConsent == '') {
                toggleVisibility(footer, true);
                toggleVisibility(shield, false);
                updateSpacing(true, { immediateHeight: 'var(--lgpd-cookie-banner-min-height-first-access, 176px)' });
            } else {
                toggleVisibility(footer, false);
                toggleVisibility(shield, true);
                updateSpacing(false);
            }
            notifyReady();
        },
        applyConsentState: function () {
            var consent = LGPDCOOKIES.getConsentWithDefaults();

            var map = {
                'Functional': 'modalLgpdCheckboxFunctional',
                'Advertising': 'modalLgpdCheckboxAdvertising',
                'Performance': 'modalLgpdCheckboxPerformance',
                'AITraining': 'modalLgpdCheckboxAITraining'
            };
            for (var key in map) {
                var el = document.getElementById(map[key]);
                if (!el) continue;
                if (consent[key]) {
                    el.classList.add('on');
                    el.setAttribute('aria-checked', 'true');
                } else {
                    el.classList.remove('on');
                    el.setAttribute('aria-checked', 'false');
                }
            }
            var crmConsent = document.getElementById('modalLgpdCheckboxCrmErp');
            if (crmConsent) {
                crmConsent.classList.add('on');
                crmConsent.setAttribute('aria-checked', 'true');
            }
        },
        saveConsent: function (all) {
            var modelSetConsent = {};
            var timestamp = new Date().toISOString();

            if (all) {
                modelSetConsent = {
                    'Necessary': true,
                    'Functional': true,
                    'Advertising': true,
                    'Performance': true,
                    'CRMDataSharing': true,
                    'AITraining': true,
                    'Location': window.location.origin,
                    'ConsentTimestamp': timestamp
                };
            } else {
                modelSetConsent = {
                    'Necessary': true,
                    'Functional': document.getElementById("modalLgpdCheckboxFunctional") ? document.getElementById("modalLgpdCheckboxFunctional").classList.contains('on') : false,
                    'Advertising': document.getElementById("modalLgpdCheckboxAdvertising") ? document.getElementById("modalLgpdCheckboxAdvertising").classList.contains('on') : false,
                    'Performance': document.getElementById("modalLgpdCheckboxPerformance") ? document.getElementById("modalLgpdCheckboxPerformance").classList.contains('on') : false,
                    'CRMDataSharing': true,
                    'AITraining': document.getElementById("modalLgpdCheckboxAITraining") ? document.getElementById("modalLgpdCheckboxAITraining").classList.contains('on') : false,
                    'Location': window.location.origin,
                    'ConsentTimestamp': timestamp
                };
            }

            LGPDCOOKIES.setCookie('lgpd-cookies-consent', encodeURIComponent(JSON.stringify(modelSetConsent)));
            LGPDCOOKIES.cookiesConsent = LGPDCOOKIES.getValueCookie('lgpd-cookies-consent');
            LGPDCOOKIES.setCookie('lgpd-cookies-last-update-date', '');

            location.reload();
        }

    }

})();
