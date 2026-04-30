(function () {
    if (typeof window === 'undefined') {
        return;
    }

    var friendlyCodeMap = {
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

    var reverseFriendlyCodeMap = Object.create(null);
    Object.keys(friendlyCodeMap).forEach(function (lineCode) {
        var slug = friendlyCodeMap[lineCode];
        if (slug) {
            reverseFriendlyCodeMap[slug.toUpperCase()] = lineCode;
        }
    });

    var fallbackHomeMetadata = {
        title: 'Configurador de Produtos IBR',
        description: 'Ferramenta online para configuração de redutores, motorredutores e motores das diversas linhas IBR.',
        canonicalUrl: 'https://configurador.redutoresibr.com.br/',
        socialImage: 'https://configurador.redutoresibr.com.br/Imagens/LogotipoAplicativo512512.png'
    };

    function getHomeMetadata() {
        var runtimeMetadata = window.__SEO_HOME_METADATA__;
        if (runtimeMetadata && typeof runtimeMetadata === 'object') {
            return runtimeMetadata;
        }
        return fallbackHomeMetadata;
    }

    function getLineMetadataMap() {
        var runtimeMap = window.__SEO_LINE_METADATA__;
        if (runtimeMap && typeof runtimeMap === 'object') {
            return runtimeMap;
        }
        return {};
    }

    var documentElement = document.documentElement || null;

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
        for (var i = 0; i < names.length; i++) {
            var candidate = window[names[i]];
            if (typeof candidate === 'string' && (candidate || allowEmpty)) {
                return candidate;
            }
        }
        return null;
    }

    var dataCanonicalOrigin = readDataAttribute('data-canonical-origin');
    var trackingQueryParams = {
        utm_source: true,
        utm_medium: true,
        utm_campaign: true,
        utm_term: true,
        utm_content: true,
        gclid: true,
        fbclid: true,
        msclkid: true,
        dclid: true
    };

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
        if (dataCanonicalOrigin) {
            return dataCanonicalOrigin;
        }
        return defaultOrigin;
    }

    function sanitizeSearch(search) {
        if (!search || search === '?') {
            return '';
        }
        var trimmed = search.charAt(0) === '?' ? search.slice(1) : search;
        if (!trimmed) {
            return '';
        }
        var params = new URLSearchParams(trimmed);
        var keysToRemove = [];
        params.forEach(function (value, key) {
            if (trackingQueryParams[(key || '').toLowerCase()]) {
                keysToRemove.push(key);
            }
        });
        for (var keyIndex = 0; keyIndex < keysToRemove.length; keyIndex++) {
            params.delete(keysToRemove[keyIndex]);
        }
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

    function ensureLeadingSlash(pathname) {
        if (!pathname) {
            return '/';
        }
        return pathname.charAt(0) === '/' ? pathname : '/' + pathname;
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
        } catch (err) {
            return search || '';
        }
    }

    function resolveConfiguradorPath(pathname, search) {
        try {
            var rawSearch = typeof search === 'string' ? search : '';
            var params = new URLSearchParams(rawSearch && rawSearch.charAt(0) === '?' ? rawSearch.slice(1) : rawSearch);
            var totalParams = 0;
            params.forEach(function () {
                totalParams += 1;
            });
            var entries = params.entries();
            var current = entries.next();
            while (!current.done) {
                var value = current.value[1];
                if (value) {
                    var normalizedCode = value.toUpperCase();
                    var slug = friendlyCodeMap[normalizedCode];
                    if (slug) {
                        var resolvedSearch = typeof search === 'string' ? search : '';
                        if (totalParams === 1) {
                            var canonicalCode = reverseFriendlyCodeMap[(slug || '').toUpperCase()];
                            if (canonicalCode && canonicalCode === value.toUpperCase()) {
                                resolvedSearch = '';
                            }
                        }
                        return {
                            pathname: ensureLeadingSlash('Configurador' + slug),
                            search: resolvedSearch
                        };
                    }
                }
                current = entries.next();
            }

            var pathnameMatch = (typeof pathname === 'string' ? pathname : '').match(/\/Configurador([^/?#]+)/i);
            if (pathnameMatch && pathnameMatch[1]) {
                var resolvedLineCode = reverseFriendlyCodeMap[pathnameMatch[1].toUpperCase()];
                if (resolvedLineCode) {
                    return {
                        pathname: ensureLeadingSlash('Configurador' + friendlyCodeMap[resolvedLineCode]),
                        search: ''
                    };
                }
            }
        } catch (err) {
            // ignore lookup errors
        }
        return {
            pathname: ensureLeadingSlash(pathname),
            search: typeof search === 'string' ? search : ''
        };
    }

    function buildCanonicalHref(origin, pathname, search) {
        var resolvedOrigin = origin;
        if (!resolvedOrigin || resolvedOrigin === 'null') {
            resolvedOrigin = window.location.protocol + '//' + window.location.host;
        }
        var sanitizedSearch = sanitizeSearch(search || '');
        var resolved = resolveConfiguradorPath(pathname, sanitizedSearch);
        var resolvedPathname = typeof resolved === 'string' ? resolved : resolved.pathname;
        var resolvedSearch = typeof resolved === 'string' ? '' : resolved.search;
        return resolvedOrigin + ensureLeadingSlash(resolvedPathname || pathname || '/') + (resolvedSearch || '');
    }

    function applyInitialCanonicalOverride() {
        var hasInitialPath = initialPathnameOverride && initialPathnameOverride.hasOverride;
        var hasInitialSearch = initialSearchOverride && initialSearchOverride.hasOverride;
        var resolvedOrigin = resolveCanonicalOrigin(window.location.origin);
        if (!hasInitialPath && !hasInitialSearch && resolvedOrigin === window.location.origin) {
            return;
        }
        try {
            var href = buildCanonicalHref(
                resolvedOrigin,
                consumeInitialOverride(initialPathnameOverride, window.location.pathname),
                consumeInitialOverride(initialSearchOverride, window.location.search)
            );
            applyCanonical(href);
        } catch (err) {
            // ignore override errors to avoid breaking rendering
        }
    }

    function applyCanonical(href) {
        if (!href) {
            return;
        }
        var head = document.head || document.getElementsByTagName('head')[0];
        if (!head) {
            return;
        }
        var link = head.querySelector('link[rel="canonical"]');
        if (link) {
            link.setAttribute('href', href);
        }
        var ogUrlMeta = head.querySelector('meta[property="og:url"]');
        if (ogUrlMeta) {
            ogUrlMeta.setAttribute('content', href);
        }
        var twitterUrlMeta = head.querySelector('meta[name="twitter:url"]');
        if (twitterUrlMeta) {
            twitterUrlMeta.setAttribute('content', href);
        }
        var alternates = head.querySelectorAll('link[rel="alternate"][hreflang]');
        for (var i = 0; i < alternates.length; i++) {
            alternates[i].setAttribute('href', href);
        }

        applyDynamicSeoMetadata(href);
    }

    function setMetaContent(selector, value) {
        if (!selector || typeof value !== 'string') {
            return;
        }
        var element = document.head ? document.head.querySelector(selector) : null;
        if (!element) {
            return;
        }
        element.setAttribute('content', value);
    }

    function normalizeLineCode(candidate) {
        var lineMetadataMap = getLineMetadataMap();
        if (!candidate || typeof candidate !== 'string') {
            return null;
        }
        var trimmed = candidate.trim();
        if (!trimmed) {
            return null;
        }
        var upper = trimmed.toUpperCase();
        if (lineMetadataMap[upper]) {
            return upper;
        }
        if (reverseFriendlyCodeMap[upper]) {
            return reverseFriendlyCodeMap[upper];
        }
        return null;
    }

    function resolveSelectedLineCode(url) {
        if (!url) {
            return null;
        }

        var pathname = typeof url.pathname === 'string' ? url.pathname : '';
        var search = typeof url.search === 'string' ? url.search : '';

        if (search) {
            try {
                var params = new URLSearchParams(search.charAt(0) === '?' ? search.slice(1) : search);
                var entries = params.entries();
                var current = entries.next();
                while (!current.done) {
                    var value = current.value[1];
                    var resolvedFromParam = normalizeLineCode(value);
                    if (resolvedFromParam) {
                        return resolvedFromParam;
                    }
                    current = entries.next();
                }
            } catch (err) {
                // ignore parsing errors
            }
        }

        var pathnameMatch = pathname.match(/\/Configurador([^/?#]+)/i);
        if (pathnameMatch && pathnameMatch[1]) {
            var slug = pathnameMatch[1].toUpperCase();
            if (reverseFriendlyCodeMap[slug]) {
                return reverseFriendlyCodeMap[slug];
            }
        }

        return null;
    }

    function buildSeoMetadataForSelection(lineCode) {
        var homeMetadata = getHomeMetadata();
        var lineMetadataMap = getLineMetadataMap();
        if (!lineCode || !lineMetadataMap[lineCode]) {
            return {
                title: homeMetadata.title,
                description: homeMetadata.description,
                socialImage: homeMetadata.socialImage,
                canonicalUrl: homeMetadata.canonicalUrl,
                lineCode: null,
                commercialName: null
            };
        }

        var lineMetadata = lineMetadataMap[lineCode];
        var title = 'Configurador de Produtos IBR';
        var description = lineMetadata.commercialName + ': ' + lineMetadata.benefit;

        return {
            title: title,
            description: description,
            socialImage: lineMetadata.socialImage || homeMetadata.socialImage,
            canonicalUrl: lineMetadata.canonicalUrl,
            lineCode: lineCode,
            commercialName: lineMetadata.commercialName
        };
    }

    function applyDynamicSeoMetadata(canonicalHref) {
        var resolvedUrl;
        try {
            resolvedUrl = new URL(canonicalHref || window.location.href, window.location.origin);
        } catch (err) {
            return;
        }

        var lineCode = resolveSelectedLineCode(resolvedUrl);
        var metadata = buildSeoMetadataForSelection(lineCode);

        if (typeof window.__refreshDynamicDocumentTitle === 'function') {
            window.__refreshDynamicDocumentTitle();
        }
        setMetaContent('meta[name="description"]', metadata.description);
        setMetaContent('meta[property="og:title"]', metadata.title);
        setMetaContent('meta[property="og:description"]', metadata.description);
        setMetaContent('meta[property="og:url"]', canonicalHref || resolvedUrl.href);
        setMetaContent('meta[property="og:image"]', metadata.socialImage);
        setMetaContent('meta[name="twitter:title"]', metadata.title);
        setMetaContent('meta[name="twitter:description"]', metadata.description);
        setMetaContent('meta[name="twitter:url"]', canonicalHref || resolvedUrl.href);
        setMetaContent('meta[name="twitter:image"]', metadata.socialImage);

        if (metadata.lineCode && metadata.canonicalUrl && canonicalHref) {
            try {
                var expectedCanonical = new URL(metadata.canonicalUrl, resolvedUrl.origin);
                var finalCanonical = new URL(canonicalHref, resolvedUrl.origin);
                var expectedPath = ensureLeadingSlash(expectedCanonical.pathname);
                var finalPath = ensureLeadingSlash(finalCanonical.pathname);
                if (expectedPath !== finalPath) {
                    console.warn('SEO metadata: canonical da linha divergente do canonical final.', {
                        lineCode: metadata.lineCode,
                        expectedCanonical: metadata.canonicalUrl,
                        finalCanonical: canonicalHref
                    });
                }
            } catch (err) {
                // ignore consistency warning failures
            }
        }
    }

    function updateCanonicalFromLocation(targetUrl) {
        try {
            var url = targetUrl ? new URL(targetUrl, window.location.origin) : window.location;
            var href = buildCanonicalHref(
                resolveCanonicalOrigin(url.origin),
                url.pathname,
                url.search
            );
            applyCanonical(href);
        } catch (err) {
        }
    }

    if (typeof window.__sanitizeCanonicalSearch !== 'function') {
        window.__sanitizeCanonicalSearch = sanitizeSearch;
    }

    if (typeof window.__resolveConfiguradorCanonicalPath !== 'function') {
        window.__resolveConfiguradorCanonicalPath = resolveConfiguradorPath;
    }

    applyInitialCanonicalOverride();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            updateCanonicalFromLocation();
        });
    } else {
        updateCanonicalFromLocation();
    }

    window.addEventListener('spa:navigation', function (event) {
        var nextUrl = event && event.detail && typeof event.detail.url === 'string'
            ? event.detail.url
            : null;
        updateCanonicalFromLocation(nextUrl);
    });
})();
