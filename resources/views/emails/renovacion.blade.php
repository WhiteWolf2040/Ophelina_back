<!DOCTYPE html>
<html lang="es">
<body style="margin:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;padding:32px;">
        <tr><td>
          <h2 style="margin:0 0 8px;color:#111;">Ophelina</h2>
          <h3 style="margin:0 0 16px;color:#111;">Tu suscripción se renovó ✅</h3>
          <p style="color:#444;">Hola, {{ $empresa->nombre }}. Te confirmamos que el cobro mensual se realizó correctamente.</p>

          <table width="100%" cellpadding="8" cellspacing="0" style="border-top:1px solid #eee;margin:16px 0;color:#333;font-size:14px;">
            <tr><td>Plan</td><td align="right"><strong>{{ $planNombre }}</strong></td></tr>
            <tr><td>Monto cobrado</td><td align="right"><strong>${{ number_format($monto, 2) }} {{ strtoupper($moneda) }}</strong></td></tr>
            @if($numeroFactura)
            <tr><td>Factura</td><td align="right">{{ $numeroFactura }}</td></tr>
            @endif
            @if($proximoCobro)
            <tr><td>Próximo cobro</td><td align="right">{{ $proximoCobro }}</td></tr>
            @endif
          </table>

          @if($facturaUrl)
          <p style="text-align:center;margin:24px 0;">
            <a href="{{ $facturaUrl }}" style="background:#111;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;">Ver recibo</a>
          </p>
          @endif

          <p style="color:#888;font-size:12px;">Si no reconoces este cobro, responde a este correo.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>