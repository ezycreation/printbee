<?php
session_name('OCCASION_SESSID');   // own cookie name so sibling apps on this site don't clash
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';

// CHANGE THIS before deploying.
$ADMIN_PASSWORD = 'change-this-password';

if(isset($_POST['password'])){
  if(hash_equals($ADMIN_PASSWORD, $_POST['password'])){
    $_SESSION['admin_ok'] = true;
  }
}
if(isset($_GET['logout'])){
  session_destroy();
  header('Location: admin.php');
  exit;
}

$loggedIn = !empty($_SESSION['admin_ok']);

if(!$loggedIn){
  ?>
  <!DOCTYPE html>
  <html><head><meta charset="UTF-8"><title>Admin login</title>
  <style>
    body{font-family:-apple-system,sans-serif;max-width:320px;margin:100px auto;padding:0 20px;color:#25282b;}
    input{width:100%;padding:10px;margin:10px 0;box-sizing:border-box;border:1px solid #c9d0d4;border-radius:4px;}
    button{width:100%;padding:10px;background:#4f6373;color:#fff;border:none;border-radius:4px;font-weight:600;cursor:pointer;}
  </style>
  </head><body>
  <h2>Admin login</h2>
  <form method="post">
    <input type="password" name="password" placeholder="Password" required autofocus>
    <button type="submit">Log in</button>
  </form>
  </body></html>
  <?php
  exit;
}

$db = get_db();

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_phone'])){
  $phone = normalize_phone($_POST['add_phone']);
  if(strlen($phone) >= 6){
    $stmt = $db->prepare("INSERT OR IGNORE INTO approved_users (phone, added_at, blocked) VALUES (:phone, :now, 0)");
    $stmt->execute(['phone' => $phone, 'now' => gmdate('Y-m-d H:i:s')]);
  }
  header('Location: admin.php');
  exit;
}

if(isset($_GET['remove'])){
  $phone = normalize_phone($_GET['remove']);
  $stmt = $db->prepare("DELETE FROM approved_users WHERE phone = :phone");
  $stmt->execute(['phone' => $phone]);
  $stmt2 = $db->prepare("DELETE FROM usage_log WHERE phone = :phone");
  $stmt2->execute(['phone' => $phone]);
  header('Location: admin.php');
  exit;
}

if(isset($_GET['block'])){
  $phone = normalize_phone($_GET['block']);
  $stmt = $db->prepare("UPDATE approved_users SET blocked = 1 WHERE phone = :phone");
  $stmt->execute(['phone' => $phone]);
  header('Location: admin.php');
  exit;
}

if(isset($_GET['unblock'])){
  $phone = normalize_phone($_GET['unblock']);
  $stmt = $db->prepare("UPDATE approved_users SET blocked = 0 WHERE phone = :phone");
  $stmt->execute(['phone' => $phone]);
  header('Location: admin.php');
  exit;
}

$rows = $db->query("
  SELECT a.phone, a.added_at, a.blocked, u.first_seen, u.last_seen, u.visit_count
  FROM approved_users a
  LEFT JOIN usage_log u ON u.phone = a.phone
  ORDER BY a.added_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$activeThresholdDays = 7;
$now = new DateTime('now', new DateTimeZone('UTC'));
$total = count($rows);
$active = 0;
$neverUsed = 0;
$blockedCount = 0;
foreach($rows as $r){
  if((int)$r['blocked'] === 1){ $blockedCount++; }
  if(empty($r['last_seen'])){ $neverUsed++; continue; }
  $last = new DateTime($r['last_seen'], new DateTimeZone('UTC'));
  $diffDays = $now->diff($last)->days;
  if($diffDays <= $activeThresholdDays) $active++;
}
$inactive = $total - $active - $neverUsed;
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Printbee — Admin</title>
<style>
  body{font-family:-apple-system,sans-serif;max-width:860px;margin:40px auto;padding:0 20px;color:#25282b;}
  h1{font-size:20px; margin-bottom:4px;}
  p.note{font-size:12px; color:#6f7378; margin-top:0;}
  a.logout{float:right;font-size:12px;color:#888;text-decoration:none;}
  .stats{display:flex;gap:16px;margin:20px 0;}
  .stat{flex:1;border:1px solid #c9d0d4;border-radius:6px;padding:14px;text-align:center;}
  .stat .n{font-size:24px;font-weight:700;}
  .stat .l{font-size:11px;color:#6f7378;text-transform:uppercase;letter-spacing:.04em;margin-top:2px;}
  form.add{display:flex;gap:8px;margin:24px 0;}
  form.add input{flex:1;padding:9px;border:1px solid #c9d0d4;border-radius:4px;}
  button{padding:9px 16px;background:#4f6373;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;}
  table{width:100%;border-collapse:collapse;margin-top:8px;}
  th,td{text-align:left;padding:8px;border-bottom:1px solid #e4e4e4;font-size:13px;}
  th{color:#6f7378;font-weight:600;text-transform:uppercase;font-size:11px;}
  .badge{padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
  .badge.active{background:#d7ecd9;color:#2a6b31;}
  .badge.inactive{background:#f3e5e1;color:#a8503f;}
  .badge.never{background:#eee;color:#777;}
  .badge.blocked{background:#a8503f;color:#fff;}
  .action{color:#4f6373;text-decoration:none;font-size:12px;margin-right:10px;}
  .action.remove{color:#a8503f;}
</style>
</head><body>
<a class="logout" href="?logout=1">Log out</a>
<h1>Printbee — Registered users</h1>
<p class="note">Anyone entering a phone number in the app gets access automatically \u2014 no approval needed. Use Block below if you need to revoke someone's access; it takes effect within about a week (their device re-checks periodically).</p>
<div class="stats">
  <div class="stat"><div class="n"><?= $total ?></div><div class="l">Total</div></div>
  <div class="stat"><div class="n"><?= $active ?></div><div class="l">Active</div></div>
  <div class="stat"><div class="n"><?= $inactive ?></div><div class="l">Inactive</div></div>
  <div class="stat"><div class="n"><?= $blockedCount ?></div><div class="l">Blocked</div></div>
</div>
<form class="add" method="post">
  <input type="text" name="add_phone" placeholder="Pre-register a phone number (optional)">
  <button type="submit">Add</button>
</form>
<table>
<tr><th>Phone</th><th>Added</th><th>Last used</th><th>Visits</th><th>Status</th><th></th></tr>
<?php foreach($rows as $r):
  $isBlocked = (int)$r['blocked'] === 1;
  $status = 'never'; $label = 'Never used';
  if($isBlocked){ $status = 'blocked'; $label = 'Blocked'; }
  elseif(!empty($r['last_seen'])){
    $last = new DateTime($r['last_seen'], new DateTimeZone('UTC'));
    $diffDays = $now->diff($last)->days;
    if($diffDays <= $activeThresholdDays){ $status = 'active'; $label = 'Active'; }
    else { $status = 'inactive'; $label = 'Inactive'; }
  }
?>
  <tr>
    <td><?= htmlspecialchars($r['phone']) ?></td>
    <td><?= htmlspecialchars(substr($r['added_at'], 0, 10)) ?></td>
    <td><?= !empty($r['last_seen']) ? htmlspecialchars(substr($r['last_seen'], 0, 10)) : '\u2014' ?></td>
    <td><?= $r['visit_count'] ?: 0 ?></td>
    <td><span class="badge <?= $status ?>"><?= $label ?></span></td>
    <td>
      <?php if($isBlocked): ?>
        <a class="action" href="?unblock=<?= urlencode($r['phone']) ?>">Unblock</a>
      <?php else: ?>
        <a class="action" href="?block=<?= urlencode($r['phone']) ?>" onclick="return confirm('Block this number? Takes effect within about a week.')">Block</a>
      <?php endif; ?>
      <a class="action remove" href="?remove=<?= urlencode($r['phone']) ?>" onclick="return confirm('Remove this number entirely?')">Remove</a>
    </td>
  </tr>
<?php endforeach; ?>
</table>
</body></html>
