// server.js
const express = require('express');
const Stripe = require('stripe');
const cors = require('cors');
const mysql = require('mysql2/promise');
require('dotenv').config();

const app = express();
const stripe = new Stripe(process.env.STRIPE_SECRET_KEY);

// Configuración de base de datos
const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'ophelina_db'
};

// Middleware
app.use(cors());
app.use(express.json());

// ============================================
// 1. CREAR SESIÓN DE CHECKOUT (Stripe)
// ============================================
app.post('/api/create-checkout-session', async (req, res) => {
  try {
    const { planId, planName, price, empresaId, successUrl, cancelUrl, customerEmail } = req.body;
    
    console.log('Creando sesión para:', { planId, planName, price, empresaId });
    
    const session = await stripe.checkout.sessions.create({
      payment_method_types: ['card', 'oxxo'],
      line_items: [
        {
          price_data: {
            currency: 'mxn',
            product_data: {
              name: `Ophelina - Plan ${planName}`,
              description: `Suscripción mensual. Acceso a todas las funciones del plan ${planName}.`,
            },
            unit_amount: price,
            recurring: {
              interval: 'month',
            },
          },
          quantity: 1,
        },
      ],
      mode: 'subscription',
      success_url: successUrl,
      cancel_url: cancelUrl,
      metadata: {
        plan_id: planId,
        empresa_id: empresaId || 'nueva',
        plan_nombre: planName
      },
      customer_email: customerEmail || undefined,
    });
    
    console.log('Sesión creada:', session.id);
    res.json({ sessionId: session.id });
    
  } catch (error) {
    console.error('Error:', error);
    res.status(500).json({ error: error.message });
  }
});

// ============================================
// 2. VERIFICAR PAGO Y ACTIVAR SUSCRIPCIÓN
// ============================================
app.post('/api/verify-payment', async (req, res) => {
  const { sessionId, empresaId, planId, customerEmail, negocioNombre } = req.body;
  
  console.log('Verificando pago:', { sessionId, empresaId, planId });
  
  if (!sessionId) {
    return res.status(400).json({ success: false, error: 'sessionId requerido' });
  }
  
  try {
    // Consultar el estado de la sesión en Stripe
    const session = await stripe.checkout.sessions.retrieve(sessionId);
    
    if (session.payment_status !== 'paid') {
      return res.status(400).json({ 
        success: false, 
        error: 'El pago no se ha completado',
        payment_status: session.payment_status
      });
    }
    
    // Conectar a BD
    const connection = await mysql.createConnection(dbConfig);
    
    try {
      let finalEmpresaId = empresaId;
      
      // Mapeo de planes a IDs
      const planIdMap = {
        'profesional': 2,
        'premium': 3
      };
      
      const dbPlanId = planIdMap[planId];
      
      if (!dbPlanId) {
        throw new Error(`Plan no reconocido: ${planId}`);
      }
      
      // Si es nueva empresa, crearla
      if (!finalEmpresaId || finalEmpresaId === 'nueva') {
        const email = customerEmail || session.customer_email;
        const nombre = negocioNombre || `Cliente ${email}`;
        
        const [result] = await connection.execute(
          `INSERT INTO empresa (nombre, email, plan_activo, fecha_inicio_plan, fecha_fin_plan, id_plan, fecha_registro) 
           VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), ?, NOW())`,
          [nombre, email, dbPlanId]
        );
        finalEmpresaId = result.insertId;
        console.log('Nueva empresa creada:', finalEmpresaId);
      } else {
        // Actualizar empresa existente
        await connection.execute(
          `UPDATE empresa 
           SET plan_activo = 1, 
               fecha_inicio_plan = CURDATE(), 
               fecha_fin_plan = DATE_ADD(CURDATE(), INTERVAL 1 MONTH),
               id_plan = ?
           WHERE id_empresa = ?`,
          [dbPlanId, finalEmpresaId]
        );
        console.log('Empresa actualizada:', finalEmpresaId);
      }
      
      res.json({
        success: true,
        empresaId: finalEmpresaId,
        message: 'Suscripción activada correctamente'
      });
      
    } finally {
      await connection.end();
    }
    
  } catch (error) {
    console.error('❌ Error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ============================================
// 3. ACTIVAR PLAN FREE
// ============================================
app.post('/api/activate-free-plan', async (req, res) => {
  const { email, nombreNegocio, telefono } = req.body;
  
  if (!email || !nombreNegocio) {
    return res.status(400).json({ success: false, error: 'Faltan datos' });
  }
  
  const connection = await mysql.createConnection(dbConfig);
  
  try {
    const [existing] = await connection.execute(
      'SELECT id_empresa FROM empresa WHERE email = ?',
      [email]
    );
    
    let empresaId;
    
    if (existing.length > 0) {
      empresaId = existing[0].id_empresa;
      await connection.execute(
        `UPDATE empresa 
         SET plan_activo = 1,
             fecha_inicio_plan = CURDATE(),
             fecha_fin_plan = DATE_ADD(CURDATE(), INTERVAL 30 DAY),
             id_plan = (SELECT id_plan FROM planes_saas WHERE clave = 'free')
         WHERE id_empresa = ?`,
        [empresaId]
      );
    } else {
      const [result] = await connection.execute(
        `INSERT INTO empresa (nombre, email, telefono, plan_activo, fecha_inicio_plan, fecha_fin_plan, id_plan, fecha_registro) 
         VALUES (?, ?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 
           (SELECT id_plan FROM planes_saas WHERE clave = 'free'), NOW())`,
        [nombreNegocio, email, telefono || null]
      );
      empresaId = result.insertId;
    }
    
    res.json({
      success: true,
      empresaId: empresaId,
      message: 'Plan Free activado por 30 días'
    });
    
  } catch (error) {
    console.error('Error:', error);
    res.status(500).json({ success: false, error: error.message });
  } finally {
    await connection.end();
  }
});

// ============================================
// 4. VERIFICAR ESTADO DE SUSCRIPCIÓN
// ============================================
app.get('/api/check-subscription/:empresaId', async (req, res) => {
  const { empresaId } = req.params;
  
  const connection = await mysql.createConnection(dbConfig);
  
  try {
    const [rows] = await connection.execute(
      `SELECT e.plan_activo, e.fecha_fin_plan, e.id_plan, p.nombre as plan_nombre
       FROM empresa e
       LEFT JOIN planes_saas p ON e.id_plan = p.id_plan
       WHERE e.id_empresa = ?`,
      [empresaId]
    );
    
    if (rows.length === 0) {
      return res.json({ activo: false, mensaje: 'Empresa no encontrada' });
    }
    
    const empresa = rows[0];
    const hoy = new Date();
    const fechaFin = new Date(empresa.fecha_fin_plan);
    const activo = empresa.plan_activo === 1 && fechaFin >= hoy;
    const diasRestantes = Math.ceil((fechaFin - hoy) / (1000 * 60 * 60 * 24));
    
    res.json({
      activo,
      fecha_fin_plan: empresa.fecha_fin_plan,
      plan_id: empresa.id_plan,
      plan_nombre: empresa.plan_nombre,
      dias_restantes: diasRestantes
    });
    
  } catch (error) {
    res.status(500).json({ error: error.message });
  } finally {
    await connection.end();
  }
});

// ============================================
// 5. INICIAR SERVIDOR
// ============================================
const PORT = process.env.PORT || 3001;
app.listen(PORT, () => {
  console.log(`
  Servidor corriendo en http://localhost:${PORT}
   Endpoints disponibles:
     POST   /api/create-checkout-session
     POST   /api/verify-payment
     POST   /api/activate-free-plan
     GET    /api/check-subscription/:empresaId
  `);
});