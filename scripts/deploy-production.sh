#!/bin/bash

# Eldvar Production Deployment Script
# Run this from your project root: bash scripts/deploy-production.sh

set -e  # Exit on error

echo "======================================================================"
echo "           Eldvar Production Deployment Script"
echo "======================================================================"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if running from correct directory
if [ ! -f "package.json" ]; then
    echo -e "${RED}Error: Must run from project root directory${NC}"
    exit 1
fi

echo -e "${YELLOW}Step 1: Installing dependencies...${NC}"
pnpm install || {
    echo -e "${RED}Failed to install dependencies${NC}"
    exit 1
}

echo ""
echo -e "${YELLOW}Step 2: Building frontend...${NC}"
pnpm build:frontend || {
    echo -e "${RED}Failed to build frontend${NC}"
    exit 1
}

echo ""
echo -e "${YELLOW}Step 3: Building backend...${NC}"
pnpm build:backend || {
    echo -e "${RED}Failed to build backend${NC}"
    exit 1
}

echo ""
echo -e "${GREEN}✓ Build completed successfully!${NC}"
echo ""
echo "Frontend built to: packages/frontend/dist/"
echo "Backend built to: packages/backend/dist/"
echo ""

# Check if PM2 is installed
if command -v pm2 &> /dev/null; then
    echo -e "${YELLOW}Step 4: Deploying backend with PM2...${NC}"

    # Check if eldvar-api is already running
    if pm2 list | grep -q "eldvar-api"; then
        echo "Restarting existing eldvar-api process..."
        pm2 restart eldvar-api
    else
        echo "Starting new eldvar-api process..."
        cd packages/backend
        pm2 start dist/main.js --name eldvar-api --time
        cd ../..
    fi

    pm2 save
    echo ""
    echo -e "${GREEN}✓ Backend deployed with PM2${NC}"
    echo ""
    pm2 status
else
    echo -e "${YELLOW}⚠ PM2 not installed. Install with: npm install -g pm2${NC}"
    echo "Then start backend manually:"
    echo "  cd packages/backend && pm2 start dist/main.js --name eldvar-api"
fi

echo ""
echo "======================================================================"
echo -e "${GREEN}           Deployment Complete!${NC}"
echo "======================================================================"
echo ""
echo "Next steps:"
echo ""
echo "1. Configure Apache to serve:"
echo "   Document Root: $(pwd)/packages/frontend/dist"
echo ""
echo "2. Set up Apache proxy for API:"
echo "   Proxy /api/* to http://localhost:3001/api/*"
echo ""
echo "3. Check backend logs:"
echo "   pm2 logs eldvar-api"
echo ""
echo "4. Test your site at: https://eldvar.com"
echo ""
echo "For detailed configuration, see: PRODUCTION_DEPLOYMENT.md"
echo ""
