/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        pixel: {
          bg: '#0f0f1e',
          panel: '#1a1a2e',
          border: '#16213e',
          accent: '#0f3460',
          primary: '#e94560',
          secondary: '#533483',
          success: '#2eb872',
          warning: '#f39c12',
          danger: '#e74c3c',
          text: '#e0e0e0',
          muted: '#7a7a8c',
        },
      },
      fontFamily: {
        pixel: ['Press Start 2P', 'monospace', 'Courier New'],
        mono: ['Courier New', 'monospace'],
      },
      boxShadow: {
        'pixel': '4px 4px 0px 0px rgba(0, 0, 0, 0.4)',
        'pixel-sm': '2px 2px 0px 0px rgba(0, 0, 0, 0.4)',
      },
    },
  },
  plugins: [],
};
