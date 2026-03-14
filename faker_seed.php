<?php

require 'vendor/autoload.php';

$faker = Faker\Factory::create('es_MX');

$pdo = new PDO("mysql:host=localhost;dbname=ophelina_v3", "root", "diego2040");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Seeding database...\n";

/* =====================
   ROLES
=====================*/

$roles = ["Administrador","Gerente","Cajero"];

foreach ($roles as $i => $rol) {
    $pdo->prepare("INSERT INTO rol(nombre,descripcion,nivel) VALUES (?,?,?)")
        ->execute([$rol,$faker->sentence(),$i+1]);
}

/* =====================
   PERMISOS
=====================*/

$permisos = [
"ver_clientes","crear_clientes","editar_clientes","eliminar_clientes",
"ver_empenos","crear_empenos","editar_empenos","cancelar_empenos",
"ver_pagos","registrar_pagos",
"ver_tienda","crear_productos","editar_productos",
"ver_caja","registrar_movimientos",
"ver_reportes"
];

foreach($permisos as $perm){
    $pdo->prepare("INSERT INTO permisos(nombre,descripcion,modulo) VALUES (?,?,?)")
        ->execute([$perm,$faker->sentence(),"general"]);
}

/* =====================
   USUARIOS
=====================*/

for ($i=0; $i<10; $i++) {

    $pdo->prepare("INSERT INTO usuario
    (id_rol,nombre,correo,contrasena,telefono)
    VALUES (?,?,?,?,?)")
    ->execute([
        rand(1,3),
        $faker->name(),
        $faker->unique()->safeEmail(),
        password_hash("123456", PASSWORD_BCRYPT),
        $faker->phoneNumber()
    ]);
}

/* =====================
   CLIENTES
=====================*/

for ($i=0; $i<50; $i++) {

    $pdo->prepare("INSERT INTO clientes
    (id_usuario,nombre,apellido,telefono,correo,direccion,codigo_postal,ciudad,estado,fecha_registro)
    VALUES (?,?,?,?,?,?,?,?,?,NOW())")
    ->execute([
        rand(1,10),
        $faker->firstName(),
        $faker->lastName(),
        $faker->phoneNumber(),
        $faker->safeEmail(),
        $faker->streetAddress(),
        $faker->postcode(),
        $faker->city(),
        $faker->state()
    ]);
}

/* =====================
   AVALES
=====================*/

for ($i=0; $i<20; $i++) {

    $pdo->prepare("INSERT INTO aval
    (nombre,apellido,telefono,direccion,email)
    VALUES (?,?,?,?,?)")
    ->execute([
        $faker->firstName(),
        $faker->lastName(),
        $faker->phoneNumber(),
        $faker->address(),
        $faker->safeEmail()
    ]);
}

/* =====================
   PRENDAS
=====================*/

$tipos = ["Joyería","Electrónica","Relojes","Herramientas","Instrumentos","Otros"];
$materiales = ["oro", "plata", "acero", "platino", "madera", "plástico"];

$productosJoyería = ["collar", "anillo", "pulsera", "aretes"];
$productosElectrónica = ["laptop", "tablet", "smartphone", "televisor"];
$productosRelojes = ["reloj de pulsera", "reloj de bolsillo"];
$productosHerramientas = ["taladro", "destornillador", "sierra eléctrica"];
$productosInstrumentos = ["guitarra", "violín", "teclado"];
$productosOtros = ["mochila", "bolso", "libro"];

for ($i=0; $i<80; $i++) {

    $tipo = $tipos[array_rand($tipos)];

    switch($tipo){
        case "Joyería": $nombre = $productosJoyería[array_rand($productosJoyería)]; break;
        case "Electrónica": $nombre = $productosElectrónica[array_rand($productosElectrónica)]; break;
        case "Relojes": $nombre = $productosRelojes[array_rand($productosRelojes)]; break;
        case "Herramientas": $nombre = $productosHerramientas[array_rand($productosHerramientas)]; break;
        case "Instrumentos": $nombre = $productosInstrumentos[array_rand($productosInstrumentos)]; break;
        default: $nombre = $productosOtros[array_rand($productosOtros)]; break;
    }

    $descripcion = "Un(a) $nombre hecho(a) de ".$materiales[array_rand($materiales)].", en excelente estado.";

    $pdo->prepare("INSERT INTO prendas
    (descripcion,tipo,material,peso_gramos,valor_estimado,codigo_barras)
    VALUES (?,?,?,?,?,?)")
    ->execute([
        $descripcion,
        $tipo,
        $materiales[array_rand($materiales)],
        rand(10,200),
        rand(500,20000),
        $faker->ean13()
    ]);
}

/* =====================
   TASAS
=====================*/

for ($i=1; $i<=5; $i++) {

    $pdo->prepare("INSERT INTO tasas_interes
    (nombre,porcentaje,plazo_dias)
    VALUES (?,?,?)")
    ->execute([
        "Plan $i",
        rand(5,18),
        rand(15,60)
    ]);
}

/* =====================
   EMPEÑOS
=====================*/

$productosEmpeno = ["collar de oro","reloj de plata","laptop","anillo de diamante","taladro eléctrico","guitarra acústica","tablet","televisor LED"];

for ($i=0; $i<40; $i++) {

    // Generar estado y fechas coherentes
    if ($i % 5 === 0) { // 1 de cada 5 vencido
        $fechaEmpeno = $faker->dateTimeBetween('-60 days', '-30 days')->format('Y-m-d');
        $fechaVencimiento = (clone new DateTime($fechaEmpeno))->modify('+15 days')->format('Y-m-d'); 
        $estado = 'vencido';
    } else { // activo
        $fechaEmpeno = $faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d');
        $fechaVencimiento = (clone new DateTime($fechaEmpeno))->modify('+15 days')->format('Y-m-d');
        $estado = 'activo';
    }

    $nombreEmpeno = "Empeño de " . $productosEmpeno[array_rand($productosEmpeno)];
    $descripcionEmpeno = "Un(a) " . strtolower($productosEmpeno[array_rand($productosEmpeno)]) . " en buen estado para empeño.";

    $pdo->prepare("INSERT INTO empeno
    (id_cliente,id_prenda,id_aval,id_tasa,fecha_empeno,monto_prestado,intereses,fecha_vencimiento,folio,estado)
    VALUES (?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        rand(1,50),
        rand(1,80),
        rand(1,20),
        rand(1,5),
        $fechaEmpeno,
        rand(500,5000),
        rand(5,20),
        $fechaVencimiento,
        strtoupper($faker->bothify("EMP###??")),
        $estado
    ]);
}

/* =====================
   AMORTIZACION
=====================*/

for ($i=0; $i<120; $i++) {

    $pdo->prepare("INSERT INTO amortizacion
    (id_empeno,saldo_inicial,saldo_final,numero_pago,fecha_pago_programado,capital,interes,iva_interes,monto_total)
    VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([
        rand(1,40),
        rand(2000,5000),
        rand(1000,2000),
        rand(1,6),
        $faker->date(),
        rand(200,800),
        rand(50,200),
        rand(10,30),
        rand(300,900)
    ]);
}

/* =====================
   PAGOS
=====================*/

for ($i=0; $i<80; $i++) {

    // Fechas coherentes con el empeño
    $fechaPago = $faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d');
    $montoTotal = rand(200, 900);

    $pdo->prepare("INSERT INTO pagos
    (id_empeno,id_amortizacion,fecha_pago,capital_pagado,interes_pagado,iva_pagado,monto_total,tipo_pago,metodo_pago)
    VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([
        rand(1,40),
        rand(1,120),
        $fechaPago,
        rand(200,800),
        rand(50,200),
        rand(10,30),
        $montoTotal,
        $faker->randomElement(['interes','abono','liquidacion']),
        $faker->randomElement(['efectivo','transferencia','tarjeta'])
    ]);
}

/* =====================
   PRODUCTOS TIENDA
=====================*/

for ($i=0; $i<30; $i++) {

    $pdo->prepare("INSERT INTO producto_tienda
    (id_prenda,nombre,descripcion,precio,stock)
    VALUES (?,?,?,?,?)")
    ->execute([
        rand(1,80),
        $faker->word(),
        $faker->sentence(),
        rand(300,15000),
        rand(1,5)
    ]);
}

/* =====================
   VENTAS
=====================*/

for ($i=0; $i<25; $i++) {

    $pdo->prepare("INSERT INTO venta_tienda
    (id_cliente,total,metodo_pago,folio)
    VALUES (?,?,?,?)")
    ->execute([
        rand(1,50),
        rand(500,8000),
        $faker->randomElement(['efectivo','tarjeta','transferencia']),
        strtoupper($faker->bothify("VT###??"))
    ]);
}

/* =====================
   DETALLE VENTA
=====================*/

for ($i=0; $i<40; $i++) {

    $precio = rand(300,2000);

    $pdo->prepare("INSERT INTO detalle_venta
    (id_venta,id_producto,cantidad,precio_unitario,subtotal)
    VALUES (?,?,?,?,?)")
    ->execute([
        rand(1,25),
        rand(1,30),
        1,
        $precio,
        $precio
    ]);
}

/* =====================
   MOVIMIENTOS CAJA
=====================*/

for ($i=0; $i<80; $i++) {

    $pdo->prepare("INSERT INTO movimientos_caja
    (tipo,monto,descripcion,id_usuario)
    VALUES (?,?,?,?)")
    ->execute([
        $faker->randomElement(['prestamo','pago','venta','gasto']),
        rand(200,5000),
        $faker->sentence(),
        rand(1,10)
    ]);
}

echo "Database seeded successfully 🚀";