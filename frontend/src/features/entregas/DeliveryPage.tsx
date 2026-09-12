import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { Icon } from '../../components/ui/Icon'
import { Modal } from '../../components/ui/Modal'
import {
  getDeliverySettings,
  getDeliveryTasks,
  advanceOrderFulfillment,
  overrideDeliveryFee,
  recalculateDeliveryRoute,
  setDeliveryCoordinates,
  selectOrderDeliveryAddress,
  updateDeliveryAddress,
  updateDeliverySettings,
  type UpdateDeliveryAddressPayload,
} from '../../services/crm.service'
import {
  loadGoogleMaps,
  loadGooglePlaces,
  type GoogleAutocompleteSessionToken,
  type GoogleAutocompleteSuggestion,
  type GoogleLatLng,
  type GoogleMap,
  type GoogleMarker,
  type GoogleMapsApi,
  type GooglePolyline,
  type GoogleTextSearchPlace,
} from '../../services/googleMapsLoader'
import type { DeliveryDistanceBand, DeliveryMapTask, DeliverySettings } from '../../types/crm'
import './DeliveryPage.css'

const mapsBrowserKey = import.meta.env.VITE_DELIVERY_GOOGLE_BROWSER_API_KEY as string | undefined

export function DeliveryPage({
  canManageDelivery,
  onOrderChanged,
}: {
  canManageDelivery: boolean
  onOrderChanged: () => Promise<void>
}) {
  const [tasks, setTasks] = useState<DeliveryMapTask[]>([])
  const [settings, setSettings] = useState<DeliverySettings | null>(null)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [pendingAction, setPendingAction] = useState<'start-delivery' | 'delivered' | null>(null)
  const [sendCustomerNotification, setSendCustomerNotification] = useState(false)
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

  function openFulfillmentAction(action: 'start-delivery' | 'delivered') {
    setPendingAction(action)
    setSendCustomerNotification(action === 'start-delivery')
  }

  async function confirmFulfillmentAction() {
    if (!selected || !pendingAction) return
    const action = pendingAction
    setSaving(true)
    try {
      const response = await advanceOrderFulfillment(selected.id, action, sendCustomerNotification)
      await Promise.all([refresh(), onOrderChanged()])
      setPendingAction(null)
      setMessage(response.warning ?? (action === 'start-delivery' ? 'Saída para entrega registrada.' : 'Entrega registrada como concluída.'))
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Não foi possível atualizar o status da entrega.')
    } finally { setSaving(false) }
  }

  async function useSearchedDestination(destination: GoogleLatLng): Promise<boolean> {
    if (!selected) return false

    const hasManualFeeOverride = selected.calculated_fee_cents !== null && selected.final_fee_cents !== selected.calculated_fee_cents
    if (hasManualFeeOverride && !window.confirm('Esta entrega possui uma taxa ajustada manualmente. Deseja recalcular a rota mantendo o ajuste atual?')) {
      return false
    }

    const previousFeeCents = selected.final_fee_cents
    setSaving(true)
    try {
      await setDeliveryCoordinates(selected.id, { latitude: destination.lat, longitude: destination.lng })
      if (hasManualFeeOverride) {
        await overrideDeliveryFee(selected.id, previousFeeCents, 'Ajuste manual preservado após atualização do destino.')
      }
      await refresh()
      setMessage('Destino atualizado e rota recalculada.')
      return true
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Não foi possível atualizar o destino desta entrega.')
      return false
    } finally {
      setSaving(false)
    }
  }

  return <PageContainer>
    <PageHeader description="Acompanhe entregas, revise localizações e calcule taxas a partir da rota registrada." title="Entregas" />
    <div className="delivery-command-center">
      {message ? <p className="delivery-feedback" role="status">{message}</p> : null}
      <section className="delivery-map-panel" aria-label="Mapa operacional de entregas">
        <DeliveryMap isSaving={saving} onUseDestination={useSearchedDestination} origin={validOrigin(settings?.provider_options?.origin)} selected={selected} tasks={tasks} />
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
        {selected ? <><dl className="delivery-details"><div><dt>Cliente</dt><dd>{selected.recipient ?? 'Não informado'}</dd></div><div><dt>Endereço</dt><dd>{addressLabel(selected.address)}</dd></div><div><dt>Rota</dt><dd>{distanceLabel(selected.distance_meters)}{selected.duration_seconds ? ` · ${durationLabel(selected.duration_seconds)}` : ''}</dd></div><div><dt>Taxa calculada</dt><dd>{selected.calculated_fee_cents === null ? 'Aguardando cálculo' : money(selected.calculated_fee_cents)}</dd></div><div><dt>Taxa final</dt><dd>{money(selected.final_fee_cents)}</dd></div></dl><div className="delivery-actions"><Button disabled={saving || !selected.address} icon="refresh" onClick={() => void recalculateSelected()}>Recalcular rota</Button><Button disabled={saving || selected.quote_id === null} icon="edit" variant="secondary" onClick={() => void adjustSelectedFee()}>Ajustar taxa</Button><ExternalRouteLinks task={selected} /></div><DeliveryAddressEditor canManage={canManageDelivery} key={selected.id} onSaved={async (feedback) => { await refresh(); setMessage(feedback) }} task={selected} /></> : <div className="delivery-detail-panel__empty"><strong>Escolha uma entrega na fila</strong><span>Os dados da rota, da taxa e do cliente aparecerão aqui para conferência.</span></div>}
      </section>
      {selected?.status === 'quoted' ? <div className="delivery-actions"><Button disabled icon="check" title="Confirme a montagem do pedido na tela Pedidos antes da saída.">Aguardando montagem</Button></div> : null}
      {selected?.status === 'ready' ? <div className="delivery-actions"><Button disabled={saving || !canManageDelivery} icon="check" onClick={() => openFulfillmentAction('start-delivery')}>Saiu para entrega</Button></div> : null}
      {selected?.status === 'out_for_delivery' ? <div className="delivery-actions"><Button disabled={saving || !canManageDelivery} icon="check" onClick={() => openFulfillmentAction('delivered')}>Marcar como entregue</Button></div> : null}
      {settings ? <DeliverySettingsForm settings={settings} onSaved={async () => { await refresh(); setMessage('Configuração de entrega atualizada.') }} /> : null}
    </div>
      <Modal closeDisabled={saving} description={pendingAction === 'start-delivery' ? 'Confirme a saída operacional deste pedido.' : 'Confirme a conclusão operacional desta entrega.'} onClose={() => setPendingAction(null)} onPrimary={() => void confirmFulfillmentAction()} open={pendingAction !== null} primaryDisabled={saving} primaryLabel={pendingAction === 'start-delivery' ? 'Confirmar saída' : 'Confirmar entrega'} title={pendingAction === 'start-delivery' ? 'Saiu para entrega' : 'Pedido entregue'}>
        <p><strong>{selected?.order_code}</strong><br />Cliente: {selected?.recipient ?? 'Não informado'}<br />Destino: {addressLabel(selected?.address ?? null)}</p>
        <label className="delivery-notification-choice"><input checked={sendCustomerNotification} onChange={(event) => setSendCustomerNotification(event.target.checked)} type="checkbox" /> {pendingAction === 'start-delivery' ? 'Enviar aviso ao cliente pelo WhatsApp' : 'Enviar mensagem de agradecimento'}</label>
      </Modal>
  </PageContainer>
}

function DeliveryAddressEditor({
  canManage,
  onSaved,
  task,
}: {
  canManage: boolean
  onSaved: (feedback: string) => Promise<void>
  task: DeliveryMapTask
}) {
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [form, setForm] = useState<UpdateDeliveryAddressPayload>(() => addressForm(task))
  const [savedAddressId, setSavedAddressId] = useState('')

  function update(field: keyof UpdateDeliveryAddressPayload, value: string | boolean) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const result = await updateDeliveryAddress(task.id, form)
      setEditing(false)
      await onSaved(result.warning ?? 'Endereço salvo e rota recalculada com sucesso.')
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Não foi possível salvar o endereço desta entrega.')
    } finally {
      setSaving(false)
    }
  }

  async function applySavedAddress() {
    if (!savedAddressId) return
    setSaving(true)
    setError(null)
    try {
      await selectOrderDeliveryAddress(task.id, savedAddressId)
      setEditing(false)
      await onSaved('Endereço salvo selecionado. Recalcule a rota antes do despacho.')
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Não foi possível selecionar este endereço.')
    } finally {
      setSaving(false)
    }
  }

  if (!canManage) {
    return <p className="delivery-address-readonly">Endereço disponível somente para leitura neste perfil.</p>
  }

  if (!editing) {
    return <div className="delivery-address-editor__closed"><span>Corrija os campos do endereço e recalcule a rota sem sair da entrega.</span><Button icon="edit" onClick={() => setEditing(true)} size="sm" variant="secondary">Adicionar ou editar endereço</Button></div>
  }

  return (
    <form className="delivery-address-editor" onSubmit={(event) => void submit(event)}>
      <div className="delivery-address-editor__heading"><div><strong>Endereço de entrega</strong><span>A correção fica salva antes da tentativa de geocodificação.</span></div><Button disabled={saving} onClick={() => setEditing(false)} size="sm" variant="ghost">Cancelar</Button></div>
      {(task.saved_addresses?.length ?? 0) > 0 ? <div className="delivery-address-editor__saved"><label>Usar endereço salvo<select onChange={(event) => setSavedAddressId(event.target.value)} value={savedAddressId}><option value="">Selecione...</option>{(task.saved_addresses ?? []).map((address) => <option key={address.id} value={address.id}>{address.label}{address.is_default ? ' (padrão)' : ''} — {address.street}, {address.number}</option>)}</select></label><Button disabled={!savedAddressId || saving} onClick={() => void applySavedAddress()} size="sm" variant="secondary">Usar neste pedido</Button></div> : null}
      <div className="delivery-address-editor__fields">
        <label>CEP<input autoComplete="postal-code" onChange={(event) => update('postal_code', event.target.value)} value={form.postal_code ?? ''} /></label>
        <label className="delivery-address-editor__street">Rua<input required onChange={(event) => update('street', event.target.value)} value={form.street ?? ''} /></label>
        <label>Número<input required onChange={(event) => update('number', event.target.value)} value={form.number ?? ''} /></label>
        <label>Complemento<input onChange={(event) => update('complement', event.target.value)} value={form.complement ?? ''} /></label>
        <label>Bairro<input required onChange={(event) => update('neighborhood', event.target.value)} value={form.neighborhood ?? ''} /></label>
        <label>Cidade<input required onChange={(event) => update('city', event.target.value)} value={form.city ?? ''} /></label>
        <label>Estado<input maxLength={2} required onChange={(event) => update('state', event.target.value.toUpperCase())} value={form.state ?? ''} /></label>
        <label className="delivery-address-editor__reference">Referência<input onChange={(event) => update('reference', event.target.value)} value={form.reference ?? ''} /></label>
        {task.customer_id ? <label className="delivery-address-editor__save"><input checked={form.save_to_customer ?? false} onChange={(event) => update('save_to_customer', event.target.checked)} type="checkbox" /> Salvar este endereço no cadastro do cliente</label> : null}
        {task.customer_id && form.save_to_customer ? <label>Nome do endereço<input onChange={(event) => update('label', event.target.value)} placeholder="Casa, Trabalho..." value={form.label ?? ''} /></label> : null}
      </div>
      {error ? <p className="delivery-form-error" role="alert">{error}</p> : null}
      <div className="delivery-address-editor__footer"><Button disabled={saving} icon="check" type="submit">{saving ? 'Salvando e recalculando...' : 'Salvar endereço e recalcular'}</Button></div>
    </form>
  )
}

function addressForm(task: DeliveryMapTask): UpdateDeliveryAddressPayload {
  return {
    postal_code: task.address?.postal_code ?? '',
    street: task.address?.street ?? '',
    number: task.address?.number ?? '',
    complement: task.address?.complement ?? '',
    neighborhood: task.address?.neighborhood ?? '',
    city: task.address?.city ?? '',
    state: task.address?.state ?? '',
    reference: task.address?.reference ?? '',
    label: task.address?.label ?? 'Casa',
    save_to_customer: false,
  }
}

type SearchedPlace = {
  address: string
  coordinates: GoogleLatLng
  name: string
}

type DeliveryOrigin = GoogleLatLng | null

function validOrigin(origin: NonNullable<DeliverySettings['provider_options']>['origin'] | undefined): DeliveryOrigin {
  if (!origin || !Number.isFinite(origin.latitude) || !Number.isFinite(origin.longitude)
    || origin.latitude < -90 || origin.latitude > 90 || origin.longitude < -180 || origin.longitude > 180) {
    return null
  }

  return { lat: origin.latitude, lng: origin.longitude }
}

function toSearchedPlace(place: GoogleTextSearchPlace): SearchedPlace | null {
  if (!place.location) return null
  const displayName = typeof place.displayName === 'string' ? place.displayName : place.displayName?.text
  const name = displayName?.trim() || 'Local pesquisado'

  return {
    address: place.formattedAddress?.trim() || name,
    coordinates: place.location,
    name,
  }
}

function DeliveryMap({
  isSaving,
  onUseDestination,
  origin,
  selected,
  tasks,
}: {
  isSaving: boolean
  onUseDestination: (coordinates: GoogleLatLng) => Promise<boolean>
  origin: DeliveryOrigin
  selected: DeliveryMapTask | null
  tasks: DeliveryMapTask[]
}) {
  const container = useRef<HTMLDivElement | null>(null)
  const map = useRef<GoogleMap | null>(null)
  const markers = useRef<GoogleMarker[]>([])
  const routeLine = useRef<GooglePolyline | null>(null)
  const searchMarker = useRef<GoogleMarker | null>(null)
  const centeredOriginKey = useRef<string | null>(null)
  const [maps, setMaps] = useState<GoogleMapsApi | null>(window.google?.maps ?? null)
  const [mapInstance, setMapInstance] = useState<GoogleMap | null>(null)
  const [mapUnavailable, setMapUnavailable] = useState(false)
  const [searchedPlace, setSearchedPlace] = useState<SearchedPlace | null>(null)

  useEffect(() => {
    if (!mapsBrowserKey || maps) return
    let cancelled = false
    void loadGoogleMaps(mapsBrowserKey)
      .then((api) => {
        if (!cancelled) setMaps(api)
      })
      .catch((error: unknown) => {
        console.error('Google Maps não pôde ser carregado para Entregas.', error)
        if (!cancelled) setMapUnavailable(true)
      })

    return () => { cancelled = true }
  }, [maps])

  useEffect(() => {
    if (!maps || !container.current) return
    const nextMap = map.current ?? new maps.Map(container.current, { center: origin ?? { lat: -23.5505, lng: -46.6333 }, disableDefaultUI: true, zoom: origin ? 15 : 12 })
    if (!map.current) setMapInstance(nextMap)
    map.current = nextMap
    markers.current.forEach((marker) => marker.setMap(null)); markers.current = []
    routeLine.current?.setMap(null); routeLine.current = null
    const bounds = new maps.LatLngBounds()
    const visible = tasks.filter((task) => task.destination)
    visible.forEach((task) => { const marker = new maps.Marker({ map: nextMap, position: { lat: task.destination!.latitude, lng: task.destination!.longitude }, title: task.order_code }); markers.current.push(marker); bounds.extend(marker.getPosition()) })
    const restaurantOrigin = origin ?? (selected?.origin ? { lat: selected.origin.latitude, lng: selected.origin.longitude } : null)
    if (restaurantOrigin) {
      const originMarker = new maps.Marker({
        map: nextMap,
        position: restaurantOrigin,
        title: 'Restaurante',
        icon: {
          path: 'M 0,-10 C -5,-10 -8,-6 -8,-1 C -8,4 0,11 0,11 C 0,11 8,4 8,-1 C 8,-6 5,-10 0,-10 Z',
          fillColor: '#47b881',
          fillOpacity: 1,
          strokeColor: '#10211b',
          strokeWeight: 1,
          scale: 1,
        },
      } as unknown as { map: GoogleMap; position: GoogleLatLng; title: string });
      markers.current.push(originMarker)
      bounds.extend(restaurantOrigin)
    }
    if (selected?.encoded_polyline && maps.geometry?.encoding) { const path = maps.geometry.encoding.decodePath(selected.encoded_polyline); routeLine.current = new maps.Polyline({ map: nextMap, path, strokeColor: '#f0a43a', strokeOpacity: .9, strokeWeight: 5 }); path.forEach((point) => bounds.extend(point)) }
    if (visible.length) nextMap.fitBounds(bounds, 48)
  }, [maps, origin, selected, tasks])

  useEffect(() => {
    if (!mapInstance || !origin) return

    const originKey = `${origin.lat}:${origin.lng}`
    if (centeredOriginKey.current === originKey) return
    centeredOriginKey.current = originKey

    if (!selected && !searchedPlace) {
      mapInstance.setCenter(origin)
      mapInstance.setZoom(15)
    }
  }, [mapInstance, origin, searchedPlace, selected])

  useEffect(() => {
    if (!maps || !mapInstance) return
    searchMarker.current?.setMap(null)
    searchMarker.current = null
    if (!searchedPlace) return

    searchMarker.current = new maps.Marker({ map: mapInstance, position: searchedPlace.coordinates, title: 'Local pesquisado' })
    mapInstance.setCenter(searchedPlace.coordinates)
    mapInstance.setZoom(16)
  }, [mapInstance, maps, searchedPlace])

  if (!mapsBrowserKey) return <div className="delivery-map-fallback"><strong>Mapa aguardando configuração</strong><span>Cadastre a chave pública restrita do Google Maps para visualizar as rotas.</span></div>
  if (mapUnavailable) return <div className="delivery-map-fallback" role="alert"><strong>Mapa indisponível no momento</strong><span>A fila de entregas continua disponível. Confira a configuração do navegador e tente novamente.</span></div>

  return <div className="delivery-map-canvas">
    <div className="delivery-google-map" ref={container} />
    <DeliveryPlaceSearch
      isSaving={isSaving}
      map={mapInstance}
      maps={maps}
      onClearPlace={() => setSearchedPlace(null)}
      onSelectPlace={setSearchedPlace}
      onUseDestination={onUseDestination}
      searchedPlace={searchedPlace}
      selected={selected}
    />
  </div>
}

function DeliveryPlaceSearch({
  isSaving,
  map,
  maps,
  onClearPlace,
  onSelectPlace,
  onUseDestination,
  searchedPlace,
  selected,
}: {
  isSaving: boolean
  map: GoogleMap | null
  maps: GoogleMapsApi | null
  onClearPlace: () => void
  onSelectPlace: (place: SearchedPlace) => void
  onUseDestination: (coordinates: GoogleLatLng) => Promise<boolean>
  searchedPlace: SearchedPlace | null
  selected: DeliveryMapTask | null
}) {
  const searchContainer = useRef<HTMLDivElement | null>(null)
  const autocompleteToken = useRef<GoogleAutocompleteSessionToken | null>(null)
  const autocompleteRequest = useRef(0)
  const skipAutocompleteOnce = useRef(false)
  const mapRef = useRef(map)
  const [query, setQuery] = useState('')
  const [searchState, setSearchState] = useState<'idle' | 'searching' | 'no_result' | 'error'>('idle')
  const [suggestions, setSuggestions] = useState<GoogleAutocompleteSuggestion[]>([])
  const [textResults, setTextResults] = useState<SearchedPlace[]>([])
  const isPanelOpen = suggestions.length > 0 || textResults.length > 0 || searchState !== 'idle'

  useEffect(() => {
    mapRef.current = map
  }, [map])

  const selectPlace = useCallback((place: SearchedPlace) => {
    skipAutocompleteOnce.current = true
    onSelectPlace(place)
    setQuery(place.address)
    setTextResults([])
    setSearchState('idle')
  }, [onSelectPlace])

  const closeSearchPanel = useCallback(() => {
    setSuggestions([])
    setTextResults([])
    setSearchState('idle')
  }, [])

  const clearSearch = useCallback(() => {
    onClearPlace()
    autocompleteToken.current = null
    setQuery('')
    closeSearchPanel()
  }, [closeSearchPanel, onClearPlace])

  useEffect(() => {
    if (!isPanelOpen) return

    const handlePointerDown = (event: PointerEvent) => {
      if (!searchContainer.current?.contains(event.target as Node)) closeSearchPanel()
    }
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape' || !isPanelOpen) return
      event.preventDefault()
      event.stopPropagation()
      closeSearchPanel()
      if (document.activeElement instanceof HTMLElement && searchContainer.current?.contains(document.activeElement)) {
        document.activeElement.blur()
      }
    }

    document.addEventListener('pointerdown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('pointerdown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [closeSearchPanel, isPanelOpen])

  const searchByText = useCallback(async (rawQuery = query) => {
    const textQuery = rawQuery.trim()
    if (!maps || textQuery.length < 2) return

    setSearchState('searching')
    setTextResults([])
    try {
      const { Place } = await loadGooglePlaces(maps)
      const { places } = await Place.searchByText({
        fields: ['id', 'displayName', 'formattedAddress', 'location'],
        language: 'pt-BR',
        locationBias: mapRef.current?.getCenter() ?? undefined,
        maxResultCount: 5,
        region: 'BR',
        textQuery,
      })
      const results = places.map(toSearchedPlace).filter((place): place is SearchedPlace => place !== null)
      setTextResults(results)
      setSearchState(results.length ? 'idle' : 'no_result')
    } catch (error: unknown) {
      console.error('Google Places Text Search não pôde ser concluído para Entregas.', error)
      setSearchState('error')
    }
  }, [maps, query])

  const selectSuggestion = useCallback(async (suggestion: GoogleAutocompleteSuggestion) => {
    const prediction = suggestion.placePrediction
    if (!prediction) return

    setSearchState('searching')
    setSuggestions([])
    try {
      const place = prediction.toPlace()
      await place.fetchFields({ fields: ['displayName', 'formattedAddress', 'location'] })
      const result = toSearchedPlace(place)
      if (!result) {
        await searchByText(prediction.text.text)
        return
      }

      selectPlace(result)
      autocompleteToken.current = null
    } catch (error: unknown) {
      console.error('Google Places Autocomplete não pôde ser concluído para Entregas.', error)
      setSearchState('error')
    }
  }, [searchByText, selectPlace])

  useEffect(() => {
    if (skipAutocompleteOnce.current) {
      skipAutocompleteOnce.current = false
      return
    }

    if (!maps || query.trim().length < 2) {
      return
    }
    let cancelled = false
    const requestId = ++autocompleteRequest.current
    const timeout = window.setTimeout(() => {
      void loadGooglePlaces(maps)
        .then(({ AutocompleteSessionToken, AutocompleteSuggestion }) => {
          autocompleteToken.current ??= new AutocompleteSessionToken()
          return AutocompleteSuggestion.fetchAutocompleteSuggestions({
            includedRegionCodes: ['br'],
            input: query.trim(),
            locationBias: mapRef.current?.getCenter() ?? undefined,
            sessionToken: autocompleteToken.current,
          })
        })
        .then(({ suggestions: nextSuggestions }) => {
          if (cancelled || requestId !== autocompleteRequest.current) return
          setSuggestions(nextSuggestions.filter((suggestion) => suggestion.placePrediction))
        })
        .catch((error: unknown) => {
          console.error('Google Places Autocomplete não pôde ser carregado para Entregas.', error)
          if (!cancelled && requestId === autocompleteRequest.current) setSearchState('error')
        })
    }, 280)

    return () => {
      cancelled = true
      window.clearTimeout(timeout)
    }
  }, [maps, query])

  async function applySearchedDestination() {
    if (!searchedPlace) return
    const applied = await onUseDestination(searchedPlace.coordinates)
    if (applied) {
      onClearPlace()
      setSearchState('idle')
    }
  }

  return <div className="delivery-place-search" ref={searchContainer}>
    <div className="delivery-place-search__input">
      <Icon name="search" size={18} />
      <input
        aria-label="Buscar endereço ou lugar"
        disabled={!maps}
        onChange={(event) => {
          setQuery(event.target.value)
          if (!event.target.value.trim()) clearSearch()
          setTextResults([])
          setSearchState('idle')
        }}
        onKeyDown={(event) => {
          if (event.key === 'Enter') {
            event.preventDefault()
            void searchByText()
            return
          }
          if (event.key === 'Escape') {
            closeSearchPanel()
          }
        }}
        placeholder="Buscar endereço ou lugar..."
        type="search"
        value={query}
      />
      {(query || searchedPlace) ? <button aria-label="Limpar busca" className="delivery-place-search__clear" onClick={clearSearch} type="button"><Icon name="close" size={16} /></button> : null}
      <button
        aria-label="Buscar locais"
        className="delivery-place-search__submit"
        disabled={!maps || query.trim().length < 2 || searchState === 'searching'}
        onClick={() => void searchByText()}
        type="button"
      ><Icon name="search" size={17} /></button>
    </div>
    {searchState === 'searching' ? <small className="delivery-place-search__status">Buscando locais...</small> : null}
    {searchState === 'no_result' ? <small className="delivery-place-search__status">Nenhum local encontrado. Tente incluir o bairro ou a cidade.</small> : null}
    {searchState === 'error' ? <small className="delivery-place-search__status delivery-place-search__status--error">Não foi possível buscar locais agora. Tente novamente.</small> : null}
    {suggestions.length ? <div className="delivery-place-search__results" role="listbox" aria-label="Sugestões de locais">
      {suggestions.map((suggestion) => {
        const prediction = suggestion.placePrediction!
        return <button key={prediction.text.text} onClick={() => void selectSuggestion(suggestion)} role="option" type="button">
          <strong>{prediction.mainText?.text ?? prediction.text.text}</strong>
          {prediction.secondaryText?.text ? <span>{prediction.secondaryText.text}</span> : null}
        </button>
      })}
    </div> : null}
    {textResults.length ? <div className="delivery-place-search__results" role="listbox" aria-label="Locais encontrados">
      {textResults.map((place) => <button key={`${place.name}-${place.coordinates.lat}-${place.coordinates.lng}`} onClick={() => selectPlace(place)} role="option" type="button">
        <strong>{place.name}</strong><span>{place.address}</span>
      </button>)}
    </div> : null}
    {searchedPlace ? <div className="delivery-place-search__result">
      <div><strong>{searchedPlace.name}</strong><span>{searchedPlace.address}</span></div>
      {selected ? <Button disabled={isSaving} icon="check" onClick={() => void applySearchedDestination()} size="sm">Usar como destino desta entrega</Button> : <small>Selecione uma entrega na fila para usar este local como destino.</small>}
    </div> : null}
  </div>
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
    const invalidBand = bands.some((band) => !Number.isInteger(band.fee_cents) || band.fee_cents < 0)
    if (!Number.isFinite(Number(latitude)) || !Number.isFinite(Number(longitude)) || (mode === 'per_km' && rateCents === null) || (mode === 'distance_bands' && invalidBand)) { setError('Informe origem e valores válidos para a entrega.'); return }
    setSaving(true)
    try { await updateDeliverySettings({ maps_provider: settings.maps_provider === 'none' ? 'fake' : settings.maps_provider as 'google' | 'fake', pricing_mode: mode, rate_per_km_cents: rateCents ?? undefined, origin: { address, latitude: Number(latitude), longitude: Number(longitude) }, distance_bands: mode === 'distance_bands' ? bands : [] }); setError(null); await onSaved() }
    catch (caught) { setError(caught instanceof Error ? caught.message : 'Não foi possível salvar a configuração.') }
    finally { setSaving(false) }
  }

  return <Card className="delivery-settings-panel">
    <SectionTitle eyebrow="Configuração da equipe" title="Regras de entrega" />
    <form className="delivery-settings-form" onSubmit={submit}>
      <fieldset className="delivery-pricing-mode">
        <legend>Como calcular a taxa</legend>
        <div className="delivery-pricing-segmented">
          <label>
            <input checked={mode === 'per_km'} name="delivery-pricing" onChange={() => setMode('per_km')} type="radio" />
            <span>Por quilômetro</span>
          </label>
          <label>
            <input checked={mode === 'distance_bands'} name="delivery-pricing" onChange={() => setMode('distance_bands')} type="radio" />
            <span>Por faixas de distância</span>
          </label>
        </div>
      </fieldset>
      {mode === 'per_km' ? <label className="delivery-settings-form__field">Valor por km<input inputMode="decimal" onChange={(event) => setRate(event.target.value)} placeholder="0,00" value={rate} /></label> : <DistanceBands bands={bands} onChange={setBands} />}
      <div className="delivery-settings-form__origin">
        <div className="delivery-settings-form__origin-heading"><strong>Origem do restaurante</strong><span>Usada para calcular a rota e a taxa de cada entrega.</span></div>
        <label className="delivery-settings-form__field delivery-settings-form__field--address">Endereço<input onChange={(event) => setAddress(event.target.value)} placeholder="Rua, número e bairro" value={address} /></label>
        <label className="delivery-settings-form__field">Latitude<input inputMode="decimal" onChange={(event) => setLatitude(event.target.value)} placeholder="-23,000000" value={latitude} /></label>
        <label className="delivery-settings-form__field">Longitude<input inputMode="decimal" onChange={(event) => setLongitude(event.target.value)} placeholder="-46,000000" value={longitude} /></label>
      </div>
      {error ? <p className="delivery-form-error">{error}</p> : null}
      <div className="delivery-settings-form__footer"><Button disabled={saving} icon="check" size="sm" type="submit">Salvar regras de entrega</Button></div>
    </form>
  </Card>
}

function DistanceBands({ bands, onChange }: { bands: DeliveryDistanceBand[]; onChange: (bands: DeliveryDistanceBand[]) => void }) {
  const [feeInputs, setFeeInputs] = useState(() => bands.map((band) => centsToInput(band.fee_cents)))

  function updateDistance(index: number, value: string) {
    const next = [...bands]
    next[index] = { ...next[index], up_to_meters: Math.round(Number(value.replace(',', '.')) * 1000) }
    onChange(next)
  }

  function updateFee(index: number, value: string) {
    setFeeInputs((current) => current.map((input, row) => row === index ? value : input))
    const next = [...bands]
    next[index] = { ...next[index], fee_cents: moneyInputToCents(value) ?? -1 }
    onChange(next)
  }

  function normalizeFee(index: number) {
    const cents = moneyInputToCents(feeInputs[index] ?? '')
    if (cents === null) return
    setFeeInputs((current) => current.map((input, row) => row === index ? centsToInput(cents) : input))
  }

  function remove(index: number) {
    setFeeInputs((current) => current.filter((_, row) => row !== index))
    onChange(bands.filter((_, row) => row !== index))
  }

  function add() {
    setFeeInputs((current) => [...current, '0,00'])
    onChange([...bands, { up_to_meters: 1000, fee_cents: 0 }])
  }

  return <div className="delivery-bands"><strong>Faixas</strong>{bands.map((band, index) => <div className="delivery-band" key={index}><label>Até (km)<input inputMode="decimal" onChange={(event) => updateDistance(index, event.target.value)} value={(band.up_to_meters / 1000).toString().replace('.', ',')} /></label><label>Taxa<input inputMode="decimal" onBlur={() => normalizeFee(index)} onChange={(event) => updateFee(index, event.target.value)} placeholder="0,00" value={feeInputs[index] ?? ''} /></label><Button aria-label="Remover faixa" className="delivery-band__remove" onClick={() => remove(index)} variant="ghost">Remover</Button></div>)}<Button icon="plus" onClick={add} size="sm" variant="secondary">Adicionar faixa</Button></div>
}

function ExternalRouteLinks({ task }: { task: DeliveryMapTask }) { if (!task.destination) return null; const destination = `${task.destination.latitude},${task.destination.longitude}`; return <div className="delivery-external-links"><a href={`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`} rel="noreferrer" target="_blank">Abrir no Maps</a><a href={`https://waze.com/ul?ll=${encodeURIComponent(destination)}&navigate=yes`} rel="noreferrer" target="_blank">Abrir no Waze</a></div> }
function money(cents: number) { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100) }
function centsToInput(cents: number) { return (cents / 100).toFixed(2).replace('.', ',') }
function moneyInputToCents(value: string) {
  const normalized = value.trim().replace(/\s/g, '')
  if (!/^\d+(?:[.,]\d{0,2})?$/.test(normalized)) return null
  const parsed = Number(normalized.replace(',', '.'))
  return Number.isFinite(parsed) && parsed >= 0 ? Math.round(parsed * 100) : null
}
function distanceLabel(meters: number | null) { return meters === null ? 'Aguardando rota' : `${(meters / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} km` }
function durationLabel(seconds: number) { return `${Math.max(1, Math.round(seconds / 60))} min` }
function statusLabel(status: string) { return ({ quoted: 'Aguardando preparo', ready: 'Pronto para sair', out_for_delivery: 'Saiu para entrega', address_pending: 'Endereço pendente' } as Record<string, string>)[status] ?? status }
function addressLabel(address: DeliveryMapTask['address']) { if (!address) return 'Endereço pendente'; return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ') || 'Localização compartilhada' }
