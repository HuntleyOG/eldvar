<?php
declare(strict_types=1);
require __DIR__ . '/../../config/config.php';
require __DIR__ . '/../../config/db.php';
if (session_status()===PHP_SESSION_NONE){ session_start(); }
if (empty($_SESSION['user_id'])) { header('Location: ' . BASE_URL . '/login.php'); exit; }

function slugify(string $s): string {
  $s = strtolower(trim(preg_replace('~[^\pL\d]+~u', '-', $s), '-'));
  $s = preg_replace('~[^-\w]+~', '', $s);
  return $s ?: 'cat-'.substr(bin2hex(random_bytes(4)),0,8);
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
  header('Location: ' . ROOT_URL . '/wiki/admin/?err=csrf'); exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
$desc = trim((string)($_POST['description'] ?? ''));
if ($name === '') { header('Location: ' . ROOT_URL . '/wiki/admin/?err=name'); exit; }
if ($slug === '') { $slug = slugify($name); }

$pdo = get_pdo();
$st = $pdo->prepare("INSERT INTO wiki_categories (name, slug, description) VALUES (?,?,?)");
$st->execute([$name,$slug,$desc]);

header('Location: ' . ROOT_URL . '/wiki/admin/?ok=cat');
