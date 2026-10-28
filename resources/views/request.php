<?php
/** @var \Lemonade\Profiler\Profile $profile */
$displayValue = fn(?string $value): string => $this->escape($value ?? $translator->translate('common.unavailable'));
$comparisonNote = $data['route'] === null || $data['route'] === '' || $data['status'] === null
    || $data['status'] < 100 || $data['status'] > 599
    ? 'comparison.unavailable' : 'comparison.insufficient';
$durationDifference = $comparison === null ? null : $data['duration_ms'] - $comparison['average_duration_ms'];
$sqlDifference = $comparison['sql_difference'] ?? null;
$durationPercentLabel = null;
if ($comparison !== null && $comparison['duration_percent'] !== null) {
    $percent = abs($comparison['duration_percent']);
    $durationPercentLabel = $percent < 1
        ? $translator->translate('overview.less_than_one_percent')
        : number_format($percent, 0, '.', '') . ' %';
}

?>
<!doctype html>
<html lang="<?= $localeCode ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $tr('request.title') ?> · Lemonade Profiler</title>
    <link rel="stylesheet" href="<?= $prefix ?>/assets/profiler.css">
</head>
<body class="request-page">
<header class="top">
    <div class="top-inner">
        <a class="brand" href="<?= $prefix ?>?lang=<?= $localeCode ?>"><img class="brand-symbol" src="<?= $prefix ?>/assets/symbol.svg" width="36" height="36" alt=""> Lemonade Profiler</a>
        <nav class="primary-nav" aria-label="<?= $tr('common.navigation') ?>"><a href="<?= $prefix ?>?lang=<?= $localeCode ?>"><?= $tr('history.title') ?></a><a href="#overview" aria-current="page"><?= $tr('request.title') ?></a></nav>
        <nav class="language-switch" aria-label="<?= $tr('common.language') ?>"><a href="<?= $prefix ?>/request/<?= $this->escape($data['id']) ?>?lang=<?= $cs ?>" lang="<?= $cs ?>"<?= $localeCode === $cs ? ' aria-current="true"' : '' ?>>CS</a><span aria-hidden="true">/</span><a href="<?= $prefix ?>/request/<?= $this->escape($data['id']) ?>?lang=<?= $en ?>" lang="<?= $en ?>"<?= $localeCode === $en ? ' aria-current="true"' : '' ?>>EN</a></nav>
    </div>
</header>
<main>
    <nav class="breadcrumbs" aria-label="<?= $tr('common.breadcrumbs') ?>"><a href="<?= $prefix ?>?lang=<?= $localeCode ?>"><?= $tr('history.title') ?></a><span aria-hidden="true">›</span><span aria-current="page"><?= $this->escape($data['route'] ?? $data['path'] ?? $translator->translate('overview.unmatched')) ?></span></nav>
    <div class="page-heading request-heading"><div><h1><span class="method method-<?= strtolower($this->escape($data['method'])) ?>"><?= $this->escape($data['method']) ?></span> <span class="request-route"><?= $this->escape($data['route'] ?? $data['path'] ?? $translator->translate('overview.unmatched')) ?></span></h1><p class="muted"><?= $this->escape($data['id']) ?> · <?= gmdate('Y-m-d H:i:s', (int) ($data['created_at'] / 1000000)) ?> UTC</p></div><div class="heading-actions"><a class="button" href="<?= $prefix ?>?lang=<?= $localeCode ?>">← <?= $tr('request.back_history') ?></a><a class="button button-primary" href="/"><?= $tr('common.back_to_site') ?> <span aria-hidden="true">↗</span></a></div></div>

    <div class="metrics">
        <div class="metric"><span><?= $tr('overview.http_status') ?></span><strong><span class="status <?= $data['status'] >= 400 ? 'bad' : 'good' ?>"><?= $this->escape($data['status']) ?></span></strong></div>
        <div class="metric"><span><?= $tr('overview.request_duration') ?></span><strong><?= $this->ms($data['duration_ms']) ?></strong>
            <div class="metric-comparison">
                <?php if ($comparison === null): ?><small class="metric-reference"><?= $tr($comparisonNote) ?></small>
                <?php else: ?>
                    <span class="metric-change <?= $durationDifference > 0 ? 'metric-change--worse' : ($durationDifference < 0 ? 'metric-change--better' : '') ?>"><?= $durationDifference > 0 ? '↑ ' : ($durationDifference < 0 ? '↓ ' : '') ?><?php if ($durationPercentLabel !== null && $durationDifference != 0): ?><?= $this->escape(($durationDifference > 0 ? '+' : '−') . $durationPercentLabel) ?> · <?php endif; ?><?= $tr($durationDifference > 0 ? 'comparison.slower' : ($durationDifference < 0 ? 'comparison.faster' : 'comparison.unchanged')) ?></span>
                    <small class="metric-reference"><?= $tr('comparison.average_duration', ['time' => $this->ms($comparison['average_duration_ms']), 'requests' => $translator->previousRequestCount($comparison['count'])]) ?></small>
                <?php endif; ?>
            </div>
        </div>
        <div class="metric"><span><?= $tr('overview.sql_queries') ?></span><strong><?= $this->escape($data['sql_count']) ?></strong>
            <div class="metric-comparison">
                <?php if ($comparison === null): ?><small class="metric-reference"><?= $this->escape($this->ms($data['sql_ms'])) ?> · <?= $tr($comparisonNote) ?></small>
                <?php else: ?>
                    <span class="metric-change <?= $sqlDifference > 0 ? 'metric-change--worse' : ($sqlDifference < 0 ? 'metric-change--better' : '') ?>"><?= $sqlDifference > 0 ? '↑ ' : ($sqlDifference < 0 ? '↓ ' : '') ?><?= $sqlDifference == 0 ? $tr('comparison.sql_unchanged', ['average' => $this->queryAverage($comparison['average_sql_count'])]) : $tr('comparison.sql_difference', ['difference' => ($sqlDifference > 0 ? '+' : '−') . $this->queryAverage(abs($sqlDifference)), 'average' => $this->queryAverage($comparison['average_sql_count'])]) ?></span>
                    <small class="metric-reference"><?= $tr('comparison.sql_time', ['current' => $this->ms($data['sql_ms']), 'average' => $this->ms($comparison['average_sql_ms'])]) ?></small>
                <?php endif; ?>
            </div>
        </div>
        <div class="metric"><span><?= $tr('overview.peak_memory') ?></span><strong><?= $this->memory($data['peak_bytes']) ?></strong></div>
    </div>

    <nav class="tabs" aria-label="<?= $tr('overview.sections') ?>">
        <a href="#overview"><?= $tr('overview.title') ?></a>
        <a href="#timeline"><?= $tr('timeline.title') ?></a>
        <a href="#database"><?= $tr('database.title') ?></a>
        <a href="#rendering"><?= $tr('rendering.title') ?></a>
        <a href="#session"><?= $tr('session.title') ?></a>
        <a href="#server"><?= $tr('server.title') ?></a>
        <a href="#composer"><?= $tr('composer.title') ?></a>
    </nav>

    <div class="overview-grid">
    <section class="panel" id="overview">
        <div class="panel-heading"><h2><?= $tr('overview.basic_information') ?></h2><span><?= $tr('overview.safe') ?></span></div>
        <dl>
            <dt><?= $tr('common.time') ?></dt><dd><?= gmdate('Y-m-d H:i:s', (int) ($data['created_at'] / 1000000)) ?> UTC</dd>
            <dt><?= $tr('common.method') ?></dt><dd><?= $this->escape($data['method']) ?></dd>
            <dt><?= $tr('common.status') ?></dt><dd><?= $this->escape($data['status']) ?></dd>
            <dt><?= $tr('common.duration') ?></dt><dd><?= $this->ms($data['duration_ms']) ?></dd>
            <dt><?= $tr('common.route') ?></dt><dd><?= $this->escape($data['route']) ?></dd>
            <dt><?= $tr('overview.route_template') ?></dt><dd><?= $this->escape($data['path']) ?></dd>
            <dt><?= $tr('common.controller') ?></dt><dd><code><?= $this->escape($data['controller']) ?></code></dd>
            <dt><?= $tr('common.action') ?></dt><dd><?= $this->escape($data['action']) ?></dd>
            <dt><?= $tr('overview.connection') ?></dt><dd><?= $data['connection_ms'] === null ? '—' : $this->ms($data['connection_ms']) ?></dd>
            <dt><?= $tr('overview.profile_id') ?></dt><dd><code><?= $this->escape($data['id']) ?></code></dd>
        </dl>
    </section>
    <section class="panel" aria-labelledby="breakdown-heading">
        <div class="panel-heading"><h2 id="breakdown-heading"><?= $tr('overview.time_breakdown') ?></h2><span><?= $tr('timeline.measured') ?></span></div>
        <?php if ($breakdown === []): ?>
            <div class="empty"><h3><?= $tr('overview.breakdown_unavailable') ?></h3><p><?= $tr('overview.breakdown_missing') ?></p></div>
        <?php else: ?>
            <div class="breakdown-content">
                <div class="donut" aria-label="<?= $tr('overview.time_breakdown') ?>">
                    <svg viewBox="0 0 100 100" aria-hidden="true"><circle class="donut-track" cx="50" cy="50" r="42" />
                        <?php $donutOffset = 0.0; foreach ($breakdown as $index => $slice):
                            $arc = 263.8938 * $slice['percent'] / 100;
                            ?><circle class="donut-segment donut-segment-<?= $index ?>" cx="50" cy="50" r="42" stroke-dasharray="<?= number_format($arc, 4, '.', '') ?> 263.8938" stroke-dashoffset="<?= number_format(-$donutOffset, 4, '.', '') ?>" /><?php $donutOffset += $arc; endforeach; ?>
                    </svg>
                    <div class="donut-center"><strong><?= $this->ms($data['duration_ms']) ?></strong><span><?= $tr('overview.total') ?></span></div>
                </div>
                <ul class="breakdown-legend">
                    <?php foreach ($breakdown as $index => $slice):
                        $label = $slice['phase'] === 'render' ? $tr('rendering.title') : ($slice['phase'] === 'controller_action_started' ? $tr('overview.controller_exclusive') : $tr(\Lemonade\Profiler\Presentation\ProfilerTranslator::phaseKey($slice['phase'])));
                        ?><li><span class="legend-dot donut-segment-<?= $index ?>" aria-hidden="true"></span><span class="legend-label"><?= $label ?></span><strong><?= $this->ms($slice['duration']) ?></strong><span class="legend-percent"><?= $slice['percent'] > 0 && $slice['percent'] < 1 ? $tr('overview.less_than_one_percent') : number_format($slice['percent'], 0) . ' %' ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <p class="breakdown-note"><?= $tr('overview.breakdown_note') ?></p>
        <?php endif; ?>
    </section>
    </div>

    <section class="panel" id="timeline">
        <div class="panel-heading"><h2><?= $tr('timeline.title') ?></h2><span><?= $tr('timeline.measured') ?></span></div>
        <p class="note"><?= $tr('timeline.note') ?></p>
        <div class="timeline">
            <?php foreach ($intervals as $interval):
                $left = min(100.0, 100 * $interval['start'] / max(0.001, $data['duration_ms']));
                $width = min(100.0 - $left, 100 * ($interval['duration'] ?? 0) / max(0.001, $data['duration_ms']));
                $render = $interval['phase'] === 'render';
                ?>
                <div class="span-row<?= $render ? ' span-row--render' : '' ?><?= $interval['duration'] === null ? ' span-row--incomplete' : '' ?>">
                    <div class="span-label"><?= $tr($interval['label_key'], ['template' => $interval['template'] ?? '']) ?>
                        <small><?= $interval['duration'] === null ? $tr('timeline.end_missing') : $this->ms($interval['duration']) ?> · <?= $tr('timeline.starts_at', ['time' => $this->ms($interval['start'])]) ?></small>
                    </div>
                    <svg class="track" aria-hidden="true" viewBox="0 0 100 20" preserveAspectRatio="none"><rect x="<?= number_format($left, 4, '.', '') ?>" width="<?= number_format($interval['duration'] === null ? 0.25 : $width, 4, '.', '') ?>" height="20" rx="0.5" /></svg>
                </div>
            <?php endforeach; ?>
            <div class="timeline-axis" aria-hidden="true"><span></span><div><?php for ($tick = 0; $tick <= 4; ++$tick): ?><span><?= $this->ms($data['duration_ms'] * $tick / 4) ?></span><?php endfor; ?></div></div>
        </div>
        <details><summary><?= $tr('timeline.raw') ?></summary><dl>
            <?php foreach ($data['marks'] as $mark): ?>
                <dt><?= $this->escape($mark['name']) ?></dt><dd><?= $this->ms($mark['elapsed_ms']) ?></dd>
            <?php endforeach; ?>
        </dl></details>
    </section>

    <div class="data-grid">
    <section class="panel" id="database">
        <div class="panel-heading"><h2><?= $tr('database.title') ?></h2><span><?= $this->escape($translator->queryCount($data['sql_count'])) ?> · <?= $this->ms($data['sql_ms']) ?></span></div>
        <p class="note"><?= $tr('database.note') ?></p>
        <?php if ($data['sql_count'] > count($data['queries'])): ?>
            <p class="note"><?= $tr('database.limit') ?></p>
        <?php endif; ?>
        <?php if ($data['queries'] === []): ?>
            <div class="empty"><h3><?= $tr('database.empty_title') ?></h3><p><?= $tr('database.empty') ?></p></div>
        <?php endif; ?>
        <?php if ($data['queries'] !== []): ?>
            <div class="table-scroll database-scroll"><table class="database-table">
                <thead><tr><th scope="col">#</th><th scope="col"><?= $tr('common.duration') ?></th><th scope="col"><?= $tr('database.query') ?></th></tr></thead>
                <tbody><?php foreach ($data['queries'] as $query): ?>
                    <tr><td><?= $this->escape($query['order']) ?></td><td class="query-time <?= $query['slow'] ? 'slow' : '' ?>"><?= $this->ms($query['elapsed_ms']) ?><?php if ($query['slow']): ?><small><?= $tr('database.slow') ?></small><?php endif; ?></td><td class="sql-cell"><pre><code><?= $this->escape($query['sql'] ?? $translator->translate('database.sql_unavailable')) ?></code></pre></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="panel" id="rendering">
        <div class="panel-heading"><h2><?= $tr('rendering.title') ?></h2><span><?= $tr('rendering.subtitle') ?></span></div>
        <p class="note"><?= $tr('rendering.note') ?></p>
        <?php if ($data['renderings'] === []): ?>
            <div class="empty"><h3><?= $tr('rendering.empty_title') ?></h3><p><?= $tr('rendering.empty') ?></p></div>
        <?php else: ?>
            <div class="table-scroll"><table class="render-table">
                <thead><tr><th scope="col">#</th><th scope="col"><?= $tr('common.template') ?></th><th scope="col"><?= $tr('common.duration') ?></th><th scope="col"><?= $tr('common.result') ?></th></tr></thead>
                <tbody><?php foreach ($data['renderings'] as $rendering): ?>
                    <tr><td><?= $rendering['order'] ?></td><td class="template-cell"><code><?= $this->escape($rendering['template']) ?></code></td><td><?= $this->ms($rendering['elapsed_ms']) ?></td><td><?= $rendering['completed'] ? $tr('common.completed') : $tr('common.interrupted') ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
    </div>
    <div class="diagnostics-grid">
    <?php foreach (['session' => 'session.title', 'server' => 'server.title', 'composer' => 'composer.title'] as $key => $title):
        $section = $data['diagnostics'][$key] ?? null;
        ?>
        <section class="panel" id="<?= $key ?>">
            <div class="panel-heading"><h2><?= $tr($title) ?></h2><span><?= $tr('diagnostics.subtitle') ?></span></div>
            <?php if ($section === null): ?>
                <p class="note"><?= $tr('common.unavailable') ?></p>
            <?php else: ?>
                <?php if ($key === 'session'): ?><p class="note"><?= $tr('session.note') ?></p><?php endif; ?>
                <?php if ($key === 'composer'): ?><p class="note"><?= $tr('composer.note') ?></p><?php endif; ?>
                <?php $values = $section['values']; if ($key === 'composer') { unset($values['composer_version']); } ?>
                <dl><?php foreach ($values as $label => $value): ?>
                    <dt><?= $tr('diagnostics.' . $label) ?></dt><dd><?= $value !== null && in_array($label, ['status', 'secure', 'httponly', 'dependency_classification'], true) ? $tr('common.' . $value) : $displayValue($value) ?></dd>
                <?php endforeach; ?></dl>
                <?php if ($section['entries'] !== []): ?>
                    <details class="entry-details"><summary><span class="entry-title"><?= $key === 'server' ? $tr('server.php_extensions') : $tr('composer.packages') ?></span><span class="entry-actions"><span class="entry-count"><?= count($section['entries']) ?></span><svg class="entry-chevron" viewBox="0 0 16 16" aria-hidden="true"><path d="m3 6 5 5 5-5" /></svg></span></summary><div class="table-scroll"><table class="diagnostic-table">
                        <caption class="visually-hidden"><?= $key === 'server' ? $tr('server.php_extensions') : $tr('composer.packages') ?></caption>
                        <thead><tr><th scope="col"><?= $key === 'server' ? $tr('server.php_extension') : $tr('common.package') ?></th><th scope="col"><?= $tr('common.version') ?></th><?php if ($key === 'composer'): ?><th scope="col"><?= $tr('composer.dependency_type') ?></th><?php endif; ?></tr></thead>
                        <tbody><?php foreach ($section['entries'] as $entry): ?>
                            <tr><td><code><?= $this->escape($entry['name']) ?></code></td><td><?= $displayValue($entry['version']) ?></td><?php if ($key === 'composer'): ?><td><?= in_array($entry['dependency'], ['direct', 'development', 'transitive'], true) ? $tr('composer.dependency.' . $entry['dependency']) : $tr('common.unavailable') ?></td><?php endif; ?></tr>
                        <?php endforeach; ?></tbody>
                    </table></div></details>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
    <p class="footnote"><?= $tr('request.footnote') ?></p>
</main>
</body>
</html>
