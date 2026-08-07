import { describe, expect, it } from 'vitest'
import { MAX_AVATAR_SIZE_BYTES, validateProfileAvatar } from './profile-avatar'

describe('profile avatar validation', () => {
  it.each([
    ['avatar.jpg', 'image/jpeg'],
    ['avatar.jpeg', 'image/jpeg'],
    ['avatar.png', 'image/png'],
    ['avatar.webp', 'image/webp'],
  ])('aceita o formato %s dentro do limite', (name, type) => {
    const file = new File(['imagem'], name, { type })

    expect(validateProfileAvatar(file)).toBeNull()
  })

  it('rejeita SVG e arquivo de texto renomeado', () => {
    const svg = new File(['<svg></svg>'], 'avatar.svg', { type: 'image/svg+xml' })
    const renamedText = new File(['texto'], 'avatar.jpg', { type: 'text/plain' })

    expect(validateProfileAvatar(svg)).toBe('Use uma imagem JPG, PNG ou WebP.')
    expect(validateProfileAvatar(renamedText)).toBe('Use uma imagem JPG, PNG ou WebP.')
  })

  it('aceita exatamente 2 MB e rejeita qualquer byte excedente', () => {
    expect(validateProfileAvatar({ size: MAX_AVATAR_SIZE_BYTES, type: 'image/png' })).toBeNull()
    expect(validateProfileAvatar({ size: MAX_AVATAR_SIZE_BYTES + 1, type: 'image/png' })).toBe(
      'A imagem deve ter no máximo 2 MB.',
    )
  })
})
