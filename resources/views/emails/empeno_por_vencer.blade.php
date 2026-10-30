<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tu empeño está por vencer</title>
</head>
<body style="margin:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;padding:32px;max-width:100%;">
        <tr><td>
          <h2 style="margin:0 0 8px;color:#111;">{{ $nombreEmpresa }}</h2>
          <h3 style="margin:0 0 16px;color:#b45309;">Tu empeño está por vencer ⏰</h3>
          <p style="color:#444;">Hola {{ $nombreCliente }}, te recordamos que tu empeño en
            <strong>{{ $nombreEmpresa }}</strong> vence
            @if($diasRestantes === 0) <strong>hoy</strong>
            @elseif($diasRestantes === 1) <strong>mañana</strong>
            @else en <strong>{{ $diasRestantes }} días</strong>
            @endif.
          </p>

          <table width="100%" cellpadding="8" cellspacing="0" style="border-top:1px solid #eee;margin:16px 0;color:#333;font-size:14px;">
            <tr><td>Prenda</td><td align="right"><strong>{{ $descripcionPrenda }}</strong></td></tr>
            <tr><td>Fecha de vencimiento</td><td align="right"><strong>{{ $fechaVencimiento }}</strong></td></tr>
            <tr><td>Monto pendiente</td><td align="right"><strong>${{ number_format($montoPendiente, 2) }} MXN</strong></td></tr>
          </table>

          <p style="color:#444;">Puedes liquidar o refrendar tu empeño antes de esa fecha para no perder tu prenda.</p>

          @if($telefonoEmpresa)
          <p style="color:#444;">Contacto: <strong>{{ $telefonoEmpresa }}</strong></p>
          @endif

          <p style="color:#888;font-size:12px;">Si ya realizaste tu pago, ignora este mensaje.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>