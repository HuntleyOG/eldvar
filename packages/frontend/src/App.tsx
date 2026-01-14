import { Routes, Route, Link } from 'react-router-dom';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';
import { GamePage } from './pages/GamePage';
import { ProfilePage } from './pages/ProfilePage';
import { EditProfilePage } from './pages/EditProfilePage';
import { TownPage } from './pages/TownPage';
import { TravelPage } from './pages/TravelPage';
import { CombatPage } from './pages/CombatPage';

function HomePage() {
  return (
    <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white">
      <div className="container mx-auto px-4 py-16">
        <div className="text-center mb-12">
          <h1 className="text-6xl font-bold mb-4 bg-gradient-to-r from-blue-400 to-purple-600 bg-clip-text text-transparent">
            Eldvar
          </h1>
          <p className="text-xl text-gray-300">
            A browser-based text MMO with turn-based combat
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto">
          <div className="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 className="text-2xl font-bold mb-3 text-blue-400">⚔️ Combat</h2>
            <p className="text-gray-300">
              Engage in strategic turn-based battles with 5 unique combat styles.
            </p>
          </div>

          <div className="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 className="text-2xl font-bold mb-3 text-purple-400">📈 Skills</h2>
            <p className="text-gray-300">
              Train 9 skills with OSRS-inspired progression from level 1 to 99.
            </p>
          </div>

          <div className="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 className="text-2xl font-bold mb-3 text-green-400">🏰 Explore</h2>
            <p className="text-gray-300">
              Descend through tower floors with increasing difficulty and rewards.
            </p>
          </div>
        </div>

        <div className="text-center mt-12">
          <div className="inline-flex gap-4">
            <Link to="/login">
              <button className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-lg transition">
                Login
              </button>
            </Link>
            <Link to="/register">
              <button className="bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-8 rounded-lg transition">
                Register
              </button>
            </Link>
          </div>
        </div>

        <div className="mt-16 text-center text-gray-500">
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
    </Routes>
  );
}

export default App;
