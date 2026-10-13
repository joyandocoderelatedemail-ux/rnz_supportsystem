<?php
// Dashboard Client Ads - configure the carousel shown on the client portal
// (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
// Shared with the client portal. It requires no config of its own precisely so
// it can be pulled in on both sides without redeclaring sanitize() et al.
require_once dirname(__DIR__) . '/includes/packages_data.php';
require_once dirname(__DIR__) . '/includes/ad_inquiry_init.php';

require_page_access('packages');

$pdo = get_db_connection();
init_package_tables($pdo);
init_ad_inquiry_tables($pdo);

// JSON helpers for the no-refresh list editor (sorting, visibility,
// delete, inline edit) and the live preview refresh below.
function pkg_json($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * One ad row shaped for the inline editor + list patching.
 *
 * @param array $row bucket_packages row
 * @return array
 */
function pkg_row_payload($row) {
    return array(
        'id' => intval($row['id']),
        'title' => $row['title'],
        'tag' => isset($row['tag']) ? $row['tag'] : '',
        'image_path' => $row['image_path'],
        'image_url' => package_image_url($row['image_path'], '../'),
        'image_missing' => !package_image_exists($row['image_path']),
        'is_active' => (intval($row['is_active']) === 1),
        'sort_order' => intval($row['sort_order'])
    );
}

// Fresh preview slides + counts without reloading the page.
if (isset($_GET['preview']) && $_GET['preview'] === '1') {
    $slides = array();
    foreach (get_active_packages($pdo) as $pv) {
        $slides[] = array(
            'title' => $pv['title'],
            'tag' => isset($pv['tag']) ? $pv['tag'] : '',
            'image_url' => package_image_url($pv['image_path'], '../')
        );
    }
    $vis_cnt = 0;
    $byte_cnt = 0;
    $all_cnt = 0;
    foreach (get_all_packages($pdo) as $ar) {
        $all_cnt++;
        if (intval($ar['is_active']) === 1 && package_image_exists($ar['image_path'])) {
            $vis_cnt++;
        }
        $ar_abs = package_project_root() . '/' . package_normalize_relpath($ar['image_path']);
        if (is_file($ar_abs)) {
            $byte_cnt += filesize($ar_abs);
        }
    }
    pkg_json(array(
        'success' => true,
        'slides' => $slides,
        'visible' => $vis_cnt,
        'total' => $all_cnt,
        'kb' => number_format($byte_cnt / 1024, 0)
    ));
}

// One ad row for the inline editor.
if (isset($_GET['row']) && intval($_GET['row']) > 0) {
    $one_row = get_package_by_id($pdo, intval($_GET['row']));
    if (!$one_row) {
        pkg_json(array('success' => false, 'error' => 'That ad no longer exists.'));
    }
    pkg_json(array('success' => true, 'row' => pkg_row_payload($one_row)));
}

$success_msg = '';
$error_msg = '';

// ---------------------------------------------------------------------------
// Actions - each one redirects so a refresh cannot replay the last write
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $redirect = 'packages.php';
    // The list editor below posts with ajax=1 and patches the page in
    // place; anything else keeps the classic redirect-after-POST.
    $is_ajax = (isset($_POST['ajax']) && $_POST['ajax'] === '1');

    try {
        if ($action === 'save') {
            $edit_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
            $title   = isset($_POST['title']) ? trim($_POST['title']) : '';
            $tag     = isset($_POST['tag']) ? trim($_POST['tag']) : '';
            $source  = isset($_POST['image_source']) ? $_POST['image_source'] : 'upload';
            $library = isset($_POST['library_image']) ? trim($_POST['library_image']) : '';

            $existing = $edit_id > 0 ? get_package_by_id($pdo, $edit_id) : null;
            if ($edit_id > 0 && !$existing) {
                throw new Exception('That ad no longer exists.');
            }
            if ($title === '') {
                throw new Exception('An ad title is required.');
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
                throw new Exception('Choose an image for this ad.');
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

            if ($is_ajax) {
                $saved_id = ($edit_id > 0) ? $edit_id : intval($pdo->lastInsertId());
                $saved_row = get_package_by_id($pdo, $saved_id);
                if (!$saved_row) {
                    pkg_json(array('success' => false, 'error' => 'Saved, but the row could not be reloaded.'));
                }
                pkg_json(array(
                    'success' => true,
                    'message' => ($success_flag === 'added') ? 'Ad added to the dashboard carousel.' : 'Ad updated.',
                    'is_new' => ($success_flag === 'added'),
                    'row' => pkg_row_payload($saved_row)
                ));
            }

        } elseif ($action === 'delete') {
            $del_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
            $row = get_package_by_id($pdo, $del_id);
            if (!$row) {
                throw new Exception('That ad no longer exists.');
            }
            $stmt = $pdo->prepare("DELETE FROM bucket_packages WHERE id = :id");
            $stmt->execute(array(':id' => $del_id));
            package_delete_owned_image($row['image_path']);
            $redirect .= '?msg=deleted';

            if ($is_ajax) {
                pkg_json(array(
                    'success' => true,
                    'message' => 'Ad removed from the carousel.',
                    'id' => $del_id
                ));
            }
        } elseif ($action === 'toggle') {
            $tog_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
            $row = get_package_by_id($pdo, $tog_id);
            if (!$row) {
                throw new Exception('That ad no longer exists.');
            }
            $new_state = intval($row['is_active']) === 1 ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE bucket_packages SET is_active = :active, updated_at = :updated WHERE id = :id");
            $stmt->execute(array(':active' => $new_state, ':updated' => date('Y-m-d H:i:s'), ':id' => $tog_id));
            $redirect .= '?msg=' . ($new_state ? 'shown' : 'hidden');

            if ($is_ajax) {
                pkg_json(array(
                    'success' => true,
                    'message' => $new_state ? 'Ad is now visible on the client dashboard.' : 'Ad hidden from the client dashboard.',
                    'id' => $tog_id,
                    'is_active' => ($new_state === 1)
                ));
            }

        } elseif ($action === 'move') {
            $mv_id = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
            $dir = (isset($_POST['direction']) && $_POST['direction'] === 'up') ? 'up' : 'down';
            package_move($pdo, $mv_id, $dir);
            $redirect .= '?msg=reordered';

            if ($is_ajax) {
                pkg_json(array(
                    'success' => true,
                    'message' => 'Carousel order updated.',
                    'id' => $mv_id,
                    'direction' => $dir
                ));
            }

        } elseif ($action === 'close_inquiry') {
            $inq_id = isset($_POST['inquiry_id']) ? intval($_POST['inquiry_id']) : 0;
            if ($inq_id <= 0) {
                throw new Exception('That inquiry no longer exists.');
            }
            $stmt_ci = $pdo->prepare("UPDATE client_ad_inquiries SET status = 'Closed', updated_at = :now WHERE id = :id");
            $stmt_ci->execute(array(':now' => date('Y-m-d H:i:s'), ':id' => $inq_id));
            $redirect .= '?msg=inquiry_closed';

        } elseif ($action === 'reopen_inquiry') {
            $inq_id = isset($_POST['inquiry_id']) ? intval($_POST['inquiry_id']) : 0;
            if ($inq_id <= 0) {
                throw new Exception('That inquiry no longer exists.');
            }
            $stmt_ri = $pdo->prepare("UPDATE client_ad_inquiries SET status = 'Open', updated_at = :now WHERE id = :id");
            $stmt_ri->execute(array(':now' => date('Y-m-d H:i:s'), ':id' => $inq_id));
            $redirect .= '?msg=inquiry_reopened';

        } else {
            throw new Exception('Unknown action.');
        }
    // PDOException extends Exception, so it has to be caught first or the
    // generic handler below would swallow it and leak SQL detail to the URL.
    } catch (PDOException $e) {
        error_log("Package action error: " . $e->getMessage());
        if ($is_ajax) {
            pkg_json(array('success' => false, 'error' => 'Database error while saving the ad.'));
        }
        $redirect .= '?msg=error&err=' . urlencode('Database error while saving the ad.');
    } catch (Exception $e) {
        if ($is_ajax) {
            pkg_json(array('success' => false, 'error' => $e->getMessage()));
        }
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
    'added'     => 'Ad added to the dashboard carousel.',
    'updated'   => 'Ad updated.',
    'deleted'   => 'Ad removed from the carousel.',
    'shown'     => 'Ad is now visible on the client dashboard.',
    'hidden'    => 'Ad hidden from the client dashboard.',
    'reordered' => 'Carousel order updated.',
    'inquiry_closed'   => 'Inquiry conversation closed.',
    'inquiry_reopened' => 'Inquiry conversation reopened.'
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

// Preview mirrors the client dashboard carousel (see client index.php)
$preview = array();
foreach ($packages as $p) {
    if (intval($p['is_active']) === 1 && package_image_exists($p['image_path'])) {
        $preview[] = $p;
    }
}

$total_bytes = 0;
foreach ($packages as $p) {
    $abs = package_project_root() . '/' . package_normalize_relpath($p['image_path']);
    if (is_file($abs)) {
        $total_bytes += filesize($abs);
    }
}

// Ad inquiries inbox: live chat threads started from the dashboard
// carousel's Inquire button (stored in client_ad_inquiries, not tickets).
$ad_inquiries = get_recent_ad_inquiries($pdo, 10);
$ad_inq_counts = ad_inquiry_counts($pdo);
$ad_inq_open = isset($ad_inq_counts['open']) ? intval($ad_inq_counts['open']) : 0;
$ad_inq_awaiting = isset($ad_inq_counts['awaiting']) ? intval($ad_inq_counts['awaiting']) : 0;

// A notification click lands here with ?open_inquiry=ID - drop straight
// into that conversation once the page loads.
$auto_open_id = isset($_GET['open_inquiry']) ? intval($_GET['open_inquiry']) : 0;
$auto_open_inquiry = ($auto_open_id > 0) ? get_ad_inquiry($pdo, $auto_open_id) : null;

$active_page = 'packages';
$page_title = 'Dashboard Client Ads';
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

        /* Same sliding track the client dashboard carousel runs, so this preview is honest */
        .rnz-carousel-track { transition: transform 0.55s ease-in-out; }

        @media (prefers-reduced-motion: reduce) {
            .rnz-carousel-track { transition: none; }
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
                    <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight">Dashboard Client Ads</h1>
                    <p class="text-xs text-slate-500 mt-1">
                        Images and captions for the client dashboard ads carousel.
                    </p>
                </div>
                <div class="flex items-center gap-4 text-xs">
                    <div class="text-right">
                        <span class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">Showing</span>
                        <span id="pkgStatShowing" class="font-bold text-slate-900"><?php echo $active_count; ?> of <?php echo count($packages); ?></span>
                    </div>
                    <div class="text-right">
                        <span class="block text-slate-400 font-semibold uppercase tracking-wider text-[10px]">Images</span>
                        <span id="pkgStatImages" class="font-bold text-slate-900"><?php echo number_format($total_bytes / 1024, 0); ?> KB</span>
                    </div>
                </div>
            </div>

            <!-- Flash messages -->
            <div id="pkgAjaxFlash" class="hidden"></div>
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
                    <span id="adPreviewCount" class="text-[11px] text-slate-400"><?php echo count($preview); ?> visible</span>
                </div>
                    <div class="bg-white">
                        <div class="relative">
                            <div id="adViewport" class="overflow-hidden">
                                <div id="adTrack" class="rnz-carousel-track flex">
                                    <?php foreach ($preview as $p): ?>
                                        <div class="w-full shrink-0 relative">
                                            <div class="h-[560px] sm:h-[640px] w-full overflow-hidden bg-[#1c0e0a] flex items-center justify-center">
                                                <img src="<?php echo sanitize(package_image_url($p['image_path'], '../')); ?>"
                                                     alt="<?php echo sanitize($p['title']); ?>"
                                                     decoding="async"
                                                     class="h-full w-full object-contain pointer-events-none select-none" draggable="false">
                                            </div>
                                            <div class="absolute bottom-0 inset-x-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent p-4 sm:p-6 pt-12 pointer-events-none">
                                                <p class="text-base sm:text-lg text-white font-bold leading-snug"><?php echo sanitize($p['title']); ?></p>
                                                <?php if (trim($p['tag']) !== ''): ?>
                                                    <p class="text-xs sm:text-sm text-white/80 font-medium mt-0.5"><?php echo sanitize($p['tag']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <p id="adPreviewEmpty" class="px-5 py-10 text-center text-sm text-slate-400<?php echo empty($preview) ? '' : ' hidden'; ?>">
                                No visible ads &mdash; the carousel section is hidden on the client dashboard.
                            </p>
                            <button type="button" id="adPrev" aria-label="Previous ad" class="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 w-10 h-10 sm:w-11 sm:h-11 rounded-full bg-white/90 backdrop-blur border border-[#FECDAA] text-[#7C2112] hover:bg-[#EB3E0B] hover:border-[#EB3E0B] hover:text-white shadow-lg transition-all active:scale-95 flex items-center justify-center z-10<?php echo count($preview) > 1 ? '' : ' hidden'; ?>">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                            <button type="button" id="adNext" aria-label="Next ad" class="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 w-10 h-10 sm:w-11 sm:h-11 rounded-full bg-[#EB3E0B] border border-[#EB3E0B] text-white hover:bg-[#C32C0B] shadow-lg transition-all active:scale-95 flex items-center justify-center z-10<?php echo count($preview) > 1 ? '' : ' hidden'; ?>">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>
                    <script>
                    // Live preview carousel: named module (not an IIFE) so the
                    // no-refresh editor below can rebuild the slides and start
                    // it over without stacking duplicate listeners or timers.
                    var adPreviewPos = 1;
                    var adPreviewTotal = 0;
                    var adPreviewAnimating = false;
                    var adPreviewTimer = null;

                    function adPreviewRender(animate) {
                        var track = document.getElementById('adTrack');
                        if (!track) return;
                        track.style.transition = animate ? '' : 'none';
                        track.style.transform = 'translateX(-' + (adPreviewPos * 100) + '%)';
                        if (!animate) {
                            void track.offsetWidth;
                            track.style.transition = '';
                        }
                    }
                    function adPreviewStep(dir) {
                        if (adPreviewAnimating) return;
                        adPreviewAnimating = true;
                        adPreviewPos += dir;
                        adPreviewRender(true);
                        adPreviewRestart();
                    }
                    function adPreviewStop() { if (adPreviewTimer) { clearInterval(adPreviewTimer); adPreviewTimer = null; } }
                    function adPreviewRestart() {
                        adPreviewStop();
                        if (adPreviewTotal <= 1) return;
                        adPreviewTimer = setInterval(function () {
                            if (!adPreviewAnimating) {
                                adPreviewAnimating = true;
                                adPreviewPos += 1;
                                adPreviewRender(true);
                            }
                        }, 3000);
                    }
                    function initAdPreviewCarousel() {
                        var track = document.getElementById('adTrack');
                        var viewport = document.getElementById('adViewport');
                        if (!track || !viewport) return;
                        var clones = track.querySelectorAll('[data-adpreview-clone]');
                        for (var c = 0; c < clones.length; c++) {
                            clones[c].parentNode.removeChild(clones[c]);
                        }
                        adPreviewStop();
                        adPreviewTotal = track.children.length;
                        adPreviewPos = 1;
                        adPreviewAnimating = false;
                        var showArrows = adPreviewTotal > 1;
                        var prevBtn = document.getElementById('adPrev');
                        var nextBtn = document.getElementById('adNext');
                        if (prevBtn) prevBtn.classList.toggle('hidden', !showArrows);
                        if (nextBtn) nextBtn.classList.toggle('hidden', !showArrows);
                        if (adPreviewTotal <= 1) {
                            track.style.transition = 'none';
                            track.style.transform = 'translateX(0%)';
                            return;
                        }
                        // Seamless infinite loop, same as the client dashboard carousel.
                        var firstClone = track.children[0].cloneNode(true);
                        var lastClone = track.children[adPreviewTotal - 1].cloneNode(true);
                        firstClone.setAttribute('aria-hidden', 'true');
                        firstClone.setAttribute('data-adpreview-clone', '1');
                        lastClone.setAttribute('aria-hidden', 'true');
                        lastClone.setAttribute('data-adpreview-clone', '1');
                        track.appendChild(firstClone);
                        track.insertBefore(lastClone, track.children[0]);
                        adPreviewRender(false);
                        adPreviewRestart();
                    }
                    // One preview slide, mirroring the server markup above.
                    function buildAdPreviewSlide(s) {
                        var html = '<div class="w-full shrink-0 relative">' +
                            '<div class="h-[560px] sm:h-[640px] w-full overflow-hidden bg-[#1c0e0a] flex items-center justify-center">' +
                                '<img src="' + escapeHtml(s.image_url) + '" alt="' + escapeHtml(s.title) + '" decoding="async" class="h-full w-full object-contain pointer-events-none select-none" draggable="false">' +
                            '</div>' +
                            '<div class="absolute bottom-0 inset-x-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent p-4 sm:p-6 pt-12 pointer-events-none">' +
                                '<p class="text-base sm:text-lg text-white font-bold leading-snug">' + escapeHtml(s.title) + '</p>';
                        if (s.tag) {
                            html += '<p class="text-xs sm:text-sm text-white/80 font-medium mt-0.5">' + escapeHtml(s.tag) + '</p>';
                        }
                        html += '</div></div>';
                        return html;
                    }
                    // Rebuild the preview + counts after any list change.
                    function refreshAdPreview() {
                        fetch('packages.php?preview=1')
                            .then(function (res) { return res.json(); })
                            .then(function (data) {
                                if (!data || !data.success) return;
                                var track = document.getElementById('adTrack');
                                if (track) {
                                    track.innerHTML = '';
                                    for (var i = 0; i < data.slides.length; i++) {
                                        track.insertAdjacentHTML('beforeend', buildAdPreviewSlide(data.slides[i]));
                                    }
                                }
                                var empty = document.getElementById('adPreviewEmpty');
                                if (empty) empty.classList.toggle('hidden', data.slides.length > 0);
                                var cnt = document.getElementById('adPreviewCount');
                                if (cnt) cnt.textContent = data.visible + ' visible';
                                var showing = document.getElementById('pkgStatShowing');
                                if (showing) showing.textContent = data.visible + ' of ' + data.total;
                                var kb = document.getElementById('pkgStatImages');
                                if (kb) kb.textContent = data.kb + ' KB';
                                initAdPreviewCarousel();
                            })
                            .catch(function () { /* preview keeps its last state */ });
                    }

                        var adPreviewTrack = document.getElementById('adTrack');
                        var adPreviewViewport = document.getElementById('adViewport');
                        if (adPreviewTrack && adPreviewViewport) {
                            adPreviewTrack.addEventListener('transitionend', function (e) {
                                if (e && e.propertyName && e.propertyName !== 'transform') return;
                                if (adPreviewPos === adPreviewTotal + 1) {
                                    adPreviewPos = 1;
                                    adPreviewRender(false);
                                } else if (adPreviewPos === 0) {
                                    adPreviewPos = adPreviewTotal;
                                    adPreviewRender(false);
                                }
                                adPreviewAnimating = false;
                            });

                            document.getElementById('adNext').addEventListener('click', function () { adPreviewStep(1); });
                            document.getElementById('adPrev').addEventListener('click', function () { adPreviewStep(-1); });

                            adPreviewViewport.addEventListener('mouseenter', adPreviewStop);
                            adPreviewViewport.addEventListener('mouseleave', adPreviewRestart);

                            var adPreviewStartX = null;
                            adPreviewViewport.addEventListener('touchstart', function (e) {
                                adPreviewStartX = e.touches[0].clientX;
                                adPreviewStop();
                            }, { passive: true });
                            adPreviewViewport.addEventListener('touchend', function (e) {
                                if (adPreviewStartX === null) return;
                                var dx = e.changedTouches[0].clientX - adPreviewStartX;
                                if (Math.abs(dx) > 40) { adPreviewStep(dx < 0 ? 1 : -1); }
                                else { adPreviewRestart(); }
                                adPreviewStartX = null;
                            });
                            adPreviewViewport.addEventListener('mousedown', function (e) { adPreviewStartX = e.clientX; adPreviewStop(); });
                            adPreviewViewport.addEventListener('mouseup', function (e) {
                                if (adPreviewStartX === null) return;
                                var dxm = e.clientX - adPreviewStartX;
                                if (Math.abs(dxm) > 40) { adPreviewStep(dxm < 0 ? 1 : -1); }
                                else { adPreviewRestart(); }
                                adPreviewStartX = null;
                            });

                            initAdPreviewCarousel();
                        }
                    </script>
            </div>

            <!-- ADMIN AD INQUIRY CHAT POPUP -->
            <div id="adSupBox" class="hidden fixed bottom-4 right-4 z-50 w-[calc(100vw-2rem)] sm:w-[400px] bg-white rounded-2xl border border-slate-200 shadow-2xl overflow-hidden flex-col">
                <div class="shrink-0 bg-slate-900 text-white px-4 py-3 flex items-center gap-3">
                    <button type="button" onclick="toggleAdSupMinimize()" class="min-w-0 flex-1 text-left" title="Click to minimize / expand">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse shrink-0"></span>
                            <span id="adSupClient" class="font-bold text-xs truncate"></span>
                        </div>
                        <div id="adSupTitle" class="text-[11px] font-medium truncate text-slate-300"></div>
                    </button>
                    <div class="flex items-center gap-1 shrink-0">
                        <span id="adSupStatus" class="px-2 py-0.5 rounded-full whitespace-nowrap text-[9px] font-bold bg-white/10 border border-white/20">Open</span>
                        <button type="button" onclick="toggleAdSupMinimize()" title="Minimize" class="w-7 h-7 rounded-full hover:bg-white/10 flex items-center justify-center transition-colors">
                            <svg id="adSupMinIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 13H5"/></svg>
                        </button>
                        <button type="button" onclick="closeAdSupChat()" title="Close chat" class="w-7 h-7 rounded-full hover:bg-white/10 flex items-center justify-center transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
                <div id="adSupBody" class="flex flex-col min-h-0">
                    <div id="adSupThread" class="h-[46vh] sm:h-[340px] overflow-y-auto bg-slate-50 px-3.5 py-4 space-y-3"></div>
                    <div id="adSupClosed" class="hidden shrink-0 px-4 py-3 bg-amber-50 border-t border-amber-200 text-center">
                        <p class="text-[11px] font-bold text-amber-800">This conversation is Closed. Reopen it from the list to continue chatting.</p>
                    </div>
                    <div id="adSupComposer" class="shrink-0 border-t border-slate-200 bg-white p-3 space-y-2">
                        <div id="adSupError" class="hidden text-[10px] font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-xl px-2.5 py-1.5"></div>
                        <div class="flex items-end gap-2">
                            <textarea id="adSupInput" rows="2" placeholder="Reply to the client..." class="flex-1 resize-none bg-slate-50 border border-slate-200 text-slate-800 text-xs rounded-xl px-3 py-2.5 max-h-28 focus:bg-white focus:border-[#EB3E0B] focus:outline-none transition-all"></textarea>
                            <button type="button" onclick="sendAdSupChat()" title="Send message (Enter)" class="w-9 h-9 shrink-0 rounded-full bg-[#EB3E0B] hover:bg-[#C32C0B] active:scale-95 text-white flex items-center justify-center transition-all">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($auto_open_inquiry):
                $auto_open_name = (isset($auto_open_inquiry['tradename']) && trim($auto_open_inquiry['tradename']) !== '') ? $auto_open_inquiry['tradename'] : $auto_open_inquiry['clientname'];
            ?>
                <button type="button" id="adSupAutoOpen" class="hidden" data-inquiry-id="<?php echo intval($auto_open_inquiry['id']); ?>" data-client="<?php echo sanitize($auto_open_name); ?>" data-ad="<?php echo sanitize($auto_open_inquiry['ad_title']); ?>" data-number="<?php echo sanitize($auto_open_inquiry['inquiry_number']); ?>"></button>
            <?php endif; ?>
            <script>
            // Admin side of the ad inquiry realtime chat: pick a thread from the
            // inbox above and the conversation runs here, polling every 3s.
            var adSupInquiryId = 0;
            var adSupLastId = 0;
            var adSupTimer = null;
            var adSupSending = false;
            var adSupMinimized = false;

            function escapeAdSupHtml(str) {
                return String(str === null || typeof str === 'undefined' ? '' : str)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            // The ad this chat is about: image + title + caption pinned as the
            // first thing in the thread, straight from the inquiry record.
            // Paths are stored relative to the project root, so prefix them
            // to reach the file from inside /backend.
            function buildAdSupCard(info) {
                var title = (info && info.ad_title) ? info.ad_title : '';
                var tag = (info && info.ad_tag) ? info.ad_tag : '';
                var img = (info && info.ad_image) ? info.ad_image : '';
                var html = '<div class="bg-white border border-slate-200 rounded-xl overflow-hidden" data-adsup-adcard="1">';
                if (img) {
                    html += '<img src="' + escapeAdSupHtml(encodeURI('../' + img)) + '" alt="" class="w-full h-40 object-cover pointer-events-none">';
                }
                html += '<div class="p-3">' +
                    '<p class="text-[11px] font-extrabold text-slate-900 leading-snug">' + escapeAdSupHtml(title) + '</p>';
                if (tag) {
                    html += '<p class="text-[10px] text-[#EB3E0B] font-semibold mt-0.5">' + escapeAdSupHtml(tag) + '</p>';
                }
                html += '</div></div>';
                return html;
            }

            function buildAdSupBubble(m) {
                var mine = !!m.is_mine;
                var bubbleClass = mine
                    ? 'bg-[#EB3E0B] text-white rounded-br-md'
                    : 'bg-white border-slate-200 text-slate-800 rounded-bl-md';
                var textClass = mine ? 'text-white' : 'text-slate-800';
                var label = escapeAdSupHtml(m.sender_name) + ' - ' + escapeAdSupHtml(m.formatted_date);
                return '<div class="flex flex-col ' + (mine ? 'items-end' : 'items-start') + '" data-adsup-msg="' + parseInt(m.id, 10) + '">' +
                    '<span class="text-[9px] font-bold uppercase tracking-wider mb-1 px-1 text-slate-400">' + label + '</span>' +
                    '<div class="max-w-[85%] px-3.5 py-2.5 rounded-2xl border text-left ' + bubbleClass + (mine ? ' border-[#EB3E0B]' : '') + '">' +
                        '<p class="text-[11.5px] leading-relaxed font-medium whitespace-pre-wrap break-words ' + textClass + '">' + escapeAdSupHtml(m.message) + '</p>' +
                    '</div>' +
                '</div>';
            }

            function showAdSupError(msg) {
                var box = document.getElementById('adSupError');
                if (!msg) {
                    box.classList.add('hidden');
                    box.textContent = '';
                    return;
                }
                box.textContent = msg;
                box.classList.remove('hidden');
            }

            function applyAdSupStatus(status) {
                document.getElementById('adSupStatus').textContent = status || 'Open';
                var closed = (status === 'Closed');
                document.getElementById('adSupComposer').classList.toggle('hidden', closed);
                document.getElementById('adSupClosed').classList.toggle('hidden', !closed);
            }

            function adSupScrollBottom() {
                var thread = document.getElementById('adSupThread');
                thread.scrollTop = thread.scrollHeight;
            }

            function adSupUpdateRow(id, text) {
                var row = document.getElementById('adSupRow' + id);
                if (!row) {
                    return;
                }
                var prev = row.querySelector('.adSupLast');
                if (prev && text) {
                    prev.textContent = text;
                    prev.classList.remove('italic');
                }
            }

            function openAdSupChat(btn) {
                var id = parseInt(btn.getAttribute('data-inquiry-id'), 10);
                if (!id) {
                    return;
                }
                adSupInquiryId = id;
                adSupLastId = 0;
                document.getElementById('adSupClient').textContent = btn.getAttribute('data-client') || 'Client';
                document.getElementById('adSupTitle').textContent = (btn.getAttribute('data-ad') || '') + ' - ' + (btn.getAttribute('data-number') || '');
                var box = document.getElementById('adSupBox');
                box.classList.remove('hidden');
                box.classList.add('flex');
                if (adSupMinimized) {
                    toggleAdSupMinimize();
                }
                document.getElementById('adSupThread').innerHTML = '<div class="text-center text-[11px] text-slate-400 font-bold py-6">Loading conversation...</div>';
                showAdSupError('');
                loadAdSupChat(true);
                if (adSupTimer) {
                    clearInterval(adSupTimer);
                }
                adSupTimer = setInterval(function () { loadAdSupChat(false); }, 3000);
            }

            function loadAdSupChat(scroll) {
                if (!adSupInquiryId) {
                    return;
                }
                var thread = document.getElementById('adSupThread');
                var stick = scroll || ((thread.scrollHeight - thread.scrollTop - thread.clientHeight) < 100);
                fetch('api_ad_inquiry.php?id=' + adSupInquiryId + '&after_id=' + adSupLastId)
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || !data.success) {
                            return;
                        }
                        applyAdSupStatus(data.status);
                        if (adSupLastId === 0) {
                            thread.innerHTML = '';
                            if (data.inquiry && !thread.querySelector('[data-adsup-adcard]')) {
                                thread.insertAdjacentHTML('beforeend', buildAdSupCard(data.inquiry));
                            }
                        }
                        var list = data.messages || [];
                        for (var i = 0; i < list.length; i++) {
                            var mid = parseInt(list[i].id, 10);
                            if (thread.querySelector('[data-adsup-msg="' + mid + '"]')) {
                                continue;
                            }
                            thread.insertAdjacentHTML('beforeend', buildAdSupBubble(list[i]));
                            if (mid > adSupLastId) {
                                adSupLastId = mid;
                            }
                            adSupUpdateRow(adSupInquiryId, (list[i].is_mine ? 'You' : list[i].sender_name) + ': ' + list[i].message);
                        }
                        if (stick && list.length) {
                            adSupScrollBottom();
                        }
                    })
                    .catch(function () { /* the next poll retries */ });
            }

            function sendAdSupChat() {
                if (adSupSending || !adSupInquiryId) {
                    return;
                }
                var input = document.getElementById('adSupInput');
                var text = input.value.replace(/^\s+|\s+$/g, '');
                if (text === '') {
                    return;
                }
                adSupSending = true;
                showAdSupError('');

                var body = new FormData();
                body.append('action', 'send');
                body.append('id', adSupInquiryId);
                body.append('message', text);

                fetch('api_ad_inquiry.php', { method: 'POST', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        adSupSending = false;
                        if (data && data.success && data.message) {
                            var thread = document.getElementById('adSupThread');
                            thread.insertAdjacentHTML('beforeend', buildAdSupBubble(data.message));
                            var mid = parseInt(data.message.id, 10);
                            if (mid > adSupLastId) {
                                adSupLastId = mid;
                            }
                            input.value = '';
                            adSupScrollBottom();
                            adSupUpdateRow(adSupInquiryId, 'You: ' + data.message.message);
                        } else {
                            showAdSupError((data && data.error) ? data.error : 'Your message could not be sent.');
                        }
                    })
                    .catch(function () {
                        adSupSending = false;
                        showAdSupError('Network error - your message was not sent.');
                    });
            }

            function closeAdSupChat() {
                var box = document.getElementById('adSupBox');
                box.classList.add('hidden');
                box.classList.remove('flex');
                if (adSupTimer) {
                    clearInterval(adSupTimer);
                    adSupTimer = null;
                }
                adSupInquiryId = 0;
                adSupLastId = 0;
            }

            function toggleAdSupMinimize() {
                adSupMinimized = !adSupMinimized;
                var bodyEl = document.getElementById('adSupBody');
                var icon = document.getElementById('adSupMinIcon');
                if (adSupMinimized) {
                    bodyEl.classList.add('hidden');
                    bodyEl.classList.remove('flex');
                    icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>';
                } else {
                    bodyEl.classList.remove('hidden');
                    bodyEl.classList.add('flex');
                    icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 13H5"/>';
                    adSupScrollBottom();
                }
            }

            document.addEventListener('DOMContentLoaded', function () {
                var input = document.getElementById('adSupInput');
                if (input) {
                    input.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            sendAdSupChat();
                        }
                    });
                }
            });

            // A notification click lands here with ?open_inquiry=ID - drop
            // straight into that conversation.
            document.addEventListener('DOMContentLoaded', function () {
                var auto = document.getElementById('adSupAutoOpen');
                if (auto && typeof openAdSupChat === 'function') {
                    openAdSupChat(auto);
                }
            });
            </script>

            <div class="grid grid-cols-1 lg:grid-cols-[22rem_1fr] gap-6 items-start">

                <!-- Add / Edit form -->
                <form method="POST" enctype="multipart/form-data" id="pkgAdForm" onsubmit="return pkgSaveSubmit(this);"
                      class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 space-y-4">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="package_id" id="pkgFormId" value="<?php echo $editing ? intval($editing['id']) : 0; ?>">

                    <div class="flex items-center justify-between">
                        <h2 id="pkgFormTitle" class="text-xs font-bold text-slate-900 uppercase tracking-wider">
                            <?php echo $editing ? 'Edit Ad' : 'Add Ad'; ?>
                        </h2>
                        <button type="button" id="pkgCancelEdit" onclick="pkgResetForm()" class="text-[11px] font-semibold text-slate-400 hover:text-slate-700<?php echo $editing ? '' : ' hidden'; ?>">Cancel</button>
                    </div>

                    <div id="pkgCurrentImageBox">
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
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">Title</label>
                        <input type="text" name="title" id="pkgFieldTitle" required maxlength="150"
                               value="<?php echo $editing ? sanitize($editing['title']) : ''; ?>"
                               placeholder="Complete POS Package"
                               class="w-full px-3 py-2 text-sm rounded-xl border border-slate-200 focus:border-[#EB3E0B] focus:ring-1 focus:ring-[#EB3E0B] outline-none">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">
                            Caption <span class="font-medium text-slate-400">(optional)</span>
                        </label>
                        <input type="text" name="tag" id="pkgFieldTag" maxlength="150"
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
                            <input type="file" name="image_file" id="pkgFieldFile" accept="image/jpeg,image/png,image/webp,image/gif"
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
                            <p id="pkgKeepNote" class="text-[10px] text-slate-400 pl-5">Leave blank to keep the current image.</p>
                        <?php else: ?>
                            <p id="pkgKeepNote" class="text-[10px] text-slate-400 pl-5 hidden">Leave blank to keep the current image.</p>
                        <?php endif; ?>
                    </div>

                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 pt-1">
                        <input type="checkbox" name="is_active" id="pkgFieldActive" value="1" class="accent-[#EB3E0B]"
                               <?php echo (!$editing || intval($editing['is_active']) === 1) ? 'checked' : ''; ?>>
                        Show on the client dashboard
                    </label>

                    <button type="submit" id="pkgFormSubmit"
                            class="w-full bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-colors active:scale-95 disabled:opacity-50">
                        <?php echo $editing ? 'Save Changes' : 'Add Ad'; ?>
                    </button>
                </form>

                <!-- Ad list -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider">Carousel Order</h2>
                    </div>

                    <?php if (empty($packages)): ?>
                        <div id="pkgAdListWrap">
                            <p class="px-5 py-12 text-center text-sm text-slate-400">
                                No ads yet. Add one using the form on the left.
                            </p>
                        </div>
                    <?php else: ?>
                        <div id="pkgAdListWrap">
                        <ul id="pkgAdList" class="divide-y divide-slate-100">
                            <?php foreach ($packages as $i => $p):
                                $missing = !package_image_exists($p['image_path']);
                                $is_on = (intval($p['is_active']) === 1);
                            ?>
                                <li data-pkg-row="<?php echo intval($p['id']); ?>" class="flex items-center gap-3 px-4 py-3 <?php echo $is_on ? '' : 'bg-slate-50/70'; ?>">
                                    <!-- Order controls -->
                                    <div class="flex flex-col gap-0.5 shrink-0">
                                        <form method="POST" onsubmit="return pkgRowSubmit(this);">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="package_id" value="<?php echo intval($p['id']); ?>">
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" title="Move up" data-pkg-move="up" <?php echo $i === 0 ? 'disabled' : ''; ?>
                                                    class="w-6 h-5 flex items-center justify-center rounded text-slate-400 hover:text-[#EB3E0B] hover:bg-slate-100 disabled:opacity-25 disabled:hover:bg-transparent disabled:cursor-not-allowed">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/>
                                                </svg>
                                            </button>
                                        </form>
                                        <form method="POST" onsubmit="return pkgRowSubmit(this);">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="package_id" value="<?php echo intval($p['id']); ?>">
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" title="Move down" data-pkg-move="down" <?php echo $i === count($packages) - 1 ? 'disabled' : ''; ?>
                                                    class="w-6 h-5 flex items-center justify-center rounded text-slate-400 hover:text-[#EB3E0B] hover:bg-slate-100 disabled:opacity-25 disabled:hover:bg-transparent disabled:cursor-not-allowed">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Thumbnail -->
                                    <?php if ($missing): ?>
                                        <div data-pkg-thumb class="w-11 h-14 rounded-lg bg-rose-50 border border-rose-200 flex items-center justify-center shrink-0">
                                            <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                            </svg>
                                        </div>
                                    <?php else: ?>
                                        <img data-pkg-thumb src="<?php echo sanitize(package_image_url($p['image_path'], '../')); ?>"
                                             alt="" class="w-11 h-14 object-cover rounded-lg bg-slate-200 shrink-0 <?php echo $is_on ? '' : 'opacity-40 grayscale'; ?>">
                                    <?php endif; ?>

                                    <!-- Text -->
                                    <div class="min-w-0 flex-1">
                                        <p data-pkg-title class="text-sm font-bold text-slate-900 truncate"><?php echo sanitize($p['title']); ?></p>
                                        <p data-pkg-tag class="text-[11px] text-[#EB3E0B] font-medium truncate<?php echo trim($p['tag']) !== '' ? '' : ' hidden'; ?>"><?php echo sanitize($p['tag']); ?></p>
                                        <p data-pkg-path class="text-[10px] text-slate-400 truncate font-mono mt-0.5"><?php echo sanitize($p['image_path']); ?></p>
                                        <p data-pkg-missing class="text-[10px] text-rose-600 font-bold mt-0.5<?php echo $missing ? '' : ' hidden'; ?>">Image file is missing &mdash; this ad is skipped on the dashboard.</p>
                                    </div>

                                    <!-- Status + actions -->
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <form method="POST" onsubmit="return pkgRowSubmit(this);">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="package_id" value="<?php echo intval($p['id']); ?>">
                                            <button type="submit" data-pkg-toggle
                                                    title="<?php echo $is_on ? 'Hide from dashboard' : 'Show on dashboard'; ?>"
                                                    class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wide transition-colors <?php echo $is_on ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100' : 'bg-slate-100 text-slate-500 border border-slate-200 hover:bg-slate-200'; ?>">
                                                <?php echo $is_on ? 'Visible' : 'Hidden'; ?>
                                            </button>
                                        </form>

                                        <button type="button" onclick="pkgStartEdit(<?php echo intval($p['id']); ?>)"
                                           title="Edit"
                                           class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-[#EB3E0B] hover:bg-slate-100 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </button>

                                        <!-- Title rides in a data attribute: entity-escaping it into a
                                             JS string literal would break on any ad with an apostrophe -->
                                        <form method="POST" data-pkg-title="<?php echo sanitize($p['title']); ?>"
                                              onsubmit="return pkgDeleteSubmit(this);">
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
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ad inquiries inbox: live chat threads started from the carousel Inquire button -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider">Ad Inquiries</h2>
                    <p class="text-[11px] text-slate-400 mt-0.5"><?php echo $ad_inq_open; ?> open &middot; <?php echo $ad_inq_awaiting; ?> awaiting reply</p>
                </div>
                <?php if (empty($ad_inquiries)): ?>
                    <p class="px-5 py-10 text-center text-sm text-slate-400">
                        No ad inquiries yet &mdash; new chats from the client dashboard carousel will appear here in realtime.
                    </p>
                <?php else: ?>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach ($ad_inquiries as $inq):
                            $inq_name = (isset($inq['tradename']) && trim($inq['tradename']) !== '') ? $inq['tradename'] : $inq['clientname'];
                            $inq_last = isset($inq['last_msg']) ? trim($inq['last_msg']) : '';
                            $inq_last = ad_inquiry_clip($inq_last, 100);
                            $inq_is_open = (isset($inq['status']) && $inq['status'] === 'Open');
                            $inq_needs_reply = ($inq_is_open && (!isset($inq['last_sender']) || $inq['last_sender'] !== 'support'));
                        ?>
                            <li id="adSupRow<?php echo intval($inq['id']); ?>" class="flex items-center gap-3 px-4 py-3 <?php echo $inq_needs_reply ? 'bg-amber-50/50' : ''; ?>">
                                <div class="w-9 h-9 rounded-xl bg-[#FFE8D5] text-[#C32C0B] font-extrabold text-xs flex items-center justify-center shrink-0">
                                    <?php echo sanitize(strtoupper(substr($inq_name, 0, 1))); ?>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-bold text-slate-900 truncate"><?php echo sanitize($inq_name); ?></p>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo get_status_badge_class($inq['status']); ?>"><?php echo sanitize($inq['status']); ?></span>
                                        <?php if ($inq_needs_reply): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">Needs reply</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs font-semibold text-slate-700 truncate mt-0.5"><?php echo sanitize($inq['ad_title']); ?> <span class="font-mono font-normal text-slate-400">&middot; <?php echo sanitize($inq['inquiry_number']); ?></span></p>
                                    <?php if ($inq_last !== ''): ?>
                                        <p class="adSupLast text-[11px] text-slate-500 truncate mt-0.5"><?php echo sanitize(((isset($inq['last_sender']) && $inq['last_sender'] === 'support') ? ((isset($inq['last_sender_name']) && trim($inq['last_sender_name']) !== '') ? $inq['last_sender_name'] : 'Support') : 'Client') . ': ' . $inq_last); ?></p>
                                    <?php else: ?>
                                        <p class="adSupLast text-[11px] text-slate-400 italic mt-0.5">Chat started &mdash; no messages yet.</p>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right shrink-0 space-y-1.5">
                                    <p class="text-[10px] text-slate-400 font-mono"><?php echo sanitize(format_date($inq['updated_at'])); ?></p>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" onclick="openAdSupChat(this)" data-inquiry-id="<?php echo intval($inq['id']); ?>" data-client="<?php echo sanitize($inq_name); ?>" data-ad="<?php echo sanitize($inq['ad_title']); ?>" data-number="<?php echo sanitize($inq['inquiry_number']); ?>" class="inline-flex items-center gap-1 bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-[11px] font-bold px-3 py-1.5 rounded-lg transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                            <span>Chat</span>
                                        </button>
                                        <?php if ($inq_is_open): ?>
                                            <form method="POST" onsubmit="return confirm('Close this inquiry conversation?');">
                                                <input type="hidden" name="action" value="close_inquiry">
                                                <input type="hidden" name="inquiry_id" value="<?php echo intval($inq['id']); ?>">
                                                <button type="submit" title="Close conversation" class="inline-flex items-center px-2.5 py-1.5 rounded-lg text-[11px] font-bold bg-slate-100 text-slate-500 border border-slate-200 hover:bg-slate-200 transition-colors">Close</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="reopen_inquiry">
                                                <input type="hidden" name="inquiry_id" value="<?php echo intval($inq['id']); ?>">
                                                <button type="submit" title="Reopen conversation" class="inline-flex items-center px-2.5 py-1.5 rounded-lg text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition-colors">Reopen</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
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
        var title = form.getAttribute('data-pkg-title') || 'this ad';
        return confirm('Remove "' + title + '" from the carousel?');
    }

    // =========================================================================
    // NO-REFRESH LIST EDITOR (sorting, visibility, delete, add/edit)
    // Every row form posts with ajax=1 and the page patches itself in place;
    // with JS off the same forms fall back to the classic POST + redirect.
    // =========================================================================
    var pkgFlashTimer = null;

    function pkgFlash(msg, isError) {
        var box = document.getElementById('pkgAjaxFlash');
        if (!box) return;
        box.className = 'rounded-2xl px-4 py-3 text-sm font-semibold flex items-center gap-2 ' +
            (isError ? 'bg-rose-50 border border-rose-200 text-rose-800'
                     : 'bg-emerald-50 border border-emerald-200 text-emerald-800');
        box.textContent = msg;
        box.classList.remove('hidden');
        if (pkgFlashTimer) {
            clearTimeout(pkgFlashTimer);
        }
        pkgFlashTimer = setTimeout(function () {
            box.classList.add('hidden');
        }, 4000);
    }

    // Generic row submit for the move / visibility / delete forms.
    function pkgRowSubmit(form) {
        var actionEl = form.querySelector('input[name="action"]');
        var action = actionEl ? actionEl.value : '';
        var data = new FormData(form);
        data.append('ajax', '1');
        fetch('packages.php', { method: 'POST', body: data })
            .then(function (res) { return res.json(); })
            .then(function (d) {
                if (!d || !d.success) {
                    pkgFlash((d && d.error) ? d.error : 'Something went wrong.', true);
                    return;
                }
                if (action === 'move') {
                    pkgApplyMove(d);
                } else if (action === 'toggle') {
                    pkgApplyToggle(d);
                } else if (action === 'delete') {
                    pkgApplyDelete(d);
                }
                pkgFlash(d.message, false);
                refreshAdPreview();
            })
            .catch(function () {
                pkgFlash('Network error - please try again.', true);
            });
        return false;
    }

    function pkgDeleteSubmit(form) {
        if (!pkgConfirmDelete(form)) {
            return false;
        }
        return pkgRowSubmit(form);
    }

    function pkgApplyMove(d) {
        var li = document.querySelector('li[data-pkg-row="' + parseInt(d.id, 10) + '"]');
        if (!li || !li.parentNode) return;
        if (d.direction === 'up' && li.previousElementSibling) {
            li.parentNode.insertBefore(li, li.previousElementSibling);
        } else if (d.direction === 'down' && li.nextElementSibling) {
            li.parentNode.insertBefore(li.nextElementSibling, li);
        }
        pkgFixMoveEdges();
    }

    // Only the first row can move up and only the last can move down.
    function pkgFixMoveEdges() {
        var items = document.querySelectorAll('#pkgAdList > li[data-pkg-row]');
        for (var i = 0; i < items.length; i++) {
            var up = items[i].querySelector('[data-pkg-move="up"]');
            var down = items[i].querySelector('[data-pkg-move="down"]');
            if (up) up.disabled = (i === 0);
            if (down) down.disabled = (i === items.length - 1);
        }
    }

    var PKG_TOGGLE_ON = 'px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wide transition-colors bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100';
    var PKG_TOGGLE_OFF = 'px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wide transition-colors bg-slate-100 text-slate-500 border border-slate-200 hover:bg-slate-200';

    function pkgApplyToggle(d) {
        var li = document.querySelector('li[data-pkg-row="' + parseInt(d.id, 10) + '"]');
        if (!li) return;
        var on = !!d.is_active;
        var btn = li.querySelector('[data-pkg-toggle]');
        if (btn) {
            btn.className = on ? PKG_TOGGLE_ON : PKG_TOGGLE_OFF;
            btn.textContent = on ? 'Visible' : 'Hidden';
            btn.title = on ? 'Hide from dashboard' : 'Show on dashboard';
        }
        var thumb = li.querySelector('[data-pkg-thumb]');
        if (thumb && thumb.tagName === 'IMG') {
            if (on) {
                thumb.classList.remove('opacity-40', 'grayscale');
            } else {
                thumb.classList.add('opacity-40', 'grayscale');
            }
        }
        if (on) {
            li.classList.remove('bg-slate-50/70');
        } else {
            li.classList.add('bg-slate-50/70');
        }
    }

    var PKG_EMPTY_HTML = '<p class="px-5 py-12 text-center text-sm text-slate-400">No ads yet. Add one using the form on the left.</p>';

    function pkgApplyDelete(d) {
        var li = document.querySelector('li[data-pkg-row="' + parseInt(d.id, 10) + '"]');
        if (!li) return;
        var list = li.parentNode;
        list.removeChild(li);
        if (list && list.id === 'pkgAdList') {
            if (!list.querySelector('li[data-pkg-row]')) {
                document.getElementById('pkgAdListWrap').innerHTML = PKG_EMPTY_HTML;
            } else {
                pkgFixMoveEdges();
            }
        }
        var formId = document.getElementById('pkgFormId');
        if (formId && parseInt(formId.value, 10) === parseInt(d.id, 10)) {
            pkgResetForm();
        }
    }

    function pkgThumbHtml(r) {
        if (r.image_missing) {
            return '<div data-pkg-thumb class="w-11 h-14 rounded-lg bg-rose-50 border border-rose-200 flex items-center justify-center shrink-0">'
                + '<svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                + '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>'
                + '</svg></div>';
        }
        return '<img data-pkg-thumb src="' + escapeHtml(r.image_url) + '" alt="" class="w-11 h-14 object-cover rounded-lg bg-slate-200 shrink-0' + (r.is_active ? '' : ' opacity-40 grayscale') + '">';
    }

    // One list row, mirroring the server markup above (used for added ads).
    function buildPkgRow(r, isFirst, isLast) {
        var tagHtml = '<p data-pkg-tag class="text-[11px] text-[#EB3E0B] font-medium truncate' + (r.tag ? '' : ' hidden') + '">' + escapeHtml(r.tag) + '</p>';
        var html = '<li data-pkg-row="' + parseInt(r.id, 10) + '" class="flex items-center gap-3 px-4 py-3' + (r.is_active ? '' : ' bg-slate-50/70') + '">'
            + '<div class="flex flex-col gap-0.5 shrink-0">'
            + '<form method="POST" onsubmit="return pkgRowSubmit(this);">'
            + '<input type="hidden" name="action" value="move">'
            + '<input type="hidden" name="package_id" value="' + parseInt(r.id, 10) + '">'
            + '<input type="hidden" name="direction" value="up">'
            + '<button type="submit" title="Move up" data-pkg-move="up"' + (isFirst ? ' disabled' : '') + ' class="w-6 h-5 flex items-center justify-center rounded text-slate-400 hover:text-[#EB3E0B] hover:bg-slate-100 disabled:opacity-25 disabled:hover:bg-transparent disabled:cursor-not-allowed">'
            + '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/></svg>'
            + '</button></form>'
            + '<form method="POST" onsubmit="return pkgRowSubmit(this);">'
            + '<input type="hidden" name="action" value="move">'
            + '<input type="hidden" name="package_id" value="' + parseInt(r.id, 10) + '">'
            + '<input type="hidden" name="direction" value="down">'
            + '<button type="submit" title="Move down" data-pkg-move="down"' + (isLast ? ' disabled' : '') + ' class="w-6 h-5 flex items-center justify-center rounded text-slate-400 hover:text-[#EB3E0B] hover:bg-slate-100 disabled:opacity-25 disabled:hover:bg-transparent disabled:cursor-not-allowed">'
            + '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>'
            + '</button></form>'
            + '</div>'
            + pkgThumbHtml(r)
            + '<div class="min-w-0 flex-1">'
            + '<p data-pkg-title class="text-sm font-bold text-slate-900 truncate">' + escapeHtml(r.title) + '</p>'
            + tagHtml
            + '<p data-pkg-path class="text-[10px] text-slate-400 truncate font-mono mt-0.5">' + escapeHtml(r.image_path) + '</p>'
            + '<p data-pkg-missing class="text-[10px] text-rose-600 font-bold mt-0.5' + (r.image_missing ? '' : ' hidden') + '">Image file is missing &mdash; this ad is skipped on the dashboard.</p>'
            + '</div>'
            + '<div class="flex items-center gap-1.5 shrink-0">'
            + '<form method="POST" onsubmit="return pkgRowSubmit(this);">'
            + '<input type="hidden" name="action" value="toggle">'
            + '<input type="hidden" name="package_id" value="' + parseInt(r.id, 10) + '">'
            + '<button type="submit" data-pkg-toggle title="' + (r.is_active ? 'Hide from dashboard' : 'Show on dashboard') + '" class="' + (r.is_active ? PKG_TOGGLE_ON : PKG_TOGGLE_OFF) + '">' + (r.is_active ? 'Visible' : 'Hidden') + '</button>'
            + '</form>'
            + '<button type="button" onclick="pkgStartEdit(' + parseInt(r.id, 10) + ')" title="Edit" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-[#EB3E0B] hover:bg-slate-100 transition-colors">'
            + '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>'
            + '</button>'
            + '<form method="POST" data-pkg-title="' + escapeHtml(r.title) + '" onsubmit="return pkgDeleteSubmit(this);">'
            + '<input type="hidden" name="action" value="delete">'
            + '<input type="hidden" name="package_id" value="' + parseInt(r.id, 10) + '">'
            + '<button type="submit" title="Delete" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors">'
            + '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>'
            + '</button>'
            + '</form>'
            + '</div></li>';
        return html;
    }

    function pkgAppendRow(r) {
        var wrap = document.getElementById('pkgAdListWrap');
        var list = document.getElementById('pkgAdList');
        if (!list) {
            wrap.innerHTML = '<ul id="pkgAdList" class="divide-y divide-slate-100"></ul>';
            list = document.getElementById('pkgAdList');
        }
        list.insertAdjacentHTML('beforeend', buildPkgRow(r, list.children.length === 0, true));
        pkgFixMoveEdges();
    }

    function pkgUpdateRow(r) {
        var li = document.querySelector('li[data-pkg-row="' + parseInt(r.id, 10) + '"]');
        if (!li) {
            pkgAppendRow(r);
            return;
        }
        li.querySelector('[data-pkg-title]').textContent = r.title;
        var tagEl = li.querySelector('[data-pkg-tag]');
        tagEl.textContent = r.tag;
        tagEl.classList.toggle('hidden', !r.tag);
        li.querySelector('[data-pkg-path]').textContent = r.image_path;
        var thumb = li.querySelector('[data-pkg-thumb]');
        if (thumb) {
            var tmp = document.createElement('div');
            tmp.innerHTML = pkgThumbHtml(r);
            thumb.parentNode.replaceChild(tmp.firstChild, thumb);
        }
        var note = li.querySelector('[data-pkg-missing]');
        if (note) {
            note.classList.toggle('hidden', !r.image_missing);
        }
        var delForm = li.querySelector('form[data-pkg-title]');
        if (delForm) {
            delForm.setAttribute('data-pkg-title', r.title);
        }
        pkgApplyToggle({ id: r.id, is_active: r.is_active });
    }

    // Add / edit submit without leaving the page (file uploads included).
    function pkgSaveSubmit(form) {
        var data = new FormData(form);
        data.append('ajax', '1');
        var btn = document.getElementById('pkgFormSubmit');
        if (btn) {
            btn.disabled = true;
        }
        fetch('packages.php', { method: 'POST', body: data })
            .then(function (res) { return res.json(); })
            .then(function (d) {
                if (btn) {
                    btn.disabled = false;
                }
                if (!d || !d.success) {
                    pkgFlash((d && d.error) ? d.error : 'Something went wrong.', true);
                    return;
                }
                if (d.is_new) {
                    pkgAppendRow(d.row);
                } else {
                    pkgUpdateRow(d.row);
                }
                pkgResetForm();
                pkgFlash(d.message, false);
                refreshAdPreview();
            })
            .catch(function () {
                if (btn) {
                    btn.disabled = false;
                }
                pkgFlash('Network error - please try again.', true);
            });
        return false;
    }

    // Inline edit: fill the form from the server and jump to it - no reload.
    function pkgStartEdit(id) {
        fetch('packages.php?row=' + parseInt(id, 10))
            .then(function (res) { return res.json(); })
            .then(function (d) {
                if (!d || !d.success) {
                    pkgFlash((d && d.error) ? d.error : 'That ad could not be loaded.', true);
                    return;
                }
                var r = d.row;
                document.getElementById('pkgFormId').value = r.id;
                document.getElementById('pkgFieldTitle').value = r.title;
                document.getElementById('pkgFieldTag').value = r.tag;
                document.getElementById('pkgFieldActive').checked = !!r.is_active;
                document.getElementById('pkgFormTitle').textContent = 'Edit Ad';
                document.getElementById('pkgFormSubmit').textContent = 'Save Changes';
                document.getElementById('pkgCurrentImageBox').innerHTML = '<div class="flex items-center gap-3 bg-slate-50 border border-slate-200 rounded-xl p-2.5">'
                    + '<img src="' + escapeHtml(r.image_url) + '" alt="" class="w-12 h-14 object-cover rounded-lg bg-slate-200 shrink-0">'
                    + '<div class="min-w-0"><p class="text-[11px] font-bold text-slate-700">Current image</p>'
                    + '<p class="text-[10px] text-slate-400 truncate font-mono">' + escapeHtml(r.image_path) + '</p></div></div>';
                document.getElementById('pkgCancelEdit').classList.remove('hidden');
                document.getElementById('pkgKeepNote').classList.remove('hidden');
                var sources = document.querySelectorAll('input[name="image_source"]');
                if (sources.length) {
                    sources[0].checked = true;
                    pkgToggleSource();
                }
                var form = document.getElementById('pkgAdForm');
                if (form && form.scrollIntoView) {
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                document.getElementById('pkgFieldTitle').focus();
            })
            .catch(function () {
                pkgFlash('Network error - please try again.', true);
            });
    }

    function pkgResetForm() {
        var form = document.getElementById('pkgAdForm');
        if (form) {
            form.reset();
        }
        document.getElementById('pkgFormId').value = '0';
        document.getElementById('pkgFieldTitle').value = '';
        document.getElementById('pkgFieldTag').value = '';
        document.getElementById('pkgFieldActive').checked = true;
        document.getElementById('pkgFormTitle').textContent = 'Add Ad';
        document.getElementById('pkgFormSubmit').textContent = 'Add Ad';
        document.getElementById('pkgCurrentImageBox').innerHTML = '';
        document.getElementById('pkgCancelEdit').classList.add('hidden');
        document.getElementById('pkgKeepNote').classList.add('hidden');
        var sources = document.querySelectorAll('input[name="image_source"]');
        if (sources.length) {
            sources[0].checked = true;
        }
        pkgToggleSource();
    }
</script>

</body>
</html>
