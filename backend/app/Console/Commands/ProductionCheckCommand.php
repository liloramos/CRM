<?php

namespace App\Console\Commands;

use App\Services\Operational\ProductionReadinessChecker;
use Illuminate\Console\Command;

class ProductionCheckCommand extends Command
{
    protected $signature = 'app:production-check';

    protected $description = 'Checks production readiness without printing secrets or contacting external services.';

    public function handle(ProductionReadinessChecker $checker): int
    {
        $checks = $checker->evaluate();

        $this->table(
            ['Status', 'Verificação', 'Resultado seguro'],
            collect($checks)->map(fn (array $check): array => [$check['status'], $check['check'], $check['message']])->all(),
        );

        if ($checker->hasFailures($checks)) {
            $this->error('Readiness reprovada: corrija os itens FAIL antes do go-live.');

            return self::FAILURE;
        }

        $this->info('Readiness concluída sem itens FAIL. Revise os avisos antes do go-live.');

        return self::SUCCESS;
    }
}
