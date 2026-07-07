type TabsProps = {
  tabs: Array<{ key: string; label: string; count?: number }>
  active: string
  onChange: (key: string) => void
}

export function Tabs({ active, onChange, tabs }: TabsProps) {
  return (
    <div className="tabs" role="tablist">
      {tabs.map((tab) => (
        <button
          aria-selected={active === tab.key}
          className={active === tab.key ? 'tab is-active' : 'tab'}
          key={tab.key}
          onClick={() => onChange(tab.key)}
          role="tab"
          type="button"
        >
          <span>{tab.label}</span>
          {tab.count !== undefined ? <span className="tab__count">{tab.count}</span> : null}
        </button>
      ))}
    </div>
  )
}
