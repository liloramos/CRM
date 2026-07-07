import { PageContainer } from '../../components/layout/PageContainer'
import { PageHeader } from '../../components/layout/PageHeader'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import type { RouteKey } from '../../types/crm'

type AuthPreviewPageProps = {
  mode: Extract<RouteKey, 'login' | 'cadastro'>
}

export function AuthPreviewPage({ mode }: AuthPreviewPageProps) {
  const isSignup = mode === 'cadastro'

  return (
    <PageContainer>
      <PageHeader
        description="Previa visual publica seguindo o mesmo tema escuro premium do CRM."
        title={isSignup ? 'Cadastro' : 'Login'}
      />
      <div className="auth-preview">
        <Card className="auth-panel" tone="glow">
          <span className="eyebrow">Sol Restaurante</span>
          <h2>{isSignup ? 'Criar acesso operacional' : 'Entrar no CRM'}</h2>
          <label>
            Nome
            <input placeholder={isSignup ? 'Usuario administrativo' : 'usuario@exemplo.local'} />
          </label>
          {isSignup ? (
            <label>
              Perfil
              <select defaultValue="atendente">
                <option value="atendente">Atendente</option>
                <option value="gerente">Gerente</option>
              </select>
            </label>
          ) : null}
          <label>
            Senha
            <input placeholder="Senha segura" type="password" />
          </label>
          <Button icon="arrow" variant="primary">
            {isSignup ? 'Criar conta' : 'Acessar'}
          </Button>
        </Card>
        <Card className="auth-side">
          <h2>Operacao sem ruído</h2>
          <p>Atendimento, pedidos, pagamento e impressao em um fluxo unico para a equipe.</p>
          <div className="auth-side__steps">
            <span>Atendimento</span>
            <span>Conferencia</span>
            <span>Comanda</span>
          </div>
        </Card>
      </div>
    </PageContainer>
  )
}
