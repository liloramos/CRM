import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Card, SectionTitle } from '../../components/ui/Card'
import type { DeliveryTask } from '../../types/crm'

type DeliveryPageProps = {
  deliveries: DeliveryTask[]
}

export function DeliveryPage({ deliveries }: DeliveryPageProps) {
  return (
    <PageContainer>
      <PageHeader
        description="Controle de entrega, retirada e pessoa autorizada sem integrar mapas reais neste modulo."
        title="Entregas e retiradas"
      />
      <div className="split-grid">
        <Card>
          <SectionTitle title="Fila de separacao" />
          <div className="delivery-list">
            {deliveries.map((delivery) => (
              <div className="delivery-item" key={delivery.id}>
                <div>
                  <strong>{delivery.orderCode}</strong>
                  <p>{delivery.recipient}</p>
                </div>
                <div>
                  <Badge tone={delivery.type === 'entrega' ? 'info' : 'manual'}>{delivery.type}</Badge>
                  <span>{delivery.status}</span>
                </div>
                <small>{delivery.routeLabel}</small>
              </div>
            ))}
          </div>
        </Card>
        <Card className="map-placeholder">
          <SectionTitle eyebrow="Preparado para futuro" title="Mapa operacional" />
          <div className="map-placeholder__canvas">
            <span />
            <span />
            <span />
          </div>
          <p>Area visual reservada para integracao futura com mapas, sem chave ou API real.</p>
        </Card>
      </div>
    </PageContainer>
  )
}
