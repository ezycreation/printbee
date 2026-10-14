<?php
/**
 * Hymnbee read-only API adapter + local cached copy.
 * Never include this file's secrets in client output. The browser only talks to hymns.php.
 */

const HYMNS_CHORUS_MARK = "\u{2063}";   // invisible separator; marks a chorus paragraph in lyric text
const HYMNS_BOOK_SLUG = 'default';
const HYMNS_FAIL_BACKOFF_SECONDS = 60;

/** Parse KEY=VALUE lines. Handles comments, quotes, `export KEY=...`, CRLF and a UTF-8 BOM. */
function hymns_parse_env_text($text){
  $out = [];
  $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $text);
  foreach(preg_split('/\r\n|\r|\n/', $text) as $line){
    $line = trim($line);
    if($line === '' || $line[0] === '#') continue;
    $line = preg_replace('/^export\s+/', '', $line);
    if(strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k); $v = trim($v);
    if($v !== '' && ($v[0] === '"' || $v[0] === "'")){
      $q = $v[0]; $endq = strpos($v, $q, 1);
      $v = ($endq === false) ? substr($v, 1) : substr($v, 1, $endq - 1);
    } else {
      $v = trim(preg_replace('/\s+#.*$/', '', $v));      // drop a trailing "  # comment"
    }
    if($k !== '') $out[$k] = $v;
  }
  return $out;
}

/** Where the env file may live: $HYMNS_ENV_FILE if set, otherwise printbee's folder, then the older occasion one. */
function hymns_env_file_candidates(){
  $explicit = getenv('HYMNS_ENV_FILE');
  if($explicit !== false && $explicit !== '') return [$explicit];
  return ['/etc/printbee/hymns.env', '/etc/occasion/hymns.env'];
}

/** Values from the first readable env file. Works for web AND cron. */
function hymns_env_file_values(){
  static $vals = null;
  if($vals !== null) return $vals;
  $vals = [];
  foreach(hymns_env_file_candidates() as $file){
    if(!is_readable($file)) continue;
    $raw = @file_get_contents($file);
    if($raw === false) continue;
    $vals = hymns_parse_env_text($raw);
    break;
  }
  return $vals;
}

function hymns_env($key, $default = null){
  $v = getenv($key);
  if($v === false || $v === ''){ $v = $_SERVER[$key] ?? ($_ENV[$key] ?? null); }
  if($v === null || $v === ''){ $f = hymns_env_file_values(); $v = $f[$key] ?? null; }
  return ($v === null || $v === '') ? $default : $v;
}

function hymns_config(){
  $verify = strtolower((string) hymns_env('HYMNS_API_VERIFY_TLS', 'true'));
  $langs = array_values(array_filter(array_map('trim', explode(',', (string) hymns_env('HYMNS_PREFERRED_LANGS', 'ne,hi,en')))));
  return [
    'base_url'        => rtrim((string) hymns_env('HYMNS_API_BASE_URL', ''), '/'),
    'api_key'         => (string) hymns_env('HYMNS_API_KEY', ''),
    'verify_tls'      => !in_array($verify, ['0', 'false', 'no', 'off'], true),
    'ttl'             => max(0, (int) hymns_env('HYMNS_CACHE_TTL_MIN', 15)) * 60,
    'cache_dir'       => (string) hymns_env('HYMNS_CACHE_DIR', __DIR__ . '/cache'),
    'timeout'         => max(1, (int) hymns_env('HYMNS_API_TIMEOUT', 10)),
    'preferred_langs' => $langs,
  ];
}

/**
 * Single HTTP GET. Returns ['status'=>int,'headers'=>[lowercase=>value],'body'=>string,'error'=>?string].
 * status 0 = could not reach the server (connection failure / timeout). Tests replace this via
 * $GLOBALS['HYMNS_TRANSPORT'] so no test ever touches a real server.
 */
function hymns_http_get($url, array $headers, array $cfg){
  if(isset($GLOBALS['HYMNS_TRANSPORT']) && is_callable($GLOBALS['HYMNS_TRANSPORT'])){
    return call_user_func($GLOBALS['HYMNS_TRANSPORT'], $url, $headers, $cfg);
  }
  if(!function_exists('curl_init')){
    return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'curl extension missing'];
  }
  $respHeaders = [];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,                 // never follow redirects with the key attached
    CURLOPT_TIMEOUT        => $cfg['timeout'],
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => $cfg['verify_tls'],    // TLS verification OFF only via HYMNS_API_VERIFY_TLS=false
    CURLOPT_SSL_VERIFYHOST => $cfg['verify_tls'] ? 2 : 0,
    CURLOPT_HEADERFUNCTION => function($c, $line) use (&$respHeaders){
      $p = explode(':', $line, 2);
      if(count($p) === 2) $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
      return strlen($line);
    },
  ]);
  $body = curl_exec($ch);
  $err = ($body === false) ? curl_error($ch) : null;
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ['status' => $status, 'headers' => $respHeaders, 'body' => ($body === false ? '' : $body), 'error' => $err];
}

function hymns_cache_paths(array $cfg){
  $d = rtrim($cfg['cache_dir'], '/');
  return [$d, $d . '/bundle.json', $d . '/meta.json'];
}

function hymns_read_json($file){
  if(!is_file($file)) return null;
  $raw = @file_get_contents($file);
  if($raw === false) return null;
  $d = json_decode($raw, true);
  return is_array($d) ? $d : null;
}

function hymns_write_json_atomic($file, $data){
  $dir = dirname($file);
  if(!is_dir($dir)) @mkdir($dir, 0775, true);
  $tmp = $file . '.' . getmypid() . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  return @rename($tmp, $file);
}

function hymns_sync_fail($metaFile, array $meta, $code, $hasBundle){
  $meta['last_error'] = $code;
  $meta['failed_at'] = time();
  hymns_write_json_atomic($metaFile, $meta);
  error_log('[occasion hymns] sync failed: ' . $code . ($hasBundle ? ' (serving last copy)' : ' (no copy yet)'));
  return ['status' => 'error', 'error' => $code, 'serving_cached' => $hasBundle];
}

/**
 * Revalidate the cached bundle (ETag / If-None-Match). Never throws, never loses the last good copy.
 * Statuses: fresh | backoff | updated | not_modified | error
 */
function hymns_sync($force = false, $cfg = null){
  $cfg = $cfg ?: hymns_config();
  [$dir, $bundleFile, $metaFile] = hymns_cache_paths($cfg);
  $meta = hymns_read_json($metaFile) ?: [];
  $hasBundle = is_file($bundleFile);
  $now = time();

  if(!$force && $hasBundle){
    if(isset($meta['checked_at']) && ($now - (int) $meta['checked_at']) < $cfg['ttl']) return ['status' => 'fresh'];
    if(isset($meta['failed_at']) && ($now - (int) $meta['failed_at']) < HYMNS_FAIL_BACKOFF_SECONDS) return ['status' => 'backoff'];
  }
  if($cfg['base_url'] === '' || $cfg['api_key'] === ''){
    return hymns_sync_fail($metaFile, $meta, 'not_configured', $hasBundle);
  }

  $headers = ['X-Api-Key: ' . $cfg['api_key'], 'Accept: application/json'];
  if($hasBundle && !empty($meta['etag'])) $headers[] = 'If-None-Match: ' . $meta['etag'];

  $res = hymns_http_get($cfg['base_url'] . '/api/v1/hymns/bundle', $headers, $cfg);
  $status = (int) ($res['status'] ?? 0);

  if($status === 304 && $hasBundle){
    $meta['checked_at'] = $now;
    unset($meta['last_error'], $meta['failed_at']);
    hymns_write_json_atomic($metaFile, $meta);
    return ['status' => 'not_modified'];
  }
  if($status === 200){
    $data = json_decode((string) $res['body'], true);
    if(!is_array($data) || !isset($data['hymns']) || !is_array($data['hymns'])){
      return hymns_sync_fail($metaFile, $meta, 'bad_payload', $hasBundle);
    }
    if(!hymns_write_json_atomic($bundleFile, $data)) return hymns_sync_fail($metaFile, $meta, 'cache_write_failed', $hasBundle);
    $newMeta = ['etag' => (string) ($res['headers']['etag'] ?? ''), 'checked_at' => $now, 'fetched_at' => $now, 'count' => count($data['hymns'])];
    hymns_write_json_atomic($metaFile, $newMeta);
    return ['status' => 'updated', 'count' => $newMeta['count']];
  }
  $code = ($status === 0) ? 'unreachable' : ('http_' . $status);   // 401 bad key, 403 wrong book, 404, 429...
  return hymns_sync_fail($metaFile, $meta, $code, $hasBundle);
}

function hymns_load_bundle($cfg = null){
  $cfg = $cfg ?: hymns_config();
  [, $bundleFile] = hymns_cache_paths($cfg);
  return hymns_read_json($bundleFile);
}

function hymns_find(array $bundle, $number){
  foreach(($bundle['hymns'] ?? []) as $h){
    if(is_array($h) && isset($h['hymnNumber']) && (string) $h['hymnNumber'] === (string) $number) return $h;
  }
  return null;
}

function hymns_pick_language(array $translations, $requested, array $preferred){
  if($requested !== null && $requested !== '' && isset($translations[$requested])) return $requested;
  foreach($preferred as $c){ if(isset($translations[$c])) return $c; }
  $codes = array_keys($translations);
  return $codes ? (string) $codes[0] : null;     // fall back to any available language
}

/** Map a Hymnbee hymn into the shape Printbee already uses: {songNumber,title,englishTitle,content,...}. */
function hymns_map_for_app(array $hymn, $requestedLang = null, array $preferred = ['ne', 'hi', 'en']){
  $translations = (isset($hymn['translations']) && is_array($hymn['translations'])) ? $hymn['translations'] : [];
  $code = hymns_pick_language($translations, $requestedLang, $preferred);
  $t = ($code !== null && is_array($translations[$code])) ? $translations[$code] : [];

  $verses = (isset($t['verses']) && is_array($t['verses'])) ? array_values($t['verses']) : [];
  usort($verses, function($a, $b){ return ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)); });

  $paras = [];
  foreach($verses as $v){
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($v['text'] ?? '')));
    if($text === '') continue;
    $label = trim((string) ($v['label'] ?? ''));
    $isChorus = (($v['type'] ?? 'verse') === 'chorus');
    if($label !== '' && !$isChorus && preg_match('/^[0-9\x{0966}-\x{096F}]+$/u', $label)) $label .= '.';
    $prefix = ($label !== '' && strpos($text, $label) !== 0) ? $label . ' ' : '';
    $paras[] = ($isChorus ? HYMNS_CHORUS_MARK : '') . $prefix . $text;
  }

  $en = (isset($translations['en']) && is_array($translations['en'])) ? (string) ($translations['en']['title'] ?? '') : '';
  return [
    'songNumber'         => (string) ($hymn['hymnNumber'] ?? ''),
    'title'              => (string) ($t['title'] ?? ''),
    'englishTitle'       => $en,
    'content'            => implode("\n\n", $paras),
    'languageCode'       => $code,
    'languageName'       => (string) ($t['languageName'] ?? ''),
    'availableLanguages' => array_keys($translations),
    'tags'               => (isset($hymn['tags']) && is_array($hymn['tags'])) ? $hymn['tags'] : [],
    'bookSlug'           => HYMNS_BOOK_SLUG,
  ];
}
