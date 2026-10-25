<?php

namespace App\Console\Commands;

use App\Services\Evals\EvalRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RunEvals extends Command
{
    protected $signature = 'evals:run {--all} {--document=}';

    protected $description = 'Метрики качества генерации на эталонном наборе';

    public function handle(EvalRunner $runner): int
    {
        $report = $runner->run(
            (bool) $this->option('all'),
            $this->option('document') ? (string) $this->option('document') : null,
        );
        $this->render($report);

        $path = 'evals/eval-'.now()->format('Ymd\THis\Z').'.json';
        Storage::disk('local')->put($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->newLine();
        $this->line('Отчёт сохранён: storage/app/private/'.$path);

        return self::SUCCESS;
    }

    private function render(array $report): void
    {
        $rows = array_map(static function (array $row): array {
            if (isset($row['skipped'])) {
                return [$row['document'], '—', '—', '—', $row['skipped']];
            }

            return [
                $row['document'],
                number_format($row['characteristic_match'], 2),
                number_format($row['citation_support'], 2),
                $row['judge_supported'] ? 'ok' : 'no',
                'attempts: '.$row['attempts'].', confidence: '.$row['confidence'],
            ];
        }, $report['documents']);

        $this->table(['документ', 'chars', 'cites', 'judge', 'детали'], $rows);

        if ($report['averages'] !== []) {
            $this->line(sprintf(
                'Средние: characteristics %s, citations %s, judge %s',
                number_format($report['averages']['characteristic_match'], 2),
                number_format($report['averages']['citation_support'], 2),
                number_format($report['averages']['judge_supported'], 2),
            ));
        }
    }
}
