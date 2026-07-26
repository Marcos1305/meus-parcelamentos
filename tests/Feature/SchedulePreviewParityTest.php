<?php

use App\Actions\BuildInstallmentSchedule;
use App\Actions\CalculateFirstDueDate;
use App\Actions\SplitIntoInstallments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

it('o preview no cliente concorda com o calculo do servidor', function (
    int $total,
    int $count,
    string $purchasedAt,
    int $closingDay,
    int $dueDay,
): void {
    $server = (new BuildInstallmentSchedule(new SplitIntoInstallments, new CalculateFirstDueDate))
        ->handle($total, $count, CarbonImmutable::parse($purchasedAt), $closingDay, $dueDay);

    $expected = array_map(fn (array $row): array => [
        'number' => $row['number'],
        'amount_cents' => $row['amount_cents'],
        'due_date' => $row['due_date']->toDateString(),
    ], $server);

    $script = <<<JS
    import { buildSchedulePreview } from './resources/js/lib/installments.ts';
    console.log(JSON.stringify(buildSchedulePreview({$total}, {$count}, '{$purchasedAt}', {$closingDay}, {$dueDay})));
    JS;

    $result = Process::path(base_path())->run([
        'node', '--experimental-strip-types', '--input-type=module', '--eval', $script,
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(json_decode(trim($result->output()), true))->toBe($expected);
})->with([
    [100000, 3, '2026-03-02', 3, 10],
    [100000, 3, '2026-03-05', 3, 10],
    [100000, 3, '2026-03-02', 28, 5],
    [30000, 3, '2025-12-15', 10, 31],
    [30000, 3, '2026-11-02', 3, 10],
    [50000, 1, '2026-03-02', 3, 10],
    [99999, 7, '2026-01-31', 15, 20],
]);
