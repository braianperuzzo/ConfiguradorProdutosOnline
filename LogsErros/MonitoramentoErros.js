if (!window.__monitoramentoErrosAtivo) {
    window.__monitoramentoErrosAtivo = true;
    const endpoint = '/LogsErros/RegistrarErroJS.php';
    const limiteReenvioMs = 4000;
    const traceIdStorageKey = 'trace_id';
    const traceIdCookieName = 'trace_id';
    let traceIdCache = '';
    let ultimoEnvio = 0;
    let ultimaAssinatura = '';
    const analyticsHandler = window.LOGS_ANALYTICS_HANDLER;
    const sdkConfig = window.ERROR_SDK_CONFIG || {};
    const sdkTags = sdkConfig.tags && typeof sdkConfig.tags === 'object' ? sdkConfig.tags : {};

    function normalizeTagValue(value) {
        return typeof value === 'string' ? value.trim() : '';
    }

    function getCookieValue(name) {
        if (!name || !document || !document.cookie) return '';
        const cookies = document.cookie.split(';').map((cookie) => cookie.trim());
        for (const entry of cookies) {
            if (entry.indexOf(name + '=') === 0) {
                return decodeURIComponent(entry.slice(name.length + 1));
            }
        }
        return '';
    }

    function readStorageValue(storage, key) {
        if (!storage) return '';
        try {
            return storage.getItem(key) || '';
        } catch {
            return '';
        }
    }

    function writeStorageValue(storage, key, value) {
        if (!storage) return false;
        try {
            storage.setItem(key, value);
            return true;
        } catch {
            return false;
        }
    }

    function setTraceCookie(traceId) {
        if (!traceId || !document || !document.cookie) return;
        let cookie = `${traceIdCookieName}=${encodeURIComponent(traceId)}; path=/; SameSite=Lax`;
        if (location.protocol === 'https:') {
            cookie += '; Secure';
        }
        document.cookie = cookie;
    }

    function obterTraceId() {
        if (traceIdCache) return traceIdCache;
        let traceId = '';
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
            traceId = gerarTraceId();
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

    function resolveAppVersion() {
        const configVersion = normalizeTagValue(sdkConfig.release || sdkConfig.version);
        if (configVersion) {
            return configVersion;
        }
        const globalVersion = normalizeTagValue(window.APP_VERSION);
        if (globalVersion) {
            return globalVersion;
        }
        if (typeof window.getAppVersion === 'function') {
            return normalizeTagValue(window.getAppVersion());
        }
        return '';
    }

    function resolveAppEnvironment() {
        const configEnv = normalizeTagValue(sdkConfig.environment);
        if (configEnv) {
            return configEnv;
        }
        const candidates = [
            normalizeTagValue(window.APP_ENV),
            normalizeTagValue(window.AMBIENTE),
            normalizeTagValue(window.ENVIRONMENT),
            normalizeTagValue(window.__APP_ENV__)
        ];
        for (const candidate of candidates) {
            if (candidate) {
                return candidate;
            }
        }
        return '';
    }

    function assinaturaErro(payload) {
        return [
            payload.tipo,
            payload.mensagem,
            payload.url,
            payload.linha,
            payload.coluna
        ].join('|');
    }

    function podeEnviar(payload) {
        if (window.LOGS_MONITORING_ENABLED === false) {
            return false;
        }
        if (jaReportado(payload)) {
            return false;
        }
        const agora = Date.now();
        const assinatura = assinaturaErro(payload);
        if (assinatura === ultimaAssinatura && agora - ultimoEnvio < limiteReenvioMs) {
            return false;
        }
        ultimoEnvio = agora;
        ultimaAssinatura = assinatura;
        return true;
    }

    function enviar(payload) {
        if (!podeEnviar(payload)) {
            return;
        }
        marcarReportado(payload);
        try {
            const traceId = payload && payload.trace_id ? payload.trace_id : obterTraceId();
            const requestId = payload && payload.request_id ? payload.request_id : gerarRequestId();
            const headers = { 'Content-Type': 'application/json' };
            if (traceId) {
                headers['X-Trace-Id'] = traceId;
            }
            if (requestId) {
                headers['X-Request-Id'] = requestId;
                payload.request_id = requestId;
            }
            fetch(endpoint, {
                method: 'POST',
                headers,
                body: JSON.stringify(payload),
                keepalive: true,
                credentials: 'same-origin'
            });
        } catch {
            // ignora falhas de envio para não causar loops
        }
        replicarAnalytics(payload);
    }

    function registrarErroBasico(dados) {
        if (!dados || typeof dados !== 'object') {
            return;
        }
        const mensagemBase = dados.mensagem || dados.msg || 'Erro capturado';
        const payload = {
            ...basePayload(),
            tipo: (dados.tipo || 'erro_basico').toString().slice(0, 100),
            nivel: 'error',
            mensagem: mensagemBase.toString().slice(0, 300),
            url: (dados.url || window.location.href).toString().slice(0, 300),
            linha: Number(dados.linha || 0),
            coluna: Number(dados.coluna || 0),
            stack: dados.stack ? String(dados.stack).slice(0, 1000) : ''
        };
        payload.trace_id = obterTraceId();
        payload.error_fingerprint = gerarFingerprint(payload);
        enviar(payload);
    }

    window.registrarErroBasico = registrarErroBasico;

    function basePayload() {
        const appVersion = resolveAppVersion();
        const environment = resolveAppEnvironment();
        return {
            trace_id: obterTraceId(),
            url: window.location.href,
            userAgent: navigator.userAgent,
            timestamp: new Date().toISOString(),
            versao: appVersion || undefined,
            ambiente: environment || undefined,
            contexto: {
                viewport: {
                    largura: window.innerWidth,
                    altura: window.innerHeight
                },
                idioma: navigator.language,
                tags: Object.assign({}, sdkTags, {
                    app_version: appVersion || undefined,
                    environment: environment || undefined
                })
            }
        };
    }

    function gerarTraceId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return `trace-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    function gerarRequestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '');
        }
        return `req-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    function gerarFingerprint(payload) {
        return assinaturaErro(payload);
    }

    function jaReportado(payload) {
        const registro = window.__jsErrorReported;
        if (!registro) {
            return false;
        }
        if (registro.fingerprint !== payload.error_fingerprint) {
            return false;
        }
        return Date.now() - registro.timestamp < limiteReenvioMs;
    }

    function marcarReportado(payload) {
        window.__jsErrorReported = {
            fingerprint: payload.error_fingerprint,
            timestamp: Date.now()
        };
    }

    function replicarAnalytics(payload) {
        if (!analyticsHandler || typeof analyticsHandler !== 'function') {
            return;
        }
        if (window.LOGS_ANALYTICS_ENABLED === false) {
            return;
        }
        analyticsHandler({
            ...payload,
            origem: 'backend'
        });
    }

    window.addEventListener('error', function (evento) {
        const payload = {
            ...basePayload(),
            tipo: 'window.onerror',
            nivel: 'error',
            mensagem: (evento.message || 'Erro JavaScript').toString(),
            url: evento.filename || window.location.href,
            linha: Number(evento.lineno || 0),
            coluna: Number(evento.colno || 0),
            stack: evento.error && evento.error.stack ? String(evento.error.stack).slice(0, 1000) : ''
        };
        payload.trace_id = obterTraceId();
        payload.error_fingerprint = gerarFingerprint(payload);
        enviar(payload);
    });

    window.addEventListener('unhandledrejection', function (evento) {
        const motivo = evento.reason;
        let mensagem = 'Unhandled promise rejection';
        let stack = '';
        if (motivo) {
            if (typeof motivo === 'string') {
                mensagem = motivo;
            } else if (typeof motivo === 'object') {
                mensagem = motivo.message || mensagem;
                if (motivo.stack) {
                    stack = String(motivo.stack).slice(0, 1000);
                }
            }
        }
        const payload = {
            ...basePayload(),
            tipo: 'unhandledrejection',
            nivel: 'error',
            mensagem: mensagem.toString(),
            url: window.location.href,
            linha: 0,
            coluna: 0,
            stack
        };
        payload.trace_id = obterTraceId();
        payload.error_fingerprint = gerarFingerprint(payload);
        enviar(payload);
    });

    window.addEventListener('error', function (evento) {
        const alvo = evento && evento.target;
        if (!alvo || alvo === window || alvo === document) {
            return;
        }

        const tag = alvo.tagName ? String(alvo.tagName).toLowerCase() : 'desconhecido';
        const source = alvo.src || alvo.href || '';
        if (!source) {
            return;
        }

        registrarErroBasico({
            tipo: 'resource_error',
            mensagem: `Falha ao carregar recurso (${tag})`,
            url: source,
            stack: `pagina:${window.location.href}`
        });
    }, true);
}
