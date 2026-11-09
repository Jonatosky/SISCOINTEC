<?php
error_reporting(0);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

include_once 'conexion.php';

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(["success" => false, "error" => "Error de conexión BD"]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'GET') {
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

if ($method == 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $accion = $data['accion'] ?? '';

    if ($accion == 'crear') {
        $codigo = $data['codigo'] ?? '';
        $serie = $data['serie'] ?? '';
        $marca = $data['marca'] ?? '';
        $rut = $data['rut'] ?? '';
        $tipo = $data['tipo'] ?? '';
        $motivo = $data['motivo'] ?? '';
        $usuario = $data['usuario'] ?? 'Sistema';

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
        } else {
            echo json_encode(["success" => false, "error" => $conn->error]);
        }
        exit;
    } elseif ($accion == 'actualizar_estado') {
        $codigo = $data['codigo'] ?? '';
        $nuevo_estado = $data['nuevo_estado'] ?? '';
        $cert = $data['cert'] ?? 'N/A';
        $usuario = $data['usuario'] ?? 'Sistema';
        $detalle = $data['detalle'] ?? '';

        $res = $conn->query("SELECT id FROM activos WHERE codigo = '$codigo'");
        if ($res && $row = $res->fetch_assoc()) {
            $activo_id = $row['id'];
            
            $stmt = $conn->prepare("UPDATE activos SET estado = ?, certificado = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssi", $nuevo_estado, $cert, $activo_id);
                if ($stmt->execute()) {
                    // Inserción segura en bitácora sin depender de columnas opcionales que puedan fallar
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
            } else {
                echo json_encode(["success" => false, "error" => $conn->error]);
            }
        } else {
            echo json_encode(["success" => false, "error" => "Activo no encontrado en la base de datos"]);
        }
        exit;
    }
}
?>