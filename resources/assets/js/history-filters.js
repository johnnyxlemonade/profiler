/*!
 * Lemonade Profiler
 *
 * Filters the already loaded request history without additional requests.
 *
 * @link      https://lemonadeframework.cz/
 * @author    Honza Mudrak <honzamudrak@gmail.com>
 * @copyright Copyright (c) 2026 Honza Mudrak. All rights reserved.
 * @license   Apache-2.0 - see LICENSE
 */

export const matchesHistoryRecord = (record, filters, now = Date.now()) => {
    const search = filters.search.trim().toLocaleLowerCase();
    if (search && !record.search.toLocaleLowerCase().includes(search)) return false;
    if (filters.status && record.status !== filters.status) return false;
    if (filters.method && record.method !== filters.method) return false;
    if (filters.period && Number(record.createdAt) / 1000 < now - Number(filters.period) * 60_000) return false;
    return true;
};

const form = typeof document === 'undefined' ? null : document.querySelector('#history-filters');

if (form) {
    const fields = Object.fromEntries(['search', 'status', 'method', 'period'].map(name => [name, form.elements.namedItem(name)]));
    const rows = [...document.querySelectorAll('[data-history-row]')];
    const active = document.querySelector('#history-active-filters');
    const chips = active.querySelector('.history-filter-chips');
    const count = document.querySelector('#history-results-count');
    const empty = document.querySelector('#history-filter-empty');
    const reset = form.querySelector('.history-filter-reset');

    const filters = () => Object.fromEntries(Object.entries(fields).map(([name, field]) => [name, field.value]));

    const render = () => {
        const current = filters();
        const now = Date.now();
        let shown = 0;
        for (const row of rows) {
            row.hidden = !matchesHistoryRecord(row.dataset, current, now);
            if (!row.hidden) shown++;
        }

        count.textContent = form.dataset.countTemplate.replace('{shown}', shown).replace('{total}', rows.length);
        if (empty) empty.hidden = shown !== 0 || rows.length === 0;
        chips.replaceChildren();

        for (const [name, field] of Object.entries(fields)) {
            if (!current[name].trim()) continue;
            const label = form.querySelector(`label[for="${field.id}"]`).textContent.trim();
            const value = name === 'search' ? current[name].trim() : field.selectedOptions[0].textContent.trim();
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'history-filter-chip';
            chip.textContent = `${label}: ${value} ×`;
            chip.setAttribute('aria-label', form.dataset.removeTemplate.replace('{filter}', `${label}: ${value}`));
            chip.addEventListener('click', () => {
                field.value = '';
                render();
                field.focus();
            });
            chips.append(chip);
        }
        active.hidden = chips.childElementCount === 0;
        reset.hidden = active.hidden;
    };

    const clear = () => {
        for (const field of Object.values(fields)) field.value = '';
        render();
    };

    form.querySelectorAll('[disabled]').forEach(control => { control.disabled = false; });
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    form.addEventListener('submit', event => event.preventDefault());
    form.addEventListener('reset', event => {
        event.preventDefault();
        clear();
    });
    empty?.querySelector('[data-history-clear]')?.addEventListener('click', clear);
    render();
}
