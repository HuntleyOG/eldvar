import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { authApi } from '../lib/api';
import { useAuthStore } from '../store/authStore';

export function RegisterPage() {
  const navigate = useNavigate();
  const setAuth = useAuthStore((state) => state.setAuth);
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');

  const registerMutation = useMutation({
    mutationFn: authApi.register,
    onSuccess: (data) => {
      setAuth(data.user, data.access_token);
      navigate('/game');
    },
    onError: (err: any) => {
      setError(err.response?.data?.message || 'Registration failed. Please try again.');
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    // Validation
    if (!username || !password) {
      setError('Username and password are required');
      return;
    }

    if (username.length < 3) {
      setError('Username must be at least 3 characters long');
      return;
    }

    if (password.length < 6) {
      setError('Password must be at least 6 characters long');
      return;
    }

    if (password !== confirmPassword) {
      setError('Passwords do not match');
      return;
    }

    registerMutation.mutate({
      username,
      password,
      email: email || undefined,
    });
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
          <p className="text-pixel-muted mt-4">Begin your adventure</p>
        </div>

        <div className="panel-pixel p-8">
          <h2 className="text-2xl font-bold mb-6">REGISTER</h2>

          {error && (
            <div className="bg-pixel-danger border-4 border-black text-white px-4 py-3 mb-4">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="username" className="block text-sm font-bold text-pixel-text mb-2">
                USERNAME *
              </label>
              <input
                id="username"
                type="text"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                className="w-full px-4 py-2 bg-pixel-bg border-4 border-black focus:outline-none focus:border-pixel-secondary text-pixel-text"
                placeholder="Choose a username"
                autoComplete="username"
              />
            </div>

            <div>
              <label htmlFor="email" className="block text-sm font-bold text-pixel-text mb-2">
                EMAIL (optional)
              </label>
              <input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full px-4 py-2 bg-pixel-bg border-4 border-black focus:outline-none focus:border-pixel-secondary text-pixel-text"
                placeholder="your@email.com"
                autoComplete="email"
              />
            </div>

            <div>
              <label htmlFor="password" className="block text-sm font-bold text-pixel-text mb-2">
                PASSWORD *
              </label>
              <input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full px-4 py-2 bg-pixel-bg border-4 border-black focus:outline-none focus:border-pixel-secondary text-pixel-text"
                placeholder="Create a password"
                autoComplete="new-password"
              />
            </div>

            <div>
              <label htmlFor="confirmPassword" className="block text-sm font-bold text-pixel-text mb-2">
                CONFIRM PASSWORD *
              </label>
              <input
                id="confirmPassword"
                type="password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                className="w-full px-4 py-2 bg-pixel-bg border-4 border-black focus:outline-none focus:border-pixel-secondary text-pixel-text"
                placeholder="Confirm your password"
                autoComplete="new-password"
              />
            </div>

            <button
              type="submit"
              disabled={registerMutation.isPending}
              className="w-full btn-pixel bg-pixel-secondary hover:bg-purple-600 disabled:bg-pixel-muted text-white py-3"
            >
              {registerMutation.isPending ? 'CREATING ACCOUNT...' : 'REGISTER'}
            </button>
          </form>

          <div className="mt-6 text-center text-pixel-text">
            Already have an account?{' '}
            <Link to="/login" className="text-pixel-secondary hover:text-purple-500 font-bold">
              Login here
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
