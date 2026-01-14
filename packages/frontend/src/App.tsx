import { Routes, Route, Link } from 'react-router-dom';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';
import { GamePage } from './pages/GamePage';
import { ProfilePage } from './pages/ProfilePage';
import { EditProfilePage } from './pages/EditProfilePage';
import { TownPage } from './pages/TownPage';
import { TravelPage } from './pages/TravelPage';
import { CombatPage } from './pages/CombatPage';
import { AdminPanel } from './pages/AdminPanel';

function HomePage() {
  return (
    <div className="min-h-screen bg-pixel-bg text-pixel-text">
      <div className="container mx-auto px-4 py-16">
        <div className="text-center mb-12">
          <h1 className="text-6xl font-bold mb-4 text-pixel-primary" style={{ textShadow: '4px 4px 0px rgba(0,0,0,0.5)' }}>
            ELDVAR
          </h1>
          <p className="text-xl text-pixel-text mt-4">
            A browser-based text MMO with turn-based combat
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto">
          <div className="panel-pixel p-6">
            <h2 className="text-2xl font-bold mb-3 text-pixel-primary">⚔️ Combat</h2>
            <p className="text-pixel-text">
              Engage in strategic turn-based battles with 5 unique combat styles.
            </p>
          </div>

          <div className="panel-pixel p-6">
            <h2 className="text-2xl font-bold mb-3 text-pixel-secondary">📈 Skills</h2>
            <p className="text-pixel-text">
              Train 9 skills with OSRS-inspired progression from level 1 to 99.
            </p>
          </div>

          <div className="panel-pixel p-6">
            <h2 className="text-2xl font-bold mb-3 text-pixel-success">🏰 Explore</h2>
            <p className="text-pixel-text">
              Descend through tower floors with increasing difficulty and rewards.
            </p>
          </div>
        </div>

        <div className="text-center mt-12">
          <div className="inline-flex gap-4">
            <Link to="/login">
              <button className="btn-pixel bg-pixel-primary hover:bg-red-600 text-white">
                LOGIN
              </button>
            </Link>
            <Link to="/register">
              <button className="btn-pixel bg-pixel-secondary hover:bg-purple-600 text-white">
                REGISTER
              </button>
            </Link>
          </div>
        </div>

        <div className="mt-16 text-center text-pixel-muted">
          <p>🚧 Eldvar v2.0 - Rebuilt with modern tech stack 🚧</p>
          <p className="mt-2 text-sm">
            NestJS • React • TypeScript • Prisma • PostgreSQL • WebSockets
          </p>
        </div>
      </div>
    </div>
  );
}

function App() {
  return (
    <Routes>
      <Route path="/" element={<HomePage />} />
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />
      <Route path="/game" element={<GamePage />} />
      <Route path="/profile" element={<ProfilePage />} />
      <Route path="/profile/edit" element={<EditProfilePage />} />
      <Route path="/profile/:username" element={<ProfilePage />} />
      <Route path="/town" element={<TownPage />} />
      <Route path="/travel" element={<TravelPage />} />
      <Route path="/combat" element={<CombatPage />} />
      <Route path="/combat/:battleId" element={<CombatPage />} />
      <Route path="/admin" element={<AdminPanel />} />
    </Routes>
  );
}

export default App;
