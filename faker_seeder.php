<?php

require 'vendor/autoload.php';

$faker = Faker\Factory::create('es_MX');

$pdo = new PDO("mysql:host=localhost;dbname=ophelina_v9.5", "root", "diego2040");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 300);

echo "========================================\n";
echo "  SEEDING DATABASE - OPHELINA V9.5 \n";
echo "  Datos consistentes para análisis\n";
echo "========================================\n\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// Obtener tablas existentes
$stmt = $pdo->query("SHOW TABLES");
$tablasExistentes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$tables = [
    'facturas_suscripcion', 'suscripciones',
    'movimientos_caja', 'detalle_venta', 'venta_tienda', 'apartados',
    'pagos', 'amortizacion', 'empeno', 'producto_tienda', 'imagen_prenda',
    'prendas', 'documento_aval', 'documento_cliente', 'direcciones',
    'metodo_pago', 'clientes', 'aval', 'rol_permiso', 'permisos',
    'tasas_interes', 'usuario', 'rol', 'empresa', 'planes_saas'
];

echo "Limpiando tablas...\n";
foreach ($tables as $table) {
    if (!in_array($table, $tablasExistentes)) {
        echo "  ⚠️ Tabla no existe: $table (saltando)\n";
        continue;
    }
    try {
        $pdo->exec("TRUNCATE TABLE $table");
        echo "  ✅ Limpiada tabla: $table\n";
    } catch (Exception $e) {
        try {
            $pdo->exec("DELETE FROM $table");
            echo "  ✅ Limpiada tabla con DELETE: $table\n";
        } catch (Exception $e2) {
            echo "  ⚠️ No se pudo limpiar $table\n";
        }
    }
}

echo "\n";

// ============================================
// 1. PLANES SAAS
// ============================================

echo "1. Creando planes SAAS...\n";
$planesData = [
    ['nombre' => 'Free Trial', 'clave' => 'free', 'precio_mensual' => 0.00],
    ['nombre' => 'Profesional', 'clave' => 'profesional', 'precio_mensual' => 999.00],
    ['nombre' => 'Premium', 'clave' => 'empresarial', 'precio_mensual' => 1499.00]
];

$planes = [];
foreach ($planesData as $plan) {
    $stmt = $pdo->prepare("INSERT INTO planes_saas (nombre, clave, precio_mensual, max_empleados, max_clientes, max_prendas, max_empenos_activos, dias_prueba, activo) VALUES (?,?,?,5,1000,5000,200,0,1)");
    $stmt->execute([$plan['nombre'], $plan['clave'], $plan['precio_mensual']]);
    $planes[$plan['clave']] = $pdo->lastInsertId();
}
echo "  ✅ Planes creados: " . count($planes) . "\n";

// ============================================
// 2. EMPRESAS (15 empresas)
// ============================================

echo "\n2. Creando empresas...\n";

$empresasData = [
    ['id' => 1, 'nombre' => 'Empresa Juan', 'nombre_comercial' => 'Juan Prendas'],
    ['id' => 2, 'nombre' => 'Empresa Tula', 'nombre_comercial' => 'Tula Empeños'],
    ['id' => 3, 'nombre' => 'Empeños Express', 'nombre_comercial' => 'Express Empeños'],
    ['id' => 4, 'nombre' => 'Monte de Piedad', 'nombre_comercial' => 'Monte Piedad'],
    ['id' => 5, 'nombre' => 'Joyas del Valle', 'nombre_comercial' => 'Joyas Valle'],
    ['id' => 6, 'nombre' => 'Empeños Don Pepe', 'nombre_comercial' => 'Don Pepe'],
    ['id' => 7, 'nombre' => 'La Casa de la Abuela', 'nombre_comercial' => 'Casa Abuela'],
    ['id' => 8, 'nombre' => 'Préstamos Rápidos', 'nombre_comercial' => 'Prestamos Rap'],
    ['id' => 9, 'nombre' => 'Empeños El Sol', 'nombre_comercial' => 'El Sol'],
    ['id' => 10, 'nombre' => 'Joyas y Metales', 'nombre_comercial' => 'Joyas Metales'],
    ['id' => 11, 'nombre' => 'Empeños Santa Fe', 'nombre_comercial' => 'Santa Fe'],
    ['id' => 12, 'nombre' => 'Monte de la Amistad', 'nombre_comercial' => 'Monte Amistad'],
    ['id' => 13, 'nombre' => 'Joyas del Rey', 'nombre_comercial' => 'Joyas Rey'],
    ['id' => 14, 'nombre' => 'Empeños El Tesoro', 'nombre_comercial' => 'El Tesoro'],
    ['id' => 15, 'nombre' => 'Préstamos Seguros', 'nombre_comercial' => 'Prestamos Seg']
];

$empresaIds = [];
foreach ($empresasData as $empresa) {
    $planClave = $faker->randomElement(['free', 'profesional', 'empresarial']);
    $idPlan = $planes[$planClave];
    
    $stmt = $pdo->prepare("INSERT INTO empresa (id_empresa, nombre, nombre_comercial, rfc, telefono, email, direccion, ciudad, estado, codigo_postal, id_plan, fecha_registro, plan_activo) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),0)");
    $stmt->execute([
        $empresa['id'],
        $empresa['nombre'],
        $empresa['nombre_comercial'],
        strtoupper($faker->bothify('???######???')),
        $faker->phoneNumber(),
        strtolower(str_replace(' ', '', $empresa['nombre'])) . '@empresa.com',
        $faker->streetAddress(),
        $faker->city(),
        $faker->state(),
        $faker->postcode(),
        $idPlan
    ]);
    $empresaIds[] = $pdo->lastInsertId();
}
echo "  ✅ Empresas creadas: " . count($empresaIds) . "\n";

// ============================================
// 3. SUSCRIPCIONES - CON FECHAS CONSISTENTES (MEJORADO CON MÁS CANCELACIONES)
// ============================================

echo "\n3. Creando suscripciones mensuales...\n";

$suscripcionesIds = [];
$facturasCount = 0;
$hoy = new DateTime();
$hoy->setTime(0, 0, 0);

// Definir meses: desde hace 8 meses hasta hoy (más histórico)
$meses = [];
for ($i = 0; $i < 8; $i++) {
    $fecha = clone $hoy;
    $fecha->modify("-$i months");
    $meses[] = $fecha->format('Y-m-01');
}
$meses = array_reverse($meses);

// Definir estados predefinidos para mezclar (más cancelaciones)
$estadosEmpresa = ['activa', 'activa', 'activa', 'inactiva', 'inactiva', 'cancelada_historial'];

foreach ($empresaIds as $empresaId) {
    // Obtener plan de la empresa
    $stmt = $pdo->prepare("SELECT id_plan FROM empresa WHERE id_empresa = ?");
    $stmt->execute([$empresaId]);
    $idPlan = $stmt->fetchColumn() ?: 1;
    
    $stmt = $pdo->prepare("SELECT precio_mensual FROM planes_saas WHERE id_plan = ?");
    $stmt->execute([$idPlan]);
    $precioMensual = $stmt->fetchColumn() ?: 0;
    
    // Número de meses de historial (4-7 meses para más variedad)
    $numMeses = rand(4, 7);
    $startIndex = rand(0, max(0, count($meses) - $numMeses));
    
    // Determinar estado de la empresa (más variado)
    $estadoEmpresa = $faker->randomElement($estadosEmpresa);
    $esActiva = ($estadoEmpresa == 'activa');
    $esInactivaConCancelaciones = ($estadoEmpresa == 'inactiva' || $estadoEmpresa == 'cancelada_historial');
    
    for ($i = 0; $i < $numMeses; $i++) {
        $mesIndex = $startIndex + $i;
        if ($mesIndex >= count($meses)) break;
        
        $fechaInicio = new DateTime($meses[$mesIndex]);
        $fechaFin = clone $fechaInicio;
        $fechaFin->modify('+1 month -1 day');
        
        $esUltima = ($i == $numMeses - 1);
        $esPasada = ($fechaFin < $hoy);
        $esFutura = ($fechaInicio > $hoy);
        
        // ============================================
        // LÓGICA DE ESTADOS MEJORADA CON MÁS CANCELACIONES
        // ============================================
        if ($esUltima && !$esPasada && $esActiva) {
            // EMPRESA ACTIVA - Suscripción actual ACTIVA
            $estado = 'activa';
            $fechaCancelacion = null;
        } elseif ($esUltima && !$esPasada && !$esActiva) {
            // EMPRESA INACTIVA - Cancelada en el pasado
            $fechaCancelacion = clone $fechaInicio;
            $diasCancelacion = rand(3, 25);
            $fechaCancelacion->modify("+$diasCancelacion days");
            
            // Siempre en el pasado
            if ($fechaCancelacion > $hoy) {
                $fechaCancelacion = clone $hoy;
                $fechaCancelacion->modify('-'. rand(1, 10) . ' days');
            }
            
            $estado = 'cancelada';
        } elseif ($esUltima && $esPasada) {
            // Ya pasó, está EXPIRADA
            $estado = 'expirada';
            $fechaCancelacion = null;
        } else {
            // SUSCRIPCIONES ANTERIORES - Mezclar entre expiradas y canceladas
            if ($esInactivaConCancelaciones && rand(1, 3) == 1) {
                // Algunas suscripciones anteriores también canceladas (historial)
                $fechaCancelacion = clone $fechaInicio;
                $diasCancelacion = rand(2, 20);
                $fechaCancelacion->modify("+$diasCancelacion days");
                
                // Asegurar que la cancelación esté dentro del período
                if ($fechaCancelacion > $fechaFin) {
                    $fechaCancelacion = clone $fechaFin;
                    $fechaCancelacion->modify('-'. rand(1, 5) . ' days');
                }
                
                // Si la fecha es futura, la ponemos en el pasado
                if ($fechaCancelacion > $hoy) {
                    $fechaCancelacion = clone $fechaInicio;
                    $fechaCancelacion->modify('+'. rand(2, 15) . ' days');
                    if ($fechaCancelacion > $hoy) {
                        $fechaCancelacion = clone $hoy;
                        $fechaCancelacion->modify('-1 day');
                    }
                }
                
                $estado = 'cancelada';
            } else {
                $estado = 'expirada';
                $fechaCancelacion = null;
            }
        }
        
        // ============================================
        // AJUSTE: Si la empresa está activa pero la suscripción es del futuro
        // ============================================
        if ($esFutura && $esActiva) {
            $estado = 'activa';
            $fechaCancelacion = null;
        }
        
        // ============================================
        // INSERTAR SUSCRIPCIÓN
        // ============================================
        $stmt = $pdo->prepare("INSERT INTO suscripciones (id_empresa, id_plan, fecha_inicio, fecha_fin, precio_mensual, estado, fecha_cancelacion) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            $empresaId,
            $idPlan,
            $fechaInicio->format('Y-m-d'),
            $fechaFin->format('Y-m-d'),
            $precioMensual,
            $estado,
            $fechaCancelacion ? $fechaCancelacion->format('Y-m-d') : null
        ]);
        $idSuscripcion = $pdo->lastInsertId();
        $suscripcionesIds[] = $idSuscripcion;
        
        // ============================================
        // CREAR FACTURA - SIEMPRE POR EL MONTO COMPLETO
        // ============================================
        $montoFactura = $precioMensual * 1.16; // Precio + IVA
        
        // Determinar estado y fecha de pago de la factura
        if ($estado == 'expirada') {
            // Las expiradas siempre están pagadas
            $estadoFactura = 'pagada';
            $fechaPago = $faker->dateTimeBetween($fechaInicio, $fechaFin);
            $fechaPago = $fechaPago->format('Y-m-d');
        } elseif ($estado == 'cancelada') {
            // Las canceladas siempre están pagadas (pagaron el mes completo)
            $estadoFactura = 'pagada';
            if ($fechaCancelacion) {
                $fechaPago = $faker->dateTimeBetween($fechaInicio, $fechaCancelacion);
            } else {
                $fechaPago = $faker->dateTimeBetween($fechaInicio, $fechaFin);
            }
            $fechaPago = $fechaPago->format('Y-m-d');
        } elseif ($estado == 'activa' && !$esFutura) {
            // Las activas actuales: 80% pagadas, 20% pendientes
            if (rand(1, 10) <= 8) {
                $estadoFactura = 'pagada';
                $fechaPago = $faker->dateTimeBetween($fechaInicio, $fechaFin);
                $fechaPago = $fechaPago->format('Y-m-d');
            } else {
                $estadoFactura = 'pendiente';
                $fechaPago = null;
            }
        } elseif ($estado == 'activa' && $esFutura) {
            // Suscripciones futuras: siempre pendientes
            $estadoFactura = 'pendiente';
            $fechaPago = null;
        } else {
            // Por defecto: pagada
            $estadoFactura = 'pagada';
            $fechaPago = $faker->dateTimeBetween($fechaInicio, $fechaFin);
            $fechaPago = $fechaPago->format('Y-m-d');
        }
        
        // Validación final: Si la fecha de pago es futura, la ajustamos
        if ($fechaPago && new DateTime($fechaPago) > $hoy) {
            $fechaPago = $hoy->format('Y-m-d');
        }
        
        // Periodo de la factura
        $periodoFin = $fechaFin->format('Y-m-d');
        if ($estado == 'cancelada' && $fechaCancelacion) {
            // Si está cancelada, el periodo fin es la fecha de cancelación
            $periodoFin = $fechaCancelacion->format('Y-m-d');
        }
        
        $stmt = $pdo->prepare("INSERT INTO facturas_suscripcion (id_empresa, id_suscripcion, monto, fecha_factura, fecha_pago, periodo_inicio, periodo_fin, estado) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $empresaId,
            $idSuscripcion,
            round($montoFactura, 2), // Monto completo del plan
            $fechaInicio->format('Y-m-d'),
            $fechaPago,
            $fechaInicio->format('Y-m-d'),
            $periodoFin,
            $estadoFactura
        ]);
        $facturasCount++;
    }
    
    // ============================================
    // ACTUALIZAR plan_activo DE LA EMPRESA
    // ============================================
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM suscripciones WHERE id_empresa = ? AND estado = 'activa' AND fecha_inicio <= ? AND fecha_fin >= ?");
    $stmt->execute([$empresaId, $hoy->format('Y-m-d'), $hoy->format('Y-m-d')]);
    $tieneActiva = $stmt->fetchColumn() > 0;
    
    $stmt = $pdo->prepare("UPDATE empresa SET plan_activo = ? WHERE id_empresa = ?");
    $stmt->execute([$tieneActiva ? 1 : 0, $empresaId]);
}

// ============================================
// CONTEO DE SUSCRIPCIONES POR ESTADO
// ============================================
$stmt = $pdo->query("SELECT estado, COUNT(*) as total FROM suscripciones GROUP BY estado");
$estadisticas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "  ✅ Suscripciones creadas: " . count($suscripcionesIds) . "\n";
echo "  ✅ Facturas creadas: $facturasCount\n";
echo "  📊 Distribución de estados:\n";
foreach ($estadisticas as $stat) {
    echo "     ├─ {$stat['estado']}: {$stat['total']}\n";
}

// ============================================
// 4. ROLES
// ============================================

echo "\n4. Creando roles...\n";

$rolesBase = ["Administrador", "Gerente", "Cajero", "Cliente"];
$rolIds = [];

foreach ($empresaIds as $empresaId) {
    foreach ($rolesBase as $nivel => $nombre) {
        $stmt = $pdo->prepare("INSERT INTO rol(id_empresa, nombre, descripcion, nivel) VALUES (?,?,?,?)");
        $descripcion = match($nombre) {
            'Administrador' => 'Acceso total al sistema',
            'Gerente' => 'Gestión de clientes y empeños',
            'Cajero' => 'Operaciones de caja',
            'Cliente' => 'Portal de clientes',
            default => 'Rol del sistema'
        };
        $stmt->execute([$empresaId, $nombre, $descripcion, $nivel + 1]);
        $rolIds[$empresaId][$nombre] = $pdo->lastInsertId();
    }
}
echo "  ✅ Roles creados: " . (count($empresaIds) * 4) . "\n";

// ============================================
// 5. PERMISOS
// ============================================

echo "\n5. Creando permisos...\n";

$permisosBase = [
    'ver_dashboard', 'ver_clientes', 'crear_clientes', 'editar_clientes', 'eliminar_clientes',
    'ver_empenos', 'crear_empenos', 'editar_empenos', 'eliminar_empenos', 'cancelar_empenos',
    'ver_tienda', 'crear_productos', 'editar_productos', 'eliminar_productos',
    'ver_pagos', 'registrar_pagos', 'ver_caja', 'registrar_movimientos',
    'ver_reportes', 'ver_configuracion', 'ver_permisos', 'ver_roles',
    'crear_roles', 'editar_roles', 'eliminar_roles', 'crear_permisos', 'editar_permisos', 'eliminar_permisos'
];

$modulos = [
    'dashboard', 'clientes', 'clientes', 'clientes', 'clientes',
    'empenos', 'empenos', 'empenos', 'empenos', 'empenos',
    'tienda', 'tienda', 'tienda', 'tienda',
    'pagos', 'pagos', 'caja', 'caja',
    'reportes', 'configuracion', 'configuracion', 'configuracion',
    'configuracion', 'configuracion', 'configuracion', 'configuracion', 'configuracion', 'configuracion'
];

$permisoIds = [];

foreach ($empresaIds as $empresaId) {
    foreach ($permisosBase as $index => $nombre) {
        $modulo = $modulos[$index] ?? 'general';
        $stmt = $pdo->prepare("INSERT INTO permisos(id_empresa, nombre, descripcion, modulo, estado) VALUES (?,?,?,?,?)");
        $stmt->execute([$empresaId, $nombre, "Permiso para $nombre", $modulo, 'activo']);
        $permisoIds[$empresaId][$nombre] = $pdo->lastInsertId();
    }
}
echo "  ✅ Permisos creados\n";

// ============================================
// 6. ROL_PERMISO
// ============================================

echo "\n6. Asignando permisos...\n";

foreach ($empresaIds as $empresaId) {
    $adminRolId = $rolIds[$empresaId]['Administrador'];
    foreach ($permisoIds[$empresaId] as $permisoId) {
        $stmt = $pdo->prepare("INSERT INTO rol_permiso(id_empresa, id_rol, id_permiso, permitido) VALUES (?,?,?,1)");
        $stmt->execute([$empresaId, $adminRolId, $permisoId]);
    }
}
echo "  ✅ Permisos asignados\n";

// ============================================
// 7. USUARIOS Y CLIENTES
// ============================================

echo "\n7. Creando usuarios y clientes...\n";

$todosUsuarios = [];
$clientes = [];
$credenciales = [];

// Preparar statement para usuarios (8 parámetros)
$stmtUsuario = $pdo->prepare("INSERT INTO usuario (id_rol, id_empresa, nombre, correo, contrasena, telefono, activo, fecha_registro) VALUES (?,?,?,?,?,?,?,NOW())");

foreach ($empresaIds as $empresaId) {
    // Obtener datos de la empresa
    $stmt = $pdo->prepare("SELECT plan_activo, nombre_comercial FROM empresa WHERE id_empresa = ?");
    $stmt->execute([$empresaId]);
    $empresaData = $stmt->fetch(PDO::FETCH_ASSOC);
    $planActivo = $empresaData['plan_activo'] ?? 0;
    $empresaNombre = $empresaData['nombre_comercial'] ?? 'Empresa';
    
    // ===== ADMINISTRADOR (siempre existe) =====
    $adminRolId = $rolIds[$empresaId]['Administrador'];
    $emailAdmin = strtolower(str_replace(' ', '', $empresaNombre)) . '@admin.com';
    
    $stmtUsuario->execute([
        $adminRolId,
        $empresaId,
        "Administrador $empresaNombre",
        $emailAdmin,
        password_hash("123456", PASSWORD_BCRYPT),
        $faker->phoneNumber(),
        $planActivo
    ]);
    $todosUsuarios[] = $pdo->lastInsertId();
    $credenciales[] = ['empresa' => $empresaNombre, 'rol' => 'Administrador', 'email' => $emailAdmin, 'password' => '123456'];
    
    // Si la empresa NO está activa, saltar el resto
    if (!$planActivo) continue;
    
    // ===== GERENTES (2) =====
    $gerenteRolId = $rolIds[$empresaId]['Gerente'];
    for ($i = 0; $i < 2; $i++) {
        $email = strtolower(str_replace(' ', '', $empresaNombre)) . ".gerente$i@admin.com";
        $stmtUsuario->execute([
            $gerenteRolId,
            $empresaId,
            "Gerente " . $faker->firstName(),
            $email,
            password_hash("123456", PASSWORD_BCRYPT),
            $faker->phoneNumber(),
            1
        ]);
        $todosUsuarios[] = $pdo->lastInsertId();
    }
    
    // ===== CAJEROS (3) =====
    $cajeroRolId = $rolIds[$empresaId]['Cajero'];
    for ($i = 0; $i < 3; $i++) {
        $email = strtolower(str_replace(' ', '', $empresaNombre)) . ".cajero$i@admin.com";
        $stmtUsuario->execute([
            $cajeroRolId,
            $empresaId,
            "Cajero " . $faker->firstName(),
            $email,
            password_hash("123456", PASSWORD_BCRYPT),
            $faker->phoneNumber(),
            1
        ]);
        $todosUsuarios[] = $pdo->lastInsertId();
    }
    
    // ===== CLIENTES (30) =====
    $clienteRolId = $rolIds[$empresaId]['Cliente'];
    for ($i = 0; $i < 30; $i++) {
        $nombre = $faker->firstName();
        $apellido = $faker->lastName();
        $email = $faker->unique()->safeEmail();
        
        // Insertar usuario cliente (8 parámetros)
        $stmtUsuario->execute([
            $clienteRolId,
            $empresaId,
            "$nombre $apellido",
            $email,
            password_hash("123456", PASSWORD_BCRYPT),
            $faker->phoneNumber(),
            1
        ]);
        $idUsuario = $pdo->lastInsertId();
        $todosUsuarios[] = $idUsuario;
        
        // Insertar cliente (12 parámetros)
        $stmtCliente = $pdo->prepare("INSERT INTO clientes (id_usuario, id_empresa, nombre, apellido, telefono, correo, direccion, codigo_postal, ciudad, estado, fecha_registro, activo) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),1)");
        $stmtCliente->execute([
            $idUsuario,
            $empresaId,
            $nombre,
            $apellido,
            $faker->phoneNumber(),
            $email,
            $faker->streetAddress(),
            $faker->postcode(),
            $faker->city(),
            $faker->state()
        ]);
        $clientes[] = ['id' => $pdo->lastInsertId(), 'id_empresa' => $empresaId];
    }
}
echo "  ✅ Usuarios: " . count($todosUsuarios) . "\n";
echo "  ✅ Clientes: " . count($clientes) . "\n";

// ============================================
// 8. AVALES
// ============================================

echo "\n8. Creando avales...\n";

$avales = [];
$clientesConAval = array_slice($clientes, 0, intval(count($clientes) * 0.3));

foreach ($clientesConAval as $cliente) {
    $stmt = $pdo->prepare("INSERT INTO aval (id_empresa, id_cliente, nombre, apellido, telefono, direccion, email) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([
        $cliente['id_empresa'],
        $cliente['id'],
        $faker->firstName(),
        $faker->lastName(),
        $faker->phoneNumber(),
        $faker->address(),
        $faker->safeEmail()
    ]);
    $avales[] = $pdo->lastInsertId();
}
echo "  ✅ Avales: " . count($avales) . "\n";

// ============================================
// 9. TASAS INTERES
// ============================================

echo "\n9. Creando tasas de interés...\n";

$tasasData = [
    ['nombre' => 'Basico', 'porcentaje' => 5.00, 'plazo_dias' => 15],
    ['nombre' => 'Estandar', 'porcentaje' => 8.00, 'plazo_dias' => 30],
    ['nombre' => 'Premium', 'porcentaje' => 10.00, 'plazo_dias' => 45],
    ['nombre' => 'Extendido', 'porcentaje' => 12.00, 'plazo_dias' => 60],
    ['nombre' => 'Flexible', 'porcentaje' => 15.00, 'plazo_dias' => 90]
];

$tasas = [];
foreach ($tasasData as $tasa) {
    $stmt = $pdo->prepare("INSERT INTO tasas_interes (nombre, porcentaje, plazo_dias, activo) VALUES (?,?,?,1)");
    $stmt->execute([$tasa['nombre'], $tasa['porcentaje'], $tasa['plazo_dias']]);
    $tasas[] = $pdo->lastInsertId();
}
echo "  ✅ Tasas: " . count($tasas) . "\n";

// ============================================
// 10. PRENDAS
// ============================================

echo "\n10. Creando prendas...\n";

$prendas = [];
$tipos = ["Joyería", "Electrónica", "Relojes", "Herramientas", "Instrumentos", "Otros"];

foreach ($empresaIds as $empresaId) {
    $stmt = $pdo->prepare("SELECT plan_activo FROM empresa WHERE id_empresa = ?");
    $stmt->execute([$empresaId]);
    if (!$stmt->fetchColumn()) continue;
    
    for ($i = 0; $i < 15; $i++) {
        $tipo = $tipos[array_rand($tipos)];
        $material = $faker->randomElement(['oro', 'plata', 'acero', 'platino']);
        $valor = rand(500, 50000);
        $stmt = $pdo->prepare("INSERT INTO prendas (id_empresa, descripcion, tipo, material, peso_gramos, valor_estimado, codigo_barras, estado) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$empresaId, "Artículo de $tipo", $tipo, $material, rand(10, 500), $valor, $faker->ean13(), 'Disponible']);
        $prendas[] = ['id' => $pdo->lastInsertId(), 'id_empresa' => $empresaId, 'valor_estimado' => $valor];
    }
}
echo "  ✅ Prendas: " . count($prendas) . "\n";

// ============================================
// 11. EMPEÑOS
// ============================================

echo "\n11. Creando empeños...\n";

$empenos = [];
foreach ($empresaIds as $empresaId) {
    $stmt = $pdo->prepare("SELECT plan_activo FROM empresa WHERE id_empresa = ?");
    $stmt->execute([$empresaId]);
    if (!$stmt->fetchColumn()) continue;
    
    $clientesEmpresa = array_filter($clientes, fn($c) => $c['id_empresa'] == $empresaId);
    $prendasEmpresa = array_filter($prendas, fn($p) => $p['id_empresa'] == $empresaId);
    if (empty($clientesEmpresa) || empty($prendasEmpresa)) continue;
    
    for ($i = 0; $i < 8; $i++) {
        $cliente = $clientesEmpresa[array_rand($clientesEmpresa)];
        $prenda = $prendasEmpresa[array_rand($prendasEmpresa)];
        $idTasa = $tasas[array_rand($tasas)];
        
        $stmt = $pdo->prepare("SELECT porcentaje, plazo_dias FROM tasas_interes WHERE id_tasa = ?");
        $stmt->execute([$idTasa]);
        $tasa = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $monto = rand(500, 15000);
        $interes = $monto * ($tasa['porcentaje'] / 100);
        $iva = $interes * 0.16;
        
        $fechaEmpeno = $faker->dateTimeBetween('-60 days', 'now')->format('Y-m-d');
        $fechaVenc = (new DateTime($fechaEmpeno))->modify("+{$tasa['plazo_dias']} days")->format('Y-m-d');
        $estado = $faker->randomElement(['activo', 'activo', 'pagado', 'vencido']);
        
        $stmt = $pdo->prepare("INSERT INTO empeno (id_empresa, id_cliente, id_prenda, id_tasa, fecha_empeno, monto_prestado, intereses, iva_porcentaje, fecha_vencimiento, estado, folio) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$empresaId, $cliente['id'], $prenda['id'], $idTasa, $fechaEmpeno, $monto, $tasa['porcentaje'], 16.00, $fechaVenc, $estado, strtoupper($faker->bothify('EMP###???'))]);
        $empenos[] = $pdo->lastInsertId();
    }
}
echo "  ✅ Empeños: " . count($empenos) . "\n";

// ============================================
// 12. LIMPIEZA FINAL
// ============================================

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "\n========================================\n";
echo "✅ DATABASE SEEDED SUCCESSFULLY!\n";
echo "========================================\n";

echo "\n📊 RESUMEN FINAL:\n";
echo "├─ Planes: " . count($planes) . "\n";
echo "├─ Empresas: " . count($empresaIds) . "\n";
echo "├─ Suscripciones: " . count($suscripcionesIds) . "\n";
echo "├─ Facturas: $facturasCount\n";
echo "├─ Roles: " . (count($empresaIds) * 4) . "\n";
echo "├─ Usuarios: " . count($todosUsuarios) . "\n";
echo "├─ Clientes: " . count($clientes) . "\n";
echo "├─ Prendas: " . count($prendas) . "\n";
echo "├─ Empeños: " . count($empenos) . "\n";

echo "\n🔑 CREDENCIALES DE ACCESO:\n";
foreach ($credenciales as $cred) {
    echo "├─ {$cred['empresa']} - {$cred['rol']}: {$cred['email']} / 123456\n";
}

echo "\n========================================\n";
echo "  ¡TODOS LOS DATOS GENERADOS CORRECTAMENTE!\n";
echo "========================================\n";