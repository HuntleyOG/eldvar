import { useEffect, useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { locationApi, Location } from '../lib/api';

export function TravelPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const { user } = useAuthStore();
  const [currentLocation, setCurrentLocation] = useState<Location | null>(null);
  const [locations, setLocations] = useState<Location[]>([]);
  const [loading, setLoading] = useState(true);
  const [traveling, setTraveling] = useState(false);
  const [travelDestination, setTravelDestination] = useState<string | null>(null);
  const [travelProgress, setTravelProgress] = useState(0);
  const [travelDistance, setTravelDistance] = useState(10); // Default 10 steps
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

        // Check if we're resuming travel after a battle
        const state = location.state as any;
        if (state?.resumeTravel) {
          setTravelDestination(state.destination);
          setTravelProgress(state.progress);
          setTravelDistance(state.distance);
          // Clear the navigation state
          navigate(location.pathname, { replace: true, state: {} });
        }
      } catch (err: any) {
        console.error('Error loading locations:', err);
        setError(err.response?.data?.message || 'Failed to load locations');
      } finally {
        setLoading(false);
      }
    };

    loadData();
  }, [user, navigate, location]);

  const handleStartTravel = (destination: string) => {
    setTravelDestination(destination);
    setTravelProgress(0);
    setTravelDistance(Math.floor(Math.random() * 6) + 5); // Random 5-10 steps
    setError(null);
  };

  const handleCancelTravel = () => {
    setTravelDestination(null);
    setTravelProgress(0);
    setError(null);
  };

  const handleTakeStep = async () => {
    if (!travelDestination || traveling) return;

    try {
      setTraveling(true);
      setError(null);

      // Calculate if this is the final step
      const newProgress = travelProgress + 1;
      const isComplete = newProgress >= travelDistance;

      const response = await locationApi.travelTo(
        travelDestination,
        isComplete,
        newProgress,
        travelDistance,
      );

      // Check for encounter
      if (response.encounter && response.battle) {
        // Redirect to combat
        navigate(`/combat/${response.battle.id}`);
        return;
      }

      // No encounter - increment progress
      setTravelProgress(newProgress);

      // Check if arrived
      if (isComplete) {
        setCurrentLocation(response.location);
        alert(`${response.message}\n\nYou have arrived!`);
        setTravelDestination(null);
        setTravelProgress(0);
      }
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

        {/* Traveling UI */}
        {travelDestination ? (
          <div className="max-w-3xl mx-auto">
            <div className="bg-gray-800 rounded-lg p-8 border border-blue-600">
              <h2 className="text-3xl font-bold mb-6 text-center">
                Traveling to{' '}
                {locations.find((l) => l.slug === travelDestination)?.name ||
                  'destination'}
              </h2>

              {/* Progress Bar */}
              <div className="mb-6">
                <div className="flex justify-between mb-2">
                  <span className="text-sm font-semibold">Progress</span>
                  <span className="text-sm">
                    {travelProgress} / {travelDistance} steps
                  </span>
                </div>
                <div className="w-full bg-gray-700 rounded-full h-6">
                  <div
                    className="bg-gradient-to-r from-blue-500 to-purple-600 h-6 rounded-full transition-all duration-300 flex items-center justify-center text-xs font-bold"
                    style={{
                      width: `${Math.max(5, (travelProgress / travelDistance) * 100)}%`,
                    }}
                  >
                    {Math.round((travelProgress / travelDistance) * 100)}%
                  </div>
                </div>
              </div>

              {/* Actions */}
              <div className="space-y-3">
                <button
                  onClick={handleTakeStep}
                  disabled={traveling}
                  className="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-gray-600 disabled:cursor-not-allowed text-white font-bold py-4 px-6 rounded-lg transition text-lg"
                >
                  {traveling ? '🚶 Taking Step...' : '🚶 Take Step Forward'}
                </button>
                <button
                  onClick={handleCancelTravel}
                  disabled={traveling}
                  className="w-full bg-red-600 hover:bg-red-700 disabled:bg-gray-600 disabled:cursor-not-allowed text-white font-bold py-2 px-6 rounded-lg transition"
                >
                  Cancel Travel
                </button>
              </div>

              <div className="mt-6 p-4 bg-yellow-900/30 border border-yellow-600 rounded-lg">
                <p className="text-yellow-200 text-sm text-center">
                  ⚠️ Each step has a chance of encountering enemies, finding loot, or gaining
                  pathfinding XP!
                </p>
              </div>
            </div>
          </div>
        ) : (
          /* Locations Grid */
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
                      onClick={() => handleStartTravel(location.slug)}
                      disabled={isCurrentLocation}
                      className={`w-full font-bold py-3 px-4 rounded-lg transition ${
                        isCurrentLocation
                          ? 'bg-gray-600 cursor-not-allowed text-gray-400'
                          : 'bg-blue-600 hover:bg-blue-700 text-white'
                      }`}
                    >
                      {isCurrentLocation
                        ? 'You are here'
                        : `Travel to ${location.name}`}
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {/* Footer Info */}
        <div className="mt-8 text-center text-gray-500 text-sm">
          <p>ℹ️ Step-based travel - each step has chances for encounters, loot, and XP!</p>
        </div>
      </div>
    </div>
  );
}
