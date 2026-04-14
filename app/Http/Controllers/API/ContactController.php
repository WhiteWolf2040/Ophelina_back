<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class ContactController extends Controller
{
    public function SendEmail(Request $request)
    {
        // Validar datos
        $request->validate([
            'nombre' => 'required|string|max:255',
            'negocio' => 'required|string|max:255',
            'telefono' => 'required|string|min:10',
            'mensaje' => 'nullable|string'
        ]);

        $nombre = htmlspecialchars($request->nombre);
        $negocio = htmlspecialchars($request->negocio);
        $telefono = htmlspecialchars($request->telefono);
        $mensaje = htmlspecialchars($request->mensaje ?? '');

        $mail = new PHPMailer(true);

        try {
            // Configuración SMTP de Gmail
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'gamboasuemi2002@gmail.com';
            $mail->Password = 'vgjr xfny sgzu msvh'; // Contraseña de aplicación
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            
            // Destinatarios
            $mail->setFrom('gamboasuemi2002@gmail.com', 'Ophelia Landing');
            $mail->addAddress('gamboasuemi2002@gmail.com');
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
            
            return response()->json([
                'success' => true,
                'message' => 'Correo enviado correctamente'
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'error' => "Error: {$mail->ErrorInfo}"
            ], 500);
        }
    }
}