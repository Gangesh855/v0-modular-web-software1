const express = require("express")
const { verifyToken, requirePermission, auditLog } = require("../middleware/auth")
const router = express.Router()

// ============================================
// PRODUCTS ENDPOINTS
// ============================================

// Get all products
router.get("/products", verifyToken, requirePermission("production_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [products] = await pool.query("SELECT * FROM products WHERE is_active = TRUE ORDER BY name")

    res.json({ success: true, data: products })
  } catch (error) {
    console.error("[v0] Get products error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch products" })
  }
})

// Create product
router.post(
  "/products",
  verifyToken,
  requirePermission("production_create"),
  auditLog("production", "CREATE_PRODUCT"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { product_code, name, description, standard_cost } = req.body

      if (!product_code || !name) {
        return res.status(400).json({
          success: false,
          message: "Product code and name required",
        })
      }

      const [result] = await pool.query(
        "INSERT INTO products (product_code, name, description, standard_cost, created_by) VALUES (?, ?, ?, ?, ?)",
        [product_code, name, description, standard_cost, req.user.id],
      )

      res.status(201).json({
        success: true,
        message: "Product created successfully",
        product_id: result.insertId,
      })
    } catch (error) {
      console.error("[v0] Create product error:", error)
      res.status(500).json({ success: false, message: "Failed to create product" })
    }
  },
)

// ============================================
// PRODUCTION ORDERS ENDPOINTS
// ============================================

// Get all production orders
router.get("/", verifyToken, requirePermission("production_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const { status } = req.query

    let query = `SELECT po.*, p.name as product_name, u.first_name, u.last_name
                 FROM production_orders po
                 JOIN products p ON po.product_id = p.id
                 LEFT JOIN users u ON po.created_by = u.id`

    const params = []

    if (status) {
      query += " WHERE po.status = ?"
      params.push(status)
    }

    query += " ORDER BY po.created_at DESC"

    const [orders] = await pool.query(query, params)

    res.json({ success: true, data: orders })
  } catch (error) {
    console.error("[v0] Get production orders error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch production orders" })
  }
})

// Get single production order with stages
router.get("/:id", verifyToken, requirePermission("production_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool

    const [orders] = await pool.query(
      `SELECT po.*, p.name as product_name, u.first_name, u.last_name
       FROM production_orders po
       JOIN products p ON po.product_id = p.id
       LEFT JOIN users u ON po.created_by = u.id
       WHERE po.id = ?`,
      [req.params.id],
    )

    if (orders.length === 0) {
      return res.status(404).json({ success: false, message: "Production order not found" })
    }

    const order = orders[0]

    // Get stages
    const [stages] = await pool.query(
      `SELECT ps.*, u.first_name, u.last_name FROM production_stages ps
       LEFT JOIN users u ON ps.operator_id = u.id
       WHERE ps.production_order_id = ?
       ORDER BY ps.stage_sequence`,
      [req.params.id],
    )

    res.json({
      success: true,
      data: {
        production_order: order,
        stages,
      },
    })
  } catch (error) {
    console.error("[v0] Get production order error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch production order" })
  }
})

// Create production order
router.post(
  "/",
  verifyToken,
  requirePermission("production_create"),
  auditLog("production", "CREATE_ORDER"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { product_id, quantity, start_date, end_date, notes } = req.body

      if (!product_id || !quantity) {
        return res.status(400).json({
          success: false,
          message: "Product and quantity required",
        })
      }

      // Generate order number
      const orderNumber = `PRD-${Date.now()}`

      const [result] = await pool.query(
        `INSERT INTO production_orders (order_number, product_id, quantity, start_date, end_date, notes, created_by, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
        [orderNumber, product_id, quantity, start_date, end_date, notes, req.user.id, "PLANNED"],
      )

      const orderId = result.insertId

      // Create default stages
      const defaultStages = [
        { name: "Setup", sequence: 1 },
        { name: "Manufacturing", sequence: 2 },
        { name: "Inspection", sequence: 3 },
        { name: "Packaging", sequence: 4 },
      ]

      for (const stage of defaultStages) {
        await pool.query(
          "INSERT INTO production_stages (production_order_id, stage_name, stage_sequence, status) VALUES (?, ?, ?, ?)",
          [orderId, stage.name, stage.sequence, "PENDING"],
        )
      }

      res.status(201).json({
        success: true,
        message: "Production order created successfully",
        order_number: orderNumber,
        order_id: orderId,
      })
    } catch (error) {
      console.error("[v0] Create production order error:", error)
      res.status(500).json({ success: false, message: "Failed to create production order" })
    }
  },
)

// Update production order status
router.put(
  "/:id/status",
  verifyToken,
  requirePermission("production_create"),
  auditLog("production", "UPDATE_ORDER_STATUS"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { status } = req.body

      const validStatuses = ["PLANNED", "SCHEDULED", "IN_PROGRESS", "COMPLETED", "CANCELLED"]
      if (!validStatuses.includes(status)) {
        return res.status(400).json({ success: false, message: "Invalid status" })
      }

      await pool.query("UPDATE production_orders SET status = ?, updated_at = NOW() WHERE id = ?", [
        status,
        req.params.id,
      ])

      res.json({ success: true, message: `Order status updated to ${status}` })
    } catch (error) {
      console.error("[v0] Update order status error:", error)
      res.status(500).json({ success: false, message: "Failed to update order" })
    }
  },
)

// ============================================
// PRODUCTION STAGES ENDPOINTS
// ============================================

// Get stages for order
router.get("/:orderId/stages", verifyToken, requirePermission("production_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [stages] = await pool.query(
      `SELECT ps.*, u.first_name, u.last_name FROM production_stages ps
       LEFT JOIN users u ON ps.operator_id = u.id
       WHERE ps.production_order_id = ?
       ORDER BY ps.stage_sequence`,
      [req.params.orderId],
    )

    res.json({ success: true, data: stages })
  } catch (error) {
    console.error("[v0] Get stages error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch stages" })
  }
})

// Update stage
router.put(
  "/stages/:stageId",
  verifyToken,
  requirePermission("production_create"),
  auditLog("production", "UPDATE_STAGE"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { status, operator_id, quality_notes } = req.body

      const validStatuses = ["PENDING", "IN_PROGRESS", "COMPLETED"]
      if (!validStatuses.includes(status)) {
        return res.status(400).json({ success: false, message: "Invalid status" })
      }

      const updateData = { status, quality_notes }

      if (status === "IN_PROGRESS" && !operator_id) {
        return res.status(400).json({
          success: false,
          message: "Operator required to start stage",
        })
      }

      if (operator_id) {
        updateData.operator_id = operator_id
        updateData.start_time = new Date()
      }

      if (status === "COMPLETED") {
        updateData.end_time = new Date()
      }

      await pool.query(
        `UPDATE production_stages SET status = ?, operator_id = ?, quality_notes = ?, 
         start_time = CASE WHEN ? THEN NOW() ELSE start_time END,
         end_time = CASE WHEN ? THEN NOW() ELSE end_time END
         WHERE id = ?`,
        [
          status,
          operator_id,
          quality_notes,
          status === "IN_PROGRESS" ? 1 : 0,
          status === "COMPLETED" ? 1 : 0,
          req.params.stageId,
        ],
      )

      res.json({ success: true, message: "Stage updated successfully" })
    } catch (error) {
      console.error("[v0] Update stage error:", error)
      res.status(500).json({ success: false, message: "Failed to update stage" })
    }
  },
)

// Get production analytics
router.get("/analytics/summary", verifyToken, requirePermission("production_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool

    // Orders by status
    const [stats] = await pool.query(`
      SELECT status, COUNT(*) as count
      FROM production_orders
      GROUP BY status
    `)

    // Total orders
    const [total] = await pool.query("SELECT COUNT(*) as count FROM production_orders")

    // Average completion time
    const [avgTime] = await pool.query(
      `SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours
       FROM production_orders WHERE status = 'COMPLETED'`,
    )

    // Total products
    const [products] = await pool.query("SELECT COUNT(*) as count FROM products WHERE is_active = TRUE")

    res.json({
      success: true,
      data: {
        by_status: stats,
        total_orders: total[0].count,
        total_products: products[0].count,
        average_completion_hours: avgTime[0].avg_hours || 0,
      },
    })
  } catch (error) {
    console.error("[v0] Analytics error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch analytics" })
  }
})

module.exports = router
