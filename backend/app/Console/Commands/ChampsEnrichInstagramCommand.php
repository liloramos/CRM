<?php

namespace App\Console\Commands;

use App\Champs\Contracts\InstagramEnricherInterface;
use App\Champs\Exceptions\InstagramEnrichmentException;
use Illuminate\Console\Command;
use Throwable;

final class ChampsEnrichInstagramCommand extends Command
{
    protected $signature = 'champs:enrich-instagram
        {username : Username previamente descoberto no website da empresa}';

    protected $description = 'Testa o Meta Business Discovery para um username conhecido';

    public function __construct(private readonly InstagramEnricherInterface $enricher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('Este comando so pode ser executado nos ambientes local ou testing.');

            return self::FAILURE;
        }

        try {
            $profile = $this->enricher->enrich((string) $this->argument('username'));
        } catch (InstagramEnrichmentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Nao foi possivel concluir o Business Discovery.');

            return self::FAILURE;
        }

        if (! $profile->enrichable) {
            $this->warn('A conta nao e profissional ou nao esta disponivel para Business Discovery.');

            return self::SUCCESS;
        }

        $this->table(['Campo', 'Valor'], [
            ['ID', $profile->id ?? '-'],
            ['Username', '@'.$profile->username],
            ['Nome', $profile->name ?? '-'],
            ['Website', $profile->website ?? '-'],
            ['Seguidores', $profile->followersCount ?? '-'],
            ['Publicacoes', $profile->mediaCount ?? '-'],
            ['Perfil profissional', $profile->isProfessional ? 'sim' : 'nao'],
        ]);

        return self::SUCCESS;
    }
}
