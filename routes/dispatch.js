const express = require("express")
const { verifyToken, requirePermission, auditLog } = require("../middleware/auth")
const router = express.Router()

// ============================================
// SHIPMENTS ENDPOINTS
// ============================================

// Get all shipments
router.get("/", verifyToken, requirePermission("dispatch_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const { status } = req.query

    let query = `SELECT s.*, po.order_number, p.name as product_name, u.first_name, u.last_name
                 FROM shipments s
                 LEFT JOIN production_orders po ON s.production_order_id = po.id
                 LEFT JOIN products p ON po.product_id = p.id
                 LEFT JOIN users u ON s.created_by = u.id`

    const params = []

    if (status) {
      query += " WHERE s.status = ?"
      params.push(status)
    }

    query += " ORDER BY s.created_at DESC"

    const [shipments] = await pool.query(query, params)

    res.json({ success: true, data: shipments })
  } catch (error) {
    console.error("[v0] Get shipments error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch shipments" })
  }
})

// Get single shipment with items
router.get("/:id", verifyToken, requirePermission("dispatch_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool

    const [shipments] = await pool.query(
      `SELECT s.*, po.order_number, p.name as product_name
       FROM shipments s
       LEFT JOIN production_orders po ON s.production_order_id = po.id
       LEFT JOIN products p ON po.product_id = p.id
       WHERE s.id = ?`,
      [req.params.id],
    )

    if (shipments.length === 0) {
      return res.status(404).json({ success: false, message: "Shipment not found" })
    }

    const shipment = shipments[0]

    // Get items
    const [items] = await pool.query(
      `SELECT si.*, p.product_code, p.name FROM shipment_items si
       JOIN products p ON si.product_id = p.id
       WHERE si.shipment_id = ?`,
      [req.params.id],
    )

    res.json({
      success: true,
      data: {
        shipment,
        items,
      },
    })
  } catch (error) {
    console.error("[v0] Get shipment error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch shipment" })
  }
})

// Create shipment
router.post(
  "/",
  verifyToken,
  requirePermission("dispatch_create"),
  auditLog("dispatch", "CREATE_SHIPMENT"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const {
        production_order_id,
        ship_date,
        expected_delivery_date,
        carrier_name,
        tracking_number,
        destination_address,
        items,
        notes,
      } = req.body

      if (!ship_date || !destination_address) {
        return res.status(400).json({
          success: false,
          message: "Ship date and destination address required",
        })
      }

      // Generate shipment number
      const shipmentNumber = `SHIP-${Date.now()}`

      const [result] = await pool.query(
        `INSERT INTO shipments (shipment_number, production_order_id, ship_date, expected_delivery_date, carrier_name, tracking_number, destination_address, notes, created_by, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          shipmentNumber,
          production_order_id,
          ship_date,
          expected_delivery_date,
          carrier_name,
          tracking_number,
          destination_address,
          notes,
          req.user.id,
          "PENDING",
        ],
      )

      const shipmentId = result.insertId

      // Add items
      for (const item of items) {
        await pool.query("INSERT INTO shipment_items (shipment_id, product_id, quantity) VALUES (?, ?, ?)", [
          shipmentId,
          item.product_id,
          item.quantity,
        ])
      }

      res.status(201).json({
        success: true,
        message: "Shipment created successfully",
        shipment_number: shipmentNumber,
        shipment_id: shipmentId,
      })
    } catch (error) {
      console.error("[v0] Create shipment error:", error)
      res.status(500).json({ success: false, message: "Failed to create shipment" })
    }
  },
)

// Update shipment status
router.put(
  "/:id/status",
  verifyToken,
  requirePermission("dispatch_create"),
  auditLog("dispatch", "UPDATE_SHIPMENT_STATUS"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { status } = req.body

      const validStatuses = ["PENDING", "IN_TRANSIT", "DELIVERED", "CANCELLED"]
      if (!validStatuses.includes(status)) {
        return res.status(400).json({ success: false, message: "Invalid status" })
      }

      const updateQuery = `UPDATE shipments SET status = ?, updated_at = NOW()
                          ${status === "DELIVERED" ? ", actual_delivery_date = NOW()" : ""}
                          WHERE id = ?`

      await pool.query(updateQuery, [status, req.params.id])

      res.json({ success: true, message: `Shipment status updated to ${status}` })
    } catch (error) {
      console.error("[v0] Update shipment status error:", error)
      res.status(500).json({ success: false, message: "Failed to update shipment" })
    }
  },
)

// Get shipment analytics
router.get("/analytics/summary", verifyToken, requirePermission("dispatch_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool

    // Shipments by status
    const [stats] = await pool.query(`
      SELECT status, COUNT(*) as count
      FROM shipments
      GROUP BY status
    `)

    // Total shipments
    const [total] = await pool.query("SELECT COUNT(*) as count FROM shipments")

    // Average delivery time
    const [avgDelivery] = await pool.query(
      `SELECT AVG(TIMESTAMPDIFF(DAY, ship_date, actual_delivery_date)) as avg_days
       FROM shipments WHERE status = 'DELIVERED' AND actual_delivery_date IS NOT NULL`,
    )

    // On-time deliveries
    const [onTime] = await pool.query(
      `SELECT COUNT(*) as count FROM shipments 
       WHERE status = 'DELIVERED' AND actual_delivery_date <= expected_delivery_date`,
    )

    res.json({
      success: true,
      data: {
        by_status: stats,
        total_shipments: total[0].count,
        average_delivery_days: avgDelivery[0].avg_days || 0,
        on_time_deliveries: onTime[0].count,
      },
    })
  } catch (error) {
    console.error("[v0] Analytics error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch analytics" })
  }
})

module.exports = router
