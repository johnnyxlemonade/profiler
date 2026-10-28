/*!
 * Lemonade Profiler
 *
 * Observes profiled Fetch and XHR responses without changing requests.
 *
 * @link      https://lemonadeframework.cz/
 * @author    Honza Mudrak <honzamudrak@gmail.com>
 * @copyright Copyright (c) 2026 Honza Mudrak. All rights reserved.
 * @license   Apache-2.0 - see LICENSE
 */
const prefix = '/_lemonade/profiler';

export class RequestMonitor {
    #ids = [];
    #onRecord;

    constructor(onRecord) {
        this.#onRecord = onRecord;
    }

    eligible(value) {
        try {
            // Do not coerce user objects or read request options a second time.
            const raw = typeof value === 'string' ? value : value instanceof URL ? value.href : value instanceof Request ? value.url : null;
            if (raw === null) return false;
            const url = new URL(raw, document.baseURI);
            return url.origin === location.origin && !url.pathname.startsWith(prefix);
        } catch {
            return false;
        }
    }

    record(url, getHeader) {
        try {
            if (typeof url !== 'string' || !url || !this.eligible(url)) return;
            const path = getHeader('X-Lemonade-Profiler');
            if (!/^\/_lemonade\/profiler\/request\/[a-f0-9]{32}$/.test(path || '')) return;
            const encoded = getHeader('X-Lemonade-Profiler-Metrics');
            if (!encoded || encoded.length > 16384) return;
            const data = JSON.parse(decodeURIComponent(encoded));
            if (path !== `${prefix}/request/${data.id}` || !/^[A-Z]{1,16}$/.test(data.method)) return;
            if (this.#ids.includes(data.id)) return;
            if (!Number.isInteger(data.status) || data.status < 100 || data.status > 599) return;
            if (!['duration_ms', 'peak_bytes', 'sql_count', 'sql_ms'].every(name => typeof data[name] === 'number' && Number.isFinite(data[name]) && data[name] >= 0)) return;
            this.#ids.push(data.id);
            if (this.#ids.length > 20) this.#ids.shift();
            this.#onRecord(data, path, this.#ids.length);
        } catch {
            // Diagnostics must never affect application requests.
        }
    }

    install() {
        this.#installFetch();
        this.#installXhr();
    }

    #installFetch() {
        const nativeFetch = window.fetch;
        if (typeof nativeFetch !== 'function') return;
        const monitor = this;
        window.fetch = function (...args) {
            const response = Reflect.apply(nativeFetch, this, args);
            if (!monitor.eligible(args[0])) return response;
            return response.then(result => {
                monitor.record(result.url, name => result.headers.get(name));
                return result;
            });
        };
    }

    #installXhr() {
        const prototype = XMLHttpRequest.prototype;
        const nativeOpen = prototype.open;
        const nativeSend = prototype.send;
        const pending = new WeakMap();
        const monitor = this;
        prototype.open = function (...args) {
            const result = Reflect.apply(nativeOpen, this, args);
            const old = pending.get(this);
            if (old?.listener) this.removeEventListener('loadend', old.listener);
            pending.set(this, {track: monitor.eligible(args[1])});
            return result;
        };
        prototype.send = function (...args) {
            const state = pending.get(this);
            if (state?.track && !state.listener) {
                state.listener = () => monitor.record(this.responseURL, name => this.getResponseHeader(name));
                this.addEventListener('loadend', state.listener, {once: true});
            }
            return Reflect.apply(nativeSend, this, args);
        };
    }
}
