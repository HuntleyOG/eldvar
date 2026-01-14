import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { authApi } from '../lib/api';
import { useAuthStore } from '../store/authStore';

export function LoginPage() {
  const navigate = useNavigate();
  const setAuth = useAuthStore((state) => state.setAuth);
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');

  const loginMutation = useMutation({
    mutationFn: authApi.login,
    onSuccess: (data) => {
      setAuth(data.user, data.access_token);
      navigate('/game');
    },
    onError: (err: any) => {
      setError(err.response?.data?.message || 'Login failed. Please try again.');
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    if (!username || !password) {
      setError('Please fill in all fields');
      return;
    }

    loginMutation.mutate({ username, password });
  };

  return (
    <div className="min-h-screen bg-pixel-bg text-pixel-text flex items-center justify-center px-4">
      <div className="max-w-md w-full">
        <div className="text-center mb-8">
          <Link to="/">
            <h1 className="text-5xl font-bold mb-2 text-pixel-primary" style={{ textShadow: '4px 4px 0px rgba(0,0,0,0.5)' }}>
              ELDVAR
            </h1>
          </Link>
          <p className="text-pixel-muted mt-4">Welcome back, adventurer</p>
        </div>

        <div className="panel-pixel p-8">
          <h2 className="text-2xl font-bold mb-6">LOGIN</h2>

          {error && (
            <div className="bg-pixel-danger border-4 border-black text-white px-4 py-3 mb-4">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="username" className="block text-sm font-bold text-pixel-text mb-2">
                USERNAME
              </label>
              <input
                id="username"
                type="text"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                className="w-full px-4 py-2 bg-pixel-bg border-4 border-black focus:outline-none focus:border-pixel-primary text-pixel-text"
                placeholder="Enter your username"
                autoComplete="username"
              />
            </div>

            <div>
              <label htmlFor="password" className="block text-sm font-bold text-pixel-text mb-2">
                PASSWORD
              </label>
              <input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full px-4 py-2 bg-pixel-bg border-4 border-black focus:outline-none focus:border-pixel-primary text-pixel-text"
                placeholder="Enter your password"
                autoComplete="current-password"
              />
            </div>

            <button
              type="submit"
              disabled={loginMutation.isPending}
              className="w-full btn-pixel bg-pixel-primary hover:bg-red-600 disabled:bg-pixel-muted text-white py-3"
            >
              {loginMutation.isPending ? 'LOGGING IN...' : 'LOGIN'}
            </button>
          </form>

          <div className="mt-6 text-center text-pixel-text">
            Don't have an account?{' '}
            <Link to="/register" className="text-pixel-primary hover:text-red-500 font-bold">
              Register here
            </Link>
          </div>
        </div>

        <div className="mt-6 text-center">
          <Link to="/" className="text-pixel-muted hover:text-pixel-text">
            ← Back to home
          </Link>
        </div>
      </div>
    </div>
  );
}
