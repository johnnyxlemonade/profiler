/*!
 * Lemonade Profiler
 *
 * Renders and manages the Shadow DOM toolbar and its panels.
 *
 * @link      https://lemonadeframework.cz/
 * @author    Honza Mudrak <honzamudrak@gmail.com>
 * @copyright Copyright (c) 2026 Honza Mudrak. All rights reserved.
 * @license   Apache-2.0 - see LICENSE
 */
import {icon} from './icons.js';
import {ms, memory} from './format.js';
import {RequestMonitor} from './request-monitor.js';

const prefix = '/_lemonade/profiler';
const titles = {HTTP: 'panels.http.title', Time: 'panels.time.title', Memory: 'panels.memory.title', SQL: 'panels.sql.title', Requests: 'panels.requests.title', PHP: 'panels.php.title', Server: 'panels.server.title', More: 'panels.more.title'};

export class ProfilerToolbar {
    #current;
    #host;
    #root;
    #metrics;
    #active = null;
    #suppressFocusOpen = false;
    #requestPanel;
    #requestTotal;
    #requestList;
    #requestEmpty;
    #morePanel;
    #locale;
    #records = [];
    #nav;
    #languageButtons = {};

    constructor(current) {
        this.#current = current;
        const page = document.documentElement.lang?.split('-')[0]?.toLowerCase();
        const browser = typeof navigator === 'undefined' ? '' : (navigator.languages ?? [navigator.language])
            .map(language => language?.split('-')[0]?.toLowerCase()).find(language => ['cs', 'en'].includes(language));
        this.#locale = ['cs', 'en'].includes(page) ? page : ['cs', 'en'].includes(browser) ? browser : 'en';
    }

    #t(key, parameters = {}) {
        let message = this.#current.i18n?.[this.#locale]?.[key] ?? this.#current.i18n?.en?.[key] ?? key;
        for (const [name, value] of Object.entries(parameters)) message = message.replaceAll(`{${name}}`, String(value));
        return message;
    }

    #localizedUrl(href) {
        if (!href.startsWith(prefix)) return href;
        const [path, fragment] = href.split('#', 2);
        return `${path}?lang=${this.#locale}${fragment ? `#${fragment}` : ''}`;
    }

    #switchLocale(locale, mobile = false) {
        if (locale === this.#locale) return;
        this.#close();
        this.#locale = locale;
        this.#nav.remove();
        this.#render();
        for (const [index, record] of this.#records.entries()) this.#renderRequest(record.data, record.path, index + 1);
        if (mobile) this.#open(this.#morePanel);
        this.#languageButtons[mobile ? 'mobile' : 'desktop']?.[locale]?.focus();
    }

    mount() {
        this.#host = document.createElement('lemonade-profiler');
        this.#host.hidden = true;
        this.#root = this.#host.attachShadow({mode: 'closed'});
        this.#render();
        this.#listen();
        const css = this.#node('link', null, this.#root);
        css.rel = 'stylesheet';
        css.href = `${prefix}/assets/toolbar.css`;
        css.addEventListener('load', () => {
            this.#host.hidden = false;
            try {
                new RequestMonitor((data, path, count) => this.#addRequest(data, path, count)).install();
            } catch {
                // Locked browser APIs must not affect the host application.
            }
        }, {once: true});
        css.addEventListener('error', () => this.#host.remove(), {once: true});
        document.documentElement.append(this.#host);
    }

    #node(tag, text = null, parent = null, className = '') {
        const element = document.createElement(tag);
        if (text != null) element.textContent = text;
        if (className) element.className = className;
        if (parent) parent.append(element);
        return element;
    }

    #link(text, href, parent) {
        const element = this.#node('a', text, parent);
        element.href = this.#localizedUrl(href);
        element.referrerPolicy = 'no-referrer';
        return element;
    }

    #render() {
        const nav = this.#node('nav', null, this.#root);
        this.#nav = nav;
        nav.setAttribute('aria-label', 'Lemonade Profiler');
        const content = this.#node('div', null, nav, 'toolbar-content');
        const brand = this.#link('', this.#profileUrl(), content);
        brand.className = 'brand';
        brand.setAttribute('aria-label', this.#t('toolbar.open_profile'));
        const logo = this.#node('img', null, brand);
        logo.src = `${prefix}/assets/symbol.svg`;
        logo.alt = '';
        logo.width = 32;
        logo.height = 32;
        this.#node('span', 'Lemonade Profiler', brand, 'brand-label');
        this.#metrics = this.#node('div', null, content, 'toolbar-metrics');
        this.#renderMetrics();
        const languages = this.#node('div', null, content, 'toolbar-language');
        this.#languageSwitch(languages, 'desktop');
        const history = this.#link('', prefix, content);
        history.className = 'history';
        history.setAttribute('aria-label', this.#t('toolbar.history'));
        history.title = this.#t('toolbar.history');
        icon('History', history);
        this.#node('span', this.#t('toolbar.history_short'), history);
    }

    #languageSwitch(parent, placement) {
        parent.setAttribute('role', 'group');
        parent.setAttribute('aria-label', this.#t('common.language'));
        this.#languageButtons[placement] = {};
        for (const locale of ['cs', 'en']) {
            const button = this.#node('button', locale.toUpperCase(), parent);
            button.type = 'button';
            button.lang = locale;
            button.setAttribute('aria-label', `${this.#t('common.language')}: ${locale.toUpperCase()}`);
            if (locale === this.#locale) button.setAttribute('aria-current', 'true');
            button.addEventListener('click', () => this.#switchLocale(locale, placement === 'mobile'));
            this.#languageButtons[placement][locale] = button;
        }
    }

    #metric(name, value, shortValue = value, tinyValue = shortValue) {
        const group = this.#node('div', null, this.#metrics, `metric ${name}`);
        const button = this.#node('button', null, group);
        icon(name, button);
        const full = this.#node('span', value, button, 'full');
        const short = this.#node('span', shortValue, button, 'short');
        const tiny = this.#node('span', tinyValue, button, 'tiny');
        button.type = 'button';
        const label = this.#t(`toolbar.metric.${name.toLowerCase()}`);
        const accessibleLabel = value ? this.#t('toolbar.metric_value', {name: label, value}) : label;
        button.setAttribute('aria-label', accessibleLabel);
        button.title = accessibleLabel;
        button.setAttribute('aria-expanded', 'false');
        const panel = this.#node('section', null, group, 'popover');
        panel.id = `lemonade-${name}`;
        panel.hidden = true;
        panel.setAttribute('aria-label', label);
        button.setAttribute('aria-controls', panel.id);
        const item = {button, panel, full, short, tiny};
        button.addEventListener('click', () => this.#open(item));
        button.addEventListener('focus', () => {
            if (!this.#suppressFocusOpen) this.#open(item);
        });
        let leaveTimer;
        group.addEventListener('pointerenter', event => {
            clearTimeout(leaveTimer);
            if (event.pointerType === 'mouse') this.#open(item);
        });
        group.addEventListener('pointerleave', () => {
            clearTimeout(leaveTimer);
            leaveTimer = setTimeout(() => {
                if (this.#active === item && !group.contains(this.#root.activeElement)) this.#close();
            }, 120);
        });
        group.addEventListener('focusout', () => queueMicrotask(() => {
            if (this.#active === item && !group.contains(this.#root.activeElement)) this.#close();
        }));
        const heading = this.#node('div', null, panel, 'panel-heading');
        const badge = this.#node('span', null, heading, 'panel-icon');
        icon(name, badge);
        this.#node('h2', this.#t(titles[name]), heading);
        const dismiss = this.#node('button', null, heading, 'dismiss');
        dismiss.type = 'button';
        dismiss.setAttribute('aria-label', this.#t('toolbar.close_panel', {name: label}));
        icon('Close', dismiss);
        dismiss.addEventListener('click', () => this.#close(true));
        return item;
    }

    #open(item) {
        if (this.#active === item) return;
        this.#close();
        this.#active = item;
        item.panel.hidden = false;
        item.button.setAttribute('aria-expanded', 'true');
        this.#position(item);
    }

    #close(restore = false) {
        if (!this.#active) return;
        const previous = this.#active;
        this.#active = null;
        previous.panel.hidden = true;
        previous.button.setAttribute('aria-expanded', 'false');
        if (restore || previous.panel.contains(this.#root.activeElement)) {
            this.#suppressFocusOpen = true;
            (previous.returnFocus || previous.button).focus();
            this.#suppressFocusOpen = false;
        }
        previous.returnFocus = null;
    }

    #position(item) {
        const button = item.button.getBoundingClientRect();
        const panel = item.panel.getBoundingClientRect();
        const hostBounds = this.#host.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const margin = 8;
        const centered = button.left + button.width / 2 - panel.width / 2;
        const left = Math.max(margin, Math.min(centered, viewportWidth - panel.width - margin));
        item.panel.style.setProperty('--panel-left', `${left - hostBounds.left}px`);
    }

    #listen() {
        window.addEventListener('resize', () => {
            if (!this.#active) return;
            if (this.#active.button.getClientRects().length === 0 && !(this.#active.returnFocus && document.documentElement.clientWidth <= 900)) this.#close();
            else this.#position(this.#active);
        });
        document.addEventListener('pointerdown', event => {
            if (!event.composedPath().includes(this.#host)) this.#close();
        });
        this.#root.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.#close(true);
            }
        });
    }

    #primary(panel, value, suffix = '') {
        const element = this.#node('div', null, panel, 'primary');
        const strong = this.#node('strong', value, element);
        if (suffix) this.#node('span', suffix, element);
        return strong;
    }

    #row(panel, label, value, parameters = {}) {
        if (value == null || value === '') return;
        const element = this.#node('div', null, panel, 'detail-row');
        this.#node('span', this.#t(label, parameters), element);
        this.#node('span', String(value), element);
    }

    #note(panel, key) {
        return this.#node('p', this.#t(key), panel, 'note');
    }

    #action(panel, key, href) {
        this.#link(`${this.#t(key)} ↗`, href, panel).className = 'panel-action';
    }

    #profileUrl() {
        return `${prefix}/request/${this.#current.id}`;
    }

    #serverValue(name) {
        const value = this.#current.server?.[name];
        return typeof value === 'string' && value.trim() ? value : this.#t('common.unavailable');
    }

    #renderMetrics() {
        const current = this.#current;
        const profileUrl = this.#profileUrl();
        const http = this.#metric('HTTP', String(current.status));
        if (current.status >= 400) http.button.classList.add('bad');
        this.#primary(http.panel, String(current.status));
        this.#row(http.panel, 'common.method', current.method);
        this.#row(http.panel, 'common.route', current.route || current.path || this.#t('common.unavailable'));
        if (current.controller) this.#row(http.panel, 'common.controller', `${current.controller}${current.action ? `::${current.action}` : ''}`);
        this.#row(http.panel, 'common.duration', ms(current.duration_ms));
        this.#action(http.panel, 'panels.http.overview', `${profileUrl}#overview`);

        const time = this.#metric('Time', ms(current.duration_ms));
        this.#primary(time.panel, ms(current.duration_ms));
        for (const phase of current.phases) {
            if (phase.duration !== null) this.#row(time.panel, phase.label_key ?? 'timeline.measured', ms(phase.duration), {template: phase.template ?? ''});
        }
        this.#note(time.panel, 'panels.time.note');
        this.#action(time.panel, 'panels.time.timeline', `${profileUrl}#timeline`);

        const mem = this.#metric('Memory', memory(current.peak_bytes));
        this.#primary(mem.panel, memory(current.peak_bytes));
        this.#row(mem.panel, 'panels.memory.peak', memory(current.peak_bytes));
        if (current.server?.memory_limit) this.#row(mem.panel, 'panels.memory.limit', this.#serverValue('memory_limit'));
        this.#note(mem.panel, 'panels.memory.note');
        this.#action(mem.panel, 'panels.memory.detail', profileUrl);

        const sql = this.#metric('SQL', String(current.sql_count));
        this.#primary(sql.panel, String(current.sql_count));
        this.#row(sql.panel, 'panels.sql.total', ms(current.sql_ms));
        if (current.longest) {
            this.#row(sql.panel, 'panels.sql.longest', ms(current.longest.elapsed_ms));
            if (current.longest.sql) this.#node('pre', current.longest.sql, sql.panel);
        }
        this.#action(sql.panel, 'panels.sql.database', `${profileUrl}#database`);

        this.#requestPanel = this.#metric('Requests', '0');
        this.#requestTotal = this.#primary(this.#requestPanel.panel, '0');
        this.#note(this.#requestPanel.panel, 'panels.requests.note');
        this.#requestList = this.#node('ol', null, this.#requestPanel.panel);
        this.#requestEmpty = this.#note(this.#requestPanel.panel, 'panels.requests.empty');
        this.#action(this.#requestPanel.panel, 'panels.requests.all', prefix);

        const php = this.#metric('PHP', this.#serverValue('php_version'));
        this.#primary(php.panel, this.#serverValue('php_version'));
        this.#details(php.panel, [
            'php_version', 'php_sapi', 'memory_limit', 'max_execution_time', 'timezone',
        ]);
        this.#action(php.panel, 'panels.php.extensions', `${profileUrl}#server`);

        const server = this.#metric('Server', this.#serverValue('web_server').split('/')[0]);
        this.#primary(server.panel, this.#serverValue('web_server'));
        this.#details(server.panel, [
            'web_server', 'os', 'environment', 'framework_version',
        ]);
        this.#action(server.panel, 'panels.server.detail', `${profileUrl}#server`);

        const more = this.#metric('More', '');
        this.#morePanel = more;
        this.#moreEntry(more, this.#t('toolbar.metric.memory'), memory(current.peak_bytes), null, 'Memory', mem);
        this.#moreEntry(more, this.#t('panels.php.title'), this.#serverValue('php_version'), null, 'PHP', php);
        this.#moreEntry(more, this.#t('panels.server.title'), this.#serverValue('web_server'), null, 'Server', server);
        this.#moreEntry(more, this.#t('panels.more.session'), this.#t('panels.more.session_detail'), `${profileUrl}#session`, 'SQL');
        this.#moreEntry(more, this.#t('panels.more.composer'), this.#t('panels.more.packages'), `${profileUrl}#composer`, 'Memory');
        this.#moreEntry(more, this.#t('panels.more.current'), current.route || current.path || this.#t('panels.more.session_detail'), profileUrl, 'HTTP');
        this.#moreEntry(more, this.#t('toolbar.history_short'), this.#t('panels.more.recent'), prefix, 'History');
        this.#languageSwitch(this.#node('div', null, more.panel, 'more-language'), 'mobile');
    }

    #details(panel, rows) {
        for (const name of rows) this.#row(panel, `diagnostics.${name}`, this.#serverValue(name));
    }

    #moreEntry(more, name, detail, href, iconName, target = null) {
        const entry = target ? this.#node('button', null, more.panel) : this.#link('', href, more.panel);
        entry.className = 'more-entry';
        if (target) {
            entry.type = 'button';
            entry.addEventListener('click', () => {
                target.returnFocus = more.button;
                this.#open(target);
            });
        }
        const badge = this.#node('span', null, entry, 'panel-icon');
        icon(iconName, badge);
        const words = this.#node('span', null, entry, 'more-words');
        this.#node('strong', name, words);
        this.#node('small', detail, words);
        this.#node('span', '›', entry, 'chevron');
    }

    #addRequest(data, path, count) {
        this.#records.push({data, path});
        if (this.#records.length > 20) this.#records.shift();
        this.#renderRequest(data, path, count);
    }

    #renderRequest(data, path, count) {
        const name = typeof data.route === 'string' ? data.route : typeof data.path === 'string' ? data.path : this.#t('panels.route_unavailable');
        const row = this.#node('li');
        const anchor = this.#link('', path, row);
        anchor.className = 'request-entry';
        this.#node('strong', `${data.method} ${name.slice(0, 512)}`, anchor);
        this.#node('span', `${data.status} · ${ms(data.duration_ms)}`, anchor);
        if (data.status >= 400) anchor.className += ' bad';
        this.#requestList.prepend(row);
        if (this.#requestList.children.length > 20) this.#requestList.lastElementChild.remove();
        this.#requestEmpty.hidden = true;
        for (const label of [this.#requestPanel.full, this.#requestPanel.short, this.#requestPanel.tiny, this.#requestTotal]) {
            label.textContent = String(count);
        }
        const label = this.#t('toolbar.metric_value', {name: this.#t('toolbar.metric.requests'), value: count});
        this.#requestPanel.button.setAttribute('aria-label', label);
        this.#requestPanel.button.title = label;
    }
}
