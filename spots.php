<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require '../config.php';
$stmt = $pdo->query("SELECT * FROM tourist_spots");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>