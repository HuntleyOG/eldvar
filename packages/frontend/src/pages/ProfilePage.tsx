import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { userApi, User, UserSkill } from '../lib/api';
import { formatPID, getRoleBadge } from '../lib/userUtils';

export function ProfilePage() {
  const navigate = useNavigate();
  const { username } = useParams<{ username?: string }>();
  const { user: currentUser } = useAuthStore();
  const [profile, setProfile] = useState<User | null>(null);
  const [skills, setSkills] = useState<UserSkill[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const isOwnProfile = !username || currentUser?.username === username;

  useEffect(() => {
    const loadProfile = async () => {
      try {
        setLoading(true);
        setError(null);

        let profileData: User;
        let skillsData: UserSkill[];

        if (isOwnProfile) {
          // Load current user's profile
          profileData = await userApi.getProfile();
          skillsData = await userApi.getSkills();
        } else if (username) {
          // Load another user's profile
          profileData = await userApi.getProfileByUsername(username);
          skillsData = await userApi.getSkillsById(profileData.id);
        } else {
          throw new Error('No username provided');
        }

        setProfile(profileData);
        setSkills(skillsData);
      } catch (err: any) {
        console.error('Error loading profile:', err);
        setError(err.response?.data?.message || 'Failed to load profile');
      } finally {
        setLoading(false);
      }
    };

    loadProfile();
  }, [username, isOwnProfile]);

  if (loading) {
    return (
      <div className="min-h-screen bg-pixel-bg text-pixel-text flex items-center justify-center">
        <div className="text-2xl font-bold">LOADING PROFILE...</div>
      </div>
    );
  }

  if (error || !profile) {
    return (
      <div className="min-h-screen bg-pixel-bg text-pixel-text">
        <div className="container mx-auto px-4 py-8">
          <div className="bg-pixel-danger border-4 border-black p-6 max-w-2xl mx-auto">
            <h2 className="text-2xl font-bold mb-4">ERROR</h2>
            <p>{error || 'Profile not found'}</p>
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
        <div className="flex justify-between items-center mb-8">
          <button
            onClick={() => navigate('/game')}
            className="btn-pixel bg-pixel-muted hover:bg-gray-600 text-white"
          >
            ← BACK
          </button>
          {isOwnProfile && (
            <button
              onClick={() => navigate('/profile/edit')}
              className="btn-pixel bg-pixel-primary hover:bg-red-600 text-white"
            >
              EDIT PROFILE
            </button>
          )}
        </div>

        {/* Banner */}
        {profile.bannerUrl && (
          <div
            className="h-48 rounded-lg mb-4 bg-cover bg-center"
            style={{ backgroundImage: `url(${profile.bannerUrl})` }}
          />
        )}

        {/* Profile Header */}
        <div className="panel-pixel p-6 mb-6">
          <div className="flex items-start gap-6">
            {profile.avatarUrl ? (
              <img
                src={profile.avatarUrl}
                alt={profile.username}
                className="w-24 h-24 border-4 border-pixel-primary"
                style={{ imageRendering: 'pixelated' }}
              />
            ) : (
              <div className="w-24 h-24 border-4 border-pixel-primary bg-pixel-secondary flex items-center justify-center text-4xl font-bold">
                {profile.username.charAt(0).toUpperCase()}
              </div>
            )}
            <div className="flex-1">
              <div className="flex items-center gap-3 mb-2">
                <h1 className="text-3xl font-bold">
                  {profile.displayName || profile.username}
                </h1>
                {profile.verified && (
                  <span className="text-green-400 text-sm">✓ Verified</span>
                )}
              </div>
              <div className="flex items-center gap-2 mb-2">
                <p className="text-gray-400">
                  @{profile.username}
                  <span className="font-mono text-gray-500">{formatPID(profile.id)}</span>
                </p>
                {(() => {
                  const roleBadge = getRoleBadge(profile.role);
                  return (
                    <span
                      className={`${roleBadge.bgClass} ${roleBadge.textClass} px-2 py-1 rounded text-xs font-bold`}
                    >
                      {roleBadge.label}
                    </span>
                  );
                })()}
              </div>
              {profile.statusText && (
                <p className="text-gray-300 mb-2">{profile.statusText}</p>
              )}
              {profile.bio && (
                <p className="text-gray-300 mt-3">{profile.bio}</p>
              )}
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Stats */}
          <div className="panel-pixel p-6">
            <h2 className="text-2xl font-bold mb-4 text-pixel-primary">STATS</h2>
            <div className="space-y-3 text-pixel-text">
              <div className="flex justify-between">
                <span className="font-bold">LEVEL:</span>
                <span className="text-pixel-primary">{profile.level}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-bold">OVERALL XP:</span>
                <span>{profile.overallXp?.toLocaleString() || 0}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-bold">CURRENT FLOOR:</span>
                <span>{profile.currentFloor || 1}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-bold">DEEPEST FLOOR:</span>
                <span className="text-pixel-secondary">{profile.deepestFloor || 1}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-bold">GOLD:</span>
                <span className="text-pixel-warning">{profile.gold?.toLocaleString() || 0}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-bold">ROLE:</span>
                <span className="uppercase">{profile.role}</span>
              </div>
            </div>
          </div>

          {/* Skills */}
          <div className="lg:col-span-2 panel-pixel p-6">
            <h2 className="text-2xl font-bold mb-4 text-pixel-secondary">SKILLS</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {skills.map((skill) => (
                <div
                  key={skill.skillId}
                  className="bg-pixel-bg border-4 border-black p-4"
                >
                  <div className="flex justify-between items-center mb-2">
                    <h3 className="font-bold text-lg">{skill.skillName.toUpperCase()}</h3>
                    <span className="text-pixel-primary font-bold">LV {skill.level}</span>
                  </div>
                  <div className="w-full bg-pixel-bg border-4 border-black h-2 mb-2">
                    <div
                      className="bg-pixel-secondary h-2"
                      style={{ width: `${Math.min((skill.xp % 100) / 100 * 100, 100)}%` }}
                    />
                  </div>
                  <div className="text-sm text-pixel-muted">
                    {skill.xp.toLocaleString()} XP
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Account Info */}
        {isOwnProfile && (
          <div className="mt-6 panel-pixel p-6">
            <h2 className="text-2xl font-bold mb-4 text-pixel-success">ACCOUNT INFO</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-pixel-text">
              {profile.email && (
                <div>
                  <span className="font-semibold">Email:</span>{' '}
                  <span>{profile.email}</span>
                </div>
              )}
              <div>
                <span className="font-semibold">Member Since:</span>{' '}
                <span>{new Date(profile.createdAt || '').toLocaleDateString()}</span>
              </div>
              {profile.lastSeen && (
                <div>
                  <span className="font-semibold">Last Seen:</span>{' '}
                  <span>{new Date(profile.lastSeen).toLocaleString()}</span>
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
