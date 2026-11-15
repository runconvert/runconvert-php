<?php

declare(strict_types=1);

// Live tests against the real API. Needs RUNCONVERT_API_KEY; converts one small text file (about 2 credits).
require __DIR__ . '/bootstrap.php';

use RunConvert\{RunConvert, RunConvertException};

if (!getenv('RUNCONVERT_API_KEY')) {
    echo "skipped: set RUNCONVERT_API_KEY to run live tests\n";
    exit(0);
}

test('me() and operations', function () {
    check(!empty((new RunConvert())->me()['credits']));
    $rc = new RunConvert('');
    check(in_array('pdf', array_column($rc->operations->forInput('TXT')['outputs'], 'format'), true));
    check(count($rc->operations->forPair('mp4', 'mp3')['engines']) > 0);
});

test('convert(): text file to PDF by upload', function () {
    $dir = sys_get_temp_dir() . '/rc-php-live-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/sdk-live-test.txt", "RunConvert PHP SDK live test\nLine two\n\nCafé – 你好 – Привет\n");
    $seen = [];
    $res = (new RunConvert())->convert("$dir/sdk-live-test.txt", 'pdf', ['out_dir' => "$dir/out", 'tag' => 'sdk-php-live-test', 'timeout' => 150, 'on_update' => function ($j) use (&$seen) { $seen[] = $j['status']; }]);
    $pdf = file_get_contents($res->saved[0]);
    same(substr($pdf, 0, 5), '%PDF-');
    echo "  job {$res->job['id']}: " . implode(' → ', array_unique($seen)) . ", {$res->job['credits_used']} credit(s), " . strlen($pdf) . " bytes\n";
});

test('a bad key gives a RunConvertException', function () {
    $e = throws(RunConvertException::class, fn () => (new RunConvert('rc_live_invalid', maxRetries: 0))->me());
    same($e->getStatus(), 401);
});

exit(run_tests());
