import { useEffect, useState, useRef } from 'react';
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
  const resumeProcessedRef = useRef(false);

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

  // Separate effect to handle resume travel state (runs only once per navigation)
  useEffect(() => {
    const state = location.state as any;
    if (state?.resumeTravel && !resumeProcessedRef.current) {
      resumeProcessedRef.current = true;
      setTravelDestination(state.destination);
      setTravelProgress(state.progress);
      setTravelDistance(state.distance);
      // Clear the navigation state
      window.history.replaceState({}, document.title);
    }
  }, [location.state]);

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
      <div className="min-h-screen bg-pixel-bg text-pixel-text flex items-center justify-center">
        <div className="text-2xl font-bold">LOADING LOCATIONS...</div>
      </div>
    );
  }

  if (error && !locations.length) {
    return (
      <div className="min-h-screen bg-pixel-bg text-pixel-text">
        <div className="container mx-auto px-4 py-8">
          <div className="bg-pixel-danger border-4 border-black p-6 max-w-2xl mx-auto">
            <h2 className="text-2xl font-bold mb-4">ERROR</h2>
            <p>{error}</p>
            <button
              onClick={() => navigate('/town')}
              className="mt-4 btn-pixel bg-pixel-primary hover:bg-red-600 text-white"
            >
              BACK TO TOWN
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
        <div className="flex justify-between items-center mb-8">
          <button
            onClick={() => navigate('/town')}
            className="btn-pixel bg-pixel-muted hover:bg-gray-600 text-white"
          >
            ← BACK TO TOWN
          </button>
          <h1 className="text-3xl font-bold text-pixel-primary" style={{ textShadow: '3px 3px 0px rgba(0,0,0,0.5)' }}>
            TRAVEL
          </h1>
          <div className="w-32"></div> {/* Spacer for centering */}
        </div>

        {/* Current Location */}
        {currentLocation && (
          <div className="bg-pixel-primary border-4 border-black p-6 mb-8">
            <div className="flex items-center gap-3">
              <span className="text-3xl">📍</span>
              <div>
                <p className="text-sm text-white">CURRENT LOCATION</p>
                <p className="text-2xl font-bold text-white">{currentLocation.name.toUpperCase()}</p>
              </div>
            </div>
          </div>
        )}

        {/* Error Message */}
        {error && (
          <div className="bg-pixel-danger border-4 border-black p-4 mb-6">
            <p className="text-white">{error}</p>
          </div>
        )}

        {/* Traveling UI */}
        {travelDestination ? (
          <div className="max-w-3xl mx-auto">
            <div className="panel-pixel p-8">
              <h2 className="text-3xl font-bold mb-6 text-center">
                TRAVELING TO{' '}
                {(locations.find((l) => l.slug === travelDestination)?.name ||
                  'destination').toUpperCase()}
              </h2>

              {/* Progress Bar */}
              <div className="mb-6">
                <div className="flex justify-between mb-2">
                  <span className="text-sm font-bold">PROGRESS</span>
                  <span className="text-sm">
                    {travelProgress} / {travelDistance} STEPS
                  </span>
                </div>
                <div className="w-full bg-pixel-bg border-4 border-black h-6">
                  <div
                    className="bg-pixel-secondary h-6 transition-all duration-300 flex items-center justify-center text-xs font-bold"
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
                  className="w-full btn-pixel bg-pixel-primary hover:bg-red-600 disabled:bg-pixel-muted disabled:cursor-not-allowed text-white py-4 text-lg"
                >
                  {traveling ? '🚶 TAKING STEP...' : '🚶 TAKE STEP FORWARD'}
                </button>
                <button
                  onClick={handleCancelTravel}
                  disabled={traveling}
                  className="w-full btn-pixel bg-pixel-danger hover:bg-red-700 disabled:bg-pixel-muted disabled:cursor-not-allowed text-white"
                >
                  CANCEL TRAVEL
                </button>
              </div>

              <div className="mt-6 p-4 bg-pixel-warning border-4 border-black">
                <p className="text-white text-sm text-center font-bold">
                  ⚠️ EACH STEP HAS A CHANCE OF ENCOUNTERING ENEMIES, FINDING LOOT, OR GAINING
                  PATHFINDING XP!
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
                  className={`panel-pixel overflow-hidden ${
                    isCurrentLocation
                      ? 'border-pixel-primary'
                      : ''
                  }`}
                >
                  {/* Location Image */}
                  {location.imagePath ? (
                    <div
                      className="h-40 bg-cover bg-center"
                      style={{
                        backgroundImage: `url(${location.imagePath})`,
                        filter: 'contrast(1.2) saturate(0.9)',
                      }}
                    />
                  ) : (
                    <div className="h-40 bg-pixel-accent flex items-center justify-center">
                      <span className="text-6xl">🏰</span>
                    </div>
                  )}

                  {/* Location Info */}
                  <div className="p-6">
                    <div className="flex justify-between items-start mb-3">
                      <h2 className="text-2xl font-bold">{location.name.toUpperCase()}</h2>
                      {isCurrentLocation && (
                        <span className="bg-pixel-primary text-xs font-bold px-2 py-1 border-2 border-black">
                          CURRENT
                        </span>
                      )}
                    </div>

                    {location.shortBlurb && (
                      <p className="text-pixel-muted mb-4">{location.shortBlurb}</p>
                    )}

                    {location.loreText && (
                      <p className="text-pixel-muted text-sm italic mb-4 line-clamp-2">
                        {location.loreText}
                      </p>
                    )}

                    <button
                      onClick={() => handleStartTravel(location.slug)}
                      disabled={isCurrentLocation}
                      className={`w-full btn-pixel py-3 ${
                        isCurrentLocation
                          ? 'bg-pixel-muted cursor-not-allowed text-white'
                          : 'bg-pixel-primary hover:bg-red-600 text-white'
                      }`}
                    >
                      {isCurrentLocation
                        ? 'YOU ARE HERE'
                        : `TRAVEL TO ${location.name.toUpperCase()}`}
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {/* Footer Info */}
        <div className="mt-8 text-center text-pixel-muted text-sm">
          <p>ℹ️ STEP-BASED TRAVEL - EACH STEP HAS CHANCES FOR ENCOUNTERS, LOOT, AND XP!</p>
        </div>
      </div>
    </div>
  );
}
