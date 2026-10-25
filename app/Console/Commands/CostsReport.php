<?php

namespace App\Console\Commands;

use App\Models\LlmCall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CostsReport extends Command
{
    protected $signature = 'costs:report {--job=}';

    protected $description = 'Стоимость вызовов модели: по моделям и ролям';

    public function handle(): int
    {
        if ($job = $this->option('job')) {
            $calls = LlmCall::where('card_job_id', $job)->orderBy('id')->get();
            if ($calls->isEmpty()) {
                $this->warn('Вызовов для этой задачи нет');

                return self::SUCCESS;
            }

            $this->table(
                ['model', 'role', 'in', 'out', 'cost', 'ms'],
                $calls->map(fn (LlmCall $call) => [
                    $call->model, $call->role, $call->input_tokens,
                    $call->output_tokens, $this->money($call->cost_micros), $call->duration_ms,
                ])->all()
            );

            $this->line('Итого: '.$this->money($calls->sum('cost_micros')));

            return self::SUCCESS;
        }

        $rows = DB::table('llm_calls')
            ->select('model', 'role', DB::raw('count(*) as calls'),
                DB::raw('sum(input_tokens) as input_tokens'),
                DB::raw('sum(output_tokens) as output_tokens'),
                DB::raw('sum(cost_micros) as cost_micros'))
            ->groupBy('model', 'role')
            ->orderBy('model')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('Вызовов модели ещё не было');

            return self::SUCCESS;
        }

        $this->table(
            ['model', 'role', 'calls', 'in', 'out', 'cost'],
            $rows->map(fn ($row) => [
                $row->model, $row->role, $row->calls, $row->input_tokens,
                $row->output_tokens, $this->money((int) $row->cost_micros),
            ])->all()
        );

        return self::SUCCESS;
    }

    private function money(int $micros): string
    {
        return intdiv($micros, 1_000_000).'.'.str_pad((string) ($micros % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
