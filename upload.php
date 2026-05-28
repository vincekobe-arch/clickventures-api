<?php
require 'config.php';

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

// Prepare upload directory
$upload_dir = __DIR__ . "/uploads/" . str_replace('-', '_', $spot_slug) . "/";
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$allowed    = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'pdf'];
$uploaded   = 0;
$errors     = [];

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

        if (move_uploaded_file($files['tmp_name'][$i], $dest)) {
            $stmt = $pdo->prepare("
                INSERT INTO media (spot_id, uploader_id, album_id, file_name, file_path, file_type, caption)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$spot['id'], $uploader_id, $album_id, $new_name, $path, $type, '']);
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
?>