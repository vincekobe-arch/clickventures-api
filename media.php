<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require 'config.php';
require 'cloudinary.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── DELETE single media ───────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    $media_id    = $_POST['media_id']    ?? 0;
    $uploader_id = $_POST['uploader_id'] ?? 0;

    $stmt = $pdo->prepare("SELECT * FROM media WHERE id = ? AND uploader_id = ?");
    $stmt->execute([$media_id, $uploader_id]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) { echo json_encode(['success' => false, 'message' => 'Not found or unauthorized.']); exit(); }

    // Delete from Cloudinary using public_id extracted from URL
    $url_parts = explode('/', $media['file_path']);
    $filename  = pathinfo(end($url_parts), PATHINFO_FILENAME);
    $folder    = implode('/', array_slice($url_parts, -3, 2)); // gets folder/subfolder
    cloudinary_delete($folder . '/' . $filename);

    $pdo->prepare("DELETE FROM comments WHERE media_id = ?")->execute([$media_id]);
    $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$media_id]);

    echo json_encode(['success' => true]);
    exit();
}

// ── EDIT single media caption ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'edit') {
    $media_id    = $_POST['media_id']    ?? 0;
    $uploader_id = $_POST['uploader_id'] ?? 0;
    $caption     = trim($_POST['caption'] ?? '');

    $stmt = $pdo->prepare("UPDATE media SET caption = ? WHERE id = ? AND uploader_id = ?");
    $stmt->execute([$caption, $media_id, $uploader_id]);
    echo json_encode(['success' => true]);
    exit();
}

// ── GET: Fetch media for a spot ───────────────────────────────────────────────
$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare("
    SELECT m.*, u.username, u.first_name, u.last_name,
           (SELECT COUNT(*) FROM comments c WHERE c.media_id = m.id) AS comment_count
    FROM media m
    JOIN users u ON m.uploader_id = u.id
    JOIN tourist_spots s ON m.spot_id = s.id
    WHERE s.slug = ? AND m.album_id IS NULL
    ORDER BY m.uploaded_at DESC
");
$stmt->execute([$slug]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>