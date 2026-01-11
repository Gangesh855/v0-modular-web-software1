# Enterprise Management System - PHP Version for Hostinger

A modular, role-based enterprise management system with 7 integrated business modules designed for Hostinger shared PHP hosting.

## Features

### 7 Business Modules
- **Stores (Hub)** - Central inventory management system that connects all modules
- **Purchases** - Supplier management and purchase order tracking
- **Foundry** - Materials and batch processing with quality control
- **Production** - Manufacturing orders with multi-stage workflow
- **Dispatch** - Shipment tracking and delivery management
- **HR** - Employee and department management
- **Die Shop** - Equipment maintenance scheduling

### System Features
- ✅ Role-based access control (Admin, Manager, Operator, Auditor)
- ✅ Audit logging for compliance and traceability
- ✅ Secure authentication with bcrypt password hashing
- ✅ Session-based authorization
- ✅ RESTful API architecture
- ✅ MySQL database with complete schema
- ✅ Bootstrap responsive UI
- ✅ Real-time dashboard with statistics
- ✅ Transaction support for data integrity

## System Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Hostinger shared hosting account (or any host with PHP + MySQL)
- Modern web browser (Chrome, Firefox, Safari, Edge)

## File Structure

```
public_html/
├── config/
│   ├── database.php          # MySQL connection configuration
│   ├── session.php           # Session management and security
│   └── functions.php         # Helper functions and utilities
├── api/
│   ├── auth.php              # Authentication (login, register, logout)
│   ├── stores.php            # Stores/Inventory module API
│   ├── purchases.php         # Purchases/PO module API
│   ├── foundry.php           # Foundry/Materials module API
│   ├── production.php        # Production/Manufacturing module API
│   ├── dispatch.php          # Dispatch/Shipping module API
│   ├── hr.php                # HR/Employees module API
│   └── die-shop.php          # Die Shop/Equipment module API
├── public/
│   ├── login.html            # User login page
│   ├── register.html         # User registration page
│   ├── dashboard.html        # Main dashboard
│   └── .htaccess             # URL rewriting rules
├── database.sql              # Complete MySQL schema
├── index.php                 # API router/entry point
├── .htaccess                 # Server configuration
└── INSTALLATION_GUIDE.txt    # Detailed setup instructions
```

## Installation Steps

### 1. Prepare Your Hostinger Account

1. Log in to **Hostinger Control Panel (cPanel)**
2. Navigate to **MySQL Databases**
3. Create a new database:
   - Database name: `enterprise_management`
   - Choose an appropriate prefix if required
4. Create a MySQL user:
   - Username: `ent_user` (or any username you prefer)
   - Generate a strong password
   - **Save these credentials** - you'll need them for Step 3
5. Add the user to the database with **ALL PRIVILEGES**

### 2. Import Database Schema

1. In cPanel, go to **phpMyAdmin**
2. Select your `enterprise_management` database
3. Click the **Import** tab
4. Click **Choose File** and select `database.sql`
5. Click **Go** to execute the import
6. You should see a success message with table creation details

### 3. Update Configuration

1. Open `config/database.php` in a text editor
2. Update these values with your Hostinger credentials:

```php
$db_host = 'localhost';  // Usually stays the same for Hostinger
$db_user = 'ent_user';   // MySQL username you created
$db_pass = 'your_strong_password';  // MySQL password you created
$db_name = 'enterprise_management';  // Database name you created
```

**Important:** Keep this file secure. Never share these credentials.

### 4. Upload Files to Hostinger

**Using cPanel File Manager:**
1. In cPanel, click **File Manager**
2. Navigate to the `public_html` folder
3. Click **Upload** button
4. Select all project files (or upload as a ZIP and extract)
5. Ensure folder structure is maintained:
   - `config/` folder with 3 PHP files
   - `api/` folder with 8 PHP files
   - `public/` folder with HTML files
   - `index.php` in root
   - `.htaccess` files in both root and public/

**Using FTP:**
1. Connect to your Hostinger account via FTP client (e.g., FileZilla)
2. Navigate to `public_html`
3. Upload all files maintaining the exact folder structure
4. Ensure permissions are set correctly (644 for files, 755 for directories)

### 5. Verify Installation

1. Open your browser and go to:
   ```
   https://yourdomain.com/public/login.html
   ```

2. You should see the login page with the Enterprise Management System header

3. Log in with default credentials:
   - **Username:** `admin`
   - **Password:** `admin123`

4. You should see the Dashboard

## First Time Setup

### ⚠️ CRITICAL: Change Default Admin Password

1. After logging in as admin, immediately change the password
2. Go to your database management and update the admin user password
3. Or create a new admin account and delete the default one

### Create Team Accounts

1. Click the **Register** link on the login page
2. Create accounts for your team members
3. Assign appropriate roles based on their responsibilities

## User Roles and Permissions

### 1. Admin
- Full system access
- User management
- View all modules
- Access audit logs
- Change system settings

### 2. Manager
- Department-level access
- Create and manage resources in assigned modules
- View reports and analytics
- Cannot access admin functions

### 3. Operator
- Data entry and operational tasks
- View assigned resources
- Limited modification capabilities
- Cannot delete records

### 4. Auditor
- Read-only access to all data
- View audit logs and compliance reports
- Cannot modify any data
- Used for compliance and internal audit

## Module Usage Guide

### Stores (Inventory Hub)
- Create and manage store locations
- Add inventory items with SKU tracking
- Process inventory transactions (IN, OUT, ADJUST, RETURN)
- View low-stock alerts
- Track reorder levels

### Purchases
- Register suppliers
- Create and track purchase orders
- Full PO workflow (Draft → Pending → Confirmed → Received)
- Automatically updates inventory when items are received
- Tracks supplier performance

### Foundry
- Define foundry materials and processes
- Create and track manufacturing batches
- Calculate yield percentages
- Add quality control notes
- Track batch history

### Production
- Create manufacturing orders for products
- Define production stages (Setup, Manufacturing, Inspection, Packaging)
- Assign operators to stages
- Track progress through multi-stage workflow
- Add quality notes per stage

### Dispatch
- Create shipments from production orders
- Track carriers and tracking numbers
- Update shipment status throughout delivery
- View on-time delivery metrics
- Track destination and delivery dates

### HR
- Register employees with departments and positions
- Track hire dates and salaries
- Link employee accounts to system users
- Manage active/inactive status
- Department-based employee filtering

### Die Shop
- Register equipment and tools
- Track equipment status (active, maintenance, retired)
- Schedule maintenance dates
- Get alerts for overdue maintenance
- Track equipment history

## API Endpoints

All endpoints return JSON and require authentication via session.

### Authentication
- `POST /api/auth?action=login` - User login
- `POST /api/auth?action=register` - User registration
- `GET /api/auth?action=logout` - User logout
- `GET /api/auth?action=current` - Get current user info

### Stores
- `GET /api/stores?action=list-stores` - List all stores
- `POST /api/stores?action=create-store` - Create store
- `GET /api/stores?action=list-inventory&store_id=1` - List inventory
- `POST /api/stores?action=add-item` - Add inventory item
- `POST /api/stores?action=transaction` - Process transaction

### Purchases
- `GET /api/purchases?action=list-suppliers` - List suppliers
- `POST /api/purchases?action=create-supplier` - Create supplier
- `GET /api/purchases?action=list-pos` - List purchase orders
- `POST /api/purchases?action=create-po` - Create PO

### Other Modules
Similar patterns for `/api/foundry`, `/api/production`, `/api/dispatch`, `/api/hr`, `/api/die-shop`

## Troubleshooting

### 403 Forbidden Error
- Verify all files are uploaded to `public_html`
- Check that `.htaccess` files are in correct locations
- Ensure file permissions are correct (644 for files)

### Database Connection Error
1. Check credentials in `config/database.php`
2. Verify MySQL user exists in cPanel
3. Verify database exists in cPanel
4. Test MySQL connection in cPanel → MySQL Databases

### Login Fails
1. Verify database schema was imported correctly
2. Check that admin user exists in `users` table
3. Try the default credentials: `admin` / `admin123`
4. Verify password hasn't been changed

### API Returns "Unauthorized"
1. Make sure you're logged in first
2. Check user session is active
3. Verify user role has permission for the action

### Blank Pages
1. Check browser console for JavaScript errors
2. Verify API endpoints are returning JSON
3. Check that PHP errors are not being hidden
4. Enable error reporting temporarily in `config/database.php`

## Security Recommendations

1. **Change Default Password** - Do this immediately after first login
2. **Use HTTPS** - Always use SSL/TLS encryption (https://)
3. **Strong Passwords** - Enforce strong password policies for users
4. **Regular Backups** - Backup database regularly from cPanel
5. **Update PHP** - Keep PHP version updated in Hostinger settings
6. **Limit Access** - Use firewall rules to limit admin access if possible
7. **Audit Logs** - Regularly review audit logs for suspicious activity
8. **Delete Test Data** - Remove demo/test data before going live

## Backup and Recovery

### Backup Database
1. cPanel → phpMyAdmin
2. Select database → Export
3. Format: SQL
4. Click Go and save the file

### Backup Files
1. cPanel → File Manager
2. Select all files in `public_html`
3. Right-click → Compress
4. Download the .zip file

### Restore Database
1. cPanel → phpMyAdmin
2. Select database → Import
3. Choose backup SQL file
4. Click Go

## Performance Tips

1. **Enable Caching** - Use browser caching headers if supported
2. **Optimize Images** - Compress images before upload
3. **Database Indexes** - Schema includes appropriate indexes
4. **Limit Results** - Paginate large result sets
5. **Use CDN** - Consider CDN for static assets (CSS, JS)

## Support and Documentation

- **Hostinger Help**: https://support.hostinger.com
- **cPanel Documentation**: https://cpanel.net/
- **PHP Documentation**: https://www.php.net/manual/
- **MySQL Documentation**: https://dev.mysql.com/doc/

## License

This system is provided as-is for use on Hostinger or compatible hosting environments.

## Version

**Version 1.0** - PHP Edition for Hostinger Shared Hosting
Released: January 2026

---

**Questions?** Refer to `INSTALLATION_GUIDE.txt` for step-by-step setup instructions or contact Hostinger support.
