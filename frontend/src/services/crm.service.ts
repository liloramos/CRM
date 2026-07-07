import { productsMock } from '../mocks/cardapio.mock'
import { customersMock } from '../mocks/clientes.mock'
import { conversationsMock } from '../mocks/conversas.mock'
import { deliveryTasksMock, financeEntriesMock, integrationsMock } from '../mocks/operacional.mock'
import { ordersMock } from '../mocks/pedidos.mock'

export function getOperationalSnapshot() {
  return {
    orders: ordersMock,
    conversations: conversationsMock,
    customers: customersMock,
    products: productsMock,
    deliveries: deliveryTasksMock,
    financeEntries: financeEntriesMock,
    integrations: integrationsMock,
  }
}
