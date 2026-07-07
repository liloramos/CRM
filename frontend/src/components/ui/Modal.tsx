import type { ReactNode } from 'react'
import { Button, IconButton } from './Button'

type ModalProps = {
  open: boolean
  title: string
  description?: string
  children: ReactNode
  primaryLabel?: string
  danger?: boolean
  onClose: () => void
}

export function Modal({ children, danger = false, description, onClose, open, primaryLabel = 'Confirmar', title }: ModalProps) {
  if (!open) {
    return null
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <div aria-modal="true" className="modal" role="dialog">
        <div className="modal__header">
          <div>
            <h2>{title}</h2>
            {description ? <p>{description}</p> : null}
          </div>
          <IconButton icon="close" label="Fechar modal" onClick={onClose} />
        </div>
        <div className="modal__body">{children}</div>
        <div className="modal__footer">
          <Button onClick={onClose} variant="secondary">
            Cancelar
          </Button>
          <Button icon={danger ? 'alert' : 'check'} onClick={onClose} variant={danger ? 'danger' : 'primary'}>
            {primaryLabel}
          </Button>
        </div>
      </div>
    </div>
  )
}
