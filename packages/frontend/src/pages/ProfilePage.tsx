import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { userApi, User, UserSkill } from '../lib/api';

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
      <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white flex items-center justify-center">
        <div className="text-2xl">Loading profile...</div>
      </div>
    );
  }

  if (error || !profile) {
    return (
      <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white">
        <div className="container mx-auto px-4 py-8">
          <div className="bg-red-900/50 border border-red-600 rounded-lg p-6 max-w-2xl mx-auto">
            <h2 className="text-2xl font-bold mb-4">Error</h2>
            <p>{error || 'Profile not found'}</p>
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
        <div className="flex justify-between items-center mb-8">
          <button
            onClick={() => navigate('/game')}
            className="bg-gray-700 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-lg transition"
          >
            ← Back
          </button>
          {isOwnProfile && (
            <button
              onClick={() => navigate('/profile/edit')}
              className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition"
            >
              Edit Profile
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
        <div className="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-6">
          <div className="flex items-start gap-6">
            {profile.avatarUrl ? (
              <img
                src={profile.avatarUrl}
                alt={profile.username}
                className="w-24 h-24 rounded-full border-4 border-blue-500"
              />
            ) : (
              <div className="w-24 h-24 rounded-full border-4 border-blue-500 bg-gradient-to-br from-blue-600 to-purple-600 flex items-center justify-center text-4xl font-bold">
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
              <p className="text-gray-400 mb-2">@{profile.username}</p>
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
          <div className="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 className="text-2xl font-bold mb-4 text-blue-400">Stats</h2>
            <div className="space-y-3 text-gray-300">
              <div className="flex justify-between">
                <span className="font-semibold">Level:</span>
                <span className="text-blue-400">{profile.level}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-semibold">Overall XP:</span>
                <span>{profile.overallXp?.toLocaleString() || 0}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-semibold">Current Floor:</span>
                <span>{profile.currentFloor || 1}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-semibold">Deepest Floor:</span>
                <span className="text-purple-400">{profile.deepestFloor || 1}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-semibold">Gold:</span>
                <span className="text-yellow-400">{profile.gold?.toLocaleString() || 0}</span>
              </div>
              <div className="flex justify-between">
                <span className="font-semibold">Role:</span>
                <span className="capitalize">{profile.role}</span>
              </div>
            </div>
          </div>

          {/* Skills */}
          <div className="lg:col-span-2 bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 className="text-2xl font-bold mb-4 text-purple-400">Skills</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {skills.map((skill) => (
                <div
                  key={skill.skillId}
                  className="bg-gray-900/50 rounded-lg p-4 border border-gray-700"
                >
                  <div className="flex justify-between items-center mb-2">
                    <h3 className="font-bold text-lg">{skill.skillName}</h3>
                    <span className="text-blue-400 font-bold">Lv {skill.level}</span>
                  </div>
                  <div className="w-full bg-gray-700 rounded-full h-2 mb-2">
                    <div
                      className="bg-gradient-to-r from-blue-500 to-purple-600 h-2 rounded-full"
                      style={{ width: `${Math.min((skill.xp % 100) / 100 * 100, 100)}%` }}
                    />
                  </div>
                  <div className="text-sm text-gray-400">
                    {skill.xp.toLocaleString()} XP
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Account Info */}
        {isOwnProfile && (
          <div className="mt-6 bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 className="text-2xl font-bold mb-4 text-green-400">Account Info</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-300">
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
