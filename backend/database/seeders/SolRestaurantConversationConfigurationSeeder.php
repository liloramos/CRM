<?php

namespace Database\Seeders;

use App\Models\AiAutomationSetting;
use App\Models\Company;
use App\Models\ConversationQuickReply;
use App\Models\User;
use Illuminate\Database\Seeder;

class SolRestaurantConversationConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'restaurante-sol')->firstOrFail();
        $actor = User::query()->where('company_id', $company->id)->orderBy('id')->first();

        foreach ($this->quickReplies() as $index => $attributes) {
            $reply = ConversationQuickReply::query()->firstOrNew([
                'company_id' => $company->id,
                'shortcut' => $attributes['shortcut'],
            ]);

            $reply->fill([
                ...$attributes,
                'is_active' => true,
                'display_order' => ($index + 1) * 10,
                'updated_by' => $actor?->id,
            ]);

            if (! $reply->exists) {
                $reply->created_by = $actor?->id;
            }

            $reply->save();
        }

        $this->configureAiStyle($company);
    }

    /** @return list<array{title: string, shortcut: string, body: string, category: string}> */
    public function quickReplies(): array
    {
        return [
            [
                'shortcut' => 'ola',
                'title' => 'Saudação',
                'category' => ConversationQuickReply::CATEGORY_GREETING,
                'body' => 'Olá, tudo bem? Em que podemos ajudar? ☀️',
            ],
            [
                'shortcut' => 'valores',
                'title' => 'Informativo de valores',
                'category' => ConversationQuickReply::CATEGORY_MENU,
                'body' => <<<'TEXT'
🌞 *Sol Restaurante – Valores das marmitex*

🥡 N5 Casa: R$ 8,00
🥡 N8 Casa: R$ 13,00
🥡 N8 Livre: R$ 16,00
🥡 N9 Livre: R$ 18,00
🥡 Separadinha com 3 divisórias: R$ 20,00

As opções disponíveis podem variar conforme o buffet do dia.

Agradecemos por escolher o *Sol Restaurante*! ☀️
TEXT,
            ],
            [
                'shortcut' => 'pix',
                'title' => 'Pagamento via PIX',
                'category' => ConversationQuickReply::CATEGORY_PAYMENT,
                'body' => <<<'TEXT'
💳 *Pagamento via PIX*

📱 Chave PIX: 62 99657-6697
👤 Favorecido: Beatriz Lessa Lopes
🏦 Banco: Mercado Pago

Depois de realizar o pagamento, envie o comprovante por aqui.

A equipe fará a conferência e avisará quando o pagamento for aprovado.
O pedido somente seguirá para envio após essa confirmação.

Agradecemos pela confiança! ☀️
*Equipe Sol Restaurante*
TEXT,
            ],
            [
                'shortcut' => 'delivery',
                'title' => 'Dados para delivery',
                'category' => ConversationQuickReply::CATEGORY_ADDRESS,
                'body' => <<<'TEXT'
📢 *Para agilizar seu pedido de delivery*

Por favor, envie:

✅ Nome e sobrenome
✅ Endereço completo, com número e bairro
✅ Complemento, quando houver
✅ Localização compartilhada, sempre que possível
✅ Telefone para contato
✅ Ponto de referência

💳 Informe também a forma de pagamento:
• PIX
• Cartão por link
• Dinheiro

Para pagamento em dinheiro, diga se precisa de troco e para qual valor.

Pedidos via PIX ou cartão seguem para envio após a confirmação do pagamento pela equipe.

Quando a entrega for realizada pela 99 Entrega, enviaremos o link de acompanhamento e o código de recebimento, quando disponibilizados.

*Equipe Sol Restaurante* ☀️
TEXT,
            ],
            [
                'shortcut' => 'marmitas',
                'title' => 'Opções de marmitex',
                'category' => ConversationQuickReply::CATEGORY_MENU,
                'body' => <<<'TEXT'
🥡 *OPÇÕES DE MARMITEX – SOL RESTAURANTE*

🍽️ *N5 Casa – R$ 8,00*
Aproximadamente 500 ml.
Arroz, feijão, macarrão, mandioca e beterraba ou cenoura, conforme o buffet.
Uma carne, com um pedaço: almôndega, porco ou frango ao molho.
A composição base é fixa.

🍽️ *N8 Casa – R$ 13,00*
Aproximadamente 750 ml.
Arroz, feijão, macarrão, mandioca, uma salada disponível e uma carne com dois pedaços iguais.
Carnes: almôndega, porco, frango ao molho ou bife de fígado.
A composição base é fixa.

🍽️ *N8 Livre – R$ 16,00*
Aproximadamente 750 ml.
Montada com os itens disponíveis no buffet do dia.

🍽️ *N9 Livre – R$ 18,00*
Aproximadamente 1.100 ml.
Montada com os itens disponíveis no buffet do dia.

🍽️ *Separadinha – R$ 20,00*
Três divisórias, aproximadamente 1.000 ml.
Montada com os itens disponíveis no buffet do dia.

➕ Adicionais possuem preços próprios, a partir de R$ 4,00.

☀️ *Sol Restaurante*
TEXT,
            ],
            [
                'shortcut' => 'orientacoes',
                'title' => 'Orientações das marmitex',
                'category' => ConversationQuickReply::CATEGORY_INFORMATION,
                'body' => <<<'TEXT'
🍽️ *INFORMAÇÕES IMPORTANTES SOBRE AS MARMITEX*

Nossas marmitex seguem porções padronizadas. Não realizamos pesagem individual de arroz, feijão, carnes, saladas ou acompanhamentos.

Para evitar erros, envie seu pedido de forma clara e objetiva. Exemplo:
“Quero beterraba cozida, não crua.”

Caso alguma informação esteja incompleta, nossa equipe confirmará os detalhes antes de finalizar o pedido sempre que necessário.

Nosso compromisso é preparar sua refeição com qualidade, agilidade e atenção.

*Sol Restaurante* ☀️
TEXT,
            ],
        ];
    }

    private function configureAiStyle(Company $company): void
    {
        $provider = (string) config('chatbotcrm.ai.provider', 'fake');
        $setting = AiAutomationSetting::query()->firstOrNew([
            'company_id' => $company->id,
            'provider' => $provider,
        ]);
        $settings = $setting->settings ?? [];
        $settings['conversation_style'] = [
            'establishment_name' => 'Sol Restaurante',
            'preferred_greeting' => 'Olá, tudo bem? Em que podemos ajudar?',
            'tone' => 'warm',
            'formality' => 'balanced',
            'emoji_usage' => 'moderate',
            'preferred_words' => ['por favor', 'vamos confirmar', 'agradecemos'],
            'forbidden_words' => ['pagamento confirmado automaticamente', 'entrega garantida sem cálculo'],
            'human_transfer_message' => 'Vou chamar uma atendente para confirmar essas informações com você.',
            'payment_proof_received_message' => 'Recebemos seu comprovante. Vamos conferir o pagamento e avisaremos assim que ele for confirmado.',
            'closing_message' => 'Agradecemos por escolher o Sol Restaurante!',
        ];

        $setting->fill([
            'default_mode' => 'assisted',
            'automation_enabled' => false,
            'allow_auto_send' => false,
            'require_human_confirmation_for_ambiguous' => true,
            'require_human_confirmation_for_payments' => true,
            'status' => AiAutomationSetting::STATUS_ACTIVE,
            'settings' => $settings,
        ])->save();
    }
}
