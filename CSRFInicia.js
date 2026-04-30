if (window.csrfSetup === undefined) {
    window.csrfSetup = true;
    window.csrfToken = null;

    async function parseJSON(response) {
        try {
            const text = await response.text();
            return text ? JSON.parse(text) : null;
        } catch {
            return null;
        }
    }

    let tokenPromise = fetch("/CSRFPega.php")
        .then(parseJSON)
        .then(data => {
            if (data && data.token) {
                window.csrfToken = data.token;
            }
        })
        .catch(() => { });

    const originalFetch = window.fetch.bind(window);

    const unsafeMethods = new Set(["POST", "PUT", "PATCH", "DELETE"]);

    function isSameOriginRequest(resource) {
        try {
            const url = resource instanceof Request ? resource.url : resource;
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch {
            return false;
        }
    }

    window.fetch = async function (resource, options = {}) {
        const method = (options.method || "GET").toUpperCase();
        if (options && unsafeMethods.has(method) && isSameOriginRequest(resource)) {
            options.headers = options.headers || {};
            if (!window.csrfToken) {
                try {
                    await tokenPromise;
                } catch { }
            }

            if (window.csrfToken) {
                if (options.headers instanceof Headers) {
                    options.headers.append("X-CSRF-Token", window.csrfToken);
                } else {
                    options.headers["X-CSRF-Token"] = window.csrfToken;
                }
            }
        }
        return originalFetch(resource, options);
    };
}
