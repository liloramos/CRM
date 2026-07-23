import { useCallback, useEffect, useMemo, useState, type ChangeEvent } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { Modal } from '../../components/ui/Modal'
import { EmptyState, ErrorState } from '../../components/ui/States'
import {
  ApiError,
  clearComponentAvailability,
  clearDailyMenuAdjustment,
  createMenuComponent,
  deleteWeeklyMenuItem,
  getAdminDailyMenuAdjustments,
  getAdminMenuComponents,
  getAdminMenuProducts,
  getAdminWeeklyMenu,
  getDailyStructuredMenu,
  setComponentAvailability,
  updateMenuComponent,
  updateMenuProduct,
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
  | { type: 'product'; product: StructuredMenuProduct }
  | { type: 'component'; component: AdminMenuComponent | null }
  | { type: 'availability'; item: DailyMenuComponent; action: 'set' | 'clear' }
  | { type: 'daily-adjustment'; item: DailyMenuComponent | null; action: DailyMenuAdjustmentAction }
  | { type: 'daily-adjustment-clear'; adjustment: AdminDailyMenuAdjustment }
  | { type: 'weekly-item'; item: AdminWeeklyMenuItem | null }
  | { type: 'weekly-delete'; item: AdminWeeklyMenuItem }
  | null

type ProductFormState = {
  name: string
  description: string
  price: string
  is_active: boolean
  is_available_by_default: boolean
  display_order: string
  service_days: ProductServiceDayKey[]
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
}

type WeeklyItemFormState = {
  component_id: string
  service_day: WeeklyMenuServiceDayKey
  section: DailyMenuSectionKey
  display_order: string
  is_active: boolean
  notes: string
}

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
  const [componentForm, setComponentForm] = useState<ComponentFormState | null>(null)
  const [availabilityForm, setAvailabilityForm] = useState<AvailabilityFormState>(emptyAvailabilityForm())
  const [dailyAdjustmentForm, setDailyAdjustmentForm] = useState<DailyAdjustmentFormState>(emptyDailyAdjustmentForm())
  const [weeklyItemForm, setWeeklyItemForm] = useState<WeeklyItemFormState>(emptyWeeklyItemForm())

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
        getAdminMenuComponents(),
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

  function openProductModal(product: StructuredMenuProduct) {
    setMutationError(null)
    setProductForm({
      name: product.name,
      description: product.description ?? '',
      price: centsToInput(product.base_price_cents),
      is_active: product.is_active,
      is_available_by_default: product.is_available_by_default,
      display_order: String(product.display_order),
      service_days: [...product.service_days],
    })
    setModal({ type: 'product', product })
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

  function openDailyAdjustmentModal(item: DailyMenuComponent | null, action: DailyMenuAdjustmentAction) {
    setMutationError(null)
    setDailyAdjustmentForm({
      component_id: item ? String(item.component.id) : '',
      section: item?.section ?? 'meat',
      action,
      display_order: item ? String(item.display_order) : '',
      notes: item?.notes ?? '',
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
  }

  async function handleSaveProduct(product: StructuredMenuProduct) {
    if (!productForm) {
      return
    }

    const priceCents = parseCurrencyToCents(productForm.price)
    const displayOrder = parseInteger(productForm.display_order)

    if (priceCents === null) {
      setMutationError('Informe um preco valido em reais.')
      return
    }

    if (displayOrder === null) {
      setMutationError('Informe uma ordem valida.')
      return
    }

    await runMutation(async () => {
      await updateMenuProduct(product.id, {
        date: selectedDate,
        name: productForm.name.trim(),
        description: productForm.description.trim() || null,
        price_cents: priceCents,
        is_active: productForm.is_active,
        is_available_by_default: productForm.is_available_by_default,
        display_order: displayOrder,
        service_days: productForm.service_days,
      })
    }, 'Produto atualizado com dados do backend.')
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
        <Card className="menu-date-card">
          <div>
            <span className="eyebrow">Data consultada</span>
            <strong>{formattedDate}</strong>
            <p>{serviceDayLabel}</p>
          </div>
          <div className="menu-admin-date-tools">
            <label>
              <span>Data</span>
              <input type="date" value={selectedDate} onChange={(event) => setSelectedDate(event.target.value)} />
            </label>
            {dailyMenu?.timezone ? <Badge tone="info">{dailyMenu.timezone}</Badge> : null}
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
              components={components}
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
            />
          ) : null}

          {activeTab === 'weekly' ? (
            <WeeklyTab
              canManageMenu={canManageMenu}
              components={components}
              isLoading={isAdminLoading}
              onCreateComponent={() => openComponentModal(null)}
              onDeleteItem={(item) => setModal({ type: 'weekly-delete', item })}
              onEditComponent={openComponentModal}
              onEditItem={(item) => openWeeklyItemModal(item)}
              onNewItem={(section) => openWeeklyItemModal(null, { service_day: selectedWeeklyDay, section })}
              selectedDay={selectedWeeklyDay}
              setSelectedDay={setSelectedWeeklyDay}
              weeklyMenu={weeklyMenu}
            />
          ) : null}

          {activeTab === 'rules' ? <RulesTab products={rulesProducts} /> : null}
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
          size={modal.type === 'product' || modal.type === 'weekly-item' ? 'lg' : 'md'}
          title={modalTitle(modal)}
        >
          {renderModalContent({
            availabilityForm,
            componentForm,
            components,
            dailyAdjustmentForm,
            isMutating,
            modal,
            mutationError,
            productForm,
            setAvailabilityForm,
            setComponentForm,
            setDailyAdjustmentForm,
            setProductForm,
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
  components,
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
  components: AdminMenuComponent[]
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
        <DailyAdjustmentsPanel adjustments={adjustments} components={components} onClearAdjustment={onClearAdjustment} />
      ) : null}
      <ProductCatalog
        actionLabel="Vendaveis nesta data"
        emptyDescription="Nao ha produtos liberados pelo backend para esta data."
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
      <div>
        <strong>{item.component.name}</strong>
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
        {item.availability.replacement ? <span>Substituto sugerido: {item.availability.replacement.name}</span> : null}
      </div>
      {canManageMenu ? (
        <div className="menu-admin-item-actions">
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
  components,
  onClearAdjustment,
}: {
  adjustments: AdminDailyMenuAdjustment[]
  components: AdminMenuComponent[]
  onClearAdjustment: (adjustment: AdminDailyMenuAdjustment) => void
}) {
  const bisteca = components.find((component) => component.slug === 'bisteca-de-porco-na-chapa')

  return (
    <Card className="menu-admin-adjustments">
      <SectionTitle
        eyebrow="Exclusivo da data"
        title="Ajustes do dia"
      />
      <p className="muted-text">
        {bisteca
          ? 'Bisteca de porco na chapa fica disponivel aqui como inclusao pontual, sem alterar o semanal.'
          : 'Inclua ou oculte componentes somente na data selecionada.'}
      </p>
      {adjustments.length > 0 ? (
        <div className="menu-admin-list">
          {adjustments.map((adjustment) => (
            <div className="menu-admin-row" key={adjustment.id}>
              <div>
                <strong>{adjustment.component.name}</strong>
                <span>
                  {adjustment.action === 'include' ? 'Incluido' : 'Ocultado'} em {sectionLabels[adjustment.section]}
                </span>
                {adjustment.notes ? <small>{adjustment.notes}</small> : null}
              </div>
              <Button onClick={() => onClearAdjustment(adjustment)} size="sm" variant="secondary">
                Limpar ajuste
              </Button>
            </div>
          ))}
        </div>
      ) : (
        <p className="muted-text">Nenhum ajuste exclusivo foi registrado para esta data.</p>
      )}
    </Card>
  )
}

function ProductsTab({
  canManageMenu,
  categories,
  isLoading,
  onEditProduct,
}: {
  canManageMenu: boolean
  categories: AdminMenuProductsResponse['categories']
  isLoading: boolean
  onEditProduct: (product: StructuredMenuProduct) => void
}) {
  const [search, setSearch] = useState('')
  const [categorySlug, setCategorySlug] = useState('all')
  const [activeFilter, setActiveFilter] = useState<'all' | 'active' | 'inactive'>('all')

  const visibleCategories = useMemo(() => {
    const normalizedSearch = normalizeSearch(search)

    return categories
      .map((category) => ({
        ...category,
        products: category.products.filter((product) => {
          const matchesSearch = normalizeSearch(product.name).includes(normalizedSearch)
          const matchesCategory = categorySlug === 'all' || category.slug === categorySlug
          const matchesActive =
            activeFilter === 'all' || (activeFilter === 'active' ? product.is_active : !product.is_active)

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
        <label>
          <span>Categoria</span>
          <select value={categorySlug} onChange={(event) => setCategorySlug(event.target.value)}>
            <option value="all">Todas</option>
            {categories.map((category) => (
              <option key={category.id} value={category.slug}>
                {category.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          <span>Status</span>
          <select value={activeFilter} onChange={(event) => setActiveFilter(event.target.value as 'all' | 'active' | 'inactive')}>
            <option value="all">Todos</option>
            <option value="active">Ativos</option>
            <option value="inactive">Inativos</option>
          </select>
        </label>
      </Card>
      <ProductCatalog
        actionLabel={canManageMenu ? 'Catalogo administrativo' : 'Produtos visiveis'}
        emptyDescription="Nenhum produto corresponde aos filtros."
        onEditProduct={canManageMenu ? onEditProduct : undefined}
        productsByCategory={visibleCategories}
      />
    </div>
  )
}

function ProductCatalog({
  actionLabel,
  emptyDescription,
  onEditProduct,
  productsByCategory,
}: {
  actionLabel: string
  emptyDescription: string
  onEditProduct?: (product: StructuredMenuProduct) => void
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
              <StructuredProductCard key={product.id} onEdit={onEditProduct} product={product} />
            ))}
          </div>
        </Card>
      ))}
    </div>
  )
}

function StructuredProductCard({
  onEdit,
  product,
}: {
  onEdit?: (product: StructuredMenuProduct) => void
  product: StructuredMenuProduct
}) {
  const insights = productInsights(product)
  const price = formatCurrency(centsToCurrency(product.base_price_cents))

  return (
    <article className={product.availability.available ? 'structured-product-card' : 'structured-product-card is-unavailable'}>
      <div className="structured-product-card__top">
        <AvailabilityBadge availability={product.availability} />
        <strong>{price}</strong>
      </div>
      <div className="structured-product-card__title">
        <h3>{product.name}</h3>
        {product.configuration_pending ? (
          <Badge size="sm" tone="warning">
            Configuracao pendente
          </Badge>
        ) : null}
      </div>
      {product.description ? <p>{product.description}</p> : null}
      <p className="muted-text">Dias: {formatServiceDays(product.service_days)}</p>
      <ul className="structured-product-rules">
        {insights.map((insight) => (
          <li key={insight}>{insight}</li>
        ))}
      </ul>
      {onEdit ? (
        <Button icon="edit" onClick={() => onEdit(product)} size="sm" variant="secondary">
          Editar
        </Button>
      ) : null}
    </article>
  )
}

function WeeklyTab({
  canManageMenu,
  components,
  isLoading,
  onCreateComponent,
  onDeleteItem,
  onEditComponent,
  onEditItem,
  onNewItem,
  selectedDay,
  setSelectedDay,
  weeklyMenu,
}: {
  canManageMenu: boolean
  components: AdminMenuComponent[]
  isLoading: boolean
  onCreateComponent: () => void
  onDeleteItem: (item: AdminWeeklyMenuItem) => void
  onEditComponent: (component: AdminMenuComponent) => void
  onEditItem: (item: AdminWeeklyMenuItem) => void
  onNewItem: (section: DailyMenuSectionKey) => void
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
        <div className="daily-menu-grid">
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
                    <div className={item.is_active ? 'menu-admin-row' : 'menu-admin-row is-inactive'} key={item.id}>
                      <div>
                        <strong>{item.component.name}</strong>
                        <span>
                          Ordem {item.display_order} {item.is_active ? '' : '- inativo'}
                        </span>
                        {item.notes ? <small>{item.notes}</small> : null}
                      </div>
                      <div className="menu-admin-item-actions">
                        <Button onClick={() => onEditItem(item)} size="sm" variant="secondary">
                          Editar vinculo
                        </Button>
                        <Button onClick={() => onEditComponent(adminComponentFor(item, components))} size="sm" variant="ghost">
                          Componente
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

function RulesTab({ products }: { products: StructuredMenuProduct[] }) {
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
          <StructuredProductCard key={product.id} product={product} />
        ))}
      </div>
    </div>
  )
}

function renderModalContent({
  availabilityForm,
  componentForm,
  components,
  dailyAdjustmentForm,
  isMutating,
  modal,
  mutationError,
  productForm,
  setAvailabilityForm,
  setComponentForm,
  setDailyAdjustmentForm,
  setProductForm,
  setWeeklyItemForm,
  weeklyItemForm,
}: {
  availabilityForm: AvailabilityFormState
  componentForm: ComponentFormState | null
  components: AdminMenuComponent[]
  dailyAdjustmentForm: DailyAdjustmentFormState
  isMutating: boolean
  modal: Exclude<ModalState, null>
  mutationError: string | null
  productForm: ProductFormState | null
  setAvailabilityForm: (updater: (current: AvailabilityFormState) => AvailabilityFormState) => void
  setComponentForm: (updater: (current: ComponentFormState | null) => ComponentFormState | null) => void
  setDailyAdjustmentForm: (updater: (current: DailyAdjustmentFormState) => DailyAdjustmentFormState) => void
  setProductForm: (updater: (current: ProductFormState | null) => ProductFormState | null) => void
  setWeeklyItemForm: (updater: (current: WeeklyItemFormState) => WeeklyItemFormState) => void
  weeklyItemForm: WeeklyItemFormState
}) {
  return (
    <div className="modal-fields">
      {modal.type === 'product' && productForm ? (
        <ProductForm form={productForm} isMutating={isMutating} setForm={setProductForm} />
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
          form={dailyAdjustmentForm}
          isMutating={isMutating}
          item={modal.item}
          setForm={setDailyAdjustmentForm}
        />
      ) : null}
      {modal.type === 'daily-adjustment-clear' ? (
        <p>
          Remover o ajuste de <strong>{modal.adjustment.component.name}</strong> em {sectionLabels[modal.adjustment.section]} e voltar
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
          Remover <strong>{modal.item.component.name}</strong> de {serviceDayLabels[modal.item.service_day]} em{' '}
          {sectionLabels[modal.item.section]}? O componente global sera preservado.
        </p>
      ) : null}
      {mutationError ? <p className="form-error">{mutationError}</p> : null}
    </div>
  )
}

function ProductForm({
  form,
  isMutating,
  setForm,
}: {
  form: ProductFormState
  isMutating: boolean
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
        <label>
          <span>Ordem</span>
          <input
            disabled={isMutating}
            inputMode="numeric"
            value={form.display_order}
            onChange={(event) => updateProductForm(setForm, 'display_order', event.target.value)}
          />
        </label>
      </div>
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
        <label>
          <span>Tipo</span>
          <select
            disabled={isMutating}
            value={form.component_type}
            onChange={(event) => updateComponentForm(setForm, 'component_type', normalizeComponentType(event.target.value))}
          >
            {componentTypes.map((type) => (
              <option key={type} value={type}>
                {componentTypeLabels[type]}
              </option>
            ))}
          </select>
        </label>
        <label>
          <span>Ordem</span>
          <input
            disabled={isMutating}
            inputMode="numeric"
            value={form.display_order}
            onChange={(event) => updateComponentForm(setForm, 'display_order', event.target.value)}
          />
        </label>
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
        Restaurar a disponibilidade de <strong>{item.component.name}</strong> para o padrao do componente nesta data?
      </p>
    )
  }

  return (
    <>
      <p>
        Alteracao global para <strong>{item.component.name}</strong> na data selecionada.
      </p>
      <label>
        <span>Status</span>
        <select
          disabled={isMutating}
          value={form.status}
          onChange={(event) => setForm((current) => ({ ...current, status: event.target.value as EffectiveAvailabilityStatus }))}
        >
          <option value="sold_out">Esgotado</option>
          <option value="unavailable">Indisponivel</option>
          <option value="available">Disponivel</option>
        </select>
      </label>
      <label>
        <span>Motivo</span>
        <textarea
          disabled={isMutating}
          placeholder="Opcional"
          value={form.reason}
          onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))}
        />
      </label>
      <label>
        <span>Substituto sugerido</span>
        <select
          disabled={isMutating}
          value={form.replacement_component_id}
          onChange={(event) => setForm((current) => ({ ...current, replacement_component_id: event.target.value }))}
        >
          <option value="">Sem substituto</option>
          {components
            .filter((component) => component.id !== item.component.id && component.is_active)
            .map((component) => (
              <option key={component.id} value={component.id}>
                {component.name}
              </option>
            ))}
        </select>
      </label>
    </>
  )
}

function DailyAdjustmentForm({
  components,
  form,
  isMutating,
  item,
  setForm,
}: {
  components: AdminMenuComponent[]
  form: DailyAdjustmentFormState
  isMutating: boolean
  item: DailyMenuComponent | null
  setForm: (updater: (current: DailyAdjustmentFormState) => DailyAdjustmentFormState) => void
}) {
  return (
    <>
      <label>
        <span>Componente</span>
        <select
          disabled={isMutating || item !== null}
          value={form.component_id}
          onChange={(event) => setForm((current) => ({ ...current, component_id: event.target.value }))}
        >
          <option value="">Selecione</option>
          {components.map((component) => (
            <option key={component.id} value={component.id}>
              {component.name}
            </option>
          ))}
        </select>
      </label>
      <div className="menu-admin-form-grid">
        <label>
          <span>Acao</span>
          <select
            disabled={isMutating}
            value={form.action}
            onChange={(event) => setForm((current) => ({ ...current, action: event.target.value as DailyMenuAdjustmentAction }))}
          >
            <option value="include">Incluir somente nesta data</option>
            <option value="exclude">Ocultar somente nesta data</option>
          </select>
        </label>
        <label>
          <span>Secao</span>
          <select
            disabled={isMutating}
            value={form.section}
            onChange={(event) => setForm((current) => ({ ...current, section: event.target.value as DailyMenuSectionKey }))}
          >
            {sectionOrder.map((section) => (
              <option key={section} value={section}>
                {sectionLabels[section]}
              </option>
            ))}
          </select>
        </label>
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
      <label>
        <span>Componente</span>
        <select
          disabled={isMutating || isEditing}
          value={form.component_id}
          onChange={(event) => setForm((current) => ({ ...current, component_id: event.target.value }))}
        >
          <option value="">Selecione</option>
          {components.map((component) => (
            <option key={component.id} value={component.id}>
              {component.name}
            </option>
          ))}
        </select>
      </label>
      <div className="menu-admin-form-grid">
        <label>
          <span>Dia</span>
          <select
            disabled={isMutating}
            value={form.service_day}
            onChange={(event) => setForm((current) => ({ ...current, service_day: event.target.value as WeeklyMenuServiceDayKey }))}
          >
            {weeklyDayOrder.map((day) => (
              <option key={day} value={day}>
                {serviceDayLabels[day]}
              </option>
            ))}
          </select>
        </label>
        <label>
          <span>Secao</span>
          <select
            disabled={isMutating}
            value={form.section}
            onChange={(event) => setForm((current) => ({ ...current, section: event.target.value as DailyMenuSectionKey }))}
          >
            {sectionOrder.map((section) => (
              <option key={section} value={section}>
                {sectionLabels[section]}
              </option>
            ))}
          </select>
        </label>
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

function productInsights(product: StructuredMenuProduct): string[] {
  const insights: string[] = []

  if (product.uses_weekly_menu) {
    insights.push('Usa o cardapio do dia para quentes, saladas, carnes e extras.')
  }

  product.combo_items.forEach((item) => {
    insights.push(`Inclui ${item.quantity}x ${item.included_product.name} no preco fechado.`)
  })

  product.groups.forEach((group) => {
    const summary = summarizeGroup(group)

    if (summary) {
      insights.push(summary)
    }
  })

  if (product.combo_items.length > 0) {
    insights.push('Itens internos do combo nao somam novamente ao total.')
  }

  if (product.configuration_pending) {
    insights.push('Ha uma configuracao pendente de confirmacao operacional.')
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
  return option.available ? option.name : `${option.name} (${statusLabels[option.availability.status].toLowerCase()})`
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
      return 'Editar produto'
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
