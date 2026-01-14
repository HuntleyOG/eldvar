import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { locationApi, Location } from '../lib/api';

export function TravelPage() {
  const navigate = useNavigate();
  const { user } = useAuthStore();
  const [currentLocation, setCurrentLocation] = useState<Location | null>(null);
  const [locations, setLocations] = useState<Location[]>([]);
  const [loading, setLoading] = useState(true);
  const [traveling, setTraveling] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!user) {
      navigate('/login');
      return;
    }

    const loadData = async () => {
      try {
        setLoading(true);
        setError(null);
        const [current, all] = await Promise.all([
          locationApi.getCurrentLocation(),
          locationApi.getAllLocations(),
        ]);
        setCurrentLocation(current);
        setLocations(all);
      } catch (err: any) {
        console.error('Error loading locations:', err);
        setError(err.response?.data?.message || 'Failed to load locations');
      } finally {
        setLoading(false);
      }
    };

    loadData();
  }, [user, navigate]);

  const handleTravel = async (destination: string) => {
    if (traveling) return;

    try {
      setTraveling(true);
      setError(null);
      const response = await locationApi.travelTo(destination);
      setCurrentLocation(response.location);

      // Show success message
      alert(response.message);

      // Navigate back to town
      navigate('/town');
    } catch (err: any) {
      console.error('Error traveling:', err);
      setError(err.response?.data?.message || 'Failed to travel');
    } finally {
      setTraveling(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white flex items-center justify-center">
        <div className="text-2xl">Loading locations...</div>
      </div>
    );
  }

  if (error && !locations.length) {
    return (
      <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white">
        <div className="container mx-auto px-4 py-8">
          <div className="bg-red-900/50 border border-red-600 rounded-lg p-6 max-w-2xl mx-auto">
            <h2 className="text-2xl font-bold mb-4">Error</h2>
            <p>{error}</p>
            <button
              onClick={() => navigate('/town')}
              className="mt-4 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition"
            >
              Back to Town
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
        <div className="flex justify-between items-center mb-8">
          <button
            onClick={() => navigate('/town')}
            className="bg-gray-700 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-lg transition"
          >
            ← Back to Town
          </button>
          <h1 className="text-3xl font-bold bg-gradient-to-r from-blue-400 to-purple-600 bg-clip-text text-transparent">
            Travel
          </h1>
          <div className="w-32"></div> {/* Spacer for centering */}
        </div>

        {/* Current Location */}
        {currentLocation && (
          <div className="bg-blue-900/30 border border-blue-600 rounded-lg p-6 mb-8">
            <div className="flex items-center gap-3">
              <span className="text-3xl">📍</span>
              <div>
                <p className="text-sm text-blue-300">Current Location</p>
                <p className="text-2xl font-bold">{currentLocation.name}</p>
              </div>
            </div>
          </div>
        )}

        {/* Error Message */}
        {error && (
          <div className="bg-red-900/50 border border-red-600 rounded-lg p-4 mb-6">
            <p className="text-red-200">{error}</p>
          </div>
        )}

        {/* Locations Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {locations.map((location) => {
            const isCurrentLocation = currentLocation?.slug === location.slug;

            return (
              <div
                key={location.id}
                className={`bg-gray-800 rounded-lg overflow-hidden border ${
                  isCurrentLocation
                    ? 'border-blue-500 ring-2 ring-blue-500/50'
                    : 'border-gray-700'
                }`}
              >
                {/* Location Image */}
                {location.imagePath ? (
                  <div
                    className="h-40 bg-cover bg-center"
                    style={{
                      backgroundImage: `linear-gradient(to bottom, rgba(0,0,0,0.3), rgba(0,0,0,0.7)), url(${location.imagePath})`,
                    }}
                  />
                ) : (
                  <div className="h-40 bg-gradient-to-br from-gray-700 to-gray-900 flex items-center justify-center">
                    <span className="text-6xl">🏰</span>
                  </div>
                )}

                {/* Location Info */}
                <div className="p-6">
                  <div className="flex justify-between items-start mb-3">
                    <h2 className="text-2xl font-bold">{location.name}</h2>
                    {isCurrentLocation && (
                      <span className="bg-blue-600 text-xs font-bold px-2 py-1 rounded">
                        CURRENT
                      </span>
                    )}
                  </div>

                  {location.shortBlurb && (
                    <p className="text-gray-400 mb-4">{location.shortBlurb}</p>
                  )}

                  {location.loreText && (
                    <p className="text-gray-500 text-sm italic mb-4 line-clamp-2">
                      {location.loreText}
                    </p>
                  )}

                  <button
                    onClick={() => handleTravel(location.slug)}
                    disabled={isCurrentLocation || traveling}
                    className={`w-full font-bold py-3 px-4 rounded-lg transition ${
                      isCurrentLocation
                        ? 'bg-gray-600 cursor-not-allowed text-gray-400'
                        : traveling
                        ? 'bg-gray-600 cursor-wait text-gray-400'
                        : 'bg-blue-600 hover:bg-blue-700 text-white'
                    }`}
                  >
                    {isCurrentLocation
                      ? 'You are here'
                      : traveling
                      ? 'Traveling...'
                      : `Travel to ${location.name}`}
                  </button>
                </div>
              </div>
            );
          })}
        </div>

        {/* Footer Info */}
        <div className="mt-8 text-center text-gray-500 text-sm">
          <p>ℹ️ Travel is currently instant. Time-based travel with map visualization coming soon!</p>
        </div>
      </div>
    </div>
  );
}
