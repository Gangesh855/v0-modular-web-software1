require("dotenv").config()
const express = require("express")
const cors = require("cors")
const helmet = require("helmet")
const mysql = require("mysql2/promise")

const app = express()

// Middleware
app.use(helmet())
app.use(cors())
app.use(express.json())
app.use(express.urlencoded({ extended: true }))

// Database connection pool
const pool = mysql.createPool({
  host: process.env.DB_HOST || "localhost",
  port: process.env.DB_PORT || 3306,
  user: process.env.DB_USER || "root",
  password: process.env.DB_PASSWORD || "",
  database: process.env.DB_NAME || "enterprise_system",
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
})

// Store pool in app for use in routes
app.locals.pool = pool

// Health check endpoint
app.get("/health", (req, res) => {
  res.json({ status: "ok", timestamp: new Date().toISOString() })
})

// API routes will be mounted here
app.use("/api/auth", require("./routes/auth"))
app.use("/api/stores", require("./routes/stores"))
app.use("/api/purchases", require("./routes/purchases"))
app.use("/api/foundry", require("./routes/foundry"))
app.use("/api/production", require("./routes/production"))
app.use("/api/dispatch", require("./routes/dispatch"))
app.use("/api/hr", require("./routes/hr"))
app.use("/api/die-shop", require("./routes/die-shop"))
app.use("/api/audit", require("./routes/audit"))

// Error handling middleware
app.use((err, req, res, next) => {
  console.error(err.stack)
  res.status(500).json({
    success: false,
    message: "Internal server error",
    error: process.env.NODE_ENV === "development" ? err.message : undefined,
  })
})

// Start server
const PORT = process.env.PORT || 3000
app.listen(PORT, () => {
  console.log(`[v0] Server running on port ${PORT}`)
  console.log(`[v0] Environment: ${process.env.NODE_ENV}`)
})

module.exports = app
