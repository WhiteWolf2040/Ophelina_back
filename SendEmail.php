<?php
// Esto debe ser lo PRIMERO, ni un espacio antes de <?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Content-Type: application/json");

// Responder a preflight OPTIONS inmediatamente
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
require __DIR__ . '/../vendor/autoload.php';


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$data = json_decode(file_get_contents('php://input'), true);

$nombre = htmlspecialchars($data['nombre'] ?? '');
$negocio = htmlspecialchars($data['negocio'] ?? '');
$telefono = htmlspecialchars($data['telefono'] ?? '');
$mensaje = htmlspecialchars($data['mensaje'] ?? '');

if (empty($nombre) || empty($negocio) || empty($telefono)) {
    http_response_code(400);
    echo json_encode(['error' => 'Campos requeridos faltantes']);
    exit;
}

$mail = new PHPMailer(true);

try {
    // Configuración SMTP de Gmail
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'gamboasuemi2002@gmail.com'; // Tu Gmail
    $mail->Password = 'vgjr xfny sgzu msvh'; // Contraseña de aplicación
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    
    // Destinatarios
    $mail->setFrom('gamboasuemi2002@gmail.com', 'Ophelia Landing');
    $mail->addAddress('gamboasuemi2002@gmail.com'); // A donde llega el mensaje
    $mail->addReplyTo('gamboasuemi2002@gmail.com', $nombre);
    
    // Contenido
    $mail->isHTML(true);
    $mail->Subject = "Nuevo contacto desde Ophelia - $negocio";
    $mail->Body = "
        <h3>Nuevo mensaje de contacto</h3>
        <p><strong>Nombre:</strong> $nombre</p>
        <p><strong>Negocio:</strong> $negocio</p>
        <p><strong>Teléfono:</strong> $telefono</p>
        <p><strong>Mensaje:</strong> " . nl2br($mensaje) . "</p>
    ";
    
    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Correo enviado']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => "Error: {$mail->ErrorInfo}"]);
}