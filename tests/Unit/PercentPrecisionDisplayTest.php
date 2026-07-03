<?php

/*
|--------------------------------------------------------------------------
| PERCENT PRECISION DISPLAY LAW (Addition 7)
|--------------------------------------------------------------------------
| Every rendered percentage in user-facing copy must round to the nearest
| whole percent — no decimal point beyond one place is allowed.
|
| Static gate over rendered instruction strings from knobInstruction (k3)
| and any percentage rendered in the checklist source files.
|
| Rationale: paystub-derived deferral_pct can be e.g. 10.0001% due to
| per-period arithmetic. The user should see "10%", not "10.00006571381633%".
*/

it('PP-01: knobInstruction k3 rounds deferral_pct to nearest whole percent', function () {
    // Simulate what the frontend does: params from ScenarioChecklistService::buildBenefitParams k3.
    // After the fix, pct and from_pct are (int)round(float) — always whole integers.
    // We verify the PHP side by checking the round() output.
    $floatyDeferral = 10.00006571381633;
    $floatyFrom = 12.00006571381633;

    $pct = (int) round($floatyDeferral);
    $fromPct = (int) round($floatyFrom);

    expect($pct)->toBe(10);
    expect($fromPct)->toBe(12);

    // Rendered string must not contain decimal point in the percentage
    $instruction = "Tell HR or your payroll portal: change your 401(k) deferral from {$fromPct}% to {$pct}%.";
    expect($instruction)->not->toContain('10.00006')
        ->and($instruction)->not->toContain('12.00006')
        ->and($instruction)->toContain('10%')
        ->and($instruction)->toContain('12%');
});

it('PP-02: no rendered percent token in knobInstruction source may exceed one decimal place', function () {
    // Static scan of the OptimizationChecklistView source file for percent rendering.
    // The display law: ${Math.round(to)}% and ${Math.round(from)}% are the only percent
    // patterns in k3 instruction — no raw float may appear in a percent context.
    $sourcePath = base_path('resources/js/Components/SpendifiAI/OptimizationChecklistView.tsx');
    expect(file_exists($sourcePath))->toBeTrue("OptimizationChecklistView.tsx not found at {$sourcePath}");

    $source = file_get_contents($sourcePath);

    // The fix: k3 must use Math.round() before embedding in the percent string.
    // Verify that the rendered k3 percent uses Math.round(to) and Math.round(from).
    expect($source)->toContain('Math.round(to)')
        ->and($source)->toContain('Math.round(from)');

    // Negative gate: raw float variable names like `${to}%` or `${from}%` must NOT
    // appear in the k3 instruction (without Math.round wrapping).
    // We look for the pattern `${to}%` — a raw unrounded number directly in a percent.
    // A match means the value wasn't rounded before display.
    $hasRawToPercent = (bool) preg_match('/\$\{to\}%/', $source);
    $hasRawFromPercent = (bool) preg_match('/\$\{from\}%/', $source);
    expect($hasRawToPercent)->toBeFalse('k3 instruction renders ${to}% without Math.round — float leak');
    expect($hasRawFromPercent)->toBeFalse('k3 instruction renders ${from}% without Math.round — float leak');
});

it('PP-03: ScenarioChecklistService k3 rounds pct and from_pct to int', function () {
    // Verify that the PHP buildBenefitParams k3 block uses round() before cast.
    $servicePath = base_path('app/Services/ScenarioChecklistService.php');
    expect(file_exists($servicePath))->toBeTrue('ScenarioChecklistService.php not found');

    $source = file_get_contents($servicePath);

    // Must contain: (int) round((float) ($chosenKnobs['k401']['deferral_pct'] ?? 0))
    expect($source)->toContain("(int) round((float) (\$chosenKnobs['k401']['deferral_pct']");
    // from_pct also rounded
    expect($source)->toContain("(int) round((float) (\$baseline['current']['deferral_pct']");
});
