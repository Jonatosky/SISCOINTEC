<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

$host = "localhost";
$db = "siscointec_db";
$user = "root";
$pass = "";

// Conexión mediante MySQLi (compatible con activos.php)
$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    echo json_encode(["success" => false, "error" => "Error de conexión: " . $conn->connect_error]);
    exit();
}

$conn->set_charset("utf8");
?>