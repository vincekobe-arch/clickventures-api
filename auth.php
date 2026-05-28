<?php

require 'config.php';
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if ($action === 'login') {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$data['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($data['password'], $user['password'])) {
        $token = base64_encode($user['id'] . ':' . $user['role'] . ':' . $user['assigned_spot'] . ':' . time());
        echo json_encode(['success' => true, 'token' => $token, 'id' => $user['id'], 'role' => $user['role'], 'assigned_spot' => $user['assigned_spot'], 'username' => $user['username'], 'first_name' => $user['first_name'], 'last_name' => $user['last_name']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
}

if ($action === 'register') {
    $hash = password_hash($data['password'], PASSWORD_BCRYPT);
    $firstName  = $data['firstName']  ?? null;
    $middleName = $data['middleName'] ?? null;
    $lastName   = $data['lastName']   ?? null;
    $gender     = $data['gender']     ?? null;
    $birthday   = $data['birthday']   ?? null;
    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role, first_name, middle_name, last_name, gender, birthday) VALUES (?, ?, 'user', ?, ?, ?, ?, ?)");
        $stmt->execute([$data['username'], $hash, $firstName, $middleName, $lastName, $gender, $birthday]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Username already taken']);
    }
}
if ($action === 'microsoft_login') {
    $microsoft_id = $data['microsoft_id'] ?? null;
    $email        = $data['email']        ?? null;
    $name         = $data['name']         ?? null; // display name from MS

    if (!$microsoft_id) {
        echo json_encode(['success' => false, 'message' => 'Missing Microsoft ID']);
        exit;
    }

    // Check if user already exists with this microsoft_id
    $stmt = $pdo->prepare("SELECT * FROM users WHERE microsoft_id = ?");
    $stmt->execute([$microsoft_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Existing MS user — log them in
        echo json_encode([
            'success'       => true,
            'id'            => $user['id'],
            'role'          => $user['role'],
            'assigned_spot' => $user['assigned_spot'],
            'username'      => $user['username'],
            'first_name'    => $user['first_name'],
            'last_name'     => $user['last_name'],
        ]);
    } else {
        // New MS user — needs to complete profile
        echo json_encode([
            'success'        => false,
            'needs_profile'  => true,
            'microsoft_id'   => $microsoft_id,
            'email'          => $email,
            'name'           => $name,
        ]);
    }
}

if ($action === 'microsoft_complete') {
    $microsoft_id = $data['microsoft_id'] ?? null;
    $firstName    = $data['firstName']    ?? null;
    $middleName   = $data['middleName']   ?? null;
    $lastName     = $data['lastName']     ?? null;
    $gender       = $data['gender']       ?? null;
    $birthday     = $data['birthday']     ?? null;
    $username     = $data['username']     ?? null;

    if (!$microsoft_id || !$username) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, microsoft_id, password, role, first_name, middle_name, last_name, gender, birthday) VALUES (?, ?, '', 'user', ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $microsoft_id, $firstName, $middleName, $lastName, $gender, $birthday]);
        $newId = $pdo->lastInsertId();
        echo json_encode([
            'success'       => true,
            'id'            => $newId,
            'role'          => 'user',
            'assigned_spot' => null,
            'username'      => $username,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Username already taken']);
    }
}
?>