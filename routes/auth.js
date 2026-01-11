const express = require("express")
const bcrypt = require("bcrypt")
const jwt = require("jsonwebtoken")
const router = express.Router()

// Get database pool from app
const getPool = () => {
  return this.pool
}

// Login endpoint
router.post("/login", async (req, res) => {
  try {
    const { email, password } = req.body

    if (!email || !password) {
      return res.status(400).json({ success: false, message: "Email and password required" })
    }

    const pool = req.app.locals.pool
    const [users] = await pool.query(
      "SELECT u.*, r.name as role FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ?",
      [email],
    )

    if (users.length === 0) {
      return res.status(401).json({ success: false, message: "Invalid credentials" })
    }

    const user = users[0]

    // Check password
    const passwordMatch = await bcrypt.compare(password, user.password_hash)
    if (!passwordMatch) {
      return res.status(401).json({ success: false, message: "Invalid credentials" })
    }

    if (!user.is_active) {
      return res.status(403).json({ success: false, message: "User account is inactive" })
    }

    // Generate JWT
    const token = jwt.sign(
      {
        id: user.id,
        email: user.email,
        role: user.role,
        department: user.department,
      },
      process.env.JWT_SECRET || "secret",
      { expiresIn: "24h" },
    )

    // Update last login
    await pool.query("UPDATE users SET last_login = NOW() WHERE id = ?", [user.id])

    // Log audit
    await pool.query("INSERT INTO audit_logs (user_id, module, action, table_name, record_id) VALUES (?, ?, ?, ?, ?)", [
      user.id,
      "auth",
      "LOGIN",
      "users",
      user.id,
    ])

    res.json({
      success: true,
      token,
      user: {
        id: user.id,
        email: user.email,
        first_name: user.first_name,
        last_name: user.last_name,
        role: user.role,
        department: user.department,
      },
    })
  } catch (error) {
    console.error("[v0] Login error:", error)
    res.status(500).json({ success: false, message: "Login failed" })
  }
})

// Register endpoint
router.post("/register", async (req, res) => {
  try {
    const { email, password, first_name, last_name } = req.body

    if (!email || !password || !first_name || !last_name) {
      return res.status(400).json({ success: false, message: "All fields required" })
    }

    const pool = req.app.locals.pool

    // Check if user exists
    const [existing] = await pool.query("SELECT id FROM users WHERE email = ?", [email])
    if (existing.length > 0) {
      return res.status(409).json({ success: false, message: "Email already registered" })
    }

    // Hash password
    const password_hash = await bcrypt.hash(password, 10)

    // Get default operator role
    const [roles] = await pool.query("SELECT id FROM roles WHERE name = ?", ["OPERATOR"])
    const role_id = roles[0].id

    // Create user
    const [result] = await pool.query(
      "INSERT INTO users (email, password_hash, first_name, last_name, role_id) VALUES (?, ?, ?, ?, ?)",
      [email, password_hash, first_name, last_name, role_id],
    )

    res.status(201).json({
      success: true,
      message: "User registered successfully",
      user_id: result.insertId,
    })
  } catch (error) {
    console.error("[v0] Register error:", error)
    res.status(500).json({ success: false, message: "Registration failed" })
  }
})

// Verify token endpoint
router.get("/verify", async (req, res) => {
  try {
    const token = req.headers.authorization?.split(" ")[1]

    if (!token) {
      return res.status(401).json({ success: false, message: "No token provided" })
    }

    const decoded = jwt.verify(token, process.env.JWT_SECRET || "secret")
    res.json({ success: true, user: decoded })
  } catch (error) {
    res.status(401).json({ success: false, message: "Invalid token" })
  }
})

module.exports = router
