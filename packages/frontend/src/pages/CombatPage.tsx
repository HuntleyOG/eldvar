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

      // Don't auto-navigate - let the user click "Continue Journey" or "Return to Town"
      setActing(false);
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
      <div className="min-h-screen bg-pixel-bg text-pixel-text flex items-center justify-center">
        <div className="text-2xl font-bold">LOADING BATTLE...</div>
      </div>
    );
  }

  if (error && !battle) {
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
              RETURN TO TOWN
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
    <div className="min-h-screen bg-pixel-bg text-pixel-text">
      <div className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="text-center mb-6">
          <h1 className="text-4xl font-bold mb-2 text-pixel-primary" style={{ textShadow: '4px 4px 0px rgba(0,0,0,0.5)' }}>
            ⚔️ COMBAT ⚔️
          </h1>
          <p className="text-pixel-muted">
            {battleEnded ? 'BATTLE ENDED' : 'FIGHT FOR YOUR LIFE!'}
          </p>
        </div>

        {/* Messages */}
        {message && (
          <div className="bg-pixel-success border-4 border-black p-4 mb-6 max-w-3xl mx-auto">
            <p className="text-white font-bold text-center">{message}</p>
          </div>
        )}

        {error && (
          <div className="bg-pixel-danger border-4 border-black p-4 mb-6 max-w-3xl mx-auto">
            <p className="text-white">{error}</p>
          </div>
        )}

        {/* Battle Area */}
        <div className="max-w-5xl mx-auto">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            {/* Player */}
            <div className="panel-pixel p-6 border-pixel-primary">
              <h2 className="text-2xl font-bold mb-4 text-pixel-primary">
                {battle.charName.toUpperCase()} (YOU)
              </h2>
              <div className="space-y-3">
                <div>
                  <div className="flex justify-between mb-1">
                    <span className="text-sm font-bold">HP</span>
                    <span className="text-sm">
                      {battle.charHpCurrent} / {battle.charHpMax}
                    </span>
                  </div>
                  <div className="w-full bg-pixel-bg border-4 border-black h-6">
                    <div
                      className="bg-pixel-success h-6 transition-all duration-300 flex items-center justify-center text-xs font-bold"
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
            <div className="panel-pixel p-6 border-pixel-danger">
              <h2 className="text-2xl font-bold mb-4 text-pixel-danger">
                {battle.mobName.toUpperCase()}
                {battle.mob && <span className="text-sm ml-2">LV {battle.mob.level}</span>}
              </h2>
              <div className="space-y-3">
                <div>
                  <div className="flex justify-between mb-1">
                    <span className="text-sm font-bold">HP</span>
                    <span className="text-sm">
                      {battle.mobHpCurrent} / {battle.mobHpMax}
                    </span>
                  </div>
                  <div className="w-full bg-pixel-bg border-4 border-black h-6">
                    <div
                      className="bg-pixel-danger h-6 transition-all duration-300 flex items-center justify-center text-xs font-bold"
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
            <div className="panel-pixel p-6 mb-6">
              <h3 className="text-xl font-bold mb-4">CHOOSE YOUR ACTION</h3>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
                {Object.values(CombatStyle).map((style) => {
                  const info = getCombatStyleInfo(style);
                  const bgColors: Record<string, string> = {
                    red: 'bg-pixel-danger',
                    orange: 'bg-pixel-warning',
                    blue: 'bg-pixel-primary',
                    green: 'bg-pixel-success',
                    purple: 'bg-pixel-secondary',
                  };
                  const hoverColors: Record<string, string> = {
                    red: 'hover:bg-red-600',
                    orange: 'hover:bg-orange-600',
                    blue: 'hover:bg-red-600',
                    green: 'hover:bg-green-600',
                    purple: 'hover:bg-purple-600',
                  };
                  return (
                    <button
                      key={style}
                      onClick={() => handleAttack(style)}
                      disabled={acting}
                      className={`btn-pixel ${bgColors[info.color]} ${hoverColors[info.color]} disabled:bg-pixel-muted disabled:cursor-not-allowed text-white py-3`}
                      title={info.desc}
                    >
                      <div className="text-2xl mb-1">{info.icon}</div>
                      <div className="text-sm">{info.name.toUpperCase()}</div>
                    </button>
                  );
                })}
              </div>
              <button
                onClick={handleFlee}
                disabled={acting}
                className="w-full btn-pixel bg-pixel-muted hover:bg-gray-700 disabled:bg-gray-800 disabled:cursor-not-allowed text-white"
              >
                🏃 FLEE
              </button>
            </div>
          )}

          {/* Battle End Screen */}
          {battleEnded && (
            <div
              className={`border-4 border-black p-6 mb-6 ${
                playerWon
                  ? 'bg-pixel-success'
                  : playerLost
                  ? 'bg-pixel-danger'
                  : 'bg-pixel-warning'
              }`}
            >
              <h2 className="text-3xl font-bold mb-4 text-center text-white">
                {playerWon && '🎉 VICTORY!'}
                {playerLost && '💀 DEFEATED'}
                {playerFled && '🏃 FLED'}
              </h2>
              {playerWon && (
                <div className="text-center space-y-2 text-white">
                  <p className="text-xl">YOU DEFEATED {battle.mobName.toUpperCase()}!</p>
                  <p className="font-bold text-2xl">
                    +{battle.rewardGold} GOLD
                  </p>
                  <p className="font-bold text-2xl">
                    +{battle.rewardXp} XP
                  </p>
                </div>
              )}
              {playerLost && (
                <p className="text-center text-xl text-white">
                  YOU WERE DEFEATED BY {battle.mobName.toUpperCase()}...
                </p>
              )}
              {playerFled && (
                <p className="text-center text-xl text-white">YOU FLED FROM {battle.mobName.toUpperCase()}.</p>
              )}
              <button
                onClick={handleContinue}
                className="mt-6 w-full btn-pixel bg-pixel-primary hover:bg-red-600 text-white py-3"
              >
                {battle.travelDestination &&
                (battle.status === BattleStatus.WON || battle.status === BattleStatus.FLED)
                  ? '🚶 CONTINUE JOURNEY'
                  : 'RETURN TO TOWN'}
              </button>
            </div>
          )}

          {/* Combat Log */}
          <div className="panel-pixel p-6">
            <h3 className="text-xl font-bold mb-4">COMBAT LOG</h3>
            <div className="space-y-2 max-h-64 overflow-y-auto">
              {battle.turns && battle.turns.length > 0 ? (
                battle.turns.map((turn) => (
                  <div
                    key={turn.id}
                    className={`p-3 border-4 border-black ${
                      turn.actor === 'PLAYER'
                        ? 'bg-pixel-primary'
                        : 'bg-pixel-danger'
                    }`}
                  >
                    <p className="text-sm text-white">
                      <span className="font-bold">TURN {turn.turnNo}:</span> {turn.logText}
                    </p>
                  </div>
                ))
              ) : (
                <p className="text-pixel-muted text-center">BATTLE JUST STARTED...</p>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
