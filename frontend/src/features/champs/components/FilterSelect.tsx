import { Check, ChevronDown } from 'lucide-react'
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'

export type FilterSelectOption = {
  label: string
  value: string
}

type FilterSelectProps = {
  disabled?: boolean
  hint?: string
  label: string
  onChange: (value: string) => void
  options: FilterSelectOption[]
  value: string
}

export function FilterSelect({ disabled = false, hint, label, onChange, options, value }: FilterSelectProps) {
  const generatedId = useId().replace(/:/g, '')
  const labelId = `filter-select-${generatedId}-label`
  const listboxId = `filter-select-${generatedId}-listbox`
  const rootRef = useRef<HTMLDivElement>(null)
  const selectedIndex = Math.max(
    0,
    options.findIndex((option) => option.value === value),
  )
  const selectedOption = options[selectedIndex] ?? options[0]
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(selectedIndex)

  useEffect(() => {
    if (!isOpen) {
      return undefined
    }

    function handlePointerDown(event: PointerEvent) {
      if (!rootRef.current?.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('pointerdown', handlePointerDown)

    return () => document.removeEventListener('pointerdown', handlePointerDown)
  }, [isOpen])

  function openSelect() {
    setActiveIndex(selectedIndex)
    setIsOpen(true)
  }

  function selectOption(index: number) {
    const nextOption = options[index]

    if (!nextOption) {
      return
    }

    onChange(nextOption.value)
    setActiveIndex(index)
    setIsOpen(false)
  }

  function moveActiveIndex(direction: 1 | -1) {
    setActiveIndex((currentIndex) => {
      const optionCount = options.length

      if (optionCount === 0) {
        return 0
      }

      return (currentIndex + direction + optionCount) % optionCount
    })
  }

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()

      if (!isOpen) {
        openSelect()
        return
      }

      moveActiveIndex(1)
      return
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()

      if (!isOpen) {
        openSelect()
        return
      }

      moveActiveIndex(-1)
      return
    }

    if (event.key === 'Home' && isOpen) {
      event.preventDefault()
      setActiveIndex(0)
      return
    }

    if (event.key === 'End' && isOpen) {
      event.preventDefault()
      setActiveIndex(Math.max(0, options.length - 1))
      return
    }

    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()

      if (!isOpen) {
        openSelect()
        return
      }

      selectOption(activeIndex)
      return
    }

    if (event.key === 'Escape' && isOpen) {
      event.preventDefault()
      setIsOpen(false)
    }
  }

  return (
    <div
      className="champs-filter-field filter-select"
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
          setIsOpen(false)
        }
      }}
      ref={rootRef}
    >
      <span className="champs-filter-field__label" id={labelId}>
        <span>{label}</span>
        {hint ? <small>{hint}</small> : null}
      </span>
      <button
        aria-activedescendant={isOpen ? `${listboxId}-option-${activeIndex}` : undefined}
        aria-controls={listboxId}
        aria-expanded={isOpen}
        aria-haspopup="listbox"
        aria-labelledby={labelId}
        className={isOpen ? 'filter-select__trigger is-open' : 'filter-select__trigger'}
        disabled={disabled}
        onClick={() => {
          if (isOpen) {
            setIsOpen(false)
            return
          }

          openSelect()
        }}
        onKeyDown={handleKeyDown}
        type="button"
      >
        <span>{selectedOption?.label}</span>
        <ChevronDown aria-hidden="true" className="filter-select__chevron" size={17} strokeWidth={2.2} />
      </button>

      {isOpen && !disabled ? (
        <div aria-labelledby={labelId} className="filter-select__menu" id={listboxId} role="listbox">
          {options.map((option, index) => (
            <button
              aria-selected={option.value === selectedOption?.value}
              className={[
                'filter-select__option',
                index === activeIndex ? 'is-active' : '',
                option.value === selectedOption?.value ? 'is-selected' : '',
              ].filter(Boolean).join(' ')}
              id={`${listboxId}-option-${index}`}
              key={option.value}
              onClick={() => selectOption(index)}
              onMouseDown={(event) => event.preventDefault()}
              onMouseEnter={() => setActiveIndex(index)}
              role="option"
              tabIndex={-1}
              type="button"
            >
              <span>{option.label}</span>
              {option.value === selectedOption?.value ? <Check aria-hidden="true" size={15} strokeWidth={2.4} /> : null}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}
