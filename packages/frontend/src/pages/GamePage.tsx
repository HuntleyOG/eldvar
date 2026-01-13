import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { authApi } from '../lib/api';

export function GamePage() {
  const navigate = useNavigate();
  const { user, clearAuth } = useAuthStore();

  const handleLogout = async () => {
    try {
      await authApi.logout();
    } catch (error) {
      console.error('Logout error:', error);
    } finally {
      clearAuth();
      navigate('/');
    }
  };

  if (!user) {
    navigate('/login');
    return null;
  }

  return (
    <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white">
      <div className="container mx-auto px-4 py-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="text-4xl font-bold bg-gradient-to-r from-blue-400 to-purple-600 bg-clip-text text-transparent">
              Welcome to Eldvar
            </h1>
            <p className="text-gray-400 mt-2">
              Hello, {user.displayName || user.username}!
            </p>
          </div>
          <button
            onClick={handleLogout}
            className="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded-lg transition"
          >
            Logout
          </button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto">
          <div className="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 className="text-2xl font-bold mb-4 text-blue-400">Your Profile</h2>
            <div className="space-y-2 text-gray-300">
              <p><span className="font-semibold">Username:</span> {user.username}</p>
              <p><span className="font-semibold">Level:</span> {user.level}</p>
              <p><span className="font-semibold">Role:</span> {user.role}</p>
              {user.email && <p><span className="font-semibold">Email:</span> {user.email}</p>}
              <p>
                <span className="font-semibold">Status:</span>{' '}
                <span className={user.verified ? 'text-green-400' : 'text-yellow-400'}>
                  {user.verified ? 'Verified' : 'Unverified'}
                </span>
              </p>
            </div>
          </div>

          <div className="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 className="text-2xl font-bold mb-4 text-purple-400">Quick Start</h2>
            <div className="space-y-3">
              <button className="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition">
                Enter Combat
              </button>
              <button className="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg transition">
                View Skills
              </button>
              <button className="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg transition">
                Explore World
              </button>
            </div>
          </div>
        </div>

        <div className="mt-8 text-center text-gray-500">
          <p>🚧 Game features coming soon 🚧</p>
        </div>
      </div>
    </div>
  );
}
