// Format user ID as PID (Player ID)
// Example: user with id=1 → "#0001", id=42 → "#0042", id=12345 → "#12345"
export function formatPID(userId: number): string {
  return `#${userId.toString().padStart(4, '0')}`;
}

// Get user display name with PID
// Example: "Huntley#0001"
export function getDisplayNameWithPID(username: string, userId: number): string {
  return `${username}${formatPID(userId)}`;
}

// Get role badge color and label
export function getRoleBadge(role: string): { color: string; label: string; bgClass: string; textClass: string } {
  const roleMap: Record<string, { color: string; label: string; bgClass: string; textClass: string }> = {
    ADMIN: {
      color: 'red',
      label: 'Admin',
      bgClass: 'bg-red-600',
      textClass: 'text-red-100',
    },
    GOVERNOR: {
      color: 'purple',
      label: 'Governor',
      bgClass: 'bg-purple-600',
      textClass: 'text-purple-100',
    },
    MODERATOR: {
      color: 'blue',
      label: 'Moderator',
      bgClass: 'bg-blue-600',
      textClass: 'text-blue-100',
    },
    HELPER: {
      color: 'green',
      label: 'Helper',
      bgClass: 'bg-green-600',
      textClass: 'text-green-100',
    },
    LIBRARIAN: {
      color: 'indigo',
      label: 'Librarian',
      bgClass: 'bg-indigo-600',
      textClass: 'text-indigo-100',
    },
    SUPPORTER: {
      color: 'yellow',
      label: 'Supporter',
      bgClass: 'bg-yellow-600',
      textClass: 'text-yellow-100',
    },
    PLAYER: {
      color: 'gray',
      label: 'Player',
      bgClass: 'bg-gray-600',
      textClass: 'text-gray-100',
    },
  };

  return roleMap[role] || roleMap.PLAYER;
}
