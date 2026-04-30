(function bootstrapInitialLoader(global) {
    if (!global) {
        return;
    }

    if (typeof global.__suppressExtensionMessagingNoise !== 'function') {
        global.__suppressExtensionMessagingNoise = function suppressExtensionMessagingNoise() {
            if (typeof global.addEventListener !== 'function') {
                return;
            }

            var KNOWN_ERROR_FRAGMENTS = [
                'A listener indicated an asynchronous response by returning true, but the message channel closed before a response was received',
                'The message port closed before a response was received',
                'Could not establish connection. Receiving end does not exist',
                'The deferred DOM Node could not be resolved to a valid node'
            ];
            var KNOWN_ERROR_MATCHER_PROPS = ['message', 'stack', 'reason', 'error', 'cause'];
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

            global.addEventListener('unhandledrejection', function (event) {
                var reason = event && event.reason;

                if (!containsKnownFragment(reason)) {
                    return;
                }

                blockNoise(event);
            }, true);

            global.addEventListener('error', function (event) {
                var message = event && event.message;
                var error = event && event.error;

                if (!containsKnownFragment(message) && !containsKnownFragment(error)) {
                    return;
                }

                blockNoise(event);
            });
        };
    }

    global.__suppressExtensionMessagingNoise();

    if (typeof global.__setupDynamicDocumentTitle !== 'function') {
        global.__setupDynamicDocumentTitle = function setupDynamicDocumentTitle() {
            if (typeof document === 'undefined') {
                return;
            }

            var TITLE_SUFFIX = 'Configurador de Produtos IBR';

            function resolveTitleFromUrl(pathname, search) {
                var path = normalizeText(pathname || '').toLowerCase();
                var normalizedPath = path.replace(/\/+$/, '') || '/';

                if (isHomePath(normalizedPath)) {
                    return 'Home | ' + TITLE_SUFFIX;
                }

                if (isAreaClienteLogin(normalizedPath, normalizedPath + (search || ''), null)) {
                    return 'Área do Cliente | ' + TITLE_SUFFIX;
                }

                var params = null;
                try {
                    params = new URLSearchParams(search || '');
                } catch (error) {
                    params = null;
                }

                var lineCodeByParam = {
                    HYLN: { '1.C': 'IBR C', '1.H': 'IBR H', '1.M': 'IBR M', '1.P': 'IBR P', '1.R': 'IBR R', '1.X': 'IBR X' },
                    QULN: { '1.Q': 'IBR Q', '1.QDR': 'IBR QDR', '1.QP': 'IBR QP' },
                    FXLN: { '1.FFA': 'IBR P FFA', '1.FKA': 'IBR X FKA', '1.FR': 'IBR C FR' },
                    MOLN: { '2.I': 'IBR MS/ML', '3.I': 'IBR T3A/T3C', '3.W': 'IBR WEG ALTO RENDIMENTO', '3.APM': 'IBR ANTICORROSIVOS APM', '3.SPM': 'IBR ANTICORROSIVOS SPM' },
                    PLLN: { '3.PB': 'IBR PB', '3.PBL': 'IBR PBL', '3.SA': 'IBR SA', '3.SB': 'IBR SB', '3.SBL': 'IBR SBL', '3.SD': 'IBR SD' },
                    VALN: { '1.V': 'IBR V' },
                    ACLN: { '1.Z': 'IBR Z', '1.VFN': 'IBR VFN' },
                    AELN: { '3.GR': 'IBR GR', '3.GS': 'IBR GS', '3.RIC': 'IBR RIC' },
                    INLN: { '4.K': 'IBR K' }
                };

                if (params) {
                    var paramKeys = Object.keys(lineCodeByParam);
                    for (var i = 0; i < paramKeys.length; i += 1) {
                        var key = paramKeys[i];
                        var value = normalizeText(params.get(key)).toUpperCase();
                        if (value && lineCodeByParam[key][value]) {
                            return lineCodeByParam[key][value] + ' | ' + TITLE_SUFFIX;
                        }
                    }
                }

                return '';
            }

            function normalizeText(value) {
                if (typeof value !== 'string') {
                    return '';
                }
                return value
                    .replace(/<[^>]*>/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
            }

            function isHomePath(pathname) {
                var path = normalizeText(pathname || '/').toLowerCase();
                return !path || path === '/' || path === '/home';
            }

            function isAreaClienteInternal(pathname, pageUrl, sourceDoc) {
                var path = normalizeText(pathname || '').toLowerCase();
                var url = normalizeText(pageUrl || '').toLowerCase();

                if (path.indexOf('/areacliente/sessao') === 0 || path.indexOf('/paginas/areacliente/sessao') === 0) {
                    return true;
                }

                if (url.indexOf('paginasareaclientesessaoperfil/arealogada.html') !== -1) {
                    return true;
                }

                if (sourceDoc && sourceDoc.querySelector
                    && sourceDoc.querySelector('.area-cliente-layout, #tituloPagina, .nav-area-cliente')) {
                    return true;
                }

                return false;
            }

            function isAreaClienteLogin(pathname, pageUrl, sourceDoc) {
                var path = normalizeText(pathname || '').toLowerCase();
                var url = normalizeText(pageUrl || '').toLowerCase();

                if (isAreaClienteInternal(path, url, sourceDoc)) {
                    return false;
                }

                if (url.indexOf('paginasareaclienteacessocadastro/acessocadastro.html') !== -1) {
                    return true;
                }

                if (path === '/areacliente' || path === '/areacliente/' || path === '/paginas/areacliente' || path === '/paginas/areacliente/') {
                    return true;
                }

                return false;
            }

            function shouldUseH2(pathname, pageUrl, sourceDoc) {
                var path = normalizeText(pathname || '').toLowerCase();
                var url = normalizeText(pageUrl || '').toLowerCase();

                if (isAreaClienteInternal(path, url, sourceDoc)) {
                    return true;
                }

                return path.indexOf('/areacliente/sessao') === 0
                    || path.indexOf('/paginas/areacliente/sessao') === 0
                    || url.indexOf('paginasareaclientesessaoperfil/arealogada.html') !== -1;
            }

            function selectHeadingText(sourceDoc, headingTag) {
                if (!sourceDoc || !sourceDoc.querySelectorAll) {
                    return '';
                }

                var selector = headingTag + ':not(.sr-only):not(.visually-hidden):not([aria-hidden="true"]):not([hidden]), main ' + headingTag + ', #main-content ' + headingTag;
                var nodes = sourceDoc.querySelectorAll(selector);
                for (var i = 0; i < nodes.length; i += 1) {
                    var node = nodes[i];
                    if (!node || (node.closest && node.closest('.modal, .offcanvas'))) {
                        continue;
                    }
                    var text = normalizeText(node.textContent || '');
                    if (text) {
                        return text;
                    }
                }

                var fallback = sourceDoc.querySelector(headingTag);
                return normalizeText(fallback && fallback.textContent ? fallback.textContent : '');
            }

            function selectSelectorProductTitle(sourceDoc) {
                if (!sourceDoc || !sourceDoc.querySelectorAll) {
                    return '';
                }

                function isProbablyVisible(node) {
                    if (!node || node.hasAttribute('hidden') || node.getAttribute('aria-hidden') === 'true') {
                        return false;
                    }

                    if (node.style && node.style.display === 'none') {
                        return false;
                    }

                    if (node.classList && (node.classList.contains('d-none') || node.classList.contains('hidden'))) {
                        return false;
                    }

                    if (typeof global !== 'undefined' && global.getComputedStyle) {
                        try {
                            var computed = global.getComputedStyle(node);
                            if (computed && (computed.display === 'none' || computed.visibility === 'hidden')) {
                                return false;
                            }
                        } catch (error) {
                            // noop
                        }
                    }

                    return true;
                }

                var galleries = sourceDoc.querySelectorAll('.galeria-produto[data-title]');
                var firstTitle = '';

                for (var i = 0; i < galleries.length; i += 1) {
                    var gallery = galleries[i];
                    if (!gallery) {
                        continue;
                    }

                    var title = normalizeText(gallery.getAttribute('data-title'));
                    if (!title) {
                        continue;
                    }

                    if (!firstTitle) {
                        firstTitle = title;
                    }

                    if (isProbablyVisible(gallery)) {
                        return title;
                    }
                }

                return firstTitle;
            }

            function isSelectorDrivenPage(pathname, sourceDoc) {
                var path = normalizeText(pathname || '').toLowerCase();
                if (path.indexOf('/configurador') !== -1 || path.indexOf('/seletor') !== -1) {
                    return true;
                }
                return Boolean(sourceDoc && sourceDoc.querySelector && sourceDoc.querySelector('.galeria-produto[data-title]'));
            }

            function resolveDynamicTitle(options) {
                var input = options || {};
                var pathname = input.pathname || (global.location && global.location.pathname) || '/';
                var pageUrl = input.pageUrl || '';
                var sourceDoc = input.sourceDoc || document;

                function removeLegacyPrefix(title) {
                    var normalizedTitle = normalizeText(title);

                    if (!normalizedTitle) {
                        return '';
                    }

                    var shortPrefixRegex = /^[A-ZÀ-Ý0-9]{1,5}\s*[-|:]\s*(.+)$/;
                    var match = normalizedTitle.match(shortPrefixRegex);
                    if (match && match[1]) {
                        return normalizeText(match[1]);
                    }

                    var configurePrefixRegex = /^configure\s+o\s+seu\s+(.+)$/i;
                    match = normalizedTitle.match(configurePrefixRegex);
                    if (match && match[1]) {
                        return normalizeText(match[1]);
                    }

                    return normalizedTitle;
                }

                function buildTitleFromHeading(rawHeadingText) {
                    var normalizedHeading = removeLegacyPrefix(normalizeText(rawHeadingText));
                    var normalizedSuffix = normalizeText(TITLE_SUFFIX);
                    var suffixToken = ' | ' + normalizedSuffix;

                    if (!normalizedHeading || normalizedHeading === normalizedSuffix) {
                        if (isHomePath(pathname)) {
                            return 'Home | ' + normalizedSuffix;
                        }
                        return '';
                    }

                    if (normalizedHeading.slice(-suffixToken.length) === suffixToken) {
                        return normalizedHeading;
                    }

                    return normalizedHeading + suffixToken;
                }

                if (isHomePath(pathname)) {
                    return 'Home | ' + TITLE_SUFFIX;
                }

                if (isAreaClienteLogin(pathname, pageUrl, sourceDoc)) {
                    return 'Área do Cliente | ' + TITLE_SUFFIX;
                }

                var urlResolvedTitle = resolveTitleFromUrl(pathname, (global.location && global.location.search) || '');
                if (urlResolvedTitle) {
                    return urlResolvedTitle;
                }

                var explicitTitle = '';
                if (sourceDoc && sourceDoc.querySelector) {
                    var titleNode = sourceDoc.querySelector('title');
                    explicitTitle = normalizeText(titleNode && titleNode.textContent ? titleNode.textContent : '');
                }

                if (!explicitTitle && sourceDoc !== document && document && document.querySelector) {
                    var liveTitleNode = document.querySelector('title');
                    explicitTitle = normalizeText(liveTitleNode && liveTitleNode.textContent ? liveTitleNode.textContent : '');
                }

                var normalizedSuffix = normalizeText(TITLE_SUFFIX).toLowerCase();
                var lowerExplicitTitle = explicitTitle.toLowerCase();
                var isGenericLegacyTitle = lowerExplicitTitle === normalizedSuffix
                    || lowerExplicitTitle === 'layout base | ' + normalizedSuffix
                    || lowerExplicitTitle === 'configurador de produto'
                    || lowerExplicitTitle === 'configurador de produtos';

                if (explicitTitle && !isGenericLegacyTitle) {
                    return explicitTitle;
                }

                var prioritizeH2 = shouldUseH2(pathname, pageUrl, sourceDoc);
                var headingTag = prioritizeH2 ? 'h2' : 'h1';
                var fallbackHeadingTag = headingTag === 'h1' ? 'h2' : 'h1';
                var headingText = '';
                var selectorTitle = '';

                if (isSelectorDrivenPage(pathname, sourceDoc)) {
                    selectorTitle = selectSelectorProductTitle(sourceDoc);
                    if (!selectorTitle && sourceDoc !== document) {
                        selectorTitle = selectSelectorProductTitle(document);
                    }
                }

                if (selectorTitle) {
                    headingText = selectorTitle;
                }

                if (!headingText) {
                    headingText = selectHeadingText(sourceDoc, headingTag);
                }

                if (!headingText && sourceDoc !== document) {
                    headingText = selectHeadingText(document, headingTag);
                }

                if (!headingText) {
                    headingText = selectHeadingText(sourceDoc, fallbackHeadingTag);
                }

                if (!headingText && sourceDoc !== document) {
                    headingText = selectHeadingText(document, fallbackHeadingTag);
                }

                var nextTitle = buildTitleFromHeading(headingText);
                if (nextTitle) {
                    return nextTitle;
                }

                return '';
            }

            global.__refreshDynamicDocumentTitle = function refreshDynamicDocumentTitle(options) {
                var nextTitle = resolveDynamicTitle(options);
                if (nextTitle && document.title !== nextTitle) {
                    document.title = nextTitle;
                }
                return document.title;
            };

            global.__resolveDynamicDocumentTitle = resolveDynamicTitle;

            global.__refreshDynamicDocumentTitle();

            if (typeof MutationObserver !== 'function') {
                return;
            }

            var rafId = null;
            var scheduleRefresh = function scheduleRefresh() {
                if (rafId !== null || typeof global.requestAnimationFrame !== 'function') {
                    if (rafId === null && typeof global.requestAnimationFrame !== 'function') {
                        global.__refreshDynamicDocumentTitle();
                    }
                    return;
                }
                rafId = global.requestAnimationFrame(function () {
                    rafId = null;
                    global.__refreshDynamicDocumentTitle();
                });
            };

            function shouldReactToMutation(mutation) {
                var target = mutation && mutation.target;
                if (!target) return false;

                if (mutation.type === 'attributes') {
                    return mutation.attributeName === 'data-title'
                        || mutation.attributeName === 'style'
                        || mutation.attributeName === 'class';
                }

                if (target.nodeType === 3 && target.parentElement) {
                    target = target.parentElement;
                }

                if (!target || !target.matches) return false;
                if (target.matches('h1, h2, .galeria-produto, #texto-configurador, title')) {
                    return true;
                }

                return Boolean(target.closest && target.closest('h1, h2, .galeria-produto, #texto-configurador'));
            }

            var observer = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i += 1) {
                    if (shouldReactToMutation(mutations[i])) {
                        scheduleRefresh();
                        break;
                    }
                }
            });
            observer.observe(document.documentElement || document, {
                childList: true,
                characterData: true,
                attributes: true,
                attributeFilter: ['style', 'class', 'data-title'],
                subtree: true
            });
        };
    }

    global.__setupDynamicDocumentTitle();

    if (typeof global.ensureInitialLoader !== 'function') {
        global.ensureInitialLoader = function ensureInitialLoader() {
            if (typeof document === 'undefined') {
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
        };
    }

    global.ensureInitialLoader();
})(typeof window !== 'undefined' ? window : this);
