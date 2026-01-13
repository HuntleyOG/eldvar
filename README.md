# Eldvar

A browser-based text MMO featuring turn-based combat, OSRS-style skill progression, and a persistent fantasy world.

## 🎮 Features

- **Turn-Based Combat**: Strategic battles with 5 combat styles (Attack, Strength, Defense, Range, Magic)
- **Skill Progression**: OSRS-inspired XP system with 9 skills to train
- **Floor-Based Exploration**: Descend through tower floors with increasing difficulty
- **Void Pressure Mechanics**: Dynamic difficulty scaling based on floor depth
- **Guild System**: Create or join guilds with other players
- **Real-Time Chat**: WebSocket-powered chat across multiple channels
- **Wiki System**: Community-maintained game documentation
- **Admin Panel**: Comprehensive game management tools

## 🛠️ Tech Stack

### Backend
- **NestJS** - Enterprise TypeScript framework
- **Prisma ORM** - Type-safe database access
- **PostgreSQL** - Primary database
- **Redis** - Caching and session management
- **Socket.io** - Real-time WebSocket communication
- **Passport.js** - Authentication

### Frontend
- **React 18** - UI framework
- **TypeScript** - Type safety
- **Vite** - Fast build tool
- **TanStack Query** - Server state management
- **Zustand** - Client state management
- **Tailwind CSS** - Styling
- **Socket.io Client** - WebSocket client

## 🚀 Getting Started

### Prerequisites

- Node.js 18+
- PNPM 8+
- Docker & Docker Compose

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/HuntleyOG/eldvar.git
   cd eldvar
   ```

2. **Install dependencies**
   ```bash
   pnpm install
   ```

3. **Start Docker services (PostgreSQL & Redis)**
   ```bash
   pnpm docker:up
   ```

4. **Setup environment variables**
   ```bash
   cp packages/backend/.env.example packages/backend/.env
   cp packages/frontend/.env.example packages/frontend/.env
   ```

5. **Run database migrations**
   ```bash
   pnpm prisma:migrate
   ```

6. **Start development servers**
   ```bash
   # Start both backend and frontend
   pnpm dev

   # Or start individually
   pnpm dev:backend
   pnpm dev:frontend
   ```

### Access the Application

- **Frontend**: http://localhost:5173
- **Backend API**: http://localhost:3000
- **Prisma Studio**: http://localhost:5555 (run `pnpm prisma:studio`)

## 📁 Project Structure

```
eldvar/
├── packages/
│   ├── backend/          # NestJS API server
│   │   ├── src/
│   │   │   ├── modules/  # Feature modules
│   │   │   ├── common/   # Shared utilities
│   │   │   └── main.ts   # Application entry
│   │   └── prisma/       # Database schema & migrations
│   ├── frontend/         # React application
│   │   ├── src/
│   │   │   ├── features/ # Feature-based components
│   │   │   ├── shared/   # Shared components
│   │   │   └── App.tsx   # Root component
│   └── shared/           # Shared types & utilities
├── docker-compose.yml    # Local development services
└── package.json          # Workspace configuration
```

## 🧪 Development

### Available Scripts

```bash
# Development
pnpm dev                  # Start all packages in dev mode
pnpm dev:backend          # Start backend only
pnpm dev:frontend         # Start frontend only

# Building
pnpm build                # Build all packages
pnpm build:backend        # Build backend only
pnpm build:frontend       # Build frontend only

# Code Quality
pnpm lint                 # Lint all packages
pnpm test                 # Run tests

# Database
pnpm prisma:generate      # Generate Prisma client
pnpm prisma:migrate       # Run migrations
pnpm prisma:studio        # Open Prisma Studio

# Docker
pnpm docker:up            # Start services
pnpm docker:down          # Stop services
pnpm docker:logs          # View logs
```

## 🎯 Game Mechanics

### Combat System
- Turn-based battles against monsters
- 5 combat styles with unique characteristics
- Damage calculated based on stats and equipment
- Void pressure increases difficulty at deeper floors

### Skill Progression
- 9 skills: Attack, Strength, Defense, Health, Range, Magic, Mining, Crafting, Blacksmithing
- OSRS-style exponential XP curve (1-99)
- Overall level = average of all skill levels
- XP gains scale with floor depth

### Floor System
- Requires multiple wins to descend to next floor
- Void pressure increases with depth
- Unique mob pools per region/tower
- Track personal best (deepest floor reached)

## 🤝 Contributing

Contributions are welcome! Please read our contributing guidelines before submitting PRs.

## 📄 License

MIT License - see LICENSE file for details

## 🔗 Links

- **GitHub**: https://github.com/HuntleyOG/eldvar
- **Issues**: https://github.com/HuntleyOG/eldvar/issues
- **Wiki**: (Coming soon)
