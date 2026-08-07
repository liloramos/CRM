import { useState } from 'react'
import { initialsFromName } from '../../utils/formatters'

type UserAvatarProps = {
  avatarUrl: string | null | undefined
  name: string
  size?: 'sm' | 'lg' | 'xl'
}

export function UserAvatar({ avatarUrl, name, size = 'sm' }: UserAvatarProps) {
  const sizeClass = size === 'sm' ? '' : ` avatar--${size}`

  return (
    <span className={`avatar${sizeClass}`}>
      {avatarUrl ? <AvatarImage key={avatarUrl} avatarUrl={avatarUrl} name={name} /> : <AvatarInitials name={name} />}
    </span>
  )
}

function AvatarImage({ avatarUrl, name }: { avatarUrl: string; name: string }) {
  const [hasFailed, setHasFailed] = useState(false)

  if (hasFailed) {
    return <AvatarInitials name={name} />
  }

  return (
    <img
      alt={`Avatar de ${name}`}
      className="avatar__image"
      onError={() => setHasFailed(true)}
      src={avatarUrl}
    />
  )
}

function AvatarInitials({ name }: { name: string }) {
  return <span aria-hidden="true">{initialsFromName(name) || 'U'}</span>
}
