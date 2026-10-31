<?php
include 'conexion.php';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Obtener activos y bitácora
    $stmtActivos = $pdo->query("SELECT * FROM activos ORDER BY id DESC");
    $activos = $stmtActivos->fetchAll(PDO::FETCH_ASSOC);

    $stmtBitacora = $pdo->query("SELECT * FROM bitacora ORDER BY id DESC");
    $bitacora = $stmtBitacora->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["activos" => $activos, "bitacora" => $bitacora]);
} 
else if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (isset($data['accion']) && $data['accion'] === 'crear') {
        $stmt = $pdo->prepare("INSERT INTO activos (codigo, tipo, marca, serie, rut_responsable, motivo, estado) VALUES (?, ?, ?, ?, ?, ?, 'Registrado')");
        $stmt->execute([$data['codigo'], $data['tipo'], $data['marca'], $data['serie'], $data['rut'], $data['motivo']]);
        
        // Registrar en bitácora
        $stmtB = $pdo->prepare("INSERT INTO bitacora (fecha, codigo_activo, estado_anterior, nuevo_estado, usuario, detalle) VALUES (NOW(), ?, 'N/A', 'Registrado', ?, 'Ingreso de equipo en estado de baja')");
        $stmtB->execute([$data['codigo'], $data['usuario']]);

        echo json_encode(["success" => true]);
    }
    else if (isset($data['accion']) && $data['accion'] === 'actualizar_estado') {
        $stmt = $pdo->prepare("UPDATE activos SET estado = ?, certificado = ? WHERE codigo = ?");
        $stmt->execute([$data['nuevo_estado'], $data['cert'], $data['codigo']]);

        // Registrar en bitácora
        $stmtB = $pdo->prepare("INSERT INTO bitacora (fecha, codigo_activo, estado_anterior, nuevo_estado, usuario, detalle) VALUES (NOW(), ?, ?, ?, ?, ?)");
        $stmtB->execute([$data['codigo'], $data['estado_anterior'], $data['nuevo_estado'], $data['usuario'], $data['detalle']]);

        echo json_encode(["success" => true]);
    }
}
?>