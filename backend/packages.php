<?php
// Dashboard Packages - configure the slideshow shown on the client portal
// (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
// Shared with the client portal. It requires no config of its own precisely so
// it can be pulled in on both sides without redeclaring sanitize() et al.
require_once dirname(__DIR__) . '/includes/packages_data.php';

require_page_access('packages');

$pdo = get_db_connection();
init_package_tables($pdo);

$success_msg = '';
$error_msg = '';

// ---------------------------------------------------------------------------
// Actions - each one redirects so a refresh cannot replay the last write
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $redirect = 'packages.php';

    try {
        if ($action === 'save') {
            $edit_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
            $title   = isset($_POST['title']) ? trim($_POST['title']) : '';
            $tag     = isset($_POST['tag']) ? trim($_POST['tag']) : '';
            $source  = isset($_POST['image_source']) ? $_POST['image_source'] : 'upload';
            $library = isset($_POST['library_image']) ? trim($_POST['library_image']) : '';

            $existing = $edit_id > 0 ? get_package_by_id($pdo, $edit_id) : null;
            if ($edit_id > 0 && !$existing) {
                throw new Exception('That package no longer exists.');
            }
            if ($title === '') {
                throw new Exception('A package title is required.');
            }
            if (strlen($title) > 150) {
                $title = substr($title, 0, 150);
            }
            if (strlen($tag) > 150) {
                $tag = substr($tag, 0, 150);
            }

            // Work out the image before touching the row, so a failed upload
            // never leaves a package pointing at nothing.
            $image_path = $existing ? $existing['image_path'] : '';
            $replaced_old = '';

            if ($source === 'library' && $library !== '') {
                $copied = package_copy_optimized($library);
                if ($copied === '') {
                    // GD could not resize it; point at the original instead
                    if (!package_image_exists($library)) {
                        throw new Exception('That library image could not be read.');
                    }
                    $copied = package_normalize_relpath($library);
                }
                $replaced_old = $image_path;
                $image_path = $copied;
            } elseif (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $up = package_store_upload($_FILES['image_file']);
                if (!$up['ok']) {
                    throw new Exception($up['error']);
                }
                $replaced_old = $image_path;
                $image_path = $up['path'];
            }

            if ($image_path === '') {
                throw new Exception('Choose an image for this package.');
            }

            $now = date('Y-m-d H:i:s');
            $is_active = !empty($_POST['is_active']) ? 1 : 0;

            if ($edit_id > 0) {
                $stmt = $pdo->prepare("UPDATE bucket_packages
                    SET title = :title, tag = :tag, image_path = :img,
                        is_active = :active, updated_at = :updated
                    WHERE id = :id");
                $stmt->execute(array(
                    ':title'   => $title,
                    ':tag'     => $tag,
                    ':img'     => $image_path,
                    ':active'  => $is_active,
                    ':updated' => $now,
                    ':id'      => $edit_id
                ));
                $success_flag = 'updated';
            } else {
                $stmt = $pdo->prepare("INSERT INTO bucket_packages
                    (title, tag, image_path, sort_order, is_active, created_at, updated_at)
                    VALUES (:title, :tag, :img, :sort, :active, :created, :updated)");
                $stmt->execute(array(
                    ':title'   => $title,
                    ':tag'     => $tag,
                    ':img'     => $image_path,
                    ':sort'    => package_next_sort_order($pdo),
                    ':active'  => $is_active,
                    ':created' => $now,
                    ':updated' => $now
                ));
                $success_flag = 'added';
            }

            // Only now that the row is safely written, drop the image it replaced
            if ($replaced_old !== '' && $replaced_old !== $image_path) {
                package_delete_owned_image($replaced_old);
            }
            $redirect .= '?msg=' . $success_flag;

        } elseif ($action === 'delete') {
            $del_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
            $row = get_package_by_id($pdo, $del_id);
            if (!$row) {
                throw new Exception('That package no longer exists.');
            }
            $stmt = $pdo->prepare("DELETE FROM bucket_packages WHERE id = :id");
            $stmt->execute(array(':id' => $del_id));
            package_delete_owned_image($row['image_path']);
            $redirect .= '?msg=deleted';

        } elseif ($action === 'toggle') {
            $tog_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
            $row = get_package_by_id($pdo, $tog_id);
            if (!$row) {
                throw new Exception('That package no longer exists.');
            }
            $new_state = intval($row['is_active']) === 1 ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE bucket_packages SET is_active = :active, updated_at = :updated WHERE id = :id");
            $stmt->execute(array(':active' => $new_state, ':updated' => date('Y-m-d H:i:s'), ':id' => $tog_id));
            $redirect .= '?msg=' . ($new_state ? 'shown' : 'hidden');

        } elseif ($action === 'move') {
            $mv_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
            $dir = (isset($_POST['direction']) && $_POST['direction'] === 'up') ? 'up' : 'down';
            package_move($pdo, $mv_id, $dir);
            $redirect .= '?msg=reordered';

        } else {
            throw new Exception('Unknown action.');
        }
    // PDOException extends Exception, so it has to be caught first or the
    // generic handler below would swallow it and leak SQL detail to the URL.
    } catch (PDOException $e) {
        error_log("Package action error: " . $e->getMessage());
        $redirect .= '?msg=error&err=' . urlencode('Database error while saving the package.');
    } catch (Exception $e) {
        $redirect .= '?msg=error&err=' . urlencode($e->getMessage());
    }

    header('Location: ' . $redirect);
    exit;
}

// ---------------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------------
$msg = isset($_GET['msg']) ? $_GET['msg'] : '';
$flash_map = array(
    'added'     => 'Package added to the dashboard slideshow.',
    'updated'   => 'Package updated.',
    'deleted'   => 'Package removed from the slideshow.',
    'shown'     => 'Package is now visible on the client dashboard.',
    'hidden'    => 'Package hidden from the client dashboard.',
    'reordered' => 'Slideshow order updated.'
);
if ($msg === 'error') {
    $error_msg = isset($_GET['err']) ? $_GET['err'] : 'Something went wrong.';
} elseif (isset($flash_map[$msg])) {
    $success_msg = $flash_map[$msg];
}

// ---------------------------------------------------------------------------
// Page data
// ---------------------------------------------------------------------------
$packages = get_all_packages($pdo);
$library  = package_library_images();

$edit_id  = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$editing  = $edit_id > 0 ? get_package_by_id($pdo, $edit_id) : null;

$active_count = 0;
foreach ($packages as $p) {
    if (intval($p['is_active']) === 1 && package_image_exists($p['image_path'])) {
        $active_count++;
    }
}

// Preview runs the same geometry the dashboard does, at the same card width
$preview = array();
foreach ($packages as $p) {
    if (intval($p['is_active']) === 1 && package_image_exists($p['image_path'])) {
        $preview[] = $p;
    }
}
$metrics = package_marquee_metrics(count($preview));

$total_bytes = 0;
foreach ($packages as $p) {
    $abs = package_project_root() . '/' . package_normalize_relpath($p['image_path']);
    if (is_file($abs)) {
        $total_bytes += filesize($abs);
    }
}

$active_page = 'packages';
$page_title = 'Dashboard Packages';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($page_title); ?> - Support Center</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#FFF5ED',
                            100: '#FFE8D5',
                            500: '#FA5915',
                            600: '#EB3E0B',
                            700: '#C32C0B',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }

        /* Same animation the client dashboard runs, so this preview is honest */
        .rnz-marquee-inner { animation: rnzMarqueeScroll linear infinite; }
        .rnz-marquee:hover .rnz-marquee-inner { animation-play-state: paused; }

        @keyframes rnzMarqueeScroll {
            0%   { transform: translateX(0%); }
            100% { transform: translateX(-50%); }
        }

        @media (prefers-reduced-motion: reduce) {
            .rnz-marquee-inner { animation: none; }
            .rnz-marquee { overflow-x: auto; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased min-h-screen">

<div class="flex min-h-screen">
    <!-- Admin Sidebar Navigation -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Top Admin Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-6 sm:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Page heading -->
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight">Dashboard Packages</h1>
                    <p class="text-xs text-slate-500 mt-1">
                        Images and captions for the &ldquo;Packages We Offer&rdquo; slideshow on the client portal dashboard.
                    </p>
                </div>
                <div class="flex items-center gap-4 text-xs">
                    <div class="text-right">
                        <span class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">Showing</span>
                        <span class="font-bold text-slate-900"><?php echo $active_count; ?> of <?php echo count($packages); ?></span>
                    </div>
                    <div class="text-right">
                        <span class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">Images</span>
                        <span class="font-bold text-slate-900"><?php echo number_format($total_bytes / 1024, 0); ?> KB</span>
                    </div>
                </div>
            </div>

            <!-- Flash messages -->
            <?php if (!empty($success_msg)): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl px-4 py-3 text-sm font-semibold flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <?php echo sanitize($success_msg); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_msg)): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl px-4 py-3 text-sm font-semibold flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                    <?php echo sanitize($error_msg); ?>
                </div>
            <?php endif; ?>

            <!-- Live preview of exactly what the client sees -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 border-b border-slate-100">
                    <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider">Live Preview</h2>
                    <span class="text-[11px] text-slate-400">Hover to pause &middot; <?php echo round($metrics['duration_ms'] / 1000); ?>s loop</span>
                </div>
                <?php if (empty($preview)): ?>
                    <p class="px-5 py-10 text-center text-sm text-slate-400">
                        No visible packages &mdash; the slideshow section is hidden on the client dashboard.
                    </p>
                <?php else: ?>
                    <div class="py-6 bg-[#FFF5ED]">
                        <div class="rnz-marquee overflow-hidden w-full relative max-w-5xl mx-auto">
                            <div class="absolute left-0 top-0 h-full w-12 z-10 pointer-events-none bg-gradient-to-r from-[#FFF5ED] to-transparent"></div>
                            <div class="rnz-marquee-inner flex w-max" style="animation-duration: <?php echo $metrics['duration_ms']; ?>ms;">
                                <?php for ($g = 0; $g < 2; $g++): ?>
                                    <div class="flex shrink-0"<?php echo $g ? ' aria-hidden="true"' : ''; ?>>
                                        <?php for ($r = 0; $r < $metrics['reps']; $r++): ?>
                                            <?php foreach ($preview as $p): ?>
                                                <div class="w-36 mx-3 shrink-0">
                                                    <div class="h-40 w-full overflow-hidden rounded-xl bg-slate-200">
                                                        <img src="<?php echo sanitize(package_image_url($p['image_path'], '../')); ?>"
                                                             alt="" class="w-full h-full object-cover">
                                                    </div>
                                                    <p class="text-[11px] text-slate-800 font-semibold mt-2 leading-snug line-clamp-2"><?php echo sanitize($p['title']); ?></p>
                                                    <?php if (trim($p['tag']) !== ''): ?>
                                                        <p class="text-[10px] text-[#EB3E0B] font-medium mt-0.5"><?php echo sanitize($p['tag']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endfor; ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <div class="absolute right-0 top-0 h-full w-12 z-10 pointer-events-none bg-gradient-to-l from-[#FFF5ED] to-transparent"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[22rem_1fr] gap-6 items-start">

                <!-- Add / Edit form -->
                <form method="POST" enctype="multipart/form-data"
                      class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="package_id" value="<?php echo $editing ? intval($editing['id']) : 0; ?>">

                    <div class="flex items-center justify-between">
                        <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider">
                            <?php echo $editing ? 'Edit Package' : 'Add Package'; ?>
                        </h2>
                        <?php if ($editing): ?>
                            <a href="packages.php" class="text-[11px] font-semibold text-slate-400 hover:text-slate-700">Cancel</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($editing): ?>
                        <div class="flex items-center gap-3 bg-slate-50 border border-slate-200 rounded-xl p-2.5">
                            <img src="<?php echo sanitize(package_image_url($editing['image_path'], '../')); ?>"
                                 alt="" class="w-12 h-14 object-cover rounded-lg bg-slate-200 shrink-0">
                            <div class="min-w-0">
                                <p class="text-[11px] font-bold text-slate-700">Current image</p>
                                <p class="text-[10px] text-slate-400 truncate font-mono"><?php echo sanitize($editing['image_path']); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">Title</label>
                        <input type="text" name="title" required maxlength="150"
                               value="<?php echo $editing ? sanitize($editing['title']) : ''; ?>"
                               placeholder="Complete POS Package"
                               class="w-full px-3 py-2 text-sm rounded-xl border border-slate-200 focus:border-[#EB3E0B] focus:ring-1 focus:ring-[#EB3E0B] outline-none">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">
                            Caption <span class="font-medium text-slate-400">(optional)</span>
                        </label>
                        <input type="text" name="tag" maxlength="150"
                               value="<?php echo $editing ? sanitize($editing['tag']) : ''; ?>"
                               placeholder="Hardware + Software"
                               class="w-full px-3 py-2 text-sm rounded-xl border border-slate-200 focus:border-[#EB3E0B] focus:ring-1 focus:ring-[#EB3E0B] outline-none">
                    </div>

                    <div class="space-y-2">
                        <label class="block text-[11px] font-bold text-slate-600">Image</label>

                        <label class="flex items-center gap-2 text-xs text-slate-700">
                            <input type="radio" name="image_source" value="upload" checked
                                   onchange="pkgToggleSource()" class="accent-[#EB3E0B]">
                            Upload a new image
                        </label>
                        <div id="pkgUploadBox" class="pl-5">
                            <input type="file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif"
                                   class="w-full text-[11px] text-slate-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-[11px] file:font-bold file:bg-[#FFE8D5] file:text-[#C32C0B] hover:file:bg-[#FECDAA] cursor-pointer">
                            <p class="text-[10px] text-slate-400 mt-1">
                                JPG, PNG, WEBP or GIF. Resized to <?php echo package_max_edge_px(); ?>px on save.
                            </p>
                        </div>

                        <?php if (!empty($library)): ?>
                            <label class="flex items-center gap-2 text-xs text-slate-700">
                                <input type="radio" name="image_source" value="library"
                                       onchange="pkgToggleSource()" class="accent-[#EB3E0B]">
                                Use an image already in /IMAGES
                            </label>
                            <div id="pkgLibraryBox" class="pl-5 hidden">
                                <select name="library_image"
                                        class="w-full px-3 py-2 text-xs rounded-xl border border-slate-200 focus:border-[#EB3E0B] focus:ring-1 focus:ring-[#EB3E0B] outline-none">
                                    <?php foreach ($library as $lib): ?>
                                        <option value="<?php echo sanitize($lib); ?>"><?php echo sanitize(basename($lib)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-[10px] text-slate-400 mt-1">A resized copy is made; the original is left untouched.</p>
                            </div>
                        <?php endif; ?>

                        <?php if ($editing): ?>
                            <p class="text-[10px] text-slate-400 pl-5">Leave blank to keep the current image.</p>
                        <?php endif; ?>
                    </div>

                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 pt-1">
                        <input type="checkbox" name="is_active" value="1" class="accent-[#EB3E0B]"
                               <?php echo (!$editing || intval($editing['is_active']) === 1) ? 'checked' : ''; ?>>
                        Show on the client dashboard
                    </label>

                    <button type="submit"
                            class="w-full bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-colors active:scale-95">
                        <?php echo $editing ? 'Save Changes' : 'Add Package'; ?>
                    </button>
                </form>

                <!-- Package list -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider">Slideshow Order</h2>
                    </div>

                    <?php if (empty($packages)): ?>
                        <p class="px-5 py-12 text-center text-sm text-slate-400">
                            No packages yet. Add one using the form on the left.
                        </p>
                    <?php else: ?>
                        <ul class="divide-y divide-slate-100">
                            <?php foreach ($packages as $i => $p):
                                $missing = !package_image_exists($p['image_path']);
                                $is_on = (intval($p['is_active']) === 1);
                            ?>
                                <li class="flex items-center gap-3 px-4 py-3 <?php echo $is_on ? '' : 'bg-slate-50/70'; ?>">
                                    <!-- Order controls -->
                                    <div class="flex flex-col gap-0.5 shrink-0">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="package_id" value="<?php echo intval($p['id']); ?>">
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" title="Move up" <?php echo $i === 0 ? 'disabled' : ''; ?>
                                                    class="w-6 h-5 flex items-center justify-center rounded text-slate-400 hover:text-[#EB3E0B] hover:bg-slate-100 disabled:opacity-25 disabled:hover:bg-transparent disabled:cursor-not-allowed">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/>
                                                </svg>
                                            </button>
                                        </form>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="package_id" value="<?php echo intval($p['id']); ?>">
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" title="Move down" <?php echo $i === count($packages) - 1 ? 'disabled' : ''; ?>
                                                    class="w-6 h-5 flex items-center justify-center rounded text-slate-400 hover:text-[#EB3E0B] hover:bg-slate-100 disabled:opacity-25 disabled:hover:bg-transparent disabled:cursor-not-allowed">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Thumbnail -->
                                    <?php if ($missing): ?>
                                        <div class="w-11 h-14 rounded-lg bg-rose-50 border border-rose-200 flex items-center justify-center shrink-0">
                                            <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                            </svg>
                                        </div>
                                    <?php else: ?>
                                        <img src="<?php echo sanitize(package_image_url($p['image_path'], '../')); ?>"
                                             alt="" class="w-11 h-14 object-cover rounded-lg bg-slate-200 shrink-0 <?php echo $is_on ? '' : 'opacity-40 grayscale'; ?>">
                                    <?php endif; ?>

                                    <!-- Text -->
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-bold text-slate-900 truncate"><?php echo sanitize($p['title']); ?></p>
                                        <?php if (trim($p['tag']) !== ''): ?>
                                            <p class="text-[11px] text-[#EB3E0B] font-medium truncate"><?php echo sanitize($p['tag']); ?></p>
                                        <?php endif; ?>
                                        <p class="text-[10px] text-slate-400 truncate font-mono mt-0.5"><?php echo sanitize($p['image_path']); ?></p>
                                        <?php if ($missing): ?>
                                            <p class="text-[10px] text-rose-600 font-bold mt-0.5">Image file is missing &mdash; this package is skipped on the dashboard.</p>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Status + actions -->
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="package_id" value="<?php echo intval($p['id']); ?>">
                                            <button type="submit"
                                                    title="<?php echo $is_on ? 'Hide from dashboard' : 'Show on dashboard'; ?>"
                                                    class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wide transition-colors <?php echo $is_on ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100' : 'bg-slate-100 text-slate-500 border border-slate-200 hover:bg-slate-200'; ?>">
                                                <?php echo $is_on ? 'Visible' : 'Hidden'; ?>
                                            </button>
                                        </form>

                                        <a href="packages.php?edit=<?php echo intval($p['id']); ?>"
                                           title="Edit"
                                           class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-[#EB3E0B] hover:bg-slate-100 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </a>

                                        <!-- Title rides in a data attribute: entity-escaping it into a
                                             JS string literal would break on any package with an apostrophe -->
                                        <form method="POST" data-pkg-title="<?php echo sanitize($p['title']); ?>"
                                              onsubmit="return pkgConfirmDelete(this);">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="package_id" value="<?php echo intval($p['id']); ?>">
                                            <button type="submit" title="Delete"
                                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

        </main>

        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
</div>

<script>
    // Show whichever image source the admin picked, so only one is submitted
    function pkgToggleSource() {
        var picked = document.querySelector('input[name="image_source"]:checked');
        var mode = picked ? picked.value : 'upload';
        var upload = document.getElementById('pkgUploadBox');
        var library = document.getElementById('pkgLibraryBox');
        if (upload) { upload.classList.toggle('hidden', mode !== 'upload'); }
        if (library) { library.classList.toggle('hidden', mode !== 'library'); }
    }
    pkgToggleSource();

    function pkgConfirmDelete(form) {
        var title = form.getAttribute('data-pkg-title') || 'this package';
        return confirm('Remove "' + title + '" from the slideshow?');
    }
</script>

</body>
</html>
