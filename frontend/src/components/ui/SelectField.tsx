import { createPortal } from 'react-dom'
import { useEffect, useId, useMemo, useRef, useState, type CSSProperties, type KeyboardEvent } from 'react'
import { Icon } from './Icon'

export type SelectFieldOption = {
  value: string
  label: string
  description?: string
  disabled?: boolean
}

type SelectFieldProps = {
  label: string
  value: string
  options: SelectFieldOption[]
  onChange: (value: string) => void
  disabled?: boolean
  placeholder?: string
  searchable?: boolean
  searchPlaceholder?: string
}

export function SelectField({
  disabled = false,
  label,
  onChange,
  options,
  placeholder = 'Selecione uma opção',
  searchable = false,
  searchPlaceholder = 'Buscar...',
  value,
}: SelectFieldProps) {
  const id = useId()
  const triggerRef = useRef<HTMLButtonElement | null>(null)
  const searchRef = useRef<HTMLInputElement | null>(null)
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(0)
  const [query, setQuery] = useState('')
  const [menuStyle, setMenuStyle] = useState<CSSProperties>({})
  const selected = options.find((option) => option.value === value)
  const filteredOptions = useMemo(() => {
    const normalizedQuery = normalize(query)

    if (!normalizedQuery) {
      return options
    }

    return options.filter((option) => {
      const haystack = normalize(`${option.label} ${option.description ?? ''}`)

      return haystack.includes(normalizedQuery)
    })
  }, [options, query])
  const activeOption = filteredOptions[activeIndex]

  function updatePosition() {
    const rect = triggerRef.current?.getBoundingClientRect()

    if (!rect) {
      return
    }

    const viewportPadding = 12
    const estimatedMenuHeight = Math.min(340, window.innerHeight - viewportPadding * 2)
    const spaceBelow = window.innerHeight - rect.bottom - viewportPadding
    const spaceAbove = rect.top - viewportPadding
    const opensAbove = spaceBelow < Math.min(estimatedMenuHeight, 220) && spaceAbove > spaceBelow
    const availableHeight = Math.max(120, opensAbove ? spaceAbove - 6 : spaceBelow - 6)
    const width = Math.min(rect.width, window.innerWidth - viewportPadding * 2)
    const left = Math.min(Math.max(viewportPadding, rect.left), window.innerWidth - width - viewportPadding)

    setMenuStyle({
      left,
      top: opensAbove ? Math.max(viewportPadding, rect.top - 6) : rect.bottom + 6,
      width,
      maxHeight: availableHeight,
      transform: opensAbove ? 'translateY(-100%)' : undefined,
    })
  }

  function close() {
    setIsOpen(false)
    setQuery('')
  }

  useEffect(() => {
    if (!isOpen) {
      return
    }

    function handleClick(event: MouseEvent) {
      const target = event.target as Node

      if (triggerRef.current?.contains(target)) {
        return
      }

      const menu = document.getElementById(`${id}-listbox`)
      if (menu?.contains(target)) {
        return
      }

      close()
    }

    function handleResize() {
      updatePosition()
    }

    document.addEventListener('mousedown', handleClick)
    window.addEventListener('resize', handleResize)
    window.addEventListener('scroll', handleResize, true)

    return () => {
      document.removeEventListener('mousedown', handleClick)
      window.removeEventListener('resize', handleResize)
      window.removeEventListener('scroll', handleResize, true)
    }
  }, [id, isOpen])

  useEffect(() => {
    if (isOpen) {
      window.setTimeout(() => {
        searchRef.current?.focus()
      }, 0)
    }
  }, [isOpen])

  function open() {
    if (disabled) {
      return
    }

    updatePosition()
    setActiveIndex(Math.max(0, filteredOptions.findIndex((option) => option.value === value)))
    setIsOpen(true)
  }

  function select(option: SelectFieldOption | undefined) {
    if (!option || option.disabled) {
      return
    }

    onChange(option.value)
    close()
    triggerRef.current?.focus()
  }

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement | HTMLInputElement>) {
    if (!isOpen && ['ArrowDown', 'Enter', ' '].includes(event.key)) {
      event.preventDefault()
      open()
      return
    }

    if (event.key === 'Escape') {
      event.preventDefault()
      close()
      triggerRef.current?.focus()
      return
    }

    if (!isOpen || filteredOptions.length === 0) {
      return
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActiveIndex((current) => Math.min(current + 1, filteredOptions.length - 1))
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveIndex((current) => Math.max(current - 1, 0))
    }

    if (event.key === 'Enter') {
      event.preventDefault()
      select(activeOption)
    }
  }

  const menu = isOpen ? (
    <div className="select-field__portal" style={menuStyle}>
      <div
        aria-labelledby={`${id}-label`}
        className="select-field__menu"
        id={`${id}-listbox`}
        role="listbox"
      >
        {searchable ? (
          <label className="select-field__search">
            <span>Buscar</span>
            <input
              onChange={(event) => {
                setQuery(event.target.value)
                setActiveIndex(0)
              }}
              onKeyDown={handleKeyDown}
              placeholder={searchPlaceholder}
              ref={searchRef}
              value={query}
            />
          </label>
        ) : null}
        <div className="select-field__options">
          {filteredOptions.length === 0 ? <span className="select-field__empty">Nenhum resultado encontrado.</span> : null}
          {filteredOptions.map((option, index) => (
            <button
              aria-selected={option.value === value}
              className={[
                'select-field__option',
                index === activeIndex ? 'is-active' : '',
                option.disabled ? 'is-disabled' : '',
              ].filter(Boolean).join(' ')}
              disabled={option.disabled}
              key={option.value}
              onClick={() => select(option)}
              onMouseEnter={() => setActiveIndex(index)}
              role="option"
              type="button"
            >
              <span>
                <strong>{option.label}</strong>
                {option.description ? <small>{option.description}</small> : null}
              </span>
              {option.value === value ? <Icon name="check" size={15} /> : null}
            </button>
          ))}
        </div>
      </div>
    </div>
  ) : null

  return (
    <label className="select-field">
      <span className="field-label" id={`${id}-label`}>{label}</span>
      <button
        aria-controls={`${id}-listbox`}
        aria-expanded={isOpen}
        aria-haspopup="listbox"
        className={isOpen ? 'select-field__trigger is-open' : 'select-field__trigger'}
        disabled={disabled}
        onClick={() => (isOpen ? close() : open())}
        onKeyDown={handleKeyDown}
        ref={triggerRef}
        role="combobox"
        type="button"
      >
        <span className={selected ? 'select-field__value' : 'select-field__placeholder'}>
          {selected?.label ?? placeholder}
        </span>
        <Icon name="chevron-right" size={16} />
      </button>
      {typeof document !== 'undefined' ? createPortal(menu, document.body) : null}
    </label>
  )
}

function normalize(value: string): string {
  return value
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .trim()
}
