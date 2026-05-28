<?php
header("Access-Control-Allow-Origin: https://clickventures-bstm.vercel.app");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $media_id = $_GET['media_id'] ?? 0;
    if (!$media_id) { echo json_encode([]); exit(); }

    // Fetch comments
    $stmt = $pdo->prepare("
        SELECT c.id, c.user_id, c.content, c.created_at,
               u.username, u.first_name, u.last_name
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.media_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$media_id]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all replies for those comments
    if (!empty($comments)) {
        $comment_ids = array_column($comments, 'id');
        $placeholders = implode(',', array_fill(0, count($comment_ids), '?'));
        $stmt2 = $pdo->prepare("
            SELECT r.id, r.comment_id, r.user_id, r.content, r.created_at,
                   r.reply_to_id, r.reply_to_name,
                   u.username, u.first_name, u.last_name
            FROM replies r
            JOIN users u ON r.user_id = u.id
            WHERE r.comment_id IN ($placeholders)
            ORDER BY r.created_at ASC
        ");
        $stmt2->execute($comment_ids);
        $replies = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Group replies by comment_id
        $repliesMap = [];
        foreach ($replies as $r) {
            $repliesMap[$r['comment_id']][] = $r;
        }

        // Attach replies to each comment
        foreach ($comments as &$c) {
            $c['replies'] = $repliesMap[$c['id']] ?? [];
        }
        unset($c);
    }

    echo json_encode($comments);
    exit();
}

if ($method === 'POST') {
    $action     = $_GET['action']      ?? 'create';
    $user_id    = $_POST['user_id']    ?? 0;
    $comment_id = $_POST['comment_id'] ?? 0;
    $reply_id   = $_POST['reply_id']   ?? 0;

    // ── EDIT COMMENT ──
    if ($action === 'edit') {
        $content = trim($_POST['content'] ?? '');
        if (!$comment_id || !$user_id || !$content) {
            echo json_encode(['success' => false, 'message' => 'Missing fields.']); exit();
        }
        $stmt = $pdo->prepare("UPDATE comments SET content = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$content, $comment_id, $user_id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit();
    }

    // ── DELETE COMMENT ──
    if ($action === 'delete') {
        if (!$comment_id || !$user_id) {
            echo json_encode(['success' => false, 'message' => 'Missing fields.']); exit();
        }
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ? AND user_id = ?");
        $stmt->execute([$comment_id, $user_id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit();
    }

    // ── CREATE REPLY ──
    if ($action === 'reply') {
        $content      = trim($_POST['content']      ?? '');
        $reply_to_id  = $_POST['reply_to_id']       ?? null;
        $reply_to_name = trim($_POST['reply_to_name'] ?? '');
        if (!$comment_id || !$user_id || !$content) {
            echo json_encode(['success' => false, 'message' => 'Missing fields.']); exit();
        }
        $stmt = $pdo->prepare("INSERT INTO replies (comment_id, user_id, content, reply_to_id, reply_to_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$comment_id, $user_id, $content, $reply_to_id ?: null, $reply_to_name ?: null]);
        $new_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT r.id, r.comment_id, r.user_id, r.content, r.created_at,
                   r.reply_to_id, r.reply_to_name,
                   u.username, u.first_name, u.last_name
            FROM replies r
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$new_id]);
        $reply = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'reply' => $reply]);
        exit();
    }

    // ── EDIT REPLY ──
    if ($action === 'edit_reply') {
        $content = trim($_POST['content'] ?? '');
        if (!$reply_id || !$user_id || !$content) {
            echo json_encode(['success' => false, 'message' => 'Missing fields.']); exit();
        }
        $stmt = $pdo->prepare("UPDATE replies SET content = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$content, $reply_id, $user_id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit();
    }

    // ── DELETE REPLY ──
    if ($action === 'delete_reply') {
        if (!$reply_id || !$user_id) {
            echo json_encode(['success' => false, 'message' => 'Missing fields.']); exit();
        }
        $stmt = $pdo->prepare("DELETE FROM replies WHERE id = ? AND user_id = ?");
        $stmt->execute([$reply_id, $user_id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit();
    }

    // ── CREATE COMMENT ──
    $media_id = $_POST['media_id'] ?? 0;
    $content  = trim($_POST['content'] ?? '');

    if (!$media_id || !$user_id || !$content) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields.']); exit();
    }

    $stmt = $pdo->prepare("SELECT id FROM media WHERE id = ?");
    $stmt->execute([$media_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Post not found.']); exit();
    }

    $stmt = $pdo->prepare("INSERT INTO comments (media_id, user_id, content) VALUES (?, ?, ?)");
    $stmt->execute([$media_id, $user_id, $content]);
    $new_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        SELECT c.id, c.user_id, c.content, c.created_at,
               u.username, u.first_name, u.last_name
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$new_id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    $comment['replies'] = [];

    echo json_encode(['success' => true, 'comment' => $comment]);
    exit();
}
?>