(function initializeThemePreference(global) {
    if (!global || typeof global !== 'object' || !global.document) {
        return;
    }

    const doc = global.document;
    const existingPreference = global.themePreference || {};
    const existingGet = typeof existingPreference.getSavedTheme === 'function'
        ? existingPreference.getSavedTheme
        : typeof global.getSavedTheme === 'function'
            ? global.getSavedTheme
            : null;
    const existingApply = typeof existingPreference.applySavedTheme === 'function'
        ? existingPreference.applySavedTheme
        : typeof global.applySavedTheme === 'function'
            ? global.applySavedTheme
            : null;

    if (existingGet && existingApply) {
        global.themePreference = Object.assign({}, existingPreference, {
            getSavedTheme: existingGet,
            applySavedTheme: existingApply
        });

        existingApply(doc);
        doc.addEventListener('DOMContentLoaded', () => existingApply(doc));
        global.addEventListener('pageshow', () => existingApply(doc));
        return;
    }

    const themeCookieName = global.THEME_COOKIE_NAME || 'modoEscuro';
    const themeCookieMaxAge = typeof global.THEME_COOKIE_MAX_AGE === 'number'
        ? global.THEME_COOKIE_MAX_AGE
        : 60 * 60 * 24 * 365;
    const themeCookieDomain = global.THEME_COOKIE_DOMAIN || '.redutoresibr.com.br';

    function safeLocalStorageGetItem(key) {
        try {
            return global.localStorage.getItem(key);
        } catch {
            return null;
        }
    }

    function fallbackGetThemeCookie() {
        if (!doc || typeof doc.cookie !== 'string') {
            return null;
        }

        const cookies = doc.cookie.split(';').map(row => row.trim()).filter(Boolean);
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
        if (!doc) {
            return;
        }

        const encodedValue = encodeURIComponent(value);
        const baseAttributes = `; path=/; max-age=${themeCookieMaxAge}; SameSite=Lax`;
        const domainCookie = `${themeCookieName}=${encodedValue}${baseAttributes}; domain=${themeCookieDomain}`;

        try {
            doc.cookie = domainCookie;
            if (fallbackGetThemeCookie() !== value) {
                doc.cookie = `${themeCookieName}=${encodedValue}${baseAttributes}`;
            }
        } catch {
            doc.cookie = `${themeCookieName}=${encodedValue}${baseAttributes}`;
        }
    }

    const getThemeCookieValue = typeof global.getThemeCookie === 'function'
        ? global.getThemeCookie
        : fallbackGetThemeCookie;

    const setThemeCookieValue = typeof global.setThemeCookie === 'function'
        ? global.setThemeCookie
        : fallbackSetThemeCookie;

    function prefersSystemDarkMode() {
        const target = typeof global.matchMedia === 'function'
            ? global
            : doc && doc.defaultView && typeof doc.defaultView.matchMedia === 'function'
                ? doc.defaultView
                : null;
        if (target) {
            try {
                return target.matchMedia('(prefers-color-scheme: dark)').matches;
            } catch { }
        }
        return false;
    }

    function getSavedTheme() {
        const cookieValue = getThemeCookieValue();
        if (cookieValue !== null) {
            return cookieValue;
        }

        let storedValue = null;
        try {
            if (global.LGPDCOOKIES && typeof global.LGPDCOOKIES.getValueCookie === 'function') {
                storedValue = global.LGPDCOOKIES.getValueCookie(themeCookieName);
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

    function applySavedTheme(targetDoc) {
        const docRef = targetDoc || doc;
        if (!docRef) {
            return;
        }

        const pref = getSavedTheme();
        const meta = docRef.querySelector('meta[name="color-scheme"]');
        const isDark = pref === '1';

        docRef.documentElement.classList.toggle('dark-mode', isDark);
        if (docRef.body) {
            docRef.body.classList.toggle('dark-mode', isDark);
        }
        if (meta) {
            meta.setAttribute('content', isDark ? 'dark' : 'light');
        }
    }

    const api = {
        getSavedTheme,
        applySavedTheme,
        getThemeCookieValue,
        setThemeCookieValue
    };

    global.themePreference = Object.assign({}, existingPreference, api);

    if (typeof global.getSavedTheme !== 'function') {
        global.getSavedTheme = getSavedTheme;
    }
    if (typeof global.applySavedTheme !== 'function') {
        global.applySavedTheme = applySavedTheme;
    }
    if (typeof global.getThemeCookie !== 'function') {
        global.getThemeCookie = getThemeCookieValue;
    }
    if (typeof global.setThemeCookie !== 'function') {
        global.setThemeCookie = setThemeCookieValue;
    }
    if (typeof global.THEME_COOKIE_NAME === 'undefined') {
        global.THEME_COOKIE_NAME = themeCookieName;
    }
    if (typeof global.THEME_COOKIE_MAX_AGE === 'undefined') {
        global.THEME_COOKIE_MAX_AGE = themeCookieMaxAge;
    }
    if (typeof global.THEME_COOKIE_DOMAIN === 'undefined') {
        global.THEME_COOKIE_DOMAIN = themeCookieDomain;
    }

    applySavedTheme(doc);
    doc.addEventListener('DOMContentLoaded', () => applySavedTheme(doc));
    global.addEventListener('pageshow', () => applySavedTheme(doc));
})(typeof window !== 'undefined' ? window : this);