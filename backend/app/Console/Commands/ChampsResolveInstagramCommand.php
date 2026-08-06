<?php

namespace App\Console\Commands;

use App\Champs\Contracts\InstagramResolverInterface;
use App\Champs\Exceptions\InstagramResolutionException;
use Illuminate\Console\Command;
use Throwable;

final class ChampsResolveInstagramCommand extends Command
{
    protected $signature = 'champs:resolve-instagram
        {website : URL publica do website da empresa}';

    protected $description = 'Descobre um perfil publico do Instagram vinculado ao website informado';

    public function __construct(private readonly InstagramResolverInterface $resolver)
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
            $profile = $this->resolver->resolve((string) $this->argument('website'));
        } catch (InstagramResolutionException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Nao foi possivel resolver o Instagram deste website.');

            return self::FAILURE;
        }

        if ($profile === null) {
            $this->info('Nenhum perfil publico do Instagram foi encontrado.');

            return self::SUCCESS;
        }

        $this->table(['Campo', 'Valor'], [
            ['Username', '@'.$profile->username],
            ['Perfil', $profile->profileUrl],
            ['Confianca', $profile->confidence],
            ['Pagina de origem', $profile->sourceUrl],
            ['Revisao necessaria', $profile->requiresReview ? 'sim' : 'nao'],
        ]);

        if ($profile->requiresReview) {
            $this->warn('Foram encontrados varios perfis. Revise o candidato antes de usa-lo.');
        }

        return self::SUCCESS;
    }
}
