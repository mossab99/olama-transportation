/*
 * Read-only browser-side PWA diagnostic.
 * Paste into Safari Web Inspector Console while viewing the affected origin.
 * It does not unregister workers or delete caches.
 */
(async function olamaPwaBrowserDiagnostics() {
    const result = {
        generatedAt: new Date().toISOString(),
        page: location.href,
        online: navigator.onLine,
        secureContext: window.isSecureContext,
        controller: null,
        registrations: [],
        caches: [],
        tests: [],
    };

    if (!('serviceWorker' in navigator)) {
        result.serviceWorker = 'unsupported';
        console.log(JSON.stringify(result, null, 2));
        return result;
    }

    const controller = navigator.serviceWorker.controller;
    result.controller = controller ? {
        scriptURL: controller.scriptURL,
        state: controller.state,
    } : null;

    const registrations = await navigator.serviceWorker.getRegistrations();
    for (const registration of registrations) {
        let navigationPreload = null;
        try {
            navigationPreload = registration.navigationPreload
                ? await registration.navigationPreload.getState()
                : null;
        } catch (error) {
            navigationPreload = { error: String(error) };
        }
        result.registrations.push({
            scope: registration.scope,
            active: registration.active && {
                scriptURL: registration.active.scriptURL,
                state: registration.active.state,
            },
            waiting: registration.waiting && {
                scriptURL: registration.waiting.scriptURL,
                state: registration.waiting.state,
            },
            installing: registration.installing && {
                scriptURL: registration.installing.scriptURL,
                state: registration.installing.state,
            },
            navigationPreload,
        });
    }

    if ('caches' in window) {
        for (const name of await caches.keys()) {
            const cache = await caches.open(name);
            const requests = await cache.keys();
            result.caches.push({
                name,
                entries: requests.length,
                sample: requests.slice(0, 50).map(request => request.url),
            });
        }
    }

    const testUrls = [
        location.href,
        new URL('/', location.origin).href,
        new URL('/wp-json/', location.origin).href,
    ];
    for (const url of [...new Set(testUrls)]) {
        const started = performance.now();
        try {
            const response = await fetch(url, {
                cache: 'no-store',
                credentials: 'same-origin',
                redirect: 'follow',
                headers: { 'X-Olama-PWA-Diagnostic': '1' },
            });
            result.tests.push({
                url,
                ok: response.ok,
                status: response.status,
                type: response.type,
                redirected: response.redirected,
                finalUrl: response.url,
                elapsedMs: Math.round(performance.now() - started),
                cacheControl: response.headers.get('cache-control'),
                age: response.headers.get('age'),
                serverCache: response.headers.get('cf-cache-status')
                    || response.headers.get('x-cache')
                    || response.headers.get('x-litespeed-cache'),
            });
        } catch (error) {
            result.tests.push({
                url,
                ok: false,
                elapsedMs: Math.round(performance.now() - started),
                error: String(error),
            });
        }
    }

    console.log(JSON.stringify(result, null, 2));
    return result;
}());

