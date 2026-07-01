<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\WithFaker;

class ImportarDatosSeeder extends Seeder
{
    use WithFaker;

    public function run(): void
    {
       
        $faker = $this->faker;

        // Limpiar tablas existentes
        $tables = [
            'movimientos_caja', 'detalle_venta', 'venta_tienda', 'apartados',
            'pagos', 'amortizacion', 'empeno', 'producto_tienda', 'imagen_prenda',
            'prendas', 'documento_aval', 'documento_cliente', 'direcciones',
            'metodo_pago', 'clientes', 'aval', 'rol_permiso', 'permisos',
            'tasas_interes', 'usuario', 'rol', 'empresa'
        ];

        
        foreach ($tables as $table) {
            try {
                DB::table($table)->truncate();
                $this->command->info("✅ Tabla {$table} limpiada");
            } catch (\Exception $e) {
                $this->command->warn("⚠️ No se pudo limpiar {$table}: " . $e->getMessage());
            }
        }
       

        $this->command->info("\n");

        // ===================== EMPRESAS =====================
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
            $id = DB::table('empresa')->insertGetId([
                'nombre' => substr($empresa['nombre'], 0, 100),
                'nombre_comercial' => substr($empresa['nombre_comercial'], 0, 100),
                'rfc' => substr($empresa['rfc'], 0, 13),
                'telefono' => substr($empresa['telefono'], 0, 20),
                'email' => substr($empresa['email'], 0, 100),
                'direccion' => substr($empresa['direccion'], 0, 255),
                'ciudad' => substr($empresa['ciudad'], 0, 100),
                'estado' => substr($empresa['estado'], 0, 100),
                'codigo_postal' => substr($empresa['codigo_postal'], 0, 10),
                'activo' => 1,
                'fecha_registro' => now()
            ]);
            $empresaIds[] = $id;
        }
        $this->command->info("✅ Empresas creadas: " . count($empresaIds));

        // ===================== ROLES =====================
        $rolesBase = ["Administrador", "Gerente", "Cajero", "Cliente"];
        $rolIds = [];

        foreach ($empresaIds as $empresaId) {
            foreach ($rolesBase as $nivel => $nombre) {
                $descripcion = match($nombre) {
                    'Administrador' => 'Acceso total al sistema',
                    'Gerente' => 'Gestión de clientes y empeños',
                    'Cajero' => 'Solo ventas y pagos',
                    'Cliente' => 'Portal de clientes',
                    default => 'Rol del sistema'
                };

                $id = DB::table('rol')->insertGetId([
                    'id_empresa' => $empresaId,
                    'nombre' => $nombre,
                    'descripcion' => substr($descripcion . " - " . $faker->sentence(2), 0, 255),
                    'nivel' => $nivel + 1
                ]);

                if (!isset($rolIds[$empresaId])) {
                    $rolIds[$empresaId] = [];
                }
                $rolIds[$empresaId][$nombre] = $id;
            }
        }
        $this->command->info("✅ Roles creados: " . (count($empresaIds) * count($rolesBase)));

        // ===================== PERMISOS =====================
        $permisosBase = [
            "ver_dashboard", "ver_clientes", "crear_clientes", "editar_clientes", "eliminar_clientes",
            "ver_empenos", "crear_empenos", "editar_empenos", "cancelar_empenos",
            "ver_pagos", "registrar_pagos",
            "ver_tienda", "crear_productos", "editar_productos",
            "ver_caja", "registrar_movimientos",
            "ver_reportes"
        ];

        $permisoIds = [];

        foreach ($empresaIds as $empresaId) {
            foreach ($permisosBase as $permiso) {
                $modulo = match(true) {
                    str_contains($permiso, 'clientes') => 'clientes',
                    str_contains($permiso, 'empenos') => 'empenos',
                    str_contains($permiso, 'pagos') => 'pagos',
                    str_contains($permiso, 'tienda') || str_contains($permiso, 'productos') => 'tienda',
                    str_contains($permiso, 'caja') => 'caja',
                    str_contains($permiso, 'reportes') => 'reportes',
                    str_contains($permiso, 'dashboard') => 'dashboard',
                    default => 'general'
                };

                $descripcion = "Permiso para " . str_replace('_', ' ', $permiso);

                $id = DB::table('permisos')->insertGetId([
                    'id_empresa' => $empresaId,
                    'nombre' => $permiso,
                    'descripcion' => substr($descripcion, 0, 255),
                    'modulo' => $modulo,
                    'estado' => 'activo'
                ]);

                if (!isset($permisoIds[$empresaId])) {
                    $permisoIds[$empresaId] = [];
                }
                $permisoIds[$empresaId][$permiso] = $id;
            }
        }
        $this->command->info("✅ Permisos creados: " . (count($empresaIds) * count($permisosBase)));

        // ===================== ROL_PERMISO =====================
        foreach ($empresaIds as $empresaId) {
            // Administrador: todos los permisos
            $adminRolId = $rolIds[$empresaId]['Administrador'];
            foreach ($permisoIds[$empresaId] as $permisoId) {
                DB::table('rol_permiso')->insert([
                    'id_empresa' => $empresaId,
                    'id_rol' => $adminRolId,
                    'id_permiso' => $permisoId,
                    'permitido' => 1
                ]);
            }

            // Gerente: permisos limitados
            $gerenteRolId = $rolIds[$empresaId]['Gerente'];
            $permisosGerente = ['ver_clientes', 'crear_clientes', 'editar_clientes', 'ver_empenos', 'crear_empenos',
                                'ver_pagos', 'registrar_pagos', 'ver_tienda', 'ver_reportes'];
            foreach ($permisosGerente as $permiso) {
                if (isset($permisoIds[$empresaId][$permiso])) {
                    DB::table('rol_permiso')->insert([
                        'id_empresa' => $empresaId,
                        'id_rol' => $gerenteRolId,
                        'id_permiso' => $permisoIds[$empresaId][$permiso],
                        'permitido' => 1
                    ]);
                }
            }

            // Cajero: pagos y caja
            $cajeroRolId = $rolIds[$empresaId]['Cajero'];
            $permisosCajero = ['ver_pagos', 'registrar_pagos', 'ver_caja', 'registrar_movimientos'];
            foreach ($permisosCajero as $permiso) {
                if (isset($permisoIds[$empresaId][$permiso])) {
                    DB::table('rol_permiso')->insert([
                        'id_empresa' => $empresaId,
                        'id_rol' => $cajeroRolId,
                        'id_permiso' => $permisoIds[$empresaId][$permiso],
                        'permitido' => 1
                    ]);
                }
            }

            // Cliente: permisos básicos
            $clienteRolId = $rolIds[$empresaId]['Cliente'];
            $permisosCliente = ['ver_dashboard', 'ver_clientes', 'ver_empenos', 'ver_pagos', 'ver_tienda'];
            foreach ($permisosCliente as $permiso) {
                if (isset($permisoIds[$empresaId][$permiso])) {
                    DB::table('rol_permiso')->insert([
                        'id_empresa' => $empresaId,
                        'id_rol' => $clienteRolId,
                        'id_permiso' => $permisoIds[$empresaId][$permiso],
                        'permitido' => 1
                    ]);
                }
            }
        }
        $this->command->info("✅ Permisos asignados a roles");

        // ===================== USUARIOS =====================
        $todosUsuarios = [];
        $clientesUsuarios = [];

        foreach ($empresaIds as $empresaId) {
            // Administrador de la empresa
            $adminRolId = $rolIds[$empresaId]['Administrador'];
            $email = strtolower(str_replace(' ', '', $empresas[array_search($empresaId, $empresaIds)]['nombre_comercial'])) . '@admin.com';

            $id = DB::table('usuario')->insertGetId([
                'id_rol' => $adminRolId,
                'id_empresa' => $empresaId,
                'nombre' => substr("Admin " . $empresas[array_search($empresaId, $empresaIds)]['nombre_comercial'], 0, 100),
                'correo' => substr($email, 0, 100),
                'contrasena' => Hash::make('123456'),
                'telefono' => substr($faker->phoneNumber(), 0, 20),
                'activo' => 1,
                'fecha_registro' => now()
            ]);
            $todosUsuarios[] = $id;

            // Gerentes (2 por empresa)
            $gerenteRolId = $rolIds[$empresaId]['Gerente'];
            for ($i = 0; $i < 2; $i++) {
                $id = DB::table('usuario')->insertGetId([
                    'id_rol' => $gerenteRolId,
                    'id_empresa' => $empresaId,
                    'nombre' => substr($faker->name(), 0, 100),
                    'correo' => substr($faker->unique()->safeEmail(), 0, 100),
                    'contrasena' => Hash::make('123456'),
                    'telefono' => substr($faker->phoneNumber(), 0, 20),
                    'activo' => 1,
                    'fecha_registro' => now()
                ]);
                $todosUsuarios[] = $id;
            }

            // Cajeros (3 por empresa)
            $cajeroRolId = $rolIds[$empresaId]['Cajero'];
            for ($i = 0; $i < 3; $i++) {
                $id = DB::table('usuario')->insertGetId([
                    'id_rol' => $cajeroRolId,
                    'id_empresa' => $empresaId,
                    'nombre' => substr($faker->name(), 0, 100),
                    'correo' => substr($faker->unique()->safeEmail(), 0, 100),
                    'contrasena' => Hash::make('123456'),
                    'telefono' => substr($faker->phoneNumber(), 0, 20),
                    'activo' => 1,
                    'fecha_registro' => now()
                ]);
                $todosUsuarios[] = $id;
            }

            // Clientes (15 por empresa)
            $clienteRolId = $rolIds[$empresaId]['Cliente'];
            for ($i = 0; $i < 15; $i++) {
                $nombre = $faker->firstName();
                $apellido = $faker->lastName();
                $email = $faker->unique()->safeEmail();

                $id = DB::table('usuario')->insertGetId([
                    'id_rol' => $clienteRolId,
                    'id_empresa' => $empresaId,
                    'nombre' => substr("$nombre $apellido", 0, 100),
                    'correo' => substr($email, 0, 100),
                    'contrasena' => Hash::make('123456'),
                    'telefono' => substr($faker->phoneNumber(), 0, 20),
                    'activo' => 1,
                    'fecha_registro' => now()
                ]);
                $todosUsuarios[] = $id;
                $clientesUsuarios[] = [
                    'id_usuario' => $id,
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'email' => $email,
                    'id_empresa' => $empresaId
                ];
            }
        }
        $this->command->info("✅ Usuarios creados: " . count($todosUsuarios));

        // ===================== CLIENTES =====================
        $clientes = [];
        foreach ($clientesUsuarios as $clienteUsuario) {
            $id = DB::table('clientes')->insertGetId([
                'id_usuario' => $clienteUsuario['id_usuario'],
                'id_empresa' => $clienteUsuario['id_empresa'],
                'nombre' => substr($clienteUsuario['nombre'], 0, 100),
                'apellido' => substr($clienteUsuario['apellido'], 0, 100),
                'telefono' => substr($faker->phoneNumber(), 0, 20),
                'correo' => substr($clienteUsuario['email'], 0, 100),
                'direccion' => substr($faker->streetAddress(), 0, 255),
                'codigo_postal' => substr($faker->postcode(), 0, 10),
                'ciudad' => substr($faker->city(), 0, 100),
                'estado' => substr($faker->state(), 0, 100),
                'fecha_registro' => now(),
                'activo' => 1
            ]);
            $clientes[] = [
                'id' => $id,
                'id_usuario' => $clienteUsuario['id_usuario'],
                'id_empresa' => $clienteUsuario['id_empresa'],
                'nombre' => $clienteUsuario['nombre'],
                'apellido' => $clienteUsuario['apellido']
            ];
        }
        $this->command->info("✅ Clientes creados: " . count($clientes));

        // ===================== AVALES =====================
        $avales = [];
        for ($i = 0; $i < 60; $i++) {
            $idEmpresa = $empresaIds[array_rand($empresaIds)];

            $id = DB::table('aval')->insertGetId([
                'id_empresa' => $idEmpresa,
                'nombre' => substr($faker->firstName(), 0, 100),
                'apellido' => substr($faker->lastName(), 0, 100),
                'telefono' => substr($faker->phoneNumber(), 0, 20),
                'direccion' => substr($faker->address(), 0, 255),
                'email' => substr($faker->safeEmail(), 0, 100)
            ]);
            $avales[] = [
                'id' => $id,
                'id_empresa' => $idEmpresa
            ];
        }
        $this->command->info("✅ Avales creados: " . count($avales));

        // ===================== PRENDAS =====================
        $tipos = ["Joyería", "Electrónica", "Relojes", "Herramientas", "Instrumentos", "Otros"];
        $materiales = ["oro", "plata", "acero", "platino", "madera", "plástico"];
        $estadosPrenda = ["Disponible", "En Empeño", "Vendido", "Vencido", "Apartado"];

        $prendas = [];
        for ($i = 0; $i < 200; $i++) {
            $idEmpresa = $empresaIds[array_rand($empresaIds)];
            $tipo = $tipos[array_rand($tipos)];
            $material = $materiales[array_rand($materiales)];
            $estadoPrenda = $estadosPrenda[array_rand($estadosPrenda)];

            $descripcion = "Artículo de $tipo hecho de $material, en buen estado.";
            $valorEstimado = rand(500, 50000);

            $id = DB::table('prendas')->insertGetId([
                'id_empresa' => $idEmpresa,
                'descripcion' => substr($descripcion, 0, 255),
                'tipo' => $tipo,
                'material' => substr($material, 0, 100),
                'peso_gramos' => rand(10, 500),
                'valor_estimado' => $valorEstimado,
                'codigo_barras' => substr($faker->ean13(), 0, 50),
                'estado' => $estadoPrenda,
                'fecha_registro' => now()
            ]);
            $prendas[] = [
                'id' => $id,
                'id_empresa' => $idEmpresa,
                'valor_estimado' => $valorEstimado,
                'estado' => $estadoPrenda
            ];
        }
        $this->command->info("✅ Prendas creadas: " . count($prendas));

        // ===================== TASAS INTERÉS =====================
        $tasasInteres = [
            ['nombre' => 'Basico', 'porcentaje' => 5.00, 'plazo_dias' => 15],
            ['nombre' => 'Estandar', 'porcentaje' => 8.00, 'plazo_dias' => 30],
            ['nombre' => 'Premium', 'porcentaje' => 10.00, 'plazo_dias' => 45],
            ['nombre' => 'Extendido', 'porcentaje' => 12.00, 'plazo_dias' => 60],
            ['nombre' => 'Flexible', 'porcentaje' => 15.00, 'plazo_dias' => 90]
        ];

        $tasas = [];
        foreach ($tasasInteres as $tasa) {
            $id = DB::table('tasas_interes')->insertGetId([
                'nombre' => $tasa['nombre'],
                'porcentaje' => $tasa['porcentaje'],
                'plazo_dias' => $tasa['plazo_dias'],
                'activo' => 1
            ]);
            $tasas[] = $id;
        }
        $this->command->info("✅ Tasas de interés creadas: " . count($tasas));

        // ===================== EMPEÑOS, AMORTIZACIONES Y PAGOS =====================
        $empenos = [];
        $amortizacionesTotales = 0;
        $pagosRegistrados = 0;

        for ($i = 0; $i < 150; $i++) {
            $cliente = $clientes[array_rand($clientes)];
            $prenda = $prendas[array_rand($prendas)];
            $aval = $avales[array_rand($avales)];
            $idTasa = $tasas[array_rand($tasas)];

            $tasa = DB::table('tasas_interes')->where('id_tasa', $idTasa)->first();

            $montoPrestado = rand(500, 15000);
            $interesPorcentaje = $tasa->porcentaje;
            $plazoDias = $tasa->plazo_dias;

            $interesMonto = $montoPrestado * ($interesPorcentaje / 100);
            $ivaInteres = $interesMonto * 0.16;
            $montoTotal = $montoPrestado + $interesMonto + $ivaInteres;

            $randomEstado = rand(1, 10);
            if ($randomEstado <= 4) {
                $fechaEmpeno = $faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d');
                $fechaVencimiento = (new \DateTime($fechaEmpeno))->modify("+$plazoDias days")->format('Y-m-d');
                $estado = 'activo';
            } elseif ($randomEstado <= 7) {
                $fechaEmpeno = $faker->dateTimeBetween('-90 days', '-30 days')->format('Y-m-d');
                $fechaVencimiento = (new \DateTime($fechaEmpeno))->modify("+$plazoDias days")->format('Y-m-d');
                $estado = 'pagado';
            } else {
                $fechaEmpeno = $faker->dateTimeBetween('-60 days', '-15 days')->format('Y-m-d');
                $fechaVencimiento = (new \DateTime($fechaEmpeno))->modify("+$plazoDias days")->format('Y-m-d');
                $estado = 'vencido';
            }

            $idEmpeno = DB::table('empeno')->insertGetId([
                'id_empresa' => $cliente['id_empresa'],
                'id_cliente' => $cliente['id'],
                'id_prenda' => $prenda['id'],
                'id_aval' => $aval['id'],
                'id_tasa' => $idTasa,
                'fecha_empeno' => $fechaEmpeno,
                'monto_prestado' => $montoPrestado,
                'intereses' => $interesPorcentaje,
                'iva_porcentaje' => 16.00,
                'fecha_vencimiento' => $fechaVencimiento,
                'estado' => $estado,
                'folio' => strtoupper(substr($faker->bothify("EMP###???"), 0, 20))
            ]);

            $fechaPagoProgramado = (new \DateTime($fechaEmpeno))->modify("+$plazoDias days")->format('Y-m-d');

            $idAmortizacion = DB::table('amortizacion')->insertGetId([
                'id_empeno' => $idEmpeno,
                'saldo_inicial' => $montoTotal,
                'saldo_final' => $montoTotal,
                'numero_pago' => 1,
                'fecha_pago_programado' => $fechaPagoProgramado,
                'capital' => $montoPrestado,
                'interes' => $interesMonto,
                'iva_interes' => $ivaInteres,
                'monto_total' => $montoTotal,
                'monto_pagado' => 0,
                'estado' => 'pendiente'
            ]);
            $amortizacionesTotales++;

            if ($estado == 'pagado') {
                $fechaPago = $faker->dateTimeBetween($fechaEmpeno, $fechaVencimiento)->format('Y-m-d');

                DB::table('pagos')->insert([
                    'id_empeno' => $idEmpeno,
                    'id_amortizacion' => $idAmortizacion,
                    'fecha_pago' => $fechaPago,
                    'capital_pagado' => $montoPrestado,
                    'interes_pagado' => $interesMonto,
                    'iva_pagado' => $ivaInteres,
                    'monto_total' => $montoTotal,
                    'tipo_pago' => 'liquidacion',
                    'metodo_pago' => $faker->randomElement(['efectivo', 'transferencia', 'tarjeta']),
                    'fecha_registro' => now()
                ]);
                $pagosRegistrados++;

                DB::table('amortizacion')
                    ->where('id_amortizacion', $idAmortizacion)
                    ->update([
                        'monto_pagado' => $montoTotal,
                        'saldo_final' => 0,
                        'estado' => 'pagado',
                        'fecha_pago_real' => $fechaPago
                    ]);

            } elseif ($estado == 'activo') {
                $numPagos = rand(0, 2);
                $montoRestante = $montoTotal;
                $totalPagado = 0;

                for ($p = 1; $p <= $numPagos; $p++) {
                    if ($montoRestante <= 0) break;

                    $porcentajePago = rand(10, 40) / 100;
                    if ($p == $numPagos) {
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

                    DB::table('pagos')->insert([
                        'id_empeno' => $idEmpeno,
                        'id_amortizacion' => $idAmortizacion,
                        'fecha_pago' => $fechaPago,
                        'capital_pagado' => $capitalPagado,
                        'interes_pagado' => $interesPagado,
                        'iva_pagado' => $ivaPagado,
                        'monto_total' => $montoPagado,
                        'tipo_pago' => 'abono',
                        'metodo_pago' => $faker->randomElement(['efectivo', 'transferencia', 'tarjeta']),
                        'fecha_registro' => now()
                    ]);
                    $pagosRegistrados++;

                    $totalPagado += $montoPagado;
                    $montoRestante = $montoTotal - $totalPagado;
                }

                if ($totalPagado > 0) {
                    DB::table('amortizacion')
                        ->where('id_amortizacion', $idAmortizacion)
                        ->update([
                            'monto_pagado' => $totalPagado,
                            'saldo_final' => $montoRestante
                        ]);
                }

                $empenos[] = ['id' => $idEmpeno, 'estado' => $estado];
            } else {
                $empenos[] = ['id' => $idEmpeno, 'estado' => $estado];
            }
        }

        $this->command->info("✅ Empeños creados: " . count($empenos));
        $this->command->info("✅ Amortizaciones creadas: $amortizacionesTotales");
        $this->command->info("✅ Pagos registrados: $pagosRegistrados");

        // ===================== PRODUCTOS TIENDA =====================
        $productos = [];
        for ($i = 0; $i < 80; $i++) {
            $prenda = $prendas[array_rand($prendas)];
            $precioVenta = round($prenda['valor_estimado'] * (rand(70, 130) / 100), 2);
            $estadosProducto = ['Nuevo', 'Como nuevo', 'Buen estado', 'Aceptable'];

            $id = DB::table('producto_tienda')->insertGetId([
                'id_empresa' => $prenda['id_empresa'],
                'id_prenda' => $prenda['id'],
                'nombre' => substr($faker->words(2, true), 0, 100),
                'descripcion' => substr($faker->sentence(), 0, 255),
                'precio' => $precioVenta,
                'stock' => rand(1, 25),
                'estado_producto' => $estadosProducto[array_rand($estadosProducto)],
                'visible' => 1,
                'destacado' => rand(0, 1),
                'fecha_publicacion' => now()->toDateString()
            ]);
            $productos[] = $id;
        }
        $this->command->info("✅ Productos tienda creados: " . count($productos));

        // ===================== VENTAS Y DETALLES =====================
        $ventasIds = [];
        for ($i = 0; $i < 60; $i++) {
            $cliente = $clientes[array_rand($clientes)];
            $totalVenta = rand(500, 15000);

            $id = DB::table('venta_tienda')->insertGetId([
                'id_cliente' => $cliente['id'],
                'total' => $totalVenta,
                'metodo_pago' => $faker->randomElement(['efectivo', 'tarjeta', 'transferencia']),
                'estado' => 'completada',
                'folio' => strtoupper(substr($faker->bothify("VT###???"), 0, 20)),
                'fecha_venta' => now()
            ]);
            $ventasIds[] = $id;
        }
        $this->command->info("✅ Ventas creadas: " . count($ventasIds));

        $detallesCount = 0;
        for ($i = 0; $i < 150; $i++) {
            $venta = $ventasIds[array_rand($ventasIds)];
            $producto = $productos[array_rand($productos)];
            $cantidad = rand(1, 5);
            $precio = rand(300, 5000);
            $subtotal = $cantidad * $precio;

            DB::table('detalle_venta')->insert([
                'id_venta' => $venta,
                'id_producto' => $producto,
                'cantidad' => $cantidad,
                'precio_unitario' => $precio,
                'subtotal' => $subtotal
            ]);
            $detallesCount++;
        }
        $this->command->info("✅ Detalles de venta creados: $detallesCount");

        // ===================== MOVIMIENTOS CAJA =====================
        $pagosExistentes = DB::table('pagos')->pluck('id_pago')->toArray();
        $usuariosLista = DB::table('usuario')->pluck('id_usuario')->toArray();

        $movimientos = 0;
        $tiposMovimiento = ['prestamo', 'pago', 'venta', 'gasto'];

        for ($i = 0; $i < 200; $i++) {
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

            DB::table('movimientos_caja')->insert([
                'tipo' => $tipo,
                'monto' => $monto,
                'descripcion' => substr($faker->sentence(3), 0, 255),
                'id_usuario' => $usuario,
                'id_pago' => $pago,
                'fecha' => now()
            ]);
            $movimientos++;
        }
        $this->command->info("✅ Movimientos de caja creados: $movimientos");

        // ===================== RESUMEN FINAL =====================
        $this->command->info("\n========================================");
        $this->command->info(" DATABASE SEEDED SUCCESSFULLY!");
        $this->command->info("========================================");
        $this->command->info("\nRESUMEN FINAL:");
        $this->command->info("├─ Empresas: " . count($empresaIds));
        $this->command->info("├─ Roles por empresa: " . count($rolesBase));
        $this->command->info("├─ Permisos por empresa: " . count($permisosBase));
        $this->command->info("├─ Usuarios: " . count($todosUsuarios));
        $this->command->info("├─ Clientes: " . count($clientes));
        $this->command->info("├─ Avales: " . count($avales));
        $this->command->info("├─ Prendas: " . count($prendas));
        $this->command->info("├─ Tasas: " . count($tasas));
        $this->command->info("├─ Empeños: " . count($empenos));
        $this->command->info("├─ Amortizaciones: $amortizacionesTotales");
        $this->command->info("├─ Pagos: $pagosRegistrados");
        $this->command->info("├─ Productos tienda: " . count($productos));
        $this->command->info("├─ Ventas: " . count($ventasIds));
        $this->command->info("├─ Detalle ventas: $detallesCount");
        $this->command->info("└─ Movimientos caja: $movimientos");
        $this->command->info("\n📌 CREDENCIALES DE ACCESO:");
        foreach ($empresas as $index => $empresa) {
            $email = strtolower(str_replace(' ', '', $empresa['nombre_comercial'])) . '@admin.com';
            $this->command->info("├─ {$empresa['nombre_comercial']}: $email / 123456");
        }
    }
}