#!/bin/bash

echo "=== CORRIGIENDO ÍNDICES EN MIGRACIONES ==="

# 1. create_usuario_table.php
sed -i 's/index("id_rol")/index("usuario_id_rol_idx")/g' database/migrations/2026_04_14_002201_create_usuario_table.php
sed -i 's/index("id_empresa")/index("usuario_id_empresa_idx")/g' database/migrations/2026_04_14_002201_create_usuario_table.php

# 2. create_permisos_table.php
sed -i 's/index("id_empresa")/index("permisos_id_empresa_idx")/g' database/migrations/2026_04_14_002201_create_permisos_table.php

# 3. create_metodo_pago_table.php
sed -i 's/index("id_cliente")/index("metodo_pago_id_cliente_idx")/g' database/migrations/2026_04_14_002201_create_metodo_pago_table.php

# 4. create_prendas_table.php
sed -i 's/index("id_empresa")/index("prendas_id_empresa_idx")/g' database/migrations/2026_04_14_002201_create_prendas_table.php

# 5. create_amortizacion_table.php
sed -i 's/index("id_empeno")/index("amortizacion_id_empeno_idx")/g' database/migrations/2026_04_14_002201_create_amortizacion_table.php

# 6. create_direcciones_table.php
sed -i 's/index("id_cliente")/index("direcciones_id_cliente_idx")/g' database/migrations/2026_04_14_002201_create_direcciones_table.php

# 7. create_empeno_table.php
sed -i 's/index("id_empresa")/index("empeno_id_empresa_idx")/g' database/migrations/2026_04_14_002201_create_empeno_table.php
sed -i 's/index("id_cliente")/index("empeno_id_cliente_idx")/g' database/migrations/2026_04_14_002201_create_empeno_table.php
sed -i 's/index("id_prenda")/index("empeno_id_prenda_idx")/g' database/migrations/2026_04_14_002201_create_empeno_table.php
sed -i 's/index("id_aval")/index("empeno_id_aval_idx")/g' database/migrations/2026_04_14_002201_create_empeno_table.php
sed -i 's/index("id_tasa")/index("empeno_id_tasa_idx")/g' database/migrations/2026_04_14_002201_create_empeno_table.php

# 8. create_documento_cliente_table.php
sed -i 's/index("id_cliente")/index("documento_cliente_id_cliente_idx")/g' database/migrations/2026_04_14_002201_create_documento_cliente_table.php

# 9. create_apartados_table.php
sed -i 's/index("id_cliente")/index("apartados_id_cliente_idx")/g' database/migrations/2026_04_14_002201_create_apartados_table.php
sed -i 's/index("id_producto")/index("apartados_id_producto_idx")/g' database/migrations/2026_04_14_002201_create_apartados_table.php

# 10. create_imagen_prenda_table.php
sed -i 's/index("id_prenda")/index("imagen_prenda_id_prenda_idx")/g' database/migrations/2026_04_14_002201_create_imagen_prenda_table.php

# 11. create_producto_tienda_table.php
sed -i 's/index("id_empresa")/index("producto_tienda_id_empresa_idx")/g' database/migrations/2026_04_14_002201_create_producto_tienda_table.php
sed -i 's/index("id_prenda")/index("producto_tienda_id_prenda_idx")/g' database/migrations/2026_04_14_002201_create_producto_tienda_table.php

# 12. create_documento_aval_table.php
sed -i 's/index("id_aval")/index("documento_aval_id_aval_idx")/g' database/migrations/2026_04_14_002201_create_documento_aval_table.php

# 13. create_detalle_venta_table.php
sed -i 's/index("id_venta")/index("detalle_venta_id_venta_idx")/g' database/migrations/2026_04_14_002201_create_detalle_venta_table.php
sed -i 's/index("id_producto")/index("detalle_venta_id_producto_idx")/g' database/migrations/2026_04_14_002201_create_detalle_venta_table.php

# 14. create_aval_table.php
sed -i 's/index("id_empresa")/index("aval_id_empresa_idx")/g' database/migrations/2026_04_14_002201_create_aval_table.php

# 15. create_venta_tienda_table.php
sed -i 's/index("id_cliente")/index("venta_tienda_id_cliente_idx")/g' database/migrations/2026_04_14_002201_create_venta_tienda_table.php

# 16. create_movimientos_caja_table.php
sed -i 's/index("id_usuario")/index("movimientos_caja_id_usuario_idx")/g' database/migrations/2026_04_14_002201_create_movimientos_caja_table.php
sed -i 's/index("id_pago")/index("movimientos_caja_id_pago_idx")/g' database/migrations/2026_04_14_002201_create_movimientos_caja_table.php

# 17. create_pagos_table.php
sed -i 's/index("id_empeno")/index("pagos_id_empeno_idx")/g' database/migrations/2026_04_14_002201_create_pagos_table.php
sed -i 's/index("id_amortizacion")/index("pagos_id_amortizacion_idx")/g' database/migrations/2026_04_14_002201_create_pagos_table.php

# 18. create_rol_table.php
sed -i 's/index("id_empresa")/index("rol_id_empresa_idx")/g' database/migrations/2026_04_14_002201_create_rol_table.php

# 19. create_rol_permiso_table.php
sed -i 's/index("id_empresa")/index("rol_permiso_id_empresa_idx")/g' database/migrations/2026_04_14_002201_create_rol_permiso_table.php
sed -i 's/index("id_rol")/index("rol_permiso_id_rol_idx")/g' database/migrations/2026_04_14_002201_create_rol_permiso_table.php
sed -i 's/index("id_permiso")/index("rol_permiso_id_permiso_idx")/g' database/migrations/2026_04_14_002201_create_rol_permiso_table.php

echo "✅ Todos los índices han sido corregidos"