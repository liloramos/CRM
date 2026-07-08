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

  if (variant === 'perfil') {
    return (
      <PageContainer>
        <PageHeader description="Preferencias do usuario, sessoes e seguranca de acesso." title="Perfil do usuario" />
        <div className="split-grid">
          <Card>
            <SectionTitle title="Administrador" />
            <div className="profile-summary">
              <span className="avatar avatar--lg">AD</span>
              <div>
                <h2>Usuario administrativo</h2>
                <p>Perfil ficticio para pre-visualizacao do CRM.</p>
              </div>
            </div>
          </Card>
          <Card>
            <SectionTitle title="Seguranca" />
            <p className="muted-text">Sessao local, senha forte e 2FA podem ser configurados pelo backend.</p>
            <Button icon="settings" variant="secondary">
              Revisar seguranca
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
            Adicionar usuario
          </Button>
        }
        description="Hub de configuracoes gerais, usuarios, marca, impressao, WhatsApp e IA."
        title="Configuracoes"
      />

      <div className="settings-grid">
        {[
          ['Geral', 'Horario, operacao e padroes do restaurante.'],
          ['Usuarios e permissoes', 'Perfis, papeis e acesso por modulo.'],
          ['Aparencia e marca', 'Cores, logo e textos principais.'],
          ['Impressao', 'Impressora, fila e previa HTML.'],
          ['Pagamentos', 'Pix, comprovantes e credito do cliente.'],
          ['Seguranca', 'Senha, sessoes e boas praticas.'],
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
