#!/bin/bash

# Create necessary directories
mkdir -p logs
mkdir -p uploads
mkdir -p config

# Create .env.example if it doesn't exist
cat > .env.example << 'EOF'
# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=your_password
DB_NAME=enterprise_system

# Server Configuration
PORT=3000
NODE_ENV=development

# JWT Configuration
JWT_SECRET=your_secret_key_here_change_in_production

# Session Configuration
SESSION_SECRET=your_session_secret_here

# Application URLs
APP_URL=http://localhost:3000
EOF

echo "Setup complete! Copy .env.example to .env and update with your values"
