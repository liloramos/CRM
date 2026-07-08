export const colors = {
  background: '#07080a',
  backgroundSoft: '#0d0f13',
  surface: '#111318',
  surfaceSoft: '#171a20',
  surfaceRaised: '#1d2028',
  border: '#2a2e38',
  borderStrong: '#3b414d',
  textPrimary: '#f7f7f8',
  textSecondary: '#a5a8b1',
  textMuted: '#707783',
  brandPrimary: '#ffb300',
  brandSecondary: '#ff8a00',
  brandSoft: 'rgba(255, 179, 0, 0.14)',
  success: '#22c55e',
  warning: '#f59e0b',
  danger: '#ef4444',
  info: '#38bdf8',
  manual: '#a78bfa',
} as const

export type ColorToken = keyof typeof colors
