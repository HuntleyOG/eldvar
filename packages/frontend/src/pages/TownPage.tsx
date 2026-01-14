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
      <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white flex items-center justify-center">
        <div className="text-2xl">Loading...</div>
      </div>
    );
  }

  if (error || !location) {
    return (
      <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white">
        <div className="container mx-auto px-4 py-8">
          <div className="bg-red-900/50 border border-red-600 rounded-lg p-6 max-w-2xl mx-auto">
            <h2 className="text-2xl font-bold mb-4">Error</h2>
            <p>{error || 'Location not found'}</p>
            <button
              onClick={() => navigate('/game')}
              className="mt-4 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition"
            >
              Back to Game
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white">
      <div className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex justify-between items-center mb-6">
          <button
            onClick={() => navigate('/game')}
            className="bg-gray-700 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-lg transition"
          >
            ← Back
          </button>
          <div className="text-sm text-gray-400">
            Town
          </div>
        </div>

        {/* Location Header */}
        <div className="bg-gray-800 rounded-lg overflow-hidden mb-6 border border-gray-700">
          {/* Banner Image */}
          {location.imagePath && (
            <div
              className="h-48 bg-cover bg-center"
              style={{
                backgroundImage: `linear-gradient(to bottom, rgba(0,0,0,0.3), rgba(0,0,0,0.7)), url(${location.imagePath})`,
              }}
            />
          )}

          {/* Location Info */}
          <div className="p-6">
            <div className="flex justify-between items-start mb-4">
              <div>
                <h1 className="text-4xl font-bold mb-2 bg-gradient-to-r from-blue-400 to-purple-600 bg-clip-text text-transparent">
                  {location.name}
                </h1>
                {location.shortBlurb && (
                  <p className="text-gray-400">{location.shortBlurb}</p>
                )}
              </div>
              <button
                onClick={() => navigate('/travel')}
                className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition whitespace-nowrap"
              >
                🗺️ Change Location
              </button>
            </div>

            {location.loreText && (
              <div className="mt-4 p-4 bg-gray-900/50 rounded-lg border border-gray-700">
                <p className="text-gray-300 text-sm italic">{location.loreText}</p>
              </div>
            )}
          </div>
        </div>

        {/* Town Services */}
        <div className="space-y-6">
          {townServices.map((section) => (
            <div key={section.section} className="bg-gray-800 rounded-lg p-6 border border-gray-700">
              <h2 className="text-2xl font-bold mb-4 text-blue-400">{section.section}</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {section.buildings.map((building) => (
                  <button
                    key={building.name}
                    onClick={() => handleServiceClick(building.path)}
                    className="bg-gray-900/50 hover:bg-gray-900 rounded-lg p-4 border border-gray-700 hover:border-blue-500 transition text-left group"
                  >
                    <div className="flex items-start gap-3">
                      <div className="text-3xl group-hover:scale-110 transition-transform">
                        {building.icon}
                      </div>
                      <div className="flex-1">
                        <h3 className="font-bold text-lg mb-1 group-hover:text-blue-400 transition">
                          {building.name}
                        </h3>
                        <p className="text-sm text-gray-400">{building.description}</p>
                      </div>
                    </div>
                  </button>
                ))}
              </div>
            </div>
          ))}

          {/* Admin Panel (only visible to admins) */}
          {user && ['ADMIN', 'GOVERNOR', 'MODERATOR'].includes(user.role) && (
            <div className="bg-red-900/20 rounded-lg p-6 border border-red-600">
              <h2 className="text-2xl font-bold mb-4 text-red-400">Administration</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <button
                  onClick={() => navigate('/admin')}
                  className="bg-red-900/50 hover:bg-red-900 rounded-lg p-4 border border-red-700 hover:border-red-500 transition text-left group"
                >
                  <div className="flex items-start gap-3">
                    <div className="text-3xl group-hover:scale-110 transition-transform">
                      🛡️
                    </div>
                    <div className="flex-1">
                      <h3 className="font-bold text-lg mb-1 group-hover:text-red-400 transition">
                        Admin Panel
                      </h3>
                      <p className="text-sm text-red-300">Manage users and view statistics</p>
                    </div>
                  </div>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="mt-8 text-center text-gray-500 text-sm">
          <p>🚧 Most services are under construction and will be available soon 🚧</p>
        </div>
      </div>
    </div>
  );
}
