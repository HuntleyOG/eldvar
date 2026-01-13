# Eldvar Production Deployment Guide

## Current Issue

You're seeing a **403 Forbidden** error because Apache is not configured to serve the Eldvar application.

## Prerequisites

1. PostgreSQL database running and seeded
2. Node.js 18+ and pnpm installed
3. Apache web server running
4. FASTPANEL control panel access

## Step 1: Install Dependencies

From your application directory:

```bash
cd /var/www/eldvar_com_usr/data/www/eldvar.com
pnpm install
```

## Step 2: Build the Frontend

Build the React application for production:

```bash
pnpm build:frontend
```

This creates optimized static files in: `packages/frontend/dist/`

## Step 3: Configure Environment Variables

### Backend Environment (.env)

Make sure your production `.env` file has the correct settings:

```bash
cd packages/backend
nano .env
```

Update these values:

```env
NODE_ENV=production
PORT=3001
API_PREFIX=api

# Database - Use your production database
DATABASE_URL="postgresql://eldvar:YOUR_PASSWORD@localhost:5432/eldvar_prod"

# Security - CHANGE THESE IN PRODUCTION!
SESSION_SECRET=<generate-random-secret>
JWT_SECRET=<generate-random-secret>
JWT_EXPIRES_IN=7d

# CORS - Your production domain
CORS_ORIGIN=https://eldvar.com
```

### Frontend Environment

Create `packages/frontend/.env.production`:

```env
VITE_API_URL=https://eldvar.com/api
VITE_WS_URL=https://eldvar.com
```

Then rebuild the frontend:

```bash
pnpm build:frontend
```

## Step 4: Set Up Process Manager (PM2)

Install PM2 to keep your backend running:

```bash
npm install -g pm2
```

Build and start the backend:

```bash
cd packages/backend
pnpm build
pm2 start dist/main.js --name eldvar-api
pm2 save
pm2 startup
```

Verify it's running:

```bash
pm2 status
pm2 logs eldvar-api
```

The backend should now be running on `http://localhost:3001`

## Step 5: Configure Apache

### Option A: Using FASTPANEL Control Panel

1. Log into your FASTPANEL control panel
2. Go to your domain settings for `eldvar.com`
3. Set the document root to: `/var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist`
4. Enable proxy for API requests:
   - Proxy `/api/*` to `http://localhost:3001/api/`
   - Proxy `/socket.io/*` to `http://localhost:3001/socket.io/`

### Option B: Manual Apache Configuration

If FASTPANEL doesn't support proxy configuration, you'll need a reverse proxy setup.

1. Enable required Apache modules:

```bash
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod proxy_wstunnel
sudo a2enmod rewrite
sudo systemctl restart apache2
```

2. Create Apache config file (through FASTPANEL or manually):

```apache
<VirtualHost *:443>
    ServerName eldvar.com

    # SSL Configuration (managed by FASTPANEL)
    # SSLEngine on
    # SSLCertificateFile ...
    # SSLCertificateKeyFile ...

    # Document root for React app
    DocumentRoot /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist

    <Directory /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # React Router SPA configuration
        RewriteEngine On
        RewriteBase /
        RewriteRule ^index\.html$ - [L]
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_URI} !^/api
        RewriteRule . /index.html [L]
    </Directory>

    # Proxy API requests to NestJS backend
    ProxyPreserveHost On
    ProxyPass /api http://localhost:3001/api
    ProxyPassReverse /api http://localhost:3001/api

    # Proxy WebSocket connections
    ProxyPass /socket.io http://localhost:3001/socket.io
    ProxyPassReverse /socket.io http://localhost:3001/socket.io

    # Log files
    ErrorLog ${APACHE_LOG_DIR}/eldvar-error.log
    CustomLog ${APACHE_LOG_DIR}/eldvar-access.log combined
</VirtualHost>
```

3. Test and reload Apache:

```bash
sudo apachectl configtest
sudo systemctl reload apache2
```

## Step 6: Database Setup (Production)

If you haven't set up the production database yet:

```bash
# Create production database
sudo -u postgres psql -c "CREATE DATABASE eldvar_prod OWNER eldvar;"

# Run migrations
cd packages/backend
PGPASSWORD='your_password' psql -U eldvar -h localhost -p 5432 -d eldvar_prod -f prisma/migrations/001_init.sql

# Seed data
PGPASSWORD='your_password' psql -U eldvar -h localhost -p 5432 -d eldvar_prod -f prisma/seed.sql
```

## Step 7: File Permissions

Ensure Apache can read the files:

```bash
cd /var/www/eldvar_com_usr/data/www/eldvar.com
sudo chown -R www-data:www-data packages/frontend/dist
sudo chmod -R 755 packages/frontend/dist
```

## Step 8: Test the Deployment

1. **Check backend is running:**
   ```bash
   curl http://localhost:3001/api
   ```

2. **Check frontend files exist:**
   ```bash
   ls -la packages/frontend/dist/
   ```

3. **Visit your site:**
   - Open https://eldvar.com in your browser
   - You should see the landing page
   - Click "Register" and create an account
   - Test login/logout

## Troubleshooting

### Still getting 403 Forbidden?

**Check file permissions:**
```bash
ls -la packages/frontend/dist/
```

All files should be readable by Apache (www-data user).

**Check Apache error logs:**
```bash
sudo tail -50 /var/log/apache2/eldvar-error.log
```

**Check if index.html exists:**
```bash
cat packages/frontend/dist/index.html
```

If it doesn't exist, rebuild:
```bash
pnpm build:frontend
```

### Backend API not working?

**Check if backend is running:**
```bash
pm2 status
pm2 logs eldvar-api
```

**Test backend directly:**
```bash
curl http://localhost:3001/api
```

**Check backend logs:**
```bash
pm2 logs eldvar-api --lines 100
```

### Database connection errors?

**Verify DATABASE_URL in .env:**
```bash
cat packages/backend/.env | grep DATABASE_URL
```

**Test database connection:**
```bash
PGPASSWORD='your_password' psql -U eldvar -h localhost -p 5432 -d eldvar_prod -c "SELECT COUNT(*) FROM users;"
```

## Quick Commands Reference

```bash
# Rebuild and deploy
pnpm build:frontend
pm2 restart eldvar-api

# View logs
pm2 logs eldvar-api
sudo tail -f /var/log/apache2/eldvar-error.log

# Backend management
pm2 status
pm2 restart eldvar-api
pm2 stop eldvar-api
pm2 logs eldvar-api

# Database
pm2 logs eldvar-api | grep database
PGPASSWORD='password' psql -U eldvar -d eldvar_prod
```

## Security Checklist

- [ ] Changed SESSION_SECRET from default
- [ ] Changed JWT_SECRET from default
- [ ] Database password is strong
- [ ] CORS_ORIGIN is set to your domain only
- [ ] SSL certificate is installed (HTTPS)
- [ ] Firewall allows only 80, 443, 22
- [ ] Database not exposed to public internet

## Alternative: Docker Deployment

If you prefer Docker (not using FASTPANEL):

```bash
# Build images
docker compose build

# Start services
docker compose up -d

# View logs
docker compose logs -f
```

Then configure Apache to proxy to the Docker containers instead.

## Support

If you continue to have issues:

1. Share the output of these commands:
   ```bash
   pm2 status
   ls -la packages/frontend/dist/
   sudo apachectl -t
   sudo tail -50 /var/log/apache2/eldvar-error.log
   ```

2. Check the SETUP_GUIDE.md for development environment details

3. Join the Discord for support

---

**After completing these steps**, your site should be live at https://eldvar.com with:
- Landing page at `/`
- Login at `/login`
- Register at `/register`
- Game dashboard at `/game` (after authentication)
