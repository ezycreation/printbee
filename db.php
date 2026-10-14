<?php
function get_db(){
  $dbPath = __DIR__ . '/access.sqlite';
  $db = new PDO('sqlite:' . $dbPath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("CREATE TABLE IF NOT EXISTS approved_users (
    phone TEXT PRIMARY KEY,
    added_at TEXT NOT NULL,
    blocked INTEGER NOT NULL DEFAULT 0
  )");
  // Migration: if this table already existed from before the blocked column was added, add it now.
  try{
    $db->exec("ALTER TABLE approved_users ADD COLUMN blocked INTEGER NOT NULL DEFAULT 0");
  }catch(Exception $e){
    // column already exists — fine, ignore
  }
  $db->exec("CREATE TABLE IF NOT EXISTS usage_log (
    phone TEXT PRIMARY KEY,
    first_seen TEXT NOT NULL,
    last_seen TEXT NOT NULL,
    visit_count INTEGER NOT NULL DEFAULT 1
  )");
  return $db;
}

function normalize_phone($phone){
  $p = trim((string)$phone);
  $p = preg_replace('/[^\d+]/', '', $p);
  return $p;
}
