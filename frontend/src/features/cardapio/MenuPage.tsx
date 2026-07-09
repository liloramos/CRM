import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import { EmptyState } from '../../components/ui/States'
import type { AppModal, Product, SnapshotSource } from '../../types/crm'
import { formatCurrency } from '../../utils/formatters'

type MenuPageProps = {
  products: Product[]
  source: SnapshotSource
  onOpenModal: (modal: AppModal) => void
}

export function MenuPage({ onOpenModal, products, source }: MenuPageProps) {
  const categories = Array.from(new Set(products.map((product) => product.category)))

  return (
    <PageContainer>
      <PageHeader
        actions={
          <Button icon="plus" onClick={() => onOpenModal('add-product')} variant="primary">
            Adicionar produto
          </Button>
        }
        description={
          source === 'api'
            ? 'Cardapio carregado do endpoint disponivel do Laravel.'
            : 'Cardapio em fallback local de desenvolvimento.'
        }
        title="Cardapio"
      />

      <div className="catalog-layout">
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
            actionLabel="Adicionar produto"
            description="Estado vazio para cardapio sem itens cadastrados."
            onAction={() => onOpenModal('add-product')}
            title="Nenhum produto cadastrado"
          />
        ) : null}
      </div>
    </PageContainer>
  )
}
