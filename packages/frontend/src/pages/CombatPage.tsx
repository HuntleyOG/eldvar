import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { combatApi, Battle, CombatStyle, BattleStatus } from '../lib/api';

export function CombatPage() {
  const navigate = useNavigate();
  const { battleId } = useParams<{ battleId: string }>();
  const { user } = useAuthStore();
  const [battle, setBattle] = useState<Battle | null>(null);
  const [loading, setLoading] = useState(true);
  const [acting, setActing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!user) {
      navigate('/login');
      return;
    }

    const loadBattle = async () => {
      try {
        setLoading(true);
        setError(null);

        let battleData: Battle;

        if (battleId) {
          battleData = await combatApi.getBattle(battleId);
        } else {
          const currentBattle = await combatApi.getCurrentBattle();
          if (!currentBattle) {
            setError('No active battle found');
            setLoading(false);
            return;
          }
          battleData = currentBattle;
        }

        setBattle(battleData);
      } catch (err: any) {
        console.error('Error loading battle:', err);
        setError(err.response?.data?.message || 'Failed to load battle');
      } finally {
        setLoading(false);
      }
    };

    loadBattle();
  }, [user, battleId, navigate]);

  const handleAttack = async (combatStyle: CombatStyle) => {
    if (!battle || acting || battle.status !== BattleStatus.ONGOING) return;

    try {
      setActing(true);
      setError(null);
      setMessage(null);

      const response = await combatApi.takeTurn(battle.id, combatStyle);
      setBattle(response.battle);

      if (response.message) {
        setMessage(response.message);
      }
    } catch (err: any) {
      console.error('Error taking turn:', err);
      setError(err.response?.data?.message || 'Failed to take action');
    } finally {
      setActing(false);
    }
  };

  const handleFlee = async () => {
    if (!battle || acting || battle.status !== BattleStatus.ONGOING) return;

    try {
      setActing(true);
      setError(null);

      const response = await combatApi.flee(battle.id);
      setBattle(response.battle);

      if (response.message) {
        setMessage(response.message);
      }

      // Return to town after fleeing
      setTimeout(() => {
        navigate('/town');
      }, 2000);
    } catch (err: any) {
      console.error('Error fleeing:', err);
      setError(err.response?.data?.message || 'Failed to flee');
      setActing(false);
    }
  };

  const handleContinue = () => {
    if (!battle) return;

    // If this was a travel battle and player won/fled, resume journey
    if (
      battle.travelDestination &&
      (battle.status === BattleStatus.WON || battle.status === BattleStatus.FLED)
    ) {
      // Navigate to travel page with state to resume journey
      navigate('/travel', {
        state: {
          resumeTravel: true,
          destination: battle.travelDestination,
          progress: battle.travelProgress || 0,
          distance: battle.travelDistance || 10,
        },
      });
    } else {
      // Normal combat or player lost - return to town
      navigate('/town');
    }
  };

  const getHpPercentage = (current: number, max: number) => {
    return Math.max(0, (current / max) * 100);
  };

  const getCombatStyleInfo = (style: CombatStyle) => {
    const styles = {
      [CombatStyle.ATTACK]: {
        name: 'Attack',
        icon: '⚔️',
        color: 'red',
        desc: 'Balanced damage and accuracy',
      },
      [CombatStyle.STRENGTH]: {
        name: 'Strength',
        icon: '💪',
        color: 'orange',
        desc: 'High damage, lower accuracy',
      },
      [CombatStyle.DEFENSE]: {
        name: 'Defense',
        icon: '🛡️',
        color: 'blue',
        desc: 'Low damage, high accuracy',
      },
      [CombatStyle.RANGE]: {
        name: 'Range',
        icon: '🏹',
        color: 'green',
        desc: 'Ranged attack',
      },
      [CombatStyle.MAGIC]: {
        name: 'Magic',
        icon: '✨',
        color: 'purple',
        desc: 'Magical attack',
      },
    };
    return styles[style];
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white flex items-center justify-center">
        <div className="text-2xl">Loading battle...</div>
      </div>
    );
  }

  if (error && !battle) {
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
              Return to Town
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (!battle) {
    return null;
  }

  const battleEnded = battle.status !== BattleStatus.ONGOING;
  const playerWon = battle.status === BattleStatus.WON;
  const playerLost = battle.status === BattleStatus.LOST;
  const playerFled = battle.status === BattleStatus.FLED;

  return (
    <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white">
      <div className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="text-center mb-6">
          <h1 className="text-4xl font-bold mb-2 bg-gradient-to-r from-red-400 to-orange-600 bg-clip-text text-transparent">
            ⚔️ Combat ⚔️
          </h1>
          <p className="text-gray-400">
            {battleEnded ? 'Battle Ended' : 'Fight for your life!'}
          </p>
        </div>

        {/* Messages */}
        {message && (
          <div className="bg-green-900/50 border border-green-600 rounded-lg p-4 mb-6 max-w-3xl mx-auto">
            <p className="text-green-200 font-bold text-center">{message}</p>
          </div>
        )}

        {error && (
          <div className="bg-red-900/50 border border-red-600 rounded-lg p-4 mb-6 max-w-3xl mx-auto">
            <p className="text-red-200">{error}</p>
          </div>
        )}

        {/* Battle Area */}
        <div className="max-w-5xl mx-auto">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            {/* Player */}
            <div className="bg-gray-800 rounded-lg p-6 border border-blue-600">
              <h2 className="text-2xl font-bold mb-4 text-blue-400">
                {battle.charName} (You)
              </h2>
              <div className="space-y-3">
                <div>
                  <div className="flex justify-between mb-1">
                    <span className="text-sm font-semibold">HP</span>
                    <span className="text-sm">
                      {battle.charHpCurrent} / {battle.charHpMax}
                    </span>
                  </div>
                  <div className="w-full bg-gray-700 rounded-full h-6">
                    <div
                      className="bg-gradient-to-r from-green-500 to-blue-500 h-6 rounded-full transition-all duration-300 flex items-center justify-center text-xs font-bold"
                      style={{
                        width: `${getHpPercentage(battle.charHpCurrent, battle.charHpMax)}%`,
                      }}
                    >
                      {Math.round(getHpPercentage(battle.charHpCurrent, battle.charHpMax))}%
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/* Mob */}
            <div className="bg-gray-800 rounded-lg p-6 border border-red-600">
              <h2 className="text-2xl font-bold mb-4 text-red-400">
                {battle.mobName}
                {battle.mob && <span className="text-sm ml-2">Lv {battle.mob.level}</span>}
              </h2>
              <div className="space-y-3">
                <div>
                  <div className="flex justify-between mb-1">
                    <span className="text-sm font-semibold">HP</span>
                    <span className="text-sm">
                      {battle.mobHpCurrent} / {battle.mobHpMax}
                    </span>
                  </div>
                  <div className="w-full bg-gray-700 rounded-full h-6">
                    <div
                      className="bg-gradient-to-r from-red-500 to-orange-500 h-6 rounded-full transition-all duration-300 flex items-center justify-center text-xs font-bold"
                      style={{
                        width: `${getHpPercentage(battle.mobHpCurrent, battle.mobHpMax)}%`,
                      }}
                    >
                      {Math.round(getHpPercentage(battle.mobHpCurrent, battle.mobHpMax))}%
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Actions */}
          {!battleEnded && (
            <div className="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-6">
              <h3 className="text-xl font-bold mb-4">Choose Your Action</h3>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
                {Object.values(CombatStyle).map((style) => {
                  const info = getCombatStyleInfo(style);
                  return (
                    <button
                      key={style}
                      onClick={() => handleAttack(style)}
                      disabled={acting}
                      className={`bg-${info.color}-600 hover:bg-${info.color}-700 disabled:bg-gray-600 disabled:cursor-not-allowed text-white font-bold py-3 px-4 rounded-lg transition`}
                      title={info.desc}
                    >
                      <div className="text-2xl mb-1">{info.icon}</div>
                      <div className="text-sm">{info.name}</div>
                    </button>
                  );
                })}
              </div>
              <button
                onClick={handleFlee}
                disabled={acting}
                className="w-full bg-gray-600 hover:bg-gray-700 disabled:bg-gray-800 disabled:cursor-not-allowed text-white font-bold py-2 px-4 rounded-lg transition"
              >
                🏃 Flee
              </button>
            </div>
          )}

          {/* Battle End Screen */}
          {battleEnded && (
            <div
              className={`rounded-lg p-6 mb-6 border-2 ${
                playerWon
                  ? 'bg-green-900/50 border-green-500'
                  : playerLost
                  ? 'bg-red-900/50 border-red-500'
                  : 'bg-yellow-900/50 border-yellow-500'
              }`}
            >
              <h2 className="text-3xl font-bold mb-4 text-center">
                {playerWon && '🎉 Victory!'}
                {playerLost && '💀 Defeated'}
                {playerFled && '🏃 Fled'}
              </h2>
              {playerWon && (
                <div className="text-center space-y-2">
                  <p className="text-xl">You defeated {battle.mobName}!</p>
                  <p className="text-yellow-400 font-bold text-2xl">
                    +{battle.rewardGold} Gold
                  </p>
                  <p className="text-blue-400 font-bold text-2xl">
                    +{battle.rewardXp} XP
                  </p>
                </div>
              )}
              {playerLost && (
                <p className="text-center text-xl">
                  You were defeated by {battle.mobName}...
                </p>
              )}
              {playerFled && (
                <p className="text-center text-xl">You fled from {battle.mobName}.</p>
              )}
              <button
                onClick={handleContinue}
                className="mt-6 w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition"
              >
                {battle.travelDestination &&
                (battle.status === BattleStatus.WON || battle.status === BattleStatus.FLED)
                  ? '🚶 Continue Journey'
                  : 'Return to Town'}
              </button>
            </div>
          )}

          {/* Combat Log */}
          <div className="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h3 className="text-xl font-bold mb-4">Combat Log</h3>
            <div className="space-y-2 max-h-64 overflow-y-auto">
              {battle.turns && battle.turns.length > 0 ? (
                battle.turns.map((turn) => (
                  <div
                    key={turn.id}
                    className={`p-3 rounded ${
                      turn.actor === 'PLAYER'
                        ? 'bg-blue-900/50 border-l-4 border-blue-500'
                        : 'bg-red-900/50 border-l-4 border-red-500'
                    }`}
                  >
                    <p className="text-sm">
                      <span className="font-bold">Turn {turn.turnNo}:</span> {turn.logText}
                    </p>
                  </div>
                ))
              ) : (
                <p className="text-gray-500 text-center">Battle just started...</p>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
