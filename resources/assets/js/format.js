/*!
 * Lemonade Profiler
 *
 * Formats measured times, memory values, and available metadata.
 *
 * @link      https://lemonadeframework.cz/
 * @author    Honza Mudrak <honzamudrak@gmail.com>
 * @copyright Copyright (c) 2026 Honza Mudrak. All rights reserved.
 * @license   Apache-2.0 - see LICENSE
 */
export const ms = value => {
    if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) return null;
    if (value === 0) return '0 µs';
    if (value >= 1) return `${value.toFixed(2)} ms`;
    const microseconds = (value * 1000).toFixed(2);
    return microseconds === '0.00' ? '<0.01 µs' : `${microseconds} µs`;
};
export const memory = value => `${(value / 1048576).toFixed(1)} MiB`;
