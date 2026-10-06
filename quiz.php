<?php

date_default_timezone_set('Europe/Moscow');

const GOOGLE_FORM = 'https://docs.google.com/forms/d/e/1FAIpQLSdoWtY1qpD1PKxcFHVvJTj4JiJyFOOHdoUnAsYji8VsHw6EIQ/formResponse';

const CHOICES = array(
  'learn' => array('Да', 'Нет'),
  'age' => array('До 18', '18–25', '25–35', '35–45', '45+'),
  'job' => array(
    'Работаю в найме',
    'Развиваю свой бизнес / проект',
    'Работаю на себя / фриланс',
    'Учусь',
    'Нахожусь в декрете',
    'Сейчас не работаю',
  ),
  'experience' => array(
    'Вообще нет опыта, хочу начать с нуля',
    'Пробовал(а) ChatGPT / нейросети, но пока без системы',
    'Уже создаю AI-контент для себя или своих проектов',
    'Создаю контент для клиентов',
    'Уже зарабатываю с помощью AI-контента',
  ),
  'income' => array(
    'Пока не зарабатываю',
    'До 250$',
    '250$–500$',
    '500$–1 000$',
    '1 000$–3 000$',
    '3 000$+',
  ),
  'target' => array(
    '250$–750$',
    '750$–1 500$',
    '1 500$–3 000$',
    '3 000$+',
    'Для начала хочу просто выйти на первые деньги',
  ),
  'purpose' => array(
    'Хочу начать зарабатывать на создании контента для клиентов',
    'Хочу развивать свои соцсети / личный бренд',
    'Хочу продвигать свой бизнес или продукт',
    'Хочу быстрее и проще создавать контент',
    'Хочу освоить новую востребованную профессию',
    'Пока изучаю возможности AI',
  ),
);

const GOOGLE_ENTRIES = array(
  'name' => '1312631349',
  'phone' => '2085169594',
  'telegram' => '1623508464',
  'learn' => '1583679858',
  'city' => '1253426548',
  'age' => '105392739',
  'job' => '111580861',
  'experience' => '1383425672',
  'income' => '617805417',
  'target' => '227563965',
  'purpose' => '1068713134',
  'result' => '1858319837',
);

if (PHP_SAPI !== 'cli') {
  handle_request();
}

function handle_request() {
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $base = rtrim(dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''), '/\\');
    header('Location: ' . $base . '/quiz/', true, 302);
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Метод не поддерживается', 405);
  }

  if (!same_site()) {
    respond(false, 'Не получилось отправить. Обнови страницу и попробуй ещё раз.', 403);
  }

  $data = read_body();
  if (!is_array($data)) {
    respond(false, 'Не получилось прочитать ответы. Попробуй ещё раз.', 400);
  }

  $company = text_field($data, 'company', 80);
  if ($company !== '') {
    respond(true, '', 200);
  }

  $answers = validate_answers($data);
  if (isset($answers['error'])) {
    respond(false, $answers['error'], 400);
  }

  save_application($answers);
  if (!forward_google(google_fields($answers))) {
    respond(false, 'Не получилось отправить. Попробуй ещё раз через минуту.', 502);
  }

  respond(true, '', 200);
}

function read_body() {
  $type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
  if (stripos($type, 'application/json') !== false) {
    $raw = stream_get_contents(fopen('php://input', 'rb'), 12000);
    if (!is_string($raw) || $raw === '') {
      return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
  }

  return is_array($_POST) ? $_POST : null;
}

function validate_answers($data) {
  $name = line_field($data, 'name', 120);
  if ($name === '' || !preg_match('/\p{L}/u', $name)) {
    return array('error' => 'Напиши имя и фамилию');
  }

  $phone = line_field($data, 'phone', 32);
  $digits = preg_replace('/\D/', '', $phone);
  if (strlen($digits) < 10 || strlen($digits) > 15) {
    return array('error' => 'Укажи номер телефона');
  }

  $telegram = normalize_telegram(line_field($data, 'telegram', 64));
  if (!preg_match('/^@[A-Za-z][A-Za-z0-9_]{4,31}$/', $telegram)) {
    return array('error' => 'Укажи ник в телеграме, например @username');
  }

  $learn = choice_field($data, 'learn');
  if ($learn === null) {
    return array('error' => 'Выбери вариант');
  }

  $city = line_field($data, 'city', 80);
  if (text_length($city) < 2 || !preg_match('/\p{L}/u', $city)) {
    return array('error' => 'Напиши свой город');
  }

  $age = choice_field($data, 'age');
  if ($age === null) {
    return array('error' => 'Выбери вариант');
  }

  $job = choice_or_other($data, 'job', 'job_other');
  if ($job === null) {
    return array('error' => isset($data['job']) && $data['job'] === '__other__'
      ? 'Напиши свой вариант'
      : 'Выбери вариант');
  }

  $experience = choice_or_other($data, 'experience', 'experience_other');
  if ($experience === null) {
    return array('error' => isset($data['experience']) && $data['experience'] === '__other__'
      ? 'Напиши свой вариант'
      : 'Выбери вариант');
  }

  $income = choice_field($data, 'income');
  if ($income === null) {
    return array('error' => 'Выбери вариант');
  }

  $target = choice_field($data, 'target');
  if ($target === null) {
    return array('error' => 'Выбери вариант');
  }

  $purpose = choice_or_other($data, 'purpose', 'purpose_other');
  if ($purpose === null) {
    return array('error' => isset($data['purpose']) && $data['purpose'] === '__other__'
      ? 'Напиши свой вариант'
      : 'Выбери вариант');
  }

  $result = block_field($data, 'result', 2000);
  if (text_length($result) < 10) {
    return array('error' => 'Расскажи чуть подробнее — хотя бы пару слов');
  }

  return array(
    'name' => $name,
    'phone' => $phone,
    'telegram' => $telegram,
    'learn' => $learn,
    'city' => $city,
    'age' => $age,
    'job' => $job['value'],
    'job_other' => $job['other'],
    'experience' => $experience['value'],
    'experience_other' => $experience['other'],
    'income' => $income,
    'target' => $target,
    'purpose' => $purpose['value'],
    'purpose_other' => $purpose['other'],
    'result' => $result,
  );
}

function choice_field($data, $key) {
  if (!isset($data[$key]) || !is_string($data[$key])) {
    return null;
  }
  $value = trim($data[$key]);
  if (!in_array($value, CHOICES[$key], true)) {
    return null;
  }
  return $value;
}

function choice_or_other($data, $key, $otherKey) {
  if (!isset($data[$key]) || !is_string($data[$key])) {
    return null;
  }
  $value = trim($data[$key]);
  if ($value === '__other__') {
    $other = line_field($data, $otherKey, 160);
    if (text_length($other) < 2) {
      return null;
    }
    return array('value' => '__other__', 'other' => $other);
  }
  if (!in_array($value, CHOICES[$key], true)) {
    return null;
  }
  return array('value' => $value, 'other' => '');
}

function google_fields($answers) {
  $fields = array(
    'fvv' => '1',
    'pageHistory' => '0',
    'submissionTimestamp' => '-1',
  );

  $plain = array('name', 'phone', 'telegram', 'learn', 'city', 'age', 'income', 'target', 'result');
  foreach ($plain as $key) {
    $fields['entry.' . GOOGLE_ENTRIES[$key]] = $answers[$key];
  }

  foreach (array('job', 'experience', 'purpose') as $key) {
    $entry = 'entry.' . GOOGLE_ENTRIES[$key];
    if ($answers[$key] === '__other__') {
      $fields[$entry] = '__other_option__';
      $fields[$entry . '.other_option_response'] = $answers[$key . '_other'];
      continue;
    }
    $fields[$entry] = $answers[$key];
  }

  return $fields;
}

function forward_google($fields) {
  $body = http_build_query($fields);
  if (function_exists('curl_init')) {
    $ch = curl_init(GOOGLE_FORM);
    curl_setopt_array($ch, array(
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 12,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_USERAGENT => 'Mozilla/5.0',
      CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
    ));
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 400;
  }

  $context = stream_context_create(array(
    'http' => array(
      'method' => 'POST',
      'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: Mozilla/5.0\r\n",
      'content' => $body,
      'timeout' => 12,
      'ignore_errors' => true,
    ),
  ));
  $result = @file_get_contents(GOOGLE_FORM, false, $context);
  if ($result === false || !isset($http_response_header[0])) {
    return false;
  }
  return preg_match('/\s(2|3)\d\d\s/', $http_response_header[0]) === 1;
}

function save_application($answers) {
  $path = applications_file();
  $record = $answers;
  $record['at'] = date('c');
  unset($record['job_other'], $record['experience_other'], $record['purpose_other']);
  if ($answers['job'] === '__other__') {
    $record['job'] = $answers['job_other'];
  }
  if ($answers['experience'] === '__other__') {
    $record['experience'] = $answers['experience_other'];
  }
  if ($answers['purpose'] === '__other__') {
    $record['purpose'] = $answers['purpose_other'];
  }

  $handle = fopen($path, 'a');
  if ($handle === false) {
    return false;
  }
  $written = false;
  if (flock($handle, LOCK_EX)) {
    $written = fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE) . "\n") !== false;
    fflush($handle);
    flock($handle, LOCK_UN);
  }
  fclose($handle);
  return $written;
}

function applications_file() {
  $outside = dirname(__DIR__) . '/dibrain-private';
  if (prepare_dir($outside)) {
    return $outside . '/applications.jsonl';
  }

  $inside = __DIR__ . '/storage';
  prepare_dir($inside);
  return $inside . '/applications.jsonl';
}

function prepare_dir($dir) {
  if (!@is_dir($dir) && !@mkdir($dir, 0700, true)) {
    return false;
  }
  return @is_writable($dir);
}

function text_field($data, $key, $max) {
  if (!isset($data[$key]) || !is_string($data[$key])) {
    return '';
  }
  $value = trim($data[$key]);
  if (text_length($value) > $max) {
    return '';
  }
  return $value;
}

function line_field($data, $key, $max) {
  $value = text_field($data, $key, $max);
  $value = preg_replace('/\s+/u', ' ', $value);
  return trim($value);
}

function block_field($data, $key, $max) {
  if (!isset($data[$key]) || !is_string($data[$key])) {
    return '';
  }
  $value = str_replace(array("\r\n", "\r"), "\n", $data[$key]);
  $value = trim($value);
  if (text_length($value) > $max) {
    return '';
  }
  return $value;
}

function normalize_telegram($value) {
  $nick = trim($value);
  $nick = preg_replace('#^https?://(?:t\.me|telegram\.me)/#i', '', $nick);
  $nick = ltrim($nick, '@');
  $nick = preg_split('/[\/?#\s]/', $nick);
  $nick = is_array($nick) && isset($nick[0]) ? $nick[0] : '';
  return $nick === '' ? '' : '@' . $nick;
}

function text_length($value) {
  if (function_exists('mb_strlen')) {
    return mb_strlen($value, 'UTF-8');
  }
  $count = preg_match_all('/./u', $value, $matches);
  return $count === false ? 0 : $count;
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

function wants_json() {
  $type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
  $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
  return stripos($type, 'application/json') !== false || stripos($accept, 'application/json') !== false;
}

function respond($ok, $error, $status) {
  http_response_code($status);
  header('X-Robots-Tag: noindex');
  header('Cache-Control: no-store');

  if (wants_json()) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
      $ok ? array('ok' => true) : array('ok' => false, 'error' => $error),
      JSON_UNESCAPED_UNICODE
    );
    exit;
  }

  if ($ok) {
    $base = rtrim(dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''), '/\\');
    header('Location: ' . $base . '/success/', true, 303);
    exit;
  }

  header('Content-Type: text/html; charset=UTF-8');
  $title = $ok ? 'Анкета отправлена' : 'Не получилось отправить';
  $text = $ok
    ? 'Мы посмотрим ответы и свяжемся, если сможем помочь.'
    : $error;
  echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
    . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
    . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#dae5ff;color:#0046d6;font-family:Arial,sans-serif;padding:1.5rem}main{max-width:32rem}a{color:#0046d6}</style></head><body><main><h1>'
    . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
    . '</h1><p>'
    . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
    . '</p><p><a href="quiz/">Вернуться к анкете</a></p></main></body></html>';
  exit;
}
