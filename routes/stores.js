const express = require("express")
const { verifyToken, requirePermission, auditLog } = require("../middleware/auth")
const router = express.Router()

// ============================================
// STORES ENDPOINTS
// ============================================

// Get all stores
router.get("/", verifyToken, requirePermission("stores_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [stores] = await pool.query(
      "SELECT s.*, u.first_name, u.last_name FROM stores s LEFT JOIN users u ON s.created_by = u.id WHERE s.is_active = TRUE",
    )

    res.json({ success: true, data: stores })
  } catch (error) {
    console.error("[v0] Get stores error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch stores" })
  }
})

// Get single store with locations and inventory
router.get("/:id", verifyToken, requirePermission("stores_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const storeId = req.params.id

    // Get store details
    const [stores] = await pool.query("SELECT * FROM stores WHERE id = ? AND is_active = TRUE", [storeId])

    if (stores.length === 0) {
      return res.status(404).json({ success: false, message: "Store not found" })
    }

    const store = stores[0]

    // Get locations
    const [locations] = await pool.query("SELECT * FROM store_locations WHERE store_id = ? ORDER BY section_name", [
      storeId,
    ])

    // Get inventory items
    const [items] = await pool.query(
      `SELECT ii.*, sl.section_name, sl.shelf_position 
       FROM inventory_items ii 
       LEFT JOIN store_locations sl ON ii.location_id = sl.id 
       WHERE ii.store_id = ? AND ii.is_active = TRUE
       ORDER BY ii.sku`,
      [storeId],
    )

    // Calculate stats
    const totalItems = items.length
    const lowStockItems = items.filter((i) => i.quantity <= i.reorder_level).length
    const totalValue = items.reduce((sum, i) => sum + i.quantity * i.unit_cost, 0)

    res.json({
      success: true,
      data: {
        store,
        locations,
        items,
        stats: {
          total_items: totalItems,
          low_stock_items: lowStockItems,
          total_value: totalValue,
        },
      },
    })
  } catch (error) {
    console.error("[v0] Get store error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch store" })
  }
})

// Create store
router.post("/", verifyToken, requirePermission("stores_create"), auditLog("stores", "CREATE"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const { name, location, capacity_units, description } = req.body

    if (!name) {
      return res.status(400).json({ success: false, message: "Store name required" })
    }

    const [result] = await pool.query(
      "INSERT INTO stores (name, location, capacity_units, description, created_by) VALUES (?, ?, ?, ?, ?)",
      [name, location, capacity_units, description, req.user.id],
    )

    res.status(201).json({
      success: true,
      message: "Store created successfully",
      store_id: result.insertId,
    })
  } catch (error) {
    console.error("[v0] Create store error:", error)
    res.status(500).json({ success: false, message: "Failed to create store" })
  }
})

// Update store
router.put("/:id", verifyToken, requirePermission("stores_edit"), auditLog("stores", "UPDATE"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const { name, location, capacity_units, description } = req.body

    await pool.query(
      "UPDATE stores SET name = ?, location = ?, capacity_units = ?, description = ?, updated_at = NOW() WHERE id = ?",
      [name, location, capacity_units, description, req.params.id],
    )

    res.json({ success: true, message: "Store updated successfully" })
  } catch (error) {
    console.error("[v0] Update store error:", error)
    res.status(500).json({ success: false, message: "Failed to update store" })
  }
})

// ============================================
// STORE LOCATIONS ENDPOINTS
// ============================================

// Get locations for a store
router.get("/:storeId/locations", verifyToken, requirePermission("stores_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [locations] = await pool.query("SELECT * FROM store_locations WHERE store_id = ? ORDER BY section_name", [
      req.params.storeId,
    ])

    res.json({ success: true, data: locations })
  } catch (error) {
    console.error("[v0] Get locations error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch locations" })
  }
})

// Create location
router.post(
  "/:storeId/locations",
  verifyToken,
  requirePermission("stores_create"),
  auditLog("stores", "CREATE_LOCATION"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { section_name, shelf_position, capacity } = req.body

      const [result] = await pool.query(
        "INSERT INTO store_locations (store_id, section_name, shelf_position, capacity) VALUES (?, ?, ?, ?)",
        [req.params.storeId, section_name, shelf_position, capacity],
      )

      res.status(201).json({
        success: true,
        message: "Location created successfully",
        location_id: result.insertId,
      })
    } catch (error) {
      console.error("[v0] Create location error:", error)
      res.status(500).json({ success: false, message: "Failed to create location" })
    }
  },
)

// ============================================
// INVENTORY ITEMS ENDPOINTS
// ============================================

// Get all inventory items
router.get("/:storeId/inventory", verifyToken, requirePermission("inventory_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [items] = await pool.query(
      `SELECT ii.*, sl.section_name, u.first_name, u.last_name
         FROM inventory_items ii
         LEFT JOIN store_locations sl ON ii.location_id = sl.id
         LEFT JOIN users u ON ii.created_by = u.id
         WHERE ii.store_id = ? AND ii.is_active = TRUE
         ORDER BY ii.sku`,
      [req.params.storeId],
    )

    res.json({ success: true, data: items })
  } catch (error) {
    console.error("[v0] Get inventory error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch inventory" })
  }
})

// Create inventory item
router.post(
  "/:storeId/inventory",
  verifyToken,
  requirePermission("inventory_create"),
  auditLog("stores", "CREATE_ITEM"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { sku, name, description, unit_of_measure, quantity, reorder_level, max_quantity, location_id, unit_cost } =
        req.body

      if (!sku || !name) {
        return res.status(400).json({ success: false, message: "SKU and name required" })
      }

      const [result] = await pool.query(
        `INSERT INTO inventory_items 
         (sku, name, description, unit_of_measure, quantity, reorder_level, max_quantity, store_id, location_id, unit_cost, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          sku,
          name,
          description,
          unit_of_measure,
          quantity || 0,
          reorder_level || 0,
          max_quantity || 0,
          req.params.storeId,
          location_id,
          unit_cost || 0,
          req.user.id,
        ],
      )

      // Log transaction
      await pool.query(
        "INSERT INTO inventory_transactions (inventory_item_id, transaction_type, quantity, notes, created_by) VALUES (?, ?, ?, ?, ?)",
        [result.insertId, "IN", quantity || 0, "Initial stock", req.user.id],
      )

      res.status(201).json({
        success: true,
        message: "Inventory item created successfully",
        item_id: result.insertId,
      })
    } catch (error) {
      console.error("[v0] Create inventory error:", error)
      res.status(500).json({ success: false, message: "Failed to create inventory item" })
    }
  },
)

// Update inventory item
router.put(
  "/:storeId/inventory/:itemId",
  verifyToken,
  requirePermission("inventory_edit"),
  auditLog("stores", "UPDATE_ITEM"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { name, description, reorder_level, max_quantity, unit_cost, location_id } = req.body

      await pool.query(
        `UPDATE inventory_items 
         SET name = ?, description = ?, reorder_level = ?, max_quantity = ?, unit_cost = ?, location_id = ?, updated_at = NOW()
         WHERE id = ? AND store_id = ?`,
        [name, description, reorder_level, max_quantity, unit_cost, location_id, req.params.itemId, req.params.storeId],
      )

      res.json({ success: true, message: "Inventory item updated successfully" })
    } catch (error) {
      console.error("[v0] Update inventory error:", error)
      res.status(500).json({ success: false, message: "Failed to update inventory item" })
    }
  },
)

// ============================================
// INVENTORY TRANSACTIONS
// ============================================

// Get transactions for item
router.get(
  "/:storeId/inventory/:itemId/transactions",
  verifyToken,
  requirePermission("inventory_view"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const [transactions] = await pool.query(
        `SELECT it.*, u.first_name, u.last_name
         FROM inventory_transactions it
         LEFT JOIN users u ON it.created_by = u.id
         WHERE it.inventory_item_id = ?
         ORDER BY it.created_at DESC`,
        [req.params.itemId],
      )

      res.json({ success: true, data: transactions })
    } catch (error) {
      console.error("[v0] Get transactions error:", error)
      res.status(500).json({ success: false, message: "Failed to fetch transactions" })
    }
  },
)

// Add transaction (IN, OUT, ADJUST, RETURN)
router.post(
  "/:storeId/inventory/:itemId/transaction",
  verifyToken,
  requirePermission("inventory_edit"),
  auditLog("stores", "INVENTORY_TRANSACTION"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { transaction_type, quantity, notes, reference_id, reference_type } = req.body

      if (!transaction_type || !quantity) {
        return res.status(400).json({
          success: false,
          message: "Transaction type and quantity required",
        })
      }

      // Get current item
      const [items] = await pool.query("SELECT * FROM inventory_items WHERE id = ? AND store_id = ?", [
        req.params.itemId,
        req.params.storeId,
      ])

      if (items.length === 0) {
        return res.status(404).json({ success: false, message: "Item not found" })
      }

      const item = items[0]
      let newQuantity = item.quantity

      // Calculate new quantity based on transaction type
      switch (transaction_type) {
        case "IN":
          newQuantity += quantity
          break
        case "OUT":
          newQuantity -= quantity
          break
        case "ADJUST":
          newQuantity = quantity
          break
        case "RETURN":
          newQuantity += quantity
          break
      }

      // Prevent negative stock
      if (newQuantity < 0) {
        return res.status(400).json({ success: false, message: "Insufficient stock" })
      }

      // Update inventory
      await pool.query("UPDATE inventory_items SET quantity = ?, updated_at = NOW() WHERE id = ?", [
        newQuantity,
        req.params.itemId,
      ])

      // Log transaction
      const [result] = await pool.query(
        "INSERT INTO inventory_transactions (inventory_item_id, transaction_type, quantity, reference_id, reference_type, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
        [req.params.itemId, transaction_type, quantity, reference_id, reference_type, notes, req.user.id],
      )

      res.status(201).json({
        success: true,
        message: "Transaction recorded successfully",
        new_quantity: newQuantity,
        transaction_id: result.insertId,
      })
    } catch (error) {
      console.error("[v0] Transaction error:", error)
      res.status(500).json({ success: false, message: "Failed to record transaction" })
    }
  },
)

// Get low stock items
router.get("/:storeId/low-stock", verifyToken, requirePermission("inventory_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [items] = await pool.query(
      `SELECT ii.*, sl.section_name
         FROM inventory_items ii
         LEFT JOIN store_locations sl ON ii.location_id = sl.id
         WHERE ii.store_id = ? AND ii.quantity <= ii.reorder_level AND ii.is_active = TRUE
         ORDER BY ii.quantity ASC`,
      [req.params.storeId],
    )

    res.json({
      success: true,
      data: items,
      count: items.length,
    })
  } catch (error) {
    console.error("[v0] Low stock error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch low stock items" })
  }
})

module.exports = router
