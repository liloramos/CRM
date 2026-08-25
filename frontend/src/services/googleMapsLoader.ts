export type GoogleLatLng = { lat: number; lng: number }

export type GoogleBounds = {
  extend: (position: GoogleLatLng) => void
}

export type GoogleMap = {
  fitBounds: (bounds: GoogleBounds, padding?: number) => void
  getCenter: () => GoogleLatLng | null
  setCenter: (position: GoogleLatLng) => void
  setZoom: (zoom: number) => void
}

export type GoogleMarker = {
  getPosition: () => GoogleLatLng
  setMap: (map: GoogleMap | null) => void
}

export type GooglePolyline = {
  setMap: (map: GoogleMap | null) => void
}

export type GoogleTextSearchPlace = {
  displayName?: string | { text?: string }
  formattedAddress?: string
  id?: string
  location?: GoogleLatLng
}

export type GoogleTextSearchRequest = {
  fields: Array<'id' | 'displayName' | 'formattedAddress' | 'location'>
  language?: string
  locationBias?: GoogleLatLng
  maxResultCount?: number
  region?: string
  textQuery: string
}

export type GoogleAutocompleteSessionToken = Record<string, never>

export type GoogleAutocompletePlace = GoogleTextSearchPlace & {
  fetchFields: (request: { fields: Array<'displayName' | 'formattedAddress' | 'location'> }) => Promise<void>
}

export type GoogleAutocompleteSuggestion = {
  placePrediction?: {
    mainText?: { text: string }
    secondaryText?: { text: string }
    text: { text: string }
    toPlace: () => GoogleAutocompletePlace
  }
}

export type GooglePlacesLibrary = {
  AutocompleteSessionToken: new () => GoogleAutocompleteSessionToken
  AutocompleteSuggestion: {
    fetchAutocompleteSuggestions: (request: {
      includedRegionCodes?: string[]
      input: string
      locationBias?: GoogleLatLng
      sessionToken?: GoogleAutocompleteSessionToken
    }) => Promise<{ suggestions: GoogleAutocompleteSuggestion[] }>
  }
  Place: {
    searchByText: (request: GoogleTextSearchRequest) => Promise<{ places: GoogleTextSearchPlace[] }>
  }
}

export type GoogleMapsApi = {
  LatLngBounds: new () => GoogleBounds
  Map: new (element: HTMLElement, options: Record<string, unknown>) => GoogleMap
  Marker: new (options: { map: GoogleMap; position: GoogleLatLng; title: string }) => GoogleMarker
  Polyline: new (options: Record<string, unknown>) => GooglePolyline
  geometry?: { encoding?: { decodePath: (encoded: string) => GoogleLatLng[] } }
  importLibrary?: (library: 'places') => Promise<GooglePlacesLibrary>
}

declare global {
  interface Window {
    google?: { maps: GoogleMapsApi }
    __deliveryGoogleMapsReady?: () => void
  }
}

const scriptId = 'delivery-google-maps-javascript-api'
let loaderPromise: Promise<GoogleMapsApi> | null = null
let placesPromise: Promise<GooglePlacesLibrary> | null = null

export function loadGoogleMaps(browserKey: string): Promise<GoogleMapsApi> {
  if (window.google?.maps) {
    return Promise.resolve(window.google.maps)
  }

  if (loaderPromise) {
    return loaderPromise
  }

  loaderPromise = new Promise<GoogleMapsApi>((resolve, reject) => {
    const resolveWhenAvailable = () => {
      if (window.google?.maps) {
        resolve(window.google.maps)
        return
      }

      reject(new Error('Google Maps JavaScript não ficou disponível após o carregamento.'))
    }
    const rejectLoading = () => {
      loaderPromise = null
      reject(new Error('Não foi possível carregar o Google Maps JavaScript.'))
    }
    const existingScript = document.getElementById(scriptId)
      ?? document.querySelector<HTMLScriptElement>('script[src*="maps.googleapis.com/maps/api/js"]')

    if (existingScript instanceof HTMLScriptElement) {
      existingScript.id = scriptId
      existingScript.addEventListener('load', resolveWhenAvailable, { once: true })
      existingScript.addEventListener('error', rejectLoading, { once: true })

      if (existingScript.dataset.googleMapsLoadState === 'loaded') {
        resolveWhenAvailable()
      }

      return
    }

    const script = document.createElement('script')
    script.id = scriptId
    script.async = true
    script.defer = true
    script.dataset.googleMapsLoadState = 'loading'
    window.__deliveryGoogleMapsReady = () => {
      script.dataset.googleMapsLoadState = 'loaded'
      resolveWhenAvailable()
      delete window.__deliveryGoogleMapsReady
    }
    script.addEventListener('error', rejectLoading, { once: true })
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(browserKey)}&libraries=geometry&loading=async&callback=__deliveryGoogleMapsReady`
    document.head.appendChild(script)
  })

  return loaderPromise
}

export function loadGooglePlaces(maps: GoogleMapsApi): Promise<GooglePlacesLibrary> {
  if (!maps.importLibrary) {
    return Promise.reject(new Error('A biblioteca Places não está disponível nesta versão do Google Maps.'))
  }

  placesPromise ??= maps.importLibrary('places')

  return placesPromise
}
