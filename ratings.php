<?php
header("Access-Control-Allow-Origin: https://clickventures-bstm.vercel.app");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: fetch ratings for a spot ─────────────────────────────────────────────
if ($method === 'GET') {
    $slug    = $_GET['slug']    ?? '';
    $user_id = $_GET['user_id'] ?? 0;

    if (!$slug) { echo json_encode(['ratings' => [], 'summary' => null, 'my_rating' => null]); exit(); }

    $stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
    $stmt->execute([$slug]);
    $spot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$spot) { echo json_encode(['ratings' => [], 'summary' => null, 'my_rating' => null]); exit(); }

    // Summary
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total, AVG(stars) as average,
               SUM(stars = 1) as s1, SUM(stars = 2) as s2,
               SUM(stars = 3) as s3, SUM(stars = 4) as s4, SUM(stars = 5) as s5
        FROM ratings WHERE spot_id = ?
    ");
    $stmt->execute([$spot['id']]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // All ratings with user info
    $stmt = $pdo->prepare("
        SELECT r.id, r.user_id, r.stars, r.feedback, r.created_at, r.updated_at,
               u.username, u.first_name, u.last_name
        FROM ratings r
        JOIN users u ON r.user_id = u.id
        WHERE r.spot_id = ?
        ORDER BY r.updated_at DESC
    ");
    $stmt->execute([$spot['id']]);
    $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current user's rating
    $myRating = null;
    if ($user_id) {
        foreach ($ratings as $r) {
            if ((int)$r['user_id'] === (int)$user_id) { $myRating = $r; break; }
        }
    }

    echo json_encode([
        'ratings'   => $ratings,
        'summary'   => $summary,
        'my_rating' => $myRating,
    ]);
    exit();
}

// ── POST: create or update rating ─────────────────────────────────────────────
if ($method === 'POST') {
    $action  = $_GET['action'] ?? 'upsert';
    $user_id = $_POST['user_id'] ?? 0;
    $slug    = $_POST['slug']    ?? '';
    $stars   = (int)($_POST['stars']    ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');

    if (!$user_id || !$slug) {
        echo json_encode(['success' => false, 'message' => 'Missing fields.']); exit();
    }

    $stmt = $pdo->prepare("SELECT id FROM tourist_spots WHERE slug = ?");
    $stmt->execute([$slug]);
    $spot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$spot) { echo json_encode(['success' => false, 'message' => 'Spot not found.']); exit(); }

    // ── DELETE rating ──
    if ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM ratings WHERE user_id = ? AND spot_id = ?");
        $stmt->execute([$user_id, $spot['id']]);
        echo json_encode(['success' => true]);
        exit();
    }

    // ── UPSERT rating ──
    if ($stars < 1 || $stars > 5) {
        echo json_encode(['success' => false, 'message' => 'Invalid star rating.']); exit();
    }

    $stmt = $pdo->prepare("
        INSERT INTO ratings (spot_id, user_id, stars, feedback)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE stars = VALUES(stars), feedback = VALUES(feedback), updated_at = NOW()
    ");
    $stmt->execute([$spot['id'], $user_id, $stars, $feedback ?: null]);

    // Return the updated rating
    $stmt = $pdo->prepare("
        SELECT r.id, r.user_id, r.stars, r.feedback, r.created_at, r.updated_at,
               u.username, u.first_name, u.last_name
        FROM ratings r JOIN users u ON r.user_id = u.id
        WHERE r.spot_id = ? AND r.user_id = ?
    ");
    $stmt->execute([$spot['id'], $user_id]);
    $rating = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'rating' => $rating]);
    exit();
}
?>