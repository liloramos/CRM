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
  variant: 'configuracoes' | 'whatsapp' | 'ia'
}

export function SettingsPage({ integrations, onNavigate, onOpenModal, variant }: SettingsPageProps) {
  if (variant === 'whatsapp') {
    return (
      <TechnicalIntegrationPage
        description="Configuracao tecnica do provider e webhooks. Nao substitui a tela operacional de conversas."
        integrations={integrations}
        onOpenModal={onOpenModal}
        title="WhatsApp / API tecnico"
      />
    )
  }

  if (variant === 'ia') {
    return (
      <TechnicalIntegrationPage
        description="IA apoia o atendente, sugere respostas e pede confirmacao humana em ambiguidades."
        integrations={integrations}
        onOpenModal={onOpenModal}
        title="IA e automacao"
      />
    )
  }

  return (
    <PageContainer>
      <PageHeader
        actions={
          <Button icon="plus" onClick={() => onOpenModal('add-user')} variant="primary">
            Adicionar usuario
          </Button>
        }
        description="Hub de configurações gerais, usuários, marca, integrações e IA."
        title="Configurações"
      />

      <div className="settings-grid">
        {[
          ['Geral', 'Horário, operação e padrões comerciais.'],
          ['Usuários e permissões', 'Perfis, papéis e acesso por módulo.'],
          ['Aparência e marca', 'Cores, logo e textos principais.'],
          ['Integrações', 'Providers, webhooks e automações.'],
          ['Performance', 'Metas, conversões e indicadores de prospecção.'],
          ['Segurança', 'Senha, sessões e boas práticas.'],
        ].map(([title, description]) => (
          <Card className="settings-card" key={title}>
            <SectionTitle title={title} />
            <p>{description}</p>
            <Button icon="settings" variant="secondary">
              Abrir
            </Button>
          </Card>
        ))}
      </div>

      <Card>
        <SectionTitle eyebrow="Previews publicos" title="Login e cadastro" />
        <div className="inline-actions">
          <Button icon="user" onClick={() => onNavigate('login')} variant="secondary">
            Ver login
          </Button>
          <Button icon="plus" onClick={() => onNavigate('cadastro')} variant="secondary">
            Ver cadastro
          </Button>
        </div>
      </Card>
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
            Salvar alteracoes
          </Button>
        }
        description={description}
        title={title}
      />
      <div className="split-grid">
        <Card>
          <SectionTitle title="Status tecnico" />
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
            <input placeholder="Configurada por variavel de ambiente segura" readOnly />
          </label>
          <label>
            Token
            <input placeholder="Valor mascarado por seguranca" readOnly type="password" />
          </label>
          <div className="inline-actions">
            <Button icon="arrow" onClick={() => onOpenModal('whatsapp-error')} variant="secondary">
              Testar conexao
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
