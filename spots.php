<?php
require 'config.php';
$stmt = $pdo->query("SELECT * FROM tourist_spots");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>