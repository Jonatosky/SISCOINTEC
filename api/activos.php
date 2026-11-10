<?php
session_start();
error_reporting(0);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");

include_once 'conexion.php';

if (!isset($conn) || $conn->connect_error) {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["success" => false, "error" => "Error de conexión BD"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// LOGIN Y SESIÓN
if (isset($_GET['accion']) && $_GET['accion'] == 'login' && $method == 'POST') {
    header("Content-Type: application/json; charset=UTF-8");
    $data = json_decode(file_get_contents("php://input"), true);
    $user = $data['usuario'] ?? '';
    $pass = $data['password'] ?? '';

    $users = [
      'bodega' => ['pass' => '1234', 'name' => 'Juan Pérez', 'role' => 'Encargado de Bodega', 'code' => 'bodega'],
      'tecnico' => ['pass' => '1234', 'name' => 'Carlos Soto', 'role' => 'Técnico de Soporte TI', 'code' => 'tecnico'],
      'jefatura' => ['pass' => '1234', 'name' => 'María González', 'role' => 'Auditora / Gerencia', 'code' => 'jefatura']
    ];

    if (isset($users[$user]) && $users[$user]['pass'] === $pass) {
        $_SESSION['usuario'] = $users[$user];
        echo json_encode(["success" => true, "user" => $_SESSION['usuario']]);
    } else {
        echo json_encode(["success" => false, "error" => "Credenciales incorrectas"]);
    }
    exit;
}

// CONSULTA DE SESIÓN ACTIVA
if (isset($_GET['accion']) && $_GET['accion'] == 'check_session' && $method == 'GET') {
    header("Content-Type: application/json; charset=UTF-8");
    if (isset($_SESSION['usuario'])) {
        echo json_encode(["logged" => true, "user" => $_SESSION['usuario']]);
    } else {
        echo json_encode(["logged" => false]);
    }
    exit;
}

// CERRAR SESIÓN
if (isset($_GET['accion']) && $_GET['accion'] == 'logout') {
    session_destroy();
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["success" => true]);
    exit;
}

// PETICIONES GET (ACTIVOS Y BITÁCORA)
if ($method == 'GET') {
    header("Content-Type: application/json; charset=UTF-8");
    if (isset($_GET['accion']) && $_GET['accion'] == 'historial') {
        $query = "SELECT b.*, a.codigo FROM bitacora b JOIN activos a ON b.activo_id = a.id ORDER BY b.id DESC";
        $resultado = $conn->query($query);
        $historial = [];
        if ($resultado && $resultado->num_rows > 0) {
            while ($row = $resultado->fetch_assoc()) {
                $historial[] = [
                    "fecha" => $row['fecha'] ?? date('Y-m-d H:i:s'),
                    "codigo_activo" => $row['codigo'],
                    "estado_anterior" => "Registrado",
                    "nuevo_estado" => "Actualizado",
                    "usuario" => $row['responsable'] ?? 'Sistema',
                    "detalle" => $row['descripcion'] ?? ''
                ];
            }
        }
        echo json_encode($historial);
        exit;
    } else {
        $resultado = $conn->query("SELECT * FROM activos ORDER BY id DESC");
        $activos = [];
        if ($resultado && $resultado->num_rows > 0) {
            while ($row = $resultado->fetch_assoc()) {
                $activos[] = $row;
            }
        }
        echo json_encode($activos);
        exit;
    }
}

// PETICIONES POST (CREAR / ACTUALIZAR CON SUBIDA DE ARCHIVOS)
if ($method == 'POST') {
    header("Content-Type: application/json; charset=UTF-8");
    
    // Soporta FormData o JSON crudo
    $accion = $_POST['accion'] ?? '';
    if (!$accion) {
        $input = json_decode(file_get_contents("php://input"), true);
        $accion = $input['accion'] ?? '';
    }

    if ($accion == 'crear') {
        $data = count($_POST) ? $_POST : json_decode(file_get_contents("php://input"), true);
        $codigo = $data['codigo'] ?? '';
        $serie = $data['serie'] ?? '';
        $marca = $data['marca'] ?? '';
        $rut = $data['rut'] ?? '';
        $tipo = $data['tipo'] ?? '';
        $motivo = $data['motivo'] ?? '';
        $usuario = $_SESSION['usuario']['name'] ?? ($data['usuario'] ?? 'Sistema');

        $stmt = $conn->prepare("INSERT INTO activos (codigo, tipo, marca, serie, rut_responsable, motivo, estado, certificado) VALUES (?, ?, ?, ?, ?, ?, 'Registrado', 'N/A')");
        if ($stmt) {
            $stmt->bind_param("ssssss", $codigo, $tipo, $marca, $serie, $rut, $motivo);
            if ($stmt->execute()) {
                $activo_id = $conn->insert_id;
                $stmtBit = $conn->prepare("INSERT INTO bitacora (activo_id, descripcion, responsable) VALUES (?, ?, ?)");
                if ($stmtBit) {
                    $desc = "Registro inicial de baja por: $motivo";
                    $stmtBit->bind_param("iss", $activo_id, $desc, $usuario);
                    $stmtBit->execute();
                }
                echo json_encode(["success" => true]);
            } else {
                echo json_encode(["success" => false, "error" => $stmt->error]);
            }
        }
        exit;
    } elseif ($accion == 'actualizar_estado') {
        $codigo = $_POST['codigo'] ?? '';
        $nuevo_estado = $_POST['nuevo_estado'] ?? '';
        $detalle = $_POST['detalle'] ?? '';
        $usuario = $_SESSION['usuario']['name'] ?? 'Sistema';
        $certNombre = 'N/A';

        // Manejo de la subida física del PDF (RF-05)
        if (isset($_FILES['certificado_file']) && $_FILES['certificado_file']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['certificado_file']['name'], PATHINFO_EXTENSION);
            if (strtolower($ext) === 'pdf') {
                $nombreGuardado = "CERT_" . time() . "_" . preg_replace("/[^a-zA-Z0-9]/", "", $codigo) . ".pdf";
                $destino = "../uploads/" . $nombreGuardado;
                if (move_uploaded_file($_FILES['certificado_file']['tmp_name'], $destino)) {
                    $certNombre = $nombreGuardado;
                }
            }
        }

        $res = $conn->query("SELECT id FROM activos WHERE codigo = '$codigo'");
        if ($res && $row = $res->fetch_assoc()) {
            $activo_id = $row['id'];
            
            $stmt = $conn->prepare("UPDATE activos SET estado = ?, certificado = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssi", $nuevo_estado, $certNombre, $activo_id);
                if ($stmt->execute()) {
                    $stmtBit = $conn->prepare("INSERT INTO bitacora (activo_id, descripcion, responsable) VALUES (?, ?, ?)");
                    if ($stmtBit) {
                        $desc = "Transición a estado: $nuevo_estado. Ubicación: $detalle";
                        $stmtBit->bind_param("iss", $activo_id, $desc, $usuario);
                        $stmtBit->execute();
                    }
                    echo json_encode(["success" => true]);
                } else {
                    echo json_encode(["success" => false, "error" => $stmt->error]);
                }
            }
        } else {
            echo json_encode(["success" => false, "error" => "Activo no encontrado"]);
        }
        exit;
    }
}
?>