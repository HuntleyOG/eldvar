# Apache Configuration for Eldvar (FASTPANEL)

## Quick Fix for 403 Forbidden Error

Your current issue: Apache doesn't know how to serve the React application.

### Immediate Solution

1. **Build the application first:**
   ```bash
   cd /var/www/eldvar_com_usr/data/www/eldvar.com
   bash scripts/deploy-production.sh
   ```

2. **Configure in FASTPANEL:**
   - Log into FASTPANEL control panel
   - Find your domain `eldvar.com` settings
   - Change **Document Root** to:
     ```
     /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist
     ```
   - Save and apply changes

3. **Set up API proxy in FASTPANEL:**

   If FASTPANEL has proxy settings:
   - Add proxy rule: `/api/*` → `http://localhost:3001/api/`
   - Add proxy rule: `/socket.io/*` → `http://localhost:3001/socket.io/`

4. **Test your site:**
   - Visit https://eldvar.com
   - You should see the Eldvar landing page

---

## Manual Apache Configuration (If FASTPANEL doesn't support proxy)

### Step 1: Create .htaccess in dist directory

```bash
cat > /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist/.htaccess << 'EOF'
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /

  # Don't rewrite API requests (let them 404 so we can handle via proxy)
  RewriteCond %{REQUEST_URI} ^/api [NC]
  RewriteRule ^ - [L]

  # Don't rewrite files that exist
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME} !-l

  # Rewrite everything else to index.html for React Router
  RewriteRule ^ /index.html [L]
</IfModule>

# Security headers
<IfModule mod_headers.c>
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-XSS-Protection "1; mode=block"
</IfModule>
EOF
```

### Step 2: Full Apache VirtualHost Configuration

Create: `/etc/apache2/fastpanel2-available/eldvar.com.conf`

**For HTTP (port 80):**

```apache
<VirtualHost *:80>
    ServerName eldvar.com
    ServerAdmin admin@eldvar.com

    # Redirect all HTTP to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>
```

**For HTTPS (port 443):**

```apache
<VirtualHost *:443>
    ServerName eldvar.com
    ServerAdmin admin@eldvar.com

    # SSL Configuration (likely managed by FASTPANEL/Let's Encrypt)
    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/privkey.pem
    SSLCertificateChainFile /path/to/chain.pem

    # Document root - React build files
    DocumentRoot /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist

    <Directory /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist>
        Options -Indexes +FollowSymLinks -MultiViews
        AllowOverride All
        Require all granted

        # React Router SPA handling
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteBase /

            # Don't rewrite API requests
            RewriteCond %{REQUEST_URI} ^/api [NC,OR]
            RewriteCond %{REQUEST_URI} ^/socket\.io [NC]
            RewriteRule ^ - [L]

            # Handle React Router
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ /index.html [L]
        </IfModule>
    </Directory>

    # Proxy API requests to NestJS backend
    <IfModule mod_proxy.c>
        ProxyPreserveHost On
        ProxyAddHeaders On

        # API endpoints
        ProxyPass /api http://localhost:3001/api
        ProxyPassReverse /api http://localhost:3001/api

        # WebSocket support
        RewriteEngine On
        RewriteCond %{HTTP:Upgrade} =websocket [NC]
        RewriteRule /socket.io/(.*) ws://localhost:3001/socket.io/$1 [P,L]

        ProxyPass /socket.io http://localhost:3001/socket.io
        ProxyPassReverse /socket.io http://localhost:3001/socket.io
    </IfModule>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/eldvar-error.log
    CustomLog ${APACHE_LOG_DIR}/eldvar-access.log combined

    # Log level for debugging
    LogLevel warn
</VirtualHost>
```

### Step 3: Enable Required Apache Modules

```bash
sudo a2enmod rewrite
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod proxy_wstunnel
sudo a2enmod headers
sudo a2enmod ssl
```

### Step 4: Test and Restart Apache

```bash
# Test configuration
sudo apachectl configtest

# If no errors, restart Apache
sudo systemctl restart apache2

# Check status
sudo systemctl status apache2
```

---

## Verification Checklist

After configuration, verify everything works:

### 1. Check Frontend Build Exists
```bash
ls -la /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist/
```

You should see:
- `index.html`
- `assets/` directory with JS/CSS files

### 2. Check Backend is Running
```bash
pm2 status
```

Should show `eldvar-api` with status `online`.

### 3. Test Backend API Directly
```bash
curl http://localhost:3001/api
```

Should return a response (not 404).

### 4. Check File Permissions
```bash
sudo chown -R www-data:www-data /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist
sudo chmod -R 755 /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist
```

### 5. Test in Browser

1. **Landing page:** https://eldvar.com
   - Should show the Eldvar homepage with Login/Register buttons

2. **Register page:** https://eldvar.com/register
   - Should show registration form

3. **API test:** https://eldvar.com/api
   - Should return data (or structured error), not 404

4. **Browser console:**
   - Open DevTools → Console
   - Should see no CORS errors
   - Should see no 404 errors for API calls

---

## Troubleshooting

### Still Getting 403 Forbidden?

**1. Check Apache error logs:**
```bash
sudo tail -100 /var/log/apache2/eldvar-error.log
```

Common issues:
- `AH01630: client denied by server configuration` → Check `Require all granted` in Directory block
- `Permission denied` → Run `sudo chown -R www-data:www-data` on dist directory

**2. Check file permissions:**
```bash
namei -l /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist/index.html
```

All directories should have execute permission (x), files should be readable (r).

**3. Verify document root:**
```bash
sudo apachectl -S | grep eldvar
```

Should show your VirtualHost with correct DocumentRoot.

### API Requests Failing (CORS errors)?

**1. Check backend CORS_ORIGIN in .env:**
```bash
cat /var/www/eldvar_com_usr/data/www/eldvar.com/packages/backend/.env | grep CORS_ORIGIN
```

Should be: `CORS_ORIGIN=https://eldvar.com`

**2. Restart backend:**
```bash
pm2 restart eldvar-api
pm2 logs eldvar-api
```

**3. Test API proxy:**
```bash
curl -H "Origin: https://eldvar.com" https://eldvar.com/api
```

### Assets Not Loading (404 for CSS/JS)?

**1. Verify base path in index.html:**
```bash
grep '<base href' /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist/index.html
```

Should be `<base href="/" />` or not present.

**2. Check asset paths:**
```bash
grep -o 'src="[^"]*"' /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist/index.html
```

Paths should be absolute (`/assets/...`) or relative without leading `/`.

**3. Rebuild with correct base:**
```bash
cd /var/www/eldvar_com_usr/data/www/eldvar.com
pnpm build:frontend
```

---

## Alternative: Nginx Configuration (If available)

If you prefer Nginx over Apache:

```nginx
server {
    listen 443 ssl http2;
    server_name eldvar.com;

    # SSL Configuration
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/privkey.pem;

    # Frontend static files
    root /var/www/eldvar_com_usr/data/www/eldvar.com/packages/frontend/dist;
    index index.html;

    # React Router - serve index.html for all non-file requests
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Proxy API requests to backend
    location /api {
        proxy_pass http://localhost:3001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # WebSocket support
    location /socket.io {
        proxy_pass http://localhost:3001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}

# HTTP to HTTPS redirect
server {
    listen 80;
    server_name eldvar.com;
    return 301 https://$server_name$request_uri;
}
```

---

## Quick Commands

```bash
# Redeploy everything
cd /var/www/eldvar_com_usr/data/www/eldvar.com
bash scripts/deploy-production.sh

# Restart services
pm2 restart eldvar-api
sudo systemctl restart apache2

# View logs
pm2 logs eldvar-api
sudo tail -f /var/log/apache2/eldvar-error.log

# Test configuration
sudo apachectl configtest
curl http://localhost:3001/api
curl https://eldvar.com/api
```

---

After following this guide, your site should be fully functional at https://eldvar.com!
