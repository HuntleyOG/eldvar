import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { locationApi, Location } from '../lib/api';

// Town services/buildings data
const townServices = [
  {
    section: 'Market',
    buildings: [
      { icon: '🛒', name: 'General Store', description: 'Buy basic supplies and equipment', path: '/shop' },
      { icon: '⚒️', name: 'Blacksmith', description: 'Forge and upgrade weapons', path: '/blacksmith' },
      { icon: '🧪', name: 'Alchemist', description: 'Brew potions and elixirs', path: '/alchemist' },
    ],
  },
  {
    section: 'Town Center',
    buildings: [
      { icon: '🏛️', name: 'Town Hall', description: 'Meet townsfolk and accept quests', path: '/town-hall' },
      { icon: '🏦', name: 'Bank', description: 'Store your gold safely', path: '/bank' },
      { icon: '📜', name: 'Quest Board', description: 'Find available quests', path: '/quests' },
    ],
  },
  {
    section: 'Training Grounds',
    buildings: [
      { icon: '⚔️', name: 'Combat Arena', description: 'Train your combat skills', path: '/arena' },
      { icon: '🎯', name: 'Archery Range', description: 'Practice your range skill', path: '/archery' },
      { icon: '✨', name: 'Magic Academy', description: 'Study magical arts', path: '/magic' },
    ],
  },
  {
    section: 'Exploration',
    buildings: [
      { icon: '🗺️', name: 'Tower Entrance', description: 'Enter the tower floors', path: '/tower' },
      { icon: '🌲', name: 'Wilderness', description: 'Venture into the wild', path: '/wilderness' },
      { icon: '⛏️', name: 'Mining Site', description: 'Gather ores and minerals', path: '/mining' },
    ],
  },
];

export function TownPage() {
  const navigate = useNavigate();
  const { user } = useAuthStore();
  const [location, setLocation] = useState<Location | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!user) {
      navigate('/login');
      return;
    }

    const loadLocation = async () => {
      try {
        setLoading(true);
        setError(null);
        const currentLocation = await locationApi.getCurrentLocation();
        setLocation(currentLocation);
      } catch (err: any) {
        console.error('Error loading location:', err);
        setError(err.response?.data?.message || 'Failed to load location');
      } finally {
        setLoading(false);
      }
    };

    loadLocation();
  }, [user, navigate]);

  const handleServiceClick = (path: string) => {
    // For now, show coming soon message
    alert(`${path} - Coming soon!`);
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-pixel-bg text-pixel-text flex items-center justify-center">
        <div className="text-2xl font-bold">LOADING...</div>
      </div>
    );
  }

  if (error || !location) {
    return (
      <div className="min-h-screen bg-pixel-bg text-pixel-text">
        <div className="container mx-auto px-4 py-8">
          <div className="bg-pixel-danger border-4 border-black p-6 max-w-2xl mx-auto">
            <h2 className="text-2xl font-bold mb-4">ERROR</h2>
            <p>{error || 'Location not found'}</p>
            <button
              onClick={() => navigate('/game')}
              className="mt-4 btn-pixel bg-pixel-primary hover:bg-red-600 text-white"
            >
              BACK TO GAME
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-pixel-bg text-pixel-text">
      <div className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex justify-between items-center mb-6">
          <button
            onClick={() => navigate('/game')}
            className="btn-pixel bg-pixel-muted hover:bg-gray-600 text-white"
          >
            ← BACK
          </button>
          <div className="text-sm text-pixel-muted">
            TOWN
          </div>
        </div>

        {/* Location Header */}
        <div className="panel-pixel overflow-hidden mb-6">
          {/* Banner Image */}
          {location.imagePath && (
            <div
              className="h-48 bg-cover bg-center"
              style={{
                backgroundImage: `url(${location.imagePath})`,
                filter: 'contrast(1.2) saturate(0.9)',
              }}
            />
          )}

          {/* Location Info */}
          <div className="p-6">
            <div className="flex justify-between items-start mb-4">
              <div>
                <h1 className="text-4xl font-bold mb-2 text-pixel-primary" style={{ textShadow: '3px 3px 0px rgba(0,0,0,0.5)' }}>
                  {location.name.toUpperCase()}
                </h1>
                {location.shortBlurb && (
                  <p className="text-pixel-muted">{location.shortBlurb}</p>
                )}
              </div>
              <button
                onClick={() => navigate('/travel')}
                className="btn-pixel bg-pixel-primary hover:bg-red-600 text-white whitespace-nowrap"
              >
                🗺️ CHANGE LOCATION
              </button>
            </div>

            {location.loreText && (
              <div className="mt-4 p-4 bg-pixel-bg border-4 border-black">
                <p className="text-pixel-text text-sm italic">{location.loreText}</p>
              </div>
            )}
          </div>
        </div>

        {/* Town Services */}
        <div className="space-y-6">
          {townServices.map((section) => (
            <div key={section.section} className="panel-pixel p-6">
              <h2 className="text-2xl font-bold mb-4 text-pixel-primary">{section.section.toUpperCase()}</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {section.buildings.map((building) => (
                  <button
                    key={building.name}
                    onClick={() => handleServiceClick(building.path)}
                    className="bg-pixel-bg hover:bg-pixel-accent border-4 border-black p-4 transition text-left group"
                  >
                    <div className="flex items-start gap-3">
                      <div className="text-3xl group-hover:scale-110 transition-transform">
                        {building.icon}
                      </div>
                      <div className="flex-1">
                        <h3 className="font-bold text-lg mb-1 group-hover:text-pixel-primary transition">
                          {building.name}
                        </h3>
                        <p className="text-sm text-pixel-muted">{building.description}</p>
                      </div>
                    </div>
                  </button>
                ))}
              </div>
            </div>
          ))}

          {/* Admin Panel (only visible to admins) */}
          {user && ['ADMIN', 'GOVERNOR', 'MODERATOR'].includes(user.role) && (
            <div className="bg-pixel-danger border-4 border-black p-6">
              <h2 className="text-2xl font-bold mb-4 text-white">ADMINISTRATION</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <button
                  onClick={() => navigate('/admin')}
                  className="bg-red-900 hover:bg-red-800 border-4 border-black p-4 transition text-left group"
                >
                  <div className="flex items-start gap-3">
                    <div className="text-3xl group-hover:scale-110 transition-transform">
                      🛡️
                    </div>
                    <div className="flex-1">
                      <h3 className="font-bold text-lg mb-1 text-white">
                        ADMIN PANEL
                      </h3>
                      <p className="text-sm text-red-200">Manage users and view statistics</p>
                    </div>
                  </div>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="mt-8 text-center text-pixel-muted text-sm">
          <p>🚧 Most services are under construction and will be available soon 🚧</p>
        </div>
      </div>
    </div>
  );
}
