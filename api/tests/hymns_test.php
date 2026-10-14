<?php
// Run:  php api/tests/hymns_test.php        (CLI only; uses a mock HTTP transport and a temp cache dir)
if(PHP_SAPI !== 'cli'){ http_response_code(403); exit; }
require __DIR__ . '/../hymns_lib.php';

$pass = 0; $fail = 0;
function check($name, $cond){ global $pass, $fail; if($cond){ $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name\n"; } }

function tmp_cfg($over = []){
  $dir = sys_get_temp_dir() . '/occ_hymns_' . uniqid();
  return array_merge([
    'base_url' => 'https://hymns.test/hymns', 'api_key' => 'secret-key', 'verify_tls' => false,
    'ttl' => 900, 'cache_dir' => $dir, 'timeout' => 5, 'preferred_langs' => ['ne', 'hi', 'en'],
  ], $over);
}
function mock($responses, &$calls){
  $calls = [];
  $GLOBALS['HYMNS_TRANSPORT'] = function($url, $headers) use (&$responses, &$calls){
    $calls[] = ['url' => $url, 'headers' => $headers];
    return array_shift($responses);
  };
}
$bundle = ['book' => ['slug' => 'default'], 'hymns' => [[
  'hymnNumber' => 22, 'tags' => ['christmas'],
  'translations' => [
    'ne' => ['languageName' => 'Nepali', 'title' => 'तीन ज्योतिषी', 'verses' => [
      ['position' => 2, 'type' => 'chorus', 'label' => 'को:', 'text' => "ओ, कति चम्किलो\nतारा"],
      ['position' => 1, 'type' => 'verse', 'label' => '१', 'text' => "तीन ज्योतिषी\nहामी आयौं"],
    ]],
    'en' => ['languageName' => 'English', 'title' => 'We Three Kings', 'verses' => [['position' => 1, 'type' => 'verse', 'label' => '1', 'text' => 'We three kings']]],
  ],
]]];
$ok200 = ['status' => 200, 'headers' => ['etag' => '"abc"'], 'body' => json_encode($bundle), 'error' => null];

echo "sync:\n";
$cfg = tmp_cfg(); mock([$ok200], $calls);
$r = hymns_sync(false, $cfg);
check('200 stores bundle', $r['status'] === 'updated' && is_file($cfg['cache_dir'] . '/bundle.json'));
check('sends X-Api-Key', in_array('X-Api-Key: secret-key', $calls[0]['headers'], true));
check('calls /api/v1/hymns/bundle', substr($calls[0]['url'], -20) === '/api/v1/hymns/bundle');
$r = hymns_sync(false, $cfg);
check('within TTL: no second HTTP call', $r['status'] === 'fresh' && count($calls) === 1);

mock([['status' => 304, 'headers' => [], 'body' => '', 'error' => null]], $calls);
$r = hymns_sync(true, $cfg);
check('304 keeps copy', $r['status'] === 'not_modified' && hymns_load_bundle($cfg) !== null);
check('sends If-None-Match', in_array('If-None-Match: "abc"', $calls[0]['headers'], true));

foreach([[401, 'http_401'], [403, 'http_403'], [404, 'http_404'], [429, 'http_429']] as [$code, $err]){
  mock([['status' => $code, 'headers' => [], 'body' => '{"error":"x"}', 'error' => null]], $calls);
  $r = hymns_sync(true, $cfg);
  check("$code -> $err, last copy kept", $r['status'] === 'error' && $r['error'] === $err && $r['serving_cached'] === true && hymns_load_bundle($cfg) !== null);
}
mock([['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Operation timed out']], $calls);
$r = hymns_sync(true, $cfg);
check('timeout -> unreachable, last copy kept', $r['error'] === 'unreachable' && hymns_load_bundle($cfg) !== null);
$r = hymns_sync(false, $cfg);
check('failure inside TTL: still fresh, no HTTP call', $r['status'] === 'fresh');
$before = count($calls);
$r = hymns_sync(false, array_merge($cfg, ['ttl' => 0]));
check('TTL expired but failed <60s ago: backoff, no hammering', $r['status'] === 'backoff' && count($calls) === $before);

$cfg2 = tmp_cfg(); mock([['status' => 401, 'headers' => [], 'body' => '', 'error' => null]], $calls);
$r = hymns_sync(false, $cfg2);
check('first-ever sync failing -> no copy, no crash', $r['status'] === 'error' && $r['serving_cached'] === false && hymns_load_bundle($cfg2) === null);
$r = hymns_sync(true, tmp_cfg(['api_key' => '']));
check('missing key -> not_configured (no HTTP)', $r['error'] === 'not_configured');
mock([['status' => 200, 'headers' => [], 'body' => 'not json', 'error' => null]], $calls);
check('garbage 200 body rejected', hymns_sync(true, tmp_cfg())['error'] === 'bad_payload');

echo "mapping / lookup:\n";
$h = hymns_find($bundle, '22');
check('find by number (string vs int)', $h !== null && hymns_find($bundle, '999') === null);
$m = hymns_map_for_app($h, null, ['ne', 'hi', 'en']);
check('preferred language chosen (ne)', $m['languageCode'] === 'ne' && $m['title'] === 'तीन ज्योतिषी');
check('englishTitle from en', $m['englishTitle'] === 'We Three Kings');
check('songNumber is a string', $m['songNumber'] === '22' && $m['bookSlug'] === 'default');
$paras = explode("\n\n", $m['content']);
check('verses ordered by position (verse before chorus)', strpos($paras[0], '१. तीन ज्योतिषी') === 0);
check('chorus marked', strpos($paras[1], HYMNS_CHORUS_MARK) === 0 && strpos($paras[1], 'को: ओ, कति') !== false);
check('line breaks kept inside a verse', strpos($paras[0], "\nहामी आयौं") !== false);
check('explicit lang wins', hymns_map_for_app($h, 'en')['languageCode'] === 'en');
check('unknown lang falls back', hymns_map_for_app($h, 'xx', ['zz'])['languageCode'] === 'ne');
check('no translations -> empty content, no crash', hymns_map_for_app(['hymnNumber' => 5])['content'] === '');

echo "env file parsing:\n";
$env = hymns_parse_env_text("\xEF\xBB\xBF# comment\r\nHYMNS_API_BASE_URL=https://127.0.0.1/hymns\r\nHYMNS_API_KEY=abc123\r\nHYMNS_API_VERIFY_TLS=false        # inline comment\r\nexport HYMNS_CACHE_TTL_MIN=5\r\nQUOTED=\"has # hash\"\r\nSINGLE='x y'\r\n\r\nno_equals_line\r\n");
check('plain values parsed (CRLF + BOM)', $env['HYMNS_API_BASE_URL'] === 'https://127.0.0.1/hymns' && $env['HYMNS_API_KEY'] === 'abc123');
check('inline comment stripped (TLS flag reads as "false")', $env['HYMNS_API_VERIFY_TLS'] === 'false');
check('export prefix handled', $env['HYMNS_CACHE_TTL_MIN'] === '5');
check('quoted value keeps its #', $env['QUOTED'] === 'has # hash' && $env['SINGLE'] === 'x y');
check('junk line ignored', !array_key_exists('no_equals_line', $env));

echo "env file location:\n";
putenv('HYMNS_ENV_FILE');   // make sure it is unset
$c = hymns_env_file_candidates();
check('default: printbee path first, occasion path as fallback', $c === ['/etc/printbee/hymns.env', '/etc/occasion/hymns.env']);
putenv('HYMNS_ENV_FILE=/tmp/custom.env');
check('HYMNS_ENV_FILE overrides the defaults', hymns_env_file_candidates() === ['/tmp/custom.env']);
putenv('HYMNS_ENV_FILE');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
