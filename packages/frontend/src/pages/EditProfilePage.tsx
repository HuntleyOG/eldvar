import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { userApi, UpdateProfileRequest } from '../lib/api';

export function EditProfilePage() {
  const navigate = useNavigate();
  const { user, setAuth, token } = useAuthStore();
  const [formData, setFormData] = useState<UpdateProfileRequest>({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  useEffect(() => {
    if (!user) {
      navigate('/login');
      return;
    }

    // Initialize form with current user data
    setFormData({
      username: user.username,
      displayName: user.displayName || '',
      email: user.email || '',
      bio: user.bio || '',
      avatarUrl: user.avatarUrl || '',
      bannerUrl: user.bannerUrl || '',
      statusText: user.statusText || '',
    });
  }, [user, navigate]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    setSuccess(false);

    try {
      // Only send fields that have changed
      const updates: UpdateProfileRequest = {};

      if (formData.username && formData.username !== user?.username) {
        updates.username = formData.username;
      }
      if (formData.displayName !== user?.displayName) {
        updates.displayName = formData.displayName;
      }
      if (formData.email !== user?.email) {
        updates.email = formData.email;
      }
      if (formData.bio !== user?.bio) {
        updates.bio = formData.bio;
      }
      if (formData.avatarUrl !== user?.avatarUrl) {
        updates.avatarUrl = formData.avatarUrl;
      }
      if (formData.bannerUrl !== user?.bannerUrl) {
        updates.bannerUrl = formData.bannerUrl;
      }
      if (formData.statusText !== user?.statusText) {
        updates.statusText = formData.statusText;
      }

      if (Object.keys(updates).length === 0) {
        setError('No changes to save');
        setLoading(false);
        return;
      }

      const updatedUser = await userApi.updateProfile(updates);

      // Update auth store with new user data (keep existing token)
      if (token) {
        setAuth(updatedUser, token);
      }

      setSuccess(true);
      setTimeout(() => {
        navigate('/profile');
      }, 1500);
    } catch (err: any) {
      console.error('Error updating profile:', err);
      setError(err.response?.data?.message || 'Failed to update profile');
    } finally {
      setLoading(false);
    }
  };

  const handleChange = (field: keyof UpdateProfileRequest, value: string) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setError(null);
    setSuccess(false);
  };

  if (!user) {
    return null;
  }

  return (
    <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white">
      <div className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex justify-between items-center mb-8">
          <button
            onClick={() => navigate('/profile')}
            className="bg-gray-700 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-lg transition"
          >
            ← Cancel
          </button>
          <h1 className="text-3xl font-bold bg-gradient-to-r from-blue-400 to-purple-600 bg-clip-text text-transparent">
            Edit Profile
          </h1>
          <div className="w-24"></div> {/* Spacer for centering */}
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="max-w-2xl mx-auto">
          <div className="bg-gray-800 rounded-lg p-6 border border-gray-700 space-y-6">
            {/* Error/Success Messages */}
            {error && (
              <div className="bg-red-900/50 border border-red-600 rounded-lg p-4">
                <p className="text-red-200">{error}</p>
              </div>
            )}
            {success && (
              <div className="bg-green-900/50 border border-green-600 rounded-lg p-4">
                <p className="text-green-200">Profile updated successfully! Redirecting...</p>
              </div>
            )}

            {/* Username */}
            <div>
              <label className="block text-sm font-semibold mb-2">
                Username <span className="text-red-400">*</span>
              </label>
              <input
                type="text"
                value={formData.username || ''}
                onChange={(e) => handleChange('username', e.target.value)}
                className="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none"
                minLength={3}
                maxLength={50}
                required
              />
              <p className="text-gray-500 text-sm mt-1">3-50 characters</p>
            </div>

            {/* Display Name */}
            <div>
              <label className="block text-sm font-semibold mb-2">Display Name</label>
              <input
                type="text"
                value={formData.displayName || ''}
                onChange={(e) => handleChange('displayName', e.target.value)}
                className="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none"
                maxLength={120}
              />
              <p className="text-gray-500 text-sm mt-1">Optional, max 120 characters</p>
            </div>

            {/* Email */}
            <div>
              <label className="block text-sm font-semibold mb-2">Email</label>
              <input
                type="email"
                value={formData.email || ''}
                onChange={(e) => handleChange('email', e.target.value)}
                className="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none"
              />
              <p className="text-gray-500 text-sm mt-1">Optional</p>
            </div>

            {/* Bio */}
            <div>
              <label className="block text-sm font-semibold mb-2">Bio</label>
              <textarea
                value={formData.bio || ''}
                onChange={(e) => handleChange('bio', e.target.value)}
                className="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none resize-none"
                rows={4}
                maxLength={500}
              />
              <p className="text-gray-500 text-sm mt-1">
                {formData.bio?.length || 0}/500 characters
              </p>
            </div>

            {/* Status Text */}
            <div>
              <label className="block text-sm font-semibold mb-2">Status</label>
              <input
                type="text"
                value={formData.statusText || ''}
                onChange={(e) => handleChange('statusText', e.target.value)}
                className="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none"
                maxLength={190}
              />
              <p className="text-gray-500 text-sm mt-1">Short status message, max 190 characters</p>
            </div>

            {/* Avatar URL */}
            <div>
              <label className="block text-sm font-semibold mb-2">Avatar URL</label>
              <input
                type="url"
                value={formData.avatarUrl || ''}
                onChange={(e) => handleChange('avatarUrl', e.target.value)}
                className="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none"
                placeholder="https://example.com/avatar.jpg"
                maxLength={255}
              />
              <p className="text-gray-500 text-sm mt-1">URL to your profile picture</p>
            </div>

            {/* Banner URL */}
            <div>
              <label className="block text-sm font-semibold mb-2">Banner URL</label>
              <input
                type="url"
                value={formData.bannerUrl || ''}
                onChange={(e) => handleChange('bannerUrl', e.target.value)}
                className="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:outline-none"
                placeholder="https://example.com/banner.jpg"
                maxLength={255}
              />
              <p className="text-gray-500 text-sm mt-1">URL to your profile banner</p>
            </div>

            {/* Preview */}
            {(formData.avatarUrl || formData.bannerUrl) && (
              <div className="border-t border-gray-700 pt-6">
                <h3 className="text-lg font-semibold mb-3">Preview</h3>
                <div className="space-y-3">
                  {formData.bannerUrl && (
                    <div>
                      <p className="text-sm text-gray-400 mb-2">Banner:</p>
                      <img
                        src={formData.bannerUrl}
                        alt="Banner preview"
                        className="w-full h-32 object-cover rounded-lg"
                        onError={(e) => {
                          e.currentTarget.style.display = 'none';
                        }}
                      />
                    </div>
                  )}
                  {formData.avatarUrl && (
                    <div>
                      <p className="text-sm text-gray-400 mb-2">Avatar:</p>
                      <img
                        src={formData.avatarUrl}
                        alt="Avatar preview"
                        className="w-24 h-24 object-cover rounded-full border-4 border-blue-500"
                        onError={(e) => {
                          e.currentTarget.style.display = 'none';
                        }}
                      />
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Submit Button */}
            <div className="pt-6 border-t border-gray-700">
              <button
                type="submit"
                disabled={loading}
                className="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-gray-600 disabled:cursor-not-allowed text-white font-bold py-3 px-6 rounded-lg transition"
              >
                {loading ? 'Saving...' : 'Save Changes'}
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
}
