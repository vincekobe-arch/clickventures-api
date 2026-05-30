<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require 'config.php';
require 'cloudinary.php';

$spot_slug   = $_POST['spot_slug']   ?? '';
$uploader_id = $_POST['uploader_id'] ?? 0;
$album_name  = trim($_POST['album_name']  ?? '');
$album_story = trim($_POST['album_story'] ?? '');

if (!$album_name) {
    echo json_encode(['success' => false, 'message' => 'Album name is required.']);
    exit();
}

// Verify spot
$stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
$stmt->execute([$spot_slug]);
$spot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$spot) {
    echo json_encode(['success' => false, 'message' => 'Spot not found.']);
    exit();
}

// Create album record
$stmt = $pdo->prepare("INSERT INTO albums (spot_id, uploader_id, name, story) VALUES (?, ?, ?, ?)");
$stmt->execute([$spot['id'], $uploader_id, $album_name, $album_story]);
$album_id = $pdo->lastInsertId();

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'pdf', 'heic', 'heif'];
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
            $errors[] = "File {$files['name'][$i]} failed to upload.";
            continue;
        }

        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));

        // iOS fallback: detect ext from mime type if missing or unrecognized
        if (!$ext || !in_array($ext, $allowed)) {
            $mime = mime_content_type($files['tmp_name'][$i]);
            $ext  = $mime_to_ext[$mime] ?? $ext;
        }

        if (!in_array($ext, $allowed)) {
            $errors[] = "File type not allowed: {$files['name'][$i]}";
            continue;
        }

        $type          = in_array($ext, ['jpg','jpeg','png','gif','webp','heic','heif']) ? 'image' : ($ext === 'mp4' ? 'video' : 'document');
        $resource_type = ($ext === 'mp4') ? 'video' : 'image';
        $folder        = "clickventures/" . str_replace('-', '_', $spot_slug);
        $result        = cloudinary_upload($files['tmp_name'][$i], $folder, $resource_type);

        if (isset($result['secure_url'])) {
            $caption = $captions[$i] ?? '';
            $stmt = $pdo->prepare("
                INSERT INTO media (spot_id, uploader_id, album_id, file_name, file_path, file_type, caption)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$spot['id'], $uploader_id, $album_id, $files['name'][$i], $result['secure_url'], $type, $caption]);
            $uploaded++;
        } else {
            $errors[] = "Cloudinary upload failed: {$files['name'][$i]}";
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
?>