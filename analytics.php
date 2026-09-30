<?php

const ANALYTICS_KEY = 'CG_hzf64LyHr4nm2-al494VNv2bu4CKq';

date_default_timezone_set('Europe/Moscow');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  record_event();
  http_response_code(204);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  not_found();
}

$provided = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
if ($provided !== '') {
  if (!hash_equals(ANALYTICS_KEY, $provided)) {
    not_found();
  }

  setcookie('dibrain_analytics', access_token(), array(
    'expires' => time() + 60 * 60 * 24 * 30,
    'path' => '/analytics.php',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
  ));
  header('Location: analytics.php', true, 302);
  header('Cache-Control: no-store');
  exit;
}

$cookie = isset($_COOKIE['dibrain_analytics']) ? $_COOKIE['dibrain_analytics'] : '';
if (!is_string($cookie) || !hash_equals(access_token(), $cookie)) {
  not_found();
}

render_page();

function access_token() {
  return hash_hmac('sha256', 'analytics', ANALYTICS_KEY);
}

function not_found() {
  http_response_code(404);
  header('Content-Type: text/html; charset=UTF-8');
  header('X-Robots-Tag: noindex');
  exit;
}

function record_event() {
  if (!same_site()) {
    return;
  }

  $raw = stream_get_contents(fopen('php://input', 'rb'), 256);
  $payload = json_decode($raw, true);
  if (!is_array($payload)) {
    return;
  }

  $events = array('view' => 'views', 'play' => 'plays', 'cta' => 'cta', 'watch' => 'watch');
  $event = isset($payload['event']) ? $payload['event'] : '';
  if (!isset($events[$event])) {
    return;
  }

  $device = isset($payload['device']) && ($payload['device'] === 'mobile' || $payload['device'] === 'desktop')
    ? $payload['device']
    : 'desktop';
  $source = isset($payload['source']) && in_array($payload['source'], array('telegram', 'direct', 'other'), true)
    ? $payload['source']
    : 'other';
  $day = date('Y-m-d');
  $isWatch = $event === 'watch';
  $seconds = 0;
  if ($isWatch) {
    $seconds = isset($payload['seconds']) ? (int) $payload['seconds'] : 0;
    if ($seconds < 1 || $seconds > 86400) {
      return;
    }
  }

  $path = data_file();
  $handle = fopen($path, 'c+');
  if ($handle === false) {
    return;
  }

  flock($handle, LOCK_EX);
  $contents = stream_get_contents($handle);
  $stats = json_decode($contents, true);
  if (!is_array($stats)) {
    $stats = empty_stats();
  }
  $stats = normalize_stats($stats);

  if ($isWatch) {
    add_watch($stats['totals'], $seconds);
    add_watch($stats['devices'][$device], $seconds);
    add_watch($stats['sources'][$source], $seconds);
    if (!isset($stats['days'][$day])) {
      $stats['days'][$day] = empty_bucket();
    }
    add_watch($stats['days'][$day], $seconds);
  } else {
    $field = $events[$event];
    $stats['totals'][$field] += 1;
    $stats['devices'][$device][$field] += 1;
    $stats['sources'][$source][$field] += 1;
    if (!isset($stats['days'][$day])) {
      $stats['days'][$day] = empty_bucket();
    }
    $stats['days'][$day][$field] += 1;
  }

  $cutoff = date('Y-m-d', strtotime('-90 days'));
  foreach (array_keys($stats['days']) as $storedDay) {
    if ($storedDay < $cutoff) {
      unset($stats['days'][$storedDay]);
    }
  }

  rewind($handle);
  ftruncate($handle, 0);
  fwrite($handle, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
}

function add_watch(&$bucket, $seconds) {
  $bucket['watch_seconds'] += $seconds;
  $bucket['watch_count'] += 1;
}

function same_site() {
  $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '';
  $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
  if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    return is_string($originHost) && strcasecmp($originHost, $host) === 0;
  }

  $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
  $refererHost = parse_url($referer, PHP_URL_HOST);
  return is_string($refererHost) && strcasecmp($refererHost, $host) === 0;
}

function data_file() {
  $outside = dirname(__DIR__) . '/dibrain-private';
  if (prepare_dir($outside)) {
    return $outside . '/analytics.json';
  }

  $inside = __DIR__ . '/storage';
  prepare_dir($inside);
  return $inside . '/analytics.json';
}

function prepare_dir($dir) {
  if (!@is_dir($dir) && !@mkdir($dir, 0700, true)) {
    return false;
  }
  return @is_writable($dir);
}

function empty_bucket() {
  return array(
    'views' => 0,
    'plays' => 0,
    'cta' => 0,
    'watch_seconds' => 0,
    'watch_count' => 0,
  );
}

function empty_stats() {
  return array(
    'totals' => empty_bucket(),
    'devices' => array('mobile' => empty_bucket(), 'desktop' => empty_bucket()),
    'sources' => array(
      'telegram' => empty_bucket(),
      'direct' => empty_bucket(),
      'other' => empty_bucket(),
    ),
    'days' => array(),
  );
}

function normalize_stats($stats) {
  $base = empty_stats();
  foreach (array('totals') as $group) {
    if (isset($stats[$group]) && is_array($stats[$group])) {
      $base[$group] = normalize_bucket($stats[$group]);
    }
  }
  foreach (array('devices', 'sources') as $group) {
    if (!isset($stats[$group]) || !is_array($stats[$group])) {
      continue;
    }
    foreach ($base[$group] as $name => $bucket) {
      if (isset($stats[$group][$name]) && is_array($stats[$group][$name])) {
        $base[$group][$name] = normalize_bucket($stats[$group][$name]);
      }
    }
  }
  if (isset($stats['days']) && is_array($stats['days'])) {
    foreach ($stats['days'] as $day => $bucket) {
      if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && is_array($bucket)) {
        $base['days'][$day] = normalize_bucket($bucket);
      }
    }
  }
  ksort($base['days']);
  return $base;
}

function normalize_bucket($bucket) {
  return array(
    'views' => isset($bucket['views']) ? max(0, (int) $bucket['views']) : 0,
    'plays' => isset($bucket['plays']) ? max(0, (int) $bucket['plays']) : 0,
    'cta' => isset($bucket['cta']) ? max(0, (int) $bucket['cta']) : 0,
    'watch_seconds' => isset($bucket['watch_seconds']) ? max(0, (int) $bucket['watch_seconds']) : 0,
    'watch_count' => isset($bucket['watch_count']) ? max(0, (int) $bucket['watch_count']) : 0,
  );
}

function avg_watch($bucket) {
  $count = (int) $bucket['watch_count'];
  if ($count <= 0) {
    return 0;
  }
  return (int) round($bucket['watch_seconds'] / $count);
}

function format_duration($seconds) {
  $seconds = max(0, (int) $seconds);
  if ($seconds <= 0) {
    return '—';
  }
  $minutes = intdiv($seconds, 60);
  $rest = $seconds % 60;
  if ($minutes <= 0) {
    return $rest . ' с';
  }
  return $minutes . ':' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
}

function render_page() {
  $path = data_file();
  $stats = empty_stats();
  if (is_file($path)) {
    $decoded = json_decode(file_get_contents($path), true);
    if (is_array($decoded)) {
      $stats = normalize_stats($decoded);
    }
  }

  $root = rtrim(realpath(__DIR__) ?: __DIR__, '/') . '/';
  $publicCopy = strpos(realpath($path) ?: $path, $root) === 0;
  $totals = $stats['totals'];
  $days = recent_days($stats['days'], 14);
  $avgTotal = avg_watch($totals);

  header('Content-Type: text/html; charset=UTF-8');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex');
  header('Referrer-Policy: no-referrer');
  ?>
<!DOCTYPE html>
<html lang="ru">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>Аналитика — [dibrain] school</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml" />
    <style>
      @font-face {
        font-family: "Resist Sans Display";
        src: url("fonts/ResistSansDisplay-Medium.ttf") format("truetype");
        font-weight: 500;
        font-display: swap;
      }

      @font-face {
        font-family: "Resist Sans Display";
        src: url("fonts/ResistSansDisplay-Bold.ttf") format("truetype");
        font-weight: 700;
        font-display: swap;
      }

      :root {
        --bg: #dae5ff;
        --ink: #0046d6;
        --ink-deep: #0039b8;
        --notice: #c4d5fb;
        --white: #ffffff;
      }

      * { box-sizing: border-box; margin: 0; padding: 0; }

      body {
        min-height: 100dvh;
        background: var(--bg);
        color: var(--ink);
        font-family: "Resist Sans Display", "Arial Narrow", Arial, sans-serif;
        font-weight: 500;
      }

      .wrap {
        width: min(100% - 2rem, 52rem);
        margin: 0 auto;
        padding: 1.4rem 0 3rem;
      }

      .logo {
        font-size: 1.45rem;
        font-weight: 700;
        letter-spacing: -0.045em;
        line-height: 0.86;
      }

      h1 {
        margin-top: 1.4rem;
        font-size: clamp(2rem, 8vw, 3.2rem);
        font-weight: 700;
        letter-spacing: -0.045em;
        line-height: 0.92;
        text-transform: uppercase;
      }

      .totals {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.7rem;
        margin-top: 1.4rem;
      }

      .total {
        padding: 0.9rem 0.85rem 0.8rem;
        border-radius: 1.1rem;
        background: var(--ink);
        color: var(--white);
      }

      .total b {
        display: block;
        font-size: clamp(1.5rem, 5vw, 2.4rem);
        letter-spacing: -0.04em;
        line-height: 0.95;
      }

      .total span,
      .section h2,
      .muted {
        display: block;
        margin-top: 0.35rem;
        font-size: 0.78rem;
        font-weight: 500;
        letter-spacing: 0.01em;
        line-height: 1.2;
        text-transform: uppercase;
      }

      .rates {
        margin-top: 0.85rem;
        color: var(--ink-deep);
        font-size: 0.95rem;
      }

      .section { margin-top: 1.6rem; }

      .table {
        width: 100%;
        margin-top: 0.7rem;
        border-collapse: collapse;
      }

      .table th,
      .table td {
        padding: 0.55rem 0.35rem;
        text-align: left;
        font-size: 0.95rem;
        border-bottom: 1px solid rgba(0, 70, 214, 0.16);
        vertical-align: baseline;
      }

      .table th {
        font-size: 0.72rem;
        font-weight: 500;
        letter-spacing: 0.01em;
        text-transform: uppercase;
        color: var(--ink-deep);
      }

      .table td:not(:first-child),
      .table th:not(:first-child) {
        text-align: right;
      }

      .table tr:last-child td { border-bottom: 0; }

      .split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.8rem;
      }

      .card {
        padding: 0.95rem 1rem 1rem;
        border-radius: 1.1rem;
        background: rgba(255, 255, 255, 0.55);
      }

      .row {
        display: flex;
        justify-content: space-between;
        gap: 0.8rem;
        margin-top: 0.45rem;
        font-size: 1rem;
      }

      .warn {
        margin-top: 1.2rem;
        font-size: 0.9rem;
        line-height: 1.35;
      }

      @media (max-width: 720px) {
        .totals, .split { grid-template-columns: 1fr 1fr; }
        .table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
      }

      @media (max-width: 420px) {
        .totals { grid-template-columns: 1fr; }
      }
    </style>
  </head>
  <body>
    <main class="wrap">
      <p class="logo">[dibrain]<br />school</p>
      <h1>аналитика</h1>
      <section class="totals">
        <?php metric($totals['views'], 'заходы'); ?>
        <?php metric($totals['plays'], 'старты'); ?>
        <?php metric($totals['cta'], 'кнопка'); ?>
        <?php metric(format_duration($avgTotal), 'ср. просмотр', true); ?>
      </section>
      <p class="rates">
        запускают видео <?php echo rate($totals['plays'], $totals['views']); ?>
        · нажимают на разбор <?php echo rate($totals['cta'], $totals['views']); ?>
      </p>

      <section class="section">
        <h2>по дням</h2>
        <table class="table">
          <thead>
            <tr>
              <th>день</th>
              <th>заходы</th>
              <th>старты</th>
              <th>кнопка</th>
              <th>ср. время</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_reverse($days, true) as $day => $bucket) { ?>
              <tr>
                <td><?php echo htmlspecialchars(substr($day, 8, 2) . '.' . substr($day, 5, 2), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo (int) $bucket['views']; ?></td>
                <td><?php echo (int) $bucket['plays']; ?></td>
                <td><?php echo (int) $bucket['cta']; ?></td>
                <td><?php echo htmlspecialchars(format_duration(avg_watch($bucket)), ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
        <p class="muted">ср. время — до куда досмотрели по таймкоду видео</p>
      </section>

      <section class="section split">
        <?php split_card('устройства', array(
          'телефон' => $stats['devices']['mobile'],
          'компьютер' => $stats['devices']['desktop'],
        )); ?>
        <?php split_card('откуда открыли', array(
          'telegram' => $stats['sources']['telegram'],
          'напрямую' => $stats['sources']['direct'],
          'другие' => $stats['sources']['other'],
        )); ?>
      </section>
      <?php if ($publicCopy) { ?>
        <p class="warn">Файл статистики лежит в папке сайта. Закройте storage от прямого доступа.</p>
      <?php } ?>
    </main>
  </body>
</html>
  <?php
}

function metric($value, $label, $raw = false) {
  $shown = $raw ? htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') : (string) ((int) $value);
  echo '<p class="total"><b>' . $shown . '</b><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></p>';
}

function rate($part, $whole) {
  if ($whole <= 0) {
    return '—';
  }
  return (int) round($part / $whole * 100) . '%';
}

function recent_days($days, $count) {
  $result = array();
  for ($offset = $count - 1; $offset >= 0; $offset -= 1) {
    $day = date('Y-m-d', strtotime('-' . $offset . ' days'));
    $result[$day] = isset($days[$day]) ? $days[$day] : empty_bucket();
  }
  return $result;
}

function split_card($title, $rows) {
  echo '<section class="card"><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
  foreach ($rows as $label => $bucket) {
    echo '<p class="row"><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><b>'
      . (int) $bucket['views'] . '</b></p>';
  }
  echo '<p class="muted">заходы</p></section>';
}
