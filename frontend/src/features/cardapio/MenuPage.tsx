import { useCallback, useEffect, useMemo, useState } from 'react'
import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { EmptyState, ErrorState } from '../../components/ui/States'
import { getDailyStructuredMenu } from '../../services/crm.service'
import type {
  AppModal,
  DailyMenuComponent,
  DailyMenuSectionKey,
  DailyStructuredMenu,
  EffectiveAvailability,
  StructuredComponentOption,
  StructuredMenuProduct,
  StructuredProductOption,
  StructuredProductOptionGroup,
} from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type MenuPageProps = {
  onOpenModal: (modal: AppModal) => void
}

const sectionLabels: Record<DailyMenuSectionKey, string> = {
  hot: 'Quentes',
  salad: 'Saladas',
  meat: 'Carnes',
  extra: 'Extras',
}

const serviceDayLabels: Record<NonNullable<DailyStructuredMenu['service_day']>, string> = {
  monday: 'Segunda-feira',
  tuesday: 'Terca-feira',
  wednesday: 'Quarta-feira',
  thursday: 'Quinta-feira',
  friday: 'Sexta-feira',
  saturday: 'Sabado',
}

const statusLabels: Record<EffectiveAvailability['status'], string> = {
  available: 'Disponivel',
  sold_out: 'Esgotado',
  unavailable: 'Indisponivel',
}

const categoryEyebrows: Record<string, string> = {
  acai: 'Gelado',
  adicionais: 'Complementos',
  bebidas: 'Bebidas vendaveis',
  combos: 'Preco fechado',
  feijoadas: 'Porcoes',
  marmitas: 'Marmitas',
  sucos: 'Sabores obrigatorios',
}

export function MenuPage({ onOpenModal }: MenuPageProps) {
  const [selectedDate] = useState(() => todayDateString())
  const [dailyMenu, setDailyMenu] = useState<DailyStructuredMenu | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const loadMenu = useCallback(async () => {
    setIsLoading(true)
    setError(null)
    setDailyMenu(null)

    try {
      setDailyMenu(await getDailyStructuredMenu(selectedDate))
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : 'Nao foi possivel carregar o cardapio.')
    } finally {
      setIsLoading(false)
    }
  }, [selectedDate])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      void loadMenu()
    }, 0)

    return () => window.clearTimeout(timeout)
  }, [loadMenu])

  const formattedDate = useMemo(() => formatDateLabel(dailyMenu?.date ?? selectedDate), [dailyMenu?.date, selectedDate])
  const serviceDayLabel = dailyMenu?.service_day ? serviceDayLabels[dailyMenu.service_day] : 'Sem cardapio semanal'

  return (
    <PageContainer>
      <PageHeader
        actions={
          <div className="menu-header-actions">
            <Button disabled={isLoading} icon="refresh" onClick={() => void loadMenu()} variant="secondary">
              {isLoading ? 'Atualizando' : 'Atualizar'}
            </Button>
            <Button icon="plus" onClick={() => onOpenModal('add-product')} variant="primary">
              Adicionar item ao pedido
            </Button>
          </div>
        }
        description="Acompanhe as opcoes do dia, produtos vendaveis e regras operacionais do cardapio."
        title="Cardapio"
      />

      <div aria-busy={isLoading} className="catalog-layout">
        <Card className="menu-date-card">
          <div>
            <span className="eyebrow">Data consultada</span>
            <strong>{formattedDate}</strong>
            <p>{serviceDayLabel}</p>
          </div>
          {dailyMenu?.timezone ? <Badge tone="info">{dailyMenu.timezone}</Badge> : null}
        </Card>

        {isLoading ? <MenuSkeleton /> : null}

        {!isLoading && error ? (
          <ErrorState
            actionLabel="Tentar novamente"
            description="Verifique sua conexao e tente novamente."
            onAction={() => void loadMenu()}
            title="Nao foi possivel atualizar o cardapio"
          />
        ) : null}

        {!isLoading && !error && dailyMenu ? (
          <>
            <DailyMenuSections dailyMenu={dailyMenu} />
            <ProductCatalog productsByCategory={dailyMenu.catalog.categories} />
          </>
        ) : null}
      </div>
    </PageContainer>
  )
}

function DailyMenuSections({ dailyMenu }: { dailyMenu: DailyStructuredMenu }) {
  if (!dailyMenu.is_service_day) {
    return (
      <Card>
        <EmptyState
          description="Produtos vendaveis continuam listados abaixo, mas nao ha cardapio semanal para esta data."
          title="Sem cardapio semanal neste dia"
        />
      </Card>
    )
  }

  return (
    <Card className="daily-menu-card">
      <SectionTitle eyebrow="Operacao do dia" title="Cardapio do dia" />
      <div className="daily-menu-grid">
        {(Object.keys(sectionLabels) as DailyMenuSectionKey[]).map((section) => (
          <div className="daily-menu-section" key={section}>
            <h3>{sectionLabels[section]}</h3>
            {dailyMenu.sections[section].length > 0 ? (
              <div className="daily-menu-list">
                {dailyMenu.sections[section].map((item) => (
                  <DailyMenuItem item={item} key={item.id} />
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

function DailyMenuItem({ item }: { item: DailyMenuComponent }) {
  return (
    <div className={item.available ? 'daily-menu-item' : 'daily-menu-item is-unavailable'}>
      <div>
        <strong>{item.component.name}</strong>
        {item.availability.reason ? <span>{item.availability.reason}</span> : null}
        {item.availability.replacement ? <span>Substituto sugerido: {item.availability.replacement.name}</span> : null}
      </div>
      <AvailabilityBadge availability={item.availability} />
    </div>
  )
}

function ProductCatalog({ productsByCategory }: { productsByCategory: DailyStructuredMenu['catalog']['categories'] }) {
  if (productsByCategory.length === 0) {
    return (
      <EmptyState
        description="Os produtos aparecerao aqui quando o catalogo estruturado estiver disponivel."
        title="Nenhum produto no catalogo"
      />
    )
  }

  return (
    <div className="structured-catalog">
      {productsByCategory.map((category) => (
        <Card className="structured-category-card" key={category.id}>
          <SectionTitle eyebrow={categoryEyebrows[category.slug] ?? 'Produtos'} title={category.name} />
          <div className="structured-product-grid">
            {category.products.map((product) => (
              <StructuredProductCard key={product.id} product={product} />
            ))}
          </div>
        </Card>
      ))}
    </div>
  )
}

function StructuredProductCard({ product }: { product: StructuredMenuProduct }) {
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
      <ul className="structured-product-rules">
        {insights.map((insight) => (
          <li key={insight}>{insight}</li>
        ))}
      </ul>
    </article>
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
    insights.push('Utiliza o cardapio do dia para quentes, saladas, carnes e extras.')
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
    insights.push('Ha uma variacao ainda pendente de confirmacao operacional.')
  }

  return insights.length > 0 ? insights : ['Produto vendavel do catalogo estruturado.']
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
    default:
      return `${group.label}: ${listNames([...componentNames, ...productNames])}.`
  }
}

function meatRuleSummary(group: StructuredProductOptionGroup, componentNames: string[]): string {
  const quantity = group.min_quantity && group.max_quantity && group.min_quantity === group.max_quantity
    ? `${group.min_quantity} ${group.min_quantity === 1 ? 'pedaco' : 'pedacos'}`
    : 'quantidade configurada'
  const sameComponent = group.same_component_only ? ' da mesma carne' : ''

  return `Carne: escolha uma entre ${listNames(componentNames)}; quantidade: ${quantity}${sameComponent}; nao permite mistura.`
}

function bifeVariationSummary(group: StructuredProductOptionGroup): string | null {
  const bife = group.component_options.find((option) => option.slug === 'bife') ?? group.component_options[0]

  if (!bife) {
    return null
  }

  if (!bife.link_active && bife.requires_confirmation) {
    return 'Variacao com bife ainda nao ativa; preco pendente.'
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

function centsToCurrency(cents: number): number {
  return cents / 100
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
