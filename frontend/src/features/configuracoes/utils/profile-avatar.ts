export const MAX_AVATAR_SIZE_BYTES = 2 * 1024 * 1024

const ACCEPTED_AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/webp']

export function validateProfileAvatar(file: Pick<File, 'size' | 'type'>): string | null {
  if (!ACCEPTED_AVATAR_TYPES.includes(file.type)) {
    return 'Use uma imagem JPG, PNG ou WebP.'
  }

  if (file.size > MAX_AVATAR_SIZE_BYTES) {
    return 'A imagem deve ter no máximo 2 MB.'
  }

  return null
}
