import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { EmptyState } from '../../components/ui/States'
import type { AppModal, Product, SnapshotSource } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type MenuPageProps = {
  isUpdating: boolean
  products: Product[]
  source: SnapshotSource
  onOpenModal: (modal: AppModal) => void
  onToggleOptionAvailability: (optionId: string, availableToday: boolean) => void
}

const groupOrder = ['Bases/guarnicoes', 'Saladas', 'Carnes', 'Bebidas', 'Adicionais', 'Componentes']

export function MenuPage({ isUpdating, onOpenModal, onToggleOptionAvailability, products, source }: MenuPageProps) {
  const categories = Array.from(new Set(products.map((product) => product.category)))
  const componentRows = products.flatMap((product) =>
    product.options.map((option) => ({
      ...option,
      productName: product.name,
    })),
  )
  const groupedComponents = groupOrder
    .map((groupLabel) => ({
      groupLabel,
      rows: componentRows.filter((option) => option.groupLabel === groupLabel),
    }))
    .filter((group) => group.rows.length > 0)

  return (
    <PageContainer>
      <PageHeader
        actions={
          <Button icon="plus" onClick={() => onOpenModal('add-product')} variant="primary">
            Adicionar item ao pedido
          </Button>
        }
        description={
          source === 'api'
            ? 'Produtos e componentes carregados do Laravel. Marmitas continuam vendaveis quando apenas uma opcao acaba.'
            : 'Fallback local de desenvolvimento; use a API para operacao real.'
        }
        title="Cardapio"
      />

      <div className="catalog-layout">
        {groupedComponents.length > 0 ? (
          <Card className="availability-card">
            <SectionTitle eyebrow="Operacao do dia" title="Disponibilidade por componente" />
            <p className="muted-text">
              Marque apenas a opcao que acabou. A marmita continua no cardapio, mas o componente indisponivel sai da selecao do pedido.
            </p>
            <div className="availability-groups">
              {groupedComponents.map((group) => (
                <div className="availability-group" key={group.groupLabel}>
                  <h3>{group.groupLabel}</h3>
                  <div className="availability-list">
                    {group.rows.map((option) => (
                      <div className={option.availableToday ? 'availability-row' : 'availability-row is-unavailable'} key={option.id}>
                        <div>
                          <strong>{option.name}</strong>
                          <span>{option.productName}</span>
                          {option.dailyReason ? <small>{option.dailyReason}</small> : null}
                        </div>
                        <div className="availability-row__actions">
                          <Badge tone={option.availableToday ? 'success' : 'danger'} size="sm">
                            {option.availableToday ? 'Disponivel' : 'Esgotado'}
                          </Badge>
                          <Button
                            disabled={isUpdating}
                            onClick={() => onToggleOptionAvailability(option.id, option.availableToday)}
                            variant={option.availableToday ? 'secondary' : 'primary'}
                          >
                            {option.availableToday ? 'Marcar esgotado' : 'Restabelecer'}
                          </Button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </Card>
        ) : null}

        <Card>
          <SectionTitle title="Categorias" />
          <div className="category-list">
            {categories.map((category) => (
              <button className="category-pill" key={category} type="button">
                {category}
              </button>
            ))}
          </div>
        </Card>

        <div className="product-grid">
          {products.map((product) => (
            <Card className="product-card" key={product.id}>
              <div className="product-card__top">
                <Badge tone={product.available ? 'success' : 'danger'}>
                  {product.available ? 'Disponivel' : 'Indisponivel'}
                </Badge>
                <strong>{formatCurrency(product.price)}</strong>
              </div>
              <h2>{product.name}</h2>
              <p>{product.description}</p>
              <div className="tag-row">
                {product.tags.map((tag) => (
                  <Badge key={tag} size="sm" tone="neutral">
                    {tag}
                  </Badge>
                ))}
                {product.options.length > 0 ? (
                  <Badge size="sm" tone="info">
                    {`${product.options.length} opcoes`}
                  </Badge>
                ) : null}
              </div>
              <Button
                icon={product.available ? 'edit' : 'alert'}
                onClick={() => onOpenModal(product.available ? 'edit-item' : 'mark-unavailable')}
                variant={product.available ? 'secondary' : 'danger'}
              >
                {product.available ? 'Editar disponibilidade' : 'Revisar item'}
              </Button>
            </Card>
          ))}
        </div>

        {products.length === 0 ? (
          <EmptyState
            description="Estado vazio para cardapio sem itens cadastrados."
            title="Nenhum produto cadastrado"
          />
        ) : null}
      </div>
    </PageContainer>
  )
}
