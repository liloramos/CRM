<?php

namespace App\Services\SystemAssistant;

use Illuminate\Support\Str;

final class SystemAssistantFeatureCatalog
{
    /** @return array<string, array{label:string, permission:?string, route:string, help:string, aliases:list<string>}> */
    public function all(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'permission' => 'dashboard.view', 'route' => 'dashboard', 'help' => 'Use o Dashboard para acompanhar o resumo operacional e os indicadores do dia.', 'aliases' => ['dashboard', 'inicio', 'resumo']],
            'conversas' => ['label' => 'Conversas', 'permission' => 'whatsapp.view', 'route' => 'conversas', 'help' => 'Use Conversas para acompanhar os atendimentos recebidos pelo WhatsApp.', 'aliases' => ['conversa', 'conversas', 'inbox', 'atendimento']],
            'caixa' => ['label' => 'Caixa', 'permission' => 'orders.manage', 'route' => 'caixa', 'help' => 'Use o Caixa para registrar vendas presenciais, lançar Self Service e abrir ou finalizar comandas.', 'aliases' => ['caixa', 'balcao', 'balcão', 'comanda', 'self service', 'venda presencial']],
            'pedidos' => ['label' => 'Pedidos', 'permission' => 'orders.view', 'route' => 'pedidos', 'help' => 'Use Pedidos para acompanhar itens, preparo, situação operacional, histórico e cancelamentos permitidos.', 'aliases' => ['pedido', 'pedidos', 'historico', 'histórico', 'cancelar venda']],
            'cardapio' => ['label' => 'Cardápio', 'permission' => 'menu.view', 'route' => 'cardapio', 'help' => 'Os produtos, preços, categorias e disponibilidade ficam em Cardápio > Produtos e preços. A edição aparece somente para quem possui permissão de gestão.', 'aliases' => ['cardapio', 'cardápio', 'menu', 'preco', 'preço', 'precos', 'preços', 'valor', 'valor do produto', 'produto', 'categoria', 'bebida']],
            'entregas' => ['label' => 'Entregas', 'permission' => 'delivery.view', 'route' => 'entregas', 'help' => 'Use Entregas para acompanhar retiradas, entregas e taxas calculadas.', 'aliases' => ['entrega', 'entregas']],
            'pagamentos' => ['label' => 'Pagamentos / Pix', 'permission' => 'payments.view', 'route' => 'pagamentos', 'help' => 'Os comprovantes pendentes ficam em Pagamentos / Pix. A confirmação é sempre humana e só aparece para perfis autorizados.', 'aliases' => ['pix', 'pagamento', 'pagamentos', 'comprovante']],
            'financeiro' => ['label' => 'Financeiro', 'permission' => 'finance.view', 'route' => 'financeiro', 'help' => 'Use Financeiro para consultar faturamento confirmado, pendências e anulações.', 'aliases' => ['financeiro', 'faturamento']],
            'clientes' => ['label' => 'Clientes', 'permission' => 'customers.view', 'route' => 'clientes', 'help' => 'Use Clientes para localizar cadastros, endereços e histórico relacionado.', 'aliases' => ['cliente', 'clientes']],
            'relatorios' => ['label' => 'Relatórios', 'permission' => 'reports.view', 'route' => 'relatorios', 'help' => 'Use Relatórios para analisar indicadores operacionais do período.', 'aliases' => ['relatorio', 'relatórios', 'relatorios']],
            'configuracoes' => ['label' => 'Configurações', 'permission' => 'settings.view', 'route' => 'configuracoes', 'help' => 'Use Configurações para acessar parâmetros operacionais e administrativos permitidos ao seu perfil.', 'aliases' => ['configuracao', 'configuração', 'configuracoes', 'configurações']],
            'perfil' => ['label' => 'Perfil', 'permission' => null, 'route' => 'perfil', 'help' => 'Use Perfil para atualizar seus próprios dados, como nome, e-mail, telefone e foto.', 'aliases' => ['perfil', 'minha conta', 'meu email', 'meu e-mail', 'minha foto']],
            'empresa' => ['label' => 'Empresa', 'permission' => 'settings.view', 'route' => 'empresa', 'help' => 'Use Empresa para consultar os dados institucionais do restaurante.', 'aliases' => ['empresa', 'restaurante']],
            'whatsapp' => ['label' => 'WhatsApp / API', 'permission' => 'whatsapp.view', 'route' => 'whatsapp', 'help' => 'Use WhatsApp / API para consultar o estado seguro da integração com a Meta.', 'aliases' => ['whatsapp', 'api meta', 'meta']],
            'ia' => ['label' => 'IA e Automação', 'permission' => 'ai.view', 'route' => 'ia', 'help' => 'Use IA e Automação para consultar o provider, rollout, orientações e proteções do Copilot.', 'aliases' => ['ia', 'automacao', 'automação', 'openai', 'copilot']],
            'usuarios' => ['label' => 'Usuários e permissões', 'permission' => 'settings.manage', 'route' => 'configuracoes-usuarios', 'help' => 'Em Configurações > Usuários e permissões, a gerência pode criar usuários, ajustar acessos e definir quem pode ser responsável por vendas.', 'aliases' => ['usuario', 'usuário', 'usuarios', 'usuários', 'permissao', 'permissão', 'vendedor', 'responsavel por vendas', 'responsável por vendas']],
            'assistente' => ['label' => 'Labia', 'permission' => null, 'route' => 'assistente', 'help' => 'A Labia orienta sobre o uso do CRM e abre somente áreas permitidas ao seu perfil.', 'aliases' => ['labia', 'assistente do sistema']],
        ];
    }

    /** @return list<string> */
    public function routeKeys(): array
    {
        return array_values(array_unique([
            ...array_column($this->all(), 'route'),
            'suporte',
            'configuracoes-gerais',
            'configuracoes-usuarios',
            'configuracoes-marca',
            'configuracoes-impressao',
            'configuracoes-pagamentos',
            'configuracoes-seguranca',
        ]));
    }

    /** @return array<string, mixed>|null */
    public function findByMessage(string $message, array $context = []): ?array
    {
        $needle = Str::of($message)->ascii()->lower()->squish()->toString();

        if (preg_match('/\bself\s*service\b|\bcomida\s+por\s+kg\b|\bvenda\s+por\s+kg\b/', $needle) === 1) {
            return ['key' => 'caixa', ...$this->all()['caixa']];
        }
        if (preg_match('/\bcancel\w*\b.{0,24}\b(?:venda|pedido)\b/', $needle) === 1) {
            return ['key' => 'pedidos', ...$this->all()['pedidos']];
        }

        $hasOperationalQuestion = preg_match('/\b(?:onde|como|abr\w*|aces\w*|ir|va|leve|ve\w*|consult\w*|localiz\w*|encontr\w*|busc\w*|cadastr\w*|adicion\w*|cri\w*|alter\w*|edit\w*|mud\w*|troc\w*|confirm\w*|confer\w*|acompan\w*|cancel\w*|exclu\w*|remov\w*|deix\w*|marc\w*)\b/', $needle) === 1;
        $hasHistory = $this->historyText($context) !== '';
        $isContextualFollowUp = $hasHistory && (
            preg_match('/^(?:e\s+(?:depois|agora|isso|essa|esse|para\s+.+|o\s+.+|a\s+.+|[a-z0-9 ]{2,24})|onde\s+fica\s+(?:isso|essa|esse))[?.!]*$/', $needle) === 1
            || preg_match('/\b(?:isso|essa|esse|ela|ele|depois)\b/', $needle) === 1
        );
        if (! $hasOperationalQuestion && ! $isContextualFollowUp) {
            return null;
        }

        $match = $this->bestMatch($needle);
        if ($match !== null) {
            return $match;
        }

        return $isContextualFollowUp ? $this->bestMatch($this->historyText($context)) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByRoute(string $route): ?array
    {
        foreach ($this->all() as $key => $feature) {
            if ($feature['route'] === $route) {
                return ['key' => $key, ...$feature];
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function bestMatch(string $text): ?array
    {
        $matches = collect($this->all())->map(function (array $feature, string $key) use ($text): array {
            $score = collect($feature['aliases'])->sum(function (string $alias) use ($text): int {
                $normalized = Str::of($alias)->ascii()->lower()->squish()->toString();
                if ($normalized === '' || preg_match('/\b'.preg_quote($normalized, '/').'\b/', $text) !== 1) {
                    return 0;
                }

                return str_contains($normalized, ' ') ? 4 : max(1, min(3, mb_strlen($normalized) - 2));
            });

            return ['key' => $key, 'score' => $score, 'feature' => $feature];
        })->sortByDesc('score')->first();

        return is_array($matches) && $matches['score'] > 0
            ? ['key' => $matches['key'], ...$matches['feature']]
            : null;
    }

    /** @param array<string,mixed> $context */
    private function historyText(array $context): string
    {
        return collect((array) ($context['recent_history'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry) && is_string($entry['text'] ?? null))
            ->slice(-4)
            ->pluck('text')
            ->map(fn (string $text): string => Str::of($text)->ascii()->lower()->squish()->toString())
            ->implode(' ');
    }
}
