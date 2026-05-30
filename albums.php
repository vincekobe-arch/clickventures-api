<?php
set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require 'config.php';
require 'cloudinary.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── DELETE album ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    $album_id    = $_POST['album_id']    ?? 0;
    $uploader_id = $_POST['uploader_id'] ?? 0;

    $stmt = $pdo->prepare("SELECT a.id FROM albums a WHERE a.id = ? AND a.uploader_id = ?");
    $stmt->execute([$album_id, $uploader_id]);
    if (!$stmt->fetch()) {
        
        echo json_encode(['success' => false, 'message' => 'Album not found or unauthorized.']);
        exit();
    }

    // Delete from Cloudinary then DB
    $stmt = $pdo->prepare("SELECT file_path FROM media WHERE album_id = ?");
    $stmt->execute([$album_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as $f) {
        cloudinary_delete($f['file_path']); // file_path now stores public_id
    }

    $pdo->prepare("DELETE FROM media WHERE album_id = ?")->execute([$album_id]);
    $pdo->prepare("DELETE FROM albums WHERE id = ?")->execute([$album_id]);

    echo json_encode(['success' => true]);
    exit();
}

// ── EDIT album ────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'edit') {
    $album_id    = $_POST['album_id']    ?? 0;
    $uploader_id = $_POST['uploader_id'] ?? 0;
    $name        = trim($_POST['name']   ?? '');
    $story       = trim($_POST['story']  ?? '');

    if (!$name) { echo json_encode(['success' => false, 'message' => 'Name is required.']); exit(); }

    $stmt = $pdo->prepare("UPDATE albums SET name = ?, story = ? WHERE id = ? AND uploader_id = ?");
    $stmt->execute([$name, $story, $album_id, $uploader_id]);
    echo json_encode(['success' => true]);
    exit();
}

// ── POST: Create album + upload files ─────────────────────────────────────────
if ($method === 'POST') {
    $spot_slug   = $_POST['spot_slug']   ?? '';
    $uploader_id = $_POST['uploader_id'] ?? 0;
    $album_name  = trim($_POST['album_name']  ?? '');
    $album_story = trim($_POST['album_story'] ?? '');

    if (!$album_name) { echo json_encode(['success' => false, 'message' => 'Album name is required.']); exit(); }

    $stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
    $stmt->execute([$spot_slug]);
    $spot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$spot) { echo json_encode(['success' => false, 'message' => 'Spot not found.']); exit(); }

    $stmt = $pdo->prepare("INSERT INTO albums (spot_id, uploader_id, name, story) VALUES (?, ?, ?, ?)");
    $stmt->execute([$spot['id'], $uploader_id, $album_name, $album_story]);
    $album_id = $pdo->lastInsertId();

    $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'pdf', 'heic', 'heif'];

$mime_to_ext = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',
    'image/heif'      => 'heif',
    'video/mp4'       => 'mp4',
    'application/pdf' => 'pdf',
];
    $uploaded = 0;
    $errors   = [];
    $captions = $_POST['captions'] ?? [];

    if (!empty($_FILES['files']['name'][0])) {
        $files      = $_FILES['files'];
        $file_count = count($files['name']);

        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "File {$files['name'][$i]} failed to upload."; continue;
            }
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));

        // iOS fallback: detect ext from mime type if missing or unrecognized
        if (!$ext || !in_array($ext, $allowed)) {
            $mime = mime_content_type($files['tmp_name'][$i]);
            $ext  = $mime_to_ext[$mime] ?? $ext;
        }

        if (!in_array($ext, $allowed)) {
            $errors[] = "File type not allowed: {$files['name'][$i]} (ext: {$ext})";
            continue;
        }

        $type           = in_array($ext, ['jpg','jpeg','png','gif','webp','heic','heif']) ? 'image' : ($ext === 'mp4' ? 'video' : 'document');
        $resource_type  = ($ext === 'mp4') ? 'video' : 'image';
        $folder         = "clickventures/" . str_replace('-', '_', $spot_slug);
        $result         = cloudinary_upload($files['tmp_name'][$i], $folder, $resource_type);

            if (isset($result['secure_url'])) {
                $caption = $captions[$i] ?? '';
                $stmt = $pdo->prepare("INSERT INTO media (spot_id, uploader_id, album_id, file_name, file_path, file_type, caption) VALUES (?, ?, ?, ?, ?, ?, ?)");
                // file_name = original name, file_path = Cloudinary URL
                $stmt->execute([$spot['id'], $uploader_id, $album_id, $files['name'][$i], $result['secure_url'], $type, $caption]);
                $uploaded++;
            } else {
                $cloudinary_error = $result['error']['message'] ?? $result['error'] ?? json_encode($result);
                $errors[] = "Cloudinary failed for {$files['name'][$i]}: {$cloudinary_error}";
            }
        }
    }

    echo json_encode([
        'success'  => $uploaded > 0,
        'album_id' => $album_id,
        'uploaded' => $uploaded,
        'errors'   => $errors,
        'message'  => $uploaded > 0 
            ? "Album \"{$album_name}\" created with {$uploaded} file(s)."
            : "Album created but no files uploaded. Errors: " . implode(', ', $errors),
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

$query = $uploader_id
    ? "SELECT a.id, a.name, a.story, a.created_at, COUNT(m.id) as file_count, u.username, u.first_name, u.last_name FROM albums a LEFT JOIN media m ON m.album_id = a.id JOIN users u ON a.uploader_id = u.id WHERE a.spot_id = ? AND a.uploader_id = ? GROUP BY a.id ORDER BY a.created_at DESC"
    : "SELECT a.id, a.name, a.story, a.created_at, COUNT(m.id) as file_count, u.username, u.first_name, u.last_name FROM albums a LEFT JOIN media m ON m.album_id = a.id JOIN users u ON a.uploader_id = u.id WHERE a.spot_id = ? GROUP BY a.id ORDER BY a.created_at DESC";

$stmt = $pdo->prepare($query);
$uploader_id ? $stmt->execute([$spot['id'], $uploader_id]) : $stmt->execute([$spot['id']]);
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