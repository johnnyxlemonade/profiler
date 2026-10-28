<?php
$maxDuration = 0.0;
$statuses = [];
$methods = [];
foreach ($profiles as $profile) {
    $profileData = $profile->toArray();
    $maxDuration = max($maxDuration, $profileData['duration_ms']);
    if ($profileData['status'] !== null) {
        $statuses[$profileData['status']] = $profileData['status'];
    }
    $methods[$profileData['method']] = $profileData['method'];
}
sort($statuses, SORT_NUMERIC);
sort($methods, SORT_STRING);
?>
<!doctype html>
<html lang="<?= $localeCode ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $tr('history.title') ?> · Lemonade Profiler</title>
    <link rel="stylesheet" href="<?= $prefix ?>/assets/profiler.css">
    <script type="module" src="<?= $prefix ?>/assets/history-filters.js"></script>
</head>
<body>
<header class="top">
    <div class="top-inner">
        <a class="brand" href="<?= $prefix ?>?lang=<?= $localeCode ?>"><img class="brand-symbol" src="<?= $prefix ?>/assets/symbol.svg" width="36" height="36" alt=""> Lemonade Profiler</a>
        <nav class="primary-nav" aria-label="<?= $tr('common.navigation') ?>"><a href="<?= $prefix ?>?lang=<?= $localeCode ?>" aria-current="page"><?= $tr('history.title') ?></a></nav>
        <nav class="language-switch" aria-label="<?= $tr('common.language') ?>"><a href="<?= $prefix ?>?lang=<?= $cs ?>" lang="<?= $cs ?>"<?= $localeCode === $cs ? ' aria-current="true"' : '' ?>>CS</a><span aria-hidden="true">/</span><a href="<?= $prefix ?>?lang=<?= $en ?>" lang="<?= $en ?>"<?= $localeCode === $en ? ' aria-current="true"' : '' ?>>EN</a></nav>
    </div>
</header>
<main>
    <nav class="breadcrumbs" aria-label="<?= $tr('common.breadcrumbs') ?>"><span>Lemonade Profiler</span><span aria-hidden="true">›</span><span aria-current="page"><?= $tr('history.title') ?></span></nav>
    <div class="page-heading"><div><h1><?= $tr('history.title') ?></h1><p class="muted"><?= $tr('history.description') ?></p></div><div class="heading-actions"><a class="button button-primary" href="/"><?= $tr('common.back_to_site') ?> <span aria-hidden="true">↗</span></a><a class="button" href="<?= $prefix ?>?lang=<?= $localeCode ?>"><?= $tr('history.refresh') ?> <span aria-hidden="true">↻</span></a></div></div>
    <section class="panel history-filters" aria-label="<?= $tr('history.filters') ?>">
        <form id="history-filters" class="history-filter-form" data-count-template="<?= $tr('history.shown_count') ?>" data-remove-template="<?= $tr('history.remove_filter') ?>">
            <div class="history-filter-field history-filter-search"><label for="history-search"><?= $tr('history.search') ?></label><div class="history-search-control"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg><input id="history-search" name="search" type="search" placeholder="<?= $tr('history.search_placeholder') ?>" autocomplete="off" disabled></div></div>
            <div class="history-filter-field"><label for="history-status"><?= $tr('common.status') ?></label><select id="history-status" name="status" disabled><option value=""><?= $tr('history.all_statuses') ?></option><?php foreach ($statuses as $status): ?><option value="<?= $status ?>"><?= $status ?></option><?php endforeach; ?></select></div>
            <div class="history-filter-field"><label for="history-method"><?= $tr('common.method') ?></label><select id="history-method" name="method" disabled><option value=""><?= $tr('history.all_methods') ?></option><?php foreach ($methods as $method): ?><option value="<?= $this->escape($method) ?>"><?= $this->escape($method) ?></option><?php endforeach; ?></select></div>
            <div class="history-filter-field"><label for="history-period"><?= $tr('history.period') ?></label><select id="history-period" name="period" disabled><option value=""><?= $tr('history.all_periods') ?></option><option value="15"><?= $tr('history.last_minutes', ['count' => 15]) ?></option><option value="30"><?= $tr('history.last_minutes', ['count' => 30]) ?></option><option value="60"><?= $tr('history.last_minutes', ['count' => 60]) ?></option></select></div>
            <button class="button history-filter-reset" type="reset" disabled hidden><?= $tr('history.clear_filters') ?></button>
        </form>
        <div id="history-active-filters" class="history-active-filters" hidden><span><?= $tr('history.active_filters') ?>:</span><div class="history-filter-chips"></div></div>
    </section>
    <section class="panel history-panel"><div class="panel-heading"><h2><?= $tr('history.latest') ?></h2><span id="history-results-count" role="status" aria-live="polite"><?= $tr('history.shown_count', ['shown' => count($profiles), 'total' => count($profiles)]) ?></span></div>
    <?php if ($profiles === []): ?>
        <div class="empty"><h3><?= $tr('history.waiting') ?></h3><p><?= $tr('history.empty') ?></p></div>
    <?php else: ?>
        <div class="table-scroll"><table class="history-table"><thead><tr><th scope="col"><?= $tr('common.time') ?></th><th scope="col"><?= $tr('common.status') ?></th><th scope="col"><?= $tr('common.method') ?></th><th scope="col"><?= $tr('common.route') ?></th><th scope="col"><?= $tr('common.duration') ?></th><th scope="col"><?= $tr('history.sql_count_time') ?></th><th scope="col"><?= $tr('history.peak_memory') ?></th><th scope="col"><span class="visually-hidden"><?= $tr('common.details') ?></span></th></tr></thead><tbody>
        <?php foreach ($profiles as $profile):
            $data = $profile->toArray();
            $url = $prefix . '/request/' . $this->escape($data['id']) . '?lang=' . $localeCode;
            $durationWidth = $maxDuration > 0 ? 100 * $data['duration_ms'] / $maxDuration : 0;
            ?>
            <tr data-history-row data-search="<?= $this->escape(implode(' ', array_filter([$data['route'], $data['path'], $data['controller']]))) ?>" data-status="<?= $this->escape($data['status'] ?? '') ?>" data-method="<?= $this->escape($data['method']) ?>" data-created-at="<?= $data['created_at'] ?>"><td class="muted time-cell"><?= gmdate('Y-m-d H:i:s', (int) ($data['created_at'] / 1000000)) ?> UTC</td><td><span class="status <?= $data['status'] >= 400 ? 'bad' : 'good' ?>"><?= $this->escape($data['status']) ?></span></td><td><span class="method method-<?= strtolower($this->escape($data['method'])) ?>"><?= $this->escape($data['method']) ?></span></td><td class="route-cell"><a href="<?= $url ?>"><?= $this->escape($data['route'] ?? $data['path']) ?></a></td><td class="duration-cell"><strong><?= $this->ms($data['duration_ms']) ?></strong><svg class="duration-track" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><rect width="<?= number_format($durationWidth, 2, '.', '') ?>" height="6" /></svg></td><td><?= $this->escape($data['sql_count']) ?> <span class="muted">/ <?= $this->ms($data['sql_ms']) ?></span></td><td><?= $this->memory($data['peak_bytes']) ?></td><td><a class="inspect-link" href="<?= $url ?>"><?= $tr('history.inspect') ?> <span aria-hidden="true">→</span></a></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <div id="history-filter-empty" class="empty" hidden><h3><?= $tr('history.no_matches') ?></h3><button class="button" type="button" data-history-clear><?= $tr('history.clear_filters') ?></button></div>
    <?php endif; ?></section>
    <p class="footnote"><?= $tr('history.footnote') ?></p>
</main>
</body>
</html>
