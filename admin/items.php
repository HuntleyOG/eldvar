<?php
declare(strict_types=1);

/**
 * /admin/items.php — Eldvar Admin: Create, Edit, and Manage Images
 *
 * Modes:
 *   - default: create + list
 *   - ?edit={id}: edit existing item
 *   - ?images={id}: manage images for item
 */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_roles(['Administrator','Moderator','Librarian', 'Governor']);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ---------- Ensure Tables ---------- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(140) NOT NULL UNIQUE,
  type ENUM('weapon','armor','consumable','material','quest','misc') NOT NULL DEFAULT 'misc',
  rarity ENUM('common','uncommon','rare','epic','legendary','mythic') NOT NULL DEFAULT 'common',
  description TEXT NULL,
  icon_path VARCHAR(255) NULL,
  stackable TINYINT(1) NOT NULL DEFAULT 1,
  max_stack INT NOT NULL DEFAULT 99,
  base_value INT NOT NULL DEFAULT 0,
  bind_on_pickup TINYINT(1) NOT NULL DEFAULT 0,
  usable TINYINT(1) NOT NULL DEFAULT 0,
  level_requirement INT NOT NULL DEFAULT 1,
  use_script VARCHAR(128) NULL,
  use_payload JSON NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (type), INDEX (rarity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS item_modifiers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  stat_name VARCHAR(64) NOT NULL,
  flat_amount INT NOT NULL DEFAULT 0,
  percent_amount DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  INDEX (item_id), INDEX (stat_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS item_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  path VARCHAR(255) NOT NULL,
  alt_text VARCHAR(190) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  INDEX (item_id), INDEX (is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------- Helpers ---------- */
function make_csrf(string $key='items'): string {
  $slot = "csrf_$key";
  if (empty($_SESSION[$slot])) $_SESSION[$slot] = bin2hex(random_bytes(32));
  return $_SESSION[$slot];
}
function check_csrf_token(string $token, string $key='items'): bool {
  $slot = "csrf_$key";
  return isset($_SESSION[$slot]) && hash_equals($_SESSION[$slot], $token);
}
function slugify(string $str): string {
  $s = mb_strtolower(trim($str), 'UTF-8');
  $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
  return trim($s, '-') ?: 'item';
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Shared upload config ---------- */
$upload_dir_fs = __DIR__ . '/../public/assets/items/';
$upload_dir_url = '/public/assets/items/';
if (!is_dir($upload_dir_fs)) { @mkdir($upload_dir_fs, 0775, true); }

/* ---------- Mode switches ---------- */
$itemIdForImages = isset($_GET['images']) ? (int)$_GET['images'] : 0;
$itemIdForEdit   = isset($_GET['edit'])   ? (int)$_GET['edit']   : 0;

$errors  = [];
$notices = [];

$csrf_items = make_csrf('items');
$csrf_imgs  = make_csrf('item_images');
$csrf_edit  = make_csrf('item_edit');

/* ---------- If images mode, fetch item ---------- */
if ($itemIdForImages > 0) {
  $item = $pdo->prepare("SELECT id, name, slug, icon_path FROM items WHERE id = ?");
  $item->execute([$itemIdForImages]);
  $item = $item->fetch();
  if (!$item) {
    $errors[] = 'Item not found.';
    $itemIdForImages = 0;
  }
}

/* ---------- If edit mode, fetch item + modifiers ---------- */
$editItem = null;
$editMods = [];
if ($itemIdForEdit > 0) {
  $st = $pdo->prepare("SELECT * FROM items WHERE id = ?");
  $st->execute([$itemIdForEdit]);
  $editItem = $st->fetch();
  if (!$editItem) {
    $errors[] = 'Item not found for edit.';
    $itemIdForEdit = 0;
  } else {
    $mm = $pdo->prepare("SELECT stat_name, flat_amount, percent_amount FROM item_modifiers WHERE item_id = ? ORDER BY id ASC");
    $mm->execute([$itemIdForEdit]);
    $editMods = $mm->fetchAll();
  }
}

/* ---------- CREATE item ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create' && $itemIdForImages === 0 && $itemIdForEdit === 0) {
  if (!check_csrf_token($_POST['csrf'] ?? '', 'items')) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    $name  = trim($_POST['name'] ?? '');
    $slug  = slugify(trim($_POST['slug'] ?? '') ?: $name);
    $type  = $_POST['type'] ?? 'misc';
    $rarity= $_POST['rarity'] ?? 'common';
    $description = trim($_POST['description'] ?? '');
    $stackable = !empty($_POST['stackable']) ? 1 : 0;
    $max_stack = (int)($_POST['max_stack'] ?? 1);
    $base_value = (int)($_POST['base_value'] ?? 0);
    $bind_on_pickup = !empty($_POST['bind_on_pickup']) ? 1 : 0;
    $usable = !empty($_POST['usable']) ? 1 : 0;
    $level_requirement = max(1, (int)($_POST['level_requirement'] ?? 1));
    $use_script = trim($_POST['use_script'] ?? '');
    $use_payload_raw = trim($_POST['use_payload'] ?? '');

    $validTypes=['weapon','armor','consumable','material','quest','misc'];
    $validRar=['common','uncommon','rare','epic','legendary','mythic'];
    if ($name==='') $errors[]='Name is required.';
    if (!in_array($type,$validTypes,true)) $errors[]='Invalid type.';
    if (!in_array($rarity,$validRar,true)) $errors[]='Invalid rarity.';
    if ($stackable===0) $max_stack = 1;
    if ($max_stack<1) $errors[]='Max stack must be at least 1.';
    if ($base_value<0) $errors[]='Base value cannot be negative.';

    $use_payload=null;
    if ($use_payload_raw!=='') {
      $decoded=json_decode($use_payload_raw,true);
      if (json_last_error()!==JSON_ERROR_NONE) $errors[]='Use payload must be valid JSON.';
      else $use_payload=json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    $icon_path = null;
    if (!empty($_FILES['icon']['name'])) {
      if (!is_uploaded_file($_FILES['icon']['tmp_name'])) $errors[]='Invalid icon upload.';
      else {
        $finfo=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($finfo,$_FILES['icon']['tmp_name']); finfo_close($finfo);
        $allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
        if (!isset($allowed[$mime])) $errors[]='Icon must be PNG, JPG, GIF, or WEBP.';
        if ($_FILES['icon']['size'] > 4*1024*1024) $errors[]='Icon exceeds 4MB.';
        if (!$errors) {
          $ext=$allowed[$mime];
          $fname=$slug.'-'.time().'-'.bin2hex(random_bytes(4)).'.'.$ext;
          $dest=$upload_dir_fs.$fname;
          if (!move_uploaded_file($_FILES['icon']['tmp_name'],$dest)) $errors[]='Failed to move uploaded icon.';
          else { @chmod($dest,0644); $icon_path=$upload_dir_url.$fname; }
        }
      }
    }

    if (!$errors) {
      try {
        $pdo->beginTransaction();
        // ensure unique slug
        $base=$slug; $i=1;
        while (true) {
          $st=$pdo->prepare("SELECT id FROM items WHERE slug=?"); $st->execute([$slug]);
          if (!$st->fetch()) break; $slug=$base.'-'.$i; $i++;
        }

        $st=$pdo->prepare("
          INSERT INTO items (name, slug, type, rarity, description, icon_path, stackable, max_stack, base_value, bind_on_pickup, usable, level_requirement, use_script, use_payload, created_by)
          VALUES (:name,:slug,:type,:rarity,:desc,:icon,:stack,:maxs,:val,:bop,:usable,:lvl,:script,CAST(:payload AS JSON),:uid)
        ");
        $st->execute([
          ':name'=>$name, ':slug'=>$slug, ':type'=>$type, ':rarity'=>$rarity,
          ':desc'=>$description!==''?$description:null,
          ':icon'=>$icon_path, ':stack'=>$stackable, ':maxs'=>$max_stack,
          ':val'=>$base_value, ':bop'=>$bind_on_pickup, ':usable'=>$usable,
          ':lvl'=>$level_requirement, ':script'=>$use_script!==''?$use_script:null,
          ':payload'=>$use_payload,
          ':uid'=>function_exists('current_user_id')?current_user_id():null
        ]);
        $item_id=(int)$pdo->lastInsertId();

        // modifiers
        $mod_names=$_POST['mod_stat_name']??[];
        $mod_flat=$_POST['mod_flat_amount']??[];
        $mod_pct=$_POST['mod_percent_amount']??[];
        if (is_array($mod_names) && $mod_names) {
          $ins=$pdo->prepare("INSERT INTO item_modifiers (item_id, stat_name, flat_amount, percent_amount) VALUES (:id,:stat,:flat,:pct)");
          for($j=0;$j<count($mod_names);$j++){
            $stat=trim((string)($mod_names[$j]??''));
            if ($stat==='') continue;
            $ins->execute([':id'=>$item_id,':stat'=>$stat,':flat'=>(int)($mod_flat[$j]??0),':pct'=>(float)($mod_pct[$j]??0)]);
          }
        }

        if ($icon_path) {
          $pdo->prepare("INSERT INTO item_images (item_id,path,alt_text,is_primary) VALUES (?,?,?,1)")
              ->execute([$item_id,$icon_path,null]);
        }

        $pdo->commit();
        $notices[]='Item created: '.h($name).' — <a href="?images='.$item_id.'">Manage Images</a> · <a href="?edit='.$item_id.'">Edit</a>';
        $_POST=[];
      } catch (Throwable $e) {
        $pdo->rollBack();
        $errors[]='DB error: '.h($e->getMessage());
      }
    }
  }
}

/* ---------- UPDATE item (edit mode) ---------- */
if ($itemIdForEdit > 0 && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update') {
  if (!check_csrf_token($_POST['csrf'] ?? '', 'item_edit')) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    $name  = trim($_POST['name'] ?? '');
    $slug  = slugify(trim($_POST['slug'] ?? '') ?: $name);
    $type  = $_POST['type'] ?? 'misc';
    $rarity= $_POST['rarity'] ?? 'common';
    $description = trim($_POST['description'] ?? '');
    $stackable = !empty($_POST['stackable']) ? 1 : 0;
    $max_stack = (int)($_POST['max_stack'] ?? 1);
    $base_value = (int)($_POST['base_value'] ?? 0);
    $bind_on_pickup = !empty($_POST['bind_on_pickup']) ? 1 : 0;
    $usable = !empty($_POST['usable']) ? 1 : 0;
    $level_requirement = max(1, (int)($_POST['level_requirement'] ?? 1));
    $use_script = trim($_POST['use_script'] ?? '');
    $use_payload_raw = trim($_POST['use_payload'] ?? '');

    $validTypes=['weapon','armor','consumable','material','quest','misc'];
    $validRar=['common','uncommon','rare','epic','legendary','mythic'];
    if ($name==='') $errors[]='Name is required.';
    if (!in_array($type,$validTypes,true)) $errors[]='Invalid type.';
    if (!in_array($rarity,$validRar,true)) $errors[]='Invalid rarity.';
    if ($stackable===0) $max_stack = 1;
    if ($max_stack<1) $errors[]='Max stack must be at least 1.';
    if ($base_value<0) $errors[]='Base value cannot be negative.';

    $use_payload=null;
    if ($use_payload_raw!=='') {
      $decoded=json_decode($use_payload_raw,true);
      if (json_last_error()!==JSON_ERROR_NONE) $errors[]='Use payload must be valid JSON.';
      else $use_payload=json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    // Optional new icon upload
    $new_icon_path = null;
    if (!empty($_FILES['icon']['name'])) {
      if (!is_uploaded_file($_FILES['icon']['tmp_name'])) $errors[]='Invalid icon upload.';
      else {
        $finfo=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($finfo,$_FILES['icon']['tmp_name']); finfo_close($finfo);
        $allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
        if (!isset($allowed[$mime])) $errors[]='Icon must be PNG, JPG, GIF, or WEBP.';
        if ($_FILES['icon']['size'] > 4*1024*1024) $errors[]='Icon exceeds 4MB.';
        if (!$errors) {
          $ext=$allowed[$mime];
          $fname=$slug.'-'.time().'-'.bin2hex(random_bytes(4)).'.'.$ext;
          $dest=$upload_dir_fs.$fname;
          if (!move_uploaded_file($_FILES['icon']['tmp_name'],$dest)) $errors[]='Failed to move uploaded icon.';
          else { @chmod($dest,0644); $new_icon_path=$upload_dir_url.$fname; }
        }
      }
    }

    if (!$errors) {
      try {
        $pdo->beginTransaction();

        // enforce unique slug (other than this item)
        $st=$pdo->prepare("SELECT id FROM items WHERE slug=? AND id<>?");
        $st->execute([$slug, $itemIdForEdit]);
        if ($st->fetch()) {
          // add numeric suffix until unique
          $base=$slug; $i=1;
          do {
            $slug=$base.'-'.$i; $i++;
            $st=$pdo->prepare("SELECT id FROM items WHERE slug=? AND id<>?");
            $st->execute([$slug, $itemIdForEdit]);
          } while ($st->fetch());
        }

        $upd=$pdo->prepare("
          UPDATE items SET
            name=:name, slug=:slug, type=:type, rarity=:rarity,
            description=:desc, stackable=:stack, max_stack=:maxs,
            base_value=:val, bind_on_pickup=:bop, usable=:usable,
            level_requirement=:lvl, use_script=:script, use_payload=CAST(:payload AS JSON)
          WHERE id=:id
        ");
        $upd->execute([
          ':name'=>$name, ':slug'=>$slug, ':type'=>$type, ':rarity'=>$rarity,
          ':desc'=>$description!==''?$description:null,
          ':stack'=>$stackable, ':maxs'=>$max_stack, ':val'=>$base_value,
          ':bop'=>$bind_on_pickup, ':usable'=>$usable, ':lvl'=>$level_requirement,
          ':script'=>$use_script!==''?$use_script:null, ':payload'=>$use_payload,
          ':id'=>$itemIdForEdit
        ]);

        // Replace modifiers
        $pdo->prepare("DELETE FROM item_modifiers WHERE item_id=?")->execute([$itemIdForEdit]);
        $mod_names=$_POST['mod_stat_name']??[];
        $mod_flat=$_POST['mod_flat_amount']??[];
        $mod_pct=$_POST['mod_percent_amount']??[];
        if (is_array($mod_names) && $mod_names) {
          $ins=$pdo->prepare("INSERT INTO item_modifiers (item_id, stat_name, flat_amount, percent_amount) VALUES (:id,:stat,:flat,:pct)");
          for($j=0;$j<count($mod_names);$j++){
            $stat=trim((string)($mod_names[$j]??''));
            if ($stat==='') continue;
            $ins->execute([':id'=>$itemIdForEdit,':stat'=>$stat,':flat'=>(int)($mod_flat[$j]??0),':pct'=>(float)($mod_pct[$j]??0)]);
          }
        }

        // If a new icon was uploaded, set it as primary and item icon
        if ($new_icon_path) {
          $pdo->prepare("UPDATE item_images SET is_primary=0 WHERE item_id=?")->execute([$itemIdForEdit]);
          $pdo->prepare("INSERT INTO item_images (item_id,path,alt_text,is_primary) VALUES (?,?,?,1)")
              ->execute([$itemIdForEdit,$new_icon_path,null]);
          $pdo->prepare("UPDATE items SET icon_path=? WHERE id=?")->execute([$new_icon_path,$itemIdForEdit]);
        }

        $pdo->commit();
        $notices[]='Item updated.';
        // Refresh edit data
        header('Location: ./items.php?edit='.$itemIdForEdit);
        exit;
      } catch (Throwable $e) {
        $pdo->rollBack();
        $errors[]='DB error: '.h($e->getMessage());
      }
    }
  }
}

/* ---------- IMAGE actions in images mode ---------- */
if ($itemIdForImages>0 && $_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  if (!check_csrf_token($_POST['csrf'] ?? '', 'item_images')) {
    $errors[]='Invalid CSRF token.';
  } else switch ($action) {
    case 'upload_image':
      if (empty($_FILES['image']['name'])) { $errors[]='Choose an image file.'; break; }
      if (!is_uploaded_file($_FILES['image']['tmp_name'])) { $errors[]='Invalid upload.'; break; }
      $finfo=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($finfo,$_FILES['image']['tmp_name']); finfo_close($finfo);
      $allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
      if (!isset($allowed[$mime])) { $errors[]='File must be PNG/JPG/GIF/WEBP.'; break; }
      if ($_FILES['image']['size']>4*1024*1024){ $errors[]='Image exceeds 4MB.'; break; }
      $slug = $item['slug'] ?? ('item-'.$itemIdForImages);
      $ext=$allowed[$mime];
      $fname=$slug.'-'.time().'-'.bin2hex(random_bytes(4)).'.'.$ext;
      $dest=$upload_dir_fs.$fname;
      if (!move_uploaded_file($_FILES['image']['tmp_name'],$dest)) { $errors[]='Failed to store image.'; break; }
      @chmod($dest,0644);
      $url=$upload_dir_url.$fname;
      $alt = trim($_POST['alt_text'] ?? '');
      $pdo->prepare("INSERT INTO item_images (item_id,path,alt_text,is_primary) VALUES (?,?,?,0)")
          ->execute([$itemIdForImages,$url, ($alt!==''?$alt:null)]);
      $notices[]='Image uploaded.';
      break;

    case 'set_primary':
      $imgId = (int)($_POST['img_id'] ?? 0);
      $img = $pdo->prepare("SELECT id, path FROM item_images WHERE id=? AND item_id=?");
      $img->execute([$imgId,$itemIdForImages]); $img=$img->fetch();
      if (!$img) { $errors[]='Image not found.'; break; }
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE item_images SET is_primary=0 WHERE item_id=?")->execute([$itemIdForImages]);
      $pdo->prepare("UPDATE item_images SET is_primary=1 WHERE id=?")->execute([$imgId]);
      $pdo->prepare("UPDATE items SET icon_path=? WHERE id=?")->execute([$img['path'],$itemIdForImages]);
      $pdo->commit();
      $notices[]='Primary image set.';
      break;

    case 'delete_image':
      $imgId = (int)($_POST['img_id'] ?? 0);
      $img = $pdo->prepare("SELECT id, path, is_primary FROM item_images WHERE id=? AND item_id=?");
      $img->execute([$imgId,$itemIdForImages]); $img=$img->fetch();
      if (!$img) { $errors[]='Image not found.'; break; }
      $pdo->prepare("DELETE FROM item_images WHERE id=?")->execute([$imgId]);
      $basename = basename($img['path']);
      $fs = $upload_dir_fs.$basename;
      if (is_file($fs)) @unlink($fs);
      if ((int)$img['is_primary'] === 1) {
        $next = $pdo->prepare("SELECT path FROM item_images WHERE item_id=? ORDER BY id DESC LIMIT 1");
        $next->execute([$itemIdForImages]); $next=$next->fetch();
        $pdo->prepare("UPDATE items SET icon_path=? WHERE id=?")
            ->execute([$next ? $next['path'] : null, $itemIdForImages]);
        if ($next) {
          $pdo->prepare("UPDATE item_images SET is_primary=1 WHERE item_id=? AND path=?")
              ->execute([$itemIdForImages, $next['path']]);
        }
      }
      $notices[]='Image deleted.';
      break;
  }
}

/* ---------- Fetch data for view ---------- */
if ($itemIdForImages>0) {
  $imgs = $pdo->prepare("SELECT id, path, alt_text, is_primary, created_at FROM item_images WHERE item_id=? ORDER BY id DESC");
  $imgs->execute([$itemIdForImages]); $imgs=$imgs->fetchAll();
} elseif ($itemIdForEdit>0 && $editItem) {
  // nothing extra; already fetched above
} else {
  $items = $pdo->query("SELECT id,name,slug,type,rarity,base_value,stackable,max_stack,usable,icon_path,created_at FROM items ORDER BY id DESC LIMIT 100")->fetchAll();
}

/* ---------- View ---------- */
$PAGE_TITLE =
  $itemIdForImages>0 ? 'Admin · Item Images' :
  ($itemIdForEdit>0 ? 'Admin · Edit Item' : 'Admin · Items');

if (file_exists(__DIR__ . '/../includes/header.php')) require __DIR__ . '/../includes/header.php';
if (file_exists(__DIR__ . '/../includes/sidebar.php')) require __DIR__ . '/../includes/sidebar.php';
?>
<style>
  .panel{background:#12141a;border:1px solid #232634;border-radius:10px;padding:16px;margin:16px 0;color:#e7e7ea}
  .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:10px}
  .grid-6{grid-column:span 6}.grid-12{grid-column:span 12}
  label{display:block;margin:6px 0 4px}
  input[type=text], input[type=number], textarea, select{width:100%;background:#0e0f12;border:1px solid #232634;border-radius:8px;padding:8px;color:#e7e7ea}
  .btn{background:#77dd77;border:none;color:#0b0c10;padding:10px 14px;border-radius:10px;cursor:pointer;font-weight:700}
  .btn.secondary{background:#a0e0ff;color:#0b0c10}.btn.danger{background:#e57373;color:#0b0c10}
  .muted{color:#a7a8b2}.table{width:100%;border-collapse:collapse}.table th,.table td{border-bottom:1px solid #232634;padding:8px;text-align:left}
  .thumb{width:80px;height:80px;object-fit:contain;border:1px solid #232634;border-radius:8px;background:#0e0f12}
  .icon{width:28px;height:28px;object-fit:contain;border:1px solid #232634;border-radius:6px;background:#0e0f12}
  .mods-row{display:flex;gap:8px;margin-bottom:8px}
  .mods-row input[type=text]{flex:2}.mods-row input[type=number]{flex:1}
  .row{display:flex;gap:16px;flex-wrap:wrap}.col{flex:1 1 360px;min-width:320px}
</style>

<main style="padding:16px;">

<?php if ($itemIdForImages>0): ?>
  <h1>Admin · Manage Images — <?php echo h($item['name']); ?></h1>

  <?php if ($errors): ?><div class="panel" style="border-color:#e57373"><ul><?php foreach($errors as $e){echo '<li>'.h($e).'</li>';} ?></ul></div><?php endif; ?>
  <?php if ($notices): ?><div class="panel" style="border-color:#77dd77"><ul><?php foreach($notices as $n){echo '<li>'.$n.'</li>'; } ?></ul></div><?php endif; ?>

  <a class="btn secondary" href="./items.php">← Back to Items</a>
  <a class="btn" style="margin-left:8px" href="./items.php?edit=<?= (int)$item['id'] ?>">Edit Item</a>

  <section class="panel">
    <h2>Upload Image</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf_imgs) ?>">
      <input type="hidden" name="action" value="upload_image">
      <label for="image">Image (PNG/JPG/GIF/WEBP, ≤ 4MB)</label>
      <input id="image" name="image" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" required>
      <label for="alt_text">Alt Text (optional)</label>
      <input id="alt_text" name="alt_text" type="text" placeholder="e.g., Healing Potion (Small)">
      <div style="margin-top:10px"><button class="btn" type="submit">Upload</button></div>
    </form>
  </section>

  <section class="panel">
    <h2>Images</h2>
    <?php if (empty($imgs)): ?>
      <div class="muted">No images yet.</div>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Preview</th><th>Alt</th><th>Primary</th><th>Added</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($imgs as $im): ?>
            <tr>
              <td><img class="thumb" src="<?= h($im['path']) ?>" alt=""></td>
              <td><?= h($im['alt_text'] ?? '') ?></td>
              <td><?= (int)$im['is_primary']===1 ? 'Yes' : 'No' ?></td>
              <td class="muted"><?= h($im['created_at']) ?></td>
              <td style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <?php if ((int)$im['is_primary']!==1): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= h($csrf_imgs) ?>">
                    <input type="hidden" name="action" value="set_primary">
                    <input type="hidden" name="img_id" value="<?= (int)$im['id'] ?>">
                    <button class="btn secondary" type="submit">Set Primary</button>
                  </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Delete this image?');" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h($csrf_imgs) ?>">
                  <input type="hidden" name="action" value="delete_image">
                  <input type="hidden" name="img_id" value="<?= (int)$im['id'] ?>">
                  <button class="btn danger" type="submit">Delete</button>
                </form>
                <a class="btn" href="<?= h($im['path']) ?>" target="_blank" rel="noopener">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

<?php elseif ($itemIdForEdit>0 && $editItem): ?>
  <h1>Admin · Edit Item — <?= h($editItem['name']) ?></h1>

  <?php if ($errors): ?><div class="panel" style="border-color:#e57373"><ul><?php foreach($errors as $e){echo '<li>'.h($e).'</li>';} ?></ul></div><?php endif; ?>
  <?php if ($notices): ?><div class="panel" style="border-color:#77dd77"><ul><?php foreach($notices as $n){echo '<li>'.$n.'</li>'; } ?></ul></div><?php endif; ?>

  <a class="btn secondary" href="./items.php">← Back to Items</a>
  <a class="btn" style="margin-left:8px" href="./items.php?images=<?= (int)$editItem['id'] ?>">Manage Images</a>

  <form class="panel" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($csrf_edit) ?>">
    <input type="hidden" name="action" value="update">

    <div class="grid">
      <div class="grid-6">
        <label for="name">Item Name *</label>
        <input id="name" name="name" type="text" required value="<?= h($_POST['name'] ?? $editItem['name']) ?>">
      </div>
      <div class="grid-6">
        <label for="slug">Slug</label>
        <input id="slug" name="slug" type="text" value="<?= h($_POST['slug'] ?? $editItem['slug']) ?>">
      </div>

      <div class="grid-6">
        <label for="type">Type</label>
        <select id="type" name="type">
          <?php $types=['weapon','armor','consumable','material','quest','misc']; $cur=$_POST['type']??$editItem['type'];
          foreach($types as $t){ $sel=$t===$cur?'selected':''; echo "<option $sel value=\"".h($t)."\">".ucfirst($t)."</option>"; } ?>
        </select>
      </div>
      <div class="grid-6">
        <label for="rarity">Rarity</label>
        <select id="rarity" name="rarity">
          <?php $rars=['common','uncommon','rare','epic','legendary','mythic']; $cur=$_POST['rarity']??$editItem['rarity'];
          foreach($rars as $r){ $sel=$r===$cur?'selected':''; echo "<option $sel value=\"".h($r)."\">".ucfirst($r)."</option>"; } ?>
        </select>
      </div>

      <div class="grid-12">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3"><?= h($_POST['description'] ?? ($editItem['description'] ?? '')) ?></textarea>
      </div>

      <div class="grid-6">
        <label><input type="checkbox" name="stackable" <?= (($_POST['stackable'] ?? $editItem['stackable']) ? 'checked' : '') ?>> Stackable</label>
        <label class="muted" for="max_stack">Max Stack</label>
        <input id="max_stack" name="max_stack" type="number" min="1" value="<?= h($_POST['max_stack'] ?? (string)$editItem['max_stack']) ?>">
      </div>
      <div class="grid-6">
        <label for="base_value">Base Value (coins)</label>
        <input id="base_value" name="base_value" type="number" min="0" value="<?= h($_POST['base_value'] ?? (string)$editItem['base_value']) ?>">
        <label><input type="checkbox" name="bind_on_pickup" <?= (($_POST['bind_on_pickup'] ?? $editItem['bind_on_pickup']) ? 'checked' : '') ?>> Bind on Pickup</label>
      </div>

      <div class="grid-6">
        <label><input type="checkbox" name="usable" <?= (($_POST['usable'] ?? $editItem['usable']) ? 'checked' : '') ?>> Usable</label>
        <label class="muted" for="level_requirement">Level Requirement</label>
        <input id="level_requirement" name="level_requirement" type="number" min="1" value="<?= h($_POST['level_requirement'] ?? (string)$editItem['level_requirement']) ?>">
      </div>
      <div class="grid-6">
        <label for="use_script">Use Script (optional)</label>
        <input id="use_script" name="use_script" type="text" value="<?= h($_POST['use_script'] ?? ($editItem['use_script'] ?? '')) ?>">
        <label for="icon">Replace Icon (PNG/JPG/GIF/WEBP, ≤ 4MB)</label>
        <input id="icon" name="icon" type="file" accept=".png,.jpg,.jpeg,.gif,.webp">
        <?php if (!empty($editItem['icon_path'])): ?>
          <div class="muted" style="margin-top:6px">Current icon:</div>
          <img class="thumb" src="<?= h($editItem['icon_path']) ?>" alt="">
        <?php endif; ?>
      </div>

      <div class="grid-12">
        <label for="use_payload">Use Payload (JSON, optional)</label>
        <textarea id="use_payload" name="use_payload" rows="3"><?= h($_POST['use_payload'] ?? (is_null($editItem['use_payload']) ? '' : $editItem['use_payload'])) ?></textarea>
      </div>
    </div>

    <h3 style="margin-top:8px;">Stat Modifiers</h3>
    <div id="mods">
      <?php
        $mod_stat_name = $_POST['mod_stat_name'] ?? array_column($editMods,'stat_name');
        $mod_flat = $_POST['mod_flat_amount'] ?? array_map(fn($m)=> (string)$m['flat_amount'], $editMods);
        $mod_percent = $_POST['mod_percent_amount'] ?? array_map(fn($m)=> (string)$m['percent_amount'], $editMods);
        $rows = max(1, count((array)$mod_stat_name));
        for ($i=0; $i<$rows; $i++):
      ?>
      <div class="mods-row">
        <input type="text" name="mod_stat_name[]" placeholder="Stat (e.g., Strength)" value="<?= h($mod_stat_name[$i] ?? '') ?>">
        <input type="number" name="mod_flat_amount[]" placeholder="Flat (+/-)" value="<?= h($mod_flat[$i] ?? '0') ?>">
        <input type="number" step="0.01" name="mod_percent_amount[]" placeholder="% (+/-)" value="<?= h($mod_percent[$i] ?? '0') ?>">
      </div>
      <?php endfor; ?>
    </div>
    <button type="button" class="btn secondary" onclick="addModRow()">+ Add Modifier</button>

    <div style="margin-top:12px;">
      <button class="btn" type="submit">Save Changes</button>
    </div>
  </form>

<?php else: ?>
  <h1>Admin · Create Item</h1>

  <?php if ($errors): ?><div class="panel" style="border-color:#e57373"><ul><?php foreach($errors as $e){echo '<li>'.h($e).'</li>';} ?></ul></div><?php endif; ?>
  <?php if ($notices): ?><div class="panel" style="border-color:#77dd77"><ul><?php foreach($notices as $n){echo '<li>'.$n.'</li>'; } ?></ul></div><?php endif; ?>

  <form class="panel" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($csrf_items) ?>">
    <input type="hidden" name="action" value="create">

    <div class="grid">
      <div class="grid-6">
        <label for="name">Item Name *</label>
        <input id="name" name="name" type="text" required value="<?= h($_POST['name'] ?? '') ?>">
      </div>
      <div class="grid-6">
        <label for="slug">Slug (optional)</label>
        <input id="slug" name="slug" type="text" placeholder="auto-from-name" value="<?= h($_POST['slug'] ?? '') ?>">
      </div>

      <div class="grid-6">
        <label for="type">Type</label>
        <select id="type" name="type">
          <?php $types=['weapon','armor','consumable','material','quest','misc']; $cur=$_POST['type']??'misc';
          foreach($types as $t){ $sel=$t===$cur?'selected':''; echo "<option $sel value=\"".h($t)."\">".ucfirst($t)."</option>"; } ?>
        </select>
      </div>
      <div class="grid-6">
        <label for="rarity">Rarity</label>
        <select id="rarity" name="rarity">
          <?php $rars=['common','uncommon','rare','epic','legendary','mythic']; $cur=$_POST['rarity']??'common';
          foreach($rars as $r){ $sel=$r===$cur?'selected':''; echo "<option $sel value=\"".h($r)."\">".ucfirst($r)."</option>"; } ?>
        </select>
      </div>

      <div class="grid-12">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3" placeholder="Flavor text & details..."><?= h($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="grid-6">
        <label><input type="checkbox" name="stackable" <?= !empty($_POST['stackable'])?'checked':''; ?>> Stackable</label>
        <label class="muted" for="max_stack">Max Stack</label>
        <input id="max_stack" name="max_stack" type="number" min="1" value="<?= h($_POST['max_stack'] ?? '99') ?>">
      </div>
      <div class="grid-6">
        <label for="base_value">Base Value (coins)</label>
        <input id="base_value" name="base_value" type="number" min="0" value="<?= h($_POST['base_value'] ?? '0') ?>">
        <label><input type="checkbox" name="bind_on_pickup" <?= !empty($_POST['bind_on_pickup'])?'checked':''; ?>> Bind on Pickup</label>
      </div>

      <div class="grid-6">
        <label><input type="checkbox" name="usable" <?= !empty($_POST['usable'])?'checked':''; ?>> Usable</label>
        <label class="muted" for="level_requirement">Level Requirement</label>
        <input id="level_requirement" name="level_requirement" type="number" min="1" value="<?= h($_POST['level_requirement'] ?? '1') ?>">
      </div>
      <div class="grid-6">
        <label for="use_script">Use Script (optional)</label>
        <input id="use_script" name="use_script" type="text" placeholder="e.g., heal_potion, buff_scroll" value="<?= h($_POST['use_script'] ?? '') ?>">
        <label for="icon">Icon (PNG/JPG/GIF/WEBP, ≤ 4MB)</label>
        <input id="icon" name="icon" type="file" accept=".png,.jpg,.jpeg,.gif,.webp">
      </div>

      <div class="grid-12">
        <label for="use_payload">Use Payload (JSON, optional)</label>
        <textarea id="use_payload" name="use_payload" rows="3" placeholder='Example: {"heal":25, "buff":{"Strength":5,"duration_s":300}}'><?= h($_POST['use_payload'] ?? '') ?></textarea>
      </div>
    </div>

    <h3 style="margin-top:8px;">Stat Modifiers (optional)</h3>
    <div id="mods">
      <?php
        $mod_stat_name = $_POST['mod_stat_name'] ?? [''];
        $mod_flat = $_POST['mod_flat_amount'] ?? ['0'];
        $mod_percent = $_POST['mod_percent_amount'] ?? ['0'];
        $rows = max(1, count((array)$mod_stat_name));
        for ($i=0; $i<$rows; $i++):
      ?>
      <div class="mods-row">
        <input type="text" name="mod_stat_name[]" placeholder="Stat (e.g., Strength)" value="<?= h($mod_stat_name[$i] ?? '') ?>">
        <input type="number" name="mod_flat_amount[]" placeholder="Flat (+/-)" value="<?= h($mod_flat[$i] ?? '0') ?>">
        <input type="number" step="0.01" name="mod_percent_amount[]" placeholder="% (+/-)" value="<?= h($mod_percent[$i] ?? '0') ?>">
      </div>
      <?php endfor; ?>
    </div>
    <button type="button" class="btn secondary" onclick="addModRow()">+ Add Modifier</button>

    <div style="margin-top:12px;">
      <button class="btn" type="submit">Create Item</button>
    </div>
  </form>

  <section class="panel">
    <h2>Recent Items</h2>
    <table class="table">
      <thead>
        <tr>
          <th>Icon</th><th>Name</th><th>Type / Rarity</th><th>Value</th><th>Stack</th><th>Usable</th><th>Slug</th><th>Created</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="9" class="muted">No items yet.</td></tr>
      <?php else: foreach($items as $it): ?>
        <tr>
          <td><?php if ($it['icon_path']): ?><img class="icon" src="<?= h($it['icon_path']) ?>" alt="icon"><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td><?= h($it['name']) ?></td>
          <td><?= h($it['type']).' / '.h($it['rarity']) ?></td>
          <td><?= (int)$it['base_value'] ?></td>
          <td><?= $it['stackable'] ? (int)$it['max_stack'] : 'No' ?></td>
          <td><?= $it['usable'] ? 'Yes' : 'No' ?></td>
          <td class="muted"><?= h($it['slug']) ?></td>
          <td class="muted"><?= h($it['created_at']) ?></td>
          <td style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn secondary" href="?edit=<?= (int)$it['id'] ?>">Edit</a>
            <a class="btn" href="?images=<?= (int)$it['id'] ?>">Images</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
</main>

<script>
function addModRow(){
  const wrap = document.getElementById('mods');
  const row = document.createElement('div');
  row.className = 'mods-row';
  row.innerHTML = `
    <input type="text" name="mod_stat_name[]" placeholder="Stat (e.g., Strength)">
    <input type="number" name="mod_flat_amount[]" placeholder="Flat (+/-)" value="0">
    <input type="number" step="0.01" name="mod_percent_amount[]" placeholder="% (+/-)" value="0">
  `;
  wrap.appendChild(row);
}
</script>

<?php if (file_exists(__DIR__ . '/../includes/footer.php')) require __DIR__ . '/../includes/footer.php'; ?>
