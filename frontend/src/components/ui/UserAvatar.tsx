import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { initialsFromName } from '../../utils/formatters'

type UserAvatarProps = {
  avatarUrl?: string | null
  className?: string
  name: string
}

export function UserAvatar({ avatarUrl, className = '', name }: UserAvatarProps) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null)
  const [isPreviewOpen, setIsPreviewOpen] = useState(false)
  const avatarRef = useRef<HTMLImageElement>(null)
  const closeButtonRef = useRef<HTMLButtonElement>(null)

  const classes = ['avatar', className].filter(Boolean).join(' ')
  const hasPhoto = Boolean(avatarUrl && failedUrl !== avatarUrl)

  useEffect(() => {
    if (!isPreviewOpen) return

    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsPreviewOpen(false)
    }
    const previousOverflow = document.body.style.overflow

    document.addEventListener('keydown', closeOnEscape)
    document.body.style.overflow = 'hidden'
    closeButtonRef.current?.focus()

    return () => {
      document.removeEventListener('keydown', closeOnEscape)
      document.body.style.overflow = previousOverflow
      avatarRef.current?.focus()
    }
  }, [isPreviewOpen])

  const openPreview = () => setIsPreviewOpen(true)
  const closePreview = () => setIsPreviewOpen(false)

  if (hasPhoto && avatarUrl) {
    return <>
      <img
        alt={`Foto de ${name}`}
        aria-haspopup="dialog"
        aria-label={`Ampliar foto de ${name}`}
        className={`${classes} avatar--clickable`}
        onClick={(event) => {
          event.stopPropagation()
          openPreview()
        }}
        onError={() => setFailedUrl(avatarUrl)}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault()
            event.stopPropagation()
            openPreview()
          }
        }}
        ref={avatarRef}
        role="button"
        src={avatarUrl}
        tabIndex={0}
      />
      {isPreviewOpen ? createPortal(
        <div aria-label="Visualização ampliada da foto" className="profile-photo-lightbox" onClick={closePreview} role="dialog" aria-modal="true">
          <div className="profile-photo-lightbox__content" onClick={(event) => event.stopPropagation()}>
            <button aria-label="Fechar visualização da foto" className="profile-photo-lightbox__close" onClick={closePreview} ref={closeButtonRef} type="button">×</button>
            <img alt={`Foto ampliada de ${name}`} src={avatarUrl} />
          </div>
        </div>,
        document.body,
      ) : null}
    </>
  }

  return <span aria-label={`Iniciais de ${name}`} className={classes}>{initialsFromName(name)}</span>
}
