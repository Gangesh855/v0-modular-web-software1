const jwt = require("jsonwebtoken")

// Middleware to verify JWT token
const verifyToken = (req, res, next) => {
  try {
    const token = req.headers.authorization?.split(" ")[1]

    if (!token) {
      return res.status(401).json({ success: false, message: "No token provided" })
    }

    const decoded = jwt.verify(token, process.env.JWT_SECRET || "secret")
    req.user = decoded
    next()
  } catch (error) {
    res.status(401).json({ success: false, message: "Invalid token" })
  }
}

// Middleware to check role
const requireRole = (allowedRoles) => {
  return (req, res, next) => {
    if (!req.user) {
      return res.status(401).json({ success: false, message: "Unauthorized" })
    }

    if (!allowedRoles.includes(req.user.role)) {
      return res.status(403).json({
        success: false,
        message: "Insufficient permissions",
      })
    }

    next()
  }
}

// Middleware to check permission
const requirePermission = (permissionName) => {
  return async (req, res, next) => {
    try {
      const pool = req.app.locals.pool

      const [perms] = await pool.query(
        `SELECT p.id FROM permissions p
         JOIN role_permissions rp ON p.id = rp.permission_id
         JOIN roles r ON rp.role_id = r.id
         WHERE r.name = ? AND p.name = ?`,
        [req.user.role, permissionName],
      )

      if (perms.length === 0) {
        return res.status(403).json({
          success: false,
          message: "Permission denied",
        })
      }

      next()
    } catch (error) {
      res.status(500).json({ success: false, message: "Permission check failed" })
    }
  }
}

// Middleware to log audit trail
const auditLog = (module, action) => {
  return async (req, res, next) => {
    try {
      const pool = req.app.locals.pool

      const newValues = JSON.stringify(req.body)

      await pool.query(
        `INSERT INTO audit_logs (user_id, module, action, table_name, new_values, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)`,
        [req.user?.id || null, module, action, "", newValues, req.ip],
      )

      next()
    } catch (error) {
      console.error("[v0] Audit log error:", error)
      next()
    }
  }
}

module.exports = {
  verifyToken,
  requireRole,
  requirePermission,
  auditLog,
}
