<?php

namespace App\Console\Commands;

use App\Champs\Contracts\LeadProviderInterface;
use App\Champs\DTOs\LeadDiscoveryRequest;
use App\Champs\Exceptions\LeadProviderException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class ChampsTestGooglePlacesCommand extends Command
{
    protected $signature = 'champs:test-google-places
        {niche : Segmento ou nicho comercial a pesquisar}
        {--city=São Paulo : Cidade da pesquisa}
        {--state=SP : Estado ou UF da pesquisa}
        {--limit=5 : Quantidade máxima de resultados}';

    protected $description = 'Testa a descoberta real de leads pelo Google Places em ambiente local';

    public function __construct(private readonly LeadProviderInterface $provider)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('Este comando só pode ser executado nos ambientes local ou testing.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($limit === false) {
            $this->error('A opção --limit deve ser um número inteiro.');

            return self::FAILURE;
        }

        try {
            $request = new LeadDiscoveryRequest(
                niche: (string) $this->argument('niche'),
                city: (string) $this->option('city'),
                state: (string) $this->option('state'),
                limit: $limit,
            );

            $leads = $this->provider->discover($request);
        } catch (LeadProviderException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Não foi possível concluir o teste do Google Places.');

            return self::FAILURE;
        }

        if ($leads === []) {
            $this->info('Nenhum estabelecimento encontrado.');

            return self::SUCCESS;
        }

        $this->table(
            ['Nome', 'Endereço', 'Telefone', 'Site', 'Avaliação', 'Qtd. avaliações'],
            array_map(static fn ($lead): array => [
                $lead->name,
                $lead->formattedAddress,
                $lead->phone ?? '-',
                $lead->website ?? '-',
                $lead->rating === null ? '-' : number_format($lead->rating, 1, ',', '.'),
                $lead->userRatingCount,
            ], $leads),
        );

        $this->info(count($leads).' estabelecimento(s) encontrado(s).');

        return self::SUCCESS;
    }
}
