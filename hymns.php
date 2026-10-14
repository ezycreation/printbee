<?php
/**
 * Read-only hymn lookup for the Occasion front end. The browser calls THIS file; the Hymnbee key never
 * leaves the server. Served from the cached bundle (see hymns_lib.php / sync.php), never live per request.
 *   GET hymns.php?number=22[&lang=ne]     -> one hymn, in Occasion's shape
 *   GET hymns.php?action=search&q=...     -> [{songNumber,title}] searched in OUR copy (not Hymnbee /search)
 *   GET hymns.php?action=languages        -> [{code,name}]
 */
require __DIR__ . '/hymns_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function hymns_out($status, $payload){
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

try{
  if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') hymns_out(405, ['error' => 'method_not_allowed']);

  $cfg = hymns_config();
  hymns_sync(false, $cfg);                       // revalidates at most every HYMNS_CACHE_TTL_MIN; never throws
  $bundle = hymns_load_bundle($cfg);
  if($bundle === null) hymns_out(503, ['error' => 'hymns_unavailable']);   // no copy yet and API unreachable

  $action = (string) ($_GET['action'] ?? 'hymn');

  if($action === 'languages'){
    $seen = [];
    foreach(($bundle['hymns'] ?? []) as $h){
      foreach((array) ($h['translations'] ?? []) as $code => $t){
        if(!isset($seen[$code])) $seen[$code] = (string) ($t['languageName'] ?? $code);
      }
    }
    $out = [];
    foreach($seen as $code => $name) $out[] = ['code' => (string) $code, 'name' => $name];
    hymns_out(200, $out);
  }

  if($action === 'search'){
    $q = trim((string) ($_GET['q'] ?? ''));
    if($q === '' || mb_strlen($q) > 80) hymns_out(400, ['error' => 'bad_query']);
    $items = [];
    foreach(($bundle['hymns'] ?? []) as $h){
      $num = (string) ($h['hymnNumber'] ?? '');
      $hit = (strpos($num, $q) === 0);
      $title = '';
      foreach((array) ($h['translations'] ?? []) as $t){
        $tt = (string) ($t['title'] ?? '');
        if($title === '' && $tt !== '') $title = $tt;
        if(!$hit && $tt !== '' && mb_stripos($tt, $q) !== false) $hit = true;
      }
      if($hit) $items[] = ['songNumber' => $num, 'title' => $title];
      if(count($items) >= 30) break;
    }
    hymns_out(200, $items);
  }

  $number = (string) ($_GET['number'] ?? '');
  if(!preg_match('/^[0-9A-Za-z\-]{1,12}$/', $number)) hymns_out(400, ['error' => 'bad_number']);
  $lang = isset($_GET['lang']) ? (string) $_GET['lang'] : null;
  if($lang !== null && !preg_match('/^[A-Za-z0-9_\-]{1,16}$/', $lang)) $lang = null;

  $hymn = hymns_find($bundle, $number);
  if($hymn === null) hymns_out(404, ['error' => 'not_found']);
  hymns_out(200, hymns_map_for_app($hymn, $lang, $cfg['preferred_langs']));
}catch(Throwable $e){
  error_log('[occasion hymns] ' . $e->getMessage());
  hymns_out(500, ['error' => 'server_error']);
}
