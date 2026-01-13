# Eldvar Setup Guide

## What Was Done

This setup includes a complete user authentication system, database initialization, and landing page for Eldvar.

### Database Setup ✅

1. **PostgreSQL Database Created**
   - Database: `eldvar_dev`
   - User: `eldvar`
   - All tables created from Prisma schema

2. **Tables Initialized**
   - Users & Authentication
   - Skills System (9 skills)
   - XP Thresholds (levels 1-99, OSRS-style)
   - Combat System (Mobs, Battles, Battle Turns)
   - Guild System
   - Items & Inventory
   - Chat System
   - Wiki System
   - World Areas & Towers
   - Admin & Support
   - News System

3. **Seed Data Added**
   - 9 skills (Attack, Strength, Defense, Health, Range, Magic, Mining, Crafting, Blacksmithing)
   - 99 XP thresholds (OSRS formula)
   - 5 world areas (Mystic Harshlands, Yulon Forest, Reichal, Undar, Frostbound Tundra)
   - 5 sample mobs (Goblin Scout, Dark Wolf, Shadow Mage, Frost Giant, Void Wraith)
   - 9 game settings

### Backend Changes ✅

1. **Auth Service Enhanced**
   - `login()` now returns JWT token + full user info (id, username, email, role, displayName, avatarUrl, level, verified)
   - `register()` now automatically logs in user and returns JWT token
   - All new users start at level 1 with all skills initialized

2. **API Endpoints Available**
   - `POST /api/auth/register` - Register new account
   - `POST /api/auth/login` - Login
   - `POST /api/auth/logout` - Logout

### Frontend Changes ✅

1. **Landing Page Updated**
   - Functional Login and Register buttons
   - Links to `/login` and `/register` routes

2. **New Pages Created**
   - **LoginPage** (`/login`)
     - Username and password fields
     - Form validation
     - Error handling
     - Links to register page
   
   - **RegisterPage** (`/register`)
     - Username, password, confirm password
     - Optional email field
     - Client-side validation (min length, password match)
     - Links to login page
   
   - **GamePage** (`/game`)
     - User dashboard after authentication
     - Displays user profile info
     - Quick start buttons (placeholder)
     - Logout functionality

3. **Authentication System**
   - API service layer with axios
   - Zustand store for auth state (persisted)
   - JWT token management
   - Protected routes

## How to Run

### Prerequisites

Before running the application, you need to install dependencies:

```bash
# From project root
pnpm install
```

### Starting the Application

1. **Ensure PostgreSQL is running** (already started in this session)

2. **Start the backend:**
   ```bash
   pnpm dev:backend
   ```
   Backend will run on: http://localhost:3001

3. **Start the frontend** (in a new terminal):
   ```bash
   pnpm dev:frontend
   ```
   Frontend will run on: http://localhost:5173

### Testing the Flow

1. Open http://localhost:5173 in your browser
2. Click "Register" button
3. Create an account:
   - Username: (minimum 3 characters)
   - Password: (minimum 6 characters)
   - Email: (optional)
4. You'll be automatically logged in and redirected to `/game`
5. View your profile information
6. Click "Logout" to return to landing page
7. Use "Login" to sign back in

## User Registration System

### Player ID (PID) System

As per the Eldvar Dev Wiki:
- Each user gets a unique Player ID (PID) starting from #000000
- Format: `Username#PID` (e.g., `Huntley#000001`)
- PIDs are permanent and allow for username changes in the future
- Currently, the ID is stored as an auto-incrementing integer in the database

### Account Verification

- New users have `verified: false` status
- Email verification system to be implemented
- Unverified users will have limited access (e.g., chat restrictions)

### Character Creation

- Characters are automatically created with the account
- All users start at level 1
- All 9 skills are initialized at level 1 with 0 XP:
  - Attack, Strength, Defense, Health
  - Range, Magic
  - Mining, Crafting, Blacksmithing

## Environment Variables

### Backend (packages/backend/.env)
```
NODE_ENV=development
PORT=3001
API_PREFIX=api
DATABASE_URL=postgresql://eldvar:eldvar_dev_password@localhost:5432/eldvar_dev
CORS_ORIGIN=http://localhost:5173
SESSION_SECRET=<your-session-secret>
JWT_SECRET=<your-jwt-secret>
JWT_EXPIRES_IN=7d
```

### Frontend (packages/frontend/.env)
```
VITE_API_URL=http://localhost:3001/api
VITE_WS_URL=http://localhost:3001
```

## Next Steps

### Immediate Features to Implement

1. **Email Verification**
   - Send verification emails on registration
   - Verify email endpoint
   - Resend verification email

2. **User Profile Page**
   - View full profile details
   - Edit profile (avatar, bio, status)
   - View skills progress

3. **Combat System**
   - Enter combat from game page
   - Battle interface
   - Turn-based combat mechanics

4. **Skills System**
   - View skills page
   - Track XP and levels
   - Skill-specific activities

5. **World Exploration**
   - World map
   - Travel between areas
   - Tower entries

### Database Management

To view/edit database contents:
```bash
pnpm prisma:studio
```
This opens Prisma Studio on http://localhost:5555

To create new migrations after schema changes:
```bash
cd packages/backend
npx prisma migrate dev --name description_of_changes
```

## Architecture

### Tech Stack
- **Frontend**: React 18, TypeScript, Vite, TailwindCSS
- **Backend**: NestJS 10, TypeScript
- **Database**: PostgreSQL 16 + Prisma ORM
- **State Management**: Zustand (auth), TanStack Query (API)
- **Authentication**: JWT + Passport.js

### Project Structure
```
eldvar/
├── packages/
│   ├── backend/          # NestJS API
│   │   ├── prisma/       # Database schema & migrations
│   │   └── src/
│   │       ├── modules/
│   │       │   ├── auth/    # ✅ Complete
│   │       │   ├── users/   # 🚧 To implement
│   │       │   ├── combat/  # 🚧 To implement
│   │       │   ├── skills/  # 🚧 To implement
│   │       │   └── ...
│   │       └── common/
│   │
│   ├── frontend/         # React app
│   │   └── src/
│   │       ├── pages/       # ✅ Login, Register, Game
│   │       ├── lib/         # ✅ API service
│   │       └── store/       # ✅ Auth store
│   │
│   └── shared/           # Shared types
```

## Troubleshooting

### Backend won't start
- Ensure PostgreSQL is running: `sudo service postgresql start`
- Check .env file has correct DATABASE_URL
- Run `pnpm install` to ensure dependencies are installed

### Frontend API errors
- Ensure backend is running on port 3001
- Check CORS_ORIGIN in backend .env matches frontend URL
- Verify VITE_API_URL in frontend .env

### Database connection errors
- Verify PostgreSQL is running
- Check database credentials
- Ensure `eldvar_dev` database exists

### Login/Register not working
- Check browser console for errors
- Verify backend API is accessible
- Check network tab for API responses

## Support

For issues or questions:
- Join the Discord
- Email: huntley@eldvar.com or brommyr@eldvar.com
- GitHub Issues: https://github.com/HuntleyOG/eldvar/issues

---

**Last Updated**: 2026-01-13
**Version**: 2.0.0
