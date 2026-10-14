<?php
header('Content-Type: application/json');
require __DIR__ . '/db.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$phone = isset($input['phone']) ? normalize_phone($input['phone']) : '';

if(strlen($phone) < 6){
  http_response_code(400);
  echo json_encode(['allowed' => false, 'error' => 'invalid_phone']);
  exit;
}

try{
  $db = get_db();

  $stmt = $db->prepare("SELECT blocked FROM approved_users WHERE phone = :phone");
  $stmt->execute(['phone' => $phone]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if($row === false){
    // First time seeing this number — register it automatically, no admin approval needed.
    $stmt = $db->prepare("INSERT INTO approved_users (phone, added_at, blocked) VALUES (:phone, :now, 0)");
    $stmt->execute(['phone' => $phone, 'now' => gmdate('Y-m-d H:i:s')]);
    $blocked = false;
  } else {
    $blocked = (bool) $row['blocked'];
  }

  if($blocked){
    echo json_encode(['allowed' => false, 'blocked' => true]);
    exit;
  }

  $now = gmdate('Y-m-d H:i:s');
  $stmt = $db->prepare("
    INSERT INTO usage_log (phone, first_seen, last_seen, visit_count)
    VALUES (:phone, :now1, :now2, 1)
    ON CONFLICT(phone) DO UPDATE SET last_seen = :now3, visit_count = visit_count + 1
  ");
  $stmt->execute(['phone' => $phone, 'now1' => $now, 'now2' => $now, 'now3' => $now]);
  echo json_encode(['allowed' => true]);
}catch(Exception $e){
  http_response_code(500);
  echo json_encode(['allowed' => false, 'error' => 'server_error']);
}
