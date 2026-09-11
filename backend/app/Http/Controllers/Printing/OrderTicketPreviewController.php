<?php

namespace App\Http\Controllers\Printing;

use App\Http\Controllers\Api\Concerns\ResolvesOperationalCompany;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PrintJob;
use App\Services\Printing\PrintWorkflowService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class OrderTicketPreviewController extends Controller
{
    use ResolvesOperationalCompany;

    public function __invoke(Request $request, Order $order, PrintWorkflowService $printing): Response
    {
        $company = $this->resolveCompany($request);
        $this->assertOrderBelongsToCompany($order, $company);

        $jobId = $request->query('print_job_id');

        try {
            $job = $jobId
                ? PrintJob::query()->where('order_id', $order->id)->findOrFail($jobId)
                : $printing->generateTicket($order, $request->user(), [
                    'target_audience' => $request->query('target_audience', 'kitchen'),
                ]);
        } catch (DomainException $exception) {
            return $this->htmlResponse(
                $this->errorHtml('Não foi possível carregar o documento.', $exception->getMessage()),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (Throwable $exception) {
            report($exception);

            $message = $order->status === Order::STATUS_DRAFT
                ? 'A comanda permanece aberta. Tente novamente ou solicite apoio técnico.'
                : 'A venda continua concluída. Tente novamente ou solicite apoio técnico.';

            return $this->htmlResponse(
                $this->errorHtml('Não foi possível carregar o documento.', $message),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->htmlResponse($job->html_content ?? '', Response::HTTP_OK);
    }

    private function htmlResponse(string $html, int $status): Response
    {
        return response($html, $status)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function errorHtml(string $title, string $message): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #111827;
            color: #f8fafc;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        main {
            width: min(420px, calc(100vw - 32px));
            border: 1px solid rgba(248, 250, 252, 0.18);
            border-radius: 14px;
            padding: 24px;
            background: #1f2937;
        }
    </style>
</head>
<body>
    <main>
        <h1>{$safeTitle}</h1>
        <p>{$safeMessage}</p>
    </main>
</body>
</html>
HTML;
    }
}
