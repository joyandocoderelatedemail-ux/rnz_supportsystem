<?php
// Dashboard package slideshow - shared data layer.
//
// Included by BOTH the client portal (/index.php) and the support backend
// (/backend/packages.php). Those two ship their own includes/config.php with
// clashing function names, so this file requires neither: every function is
// handed the PDO connection it should work on.
// (PHP 5.6 Compatible)

/**
 * Project root on disk. Image paths are stored relative to it, so the same row
 * renders from the portal root and from inside /backend.
 * @return string
 */
function package_project_root() {
    return dirname(__DIR__);
}

/**
 * Folder holding optimized package images, relative to the project root.
 * @return string
 */
function package_upload_reldir() {
    return 'uploads/packages';
}

/**
 * Image types an admin may upload.
 * @return array
 */
function package_allowed_image_exts() {
    return array('jpg', 'jpeg', 'png', 'webp', 'gif');
}

/** @return int hard ceiling on an uploaded file, before downscaling */
function package_max_upload_bytes() {
    return 12 * 1024 * 1024;
}

/** @return int longest edge an image is kept at once stored */
function package_max_edge_px() {
    return 700;
}

/**
 * The five packages the dashboard shipped with. Seeded once, on the very first
 * run, so an existing install keeps showing the slideshow it always showed.
 * @return array
 */
function package_default_seed() {
    return array(
        array('title' => 'Complete POS Package',        'tag' => 'Hardware + Software',      'image_path' => 'IMAGES/actualpos1.jpg'),
        array('title' => 'Touchscreen POS Package',     'tag' => 'Touch Terminal Bundle',    'image_path' => 'IMAGES/brochure3.jpg'),
        array('title' => 'Retail Counter Package',      'tag' => 'Fast Cashier Workflow',    'image_path' => 'IMAGES/actualpos (2).jpg'),
        array('title' => 'Restaurant POS Package',      'tag' => 'Orders, Tables & Reports', 'image_path' => 'IMAGES/brochure.jpg'),
        array('title' => 'Grocery & Inventory Package', 'tag' => 'Inventory-Ready Selling',  'image_path' => 'IMAGES/actualpos (3).jpg'),
    );
}

/**
 * Create bucket_packages when missing and seed the defaults the first time
 * only. Seeding is tied to the table not existing yet, so an admin who deletes
 * every package keeps an empty slideshow instead of watching them grow back.
 *
 * @param PDO  $pdo
 * @param bool $force skip the once-per-session short circuit
 * @return bool
 */
function init_package_tables($pdo, $force = false) {
    if (!$pdo) {
        return false;
    }
    if (!$force && isset($_SESSION['package_schema_ready']) && $_SESSION['package_schema_ready']) {
        return true;
    }

    try {
        $existed = false;
        $chk = $pdo->query("SHOW TABLES LIKE 'bucket_packages'");
        if ($chk && $chk->rowCount() > 0) {
            $existed = true;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `bucket_packages` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(150) NOT NULL,
            `tag` VARCHAR(150) NOT NULL DEFAULT '',
            `image_path` VARCHAR(255) NOT NULL,
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`),
            KEY `sort_order` (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

        if (!$existed) {
            $now = date('Y-m-d H:i:s');
            $ins = $pdo->prepare("INSERT INTO bucket_packages
                (title, tag, image_path, sort_order, is_active, created_at, updated_at)
                VALUES (:title, :tag, :img, :sort, 1, :created, :updated)");
            $order = 10;
            foreach (package_default_seed() as $row) {
                // Shrink the marketing-site original into /uploads/packages so the
                // dashboard never downloads a 2048px photo for a small tile. The
                // file under /IMAGES is left alone - the public site still uses it.
                $optimized = package_copy_optimized($row['image_path']);
                $ins->execute(array(
                    ':title'   => $row['title'],
                    ':tag'     => $row['tag'],
                    ':img'     => ($optimized !== '' ? $optimized : $row['image_path']),
                    ':sort'    => $order,
                    ':created' => $now,
                    ':updated' => $now
                ));
                $order += 10;
            }
        }

        $_SESSION['package_schema_ready'] = true;
        return true;
    } catch (PDOException $e) {
        error_log("Package init error: " . $e->getMessage());
        return false;
    }
}

/**
 * Packages the client dashboard should show, in display order. Rows whose file
 * has gone missing are dropped so the slideshow never renders a broken tile.
 *
 * @param PDO $pdo
 * @return array
 */
function get_active_packages($pdo) {
    if (!$pdo) {
        return array();
    }
    try {
        $stmt = $pdo->query("SELECT id, title, tag, image_path
            FROM bucket_packages WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC");
        $rows = $stmt ? $stmt->fetchAll() : array();
    } catch (PDOException $e) {
        error_log("Package fetch error: " . $e->getMessage());
        return array();
    }

    $out = array();
    foreach ($rows as $row) {
        if (package_image_exists($row['image_path'])) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Every package including hidden ones, for the admin list.
 *
 * @param PDO $pdo
 * @return array
 */
function get_all_packages($pdo) {
    if (!$pdo) {
        return array();
    }
    try {
        $stmt = $pdo->query("SELECT * FROM bucket_packages ORDER BY sort_order ASC, id ASC");
        return $stmt ? $stmt->fetchAll() : array();
    } catch (PDOException $e) {
        error_log("Package list error: " . $e->getMessage());
        return array();
    }
}

/**
 * One package by id.
 *
 * @param PDO $pdo
 * @param int $id
 * @return array|null
 */
function get_package_by_id($pdo, $id) {
    $id = intval($id);
    if (!$pdo || $id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM bucket_packages WHERE id = :id LIMIT 1");
        $stmt->execute(array(':id' => $id));
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Package lookup error: " . $e->getMessage());
        return null;
    }
    return $row ? $row : null;
}

/**
 * Keep a stored path inside the project: strip leading slashes and reject
 * traversal, so a bad row can never point an <img> outside the app or let a
 * delete reach a file it has no business touching.
 *
 * @param string $rel_path
 * @return string normalized path, or '' when it is not usable
 */
function package_normalize_relpath($rel_path) {
    $rel = str_replace('\\', '/', trim((string)$rel_path));
    $rel = ltrim($rel, '/');
    if ($rel === '') {
        return '';
    }
    if (strpos($rel, '..') !== false || strpos($rel, ':') !== false) {
        return '';
    }
    return $rel;
}

/**
 * @param string $rel_path
 * @return bool
 */
function package_image_exists($rel_path) {
    $rel = package_normalize_relpath($rel_path);
    if ($rel === '') {
        return false;
    }
    return is_file(package_project_root() . '/' . $rel);
}

/**
 * Browser-safe URL for a stored path - every segment encoded, slashes kept,
 * because the seeded filenames contain spaces and parentheses.
 *
 * @param string $rel_path
 * @param string $prefix   '../' to reach the project root from inside /backend
 * @return string
 */
function package_image_url($rel_path, $prefix = '') {
    $rel = package_normalize_relpath($rel_path);
    if ($rel === '') {
        return '';
    }
    $parts = explode('/', $rel);
    foreach ($parts as $k => $part) {
        $parts[$k] = rawurlencode($part);
    }
    return $prefix . implode('/', $parts);
}

/**
 * Marquee geometry, shared so the admin preview and the live dashboard agree.
 *
 * The scrolling track must never be narrower than the strip it scrolls inside
 * or the loop shows an empty gap, so the package list is repeated until one
 * group covers the viewport. Duration follows the resulting width, which holds
 * the scroll speed steady however many packages an admin has added.
 *
 * @param int $count        how many packages are on screen
 * @param int $card_px      card width plus its horizontal margins
 * @param int $viewport_px  widest the strip is ever drawn
 * @param int $speed_px_sec scroll speed
 * @return array ('reps', 'group_px', 'duration_ms')
 */
function package_marquee_metrics($count, $card_px = 168, $viewport_px = 1200, $speed_px_sec = 60) {
    $count = max(1, intval($count));
    $set_px = $count * $card_px;
    $reps = (int)ceil($viewport_px / $set_px);
    if ($reps < 1) {
        $reps = 1;
    }
    $group_px = $reps * $set_px;
    $duration_ms = (int)round(($group_px / $speed_px_sec) * 1000);
    if ($duration_ms < 8000) {
        $duration_ms = 8000;
    }
    return array('reps' => $reps, 'group_px' => $group_px, 'duration_ms' => $duration_ms);
}

/**
 * Make sure /uploads/packages exists and is writable.
 * @return string absolute path, or '' when it could not be created
 */
function package_ensure_upload_dir() {
    $dir = package_project_root() . '/' . package_upload_reldir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return is_dir($dir) ? $dir : '';
}

/**
 * Load an image file into a GD resource, whatever of the supported types it is.
 *
 * @param string $abs_path
 * @param string $ext
 * @return resource|false
 */
function package_gd_load($abs_path, $ext) {
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($abs_path) : false;
        case 'png':
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($abs_path) : false;
        case 'gif':
            return function_exists('imagecreatefromgif') ? @imagecreatefromgif($abs_path) : false;
        case 'webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs_path) : false;
    }
    return false;
}

/**
 * Undo the EXIF rotation phone cameras record, so an upload that looked upright
 * in the file picker is not served on its side.
 *
 * @param resource $img
 * @param string   $abs_path
 * @param string   $ext
 * @return resource
 */
function package_apply_exif_rotation($img, $abs_path, $ext) {
    if (($ext !== 'jpg' && $ext !== 'jpeg') || !function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($abs_path);
    if (!$exif || empty($exif['Orientation'])) {
        return $img;
    }
    $angle = 0;
    if ($exif['Orientation'] == 3) {
        $angle = 180;
    } elseif ($exif['Orientation'] == 6) {
        $angle = -90;
    } elseif ($exif['Orientation'] == 8) {
        $angle = 90;
    }
    if ($angle === 0 || !function_exists('imagerotate')) {
        return $img;
    }
    $rotated = @imagerotate($img, $angle, 0);
    if ($rotated) {
        imagedestroy($img);
        return $rotated;
    }
    return $img;
}

/**
 * Downscale a source image into /uploads/packages under a fresh name.
 *
 * Everything lands as JPEG except PNG, which is kept so logos and other art
 * with transparency do not gain a black background.
 *
 * @param string $src_abs  file on disk to read
 * @param string $src_ext  its extension, lowercased
 * @param int    $max_edge longest edge to keep
 * @return string relative path of the new file, or '' on any failure
 */
function package_write_optimized($src_abs, $src_ext, $max_edge = null) {
    if ($max_edge === null) {
        $max_edge = package_max_edge_px();
    }
    if (!function_exists('imagecreatetruecolor')) {
        return '';
    }
    $dir = package_ensure_upload_dir();
    if ($dir === '') {
        return '';
    }

    $src = package_gd_load($src_abs, $src_ext);
    if (!$src) {
        return '';
    }
    $src = package_apply_exif_rotation($src, $src_abs, $src_ext);

    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw < 1 || $sh < 1) {
        imagedestroy($src);
        return '';
    }

    // Only ever shrink - upscaling a small logo would just blur it
    $scale = 1.0;
    if ($sw > $max_edge || $sh > $max_edge) {
        $scale = ($sw >= $sh) ? ($max_edge / $sw) : ($max_edge / $sh);
    }
    $dw = max(1, (int)round($sw * $scale));
    $dh = max(1, (int)round($sh * $scale));

    $keep_png = ($src_ext === 'png');
    $dst = imagecreatetruecolor($dw, $dh);
    if (!$dst) {
        imagedestroy($src);
        return '';
    }
    if ($keep_png) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    } else {
        // GIF and WEBP can carry transparency too; flatten onto white rather
        // than letting it come out black once written as JPEG.
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $dw, $dh, $white);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);

    $out_ext = $keep_png ? 'png' : 'jpg';
    $name = 'pkg_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $out_ext;
    $abs = $dir . '/' . $name;

    $ok = $keep_png ? @imagepng($dst, $abs, 7) : @imagejpeg($dst, $abs, 82);
    imagedestroy($dst);

    if (!$ok || !is_file($abs)) {
        return '';
    }
    return package_upload_reldir() . '/' . $name;
}

/**
 * Copy an image already inside the project (typically /IMAGES) into
 * /uploads/packages at slideshow size, leaving the original untouched.
 *
 * @param string $rel_path
 * @return string relative path of the optimized copy, or '' on failure
 */
function package_copy_optimized($rel_path) {
    $rel = package_normalize_relpath($rel_path);
    if ($rel === '') {
        return '';
    }
    $abs = package_project_root() . '/' . $rel;
    if (!is_file($abs)) {
        return '';
    }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext, package_allowed_image_exts())) {
        return '';
    }
    return package_write_optimized($abs, $ext);
}

/**
 * Validate and store an uploaded package image.
 *
 * @param array $file one entry from $_FILES
 * @return array ('ok' => bool, 'path' => string, 'error' => string)
 */
function package_store_upload($file) {
    $fail = array('ok' => false, 'path' => '', 'error' => '');

    if (!is_array($file) || !isset($file['error'])) {
        $fail['error'] = 'No file was received.';
        return $fail;
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        $fail['error'] = 'No file was chosen.';
        return $fail;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $fail['error'] = 'Upload failed (the file may be larger than the server allows).';
        return $fail;
    }
    if ($file['size'] > package_max_upload_bytes()) {
        $fail['error'] = 'Image is larger than ' . round(package_max_upload_bytes() / 1048576) . ' MB.';
        return $fail;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, package_allowed_image_exts())) {
        $fail['error'] = 'Only JPG, PNG, WEBP and GIF images are accepted.';
        return $fail;
    }
    // Trust the pixels, not the extension: a .jpg that is not an image fails here
    $info = @getimagesize($file['tmp_name']);
    if (!$info || empty($info[0])) {
        $fail['error'] = 'That file is not a readable image.';
        return $fail;
    }

    $stored = package_write_optimized($file['tmp_name'], $ext);
    if ($stored !== '') {
        return array('ok' => true, 'path' => $stored, 'error' => '');
    }

    // GD unavailable or refused the file - keep the original rather than lose it
    $dir = package_ensure_upload_dir();
    if ($dir === '') {
        $fail['error'] = 'The uploads/packages folder could not be created.';
        return $fail;
    }
    $name = 'pkg_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        $fail['error'] = 'The image could not be saved to disk.';
        return $fail;
    }
    return array('ok' => true, 'path' => package_upload_reldir() . '/' . $name, 'error' => '');
}

/**
 * Delete a package image, but only one this feature owns. Anything outside
 * /uploads/packages - a /IMAGES original the public site still renders - is
 * left on disk.
 *
 * @param string $rel_path
 * @return bool whether a file was removed
 */
function package_delete_owned_image($rel_path) {
    $rel = package_normalize_relpath($rel_path);
    if ($rel === '') {
        return false;
    }
    if (strpos($rel, package_upload_reldir() . '/') !== 0) {
        return false;
    }
    $abs = package_project_root() . '/' . $rel;
    if (!is_file($abs)) {
        return false;
    }
    return @unlink($abs);
}

/**
 * Images under /IMAGES an admin can pick without uploading anything.
 *
 * @return array relative paths
 */
function package_library_images() {
    $dir = package_project_root() . '/IMAGES';
    if (!is_dir($dir)) {
        return array();
    }
    $allowed = package_allowed_image_exts();
    $out = array();
    $entries = @scandir($dir);
    if (!$entries) {
        return array();
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!is_file($dir . '/' . $entry)) {
            continue;
        }
        if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $allowed)) {
            continue;
        }
        $out[] = 'IMAGES/' . $entry;
    }
    sort($out);
    return $out;
}

/**
 * Next sort_order, so a new package lands at the end of the slideshow.
 *
 * @param PDO $pdo
 * @return int
 */
function package_next_sort_order($pdo) {
    if (!$pdo) {
        return 10;
    }
    try {
        $stmt = $pdo->query("SELECT MAX(sort_order) AS m FROM bucket_packages");
        $row = $stmt ? $stmt->fetch() : null;
        $max = ($row && $row['m'] !== null) ? intval($row['m']) : 0;
    } catch (PDOException $e) {
        error_log("Package order lookup error: " . $e->getMessage());
        return 10;
    }
    return $max + 10;
}

/**
 * Swap one package with its neighbour in display order.
 *
 * Rows are renumbered 10, 20, 30... first, because seeded and hand-edited rows
 * can end up sharing a sort_order and a swap between two equal values would do
 * nothing at all.
 *
 * @param PDO    $pdo
 * @param int    $id
 * @param string $direction 'up' or 'down'
 * @return bool
 */
function package_move($pdo, $id, $direction) {
    $id = intval($id);
    if (!$pdo || $id <= 0) {
        return false;
    }
    try {
        package_renumber($pdo);

        $rows = $pdo->query("SELECT id, sort_order FROM bucket_packages ORDER BY sort_order ASC, id ASC")->fetchAll();
        $pos = -1;
        foreach ($rows as $i => $row) {
            if (intval($row['id']) === $id) {
                $pos = $i;
                break;
            }
        }
        if ($pos < 0) {
            return false;
        }
        $swap_pos = ($direction === 'up') ? $pos - 1 : $pos + 1;
        if ($swap_pos < 0 || $swap_pos >= count($rows)) {
            return false;
        }

        $upd = $pdo->prepare("UPDATE bucket_packages SET sort_order = :so WHERE id = :id");
        $upd->execute(array(':so' => intval($rows[$swap_pos]['sort_order']), ':id' => intval($rows[$pos]['id'])));
        $upd->execute(array(':so' => intval($rows[$pos]['sort_order']), ':id' => intval($rows[$swap_pos]['id'])));
        return true;
    } catch (PDOException $e) {
        error_log("Package move error: " . $e->getMessage());
        return false;
    }
}

/**
 * Rewrite sort_order as 10, 20, 30... in current display order.
 *
 * @param PDO $pdo
 * @return bool
 */
function package_renumber($pdo) {
    if (!$pdo) {
        return false;
    }
    try {
        $rows = $pdo->query("SELECT id FROM bucket_packages ORDER BY sort_order ASC, id ASC")->fetchAll();
        $upd = $pdo->prepare("UPDATE bucket_packages SET sort_order = :so WHERE id = :id");
        $order = 10;
        foreach ($rows as $row) {
            $upd->execute(array(':so' => $order, ':id' => intval($row['id'])));
            $order += 10;
        }
        return true;
    } catch (PDOException $e) {
        error_log("Package renumber error: " . $e->getMessage());
        return false;
    }
}
