<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── DELETE album ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    $album_id    = $_POST['album_id']    ?? 0;
    $uploader_id = $_POST['uploader_id'] ?? 0;

    // Verify ownership
    $stmt = $pdo->prepare("SELECT a.id, s.slug FROM albums a JOIN tourist_spots s ON a.spot_id = s.id WHERE a.id = ? AND a.uploader_id = ?");
    $stmt->execute([$album_id, $uploader_id]);
    $album = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$album) {
        echo json_encode(['success' => false, 'message' => 'Album not found or unauthorized.']);
        exit();
    }

    // Delete physical files
    $stmt = $pdo->prepare("SELECT file_path FROM media WHERE album_id = ?");
    $stmt->execute([$album_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as $f) {
        $full = __DIR__ . '/' . $f['file_path'];
        if (file_exists($full)) unlink($full);
    }

    // Delete media rows then album
    $pdo->prepare("DELETE FROM media WHERE album_id = ?")->execute([$album_id]);
    $pdo->prepare("DELETE FROM albums WHERE id = ?")->execute([$album_id]);

    echo json_encode(['success' => true]);
    exit();
}

// ── EDIT album (name + story only) ───────────────────────────────────────────
if ($method === 'POST' && $action === 'edit') {
    $album_id    = $_POST['album_id']    ?? 0;
    $uploader_id = $_POST['uploader_id'] ?? 0;
    $name        = trim($_POST['name']   ?? '');
    $story       = trim($_POST['story']  ?? '');

    if (!$name) {
        echo json_encode(['success' => false, 'message' => 'Name is required.']);
        exit();
    }

    $stmt = $pdo->prepare("UPDATE albums SET name = ?, story = ? WHERE id = ? AND uploader_id = ?");
    $stmt->execute([$name, $story, $album_id, $uploader_id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Nothing updated or unauthorized.']);
        exit();
    }
    echo json_encode(['success' => true]);
    exit();
}

// ── POST: Create album + upload files ────────────────────────────────────────
if ($method === 'POST') {
    $spot_slug   = $_POST['spot_slug']   ?? '';
    $uploader_id = $_POST['uploader_id'] ?? 0;
    $album_name  = trim($_POST['album_name']  ?? '');
    $album_story = trim($_POST['album_story'] ?? '');

    if (!$album_name) {
        echo json_encode(['success' => false, 'message' => 'Album name is required.']);
        exit();
    }

    $stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
    $stmt->execute([$spot_slug]);
    $spot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$spot) {
        echo json_encode(['success' => false, 'message' => 'Spot not found.']);
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO albums (spot_id, uploader_id, name, story) VALUES (?, ?, ?, ?)");
    $stmt->execute([$spot['id'], $uploader_id, $album_name, $album_story]);
    $album_id = $pdo->lastInsertId();

    $upload_dir = __DIR__ . "/uploads/" . str_replace('-', '_', $spot_slug) . "/";
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
        chmod($upload_dir, 0777);
    }

    $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'pdf'];
    $uploaded = 0;
    $errors   = [];
    $captions = $_POST['captions'] ?? [];

    if (!empty($_FILES['files']['name'][0])) {
        $files      = $_FILES['files'];
        $file_count = count($files['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "File {$files['name'][$i]} failed to upload.";
                continue;
            }
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $errors[] = "File type not allowed: {$files['name'][$i]}";
                continue;
            }
            $new_name = uniqid() . '.' . $ext;
            $dest     = $upload_dir . $new_name;
            $path     = "uploads/" . str_replace('-', '_', $spot_slug) . "/" . $new_name;
            $type     = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? 'image' : ($ext === 'mp4' ? 'video' : 'document');
            $caption  = $captions[$i] ?? '';

            if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
                $stmt = $pdo->prepare("INSERT INTO media (spot_id, uploader_id, album_id, file_name, file_path, file_type, caption) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$spot['id'], $uploader_id, $album_id, $new_name, $path, $type, $caption]);
                $uploaded++;
            } else {
                $errors[] = "Failed to save: {$files['name'][$i]}";
            }
        }
    }

    echo json_encode([
        'success'  => true,
        'album_id' => $album_id,
        'uploaded' => $uploaded,
        'errors'   => $errors,
        'message'  => "Album \"{$album_name}\" created with {$uploaded} file(s).",
    ]);
    exit();
}

// ── GET: Fetch albums ─────────────────────────────────────────────────────────
$spot_slug   = $_GET['slug']        ?? '';
$uploader_id = $_GET['uploader_id'] ?? 0;

$stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
$stmt->execute([$spot_slug]);
$spot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spot) { echo json_encode([]); exit(); }

if ($uploader_id) {
    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.story, a.created_at,
               COUNT(m.id) as file_count,
               u.username, u.first_name, u.last_name
        FROM albums a
        LEFT JOIN media m ON m.album_id = a.id
        JOIN users u ON a.uploader_id = u.id
        WHERE a.spot_id = ? AND a.uploader_id = ?
        GROUP BY a.id ORDER BY a.created_at DESC
    ");
    $stmt->execute([$spot['id'], $uploader_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.story, a.created_at,
               COUNT(m.id) as file_count,
               u.username, u.first_name, u.last_name
        FROM albums a
        LEFT JOIN media m ON m.album_id = a.id
        JOIN users u ON a.uploader_id = u.id
        WHERE a.spot_id = ?
        GROUP BY a.id ORDER BY a.created_at DESC
    ");
    $stmt->execute([$spot['id']]);
}
$albums = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($albums as &$album) {
    $stmt = $pdo->prepare("SELECT id, file_name, file_path, file_type, caption, uploaded_at FROM media WHERE album_id = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$album['id']]);
    $album['media'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $album['cover'] = null;
    foreach ($album['media'] as $f) {
        if ($f['file_type'] === 'image') { $album['cover'] = $f['file_path']; break; }
    }
}

echo json_encode($albums);
?>