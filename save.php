<?php
/* ==========================================================
   Knight's Vision – admin save endpoint (for Hostinger / PHP)
   ----------------------------------------------------------
   >>> CHANGE THE PASSWORD ON THE NEXT LINE BEFORE GOING LIVE <<<
   ========================================================== */
$ADMIN_PASSWORD = 'ChangeMe@2026';

/* ---------- nothing below needs editing ---------- */
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$DIR     = __DIR__;
$DATA    = $DIR . '/data.js';
$UPLOADS = $DIR . '/uploads';
$BACKUPS = $DIR . '/backups';

function out($arr, $code = 200){ http_response_code($code); echo json_encode($arr); exit; }
function authed(){ return !empty($_SESSION['kv_admin']); }

$action = isset($_GET['action']) ? $_GET['action'] : '';

/* ---- check login ---- */
if ($action === 'check') out(['ok' => true, 'loggedIn' => authed()]);

/* ---- login ---- */
if ($action === 'login'){
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);
  $in = json_decode(file_get_contents('php://input'), true);
  $pw = isset($in['password']) ? (string)$in['password'] : '';
  if ($ADMIN_PASSWORD !== '' && hash_equals($ADMIN_PASSWORD, $pw)){
    session_regenerate_id(true);
    $_SESSION['kv_admin'] = true;
    out(['ok' => true]);
  }
  sleep(2); /* slow down password guessing */
  out(['ok' => false, 'error' => 'Wrong password'], 401);
}

/* ---- logout ---- */
if ($action === 'logout'){ $_SESSION = []; session_destroy(); out(['ok' => true]); }

/* ---- save ---- */
if ($action === 'save'){
  if (!authed()) out(['ok' => false, 'error' => 'Not logged in'], 401);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);
  $in = json_decode(file_get_contents('php://input'), true);
  if (!is_array($in) || !isset($in['gallery'], $in['programs']) || !is_array($in['gallery']) || !is_array($in['programs']))
    out(['ok' => false, 'error' => 'Invalid data'], 400);

  if (!is_dir($UPLOADS)) @mkdir($UPLOADS, 0755, true);
  if (!file_exists($UPLOADS . '/.htaccess'))
    @file_put_contents($UPLOADS . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");

  /* gallery: turn embedded photos into real image files */
  $gallery = [];
  foreach ($in['gallery'] as $g){
    if (!is_array($g)) continue;
    $photo = isset($g['photo']) ? (string)$g['photo'] : '';
    if (preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,(.+)$#s', $photo, $m)){
      $bin = base64_decode($m[2], true);
      if ($bin === false || @getimagesizefromstring($bin) === false) continue;
      $ext = ($m[1] === 'jpg') ? 'jpeg' : $m[1];
      $ext = ($ext === 'jpeg') ? 'jpg' : $ext;
      $name = 'kv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      if (@file_put_contents($UPLOADS . '/' . $name, $bin) === false) out(['ok' => false, 'error' => 'Could not write photo to uploads folder'], 500);
      $photo = 'uploads/' . $name;
    } elseif (!preg_match('#^(https?://|uploads/kv_[\w.-]+$)#i', $photo)){
      continue; /* drop anything that is not an uploaded file or a web link */
    }
    $gallery[] = [
      'photo' => $photo,
      'desc'  => isset($g['desc']) ? mb_substr((string)$g['desc'], 0, 500) : '',
      'link'  => isset($g['link']) ? mb_substr((string)$g['link'], 0, 500) : ''
    ];
  }

  $programs = [];
  foreach ($in['programs'] as $p){
    if (!is_array($p)) continue;
    $programs[] = [
      'title'    => isset($p['title']) ? (string)$p['title'] : '',
      'mode'     => isset($p['mode']) ? (string)$p['mode'] : '',
      'features' => isset($p['features']) && is_array($p['features']) ? array_values(array_map('strval', $p['features'])) : [],
      'popular'  => !empty($p['popular']),
      'btn'      => isset($p['btn']) ? (string)$p['btn'] : '',
      'wa'       => isset($p['wa']) ? (string)$p['wa'] : ''
    ];
  }

  $json = json_encode(['gallery' => $gallery, 'programs' => $programs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
  if ($json === false) out(['ok' => false, 'error' => 'Could not encode data'], 500);

  /* keep a backup of the previous data.js (last 15) */
  if (file_exists($DATA)){
    if (!is_dir($BACKUPS)) @mkdir($BACKUPS, 0755, true);
    if (!file_exists($BACKUPS . '/.htaccess')) @file_put_contents($BACKUPS . '/.htaccess', "Require all denied\n");
    @copy($DATA, $BACKUPS . '/data_' . date('Ymd_His') . '.js');
    $old = glob($BACKUPS . '/data_*.js'); sort($old);
    while (count($old) > 15) @unlink(array_shift($old));
  }

  $tmp = $DATA . '.tmp';
  if (@file_put_contents($tmp, 'window.KV_DATA = ' . $json . ";\n", LOCK_EX) === false || !@rename($tmp, $DATA))
    out(['ok' => false, 'error' => 'Could not write data.js – check folder permissions'], 500);

  /* remove photos no longer used */
  $used = [];
  foreach ($gallery as $g) if (strpos($g['photo'], 'uploads/') === 0) $used[basename($g['photo'])] = true;
  foreach (glob($UPLOADS . '/kv_*') as $f) if (!isset($used[basename($f)])) @unlink($f);

  out(['ok' => true, 'data' => ['gallery' => $gallery, 'programs' => $programs]]);
}

out(['ok' => false, 'error' => 'Unknown action'], 400);
