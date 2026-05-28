<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── DELETE member ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    $member_id   = $_POST['member_id']   ?? 0;
    $uploader_id = $_POST['uploader_id'] ?? 0;

    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? AND uploader_id = ?");
    $stmt->execute([$member_id, $uploader_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) { echo json_encode(['success' => false, 'message' => 'Not found or unauthorized.']); exit(); }

    if ($member['photo_path']) {
        $full = __DIR__ . '/' . $member['photo_path'];
        if (file_exists($full)) unlink($full);
    }

    $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$member_id]);
    echo json_encode(['success' => true]);
    exit();
}

// ── EDIT member ───────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'edit') {
    $member_id   = $_POST['member_id']   ?? 0;
    $uploader_id = $_POST['uploader_id'] ?? 0;
    $name        = trim($_POST['name']      ?? '');
    $position    = trim($_POST['position']  ?? '');
    $bio         = trim($_POST['bio']       ?? '');
    $gender      = trim($_POST['gender']    ?? '');
    $birthday    = trim($_POST['birthday']  ?? '') ?: null;

    if (!$name) { echo json_encode(['success' => false, 'message' => 'Name is required.']); exit(); }

    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? AND uploader_id = ?");
    $stmt->execute([$member_id, $uploader_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) { echo json_encode(['success' => false, 'message' => 'Not found or unauthorized.']); exit(); }

    $photo_path = $member['photo_path'];

    if (!empty($_FILES['photo']['tmp_name'])) {
        $spot_slug  = $_POST['spot_slug'] ?? 'general';
        $upload_dir = __DIR__ . "/uploads/members/";
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

        $ext      = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed  = ['jpg','jpeg','png','gif','webp'];
        if (in_array($ext, $allowed)) {
            if ($photo_path && file_exists(__DIR__ . '/' . $photo_path)) unlink(__DIR__ . '/' . $photo_path);
            $new_name   = uniqid('mbr_') . '.' . $ext;
            $photo_path = "uploads/members/" . $new_name;
            move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $new_name);
        }
    }

    $$pdo->prepare("UPDATE members SET name=?, position=?, bio=?, gender=?, birthday=?, photo_path=? WHERE id=?")
        ->execute([$name, $position, $bio, $gender, $birthday, $photo_path, $member_id]);

    echo json_encode(['success' => true, 'photo_path' => $photo_path]);
    exit();
}

// ── POST: Add member ──────────────────────────────────────────────────────────
if ($method === 'POST') {
    $spot_slug   = $_POST['spot_slug']   ?? '';
    $uploader_id = $_POST['uploader_id'] ?? 0;
    $name        = trim($_POST['name']      ?? '');
    $position    = trim($_POST['position']  ?? '');
    $bio         = trim($_POST['bio']       ?? '');
    $gender      = trim($_POST['gender']    ?? '');
    $birthday    = trim($_POST['birthday']  ?? '') ?: null;

    if (!$name) { echo json_encode(['success' => false, 'message' => 'Name is required.']); exit(); }

    $stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
    $stmt->execute([$spot_slug]);
    $spot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$spot) { echo json_encode(['success' => false, 'message' => 'Spot not found.']); exit(); }

    $upload_dir = __DIR__ . "/uploads/members/";
    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

    $photo_path = null;
    if (!empty($_FILES['photo']['tmp_name'])) {
        $ext     = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (in_array($ext, $allowed)) {
            $new_name   = uniqid('mbr_') . '.' . $ext;
            $photo_path = "uploads/members/" . $new_name;
            move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $new_name);
        }
    }

    $pdo->prepare("INSERT INTO members (spot_id, uploader_id, name, position, bio, gender, birthday, photo_path) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$spot['id'], $uploader_id, $name, $position, $bio, $gender, $birthday, $photo_path]);

    $member_id = $pdo->lastInsertId();
    echo json_encode(['success' => true, 'member_id' => $member_id, 'photo_path' => $photo_path]);
    exit();
}

// ── GET: Fetch members ────────────────────────────────────────────────────────
$spot_slug   = $_GET['slug']        ?? '';
$uploader_id = $_GET['uploader_id'] ?? 0;

$stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
$stmt->execute([$spot_slug]);
$spot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$spot) { echo json_encode([]); exit(); }

if ($uploader_id) {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE spot_id = ? AND uploader_id = ? ORDER BY sort_order ASC, created_at ASC");
    $stmt->execute([$spot['id'], $uploader_id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE spot_id = ? ORDER BY sort_order ASC, created_at ASC");
    $stmt->execute([$spot['id']]);
}

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>