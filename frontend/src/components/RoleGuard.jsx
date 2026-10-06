import { useAuth } from '../auth/AuthContext';

export function RoleGuard({ roles, children, fallback = null }) {
  const { user } = useAuth();
  const granted = user?.roles ?? [];
  const allowed = roles.some((role) => granted.includes(role));

  return allowed ? children : fallback;
}
