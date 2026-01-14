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
    <div className="min-h-screen bg-pixel-bg text-pixel-text">
      <div className="container mx-auto px-4 py-8">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="text-4xl font-bold text-pixel-primary" style={{ textShadow: '4px 4px 0px rgba(0,0,0,0.5)' }}>
              WELCOME TO ELDVAR
            </h1>
            <p className="text-pixel-muted mt-2">
              Hello, {user.displayName || user.username}!
            </p>
          </div>
          <button
            onClick={handleLogout}
            className="btn-pixel bg-pixel-danger hover:bg-red-700 text-white"
          >
            LOGOUT
          </button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto">
          <div className="panel-pixel p-6">
            <h2 className="text-2xl font-bold mb-4 text-pixel-primary">YOUR PROFILE</h2>
            <div className="space-y-2 text-pixel-text">
              <p><span className="font-bold">Username:</span> {user.username}</p>
              <p><span className="font-bold">Level:</span> {user.level}</p>
              <p><span className="font-bold">Role:</span> {user.role}</p>
              {user.email && <p><span className="font-bold">Email:</span> {user.email}</p>}
              <p>
                <span className="font-bold">Status:</span>{' '}
                <span className={user.verified ? 'text-pixel-success' : 'text-pixel-warning'}>
                  {user.verified ? 'Verified' : 'Unverified'}
                </span>
              </p>
            </div>
          </div>

          <div className="panel-pixel p-6">
            <h2 className="text-2xl font-bold mb-4 text-pixel-secondary">QUICK ACTIONS</h2>
            <div className="space-y-3">
              <button
                onClick={() => navigate('/town')}
                className="w-full btn-pixel bg-pixel-primary hover:bg-red-600 text-white"
              >
                🏘️ VISIT TOWN
              </button>
              <button
                onClick={() => navigate('/profile')}
                className="w-full btn-pixel bg-pixel-success hover:bg-green-600 text-white"
              >
                👤 VIEW PROFILE
              </button>
              <button className="w-full btn-pixel bg-pixel-secondary hover:bg-purple-600 text-white">
                ⚔️ ENTER COMBAT
              </button>
            </div>
          </div>
        </div>

        <div className="mt-8 text-center text-pixel-muted">
          <p>🚧 Game features coming soon 🚧</p>
        </div>
      </div>
    </div>
  );
}
