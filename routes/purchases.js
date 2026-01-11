const express = require("express")
const { verifyToken, requirePermission, auditLog } = require("../middleware/auth")
const router = express.Router()

// ============================================
// SUPPLIERS ENDPOINTS
// ============================================

// Get all suppliers
router.get("/suppliers", verifyToken, requirePermission("purchases_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [suppliers] = await pool.query("SELECT * FROM suppliers WHERE is_active = TRUE ORDER BY name")

    res.json({ success: true, data: suppliers })
  } catch (error) {
    console.error("[v0] Get suppliers error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch suppliers" })
  }
})

// Get single supplier
router.get("/suppliers/:id", verifyToken, requirePermission("purchases_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [suppliers] = await pool.query("SELECT * FROM suppliers WHERE id = ? AND is_active = TRUE", [req.params.id])

    if (suppliers.length === 0) {
      return res.status(404).json({ success: false, message: "Supplier not found" })
    }

    // Get purchase orders for this supplier
    const [pos] = await pool.query("SELECT * FROM purchase_orders WHERE supplier_id = ? ORDER BY order_date DESC", [
      req.params.id,
    ])

    res.json({
      success: true,
      data: {
        supplier: suppliers[0],
        purchase_orders: pos,
      },
    })
  } catch (error) {
    console.error("[v0] Get supplier error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch supplier" })
  }
})

// Create supplier
router.post(
  "/suppliers",
  verifyToken,
  requirePermission("purchases_create"),
  auditLog("purchases", "CREATE_SUPPLIER"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { name, contact_person, email, phone, address, payment_terms } = req.body

      if (!name) {
        return res.status(400).json({ success: false, message: "Supplier name required" })
      }

      const [result] = await pool.query(
        "INSERT INTO suppliers (name, contact_person, email, phone, address, payment_terms) VALUES (?, ?, ?, ?, ?, ?)",
        [name, contact_person, email, phone, address, payment_terms],
      )

      res.status(201).json({
        success: true,
        message: "Supplier created successfully",
        supplier_id: result.insertId,
      })
    } catch (error) {
      console.error("[v0] Create supplier error:", error)
      res.status(500).json({ success: false, message: "Failed to create supplier" })
    }
  },
)

// Update supplier
router.put(
  "/suppliers/:id",
  verifyToken,
  requirePermission("purchases_create"),
  auditLog("purchases", "UPDATE_SUPPLIER"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { name, contact_person, email, phone, address, payment_terms } = req.body

      await pool.query(
        "UPDATE suppliers SET name = ?, contact_person = ?, email = ?, phone = ?, address = ?, payment_terms = ? WHERE id = ?",
        [name, contact_person, email, phone, address, payment_terms, req.params.id],
      )

      res.json({ success: true, message: "Supplier updated successfully" })
    } catch (error) {
      console.error("[v0] Update supplier error:", error)
      res.status(500).json({ success: false, message: "Failed to update supplier" })
    }
  },
)

// ============================================
// PURCHASE ORDERS ENDPOINTS
// ============================================

// Get all purchase orders
router.get("/", verifyToken, requirePermission("purchases_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const { status } = req.query

    let query = `SELECT po.*, s.name as supplier_name, u.first_name, u.last_name 
                 FROM purchase_orders po 
                 JOIN suppliers s ON po.supplier_id = s.id
                 LEFT JOIN users u ON po.created_by = u.id`

    const params = []

    if (status) {
      query += " WHERE po.status = ?"
      params.push(status)
    }

    query += " ORDER BY po.order_date DESC"

    const [pos] = await pool.query(query, params)

    res.json({ success: true, data: pos })
  } catch (error) {
    console.error("[v0] Get POs error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch purchase orders" })
  }
})

// Get single PO with items
router.get("/:id", verifyToken, requirePermission("purchases_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool

    const [pos] = await pool.query(
      `SELECT po.*, s.name as supplier_name, s.contact_person, s.email, s.phone
       FROM purchase_orders po
       JOIN suppliers s ON po.supplier_id = s.id
       WHERE po.id = ?`,
      [req.params.id],
    )

    if (pos.length === 0) {
      return res.status(404).json({ success: false, message: "Purchase order not found" })
    }

    const po = pos[0]

    // Get items
    const [items] = await pool.query(
      `SELECT poi.*, ii.name, ii.sku, ii.unit_of_measure
       FROM purchase_order_items poi
       JOIN inventory_items ii ON poi.inventory_item_id = ii.id
       WHERE poi.purchase_order_id = ?`,
      [req.params.id],
    )

    res.json({
      success: true,
      data: {
        purchase_order: po,
        items,
      },
    })
  } catch (error) {
    console.error("[v0] Get PO error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch purchase order" })
  }
})

// Create purchase order
router.post(
  "/",
  verifyToken,
  requirePermission("purchases_create"),
  auditLog("purchases", "CREATE_PO"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { supplier_id, order_date, expected_delivery_date, items, notes } = req.body

      if (!supplier_id || !order_date) {
        return res.status(400).json({
          success: false,
          message: "Supplier ID and order date required",
        })
      }

      // Generate PO number
      const [lastPO] = await pool.query("SELECT MAX(po_number) as lastPO FROM purchase_orders")
      const poNumber = `PO-${Date.now()}`

      // Calculate total
      let total = 0
      items.forEach((item) => {
        total += item.quantity * item.unit_price
      })

      // Create PO
      const [result] = await pool.query(
        `INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_delivery_date, total_amount, notes, created_by, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
        [poNumber, supplier_id, order_date, expected_delivery_date, total, notes, req.user.id, "DRAFT"],
      )

      const poId = result.insertId

      // Add items
      for (const item of items) {
        await pool.query(
          `INSERT INTO purchase_order_items (purchase_order_id, inventory_item_id, quantity, unit_price, line_total)
           VALUES (?, ?, ?, ?, ?)`,
          [poId, item.inventory_item_id, item.quantity, item.unit_price, item.quantity * item.unit_price],
        )
      }

      res.status(201).json({
        success: true,
        message: "Purchase order created successfully",
        po_number: poNumber,
        po_id: poId,
      })
    } catch (error) {
      console.error("[v0] Create PO error:", error)
      res.status(500).json({ success: false, message: "Failed to create purchase order" })
    }
  },
)

// Update PO status
router.put(
  "/:id/status",
  verifyToken,
  requirePermission("purchases_approve"),
  auditLog("purchases", "UPDATE_PO_STATUS"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { status } = req.body

      const validStatuses = ["DRAFT", "PENDING", "CONFIRMED", "RECEIVED", "CANCELLED"]
      if (!validStatuses.includes(status)) {
        return res.status(400).json({ success: false, message: "Invalid status" })
      }

      await pool.query("UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?", [
        status,
        req.params.id,
      ])

      res.json({ success: true, message: `PO status updated to ${status}` })
    } catch (error) {
      console.error("[v0] Update PO status error:", error)
      res.status(500).json({ success: false, message: "Failed to update purchase order" })
    }
  },
)

// Receive PO - updates inventory
router.post(
  "/:id/receive",
  verifyToken,
  requirePermission("purchases_approve"),
  auditLog("purchases", "RECEIVE_PO"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { received_items } = req.body

      // Get PO details
      const [pos] = await pool.query("SELECT * FROM purchase_orders WHERE id = ?", [req.params.id])
      if (pos.length === 0) {
        return res.status(404).json({ success: false, message: "Purchase order not found" })
      }

      const po = pos[0]

      // Update each item
      for (const receivedItem of received_items) {
        // Update PO item received quantity
        await pool.query("UPDATE purchase_order_items SET received_quantity = ? WHERE id = ?", [
          receivedItem.received_quantity,
          receivedItem.po_item_id,
        ])

        // Update inventory
        await pool.query("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?", [
          receivedItem.received_quantity,
          receivedItem.inventory_item_id,
        ])

        // Log transaction
        await pool.query(
          "INSERT INTO inventory_transactions (inventory_item_id, transaction_type, quantity, reference_id, reference_type, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
          [
            receivedItem.inventory_item_id,
            "IN",
            receivedItem.received_quantity,
            req.params.id,
            "PURCHASE_ORDER",
            `Received from PO ${po.po_number}`,
            req.user.id,
          ],
        )
      }

      // Update PO status
      await pool.query(
        "UPDATE purchase_orders SET status = ?, actual_delivery_date = NOW(), updated_at = NOW() WHERE id = ?",
        ["RECEIVED", req.params.id],
      )

      res.json({
        success: true,
        message: "Purchase order received and inventory updated",
      })
    } catch (error) {
      console.error("[v0] Receive PO error:", error)
      res.status(500).json({ success: false, message: "Failed to receive purchase order" })
    }
  },
)

// Get PO summary statistics
router.get("/analytics/summary", verifyToken, requirePermission("purchases_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool

    // Total POs by status
    const [stats] = await pool.query(`
      SELECT status, COUNT(*) as count, SUM(total_amount) as total
      FROM purchase_orders
      GROUP BY status
    `)

    // Total suppliers
    const [suppliers] = await pool.query("SELECT COUNT(*) as count FROM suppliers WHERE is_active = TRUE")

    // Average order value
    const [avg] = await pool.query(
      "SELECT AVG(total_amount) as avg_value FROM purchase_orders WHERE status IN ('CONFIRMED', 'RECEIVED')",
    )

    res.json({
      success: true,
      data: {
        by_status: stats,
        total_suppliers: suppliers[0].count,
        average_order_value: avg[0].avg_value || 0,
      },
    })
  } catch (error) {
    console.error("[v0] Analytics error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch analytics" })
  }
})

module.exports = router
