import { Archive, X } from 'lucide-react'
import { useEffect, useRef } from 'react'
import type { ChampsSearch } from '../services/champs.service'

export type ChampsArchiveTarget =
  | { kind: 'all' }
  | { kind: 'search'; search: ChampsSearch }

type ChampsArchiveDialogProps = {
  isBusy: boolean
  onCancel: () => void
  onConfirm: () => void
  target: ChampsArchiveTarget
}

export function ChampsArchiveDialog({
  isBusy,
  onCancel,
  onConfirm,
  target,
}: ChampsArchiveDialogProps) {
  const cancelButtonRef = useRef<HTMLButtonElement>(null)
  const isAll = target.kind === 'all'

  useEffect(() => {
    cancelButtonRef.current?.focus()

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape' && !isBusy) {
        onCancel()
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [isBusy, onCancel])

  return (
    <div className="champs-modal-backdrop" role="presentation">
      <section
        aria-describedby="champs-archive-description"
        aria-labelledby="champs-archive-title"
        aria-modal="true"
        className="champs-modal"
        role="dialog"
      >
        <div className="champs-modal__icon" aria-hidden="true">
          <Archive size={22} />
        </div>
        <div>
          <h2 id="champs-archive-title">
            {isAll ? 'Limpar histórico ativo?' : `Arquivar “${target.search.name}”?`}
          </h2>
          <p id="champs-archive-description">
            As pesquisas sairão da lista ativa, mas os leads e a memória de prospecção serão preservados.
            Empresas já apresentadas continuarão ocultas nas próximas buscas progressivas.
          </p>
        </div>
        <button
          aria-label="Fechar confirmação"
          className="champs-modal__close"
          disabled={isBusy}
          onClick={onCancel}
          type="button"
        >
          <X aria-hidden="true" size={18} />
        </button>
        <div className="champs-modal__actions">
          <button
            className="champs-button champs-button--secondary"
            disabled={isBusy}
            onClick={onCancel}
            ref={cancelButtonRef}
            type="button"
          >
            Cancelar
          </button>
          <button
            className="champs-button champs-button--danger"
            disabled={isBusy}
            onClick={onConfirm}
            type="button"
          >
            <Archive aria-hidden="true" size={17} />
            {isBusy ? 'Arquivando...' : isAll ? 'Limpar histórico' : 'Arquivar pesquisa'}
          </button>
        </div>
      </section>
    </div>
  )
}
