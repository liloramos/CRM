# Instalação do MVP emergencial do Champs

Este pacote adiciona uma tela funcional de prospecção ao frontend atual:

- importação CSV;
- pontuação automática;
- priorização de SP e RJ;
- filtros;
- dados demonstrativos;
- persistência no navegador;
- exportação CSV compatível com Excel.

## 1. Copiar os arquivos novos

Copie:

```text
frontend/src/features/champs/ChampsPage.tsx
frontend/src/features/champs/ChampsPage.css
```

para a mesma localização dentro de `ChatBotCRM-Champs`.

## 2. Adicionar a rota ao tipo RouteKey

Abra:

```text
frontend/src/types/crm.ts
```

Localize:

```ts
export type RouteKey = ...
```

Adicione `'champs'` à união. Exemplo:

```ts
export type RouteKey =
  | 'login'
  | 'cadastro'
  | 'dashboard'
  // demais rotas...
  | 'perfil'
  | 'champs'
```

Não remova as rotas existentes.

## 3. Alterar `frontend/src/constants/routes.ts`

No início de `menuItems`, deixe o Champs como item visível principal:

```ts
export const menuItems: MenuItem[] = [
  { key: 'champs', label: 'Prospecção de leads', icon: 'reports', group: 'operacao' },
  { key: 'perfil', label: 'Perfil', icon: 'user', group: 'configuracao' },
]
```

No objeto `routeLabels`, acrescente:

```ts
champs: 'Prospecção de leads',
```

Como `routeLabels` é um `Record<RouteKey, string>`, mantenha os demais rótulos existentes.

## 4. Alterar `frontend/src/App.tsx`

Adicione o import:

```ts
import { ChampsPage } from './features/champs/ChampsPage'
```

Mude a rota inicial:

```ts
const [activeRoute, setActiveRoute] = useState<RouteKey>('champs')
```

Dentro do `switch (activeRoute)`, antes do caso `dashboard`, adicione:

```ts
case 'champs':
  return <ChampsPage />
```

## 5. Trocar a marca da sidebar rapidamente

Substitua o conteúdo de:

```text
frontend/src/components/layout/SolLogo.tsx
```

por:

```tsx
type SolLogoProps = {
  compact?: boolean
}

export function SolLogo({ compact = false }: SolLogoProps) {
  return (
    <div
      aria-label="Champs"
      style={{
        display: 'flex',
        minHeight: compact ? 54 : 76,
        alignItems: 'center',
        justifyContent: 'center',
        padding: compact ? 8 : 18,
      }}
    >
      <span
        style={{
          color: '#6246ea',
          fontSize: compact ? 22 : 26,
          fontWeight: 900,
          letterSpacing: compact ? '-0.06em' : '0.08em',
        }}
      >
        {compact ? 'C' : 'CHAMPS'}
      </span>
    </div>
  )
}
```

O nome do componente pode continuar `SolLogo` hoje para evitar alterações adicionais.

## 6. Validar

Na pasta `frontend`:

```powershell
npm run lint
npm run build
npm run dev
```

Abra a aplicação, faça login e teste:

1. O menu mostra “Prospecção de leads”.
2. A primeira tela aberta é o Champs.
3. “Carregar demonstração” preenche a tabela.
4. “Baixar modelo CSV” gera o modelo.
5. Importe o modelo preenchido.
6. Os scores são calculados.
7. Os filtros funcionam.
8. “Exportar CSV” gera uma planilha.

## 7. Commit

```powershell
git add frontend/src/features/champs `
  frontend/src/types/crm.ts `
  frontend/src/constants/routes.ts `
  frontend/src/App.tsx `
  frontend/src/components/layout/SolLogo.tsx

git commit -m "feat: entregar MVP operacional de prospeccao Champs"
git push
```

## Limite honesto desta entrega

Esta versão importa, qualifica e exporta leads. A coleta automática de perfis do Instagram não está implementada nesta versão e deve ser apresentada como próxima integração.
