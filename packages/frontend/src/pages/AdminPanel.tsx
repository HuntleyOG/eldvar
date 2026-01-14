import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { adminApi, AdminUser, DashboardStats } from '../lib/api';
import { formatPID, getRoleBadge } from '../lib/userUtils';

export function AdminPanel() {
  const navigate = useNavigate();
  const { user } = useAuthStore();
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [error, setError] = useState<string | null>(null);

  // Check if user has admin access
  useEffect(() => {
    if (!user) {
      navigate('/login');
      return;
    }

    const allowedRoles = ['ADMIN', 'GOVERNOR', 'MODERATOR'];
    if (!allowedRoles.includes(user.role)) {
      navigate('/town');
      return;
    }
  }, [user, navigate]);

  // Load dashboard stats
  useEffect(() => {
    const loadStats = async () => {
      try {
        const data = await adminApi.getStats();
        setStats(data);
      } catch (err: any) {
        console.error('Error loading stats:', err);
        setError(err.response?.data?.message || 'Failed to load stats');
      }
    };

    loadStats();
  }, []);

  // Load users
  useEffect(() => {
    const loadUsers = async () => {
      try {
        setLoading(true);
        setError(null);
        const data = await adminApi.getAllUsers(page, 50);
        setUsers(data.users);
        setTotal(data.total);
        setTotalPages(data.totalPages);
      } catch (err: any) {
        console.error('Error loading users:', err);
        setError(err.response?.data?.message || 'Failed to load users');
      } finally {
        setLoading(false);
      }
    };

    if (!searchQuery) {
      loadUsers();
    }
  }, [page, searchQuery]);

  const handleSearch = async () => {
    if (!searchQuery.trim()) {
      return;
    }

    try {
      setLoading(true);
      setError(null);
      const results = await adminApi.searchUsers(searchQuery);
      setUsers(results);
      setTotal(results.length);
      setTotalPages(1);
    } catch (err: any) {
      console.error('Error searching users:', err);
      setError(err.response?.data?.message || 'Failed to search users');
    } finally {
      setLoading(false);
    }
  };

  const handleUpdateRole = async (userId: number, role: string) => {
    try {
      await adminApi.updateUserRole(userId, role);
      alert('User role updated successfully');
      // Reload users
      const data = await adminApi.getAllUsers(page, 50);
      setUsers(data.users);
    } catch (err: any) {
      console.error('Error updating role:', err);
      alert(err.response?.data?.message || 'Failed to update role');
    }
  };

  const handleBanToggle = async (userId: number) => {
    try {
      await adminApi.toggleUserBan(userId);
      alert('User ban status updated');
      // Reload users
      const data = await adminApi.getAllUsers(page, 50);
      setUsers(data.users);
    } catch (err: any) {
      console.error('Error toggling ban:', err);
      alert(err.response?.data?.message || 'Failed to toggle ban');
    }
  };

  if (loading && !users.length) {
    return (
      <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white flex items-center justify-center">
        <div className="text-2xl">Loading admin panel...</div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-b from-gray-900 to-gray-800 text-white">
      <div className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex justify-between items-center mb-8">
          <h1 className="text-4xl font-bold bg-gradient-to-r from-red-400 to-purple-600 bg-clip-text text-transparent">
            🛡️ Admin Panel
          </h1>
          <button
            onClick={() => navigate('/town')}
            className="bg-gray-700 hover:bg-gray-600 text-white font-bold py-2 px-6 rounded-lg transition"
          >
            ← Back to Town
          </button>
        </div>

        {/* Error Message */}
        {error && (
          <div className="bg-red-900/50 border border-red-600 rounded-lg p-4 mb-6">
            <p className="text-red-200">{error}</p>
          </div>
        )}

        {/* Dashboard Stats */}
        {stats && (
          <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
            <div className="bg-blue-900/30 border border-blue-600 rounded-lg p-4">
              <div className="text-sm text-blue-300 mb-1">Total Users</div>
              <div className="text-3xl font-bold">{stats.totalUsers.toLocaleString()}</div>
            </div>
            <div className="bg-green-900/30 border border-green-600 rounded-lg p-4">
              <div className="text-sm text-green-300 mb-1">Online Now</div>
              <div className="text-3xl font-bold">{stats.onlineUsers.toLocaleString()}</div>
            </div>
            <div className="bg-purple-900/30 border border-purple-600 rounded-lg p-4">
              <div className="text-sm text-purple-300 mb-1">New Today</div>
              <div className="text-3xl font-bold">{stats.newUsersToday.toLocaleString()}</div>
            </div>
            <div className="bg-yellow-900/30 border border-yellow-600 rounded-lg p-4">
              <div className="text-sm text-yellow-300 mb-1">Total Battles</div>
              <div className="text-3xl font-bold">{stats.totalBattles.toLocaleString()}</div>
            </div>
            <div className="bg-red-900/30 border border-red-600 rounded-lg p-4">
              <div className="text-sm text-red-300 mb-1">Active Battles</div>
              <div className="text-3xl font-bold">{stats.activeBattles.toLocaleString()}</div>
            </div>
            <div className="bg-orange-900/30 border border-orange-600 rounded-lg p-4">
              <div className="text-sm text-orange-300 mb-1">Total Gold</div>
              <div className="text-3xl font-bold">{stats.totalGoldInEconomy.toLocaleString()}</div>
            </div>
          </div>
        )}

        {/* User Management */}
        <div className="bg-gray-800 rounded-lg border border-gray-700 p-6">
          <h2 className="text-2xl font-bold mb-4">User Management</h2>

          {/* Search */}
          <div className="flex gap-2 mb-6">
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyPress={(e) => e.key === 'Enter' && handleSearch()}
              placeholder="Search by username or email..."
              className="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white placeholder-gray-400 focus:outline-none focus:border-blue-500"
            />
            <button
              onClick={handleSearch}
              className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition"
            >
              Search
            </button>
            {searchQuery && (
              <button
                onClick={() => {
                  setSearchQuery('');
                  setPage(1);
                }}
                className="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-lg transition"
              >
                Clear
              </button>
            )}
          </div>

          {/* Users Table */}
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-gray-700">
                  <th className="text-left py-3 px-4">PID</th>
                  <th className="text-left py-3 px-4">Username</th>
                  <th className="text-left py-3 px-4">Email</th>
                  <th className="text-left py-3 px-4">Role</th>
                  <th className="text-left py-3 px-4">Level</th>
                  <th className="text-left py-3 px-4">Gold</th>
                  <th className="text-left py-3 px-4">Status</th>
                  <th className="text-left py-3 px-4">Actions</th>
                </tr>
              </thead>
              <tbody>
                {users.map((u) => {
                  const roleBadge = getRoleBadge(u.role);
                  return (
                    <tr key={u.id} className="border-b border-gray-700/50 hover:bg-gray-700/30">
                      <td className="py-3 px-4 font-mono text-sm text-gray-400">
                        {formatPID(u.id)}
                      </td>
                      <td className="py-3 px-4 font-semibold">{u.username}</td>
                      <td className="py-3 px-4 text-sm text-gray-400">{u.email || 'N/A'}</td>
                      <td className="py-3 px-4">
                        <span
                          className={`${roleBadge.bgClass} ${roleBadge.textClass} px-2 py-1 rounded text-xs font-bold`}
                        >
                          {roleBadge.label}
                        </span>
                      </td>
                      <td className="py-3 px-4">{u.level}</td>
                      <td className="py-3 px-4">💰 {u.gold.toLocaleString()}</td>
                      <td className="py-3 px-4">
                        {u.verified ? (
                          <span className="text-green-400">✓ Active</span>
                        ) : (
                          <span className="text-red-400">✗ Banned</span>
                        )}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex gap-2">
                          <select
                            value={u.role}
                            onChange={(e) => handleUpdateRole(u.id, e.target.value)}
                            className="bg-gray-700 border border-gray-600 rounded px-2 py-1 text-sm text-white"
                          >
                            <option value="PLAYER">Player</option>
                            <option value="SUPPORTER">Supporter</option>
                            <option value="HELPER">Helper</option>
                            <option value="LIBRARIAN">Librarian</option>
                            <option value="MODERATOR">Moderator</option>
                            <option value="GOVERNOR">Governor</option>
                            <option value="ADMIN">Admin</option>
                          </select>
                          <button
                            onClick={() => handleBanToggle(u.id)}
                            className={`${
                              u.verified
                                ? 'bg-red-600 hover:bg-red-700'
                                : 'bg-green-600 hover:bg-green-700'
                            } text-white text-xs font-bold px-3 py-1 rounded transition`}
                          >
                            {u.verified ? 'Ban' : 'Unban'}
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {!searchQuery && totalPages > 1 && (
            <div className="flex justify-between items-center mt-6">
              <div className="text-sm text-gray-400">
                Page {page} of {totalPages} ({total.toLocaleString()} total users)
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage(Math.max(1, page - 1))}
                  disabled={page === 1}
                  className="bg-gray-700 hover:bg-gray-600 disabled:bg-gray-800 disabled:cursor-not-allowed text-white font-bold py-2 px-4 rounded-lg transition"
                >
                  ← Previous
                </button>
                <button
                  onClick={() => setPage(Math.min(totalPages, page + 1))}
                  disabled={page === totalPages}
                  className="bg-gray-700 hover:bg-gray-600 disabled:bg-gray-800 disabled:cursor-not-allowed text-white font-bold py-2 px-4 rounded-lg transition"
                >
                  Next →
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
