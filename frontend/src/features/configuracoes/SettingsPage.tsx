import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, SectionTitle } from '../../components/ui/Card'
import type { AppModal, IntegrationStatus, RouteKey } from '../../types/crm'

type SettingsPageProps = {
  integrations: IntegrationStatus[]
  onNavigate: (route: RouteKey) => void
  onOpenModal: (modal: AppModal) => void
  variant: 'configuracoes' | 'whatsapp' | 'ia' | 'perfil'
}

export function SettingsPage({ integrations, onNavigate, onOpenModal, variant }: SettingsPageProps) {
  if (variant === 'whatsapp') {
    return (
      <TechnicalIntegrationPage
        description="Configuração técnica da integração e dos webhooks. Não substitui a tela operacional de conversas."
        integrations={integrations}
        onOpenModal={onOpenModal}
        title="WhatsApp / API técnico"
      />
    )
  }

  if (variant === 'ia') {
    return (
      <TechnicalIntegrationPage
        description="IA apoia o atendente, sugere respostas e pede confirmação humana em ambiguidades."
        integrations={integrations}
        onOpenModal={onOpenModal}
        title="IA e automação"
      />
    )
  }

  if (variant === 'perfil') {
    return (
      <PageContainer>
        <PageHeader description="Preferências do usuário, sessões e segurança de acesso." title="Perfil do usuário" />
        <div className="split-grid">
          <Card>
            <SectionTitle title="Administrador" />
            <div className="profile-summary">
              <span className="avatar avatar--lg">AD</span>
              <div>
                <h2>Usuário administrativo</h2>
                <p>Perfil demo para pré-visualização do CRM.</p>
              </div>
            </div>
          </Card>
          <Card>
            <SectionTitle title="Segurança" />
            <p className="muted-text">Sessão local, senha forte e 2FA podem ser configurados pelo backend.</p>
            <Button icon="settings" variant="secondary">
              Revisar segurança
            </Button>
          </Card>
        </div>
      </PageContainer>
    )
  }

  return (
    <PageContainer>
      <PageHeader
        actions={
          <Button icon="plus" onClick={() => onOpenModal('add-user')} variant="primary">
            Adicionar usuário
          </Button>
        }
        description="Configurações gerais, usuários, marca, impressão, WhatsApp e IA."
        title="Configurações"
      />

      <div className="settings-grid">
        {[
          ['Geral', 'Horário, operação e padrões do restaurante.'],
          ['Usuários e permissões', 'Perfis, papéis e acesso por módulo.'],
          ['Aparência e marca', 'Cores, logo e textos principais.'],
          ['Impressão', 'Impressora, fila e prévia da comanda.'],
          ['Pagamentos', 'Pix, comprovantes e crédito do cliente.'],
          ['Segurança', 'Senha, sessões e boas práticas.'],
        ].map(([title, description]) => (
          <Card className="settings-card" key={title}>
            <SectionTitle title={title} />
            <p>{description}</p>
            <Button icon="settings" onClick={() => title === 'Geral' ? onNavigate('configuracoes-gerais') : title === 'Usuários e permissões' ? onNavigate('configuracoes-usuarios') : title === 'Aparência e marca' ? onNavigate('configuracoes-marca') : title === 'Impressão' ? onNavigate('configuracoes-impressao') : title === 'Pagamentos' ? onNavigate('configuracoes-pagamentos') : title === 'Segurança' ? onNavigate('configuracoes-seguranca') : undefined} variant="secondary">
              Abrir
            </Button>
          </Card>
        ))}
      </div>

    </PageContainer>
  )
}

type TechnicalIntegrationPageProps = {
  description: string
  integrations: IntegrationStatus[]
  onOpenModal: (modal: AppModal) => void
  title: string
}

function TechnicalIntegrationPage({ description, integrations, onOpenModal, title }: TechnicalIntegrationPageProps) {
  return (
    <PageContainer>
      <PageHeader
        actions={
          <Button icon="check" variant="primary">
            Salvar alterações
          </Button>
        }
        description={description}
        title={title}
      />
      <div className="split-grid">
        <Card>
          <SectionTitle title="Status técnico" />
          <div className="integration-list">
            {integrations.map((integration) => (
              <div className="integration-item" key={integration.id}>
                <div>
                  <strong>{integration.title}</strong>
                  <p>{integration.description}</p>
                </div>
                <Badge tone={integration.status === 'online' ? 'success' : 'warning'}>{integration.status}</Badge>
              </div>
            ))}
          </div>
        </Card>
        <Card>
          <SectionTitle title="Webhooks e credenciais" />
          <label>
            URL do webhook
            <input placeholder="Configurada por variável de ambiente segura" readOnly />
          </label>
          <label>
            Token
            <input placeholder="Valor mascarado por segurança" readOnly type="password" />
          </label>
          <div className="inline-actions">
            <Button icon="arrow" onClick={() => onOpenModal('whatsapp-error')} variant="secondary">
              Testar conexão
            </Button>
            <Button icon="alert" onClick={() => onOpenModal('whatsapp-error')} variant="secondary">
              Simular erro
            </Button>
          </div>
        </Card>
      </div>
    </PageContainer>
  )
}
