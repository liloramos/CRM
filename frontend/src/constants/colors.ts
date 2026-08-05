export const colors = {
  background: '#070708',
  backgroundSoft: '#0d0e10',
  surface: '#121316',
  surfaceSoft: '#18191d',
  surfaceRaised: '#202126',
  border: 'rgba(255, 255, 255, 0.08)',
  borderStrong: 'rgba(255, 255, 255, 0.14)',
  textPrimary: '#f7f5f0',
  textSecondary: '#b8b7b2',
  textMuted: '#7d7d80',
  brandPrimary: '#c9a55a',
  brandSecondary: '#e1c27b',
  brandSoft: 'rgba(201, 165, 90, 0.12)',
  success: '#4faf83',
  warning: '#d5a84a',
  danger: '#d2676f',
  info: '#8ea4a8',
  manual: '#9a9282',
} as const

export type ColorToken = keyof typeof colors
