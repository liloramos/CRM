import { createPortal } from 'react-dom'
import { useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties } from 'react'
import { Button } from './Button'

type DatePickerFieldProps = {
  label: string
  value: string
  onChange: (value: string) => void
  secondaryLabel?: (value: string) => string
}

export function DatePickerField({ label, onChange, secondaryLabel = weekdayLabel, value }: DatePickerFieldProps) {
  const id = useId()
  const containerRef = useRef<HTMLDivElement>(null)
  const popoverRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const selectedDate = parseDateString(value) ?? new Date()
  const [isOpen, setIsOpen] = useState(false)
  const [popoverStyle, setPopoverStyle] = useState<CSSProperties>({ visibility: 'hidden' })
  const [visibleMonth, setVisibleMonth] = useState(() => startOfMonth(selectedDate))

  function updatePosition() {
    const trigger = triggerRef.current
    const popover = popoverRef.current
    if (!trigger || !popover) return

    const margin = 12
    const gap = 8
    const triggerRect = trigger.getBoundingClientRect()
    const width = Math.min(328, window.innerWidth - (margin * 2))
    const measuredHeight = popover.offsetHeight || 380
    const availableBelow = window.innerHeight - triggerRect.bottom - margin
    const availableAbove = triggerRect.top - margin
    const openAbove = availableBelow < measuredHeight + gap && availableAbove > availableBelow
    const unclampedTop = openAbove
      ? triggerRect.top - measuredHeight - gap
      : triggerRect.bottom + gap
    const top = Math.max(margin, Math.min(unclampedTop, window.innerHeight - measuredHeight - margin))
    const left = Math.max(margin, Math.min(triggerRect.left, window.innerWidth - width - margin))

    setPopoverStyle({ left, position: 'fixed', top, visibility: 'visible', width })
  }

  useLayoutEffect(() => {
    if (isOpen) updatePosition()
  }, [isOpen, visibleMonth])

  useEffect(() => {
    if (!isOpen) return undefined

    function closeAndRestoreFocus() {
      setIsOpen(false)
      triggerRef.current?.focus()
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') closeAndRestoreFocus()
    }

    function handlePointerDown(event: MouseEvent) {
      const target = event.target as Node
      if (!containerRef.current?.contains(target) && !popoverRef.current?.contains(target)) setIsOpen(false)
    }

    function handleViewportChange() {
      updatePosition()
    }

    document.addEventListener('keydown', handleKeyDown)
    document.addEventListener('mousedown', handlePointerDown)
    window.addEventListener('resize', handleViewportChange)
    window.addEventListener('scroll', handleViewportChange, true)
    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      document.removeEventListener('mousedown', handlePointerDown)
      window.removeEventListener('resize', handleViewportChange)
      window.removeEventListener('scroll', handleViewportChange, true)
    }
  }, [isOpen])

  const days = monthCalendarDays(visibleMonth)
  const monthLabel = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' }).format(visibleMonth)
  const today = dateToString(new Date())

  function toggleCalendar() {
    setVisibleMonth(startOfMonth(parseDateString(value) ?? new Date()))
    setPopoverStyle({ visibility: 'hidden' })
    setIsOpen((current) => !current)
  }

  function selectDate(nextDate: string) {
    onChange(nextDate)
    setVisibleMonth(startOfMonth(parseDateString(nextDate) ?? new Date()))
    setIsOpen(false)
    triggerRef.current?.focus()
  }

  const popover = isOpen ? (
    <div
      aria-label={`Selecionar ${label.toLowerCase()}`}
      className="menu-datepicker__popover"
      id={`${id}-dialog`}
      ref={popoverRef}
      role="dialog"
      style={popoverStyle}
    >
      <div className="menu-datepicker__header">
        <button aria-label="Mês anterior" onClick={() => setVisibleMonth(shiftMonth(visibleMonth, -1))} type="button">{'<'}</button>
        <strong>{capitalize(monthLabel)}</strong>
        <button aria-label="Próximo mês" onClick={() => setVisibleMonth(shiftMonth(visibleMonth, 1))} type="button">{'>'}</button>
      </div>
      <div className="menu-datepicker__weekdays" aria-hidden="true">
        {['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'].map((day) => <span key={day}>{day}</span>)}
      </div>
      <div className="menu-datepicker__days" role="grid">
        {days.map((day) => {
          const dayValue = dateToString(day)
          const isSelected = dayValue === value
          const isToday = dayValue === today
          const outsideMonth = day.getMonth() !== visibleMonth.getMonth()

          return (
            <button
              aria-current={isToday ? 'date' : undefined}
              aria-label={formatDateLabel(dayValue)}
              aria-selected={isSelected}
              className={[
                'menu-datepicker__day',
                isSelected ? 'is-selected' : '',
                isToday ? 'is-today' : '',
                outsideMonth ? 'is-outside' : '',
              ].filter(Boolean).join(' ')}
              key={dayValue}
              onClick={() => selectDate(dayValue)}
              role="gridcell"
              type="button"
            >
              {day.getDate()}
            </button>
          )
        })}
      </div>
      <div className="menu-datepicker__footer">
        <Button onClick={() => selectDate(today)} size="sm" variant="secondary">Hoje</Button>
      </div>
    </div>
  ) : null

  return (
    <div className="menu-datepicker" ref={containerRef}>
      <span>{label}</span>
      <button
        aria-controls={`${id}-dialog`}
        aria-expanded={isOpen}
        aria-haspopup="dialog"
        className="menu-datepicker__trigger"
        onClick={toggleCalendar}
        ref={triggerRef}
        type="button"
      >
        <strong>{formatDateLabel(value)}</strong>
        <small>{value === today ? 'Hoje' : secondaryLabel(value)}</small>
      </button>
      {typeof document !== 'undefined' ? createPortal(popover, document.body) : null}
    </div>
  )
}

function parseDateString(value: string): Date | null {
  const [year, month, day] = value.split('-').map(Number)
  if (!year || !month || !day) return null
  const date = new Date(year, month - 1, day)
  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day ? date : null
}

function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1)
}

function shiftMonth(date: Date, offset: number): Date {
  return new Date(date.getFullYear(), date.getMonth() + offset, 1)
}

function monthCalendarDays(monthDate: Date): Date[] {
  const firstDay = startOfMonth(monthDate)
  const firstCalendarDay = new Date(firstDay)
  firstCalendarDay.setDate(firstDay.getDate() - ((firstDay.getDay() + 6) % 7))
  return Array.from({ length: 42 }, (_, index) => {
    const day = new Date(firstCalendarDay)
    day.setDate(firstCalendarDay.getDate() + index)
    return day
  })
}

function dateToString(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function formatDateLabel(value: string): string {
  const [year, month, day] = value.split('-')
  return year && month && day ? `${day}/${month}/${year}` : 'Selecione uma data'
}

function weekdayLabel(value: string): string {
  if (value === '') return ''
  const date = parseDateString(value)
  return date ? capitalize(new Intl.DateTimeFormat('pt-BR', { weekday: 'long' }).format(date)) : 'Data inválida'
}

function capitalize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1)
}
