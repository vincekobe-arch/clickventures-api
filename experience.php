<?php
require 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $spot_slug   = $_GET['slug']        ?? '';
    $uploader_id = $_GET['uploader_id'] ?? 0;

    $stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
    $stmt->execute([$spot_slug]);
    $spot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$spot) { echo json_encode(['content' => '']); exit(); }

    if ($uploader_id) {
        $stmt = $pdo->prepare("SELECT content, updated_at FROM experiences WHERE spot_id = ? AND uploader_id = ?");
        $stmt->execute([$spot['id'], $uploader_id]);
    } else {
        $stmt = $pdo->prepare("SELECT content, updated_at FROM experiences WHERE spot_id = ? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$spot['id']]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode($row ?: ['content' => '', 'updated_at' => null]);
    exit();
}

if ($method === 'POST') {
    $spot_slug   = $_POST['spot_slug']   ?? '';
    $uploader_id = $_POST['uploader_id'] ?? 0;
    $content     = trim($_POST['content'] ?? '');

    if (!$content) { echo json_encode(['success' => false, 'message' => 'Content is empty.']); exit(); }

    $stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
    $stmt->execute([$spot_slug]);
    $spot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$spot) { echo json_encode(['success' => false, 'message' => 'Spot not found.']); exit(); }

    // Upsert — insert or update if already exists
    $stmt = $pdo->prepare("
        INSERT INTO experiences (spot_id, uploader_id, content)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = current_timestamp()
    ");
    $stmt->execute([$spot['id'], $uploader_id, $content]);

    echo json_encode(['success' => true, 'message' => 'Experience saved.']);
    exit();
}