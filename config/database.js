const mysql = require("mysql2/promise")

let pool

async function initializePool() {
  pool = mysql.createPool({
    host: process.env.DB_HOST || "localhost",
    port: process.env.DB_PORT || 3306,
    user: process.env.DB_USER || "root",
    password: process.env.DB_PASSWORD || "",
    database: process.env.DB_NAME || "enterprise_system",
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0,
    enableKeepAlive: true,
    keepAliveInitialDelayMs: 0,
  })

  return pool
}

function getPool() {
  if (!pool) {
    throw new Error("Database pool not initialized")
  }
  return pool
}

async function testConnection() {
  try {
    const connection = await getPool().getConnection()
    const [rows] = await connection.query("SELECT 1")
    connection.release()
    console.log("[v0] Database connection successful")
    return true
  } catch (error) {
    console.error("[v0] Database connection failed:", error.message)
    return false
  }
}

module.exports = {
  initializePool,
  getPool,
  testConnection,
}
