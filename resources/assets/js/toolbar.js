/*!
 * Lemonade Profiler
 *
 * Initializes the profiler toolbar from the embedded profile snapshot.
 *
 * @link      https://lemonadeframework.cz/
 * @author    Honza Mudrak <honzamudrak@gmail.com>
 * @copyright Copyright (c) 2026 Honza Mudrak. All rights reserved.
 * @license   Apache-2.0 - see LICENSE
 */
import {ProfilerToolbar} from './profiler-toolbar.js';

const key = Symbol.for('lemonade.profiler.toolbar');
if (!window[key]) {
    const script = document.querySelector('script[data-lemonade-profile][src$="/assets/toolbar.js"]');
    if (script) {
        try {
            const current = JSON.parse(script.dataset.lemonadeProfile);
            if (/^[a-f0-9]{32}$/.test(current.id)) {
                const toolbar = new ProfilerToolbar(current);
                toolbar.mount();
                window[key] = true;
            }
        } catch {
            // Diagnostics must never prevent the host application from initializing.
        }
    }
}
