const express = require("express")
const { verifyToken, requirePermission, auditLog } = require("../middleware/auth")
const router = express.Router()

// ============================================
// MATERIALS ENDPOINTS
// ============================================

// Get all materials
router.get("/materials", verifyToken, requirePermission("foundry_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [materials] = await pool.query(
      "SELECT m.*, s.name as supplier_name FROM materials m LEFT JOIN suppliers s ON m.supplier_id = s.id WHERE m.is_active = TRUE ORDER BY m.name",
    )

    res.json({ success: true, data: materials })
  } catch (error) {
    console.error("[v0] Get materials error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch materials" })
  }
})

// Create material
router.post(
  "/materials",
  verifyToken,
  requirePermission("foundry_create"),
  auditLog("foundry", "CREATE_MATERIAL"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { name, material_type, specification, supplier_id, unit_cost } = req.body

      if (!name) {
        return res.status(400).json({ success: false, message: "Material name required" })
      }

      const [result] = await pool.query(
        "INSERT INTO materials (name, material_type, specification, supplier_id, unit_cost) VALUES (?, ?, ?, ?, ?)",
        [name, material_type, specification, supplier_id, unit_cost],
      )

      res.status(201).json({
        success: true,
        message: "Material created successfully",
        material_id: result.insertId,
      })
    } catch (error) {
      console.error("[v0] Create material error:", error)
      res.status(500).json({ success: false, message: "Failed to create material" })
    }
  },
)

// ============================================
// FOUNDRY PROCESSES ENDPOINTS
// ============================================

// Get all processes
router.get("/processes", verifyToken, requirePermission("foundry_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [processes] = await pool.query(
      "SELECT p.*, m.name as material_name FROM foundry_processes p JOIN materials m ON p.material_id = m.id WHERE p.is_active = TRUE ORDER BY p.process_name",
    )

    res.json({ success: true, data: processes })
  } catch (error) {
    console.error("[v0] Get processes error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch processes" })
  }
})

// Get single process
router.get("/processes/:id", verifyToken, requirePermission("foundry_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [processes] = await pool.query(
      "SELECT p.*, m.name as material_name FROM foundry_processes p JOIN materials m ON p.material_id = m.id WHERE p.id = ? AND p.is_active = TRUE",
      [req.params.id],
    )

    if (processes.length === 0) {
      return res.status(404).json({ success: false, message: "Process not found" })
    }

    res.json({ success: true, data: processes[0] })
  } catch (error) {
    console.error("[v0] Get process error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch process" })
  }
})

// Create process
router.post(
  "/processes",
  verifyToken,
  requirePermission("foundry_create"),
  auditLog("foundry", "CREATE_PROCESS"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { process_name, description, material_id, temperature, duration_minutes, yield_percentage } = req.body

      if (!process_name || !material_id) {
        return res.status(400).json({
          success: false,
          message: "Process name and material required",
        })
      }

      const [result] = await pool.query(
        `INSERT INTO foundry_processes (process_name, description, material_id, temperature, duration_minutes, yield_percentage, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)`,
        [process_name, description, material_id, temperature, duration_minutes, yield_percentage, req.user.id],
      )

      res.status(201).json({
        success: true,
        message: "Process created successfully",
        process_id: result.insertId,
      })
    } catch (error) {
      console.error("[v0] Create process error:", error)
      res.status(500).json({ success: false, message: "Failed to create process" })
    }
  },
)

// ============================================
// FOUNDRY BATCHES ENDPOINTS
// ============================================

// Get all batches
router.get("/batches", verifyToken, requirePermission("foundry_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const { status } = req.query

    let query = `SELECT b.*, p.process_name, m.name as material_name, u.first_name, u.last_name
                 FROM foundry_batches b
                 JOIN foundry_processes p ON b.process_id = p.id
                 JOIN materials m ON b.material_id = m.id
                 LEFT JOIN users u ON b.created_by = u.id`

    const params = []

    if (status) {
      query += " WHERE b.status = ?"
      params.push(status)
    }

    query += " ORDER BY b.created_at DESC"

    const [batches] = await pool.query(query, params)

    res.json({ success: true, data: batches })
  } catch (error) {
    console.error("[v0] Get batches error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch batches" })
  }
})

// Get single batch
router.get("/batches/:id", verifyToken, requirePermission("foundry_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool
    const [batches] = await pool.query(
      `SELECT b.*, p.process_name, p.temperature, p.duration_minutes, p.yield_percentage,
              m.name as material_name, u.first_name, u.last_name
       FROM foundry_batches b
       JOIN foundry_processes p ON b.process_id = p.id
       JOIN materials m ON b.material_id = m.id
       LEFT JOIN users u ON b.created_by = u.id
       WHERE b.id = ?`,
      [req.params.id],
    )

    if (batches.length === 0) {
      return res.status(404).json({ success: false, message: "Batch not found" })
    }

    res.json({ success: true, data: batches[0] })
  } catch (error) {
    console.error("[v0] Get batch error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch batch" })
  }
})

// Create batch
router.post(
  "/batches",
  verifyToken,
  requirePermission("foundry_create"),
  auditLog("foundry", "CREATE_BATCH"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { process_id, material_id, quantity } = req.body

      if (!process_id || !material_id || !quantity) {
        return res.status(400).json({
          success: false,
          message: "Process, material, and quantity required",
        })
      }

      // Generate batch number
      const batchNumber = `BATCH-${Date.now()}`

      const [result] = await pool.query(
        `INSERT INTO foundry_batches (batch_number, process_id, material_id, quantity, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?)`,
        [batchNumber, process_id, material_id, quantity, "PLANNED", req.user.id],
      )

      res.status(201).json({
        success: true,
        message: "Batch created successfully",
        batch_number: batchNumber,
        batch_id: result.insertId,
      })
    } catch (error) {
      console.error("[v0] Create batch error:", error)
      res.status(500).json({ success: false, message: "Failed to create batch" })
    }
  },
)

// Start batch
router.put(
  "/batches/:id/start",
  verifyToken,
  requirePermission("foundry_create"),
  auditLog("foundry", "START_BATCH"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool

      await pool.query("UPDATE foundry_batches SET status = ?, start_date = NOW() WHERE id = ?", [
        "IN_PROGRESS",
        req.params.id,
      ])

      res.json({ success: true, message: "Batch started successfully" })
    } catch (error) {
      console.error("[v0] Start batch error:", error)
      res.status(500).json({ success: false, message: "Failed to start batch" })
    }
  },
)

// Complete batch
router.put(
  "/batches/:id/complete",
  verifyToken,
  requirePermission("foundry_create"),
  auditLog("foundry", "COMPLETE_BATCH"),
  async (req, res) => {
    try {
      const pool = req.app.locals.pool
      const { quality_check_result, notes } = req.body

      await pool.query(
        "UPDATE foundry_batches SET status = ?, end_date = NOW(), quality_check_result = ?, notes = ? WHERE id = ?",
        ["COMPLETED", quality_check_result, notes, req.params.id],
      )

      res.json({ success: true, message: "Batch completed successfully" })
    } catch (error) {
      console.error("[v0] Complete batch error:", error)
      res.status(500).json({ success: false, message: "Failed to complete batch" })
    }
  },
)

// Get batch analytics
router.get("/analytics/summary", verifyToken, requirePermission("foundry_view"), async (req, res) => {
  try {
    const pool = req.app.locals.pool

    // Batches by status
    const [stats] = await pool.query(`
      SELECT status, COUNT(*) as count
      FROM foundry_batches
      GROUP BY status
    `)

    // Average yield
    const [yields] = await pool.query(
      "SELECT AVG(p.yield_percentage) as avg_yield FROM foundry_batches b JOIN foundry_processes p ON b.process_id = p.id WHERE b.status = 'COMPLETED'",
    )

    // Total batches
    const [total] = await pool.query("SELECT COUNT(*) as count FROM foundry_batches")

    // Materials count
    const [materials] = await pool.query("SELECT COUNT(*) as count FROM materials WHERE is_active = TRUE")

    res.json({
      success: true,
      data: {
        by_status: stats,
        total_batches: total[0].count,
        total_materials: materials[0].count,
        average_yield: yields[0].avg_yield || 0,
      },
    })
  } catch (error) {
    console.error("[v0] Analytics error:", error)
    res.status(500).json({ success: false, message: "Failed to fetch analytics" })
  }
})

module.exports = router
