/*!
 * Lemonade Profiler
 *
 * Creates the inline SVG icons used by the toolbar and panels.
 *
 * @link      https://lemonadeframework.cz/
 * @author    Honza Mudrak <honzamudrak@gmail.com>
 * @copyright Copyright (c) 2026 Honza Mudrak. All rights reserved.
 * @license   Apache-2.0 - see LICENSE
 */
const paths = {
    HTTP: 'M4 5h16v14H4z M4 9h16 M8 14h3',
    Time: 'M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18 M12 7v5l3 2',
    Memory: 'M7 7h10v10H7z M10 10h4v4h-4z M9 3v4 M15 3v4 M9 17v4 M15 17v4 M3 9h4 M3 15h4 M17 9h4 M17 15h4',
    SQL: 'M4 6c0-4 16-4 16 0s-16 4-16 0 M4 6v12c0 4 16 4 16 0V6 M4 12c0 4 16 4 16 0',
    Requests: 'M4 8h12l-3-3 M16 8l-3 3 M20 16H8l3-3 M8 16l3 3',
    PHP: 'M3 10v8 M3 10h3a2 2 0 0 1 0 4H3 M10 6v8 M10 10c0-3 4-3 4 0v4 M17 10v8 M17 10h3a2 2 0 0 1 0 4h-3',
    Server: 'M4 4h16v7H4z M4 13h16v7H4z M8 8h.01 M8 17h.01',
    More: 'M5 12h.01 M12 12h.01 M19 12h.01',
    History: 'M3 8a9 9 0 1 1 0 8 M3 3v5h5 M12 7v5l3 2',
    Close: 'M6 6l12 12 M18 6L6 18',
};

export function icon(name, parent) {
    const namespace = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(namespace, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '18');
    svg.setAttribute('height', '18');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const path = document.createElementNS(namespace, 'path');
    path.setAttribute('d', paths[name]);
    svg.append(path);
    parent.append(svg);
}
