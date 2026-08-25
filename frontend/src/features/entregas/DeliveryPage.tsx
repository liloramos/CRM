import { useEffect, useMemo, useRef, useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import {
  getDeliverySettings,
  getDeliveryTasks,
  overrideDeliveryFee,
  recalculateDeliveryRoute,
  updateDeliverySettings,
} from '../../services/crm.service'
import type { DeliveryDistanceBand, DeliveryMapTask, DeliverySettings } from '../../types/crm'
import './DeliveryPage.css'

type GoogleLatLng = { lat: number; lng: number }
type GoogleMarker = { setMap: (map: GoogleMap | null) => void; getPosition: () => GoogleLatLng }
type GoogleMap = { fitBounds: (bounds: GoogleBounds, padding?: number) => void }
type GooglePolyline = { setMap: (map: GoogleMap | null) => void }
type GoogleBounds = { extend: (position: GoogleLatLng) => void }
type GoogleMapsApi = {
  Map: new (element: HTMLElement, options: Record<string, unknown>) => GoogleMap
  Marker: new (options: { map: GoogleMap; position: GoogleLatLng; title: string }) => GoogleMarker
  Polyline: new (options: Record<string, unknown>) => GooglePolyline
  LatLngBounds: new () => GoogleBounds
  geometry?: { encoding?: { decodePath: (encoded: string) => GoogleLatLng[] } }
}

declare global {
  interface Window { google?: { maps: GoogleMapsApi } }
}

const mapsBrowserKey = import.meta.env.VITE_DELIVERY_GOOGLE_BROWSER_API_KEY as string | undefined

export function DeliveryPage() {
  const [tasks, setTasks] = useState<DeliveryMapTask[]>([])
  const [settings, setSettings] = useState<DeliverySettings | null>(null)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const selected = useMemo(() => tasks.find((task) => task.id === selectedId) ?? tasks[0] ?? null, [selectedId, tasks])

  async function refresh() {
    setLoading(true)
    try {
      const [nextTasks, nextSettings] = await Promise.all([getDeliveryTasks(), getDeliverySettings()])
      setTasks(nextTasks)
      setSettings(nextSettings)
      setSelectedId((current) => current && nextTasks.some((task) => task.id === current) ? current : nextTasks[0]?.id ?? null)
      setMessage(null)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Não foi possível atualizar as entregas.')
    } finally { setLoading(false) }
  }

  useEffect(() => {
    const timeout = window.setTimeout(() => { void refresh() })
    return () => window.clearTimeout(timeout)
  }, [])

  async function recalculateSelected() {
    if (!selected) return
    setSaving(true)
    try { await recalculateDeliveryRoute(selected.id); await refresh(); setMessage('Rota recalculada.') }
    catch (error) { setMessage(error instanceof Error ? error.message : 'Não foi possível calcular a rota. Confira o endereço ou tente novamente.') }
    finally { setSaving(false) }
  }

  async function adjustSelectedFee() {
    if (!selected) return
    const amount = window.prompt('Taxa final da entrega em reais:', centsToInput(selected.final_fee_cents))
    if (amount === null) return
    const cents = moneyInputToCents(amount)
    if (cents === null) { setMessage('Informe uma taxa válida.'); return }
    const reason = window.prompt('Motivo do ajuste (opcional):') ?? undefined
    setSaving(true)
    try { await overrideDeliveryFee(selected.id, cents, reason); await refresh(); setMessage('Taxa de entrega ajustada.') }
    catch (error) { setMessage(error instanceof Error ? error.message : 'Não foi possível ajustar a taxa.') }
    finally { setSaving(false) }
  }

  return <PageContainer>
    <PageHeader description="Acompanhe entregas, revise localizações e calcule taxas a partir da rota registrada." title="Entregas" />
    <div className="delivery-command-center">
      {message ? <p className="delivery-feedback" role="status">{message}</p> : null}
      <section className="delivery-map-panel" aria-label="Mapa operacional de entregas">
        <DeliveryMap selected={selected} tasks={tasks} />
        <div className="delivery-map-panel__legend"><span><i className="delivery-map-panel__marker delivery-map-panel__marker--origin" />Restaurante</span><span><i className="delivery-map-panel__marker" />Entrega ativa</span></div>
      </section>
      <aside className="delivery-queue-panel">
        <div className="delivery-queue-panel__heading"><div><p className="eyebrow">Fila operacional</p><h2>Entregas ativas</h2></div><Button icon="refresh" size="sm" variant="ghost" onClick={() => void refresh()}>Atualizar</Button></div>
        <div className="delivery-queue-panel__list" aria-busy={loading}>
          {!loading && tasks.length === 0 ? <p className="delivery-empty">Nenhuma entrega ativa no momento.</p> : null}
          {tasks.map((task) => <button aria-pressed={selected?.id === task.id} className={`delivery-route-card ${selected?.id === task.id ? 'delivery-route-card--selected' : ''}`} key={task.id} onClick={() => setSelectedId(task.id)} type="button"><span className="delivery-route-card__top"><strong>{task.order_code}</strong><small>{statusLabel(task.status)}</small></span><span className="delivery-route-card__recipient">{task.recipient ?? 'Cliente não informado'}</span><span className="delivery-route-card__meta">{distanceLabel(task.distance_meters)} · {money(task.final_fee_cents)}</span></button>)}
        </div>
      </aside>
      <section className="delivery-detail-panel" aria-live="polite">
        <SectionTitle eyebrow="Entrega selecionada" title={selected?.order_code ?? 'Selecione uma entrega'} />
        {selected ? <><dl className="delivery-details"><div><dt>Cliente</dt><dd>{selected.recipient ?? 'Não informado'}</dd></div><div><dt>Endereço</dt><dd>{addressLabel(selected.address)}</dd></div><div><dt>Rota</dt><dd>{distanceLabel(selected.distance_meters)}{selected.duration_seconds ? ` · ${durationLabel(selected.duration_seconds)}` : ''}</dd></div><div><dt>Taxa calculada</dt><dd>{selected.calculated_fee_cents === null ? 'Aguardando cálculo' : money(selected.calculated_fee_cents)}</dd></div><div><dt>Taxa final</dt><dd>{money(selected.final_fee_cents)}</dd></div></dl><div className="delivery-actions"><Button disabled={saving || !selected.destination} icon="refresh" onClick={() => void recalculateSelected()}>Recalcular rota</Button><Button disabled={saving || selected.quote_id === null} icon="edit" variant="secondary" onClick={() => void adjustSelectedFee()}>Ajustar taxa</Button><ExternalRouteLinks task={selected} /></div></> : <p className="delivery-empty">As informações da rota aparecerão aqui quando houver uma entrega ativa.</p>}
      </section>
      {settings ? <DeliverySettingsForm settings={settings} onSaved={async () => { await refresh(); setMessage('Configuração de entrega atualizada.') }} /> : null}
    </div>
  </PageContainer>
}

function DeliveryMap({ selected, tasks }: { selected: DeliveryMapTask | null; tasks: DeliveryMapTask[] }) {
  const container = useRef<HTMLDivElement | null>(null)
  const map = useRef<GoogleMap | null>(null)
  const markers = useRef<GoogleMarker[]>([])
  const routeLine = useRef<GooglePolyline | null>(null)
  useEffect(() => {
    if (!mapsBrowserKey || !container.current) return
    let cancelled = false
    const start = () => {
      const maps = window.google?.maps
      if (cancelled || !container.current || !maps) return
      const mapInstance = map.current ?? new maps.Map(container.current, { center: { lat: -23.5505, lng: -46.6333 }, disableDefaultUI: true, zoom: 12 })
      map.current = mapInstance
      markers.current.forEach((marker) => marker.setMap(null)); markers.current = []
      routeLine.current?.setMap(null); routeLine.current = null
      const bounds = new maps.LatLngBounds()
      const visible = tasks.filter((task) => task.destination)
      visible.forEach((task) => { const marker = new maps.Marker({ map: mapInstance, position: { lat: task.destination!.latitude, lng: task.destination!.longitude }, title: task.order_code }); markers.current.push(marker); bounds.extend(marker.getPosition()) })
      if (selected?.origin) { const origin = { lat: selected.origin.latitude, lng: selected.origin.longitude }; const originMarker = new maps.Marker({ map: mapInstance, position: origin, title: 'Restaurante' }); markers.current.push(originMarker); bounds.extend(origin) }
      if (selected?.encoded_polyline && maps.geometry?.encoding) { const path = maps.geometry.encoding.decodePath(selected.encoded_polyline); routeLine.current = new maps.Polyline({ map: mapInstance, path, strokeColor: '#f0a43a', strokeOpacity: .9, strokeWeight: 5 }); path.forEach((point) => bounds.extend(point)) }
      if (visible.length) mapInstance.fitBounds(bounds, 48)
    }
    if (window.google?.maps) start()
    else { const script = document.createElement('script'); script.async = true; script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(mapsBrowserKey)}&libraries=geometry`; script.addEventListener('load', start, { once: true }); document.head.appendChild(script) }
    return () => { cancelled = true }
  }, [selected, tasks])
  return mapsBrowserKey ? <div className="delivery-google-map" ref={container} /> : <div className="delivery-map-fallback"><strong>Mapa aguardando configuração</strong><span>Cadastre a chave pública restrita do Google Maps para visualizar as rotas.</span></div>
}

function DeliverySettingsForm({ settings, onSaved }: { settings: DeliverySettings; onSaved: () => Promise<void> }) {
  const origin = settings.provider_options?.origin
  const [mode, setMode] = useState<'per_km' | 'distance_bands'>(settings.calculation_mode === 'distance_bands' ? 'distance_bands' : 'per_km')
  const [rate, setRate] = useState(centsToInput(settings.price_per_km_cents))
  const [address, setAddress] = useState(origin?.address ?? '')
  const [latitude, setLatitude] = useState(origin?.latitude?.toString() ?? '')
  const [longitude, setLongitude] = useState(origin?.longitude?.toString() ?? '')
  const [bands, setBands] = useState<DeliveryDistanceBand[]>(settings.provider_options?.distance_bands ?? [])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); const rateCents = moneyInputToCents(rate)
    if (!Number.isFinite(Number(latitude)) || !Number.isFinite(Number(longitude)) || (mode === 'per_km' && rateCents === null)) { setError('Informe origem e valores válidos para a entrega.'); return }
    setSaving(true)
    try { await updateDeliverySettings({ maps_provider: settings.maps_provider === 'none' ? 'fake' : settings.maps_provider as 'google' | 'fake', pricing_mode: mode, rate_per_km_cents: rateCents ?? undefined, origin: { address, latitude: Number(latitude), longitude: Number(longitude) }, distance_bands: mode === 'distance_bands' ? bands : [] }); setError(null); await onSaved() }
    catch (caught) { setError(caught instanceof Error ? caught.message : 'Não foi possível salvar a configuração.') }
    finally { setSaving(false) }
  }
  return <Card className="delivery-settings-panel"><SectionTitle eyebrow="Configuração da equipe" title="Cálculo de entrega" /><form className="delivery-settings-form" onSubmit={submit}><fieldset><legend>Modo de cobrança</legend><label><input checked={mode === 'per_km'} name="delivery-pricing" onChange={() => setMode('per_km')} type="radio" /> Por quilômetro</label><label><input checked={mode === 'distance_bands'} name="delivery-pricing" onChange={() => setMode('distance_bands')} type="radio" /> Por faixas de distância</label></fieldset>{mode === 'per_km' ? <label>Valor por km<input inputMode="decimal" onChange={(event) => setRate(event.target.value)} value={rate} /></label> : <DistanceBands bands={bands} onChange={setBands} />}<div className="delivery-settings-form__origin"><strong>Origem do restaurante</strong><label>Endereço<input onChange={(event) => setAddress(event.target.value)} value={address} /></label><label>Latitude<input inputMode="decimal" onChange={(event) => setLatitude(event.target.value)} value={latitude} /></label><label>Longitude<input inputMode="decimal" onChange={(event) => setLongitude(event.target.value)} value={longitude} /></label></div>{error ? <p className="delivery-form-error">{error}</p> : null}<Button disabled={saving} icon="check" type="submit">Salvar configuração</Button></form></Card>
}

function DistanceBands({ bands, onChange }: { bands: DeliveryDistanceBand[]; onChange: (bands: DeliveryDistanceBand[]) => void }) {
  function update(index: number, field: keyof DeliveryDistanceBand, value: string) { const next = [...bands]; next[index] = { ...next[index], [field]: field === 'up_to_meters' ? Math.round(Number(value.replace(',', '.')) * 1000) : moneyInputToCents(value) ?? 0 }; onChange(next) }
  return <div className="delivery-bands"><strong>Faixas</strong>{bands.map((band, index) => <div className="delivery-band" key={`${band.up_to_meters}-${index}`}><label>Até (km)<input inputMode="decimal" onChange={(event) => update(index, 'up_to_meters', event.target.value)} value={(band.up_to_meters / 1000).toString().replace('.', ',')} /></label><label>Taxa<input inputMode="decimal" onChange={(event) => update(index, 'fee_cents', event.target.value)} value={centsToInput(band.fee_cents)} /></label><Button aria-label="Remover faixa" className="delivery-band__remove" onClick={() => onChange(bands.filter((_, row) => row !== index))} variant="ghost">Remover</Button></div>)}<Button icon="plus" onClick={() => onChange([...bands, { up_to_meters: 1000, fee_cents: 0 }])} size="sm" variant="secondary">Adicionar faixa</Button></div>
}

function ExternalRouteLinks({ task }: { task: DeliveryMapTask }) { if (!task.destination) return null; const destination = `${task.destination.latitude},${task.destination.longitude}`; return <div className="delivery-external-links"><a href={`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`} rel="noreferrer" target="_blank">Abrir no Maps</a><a href={`https://waze.com/ul?ll=${encodeURIComponent(destination)}&navigate=yes`} rel="noreferrer" target="_blank">Abrir no Waze</a></div> }
function money(cents: number) { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100) }
function centsToInput(cents: number) { return (cents / 100).toFixed(2).replace('.', ',') }
function moneyInputToCents(value: string) { const parsed = Number(value.trim().replace(/\./g, '').replace(',', '.')); return Number.isFinite(parsed) && parsed >= 0 ? Math.round(parsed * 100) : null }
function distanceLabel(meters: number | null) { return meters === null ? 'Aguardando rota' : `${(meters / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} km` }
function durationLabel(seconds: number) { return `${Math.max(1, Math.round(seconds / 60))} min` }
function statusLabel(status: string) { return ({ quoted: 'Aguardando preparo', out_for_delivery: 'Em entrega', address_pending: 'Endereço pendente' } as Record<string, string>)[status] ?? status }
function addressLabel(address: Record<string, unknown> | null) { if (!address) return 'Endereço pendente'; return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ') || 'Localização compartilhada' }
