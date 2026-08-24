import { useCallback, useEffect, useMemo, useRef, useState, type ChangeEvent } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { Modal } from '../../components/ui/Modal'
import { SelectField } from '../../components/ui/SelectField'
import { EmptyState, ErrorState } from '../../components/ui/States'
import {
  ApiError,
  type CounterProductCategorySlug,
  clearComponentAvailability,
  clearDailyMenuAdjustment,
  createCounterProduct,
  createMenuComponent,
  deleteWeeklyMenuItem,
  getAdminDailyMenuAdjustments,
  getAdminMenuComponents,
  getAdminMenuProducts,
  getAdminWeeklyMenu,
  getDailyStructuredMenu,
  setComponentAvailability,
  removeMenuProductImage,
  updateMenuComponent,
  updateMenuProduct,
  updateProductComponentOption,
  uploadMenuProductImage,
  updateWeeklyMenuItem,
  upsertDailyMenuAdjustment,
  upsertWeeklyMenuComponent,
} from '../../services/crm.service'
import type {
  AdminDailyMenuAdjustment,
  AdminMenuComponent,
  AdminMenuProductsResponse,
  AdminWeeklyMenuItem,
  AdminWeeklyMenuResponse,
  AppModal,
  AuthUser,
  DailyMenuAdjustmentAction,
  DailyMenuComponent,
  DailyMenuSectionKey,
  DailyStructuredMenu,
  EffectiveAvailability,
  EffectiveAvailabilityStatus,
  MenuComponentTypeKey,
  ProductServiceDayKey,
  StructuredComponentOption,
  StructuredMenuComponentSummary,
  StructuredMenuProduct,
  StructuredProductOption,
  StructuredProductOptionGroup,
  WeeklyMenuServiceDayKey,
} from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type MenuPageProps = {
  onOpenModal: (modal: AppModal) => void
  user: AuthUser | null
}

type MenuAdminTab = 'today' | 'products' | 'weekly' | 'rules'

type ModalState =
  | { type: 'product'; product: StructuredMenuProduct | null }
  | { type: 'component'; component: AdminMenuComponent | null }
  | { type: 'availability'; item: DailyMenuComponent; action: 'set' | 'clear' }
  | { type: 'daily-adjustment'; item: DailyMenuComponent | null; action: DailyMenuAdjustmentAction }
  | { type: 'daily-adjustment-clear'; adjustment: AdminDailyMenuAdjustment }
  | { type: 'weekly-item'; item: AdminWeeklyMenuItem | null }
  | { type: 'weekly-delete'; item: AdminWeeklyMenuItem }
  | { type: 'pending-configuration'; product: StructuredMenuProduct }
  | { type: 'component-days'; component: AdminMenuComponent }
  | null

type ProductFormState = {
  name: string
  description: string
  price: string
  is_active: boolean
  is_available_by_default: boolean
  display_order: string
  category_slug: CounterProductCategorySlug
  service_days: ProductServiceDayKey[]
  beef_rules: BeefRulesFormState | null
}

type BeefRulesFormState = {
  beef_only_enabled: boolean
  beef_only_final_price: string
  extra_beef_enabled: boolean
  extra_beef_price: string
  extra_beef_max_quantity: string
}

type ComponentFormState = {
  name: string
  component_type: MenuComponentTypeKey
  description: string
  is_active: boolean
  display_order: string
}

type AvailabilityFormState = {
  status: EffectiveAvailabilityStatus
  reason: string
  replacement_component_id: string
}

type DailyAdjustmentFormState = {
  component_id: string
  section: DailyMenuSectionKey
  action: DailyMenuAdjustmentAction
  display_order: string
  notes: string
  search: string
}

type WeeklyItemFormState = {
  component_id: string
  service_day: WeeklyMenuServiceDayKey
  section: DailyMenuSectionKey
  display_order: string
  is_active: boolean
  notes: string
}

type PendingConfigurationFormState = {
  resolution: 'offered' | 'not_offered'
  final_price: string
}

type ComponentDaysFormState = {
  service_days: WeeklyMenuServiceDayKey[]
  section: DailyMenuSectionKey
  display_order: string
}

type ComponentAdminFilter = 'all' | MenuComponentTypeKey | 'active' | 'inactive' | 'without_days'

type ProductAdminFilter = 'active' | 'inactive' | 'legacy' | 'all'

type ProductCardMode = 'daily' | 'admin' | 'rules'

const menuAdminTabKey = 'sol.menu.admin.activeTab.v1'

const sectionOrder: DailyMenuSectionKey[] = ['hot', 'salad', 'meat', 'extra']

const sectionLabels: Record<DailyMenuSectionKey, string> = {
  hot: 'Quentes',
  salad: 'Saladas',
  meat: 'Carnes',
  extra: 'Extras',
}

const serviceDayOrder: ProductServiceDayKey[] = [
  'monday',
  'tuesday',
  'wednesday',
  'thursday',
  'friday',
  'saturday',
  'sunday',
]

const weeklyDayOrder: WeeklyMenuServiceDayKey[] = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']

const serviceDayLabels: Record<ProductServiceDayKey, string> = {
  monday: 'Segunda',
  tuesday: 'Terca',
  wednesday: 'Quarta',
  thursday: 'Quinta',
  friday: 'Sexta',
  saturday: 'Sabado',
  sunday: 'Domingo',
}

const statusLabels: Record<EffectiveAvailabilityStatus, string> = {
  available: 'Disponivel',
  sold_out: 'Esgotado',
  unavailable: 'Indisponivel',
}

const componentTypeLabels: Record<MenuComponentTypeKey, string> = {
  addon: 'Adicional',
  base: 'Base',
  extra: 'Extra',
  hot: 'Quente',
  juice_flavor: 'Sabor de suco',
  meat: 'Carne',
  salad: 'Salada',
}

const componentTypes: MenuComponentTypeKey[] = ['base', 'hot', 'salad', 'meat', 'extra', 'addon', 'juice_flavor']

const counterProductCategoryOptions: Array<{ value: CounterProductCategorySlug; label: string }> = [
  { value: 'doces', label: 'Doces' },
  { value: 'geladinhos', label: 'Geladinhos' },
  { value: 'bebidas', label: 'Bebidas' },
  { value: 'sucos', label: 'Sucos' },
  { value: 'outros', label: 'Outros' },
]

const tabLabels: Record<MenuAdminTab, string> = {
  today: 'Hoje',
  products: 'Produtos e precos',
  weekly: 'Cardapio semanal',
  rules: 'Regras e combos',
}

export function MenuPage({ onOpenModal, user }: MenuPageProps) {
  const canManageMenu = user?.permissions.includes('menu.manage') ?? false
  const [activeTab, setActiveTab] = useState<MenuAdminTab>(() => initialTab())
  const [selectedDate, setSelectedDate] = useState(() => todayDateString())
  const [selectedWeeklyDay, setSelectedWeeklyDay] = useState<WeeklyMenuServiceDayKey>('monday')
  const [dailyMenu, setDailyMenu] = useState<DailyStructuredMenu | null>(null)
  const [adminProducts, setAdminProducts] = useState<AdminMenuProductsResponse | null>(null)
  const [components, setComponents] = useState<AdminMenuComponent[]>([])
  const [weeklyMenu, setWeeklyMenu] = useState<AdminWeeklyMenuResponse | null>(null)
  const [dayAdjustments, setDayAdjustments] = useState<AdminDailyMenuAdjustment[]>([])
  const [isDailyLoading, setIsDailyLoading] = useState(false)
  const [isAdminLoading, setIsAdminLoading] = useState(false)
  const [dailyError, setDailyError] = useState<string | null>(null)
  const [adminError, setAdminError] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)
  const [mutationError, setMutationError] = useState<string | null>(null)
  const [isMutating, setIsMutating] = useState(false)
  const [modal, setModal] = useState<ModalState>(null)
  const [productForm, setProductForm] = useState<ProductFormState | null>(null)
  const [productImageFile, setProductImageFile] = useState<File | null>(null)
  const [productImagePreview, setProductImagePreview] = useState<string | null>(null)
  const [removeProductImage, setRemoveProductImage] = useState(false)
  const [componentForm, setComponentForm] = useState<ComponentFormState | null>(null)
  const [availabilityForm, setAvailabilityForm] = useState<AvailabilityFormState>(emptyAvailabilityForm())
  const [dailyAdjustmentForm, setDailyAdjustmentForm] = useState<DailyAdjustmentFormState>(emptyDailyAdjustmentForm())
  const [weeklyItemForm, setWeeklyItemForm] = useState<WeeklyItemFormState>(emptyWeeklyItemForm())
  const [pendingConfigurationForm, setPendingConfigurationForm] = useState<PendingConfigurationFormState>({
    final_price: '',
    resolution: 'not_offered',
  })
  const [componentDaysForm, setComponentDaysForm] = useState<ComponentDaysFormState>(emptyComponentDaysForm())

  const loadDailyMenu = useCallback(async () => {
    setIsDailyLoading(true)
    setDailyError(null)

    try {
      setDailyMenu(await getDailyStructuredMenu(selectedDate))
    } catch (error) {
      setDailyError(friendlyError(error, 'Nao foi possivel carregar o cardapio do dia.'))
    } finally {
      setIsDailyLoading(false)
    }
  }, [selectedDate])

  const loadAdminData = useCallback(async () => {
    if (!canManageMenu) {
      setAdminProducts(null)
      setComponents([])
      setWeeklyMenu(null)
      setDayAdjustments([])
      setAdminError(null)
      return
    }

    setIsAdminLoading(true)
    setAdminError(null)

    try {
      const [productsResponse, componentsResponse, weeklyResponse, adjustmentsResponse] = await Promise.all([
        getAdminMenuProducts(selectedDate),
        getAdminMenuComponents(selectedDate),
        getAdminWeeklyMenu(),
        getAdminDailyMenuAdjustments(selectedDate),
      ])

      setAdminProducts(productsResponse)
      setComponents(componentsResponse.components)
      setWeeklyMenu(weeklyResponse)
      setDayAdjustments(adjustmentsResponse.adjustments)
    } catch (error) {
      setAdminError(friendlyError(error, 'Nao foi possivel carregar os dados administrativos do cardapio.'))
    } finally {
      setIsAdminLoading(false)
    }
  }, [canManageMenu, selectedDate])

  const reloadWorkspace = useCallback(async () => {
    await Promise.all([loadDailyMenu(), loadAdminData()])
  }, [loadAdminData, loadDailyMenu])

  useEffect(() => {
    window.localStorage.setItem(menuAdminTabKey, activeTab)
  }, [activeTab])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      void reloadWorkspace()
    }, 0)

    return () => window.clearTimeout(timeout)
  }, [reloadWorkspace])

  const productCategories = canManageMenu ? (adminProducts?.categories ?? []) : (dailyMenu?.catalog.categories ?? [])
  const rulesCategories = adminProducts?.categories ?? dailyMenu?.catalog.categories ?? []
  const rulesProducts = rulesCategories.flatMap((category) => category.products)
  const serviceDayLabel = dailyMenu?.service_day ? serviceDayLabels[dailyMenu.service_day] : 'Sem cardapio semanal'
  const formattedDate = formatDateLabel(dailyMenu?.date ?? selectedDate)
  const timezoneLabel = friendlyTimezoneLabel(dailyMenu?.timezone)

  function openProductModal(product: StructuredMenuProduct) {
    setMutationError(null)
    setProductForm({
      name: product.name,
      description: product.description ?? '',
      price: centsToInput(productPriceCents(product)),
      is_active: product.is_active,
      is_available_by_default: product.is_available_by_default,
      display_order: String(product.display_order),
      category_slug: counterCategorySlug(product.category?.slug),
      service_days: [...product.service_days],
      beef_rules: beefRulesFormFromProduct(product),
    })
    resetProductImage(product.image_url)
    setModal({ type: 'product', product })
  }

  function openCreateCounterProductModal() {
    setMutationError(null)
    setProductForm({
      name: '',
      description: '',
      price: '',
      is_active: true,
      is_available_by_default: true,
      display_order: '0',
      category_slug: 'doces',
      service_days: serviceDayOrder.filter((day) => day !== 'sunday'),
      beef_rules: null,
    })
    resetProductImage(null)
    setModal({ type: 'product', product: null })
  }

  function resetProductImage(imageUrl: string | null) {
    if (productImagePreview?.startsWith('blob:')) {
      URL.revokeObjectURL(productImagePreview)
    }

    setProductImageFile(null)
    setProductImagePreview(imageUrl)
    setRemoveProductImage(false)
  }

  function selectProductImage(file: File | null) {
    if (productImagePreview?.startsWith('blob:')) {
      URL.revokeObjectURL(productImagePreview)
    }

    setProductImageFile(file)
    setProductImagePreview(file ? URL.createObjectURL(file) : null)
    setRemoveProductImage(false)
  }

  function clearProductImage() {
    if (productImagePreview?.startsWith('blob:')) {
      URL.revokeObjectURL(productImagePreview)
    }

    setProductImageFile(null)
    setProductImagePreview(null)
    setRemoveProductImage(true)
  }

  function openComponentModal(component: AdminMenuComponent | null = null) {
    setMutationError(null)
    setComponentForm({
      name: component?.name ?? '',
      component_type: normalizeComponentType(component?.component_type ?? 'extra'),
      description: component?.description ?? '',
      is_active: component?.is_active ?? true,
      display_order: String(component?.display_order ?? 0),
    })
    setModal({ type: 'component', component })
  }

  function openAvailabilityModal(item: DailyMenuComponent, status: EffectiveAvailabilityStatus | 'clear') {
    setMutationError(null)
    setAvailabilityForm({
      status: status === 'clear' ? 'available' : status,
      reason: item.availability.reason ?? '',
      replacement_component_id: '',
    })
    setModal({ type: 'availability', item, action: status === 'clear' ? 'clear' : 'set' })
  }

  function openDailyAdjustmentModal(
    item: DailyMenuComponent | null,
    action: DailyMenuAdjustmentAction,
    defaults?: Partial<DailyAdjustmentFormState>,
  ) {
    setMutationError(null)
    setDailyAdjustmentForm({
      component_id: item ? String(item.component.id) : defaults?.component_id ?? '',
      section: item?.section ?? defaults?.section ?? 'meat',
      action,
      display_order: item ? String(item.display_order) : defaults?.display_order ?? '',
      notes: item?.notes ?? defaults?.notes ?? '',
      search: defaults?.search ?? '',
    })
    setModal({ type: 'daily-adjustment', item, action })
  }

  function openWeeklyItemModal(item: AdminWeeklyMenuItem | null, defaults?: Partial<WeeklyItemFormState>) {
    setMutationError(null)
    setWeeklyItemForm({
      component_id: item ? String(item.component.id) : (defaults?.component_id ?? ''),
      service_day: item?.service_day ?? defaults?.service_day ?? selectedWeeklyDay,
      section: item?.section ?? defaults?.section ?? 'meat',
      display_order: item ? String(item.display_order) : (defaults?.display_order ?? ''),
      is_active: item?.is_active ?? defaults?.is_active ?? true,
      notes: item?.notes ?? defaults?.notes ?? '',
    })
    setModal({ type: 'weekly-item', item })
  }

  function openPendingConfigurationModal(product: StructuredMenuProduct) {
    const pendingOption = pendingComponentOption(product)

    setMutationError(null)
    setPendingConfigurationForm({
      final_price: pendingOption?.final_price_cents ? centsToInput(pendingOption.final_price_cents) : '',
      resolution: 'not_offered',
    })
    setModal({ type: 'pending-configuration', product })
  }

  function openComponentDaysModal(component: AdminMenuComponent) {
    setMutationError(null)
    setComponentDaysForm({
      display_order: '',
      section: sectionForComponentType(component.component_type),
      service_days: component.weekly_menu_items.map((item) => item.service_day),
    })
    setModal({ type: 'component-days', component })
  }

  function openComponentDailyAdjustment(component: AdminMenuComponent) {
    openDailyAdjustmentModal(null, 'include', {
      component_id: String(component.id),
      search: componentDisplayName(component),
      section: sectionForComponentType(component.component_type),
    })
  }

  async function handleModalPrimary() {
    if (modal?.type === 'product') {
      await handleSaveProduct(modal.product)
      return
    }

    if (modal?.type === 'component') {
      await handleSaveComponent(modal.component)
      return
    }

    if (modal?.type === 'availability') {
      await handleSaveAvailability(modal)
      return
    }

    if (modal?.type === 'daily-adjustment') {
      await handleSaveDailyAdjustment()
      return
    }

    if (modal?.type === 'daily-adjustment-clear') {
      await handleClearDailyAdjustment(modal.adjustment)
      return
    }

    if (modal?.type === 'weekly-item') {
      await handleSaveWeeklyItem(modal.item)
      return
    }

    if (modal?.type === 'weekly-delete') {
      await handleDeleteWeeklyItem(modal.item)
    }

    if (modal?.type === 'pending-configuration') {
      await handleResolvePendingConfiguration(modal.product)
    }

    if (modal?.type === 'component-days') {
      await handleSaveComponentDays(modal.component)
    }
  }

  function beefRulesPayload(form: BeefRulesFormState) {
    const beefOnlyFinalPrice = form.beef_only_enabled ? parseCurrencyToCents(form.beef_only_final_price) : null
    const extraBeefPrice = form.extra_beef_enabled ? parseCurrencyToCents(form.extra_beef_price) : null
    const extraBeefMaxQuantity = form.extra_beef_enabled ? parseInteger(form.extra_beef_max_quantity) : null

    if (form.beef_only_enabled && beefOnlyFinalPrice === null) {
      setMutationError('Informe o preco final do modo somente bife.')
      return null
    }

    if (form.extra_beef_enabled && extraBeefPrice === null) {
      setMutationError('Informe o preco do bife adicional.')
      return null
    }

    if (form.extra_beef_enabled && (extraBeefMaxQuantity === null || extraBeefMaxQuantity < 1)) {
      setMutationError('Informe uma quantidade maxima valida para o bife adicional.')
      return null
    }

    return {
      beef_only: {
        enabled: form.beef_only_enabled,
        final_price_cents: beefOnlyFinalPrice,
      },
      extra_beef: {
        enabled: form.extra_beef_enabled,
        price_cents: extraBeefPrice,
        max_quantity: extraBeefMaxQuantity,
      },
    }
  }

  async function handleSaveProduct(product: StructuredMenuProduct | null) {
    if (!productForm) {
      return
    }

    const priceCents = parseCurrencyToCents(productForm.price)
    const displayOrder = parseInteger(productForm.display_order)

    if (priceCents === null) {
      setMutationError('Informe um preco valido em reais.')
      return
    }

    if (product && displayOrder === null) {
      setMutationError('Informe uma ordem valida.')
      return
    }

    const beefRules = productForm.beef_rules ? beefRulesPayload(productForm.beef_rules) : undefined

    if (beefRules === null) {
      return
    }

    await runMutation(async () => {
      const payload = {
        date: selectedDate,
        name: productForm.name.trim(),
        description: productForm.description.trim() || null,
        price_cents: priceCents,
        is_active: productForm.is_active,
        is_available_by_default: productForm.is_available_by_default,
        display_order: displayOrder ?? 0,
        service_days: productForm.service_days,
        ...(beefRules ? { beef_rules: beefRules } : {}),
      }

      const savedProduct = product
        ? await updateMenuProduct(product.id, {
          ...payload,
          ...(product.is_counter_product ? { category_slug: productForm.category_slug } : {}),
        })
        : await createCounterProduct({
          date: payload.date,
          name: payload.name,
          description: payload.description,
          price_cents: payload.price_cents,
          is_active: payload.is_active,
          is_available_by_default: payload.is_available_by_default,
          category_slug: productForm.category_slug,
          service_days: payload.service_days,
        })

      if (productImageFile) {
        await uploadMenuProductImage(savedProduct.id, productImageFile)
      } else if (product && removeProductImage && product.image_url) {
        await removeMenuProductImage(product.id)
      }
    }, product ? 'Produto atualizado com dados do backend.' : 'Produto de balcao criado.')
  }

  async function handleSaveComponent(component: AdminMenuComponent | null) {
    if (!componentForm) {
      return
    }

    const displayOrder = parseInteger(componentForm.display_order)

    if (displayOrder === null) {
      setMutationError('Informe uma ordem valida.')
      return
    }

    await runMutation(async () => {
      const payload = {
        name: componentForm.name.trim(),
        component_type: componentForm.component_type,
        description: componentForm.description.trim() || null,
        is_active: componentForm.is_active,
        display_order: displayOrder,
      }

      if (component) {
        await updateMenuComponent(component.id, payload)
      } else {
        await createMenuComponent(payload)
      }
    }, component ? 'Componente atualizado.' : 'Componente criado.')
  }

  async function handleSaveAvailability(state: Extract<ModalState, { type: 'availability' }>) {
    await runMutation(async () => {
      if (state.action === 'clear') {
        await clearComponentAvailability(state.item.component.id, selectedDate)
        return
      }

      await setComponentAvailability(state.item.component.id, {
        date: selectedDate,
        status: availabilityForm.status,
        reason: availabilityForm.reason.trim() || null,
        replacement_component_id: availabilityForm.replacement_component_id
          ? Number(availabilityForm.replacement_component_id)
          : null,
      })
    }, state.action === 'clear' ? 'Disponibilidade restaurada.' : 'Disponibilidade atualizada.')
  }

  async function handleSaveDailyAdjustment() {
    if (!dailyAdjustmentForm.component_id) {
      setMutationError('Selecione um componente.')
      return
    }

    const displayOrder = dailyAdjustmentForm.display_order ? parseInteger(dailyAdjustmentForm.display_order) : null

    if (dailyAdjustmentForm.display_order && displayOrder === null) {
      setMutationError('Informe uma ordem valida.')
      return
    }

    await runMutation(async () => {
      await upsertDailyMenuAdjustment(dailyAdjustmentForm.component_id, {
        date: selectedDate,
        section: dailyAdjustmentForm.section,
        action: dailyAdjustmentForm.action,
        display_order: displayOrder,
        notes: dailyAdjustmentForm.notes.trim() || null,
      })
    }, dailyAdjustmentForm.action === 'include' ? 'Item incluido somente nesta data.' : 'Item ocultado somente nesta data.')
  }

  async function handleClearDailyAdjustment(adjustment: AdminDailyMenuAdjustment) {
    await runMutation(async () => {
      await clearDailyMenuAdjustment(adjustment.component.id, selectedDate, adjustment.section)
    }, 'Ajuste da data removido.')
  }

  async function handleSaveWeeklyItem(item: AdminWeeklyMenuItem | null) {
    if (!weeklyItemForm.component_id) {
      setMutationError('Selecione um componente.')
      return
    }

    const displayOrder = weeklyItemForm.display_order ? parseInteger(weeklyItemForm.display_order) : null

    if (weeklyItemForm.display_order && displayOrder === null) {
      setMutationError('Informe uma ordem valida.')
      return
    }

    await runMutation(async () => {
      if (item) {
        await updateWeeklyMenuItem(item.id, {
          service_day: weeklyItemForm.service_day,
          section: weeklyItemForm.section,
          display_order: displayOrder ?? item.display_order,
          is_active: weeklyItemForm.is_active,
          notes: weeklyItemForm.notes.trim() || null,
        })
        return
      }

      await upsertWeeklyMenuComponent(weeklyItemForm.component_id, {
        service_day: weeklyItemForm.service_day,
        section: weeklyItemForm.section,
        display_order: displayOrder,
        is_active: weeklyItemForm.is_active,
        notes: weeklyItemForm.notes.trim() || null,
      })
    }, item ? 'Item semanal atualizado.' : 'Item semanal adicionado.')
  }

  async function handleDeleteWeeklyItem(item: AdminWeeklyMenuItem) {
    await runMutation(async () => {
      await deleteWeeklyMenuItem(item.id)
    }, 'Vinculo semanal removido.')
  }

  async function handleResolvePendingConfiguration(product: StructuredMenuProduct) {
    const pendingOption = pendingComponentOption(product)

    if (!pendingOption) {
      setMutationError('Nao ha configuracao pendente para este produto.')
      return
    }

    const finalPriceCents = pendingConfigurationForm.resolution === 'offered'
      ? parseCurrencyToCents(pendingConfigurationForm.final_price)
      : null

    if (pendingConfigurationForm.resolution === 'offered' && finalPriceCents === null) {
      setMutationError('Informe o preco final da variacao em reais.')
      return
    }

    if (
      pendingConfigurationForm.resolution === 'offered'
      && finalPriceCents !== null
      && finalPriceCents < productPriceCents(product)
    ) {
      setMutationError('O preco final da variacao nao pode ser menor que o preco base do produto.')
      return
    }

    await runMutation(async () => {
      await updateProductComponentOption(pendingOption.id, {
        date: selectedDate,
        resolution: pendingConfigurationForm.resolution,
        final_price_cents: finalPriceCents,
      })
    }, pendingConfigurationForm.resolution === 'offered'
      ? 'Variacao configurada com preco informado.'
      : 'Variacao marcada como nao oferecida.')
  }

  async function handleSaveComponentDays(component: AdminMenuComponent) {
    const displayOrder = componentDaysForm.display_order ? parseInteger(componentDaysForm.display_order) : null

    if (componentDaysForm.display_order && displayOrder === null) {
      setMutationError('Informe uma ordem valida.')
      return
    }

    const selectedDays = new Set(componentDaysForm.service_days)
    const existingItems = component.weekly_menu_items

    await runMutation(async () => {
      for (const item of existingItems) {
        if (!selectedDays.has(item.service_day)) {
          await deleteWeeklyMenuItem(item.id)
          continue
        }

        if (item.section !== componentDaysForm.section || (displayOrder !== null && item.display_order !== displayOrder)) {
          await updateWeeklyMenuItem(item.id, {
            service_day: item.service_day,
            section: componentDaysForm.section,
            display_order: displayOrder ?? item.display_order,
            is_active: true,
            notes: item.notes,
          })
        }
      }

      for (const day of componentDaysForm.service_days) {
        const alreadyLinked = existingItems.some((item) => item.service_day === day)

        if (!alreadyLinked) {
          await upsertWeeklyMenuComponent(component.id, {
            service_day: day,
            section: componentDaysForm.section,
            display_order: displayOrder,
            is_active: true,
            notes: null,
          })
        }
      }
    }, 'Dias da semana atualizados.')
  }

  async function runMutation(action: () => Promise<void>, success: string) {
    setIsMutating(true)
    setMutationError(null)
    setSuccessMessage(null)

    try {
      await action()
      setModal(null)
      setSuccessMessage(success)
      await reloadWorkspace()
    } catch (error) {
      setMutationError(friendlyError(error, 'Nao foi possivel salvar a alteracao.'))
    } finally {
      setIsMutating(false)
    }
  }

  return (
    <PageContainer>
      <PageHeader
        actions={
          <div className="menu-header-actions">
            <Button disabled={isDailyLoading || isAdminLoading} icon="refresh" onClick={() => void reloadWorkspace()} variant="secondary">
              {isDailyLoading || isAdminLoading ? 'Atualizando' : 'Atualizar'}
            </Button>
            <Button icon="plus" onClick={() => onOpenModal('add-product')} variant="primary">
              Adicionar item ao pedido
            </Button>
          </div>
        }
        description="Controle o cardapio operacional sem tirar precos, regras ou disponibilidade do backend."
        title="Cardapio"
      />

      <div className="menu-admin-workspace">
        <Card className="menu-date-card menu-date-card--admin">
          <div className="menu-date-card__summary">
            <span className="eyebrow">Data consultada</span>
            <strong>{formattedDate}</strong>
            <p>{serviceDayLabel}</p>
          </div>
          <div className="menu-admin-date-tools">
            <CalendarDatePicker value={selectedDate} onChange={setSelectedDate} />
            {timezoneLabel ? <Badge tone="info">{timezoneLabel}</Badge> : null}
          </div>
        </Card>

        <div aria-label="Areas do cardapio" className="menu-admin-tabs" role="tablist">
          {(Object.keys(tabLabels) as MenuAdminTab[]).map((tab) => (
            <button
              aria-controls={`menu-admin-panel-${tab}`}
              aria-selected={activeTab === tab}
              className={activeTab === tab ? 'tab is-active' : 'tab'}
              id={`menu-admin-tab-${tab}`}
              key={tab}
              onClick={() => setActiveTab(tab)}
              role="tab"
              type="button"
            >
              {tabLabels[tab]}
            </button>
          ))}
        </div>

        {successMessage ? (
          <p className="menu-admin-feedback menu-admin-feedback--success" role="status">
            {successMessage}
          </p>
        ) : null}

        {adminError ? (
          <p className="menu-admin-feedback menu-admin-feedback--warning" role="status">
            {adminError}
          </p>
        ) : null}

        <div
          aria-labelledby={`menu-admin-tab-${activeTab}`}
          className="menu-admin-panel"
          id={`menu-admin-panel-${activeTab}`}
          role="tabpanel"
        >
          {activeTab === 'today' ? (
            <TodayTab
              adjustments={dayAdjustments}
              canManageMenu={canManageMenu}
              dailyMenu={dailyMenu}
              error={dailyError}
              isLoading={isDailyLoading}
              onAddAdjustment={() => openDailyAdjustmentModal(null, 'include')}
              onClearAdjustment={(adjustment) => setModal({ type: 'daily-adjustment-clear', adjustment })}
              onOpenAdjustment={openDailyAdjustmentModal}
              onOpenAvailability={openAvailabilityModal}
              onRetry={() => void reloadWorkspace()}
            />
          ) : null}

          {activeTab === 'products' ? (
            <ProductsTab
              canManageMenu={canManageMenu}
              categories={productCategories}
              isLoading={isAdminLoading && canManageMenu}
              onEditProduct={openProductModal}
              onCreateCounterProduct={openCreateCounterProductModal}
              onResolvePending={openPendingConfigurationModal}
            />
          ) : null}

          {activeTab === 'weekly' ? (
            <WeeklyTab
              canManageMenu={canManageMenu}
              components={components}
              dayAdjustments={dayAdjustments}
              isLoading={isAdminLoading}
              onAddComponentToday={openComponentDailyAdjustment}
              onClearAdjustment={(adjustment) => setModal({ type: 'daily-adjustment-clear', adjustment })}
              onCreateComponent={() => openComponentModal(null)}
              onDeleteItem={(item) => setModal({ type: 'weekly-delete', item })}
              onDefineComponentDays={openComponentDaysModal}
              onEditComponent={openComponentModal}
              onEditItem={(item) => openWeeklyItemModal(item)}
              onOpenComponentAvailability={(component, status) => openAvailabilityModal(dailyItemFromComponent(component), status)}
              onNewItem={(section) => openWeeklyItemModal(null, { service_day: selectedWeeklyDay, section })}
              selectedDate={selectedDate}
              selectedDay={selectedWeeklyDay}
              setSelectedDay={setSelectedWeeklyDay}
              weeklyMenu={weeklyMenu}
            />
          ) : null}

          {activeTab === 'rules' ? <RulesTab onResolvePending={canManageMenu ? openPendingConfigurationModal : undefined} products={rulesProducts} /> : null}
        </div>
      </div>

      {modal ? (
        <Modal
          closeDisabled={isMutating}
          danger={modal.type === 'weekly-delete' || modal.type === 'daily-adjustment-clear'}
          onClose={() => setModal(null)}
          onPrimary={() => void handleModalPrimary()}
          open
          primaryDisabled={isMutating}
          primaryLabel={modalPrimaryLabel(modal, isMutating)}
          size={
            modal.type === 'product' || modal.type === 'weekly-item' || modal.type === 'pending-configuration' || modal.type === 'component-days'
              ? 'lg'
              : 'md'
          }
          title={modalTitle(modal)}
        >
          {renderModalContent({
            availabilityForm,
            componentForm,
            componentDaysForm,
            components,
            dailyMenu,
            dailyAdjustmentForm,
            isMutating,
            modal,
            mutationError,
            pendingConfigurationForm,
            productForm,
            productImagePreview,
            setAvailabilityForm,
            setComponentDaysForm,
            setComponentForm,
            setDailyAdjustmentForm,
            setPendingConfigurationForm,
            setProductForm,
            onClearProductImage: clearProductImage,
            onSelectProductImage: selectProductImage,
            setWeeklyItemForm,
            weeklyItemForm,
          })}
        </Modal>
      ) : null}
    </PageContainer>
  )
}

function TodayTab({
  adjustments,
  canManageMenu,
  dailyMenu,
  error,
  isLoading,
  onAddAdjustment,
  onClearAdjustment,
  onOpenAdjustment,
  onOpenAvailability,
  onRetry,
}: {
  adjustments: AdminDailyMenuAdjustment[]
  canManageMenu: boolean
  dailyMenu: DailyStructuredMenu | null
  error: string | null
  isLoading: boolean
  onAddAdjustment: () => void
  onClearAdjustment: (adjustment: AdminDailyMenuAdjustment) => void
  onOpenAdjustment: (item: DailyMenuComponent | null, action: DailyMenuAdjustmentAction) => void
  onOpenAvailability: (item: DailyMenuComponent, status: EffectiveAvailabilityStatus | 'clear') => void
  onRetry: () => void
}) {
  if (isLoading) {
    return <MenuSkeleton />
  }

  if (error) {
    return (
      <ErrorState
        actionLabel="Tentar novamente"
        description="Verifique a conexao com o backend e tente novamente."
        onAction={onRetry}
        title="Nao foi possivel atualizar o cardapio"
      />
    )
  }

  if (!dailyMenu) {
    return (
      <EmptyState
        description="A consulta ao backend ainda nao retornou dados."
        title="Cardapio nao carregado"
      />
    )
  }

  return (
    <div className="menu-admin-stack" aria-busy={isLoading}>
      <DailyMenuSections
        canManageMenu={canManageMenu}
        dailyMenu={dailyMenu}
        onAddAdjustment={onAddAdjustment}
        onOpenAdjustment={onOpenAdjustment}
        onOpenAvailability={onOpenAvailability}
      />
      {canManageMenu ? (
        <DailyAdjustmentsPanel adjustments={adjustments} onClearAdjustment={onClearAdjustment} />
      ) : null}
      <ProductCatalog
        actionLabel="Vendaveis nesta data"
        emptyDescription="Nao ha produtos liberados pelo backend para esta data."
        mode="daily"
        productsByCategory={dailyMenu.catalog.categories}
      />
    </div>
  )
}

function DailyMenuSections({
  canManageMenu,
  dailyMenu,
  onAddAdjustment,
  onOpenAdjustment,
  onOpenAvailability,
}: {
  canManageMenu: boolean
  dailyMenu: DailyStructuredMenu
  onAddAdjustment: () => void
  onOpenAdjustment: (item: DailyMenuComponent | null, action: DailyMenuAdjustmentAction) => void
  onOpenAvailability: (item: DailyMenuComponent, status: EffectiveAvailabilityStatus | 'clear') => void
}) {
  if (!dailyMenu.is_service_day && allSectionsEmpty(dailyMenu)) {
    return (
      <Card>
        <SectionTitle
          action={
            canManageMenu ? (
              <Button icon="plus" onClick={onAddAdjustment} size="sm" variant="secondary">
                Adicionar item nesta data
              </Button>
            ) : null
          }
          eyebrow="Operacao do dia"
          title="Cardapio do dia"
        />
        <EmptyState
          description="Produtos vendaveis tambem dependem da agenda da data. Domingo nao recebe itens automaticamente."
          title="Sem cardapio semanal neste dia"
        />
      </Card>
    )
  }

  return (
    <Card className="daily-menu-card">
      <SectionTitle
        action={
          canManageMenu ? (
            <Button icon="plus" onClick={onAddAdjustment} size="sm" variant="secondary">
              Adicionar item nesta data
            </Button>
          ) : null
        }
        eyebrow="Operacao do dia"
        title="Cardapio do dia"
      />
      <div className="daily-menu-grid">
        {sectionOrder.map((section) => (
          <div className="daily-menu-section" key={section}>
            <h3>{sectionLabels[section]}</h3>
            {dailyMenu.sections[section].length > 0 ? (
              <div className="daily-menu-list">
                {dailyMenu.sections[section].map((item) => (
                  <DailyMenuItem
                    canManageMenu={canManageMenu}
                    item={item}
                    key={`${item.source ?? 'weekly'}-${item.id}-${item.component.id}`}
                    onOpenAdjustment={onOpenAdjustment}
                    onOpenAvailability={onOpenAvailability}
                  />
                ))}
              </div>
            ) : (
              <p className="muted-text">Nenhum item cadastrado nesta secao.</p>
            )}
          </div>
        ))}
      </div>
    </Card>
  )
}

function DailyMenuItem({
  canManageMenu,
  item,
  onOpenAdjustment,
  onOpenAvailability,
}: {
  canManageMenu: boolean
  item: DailyMenuComponent
  onOpenAdjustment: (item: DailyMenuComponent | null, action: DailyMenuAdjustmentAction) => void
  onOpenAvailability: (item: DailyMenuComponent, status: EffectiveAvailabilityStatus | 'clear') => void
}) {
  return (
    <div className={item.available ? 'daily-menu-item' : 'daily-menu-item is-unavailable'}>
      <div className="daily-menu-item__content">
        <strong>{componentDisplayName(item.component)}</strong>
        {componentSupportingName(item.component) ? <span>{componentSupportingName(item.component)}</span> : null}
        <div className="menu-admin-inline-badges">
          <AvailabilityBadge availability={item.availability} />
          {item.source === 'daily_adjustment' ? (
            <Badge size="sm" tone="info">
              Incluido na data
            </Badge>
          ) : null}
        </div>
        {item.notes ? <span>{item.notes}</span> : null}
        {item.availability.reason ? <span>{item.availability.reason}</span> : null}
        {item.availability.replacement ? <span>Substituto sugerido: {componentDisplayName(item.availability.replacement)}</span> : null}
      </div>
      {canManageMenu ? (
        <div className="daily-menu-item__actions menu-admin-item-actions">
          <Button onClick={() => onOpenAvailability(item, 'sold_out')} size="sm" variant="secondary">
            Esgotado
          </Button>
          <Button onClick={() => onOpenAvailability(item, 'unavailable')} size="sm" variant="secondary">
            Indisponivel
          </Button>
          {item.availability.source !== 'component_default' ? (
            <Button onClick={() => onOpenAvailability(item, 'clear')} size="sm" variant="ghost">
              Restaurar
            </Button>
          ) : null}
          <Button onClick={() => onOpenAdjustment(item, 'exclude')} size="sm" variant="ghost">
            Ocultar hoje
          </Button>
        </div>
      ) : (
        <AvailabilityBadge availability={item.availability} />
      )}
    </div>
  )
}

function DailyAdjustmentsPanel({
  adjustments,
  onClearAdjustment,
}: {
  adjustments: AdminDailyMenuAdjustment[]
  onClearAdjustment: (adjustment: AdminDailyMenuAdjustment) => void
}) {
  return (
    <Card className="menu-admin-adjustments">
      <SectionTitle
        eyebrow="Excecao por data"
        title="Alteracoes somente de hoje"
      />
      <p className="muted-text">
        Estes ajustes valem apenas para a data indicada. Para mudar todas as semanas, use a aba Cardapio semanal.
      </p>
      {adjustments.length > 0 ? (
        <div className="menu-admin-list">
          {adjustments.map((adjustment) => (
            <div className="daily-adjustment-row" key={adjustment.id}>
              <div className="daily-adjustment-row__content">
                <div className="daily-adjustment-row__title">
                  <strong>{componentDisplayName(adjustment.component)}</strong>
                  {componentSupportingName(adjustment.component) ? <span>{componentSupportingName(adjustment.component)}</span> : null}
                  <Badge size="sm" tone={adjustment.action === 'include' ? 'info' : 'warning'}>
                    {adjustment.action === 'include' ? 'Incluido hoje' : 'Ocultado hoje'}
                  </Badge>
                </div>
                <span>Secao: {sectionLabels[adjustment.section]}</span>
                <span>
                  Padrao semanal:{' '}
                  {adjustment.action === 'include'
                    ? 'este item nao entra automaticamente nesta data'
                    : 'este item voltara a aparecer quando o ajuste for limpo'}
                </span>
                <span>Alteracao de hoje: {adjustment.action === 'include' ? 'mostrar nesta data' : 'nao mostrar nesta data'}</span>
                {adjustment.notes ? <small>{adjustment.notes}</small> : null}
                <small>
                  Responsavel: {adjustment.marked_by?.name ?? 'Nao informado'}
                  {adjustment.updated_at ? ` - ${formatDateTimeLabel(adjustment.updated_at)}` : ''}
                </small>
              </div>
              <Button onClick={() => onClearAdjustment(adjustment)} size="sm" variant="secondary">
                Desfazer ajuste
              </Button>
            </div>
          ))}
        </div>
      ) : (
        <p className="muted-text">Nenhuma alteracao especial foi feita para hoje. O cardapio padrao da semana esta sendo utilizado.</p>
      )}
    </Card>
  )
}

function ProductsTab({
  canManageMenu,
  categories,
  isLoading,
  onCreateCounterProduct,
  onEditProduct,
  onResolvePending,
}: {
  canManageMenu: boolean
  categories: AdminMenuProductsResponse['categories']
  isLoading: boolean
  onCreateCounterProduct: () => void
  onEditProduct: (product: StructuredMenuProduct) => void
  onResolvePending: (product: StructuredMenuProduct) => void
}) {
  const [search, setSearch] = useState('')
  const [categorySlug, setCategorySlug] = useState('all')
  const [activeFilter, setActiveFilter] = useState<ProductAdminFilter>('active')

  const visibleCategories = useMemo(() => {
    const normalizedSearch = normalizeSearch(search)

    return categories
      .map((category) => ({
        ...category,
        products: category.products.filter((product) => {
          const matchesSearch = normalizeSearch(product.name).includes(normalizedSearch)
          const matchesCategory = categorySlug === 'all' || category.slug === categorySlug
          const matchesActive = productMatchesAdminFilter(product, activeFilter)

          return matchesSearch && matchesCategory && matchesActive
        }),
      }))
      .filter((category) => category.products.length > 0)
  }, [activeFilter, categories, categorySlug, search])

  if (isLoading) {
    return <MenuSkeleton />
  }

  return (
    <div className="menu-admin-stack">
      {!canManageMenu ? (
        <Card>
          <p className="muted-text">
            Sua permissao atual permite consulta operacional. Edicoes e produtos inativos ficam restritos a menu.manage.
          </p>
        </Card>
      ) : null}
      <Card className="menu-admin-filters">
        <label>
          <span>Buscar produto</span>
          <input placeholder="Nome do produto" value={search} onChange={(event) => setSearch(event.target.value)} />
        </label>
        <SelectField
          label="Categoria"
          onChange={setCategorySlug}
          options={[
            { value: 'all', label: 'Todas' },
            ...categories.map((category) => ({ value: category.slug, label: category.name })),
          ]}
          value={categorySlug}
        />
        <SelectField
          label="Status"
          onChange={(value) => setActiveFilter(value as ProductAdminFilter)}
          options={[
            { value: 'active', label: 'Ativos' },
            { value: 'inactive', label: 'Inativos' },
            { value: 'legacy', label: 'Legados' },
            { value: 'all', label: 'Todos' },
          ]}
          value={activeFilter}
        />
      </Card>
      {canManageMenu ? (
        <Card className="counter-products-intro">
          <SectionTitle
            action={(
              <Button icon="plus" onClick={onCreateCounterProduct} size="sm" variant="primary">
                Novo produto de balcão
              </Button>
            )}
            eyebrow="Venda rápida"
            title="Produtos de balcão"
          />
          <p className="muted-text">Cadastre doces, geladinhos e itens rápidos para ficarem disponíveis no catálogo operacional.</p>
        </Card>
      ) : null}
      <ProductCatalog
        actionLabel={canManageMenu ? 'Catalogo administrativo' : 'Produtos visiveis'}
        emptyDescription="Nenhum produto corresponde aos filtros."
        mode="admin"
        onEditProduct={canManageMenu ? onEditProduct : undefined}
        onResolvePending={canManageMenu ? onResolvePending : undefined}
        productsByCategory={visibleCategories}
      />
    </div>
  )
}

function ProductCatalog({
  actionLabel,
  emptyDescription,
  mode = 'daily',
  onEditProduct,
  onResolvePending,
  productsByCategory,
}: {
  actionLabel: string
  emptyDescription: string
  mode?: ProductCardMode
  onEditProduct?: (product: StructuredMenuProduct) => void
  onResolvePending?: (product: StructuredMenuProduct) => void
  productsByCategory: AdminMenuProductsResponse['categories']
}) {
  if (productsByCategory.length === 0) {
    return (
      <EmptyState
        description={emptyDescription}
        title="Nenhum produto encontrado"
      />
    )
  }

  return (
    <div className="structured-catalog">
      {productsByCategory.map((category) => (
        <Card className="structured-category-card" key={category.id}>
          <SectionTitle eyebrow={actionLabel} title={category.name} />
          <div className="structured-product-grid">
            {category.products.map((product) => (
              <StructuredProductCard
                key={product.id}
                mode={mode}
                onEdit={onEditProduct}
                onResolvePending={onResolvePending}
                product={product}
              />
            ))}
          </div>
        </Card>
      ))}
    </div>
  )
}

function ComponentCatalog({
  adjustments,
  canManageMenu,
  components,
  onAddToday,
  onClearAdjustment,
  onCreateComponent,
  onDefineDays,
  onEditComponent,
  onOpenAvailability,
  selectedDate,
}: {
  adjustments: AdminDailyMenuAdjustment[]
  canManageMenu: boolean
  components: AdminMenuComponent[]
  onAddToday: (component: AdminMenuComponent) => void
  onClearAdjustment: (adjustment: AdminDailyMenuAdjustment) => void
  onCreateComponent: () => void
  onDefineDays: (component: AdminMenuComponent) => void
  onEditComponent: (component: AdminMenuComponent) => void
  onOpenAvailability: (component: AdminMenuComponent, status: EffectiveAvailabilityStatus | 'clear') => void
  selectedDate: string
}) {
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState<ComponentAdminFilter>('all')
  const adjustmentsByComponent = useMemo(
    () => new Map(adjustments.map((adjustment) => [adjustment.component.id, adjustment])),
    [adjustments],
  )
  const visibleComponents = useMemo(() => {
    const normalizedSearch = normalizeSearch(search)

    return components.filter((component) => {
      const matchesSearch = normalizedSearch === '' || componentSearchText(component).includes(normalizedSearch)
      const matchesFilter = componentMatchesAdminFilter(component, filter)

      return matchesSearch && matchesFilter
    })
  }, [components, filter, search])

  return (
    <Card className="menu-components-admin">
      <SectionTitle
        action={canManageMenu ? (
          <Button icon="plus" onClick={onCreateComponent} size="sm" variant="secondary">
            Nova carne ou ingrediente
          </Button>
        ) : null}
        eyebrow="Administracao"
        title="Ingredientes e opcoes"
      />
      <p className="muted-text">
        Localize carnes, saladas e acompanhamentos para editar, definir dias fixos ou usar apenas em uma alteracao da data.
      </p>
      <div className="menu-admin-filters">
        <label>
          <span>Buscar ingrediente</span>
          <input
            placeholder="Ex.: Peixe frito, bisteca ou salada"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </label>
        <SelectField
          label="Filtro"
          onChange={(value) => setFilter(value as ComponentAdminFilter)}
          options={[
            { value: 'all', label: 'Todos' },
            { value: 'meat', label: 'Carnes' },
            { value: 'salad', label: 'Saladas' },
            { value: 'hot', label: 'Acompanhamentos quentes' },
            { value: 'extra', label: 'Extras' },
            { value: 'addon', label: 'Adicionais' },
            { value: 'juice_flavor', label: 'Bebidas e sabores' },
            { value: 'active', label: 'Ativos' },
            { value: 'inactive', label: 'Inativos' },
            { value: 'without_days', label: 'Sem dia fixo' },
          ]}
          value={filter}
        />
      </div>
      {visibleComponents.length > 0 ? (
        <div className="component-admin-grid">
          {visibleComponents.map((component) => (
            <ComponentAdminCard
              adjustment={adjustmentsByComponent.get(component.id) ?? null}
              canManageMenu={canManageMenu}
              component={component}
              key={component.id}
              onAddToday={onAddToday}
              onClearAdjustment={onClearAdjustment}
              onDefineDays={onDefineDays}
              onEditComponent={onEditComponent}
              onOpenAvailability={onOpenAvailability}
              selectedDate={selectedDate}
            />
          ))}
        </div>
      ) : (
        <p className="muted-text">Nenhum ingrediente encontrado com esses filtros.</p>
      )}
    </Card>
  )
}

function ComponentAdminCard({
  adjustment,
  canManageMenu,
  component,
  onAddToday,
  onClearAdjustment,
  onDefineDays,
  onEditComponent,
  onOpenAvailability,
  selectedDate,
}: {
  adjustment: AdminDailyMenuAdjustment | null
  canManageMenu: boolean
  component: AdminMenuComponent
  onAddToday: (component: AdminMenuComponent) => void
  onClearAdjustment: (adjustment: AdminDailyMenuAdjustment) => void
  onDefineDays: (component: AdminMenuComponent) => void
  onEditComponent: (component: AdminMenuComponent) => void
  onOpenAvailability: (component: AdminMenuComponent, status: EffectiveAvailabilityStatus | 'clear') => void
  selectedDate: string
}) {
  const hasFixedDays = component.weekly_menu_items.length > 0

  return (
    <article className="component-admin-card">
      <div className="component-admin-card__header">
        <div>
          <h3>{componentDisplayName(component)}</h3>
          {componentSupportingName(component) ? <span>{componentSupportingName(component)}</span> : null}
        </div>
        <div className="menu-admin-inline-badges">
          <Badge size="sm" tone={component.is_active ? 'success' : 'danger'}>
            {component.is_active ? 'Ativo' : 'Inativo'}
          </Badge>
          <Badge size="sm" tone="neutral">
            {componentTypeLabel(component.component_type)}
          </Badge>
        </div>
      </div>
      <div className="component-admin-card__meta">
        <span>{hasFixedDays ? `Dias: ${formatWeeklyComponentDays(component)}` : 'Sem dia fixo - use em uma alteracao do dia.'}</span>
        <span>Produtos/grupos vinculados: {component.product_group_links_count}</span>
        <span>Data consultada: {formatDateLabel(selectedDate)}</span>
      </div>
      <div className="component-admin-card__status">
        <AvailabilityBadge availability={component.availability} />
        {adjustment ? (
          <span>
            {adjustment.action === 'include' ? 'Adicionado somente nesta data' : 'Ocultado somente nesta data'}
            {adjustment.marked_by?.name ? ` por ${adjustment.marked_by.name}` : ''}
            {adjustment.updated_at ? ` - ${formatDateTimeLabel(adjustment.updated_at)}` : ''}
          </span>
        ) : null}
      </div>
      {canManageMenu ? (
        <div className="component-admin-card__actions">
          <Button onClick={() => onEditComponent(component)} size="sm" variant="secondary">
            Editar
          </Button>
          <Button onClick={() => onDefineDays(component)} size="sm" variant="secondary">
            Definir dias da semana
          </Button>
          <Button onClick={() => onAddToday(component)} size="sm" variant="primary">
            Adicionar somente hoje
          </Button>
          <Button onClick={() => onOpenAvailability(component, 'sold_out')} size="sm" variant="ghost">
            Esgotado hoje
          </Button>
          <Button onClick={() => onOpenAvailability(component, 'unavailable')} size="sm" variant="ghost">
            Indisponivel hoje
          </Button>
          {component.availability.source !== 'component_default' ? (
            <Button onClick={() => onOpenAvailability(component, 'clear')} size="sm" variant="ghost">
              Restaurar disponibilidade
            </Button>
          ) : null}
          {adjustment ? (
            <Button onClick={() => onClearAdjustment(adjustment)} size="sm" variant="ghost">
              Desfazer alteracao
            </Button>
          ) : null}
        </div>
      ) : null}
    </article>
  )
}

function StructuredProductCard({
  mode = 'daily',
  onEdit,
  onResolvePending,
  product,
}: {
  mode?: ProductCardMode
  onEdit?: (product: StructuredMenuProduct) => void
  onResolvePending?: (product: StructuredMenuProduct) => void
  product: StructuredMenuProduct
}) {
  const insights = productInsights(product)
  const price = formatCurrency(centsToCurrency(productPriceCents(product)))
  const isVisuallyUnavailable = mode === 'daily' && !product.availability.available

  return (
    <article className={isVisuallyUnavailable ? 'structured-product-card is-unavailable' : 'structured-product-card'}>
      <div className="structured-product-card__top">
        {mode === 'admin' ? <ProductAdministrativeBadge product={product} /> : <AvailabilityBadge availability={product.availability} />}
        <strong>{price}</strong>
      </div>
      {product.image_url ? <img alt="" className="structured-product-card__image" src={product.image_url} /> : null}
      <div className="structured-product-card__title">
        <h3>{product.name}</h3>
        {product.is_counter_product ? (
          <Badge size="sm" tone="info">
            Balcão
          </Badge>
        ) : null}
        {product.is_legacy && mode !== 'admin' ? (
          <Badge size="sm" tone="neutral">
            Legado
          </Badge>
        ) : null}
        {product.configuration_pending ? (
          <Badge size="sm" tone="warning">
            Configuracao pendente
          </Badge>
        ) : null}
      </div>
      {product.description ? <p>{product.description}</p> : null}
      {product.legacy_reason ? <p className="muted-text">{product.legacy_reason}</p> : null}
      {mode === 'admin' ? (
        <div className="structured-product-admin-state">
          <span>{product.is_available_by_default ? 'Disponivel por padrao' : 'Indisponivel por padrao'}</span>
          <span>Dias: {formatServiceDays(product.service_days)}</span>
        </div>
      ) : (
        <p className="muted-text">Dias: {formatServiceDays(product.service_days)}</p>
      )}
      <ul className="structured-product-rules">
        {insights.map((insight) => (
          <li key={insight}>{insight}</li>
        ))}
      </ul>
      <div className="structured-product-card__actions">
        {onResolvePending && product.configuration_pending ? (
          <Button icon="alert" onClick={() => onResolvePending(product)} size="sm" variant="primary">
            Resolver configuracao
          </Button>
        ) : null}
        {onEdit ? (
          <Button icon="edit" onClick={() => onEdit(product)} size="sm" variant="secondary">
            Editar
          </Button>
        ) : null}
      </div>
    </article>
  )
}

function WeeklyTab({
  canManageMenu,
  components,
  dayAdjustments,
  isLoading,
  onAddComponentToday,
  onClearAdjustment,
  onCreateComponent,
  onDeleteItem,
  onDefineComponentDays,
  onEditComponent,
  onEditItem,
  onOpenComponentAvailability,
  onNewItem,
  selectedDate,
  selectedDay,
  setSelectedDay,
  weeklyMenu,
}: {
  canManageMenu: boolean
  components: AdminMenuComponent[]
  dayAdjustments: AdminDailyMenuAdjustment[]
  isLoading: boolean
  onAddComponentToday: (component: AdminMenuComponent) => void
  onClearAdjustment: (adjustment: AdminDailyMenuAdjustment) => void
  onCreateComponent: () => void
  onDeleteItem: (item: AdminWeeklyMenuItem) => void
  onDefineComponentDays: (component: AdminMenuComponent) => void
  onEditComponent: (component: AdminMenuComponent) => void
  onEditItem: (item: AdminWeeklyMenuItem) => void
  onOpenComponentAvailability: (component: AdminMenuComponent, status: EffectiveAvailabilityStatus | 'clear') => void
  onNewItem: (section: DailyMenuSectionKey) => void
  selectedDate: string
  selectedDay: WeeklyMenuServiceDayKey
  setSelectedDay: (day: WeeklyMenuServiceDayKey) => void
  weeklyMenu: AdminWeeklyMenuResponse | null
}) {
  if (!canManageMenu) {
    return (
      <Card>
        <EmptyState
          description="A edicao do cardapio semanal exige a permissao menu.manage."
          title="Cardapio semanal protegido"
        />
      </Card>
    )
  }

  if (isLoading) {
    return <MenuSkeleton />
  }

  if (!weeklyMenu) {
    return (
      <EmptyState
        description="Nao foi possivel carregar os vinculos semanais administrativos."
        title="Sem cardapio semanal administrativo"
      />
    )
  }

  return (
    <div className="menu-admin-stack">
      <ComponentCatalog
        adjustments={dayAdjustments}
        canManageMenu={canManageMenu}
        components={components}
        onAddToday={onAddComponentToday}
        onClearAdjustment={onClearAdjustment}
        onCreateComponent={onCreateComponent}
        onDefineDays={onDefineComponentDays}
        onEditComponent={onEditComponent}
        onOpenAvailability={onOpenComponentAvailability}
        selectedDate={selectedDate}
      />

      <Card className="menu-admin-weekly-toolbar">
        <div className="menu-admin-day-tabs" role="tablist" aria-label="Dias do cardapio semanal">
          {weeklyDayOrder.map((day) => (
            <button
              aria-selected={selectedDay === day}
              className={selectedDay === day ? 'tab is-active' : 'tab'}
              key={day}
              onClick={() => setSelectedDay(day)}
              role="tab"
              type="button"
            >
              {serviceDayLabels[day]}
            </button>
          ))}
        </div>
        <Button icon="plus" onClick={onCreateComponent} variant="secondary">
          Novo componente
        </Button>
      </Card>

      <Card className="daily-menu-card">
        <SectionTitle eyebrow={weeklyMenu.weekly_menu?.name ?? 'Semanal'} title={serviceDayLabels[selectedDay]} />
        <div className="weekly-menu-grid">
          {sectionOrder.map((section) => (
            <div className="daily-menu-section" key={section}>
              <div className="menu-admin-section-heading">
                <h3>{sectionLabels[section]}</h3>
                <Button onClick={() => onNewItem(section)} size="sm" variant="ghost">
                  Adicionar
                </Button>
              </div>
              <div className="daily-menu-list">
                {weeklyMenu.days[selectedDay][section].length > 0 ? (
                  weeklyMenu.days[selectedDay][section].map((item) => (
                    <div className={item.is_active ? 'weekly-menu-item' : 'weekly-menu-item is-inactive'} key={item.id}>
                      <div className="weekly-menu-item__body">
                        <div className="weekly-menu-item__header">
                          <div className="weekly-menu-item__name">
                            <strong>{componentDisplayName(item.component)}</strong>
                            {componentSupportingName(item.component) ? <span>{componentSupportingName(item.component)}</span> : null}
                          </div>
                          {!item.is_active ? (
                            <Badge size="sm" tone="neutral">
                              Inativo
                            </Badge>
                          ) : null}
                        </div>
                        <span>Ordem {item.display_order}</span>
                        {item.notes ? <small>{item.notes}</small> : null}
                      </div>
                      <div className="weekly-menu-item__actions menu-admin-item-actions">
                        <Button onClick={() => onEditItem(item)} size="sm" variant="secondary">
                          Editar vinculo
                        </Button>
                        <Button onClick={() => onEditComponent(adminComponentFor(item, components))} size="sm" variant="ghost">
                          Editar componente
                        </Button>
                        <Button onClick={() => onDeleteItem(item)} size="sm" variant="danger">
                          Remover
                        </Button>
                      </div>
                    </div>
                  ))
                ) : (
                  <p className="muted-text">Nenhum item nesta secao.</p>
                )}
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}

function RulesTab({
  onResolvePending,
  products,
}: {
  onResolvePending?: (product: StructuredMenuProduct) => void
  products: StructuredMenuProduct[]
}) {
  const productsBySlug = new Map(products.map((product) => [product.slug, product]))
  const ruleProducts = [
    'n5-casa',
    'n8-casa',
    'suco',
    'combo-n8-casa-baby',
    'combo-n8-com-latinha',
    'n8-tradicional',
    'n9-tradicional',
  ]
    .map((slug) => productsBySlug.get(slug))
    .filter((product): product is StructuredMenuProduct => product !== undefined)

  return (
    <div className="menu-admin-stack">
      <Card>
        <SectionTitle eyebrow="Visualizacao" title="Regras estruturadas" />
        <p className="muted-text">Edicao avancada das regras sera disponibilizada em uma proxima etapa.</p>
      </Card>
      <div className="structured-product-grid">
        {ruleProducts.map((product) => (
          <StructuredProductCard key={product.id} onResolvePending={onResolvePending} product={product} />
        ))}
      </div>
    </div>
  )
}

function CalendarDatePicker({
  onChange,
  value,
}: {
  onChange: (value: string) => void
  value: string
}) {
  const containerRef = useRef<HTMLDivElement>(null)
  const selectedDate = parseDateString(value) ?? new Date()
  const [isOpen, setIsOpen] = useState(false)
  const [visibleMonth, setVisibleMonth] = useState(() => startOfMonth(selectedDate))

  useEffect(() => {
    if (!isOpen) {
      return undefined
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setIsOpen(false)
      }
    }

    function handlePointerDown(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    document.addEventListener('mousedown', handlePointerDown)

    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      document.removeEventListener('mousedown', handlePointerDown)
    }
  }, [isOpen])

  const days = monthCalendarDays(visibleMonth)
  const monthLabel = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' }).format(visibleMonth)
  const today = todayDateString()

  function toggleCalendar() {
    setVisibleMonth(startOfMonth(parseDateString(value) ?? new Date()))
    setIsOpen((current) => !current)
  }

  function selectDate(nextDate: string) {
    onChange(nextDate)
    setVisibleMonth(startOfMonth(parseDateString(nextDate) ?? new Date()))
    setIsOpen(false)
  }

  return (
    <div className="menu-datepicker" ref={containerRef}>
      <span>Data</span>
      <button
        aria-expanded={isOpen}
        aria-haspopup="dialog"
        className="menu-datepicker__trigger"
        onClick={toggleCalendar}
        type="button"
      >
        <strong>{formatDateLabel(value)}</strong>
        <small>{value === today ? 'Hoje' : serviceDayNameForDate(value)}</small>
      </button>
      {isOpen ? (
        <div aria-label="Selecionar data" className="menu-datepicker__popover" role="dialog">
          <div className="menu-datepicker__header">
            <button aria-label="Mes anterior" onClick={() => setVisibleMonth(shiftMonth(visibleMonth, -1))} type="button">
              {'<'}
            </button>
            <strong>{capitalize(monthLabel)}</strong>
            <button aria-label="Proximo mes" onClick={() => setVisibleMonth(shiftMonth(visibleMonth, 1))} type="button">
              {'>'}
            </button>
          </div>
          <div className="menu-datepicker__weekdays" aria-hidden="true">
            {['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'].map((day) => (
              <span key={day}>{day}</span>
            ))}
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
            <Button onClick={() => selectDate(today)} size="sm" variant="secondary">
              Hoje
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}

function renderModalContent({
  availabilityForm,
  componentDaysForm,
  componentForm,
  components,
  dailyMenu,
  dailyAdjustmentForm,
  isMutating,
  modal,
  mutationError,
  pendingConfigurationForm,
  onClearProductImage,
  onSelectProductImage,
  productForm,
  productImagePreview,
  setAvailabilityForm,
  setComponentDaysForm,
  setComponentForm,
  setDailyAdjustmentForm,
  setPendingConfigurationForm,
  setProductForm,
  setWeeklyItemForm,
  weeklyItemForm,
}: {
  availabilityForm: AvailabilityFormState
  componentDaysForm: ComponentDaysFormState
  componentForm: ComponentFormState | null
  components: AdminMenuComponent[]
  dailyMenu: DailyStructuredMenu | null
  dailyAdjustmentForm: DailyAdjustmentFormState
  isMutating: boolean
  modal: Exclude<ModalState, null>
  mutationError: string | null
  pendingConfigurationForm: PendingConfigurationFormState
  onClearProductImage: () => void
  onSelectProductImage: (file: File | null) => void
  productForm: ProductFormState | null
  productImagePreview: string | null
  setAvailabilityForm: (updater: (current: AvailabilityFormState) => AvailabilityFormState) => void
  setComponentDaysForm: (updater: (current: ComponentDaysFormState) => ComponentDaysFormState) => void
  setComponentForm: (updater: (current: ComponentFormState | null) => ComponentFormState | null) => void
  setDailyAdjustmentForm: (updater: (current: DailyAdjustmentFormState) => DailyAdjustmentFormState) => void
  setPendingConfigurationForm: (updater: (current: PendingConfigurationFormState) => PendingConfigurationFormState) => void
  setProductForm: (updater: (current: ProductFormState | null) => ProductFormState | null) => void
  setWeeklyItemForm: (updater: (current: WeeklyItemFormState) => WeeklyItemFormState) => void
  weeklyItemForm: WeeklyItemFormState
}) {
  return (
    <div className="modal-fields">
      {modal.type === 'product' && productForm ? (
        <ProductForm
          form={productForm}
          imagePreview={productImagePreview}
          isCounterProduct={modal.product?.is_counter_product ?? true}
          isMutating={isMutating}
          isNew={modal.product === null}
          onClearImage={onClearProductImage}
          onSelectImage={onSelectProductImage}
          setForm={setProductForm}
        />
      ) : null}
      {modal.type === 'component' && componentForm ? (
        <ComponentForm form={componentForm} isMutating={isMutating} setForm={setComponentForm} />
      ) : null}
      {modal.type === 'availability' ? (
        <AvailabilityForm
          components={components}
          form={availabilityForm}
          isClear={modal.action === 'clear'}
          isMutating={isMutating}
          item={modal.item}
          setForm={setAvailabilityForm}
        />
      ) : null}
      {modal.type === 'daily-adjustment' ? (
        <DailyAdjustmentForm
          components={components}
          dailyMenu={dailyMenu}
          form={dailyAdjustmentForm}
          isMutating={isMutating}
          item={modal.item}
          setForm={setDailyAdjustmentForm}
        />
      ) : null}
      {modal.type === 'daily-adjustment-clear' ? (
        <p>
          Remover o ajuste de <strong>{componentDisplayName(modal.adjustment.component)}</strong> em {sectionLabels[modal.adjustment.section]} e voltar
          ao comportamento do cardapio semanal desta data?
        </p>
      ) : null}
      {modal.type === 'weekly-item' ? (
        <WeeklyItemForm
          components={components}
          form={weeklyItemForm}
          isEditing={modal.item !== null}
          isMutating={isMutating}
          setForm={setWeeklyItemForm}
        />
      ) : null}
      {modal.type === 'weekly-delete' ? (
        <p>
          Remover <strong>{componentDisplayName(modal.item.component)}</strong> de {serviceDayLabels[modal.item.service_day]} em{' '}
          {sectionLabels[modal.item.section]}? O componente global sera preservado.
        </p>
      ) : null}
      {modal.type === 'pending-configuration' ? (
        <PendingConfigurationForm
          form={pendingConfigurationForm}
          isMutating={isMutating}
          product={modal.product}
          setForm={setPendingConfigurationForm}
        />
      ) : null}
      {modal.type === 'component-days' ? (
        <ComponentDaysForm
          component={modal.component}
          form={componentDaysForm}
          isMutating={isMutating}
          setForm={setComponentDaysForm}
        />
      ) : null}
      {mutationError ? <p className="form-error">{mutationError}</p> : null}
    </div>
  )
}

function ProductForm({
  form,
  imagePreview,
  isCounterProduct,
  isMutating,
  isNew,
  onClearImage,
  onSelectImage,
  setForm,
}: {
  form: ProductFormState
  imagePreview: string | null
  isCounterProduct: boolean
  isMutating: boolean
  isNew: boolean
  onClearImage: () => void
  onSelectImage: (file: File | null) => void
  setForm: (updater: (current: ProductFormState | null) => ProductFormState | null) => void
}) {
  return (
    <>
      <label>
        <span>Nome</span>
        <input disabled={isMutating} value={form.name} onChange={(event) => updateProductForm(setForm, 'name', event.target.value)} />
      </label>
      <label>
        <span>Descricao</span>
        <textarea
          disabled={isMutating}
          value={form.description}
          onChange={(event) => updateProductForm(setForm, 'description', event.target.value)}
        />
      </label>
      <div className="menu-admin-form-grid">
        <label>
          <span>Preco em reais</span>
          <input
            disabled={isMutating}
            inputMode="decimal"
            value={form.price}
            onChange={(event) => updateProductForm(setForm, 'price', event.target.value)}
          />
        </label>
        {isCounterProduct || isNew ? (
          <SelectField
            disabled={isMutating}
            label="Categoria"
            onChange={(value) => updateProductForm(setForm, 'category_slug', value as CounterProductCategorySlug)}
            options={counterProductCategoryOptions}
            value={form.category_slug}
          />
        ) : null}
      </div>
      {isCounterProduct || isNew ? (
        <div className="product-image-field">
          <div aria-label="Prévia da foto do produto" className="product-image-field__preview">
            {imagePreview ? <img alt="Prévia do produto" src={imagePreview} /> : <span>Sem foto</span>}
          </div>
          <div className="product-image-field__actions">
            <label className="button button--secondary button--sm">
              <span>{imagePreview ? 'Trocar foto' : 'Adicionar foto'}</span>
              <input
                accept="image/jpeg,image/png,image/webp"
                className="sr-only"
                disabled={isMutating}
                onChange={(event) => onSelectImage(event.target.files?.[0] ?? null)}
                type="file"
              />
            </label>
            {imagePreview ? (
              <Button disabled={isMutating} onClick={onClearImage} size="sm" variant="ghost">
                Remover foto
              </Button>
            ) : null}
            <p className="muted-text">JPG, PNG ou WebP de até 5 MB.</p>
          </div>
        </div>
      ) : null}
      <div className="menu-admin-check-grid">
        <CheckField
          checked={form.is_active}
          disabled={isMutating}
          label="Produto ativo"
          onChange={(checked) => updateProductForm(setForm, 'is_active', checked)}
        />
        <CheckField
          checked={form.is_available_by_default}
          disabled={isMutating}
          label="Disponivel por padrao"
          onChange={(checked) => updateProductForm(setForm, 'is_available_by_default', checked)}
        />
      </div>
      <fieldset className="menu-admin-fieldset">
        <legend>Dias recorrentes de venda</legend>
        <div className="menu-admin-check-grid">
          {serviceDayOrder.map((day) => (
            <CheckField
              checked={form.service_days.includes(day)}
              disabled={isMutating}
              key={day}
              label={serviceDayLabels[day]}
              onChange={() => toggleServiceDay(setForm, day)}
            />
          ))}
        </div>
      </fieldset>
      {form.beef_rules ? (
        <BeefRulesForm form={form.beef_rules} isMutating={isMutating} setForm={setForm} />
      ) : null}
    </>
  )
}

function BeefRulesForm({
  form,
  isMutating,
  setForm,
}: {
  form: BeefRulesFormState
  isMutating: boolean
  setForm: (updater: (current: ProductFormState | null) => ProductFormState | null) => void
}) {
  return (
    <fieldset className="menu-admin-fieldset menu-beef-rules-form">
      <legend>Regras de bife</legend>
      <div className="menu-beef-rule-card">
        <CheckField
          checked={form.beef_only_enabled}
          disabled={isMutating}
          label="Somente bife"
          onChange={(checked) => updateBeefRulesForm(setForm, 'beef_only_enabled', checked)}
        />
        <p className="muted-text">Substitui todas as carnes tradicionais.</p>
        <label>
          <span>Preco final em reais</span>
          <input
            disabled={isMutating || !form.beef_only_enabled}
            inputMode="decimal"
            placeholder="Ex.: 20,00"
            value={form.beef_only_final_price}
            onChange={(event) => updateBeefRulesForm(setForm, 'beef_only_final_price', event.target.value)}
          />
        </label>
      </div>
      <div className="menu-beef-rule-card">
        <CheckField
          checked={form.extra_beef_enabled}
          disabled={isMutating}
          label="Bife adicional"
          onChange={(checked) => updateBeefRulesForm(setForm, 'extra_beef_enabled', checked)}
        />
        <p className="muted-text">Mantem as carnes escolhidas e acrescenta um pedaco de bife.</p>
        <div className="menu-admin-form-grid">
          <label>
            <span>Valor adicional em reais</span>
            <input
              disabled={isMutating || !form.extra_beef_enabled}
              inputMode="decimal"
              placeholder="Ex.: 7,00"
              value={form.extra_beef_price}
              onChange={(event) => updateBeefRulesForm(setForm, 'extra_beef_price', event.target.value)}
            />
          </label>
          <label>
            <span>Quantidade maxima</span>
            <input
              disabled={isMutating || !form.extra_beef_enabled}
              inputMode="numeric"
              value={form.extra_beef_max_quantity}
              onChange={(event) => updateBeefRulesForm(setForm, 'extra_beef_max_quantity', event.target.value)}
            />
          </label>
        </div>
      </div>
    </fieldset>
  )
}

function PendingConfigurationForm({
  form,
  isMutating,
  product,
  setForm,
}: {
  form: PendingConfigurationFormState
  isMutating: boolean
  product: StructuredMenuProduct
  setForm: (updater: (current: PendingConfigurationFormState) => PendingConfigurationFormState) => void
}) {
  const pendingOption = pendingComponentOption(product)
  const basePrice = formatCurrency(centsToCurrency(productPriceCents(product)))

  if (!pendingOption) {
    return (
      <p className="muted-text">
        Nao ha configuracao pendente neste produto. Atualize os dados do cardapio e tente novamente se o aviso continuar aparecendo.
      </p>
    )
  }

  return (
    <>
      <div className="menu-pending-summary">
        <Badge size="sm" tone="warning">
          Decisao necessaria
        </Badge>
        <strong>{product.name}</strong>
        <p>
          A variacao <strong>{componentDisplayName(pendingOption)}</strong> esta cadastrada, mas ficou pendente porque falta uma decisao operacional:
          oferecer com preco final definido ou deixar claro que essa variacao nao sera vendida.
        </p>
        <small>Preco base atual: {basePrice}</small>
      </div>
      <fieldset className="menu-admin-choice-group" disabled={isMutating}>
        <legend>Como resolver?</legend>
        <label>
          <input
            checked={form.resolution === 'not_offered'}
            name="pending-configuration-resolution"
            onChange={() => setForm((current) => ({ ...current, resolution: 'not_offered' }))}
            type="radio"
          />
          <span>Nao oferecer essa variacao por enquanto</span>
        </label>
        <label>
          <input
            checked={form.resolution === 'offered'}
            name="pending-configuration-resolution"
            onChange={() => setForm((current) => ({ ...current, resolution: 'offered' }))}
            type="radio"
          />
          <span>Oferecer com preco final definido</span>
        </label>
      </fieldset>
      {form.resolution === 'offered' ? (
        <label>
          <span>Preco final em reais</span>
          <input
            disabled={isMutating}
            inputMode="decimal"
            placeholder="Ex.: 21,00"
            value={form.final_price}
            onChange={(event) => setForm((current) => ({ ...current, final_price: event.target.value }))}
          />
        </label>
      ) : null}
      <p className="muted-text">
        Essa acao altera apenas a regra estruturada desta variacao. Se a equipe ainda nao souber o preco correto, escolha nao oferecer
        para remover o alerta sem inventar valor.
      </p>
    </>
  )
}

function ComponentForm({
  form,
  isMutating,
  setForm,
}: {
  form: ComponentFormState
  isMutating: boolean
  setForm: (updater: (current: ComponentFormState | null) => ComponentFormState | null) => void
}) {
  return (
    <>
      <label>
        <span>Nome</span>
        <input disabled={isMutating} value={form.name} onChange={(event) => updateComponentForm(setForm, 'name', event.target.value)} />
      </label>
      <div className="menu-admin-form-grid">
        <SelectField
          disabled={isMutating}
          label="Tipo"
          onChange={(value) => updateComponentForm(setForm, 'component_type', normalizeComponentType(value))}
          options={componentTypes.map((type) => ({ value: type, label: componentTypeLabels[type] }))}
          value={form.component_type}
        />
      </div>
      <label>
        <span>Descricao</span>
        <textarea
          disabled={isMutating}
          value={form.description}
          onChange={(event) => updateComponentForm(setForm, 'description', event.target.value)}
        />
      </label>
      <CheckField
        checked={form.is_active}
        disabled={isMutating}
        label="Componente ativo"
        onChange={(checked) => updateComponentForm(setForm, 'is_active', checked)}
      />
    </>
  )
}

function ComponentDaysForm({
  component,
  form,
  isMutating,
  setForm,
}: {
  component: AdminMenuComponent
  form: ComponentDaysFormState
  isMutating: boolean
  setForm: (updater: (current: ComponentDaysFormState) => ComponentDaysFormState) => void
}) {
  return (
    <>
      <div className="menu-pending-summary">
        <strong>{componentDisplayName(component)}</strong>
        {componentSupportingName(component) ? <small>{componentSupportingName(component)}</small> : null}
        <p>
          Escolha os dias fixos em que este item entra no cardapio semanal. Sem nenhum dia marcado, ele continua disponivel para
          ser usado apenas em alteracoes de uma data especifica.
        </p>
      </div>
      <SelectField
        disabled={isMutating}
        label="Seção do cardápio"
        onChange={(value) => setForm((current) => ({ ...current, section: value as DailyMenuSectionKey }))}
        options={sectionOrder.map((section) => ({ value: section, label: sectionLabels[section] }))}
        value={form.section}
      />
      <fieldset className="menu-admin-choice-group" disabled={isMutating}>
        <legend>Dias da semana</legend>
        <div className="menu-admin-choice-group__days">
          {weeklyDayOrder.map((day) => (
            <CheckField
              checked={form.service_days.includes(day)}
              disabled={isMutating}
              key={day}
              label={serviceDayLabels[day]}
              onChange={() => setForm((current) => toggleWeeklyServiceDay(current, day))}
            />
          ))}
        </div>
      </fieldset>
      <p className="muted-text">Domingo nao possui cardapio semanal recorrente nesta estrutura.</p>
    </>
  )
}

function AvailabilityForm({
  components,
  form,
  isClear,
  isMutating,
  item,
  setForm,
}: {
  components: AdminMenuComponent[]
  form: AvailabilityFormState
  isClear: boolean
  isMutating: boolean
  item: DailyMenuComponent
  setForm: (updater: (current: AvailabilityFormState) => AvailabilityFormState) => void
}) {
  if (isClear) {
    return (
      <p>
        Restaurar a disponibilidade de <strong>{componentDisplayName(item.component)}</strong> para o padrao do componente nesta data?
      </p>
    )
  }

  return (
    <>
      <p>
        Alteracao global para <strong>{componentDisplayName(item.component)}</strong> na data selecionada.
      </p>
      <SelectField
        disabled={isMutating}
        label="Status"
        onChange={(value) => setForm((current) => ({ ...current, status: value as EffectiveAvailabilityStatus }))}
        options={[
          { value: 'sold_out', label: 'Esgotado' },
          { value: 'unavailable', label: 'Indisponível' },
          { value: 'available', label: 'Disponível' },
        ]}
        value={form.status}
      />
      <label>
        <span>Motivo</span>
        <textarea
          disabled={isMutating}
          placeholder="Opcional"
          value={form.reason}
          onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))}
        />
      </label>
      <SelectField
        disabled={isMutating}
        label="Substituto sugerido"
        onChange={(value) => setForm((current) => ({ ...current, replacement_component_id: value }))}
        options={[
          { value: '', label: 'Sem substituto' },
          ...components
            .filter((component) => component.id !== item.component.id && component.is_active)
            .map((component) => ({ value: String(component.id), label: componentOptionLabel(component) })),
        ]}
        searchable
        searchPlaceholder="Buscar substituto..."
        value={form.replacement_component_id}
      />
    </>
  )
}

function DailyAdjustmentForm({
  components,
  dailyMenu,
  form,
  isMutating,
  item,
  setForm,
}: {
  components: AdminMenuComponent[]
  dailyMenu: DailyStructuredMenu | null
  form: DailyAdjustmentFormState
  isMutating: boolean
  item: DailyMenuComponent | null
  setForm: (updater: (current: DailyAdjustmentFormState) => DailyAdjustmentFormState) => void
}) {
  const normalizedSearch = normalizeSearch(form.search)
  const sectionComponentType = componentTypeForSection(form.section)
  const componentIdsAlreadyInSection = new Set(
    dailyMenu?.sections[form.section]
      .map((sectionItem) => sectionItem.component.id)
      .filter((componentId) => !item || componentId !== item.component.id) ?? [],
  )
  const selectableComponents = components
    .filter((component) => component.is_active)
    .filter((component) => component.component_type === sectionComponentType)
    .filter((component) => form.action !== 'include' || !componentIdsAlreadyInSection.has(component.id))
    .filter((component) => normalizedSearch === '' || componentSearchText(component).includes(normalizedSearch))

  return (
    <>
      <SelectField
        disabled={isMutating || item !== null}
        label="Seção"
        onChange={(value) => setForm((current) => ({ ...current, component_id: '', section: value as DailyMenuSectionKey }))}
        options={sectionOrder.map((section) => ({ value: section, label: sectionLabels[section] }))}
        value={form.section}
      />
      <label>
        <span>Buscar componente</span>
        <input
          disabled={isMutating || item !== null}
          placeholder="Digite o nome do item"
          value={form.search}
          onChange={(event) => setForm((current) => ({ ...current, search: event.target.value }))}
        />
      </label>
      <SelectField
        disabled={isMutating || item !== null}
        label="Componente"
        onChange={(value) => setForm((current) => ({ ...current, component_id: value }))}
        options={[
          { value: '', label: 'Selecione' },
          ...selectableComponents.map((component) => ({ value: String(component.id), label: componentOptionLabel(component) })),
        ]}
        searchable
        searchPlaceholder="Buscar componente..."
        value={form.component_id}
      />
      {selectableComponents.length === 0 && item === null ? (
        <p className="muted-text">Nenhum componente ativo encontrado para esta secao e busca.</p>
      ) : null}
      <div className="menu-admin-form-grid">
        <SelectField
          disabled={isMutating}
          label="Ação"
          onChange={(value) => setForm((current) => ({ ...current, action: value as DailyMenuAdjustmentAction }))}
          options={[
            { value: 'include', label: 'Incluir somente nesta data' },
            { value: 'exclude', label: 'Ocultar somente nesta data' },
          ]}
          value={form.action}
        />
      </div>
      <label>
        <span>Ordem</span>
        <input
          disabled={isMutating}
          inputMode="numeric"
          placeholder="Opcional"
          value={form.display_order}
          onChange={(event) => setForm((current) => ({ ...current, display_order: event.target.value }))}
        />
      </label>
      <label>
        <span>Observacao</span>
        <textarea
          disabled={isMutating}
          placeholder="Opcional"
          value={form.notes}
          onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))}
        />
      </label>
    </>
  )
}

function WeeklyItemForm({
  components,
  form,
  isEditing,
  isMutating,
  setForm,
}: {
  components: AdminMenuComponent[]
  form: WeeklyItemFormState
  isEditing: boolean
  isMutating: boolean
  setForm: (updater: (current: WeeklyItemFormState) => WeeklyItemFormState) => void
}) {
  return (
    <>
      <SelectField
        disabled={isMutating || isEditing}
        label="Componente"
        onChange={(value) => setForm((current) => ({ ...current, component_id: value }))}
        options={[
          { value: '', label: 'Selecione' },
          ...components.map((component) => ({ value: String(component.id), label: componentOptionLabel(component) })),
        ]}
        searchable
        searchPlaceholder="Buscar componente..."
        value={form.component_id}
      />
      <div className="menu-admin-form-grid menu-admin-form-grid--single">
        <SelectField
          disabled={isMutating}
          label="Dia"
          onChange={(value) => setForm((current) => ({ ...current, service_day: value as WeeklyMenuServiceDayKey }))}
          options={weeklyDayOrder.map((day) => ({ value: day, label: serviceDayLabels[day] }))}
          value={form.service_day}
        />
        <SelectField
          disabled={isMutating}
          label="Seção"
          onChange={(value) => setForm((current) => ({ ...current, section: value as DailyMenuSectionKey }))}
          options={sectionOrder.map((section) => ({ value: section, label: sectionLabels[section] }))}
          value={form.section}
        />
      </div>
      <label>
        <span>Ordem</span>
        <input
          disabled={isMutating}
          inputMode="numeric"
          placeholder={isEditing ? undefined : 'Opcional'}
          value={form.display_order}
          onChange={(event) => setForm((current) => ({ ...current, display_order: event.target.value }))}
        />
      </label>
      <CheckField
        checked={form.is_active}
        disabled={isMutating}
        label="Vinculo ativo"
        onChange={(checked) => setForm((current) => ({ ...current, is_active: checked }))}
      />
      <label>
        <span>Observacao</span>
        <textarea
          disabled={isMutating}
          placeholder="Opcional"
          value={form.notes}
          onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))}
        />
      </label>
    </>
  )
}

function CheckField({
  checked,
  disabled,
  label,
  onChange,
}: {
  checked: boolean
  disabled?: boolean
  label: string
  onChange: (checked: boolean) => void
}) {
  return (
    <label className="menu-admin-check">
      <input
        checked={checked}
        disabled={disabled}
        onChange={(event: ChangeEvent<HTMLInputElement>) => onChange(event.target.checked)}
        type="checkbox"
      />
      <span>{label}</span>
    </label>
  )
}

function AvailabilityBadge({ availability }: { availability: EffectiveAvailability }) {
  const tone = availability.status === 'available' ? 'success' : availability.status === 'sold_out' ? 'warning' : 'danger'

  return (
    <Badge size="sm" tone={tone}>
      {statusLabels[availability.status]}
    </Badge>
  )
}

function ProductAdministrativeBadge({ product }: { product: StructuredMenuProduct }) {
  if (product.administrative_status === 'legacy') {
    return (
      <Badge size="sm" tone="neutral">
        Legado
      </Badge>
    )
  }

  return (
    <Badge size="sm" tone={product.administrative_status === 'active' ? 'success' : 'danger'}>
      {product.administrative_status === 'active' ? 'Ativo' : 'Inativo'}
    </Badge>
  )
}

function productMatchesAdminFilter(product: StructuredMenuProduct, filter: ProductAdminFilter): boolean {
  switch (filter) {
    case 'active':
      return product.administrative_status === 'active'
    case 'inactive':
      return product.administrative_status === 'inactive'
    case 'legacy':
      return product.administrative_status === 'legacy'
    case 'all':
      return true
  }
}

function productPriceCents(product: StructuredMenuProduct): number {
  return product.base_price_cents ?? 0
}

function counterCategorySlug(slug: string | undefined): CounterProductCategorySlug {
  return counterProductCategoryOptions.some((option) => option.value === slug)
    ? slug as CounterProductCategorySlug
    : 'outros'
}

function componentDisplayName(component: StructuredMenuComponentSummary): string {
  return component.display_name || component.name
}

function componentSupportingName(component: StructuredMenuComponentSummary): string | null {
  if (!component.supporting_name || component.supporting_name === componentDisplayName(component)) {
    return null
  }

  return component.supporting_name
}

function componentOptionLabel(component: StructuredMenuComponentSummary): string {
  const supportingName = componentSupportingName(component)

  return supportingName ? `${componentDisplayName(component)} - ${supportingName}` : componentDisplayName(component)
}

function componentSearchText(component: AdminMenuComponent): string {
  return normalizeSearch([
    component.name,
    component.display_name,
    component.supporting_name ?? '',
    component.slug,
    ...component.search_aliases,
  ].join(' '))
}

function componentMatchesAdminFilter(component: AdminMenuComponent, filter: ComponentAdminFilter): boolean {
  if (filter === 'all') {
    return true
  }

  if (filter === 'active') {
    return component.is_active
  }

  if (filter === 'inactive') {
    return !component.is_active
  }

  if (filter === 'without_days') {
    return component.weekly_menu_items.length === 0
  }

  return component.component_type === filter
}

function componentTypeLabel(type: MenuComponentTypeKey | string): string {
  return componentTypeLabels[normalizeComponentType(type)]
}

function formatWeeklyComponentDays(component: AdminMenuComponent): string {
  const days = component.weekly_menu_items
    .map((item) => item.service_day)
    .filter((day, index, allDays) => allDays.indexOf(day) === index)
    .sort((first, second) => weeklyDayOrder.indexOf(first) - weeklyDayOrder.indexOf(second))

  return days.length > 0 ? days.map((day) => serviceDayLabels[day]).join(', ') : 'Sem dia fixo'
}

function sectionForComponentType(type: MenuComponentTypeKey | string): DailyMenuSectionKey {
  switch (normalizeComponentType(type)) {
    case 'meat':
      return 'meat'
    case 'salad':
      return 'salad'
    case 'base':
    case 'hot':
      return 'hot'
    case 'addon':
    case 'extra':
    case 'juice_flavor':
      return 'extra'
  }
}

function dailyItemFromComponent(component: AdminMenuComponent): DailyMenuComponent {
  return {
    id: component.id,
    source: 'daily_adjustment',
    section: sectionForComponentType(component.component_type),
    display_order: component.display_order,
    notes: component.description,
    component: {
      id: component.id,
      slug: component.slug,
      name: component.name,
      display_name: component.display_name,
      supporting_name: component.supporting_name,
      search_aliases: component.search_aliases,
      component_type: component.component_type,
    },
    availability: component.availability,
    available: component.availability.available,
  }
}

function toggleWeeklyServiceDay(current: ComponentDaysFormState, day: WeeklyMenuServiceDayKey): ComponentDaysFormState {
  const serviceDays = current.service_days.includes(day)
    ? current.service_days.filter((serviceDay) => serviceDay !== day)
    : [...current.service_days, day].sort((first, second) => weeklyDayOrder.indexOf(first) - weeklyDayOrder.indexOf(second))

  return {
    ...current,
    service_days: serviceDays,
  }
}

function pendingComponentOption(product: StructuredMenuProduct): StructuredComponentOption | null {
  for (const group of product.groups) {
    const pendingOption = group.component_options.find((option) => !option.link_active && option.requires_confirmation)

    if (pendingOption) {
      return pendingOption
    }
  }

  return null
}

function beefRulesFormFromProduct(product: StructuredMenuProduct): BeefRulesFormState | null {
  if (!product.meat_configuration) {
    return null
  }

  const extraBeef = product.additions.find((addition) => addition.code === 'extra_beef')

  return {
    beef_only_enabled: product.meat_configuration.beef_only.enabled,
    beef_only_final_price: product.meat_configuration.beef_only.final_price_cents !== null
      ? centsToInput(product.meat_configuration.beef_only.final_price_cents)
      : '',
    extra_beef_enabled: extraBeef?.enabled ?? false,
    extra_beef_price: extraBeef ? centsToInput(extraBeef.price_cents) : '',
    extra_beef_max_quantity: String(extraBeef?.max_quantity ?? 1),
  }
}

function productInsights(product: StructuredMenuProduct): string[] {
  const insights: string[] = []

  if (product.uses_weekly_menu) {
    insights.push('Usa o cardapio do dia para quentes, saladas, carnes e extras.')
  }

  beefRuleInsights(product).forEach((insight) => insights.push(insight))

  product.combo_items.forEach((item) => {
    insights.push(`Inclui ${item.quantity}x ${item.included_product.name} no preco fechado.`)
  })

  product.groups.forEach((group) => {
    if (product.meat_configuration && ['variacao_bife', 'bife_adicional'].includes(group.code)) {
      return
    }

    const summary = summarizeGroup(group)

    if (summary) {
      insights.push(summary)
    }
  })

  if (product.combo_items.length > 0) {
    insights.push('Itens internos do combo nao somam novamente ao total.')
  }

  if (product.configuration_pending) {
    const pendingOption = pendingComponentOption(product)

    insights.push(
      pendingOption
        ? `Configuracao pendente: decidir se ${componentDisplayName(pendingOption)} sera oferecido e qual sera o preco final.`
        : 'Ha uma configuracao pendente de confirmacao operacional.',
    )
  }

  return insights
}

function beefRuleInsights(product: StructuredMenuProduct): string[] {
  if (!product.meat_configuration) {
    return []
  }

  const basePrice = product.meat_configuration.traditional.base_price_cents ?? productPriceCents(product)
  const beefOnly = product.meat_configuration.beef_only
  const extraBeef = product.additions.find((addition) => addition.code === 'extra_beef')
  const insights = [`Preco padrao: ${formatCurrency(centsToCurrency(basePrice))}.`]

  if (beefOnly.enabled && beefOnly.final_price_cents !== null) {
    insights.push(`Somente bife: ${formatCurrency(centsToCurrency(beefOnly.final_price_cents))}; substitui as carnes tradicionais.`)
  } else {
    insights.push('Somente bife: inativo.')
  }

  if (extraBeef?.enabled) {
    const total = basePrice + extraBeef.price_cents

    insights.push(
      `Adicionar bife as carnes escolhidas: + ${formatCurrency(centsToCurrency(extraBeef.price_cents))}; total ${formatCurrency(centsToCurrency(total))}.`,
    )
  } else {
    insights.push('Bife adicional: inativo.')
  }

  return insights
}

function summarizeGroup(group: StructuredProductOptionGroup): string | null {
  const componentNames = group.component_options.map(optionNameWithState)
  const productNames = group.product_options.map(productOptionNameWithState)

  switch (group.code) {
    case 'bases_fixas':
      return `Bases fixas: ${listNames(componentNames)}.`
    case 'salada_casa':
      return `Salada: escolhida pela casa entre ${listNames(componentNames)}.`
    case 'salada':
      return `Salada: escolha uma entre ${listNames(componentNames)}.`
    case 'carne':
      return meatRuleSummary(group, componentNames)
    case 'sabor':
      return `Sabor obrigatorio: escolha um entre ${listNames(componentNames)}.`
    case 'bebida_combo':
      return `Bebida do combo: escolha uma lata entre ${listNames(productNames)}.`
    case 'variacao_bife':
      return bifeVariationSummary(group)
    default: {
      const names = [...componentNames, ...productNames]

      return names.length > 0 ? `${group.label}: ${listNames(names)}.` : null
    }
  }
}

function meatRuleSummary(group: StructuredProductOptionGroup, componentNames: string[]): string {
  const quantity = group.min_quantity && group.max_quantity && group.min_quantity === group.max_quantity
    ? `${group.min_quantity} ${group.min_quantity === 1 ? 'pedaco' : 'pedacos'}`
    : 'quantidade configurada'
  const sameComponent = group.same_component_only ? ' da mesma carne' : ''

  return `Carne: escolha uma entre ${listNames(componentNames)}; quantidade: ${quantity}${sameComponent}; sem mistura.`
}

function bifeVariationSummary(group: StructuredProductOptionGroup): string | null {
  const bife = group.component_options.find((option) => option.slug === 'bife') ?? group.component_options[0]

  if (!bife) {
    return null
  }

  if (!bife.link_active && bife.requires_confirmation) {
    return 'Variacao com bife ainda inativa; preco pendente.'
  }

  if (bife.final_price_cents !== null) {
    return `Variacao com bife: preco final ${formatCurrency(centsToCurrency(bife.final_price_cents))}.`
  }

  if (bife.price_delta_cents > 0) {
    return `Variacao com bife: adicional de ${formatCurrency(centsToCurrency(bife.price_delta_cents))}.`
  }

  return 'Variacao com bife configurada.'
}

function optionNameWithState(option: StructuredComponentOption): string {
  const name = componentDisplayName(option)

  return option.available ? name : `${name} (${statusLabels[option.availability.status].toLowerCase()})`
}

function productOptionNameWithState(option: StructuredProductOption): string {
  const name = option.selectable_product.name

  return option.available ? name : `${name} (${statusLabels[option.availability.status].toLowerCase()})`
}

function listNames(names: string[]): string {
  if (names.length === 0) {
    return 'sem opcoes cadastradas'
  }

  if (names.length === 1) {
    return names[0]
  }

  return `${names.slice(0, -1).join(', ')} e ${names[names.length - 1]}`
}

function modalTitle(modal: Exclude<ModalState, null>): string {
  switch (modal.type) {
    case 'product':
      return modal.product ? 'Editar produto' : 'Novo produto de balcão'
    case 'component':
      return modal.component ? 'Editar componente' : 'Novo componente'
    case 'availability':
      return modal.action === 'clear' ? 'Restaurar disponibilidade' : 'Alterar disponibilidade'
    case 'daily-adjustment':
      return modal.action === 'include' ? 'Adicionar item nesta data' : 'Ocultar item nesta data'
    case 'daily-adjustment-clear':
      return 'Limpar ajuste da data'
    case 'weekly-item':
      return modal.item ? 'Editar item semanal' : 'Adicionar item semanal'
    case 'weekly-delete':
      return 'Remover item semanal'
    case 'pending-configuration':
      return 'Resolver configuracao pendente'
    case 'component-days':
      return 'Definir dias da semana'
  }
}

function modalPrimaryLabel(modal: Exclude<ModalState, null>, isMutating: boolean): string {
  if (isMutating) {
    return 'Salvando'
  }

  switch (modal.type) {
    case 'weekly-delete':
      return 'Remover vinculo'
    case 'daily-adjustment-clear':
      return 'Limpar ajuste'
    case 'availability':
      return modal.action === 'clear' ? 'Restaurar' : 'Salvar disponibilidade'
    case 'pending-configuration':
      return 'Resolver configuracao'
    case 'component-days':
      return 'Salvar dias'
    default:
      return 'Salvar'
  }
}

function updateProductForm<Key extends keyof ProductFormState>(
  setForm: (updater: (current: ProductFormState | null) => ProductFormState | null) => void,
  key: Key,
  value: ProductFormState[Key],
) {
  setForm((current) => (current ? { ...current, [key]: value } : current))
}

function updateBeefRulesForm<Key extends keyof BeefRulesFormState>(
  setForm: (updater: (current: ProductFormState | null) => ProductFormState | null) => void,
  key: Key,
  value: BeefRulesFormState[Key],
) {
  setForm((current) => {
    if (!current?.beef_rules) {
      return current
    }

    return {
      ...current,
      beef_rules: {
        ...current.beef_rules,
        [key]: value,
      },
    }
  })
}

function updateComponentForm<Key extends keyof ComponentFormState>(
  setForm: (updater: (current: ComponentFormState | null) => ComponentFormState | null) => void,
  key: Key,
  value: ComponentFormState[Key],
) {
  setForm((current) => (current ? { ...current, [key]: value } : current))
}

function toggleServiceDay(
  setForm: (updater: (current: ProductFormState | null) => ProductFormState | null) => void,
  day: ProductServiceDayKey,
) {
  setForm((current) => {
    if (!current) {
      return current
    }

    const serviceDays = current.service_days.includes(day)
      ? current.service_days.filter((serviceDay) => serviceDay !== day)
      : [...current.service_days, day].sort((a, b) => serviceDayOrder.indexOf(a) - serviceDayOrder.indexOf(b))

    return {
      ...current,
      service_days: serviceDays,
    }
  })
}

function adminComponentFor(item: AdminWeeklyMenuItem, components: AdminMenuComponent[]): AdminMenuComponent {
  return (
    components.find((component) => component.id === item.component.id) ?? {
      ...item.component,
      description: null,
      default_price_delta_cents: 0,
      is_active: true,
      display_order: item.display_order,
      product_group_links_count: 0,
      weekly_menu_items_count: 0,
      weekly_menu_items: [
        {
          id: item.id,
          service_day: item.service_day,
          section: item.section,
          display_order: item.display_order,
          is_active: item.is_active,
          notes: item.notes,
        },
      ],
      availability: {
        status: 'available',
        available: true,
        source: 'component_default',
        reason: null,
        availability_date: todayDateString(),
        replacement: null,
      },
    }
  )
}

function emptyAvailabilityForm(): AvailabilityFormState {
  return {
    status: 'sold_out',
    reason: '',
    replacement_component_id: '',
  }
}

function emptyDailyAdjustmentForm(): DailyAdjustmentFormState {
  return {
    component_id: '',
    section: 'meat',
    action: 'include',
    display_order: '',
    notes: '',
    search: '',
  }
}

function emptyComponentDaysForm(): ComponentDaysFormState {
  return {
    service_days: [],
    section: 'meat',
    display_order: '',
  }
}

function emptyWeeklyItemForm(): WeeklyItemFormState {
  return {
    component_id: '',
    service_day: 'monday',
    section: 'meat',
    display_order: '',
    is_active: true,
    notes: '',
  }
}

function allSectionsEmpty(dailyMenu: DailyStructuredMenu): boolean {
  return sectionOrder.every((section) => dailyMenu.sections[section].length === 0)
}

function formatServiceDays(days: ProductServiceDayKey[]): string {
  if (days.length === 0) {
    return 'sem dias ativos'
  }

  return days.map((day) => serviceDayLabels[day]).join(', ')
}

function parseCurrencyToCents(value: string): number | null {
  const cleanValue = value.trim()

  if (!cleanValue) {
    return null
  }

  const normalized = cleanValue.includes(',')
    ? cleanValue.replace(/\./g, '').replace(',', '.')
    : cleanValue
  const amount = Number(normalized)

  if (!Number.isFinite(amount) || amount < 0) {
    return null
  }

  return Math.round(amount * 100)
}

function parseInteger(value: string): number | null {
  const parsed = Number(value)

  if (!Number.isInteger(parsed) || parsed < 0) {
    return null
  }

  return parsed
}

function centsToInput(cents: number): string {
  return (cents / 100).toFixed(2).replace('.', ',')
}

function centsToCurrency(cents: number): number {
  return cents / 100
}

function normalizeSearch(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
}

function normalizeComponentType(value: string): MenuComponentTypeKey {
  return componentTypes.includes(value as MenuComponentTypeKey) ? (value as MenuComponentTypeKey) : 'extra'
}

function componentTypeForSection(section: DailyMenuSectionKey): MenuComponentTypeKey {
  switch (section) {
    case 'hot':
      return 'hot'
    case 'salad':
      return 'salad'
    case 'meat':
      return 'meat'
    case 'extra':
      return 'extra'
  }
}

function parseDateString(value: string): Date | null {
  const [year, month, day] = value.split('-').map(Number)

  if (!year || !month || !day) {
    return null
  }

  const date = new Date(year, month - 1, day)

  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
    return null
  }

  return date
}

function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1)
}

function shiftMonth(date: Date, offset: number): Date {
  return new Date(date.getFullYear(), date.getMonth() + offset, 1)
}

function monthCalendarDays(monthDate: Date): Date[] {
  const firstDay = startOfMonth(monthDate)
  const mondayBasedOffset = (firstDay.getDay() + 6) % 7
  const firstCalendarDay = new Date(firstDay)
  firstCalendarDay.setDate(firstDay.getDate() - mondayBasedOffset)

  return Array.from({ length: 42 }, (_, index) => {
    const day = new Date(firstCalendarDay)
    day.setDate(firstCalendarDay.getDate() + index)

    return day
  })
}

function dateToString(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${date.getFullYear()}-${month}-${day}`
}

function serviceDayNameForDate(value: string): string {
  const date = parseDateString(value)

  if (!date) {
    return 'Data invalida'
  }

  const day = serviceDayOrder[date.getDay() === 0 ? 6 : date.getDay() - 1]

  return serviceDayLabels[day]
}

function friendlyTimezoneLabel(timezone?: string | null): string | null {
  if (!timezone) {
    return null
  }

  if (timezone === 'America/Sao_Paulo') {
    return 'Anapolis/GO - Horario de Brasilia'
  }

  return timezone.replaceAll('_', ' ')
}

function capitalize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function initialTab(): MenuAdminTab {
  const stored = window.localStorage.getItem(menuAdminTabKey)

  return stored === 'today' || stored === 'products' || stored === 'weekly' || stored === 'rules' ? stored : 'today'
}

function todayDateString(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')

  return `${now.getFullYear()}-${month}-${day}`
}

function formatDateLabel(date: string): string {
  const [year, month, day] = date.split('-')

  return `${day}/${month}/${year}`
}

function formatDateTimeLabel(value: string): string {
  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    month: '2-digit',
  }).format(date)
}

function friendlyError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    if (error.status === 403) {
      return 'Voce nao tem permissao para esta acao.'
    }

    if (error.status === 404) {
      return 'O item solicitado nao foi encontrado.'
    }

    if (error.status === 422) {
      return 'Revise os campos informados e tente novamente.'
    }
  }

  return error instanceof Error && error.message ? error.message : fallback
}

function MenuSkeleton() {
  return (
    <div className="menu-skeleton" role="status">
      <span className="sr-only">Carregando cardapio estruturado</span>
      <div />
      <div />
      <div />
    </div>
  )
}
