<?php
/** @var \Lemonade\Profiler\Profile $profile */
$data = $profile->data;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lemonade Profiler</title><link rel="stylesheet" href="<?= $prefix ?>/assets/profiler.css"></head>
<body class="toolbar-page"><nav class="toolbar" aria-label="Request profiler">
<a class="brand" target="_top" href="<?= $prefix ?>/request/<?= $this->escape($data['id']) ?>"><span class="lemon">L</span> Lemonade <span class="muted">Profiler</span></a>
<span class="status <?= $data['status'] >= 400 ? 'bad' : 'good' ?>"><?= $this->escape($data['status']) ?></span>
<span><?= $this->ms($data['duration_ms']) ?></span><span><?= $this->memory($data['peak_bytes']) ?></span>
<span>SQL <b><?= $data['sql_count'] ?></b> <span class="muted">/ <?= $this->ms($data['sql_ms']) ?></span></span>
<span class="route"><?= $this->escape($data['route'] ?? $data['path']) ?></span>
<a target="_top" href="<?= $prefix ?>">History ↗</a></nav></body></html>
