<?php

require 'vendor/autoload.php';

$faker = Faker\Factory::create('es_MX');

$pdo = new PDO("mysql:host=localhost;dbname=ophelina_v4", "root", "diego2040");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Seeding database...\n\n";

// Desactivar revisión de llaves foráneas temporalmente
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// Limpiar tablas existentes
$tables = [
    'movimientos_caja', 'detalle_venta', 'venta_tienda', 'apartados',
    'pagos', 'amortizacion', 'empeno', 'producto_tienda', 'imagen_prenda',
    'prendas', 'documento_aval', 'documento_cliente', 'direcciones',
    'metodo_pago', 'clientes', 'aval', 'rol_permiso', 'permisos',
    'tasas_interes', 'usuario', 'rol', 'empresa'
];

foreach ($tables as $table) {
    $pdo->exec("TRUNCATE TABLE $table");
    echo "Limpiada tabla: $table\n";
}

echo "\n";

/* =====================
   EMPRESAS
=====================*/

$empresas = [
    [
        'nombre' => 'Empresa Juan', 
        'nombre_comercial' => 'Juan Prendas', 
        'rfc' => 'JUAN123456ABC', 
        'telefono' => '5551234567', 
        'email' => 'juan@empresa.com', 
        'direccion' => 'Calle Principal 123', 
        'ciudad' => 'Ciudad de México', 
        'estado' => 'CDMX', 
        'codigo_postal' => '12345'
    ],
    [
        'nombre' => 'Empresa Tula', 
        'nombre_comercial' => 'Tula Empeños', 
        'rfc' => 'TULA987654XYZ', 
        'telefono' => '5557654321', 
        'email' => 'tula@empresa.com', 
        'direccion' => 'Av. Reforma 456', 
        'ciudad' => 'Tula', 
        'estado' => 'Hidalgo', 
        'codigo_postal' => '67890'
    ],
    [
        'nombre' => 'Empeños Express', 
        'nombre_comercial' => 'Express Empeños', 
        'rfc' => 'EXP123456ABC', 
        'telefono' => '5559876543', 
        'email' => 'express@empresa.com', 
        'direccion' => 'Boulevard Central 789', 
        'ciudad' => 'Guadalajara', 
        'estado' => 'Jalisco', 
        'codigo_postal' => '44100'
    ]
];

$empresaIds = [];
foreach ($empresas as $empresa) {
    $stmt = $pdo->prepare("INSERT INTO empresa (nombre, nombre_comercial, rfc, telefono, email, direccion, ciudad, estado, codigo_postal) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        substr($empresa['nombre'], 0, 100),
        substr($empresa['nombre_comercial'], 0, 100),
        substr($empresa['rfc'], 0, 13),
        substr($empresa['telefono'], 0, 20),
        substr($empresa['email'], 0, 100),
        substr($empresa['direccion'], 0, 255),
        substr($empresa['ciudad'], 0, 100),
        substr($empresa['estado'], 0, 100),
        substr($empresa['codigo_postal'], 0, 10)
    ]);
    $empresaIds[] = $pdo->lastInsertId();
}
echo "Empresas creadas: " . count($empresaIds) . "\n";

/* =====================
   ROLES
=====================*/

$roles = ["Administrador", "Gerente", "Cajero", "Cliente"];
$rolIds = [];
foreach ($roles as $i => $rol) {
    $stmt = $pdo->prepare("INSERT INTO rol(nombre, descripcion, nivel) VALUES (?,?,?)");
    $stmt->execute([$rol, substr($faker->sentence(3), 0, 255), $i + 1]);
    $rolIds[] = $pdo->lastInsertId();
}
echo "Roles creados: 4\n";

/* =====================
   PERMISOS
=====================*/

$permisos = [
    "ver_clientes", "crear_clientes", "editar_clientes", "eliminar_clientes",
    "ver_empenos", "crear_empenos", "editar_empenos", "cancelar_empenos",
    "ver_pagos", "registrar_pagos",
    "ver_tienda", "crear_productos", "editar_productos",
    "ver_caja", "registrar_movimientos",
    "ver_reportes", "ver_dashboard"
];

foreach ($permisos as $perm) {
    $stmt = $pdo->prepare("INSERT INTO permisos(nombre, descripcion, modulo) VALUES (?,?,?)");
    $stmt->execute([$perm, substr($faker->sentence(3), 0, 255), "general"]);
}
echo "Permisos creados: " . count($permisos) . "\n";

/* =====================
   USUARIOS
=====================*/

// Crear usuarios administradores
$admins = [];
foreach ($empresaIds as $index => $empresaId) {
    $nombreEmpresa = $empresas[$index]['nombre_comercial'];
    $email = strtolower(str_replace(' ', '', $nombreEmpresa)) . '@admin.com';
    
    $stmt = $pdo->prepare("INSERT INTO usuario (id_rol, id_empresa, nombre, correo, contrasena, telefono, activo, fecha_registro) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        $rolIds[0], 
        $empresaId, 
        substr("Admin $nombreEmpresa", 0, 100), 
        substr($email, 0, 100), 
        password_hash("123456", PASSWORD_BCRYPT), 
        substr($faker->phoneNumber(), 0, 20), 
        1
    ]);
    $admins[] = $pdo->lastInsertId();
}

// Crear usuarios gerentes
$gerentes = [];
for ($i = 0; $i < count($empresaIds) * 2; $i++) {
    $empresaId = $empresaIds[$i % count($empresaIds)];
    $stmt = $pdo->prepare("INSERT INTO usuario (id_rol, id_empresa, nombre, correo, contrasena, telefono, activo, fecha_registro) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        $rolIds[1],
        $empresaId,
        substr($faker->name(), 0, 100),
        substr($faker->unique()->safeEmail(), 0, 100),
        password_hash("123456", PASSWORD_BCRYPT),
        substr($faker->phoneNumber(), 0, 20),
        1
    ]);
    $gerentes[] = $pdo->lastInsertId();
}

// Crear usuarios cajeros
$cajeros = [];
for ($i = 0; $i < count($empresaIds) * 3; $i++) {
    $empresaId = $empresaIds[$i % count($empresaIds)];
    $stmt = $pdo->prepare("INSERT INTO usuario (id_rol, id_empresa, nombre, correo, contrasena, telefono, activo, fecha_registro) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        $rolIds[2],
        $empresaId,
        substr($faker->name(), 0, 100),
        substr($faker->unique()->safeEmail(), 0, 100),
        password_hash("123456", PASSWORD_BCRYPT),
        substr($faker->phoneNumber(), 0, 20),
        1
    ]);
    $cajeros[] = $pdo->lastInsertId();
}

// Crear usuarios clientes
$clientesUsuarios = [];
for ($i = 0; $i < 100; $i++) {
    $empresaId = $empresaIds[array_rand($empresaIds)];
    $email = $faker->unique()->safeEmail();
    $nombre = $faker->firstName();
    $apellido = $faker->lastName();
    
    $stmt = $pdo->prepare("INSERT INTO usuario (id_rol, id_empresa, nombre, correo, contrasena, telefono, activo, fecha_registro) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        $rolIds[3],
        $empresaId,
        substr("$nombre $apellido", 0, 100),
        substr($email, 0, 100),
        password_hash("123456", PASSWORD_BCRYPT),
        substr($faker->phoneNumber(), 0, 20),
        1
    ]);
    $clientesUsuarios[] = [
        'id_usuario' => $pdo->lastInsertId(),
        'nombre' => $nombre,
        'apellido' => $apellido,
        'email' => $email,
        'id_empresa' => $empresaId
    ];
}

$todosUsuarios = array_merge($admins, $gerentes, $cajeros, array_column($clientesUsuarios, 'id_usuario'));
echo "Usuarios creados: " . count($todosUsuarios) . "\n";

/* =====================
   CLIENTES
=====================*/

$clientes = [];
foreach ($clientesUsuarios as $clienteUsuario) {
    $stmt = $pdo->prepare("INSERT INTO clientes (id_usuario, id_empresa, nombre, apellido, telefono, correo, direccion, codigo_postal, ciudad, estado, fecha_registro, activo) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),1)");
    $stmt->execute([
        $clienteUsuario['id_usuario'],
        $clienteUsuario['id_empresa'],
        substr($clienteUsuario['nombre'], 0, 100),
        substr($clienteUsuario['apellido'], 0, 100),
        substr($faker->phoneNumber(), 0, 20),
        substr($clienteUsuario['email'], 0, 100),
        substr($faker->streetAddress(), 0, 255),
        substr($faker->postcode(), 0, 10),
        substr($faker->city(), 0, 100),
        substr($faker->state(), 0, 100)
    ]);
    $clientes[] = [
        'id' => $pdo->lastInsertId(),
        'id_usuario' => $clienteUsuario['id_usuario'],
        'id_empresa' => $clienteUsuario['id_empresa'],
        'nombre' => $clienteUsuario['nombre'],
        'apellido' => $clienteUsuario['apellido']
    ];
}
echo "Clientes creados: " . count($clientes) . "\n";

/* =====================
   AVALES
=====================*/

$avales = [];
for ($i = 0; $i < 30; $i++) {
    $idEmpresa = $empresaIds[array_rand($empresaIds)];
    
    $stmt = $pdo->prepare("INSERT INTO aval (id_empresa, nombre, apellido, telefono, direccion, email) VALUES (?,?,?,?,?,?)");
    $stmt->execute([
        $idEmpresa,
        substr($faker->firstName(), 0, 100),
        substr($faker->lastName(), 0, 100),
        substr($faker->phoneNumber(), 0, 20),
        substr($faker->address(), 0, 255),
        substr($faker->safeEmail(), 0, 100)
    ]);
    $avales[] = [
        'id' => $pdo->lastInsertId(),
        'id_empresa' => $idEmpresa
    ];
}
echo "Avales creados: " . count($avales) . "\n";

/* =====================
   PRENDAS
=====================*/

$tipos = ["Joyería", "Electrónica", "Relojes", "Herramientas", "Instrumentos", "Otros"];
$materiales = ["oro", "plata", "acero", "platino", "madera", "plástico"];
$estadosPrenda = ["Disponible", "En Empeño", "Vendido", "Vencido", "Apartado"];

$prendas = [];
for ($i = 0; $i < 150; $i++) {
    $idEmpresa = $empresaIds[array_rand($empresaIds)];
    $tipo = $tipos[array_rand($tipos)];
    $material = $materiales[array_rand($materiales)];
    $estadoPrenda = $estadosPrenda[array_rand($estadosPrenda)];
    
    $descripcion = "Artículo de $tipo hecho de $material, en buen estado.";
    $valorEstimado = rand(500, 50000);
    
    $stmt = $pdo->prepare("INSERT INTO prendas (id_empresa, descripcion, tipo, material, peso_gramos, valor_estimado, codigo_barras, estado) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $idEmpresa,
        substr($descripcion, 0, 255),
        $tipo,
        substr($material, 0, 100),
        rand(10, 500),
        $valorEstimado,
        substr($faker->ean13(), 0, 50),
        $estadoPrenda
    ]);
    $prendas[] = [
        'id' => $pdo->lastInsertId(),
        'id_empresa' => $idEmpresa,
        'valor_estimado' => $valorEstimado,
        'estado' => $estadoPrenda
    ];
}
echo "Prendas creadas: " . count($prendas) . "\n";

/* =====================
   TASAS INTERES
=====================*/

$tasasInteres = [
    ['nombre' => 'Basico', 'porcentaje' => 5.00, 'plazo_dias' => 15],
    ['nombre' => 'Estandar', 'porcentaje' => 8.00, 'plazo_dias' => 30],
    ['nombre' => 'Premium', 'porcentaje' => 10.00, 'plazo_dias' => 45],
    ['nombre' => 'Extendido', 'porcentaje' => 12.00, 'plazo_dias' => 60],
    ['nombre' => 'Flexible', 'porcentaje' => 15.00, 'plazo_dias' => 90]
];

$tasas = [];
foreach ($tasasInteres as $tasa) {
    $stmt = $pdo->prepare("INSERT INTO tasas_interes (nombre, porcentaje, plazo_dias, activo) VALUES (?,?,?,1)");
    $stmt->execute([$tasa['nombre'], $tasa['porcentaje'], $tasa['plazo_dias']]);
    $tasas[] = $pdo->lastInsertId();
}
echo "Tasas de interés creadas: " . count($tasas) . "\n";

/* =====================
   EMPEÑOS, AMORTIZACIONES Y PAGOS - CORREGIDO
=====================*/

$empenos = [];
$amortizacionesTotales = 0;
$pagosRegistrados = 0;

for ($i = 0; $i < 100; $i++) {  // Aumentado a 100 empeños
    $cliente = $clientes[array_rand($clientes)];
    $prenda = $prendas[array_rand($prendas)];
    $aval = $avales[array_rand($avales)];
    $idTasa = $tasas[array_rand($tasas)];
    
    $stmt = $pdo->prepare("SELECT porcentaje, plazo_dias FROM tasas_interes WHERE id_tasa = ?");
    $stmt->execute([$idTasa]);
    $tasa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $montoPrestado = rand(500, 15000);
    $interesPorcentaje = $tasa['porcentaje'];
    $plazoDias = $tasa['plazo_dias'];
    
    $interesMonto = $montoPrestado * ($interesPorcentaje / 100);
    $ivaInteres = $interesMonto * 0.16;
    $montoTotal = $montoPrestado + $interesMonto + $ivaInteres;
    
    // Distribución de estados: 40% activos, 30% pagados, 30% vencidos
    $randomEstado = rand(1, 10);
    if ($randomEstado <= 4) { // 40% activos
        $fechaEmpeno = $faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d');
        $fechaVencimiento = (new DateTime($fechaEmpeno))->modify("+$plazoDias days")->format('Y-m-d');
        $estado = 'activo';
    } elseif ($randomEstado <= 7) { // 30% pagados
        $fechaEmpeno = $faker->dateTimeBetween('-90 days', '-30 days')->format('Y-m-d');
        $fechaVencimiento = (new DateTime($fechaEmpeno))->modify("+$plazoDias days")->format('Y-m-d');
        $estado = 'pagado';
    } else { // 30% vencidos
        $fechaEmpeno = $faker->dateTimeBetween('-60 days', '-15 days')->format('Y-m-d');
        $fechaVencimiento = (new DateTime($fechaEmpeno))->modify("+$plazoDias days")->format('Y-m-d');
        $estado = 'vencido';
    }
    
    // Insertar empeño
    $stmt = $pdo->prepare("INSERT INTO empeno (id_empresa, id_cliente, id_prenda, id_aval, id_tasa, fecha_empeno, monto_prestado, intereses, iva_porcentaje, fecha_vencimiento, estado, folio) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $cliente['id_empresa'],
        $cliente['id'],
        $prenda['id'],
        $aval['id'],
        $idTasa,
        $fechaEmpeno,
        $montoPrestado,
        $interesPorcentaje,
        16.00,
        $fechaVencimiento,
        $estado,
        strtoupper(substr($faker->bothify("EMP###???"), 0, 20))
    ]);
    
    $idEmpeno = $pdo->lastInsertId();
    
    // Crear amortización (SIEMPRE se crea una amortización por empeño)
    $fechaPagoProgramado = (new DateTime($fechaEmpeno))->modify("+$plazoDias days")->format('Y-m-d');
    
    $stmt = $pdo->prepare("INSERT INTO amortizacion (id_empeno, saldo_inicial, saldo_final, numero_pago, fecha_pago_programado, capital, interes, iva_interes, monto_total, monto_pagado, estado) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $idEmpeno,
        $montoTotal,
        $montoTotal,
        1,
        $fechaPagoProgramado,
        $montoPrestado,
        $interesMonto,
        $ivaInteres,
        $montoTotal,
        0,
        'pendiente'
    ]);
    $idAmortizacion = $pdo->lastInsertId();
    $amortizacionesTotales++;
    
    // Crear pagos según el estado del empeño
    if ($estado == 'pagado') {
        // Pago completo
        $fechaPago = $faker->dateTimeBetween($fechaEmpeno, $fechaVencimiento)->format('Y-m-d');
        
        $stmt = $pdo->prepare("INSERT INTO pagos (id_empeno, id_amortizacion, fecha_pago, capital_pagado, interes_pagado, iva_pagado, monto_total, tipo_pago, metodo_pago, fecha_registro) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([
            $idEmpeno,
            $idAmortizacion,
            $fechaPago,
            $montoPrestado,
            $interesMonto,
            $ivaInteres,
            $montoTotal,
            'liquidacion',
            $faker->randomElement(['efectivo', 'transferencia', 'tarjeta']),
        ]);
        $pagosRegistrados++;
        
        // Actualizar amortización como pagada
        $stmt = $pdo->prepare("UPDATE amortizacion SET monto_pagado = monto_total, saldo_final = 0, estado = 'pagado', fecha_pago_real = ? WHERE id_amortizacion = ?");
        $stmt->execute([$fechaPago, $idAmortizacion]);
        
    } elseif ($estado == 'activo') {
        // Para empeños activos, crear pagos parciales en algunos casos
        $numPagos = rand(0, 2); // 0, 1 o 2 pagos parciales
        $montoRestante = $montoTotal;
        $totalPagado = 0;
        
        for ($p = 1; $p <= $numPagos; $p++) {
            if ($montoRestante <= 0) break;
            
            $porcentajePago = rand(10, 40) / 100;
            if ($p == $numPagos) {
                // Último pago parcial, no pagar más del 70% del total
                $porcentajePago = min($porcentajePago, 0.7);
            }
            
            $capitalPagado = round($montoPrestado * $porcentajePago, 2);
            $interesPagado = round($interesMonto * $porcentajePago, 2);
            $ivaPagado = round($ivaInteres * $porcentajePago, 2);
            $montoPagado = $capitalPagado + $interesPagado + $ivaPagado;
            
            if ($montoPagado > $montoRestante) {
                $montoPagado = $montoRestante;
                $factor = $montoPagado / $montoTotal;
                $capitalPagado = round($montoPrestado * $factor, 2);
                $interesPagado = round($interesMonto * $factor, 2);
                $ivaPagado = round($ivaInteres * $factor, 2);
            }
            
            $fechaPago = $faker->dateTimeBetween($fechaEmpeno, 'now')->format('Y-m-d');
            
            $stmt = $pdo->prepare("INSERT INTO pagos (id_empeno, id_amortizacion, fecha_pago, capital_pagado, interes_pagado, iva_pagado, monto_total, tipo_pago, metodo_pago, fecha_registro) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
            $stmt->execute([
                $idEmpeno,
                $idAmortizacion,
                $fechaPago,
                $capitalPagado,
                $interesPagado,
                $ivaPagado,
                $montoPagado,
                'abono',
                $faker->randomElement(['efectivo', 'transferencia', 'tarjeta']),
            ]);
            $pagosRegistrados++;
            
            $totalPagado += $montoPagado;
            $montoRestante = $montoTotal - $totalPagado;
        }
        
        // Actualizar amortización con el total pagado
        if ($totalPagado > 0) {
            $stmt = $pdo->prepare("UPDATE amortizacion SET monto_pagado = ?, saldo_final = ? WHERE id_amortizacion = ?");
            $stmt->execute([$totalPagado, $montoRestante, $idAmortizacion]);
        }
        
        $empenos[] = [
            'id' => $idEmpeno,
            'estado' => $estado,
            'monto_pagado' => $totalPagado
        ];
    } else {
        // Empeños vencidos - no tienen pagos
        $empenos[] = [
            'id' => $idEmpeno,
            'estado' => $estado,
            'monto_pagado' => 0
        ];
    }
    
    // Para empeños pagados, también agregar al array
    if ($estado == 'pagado') {
        $empenos[] = [
            'id' => $idEmpeno,
            'estado' => $estado,
            'monto_pagado' => $montoTotal
        ];
    }
}

echo "Empeños creados: " . count($empenos) . "\n";
echo "Amortizaciones creadas: $amortizacionesTotales\n";
echo "Pagos registrados: $pagosRegistrados\n";

// Verificar que se crearon amortizaciones
$checkAmortizaciones = $pdo->query("SELECT COUNT(*) FROM amortizacion")->fetchColumn();
echo "Verificación - Amortizaciones en BD: $checkAmortizaciones\n";

$checkPagos = $pdo->query("SELECT COUNT(*) FROM pagos")->fetchColumn();
echo "Verificación - Pagos en BD: $checkPagos\n";

/* =====================
   PRODUCTOS TIENDA
=====================*/

$productos = [];
for ($i = 0; $i < 60; $i++) {
    $prenda = $prendas[array_rand($prendas)];
    $precioVenta = round($prenda['valor_estimado'] * (rand(70, 130) / 100), 2);
    $estadosProducto = ['Nuevo', 'Como nuevo', 'Buen estado', 'Aceptable'];
    
    $stmt = $pdo->prepare("INSERT INTO producto_tienda (id_empresa, id_prenda, nombre, descripcion, precio, stock, estado_producto, visible, destacado) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $prenda['id_empresa'],
        $prenda['id'],
        substr($faker->words(2, true), 0, 100),
        substr($faker->sentence(), 0, 255),
        $precioVenta,
        rand(1, 25),
        $estadosProducto[array_rand($estadosProducto)],
        1,
        rand(0, 1)
    ]);
    $productos[] = $pdo->lastInsertId();
}
echo "Productos tienda creados: " . count($productos) . "\n";

/* =====================
   VENTAS Y DETALLES
=====================*/

$ventasIds = [];
for ($i = 0; $i < 50; $i++) {
    $cliente = $clientes[array_rand($clientes)];
    $totalVenta = rand(500, 15000);
    
    $stmt = $pdo->prepare("INSERT INTO venta_tienda (id_cliente, total, metodo_pago, folio, fecha_venta) VALUES (?,?,?,?,NOW())");
    $stmt->execute([
        $cliente['id'],
        $totalVenta,
        $faker->randomElement(['efectivo', 'tarjeta', 'transferencia']),
        strtoupper(substr($faker->bothify("VT###???"), 0, 20))
    ]);
    $ventasIds[] = $pdo->lastInsertId();
}
echo "Ventas creadas: " . count($ventasIds) . "\n";

$detallesCount = 0;
for ($i = 0; $i < 120; $i++) {
    $venta = $ventasIds[array_rand($ventasIds)];
    $producto = $productos[array_rand($productos)];
    $cantidad = rand(1, 5);
    $precio = rand(300, 5000);
    $subtotal = $cantidad * $precio;
    
    $stmt = $pdo->prepare("INSERT INTO detalle_venta (id_venta, id_producto, cantidad, precio_unitario, subtotal) VALUES (?,?,?,?,?)");
    $stmt->execute([$venta, $producto, $cantidad, $precio, $subtotal]);
    $detallesCount++;
}
echo "Detalles de venta creados: $detallesCount\n";

/* =====================
   MOVIMIENTOS CAJA
=====================*/

$pagosExistentes = $pdo->query("SELECT id_pago FROM pagos")->fetchAll(PDO::FETCH_COLUMN);
$usuariosLista = $pdo->query("SELECT id_usuario FROM usuario")->fetchAll(PDO::FETCH_COLUMN);

$movimientos = 0;
$tiposMovimiento = ['prestamo', 'pago', 'venta', 'gasto'];

for ($i = 0; $i < 150; $i++) {
    $usuario = $usuariosLista[array_rand($usuariosLista)];
    $pago = !empty($pagosExistentes) && rand(1, 3) == 1 ? $pagosExistentes[array_rand($pagosExistentes)] : null;
    $tipo = $tiposMovimiento[array_rand($tiposMovimiento)];
    
    $monto = match($tipo) {
        'prestamo' => rand(1000, 20000),
        'pago' => rand(500, 10000),
        'venta' => rand(300, 8000),
        'gasto' => rand(100, 2000),
        default => rand(500, 5000)
    };
    
    $stmt = $pdo->prepare("INSERT INTO movimientos_caja (tipo, monto, descripcion, id_usuario, id_pago, fecha) VALUES (?,?,?,?,?,NOW())");
    $stmt->execute([
        $tipo,
        $monto,
        substr($faker->sentence(3), 0, 255),
        $usuario,
        $pago
    ]);
    $movimientos++;
}
echo "Movimientos de caja creados: $movimientos\n";

// Reactivar llaves foráneas
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "\n========================================\n";
echo " DATABASE SEEDED SUCCESSFULLY! \n";
echo "========================================\n";
echo "\nRESUMEN FINAL:\n";
echo "├─ Empresas: " . count($empresaIds) . "\n";
echo "├─ Roles: 4\n";
echo "├─ Permisos: " . count($permisos) . "\n";
echo "├─ Usuarios: " . count($todosUsuarios) . "\n";
echo "├─ Clientes: " . count($clientes) . "\n";
echo "├─ Avales: " . count($avales) . "\n";
echo "├─ Prendas: " . count($prendas) . "\n";
echo "├─ Tasas: " . count($tasas) . "\n";
echo "├─ Empeños: " . count($empenos) . "\n";
echo "├─ Amortizaciones: $amortizacionesTotales\n";
echo "├─ Pagos: $pagosRegistrados\n";
echo "├─ Productos tienda: " . count($productos) . "\n";
echo "├─ Ventas: " . count($ventasIds) . "\n";
echo "├─ Detalle ventas: $detallesCount\n";
echo "└─ Movimientos caja: $movimientos\n";
echo "\nCREDENCIALES DE ACCESO:\n";
foreach ($empresas as $index => $empresa) {
    $email = strtolower(str_replace(' ', '', $empresa['nombre_comercial'])) . '@admin.com';
    echo "├─ {$empresa['nombre_comercial']}: $email / 123456\n";
}
echo "\nCREDENCIALES CLIENTES DE PRUEBA:\n";
for ($i = 0; $i < 5 && $i < count($clientes); $i++) {
    if (isset($clientes[$i]) && isset($clientesUsuarios[$i])) {
        echo "├─ {$clientes[$i]['nombre']} {$clientes[$i]['apellido']}: {$clientesUsuarios[$i]['email']} / 123456\n";
    }
}