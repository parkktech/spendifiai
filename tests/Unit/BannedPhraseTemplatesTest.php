<?php

/**
 * BannedPhraseTemplatesTest — closes the deterministic-copy liability gap (SAFE-01).
 *
 * Iterates every string leaf of the Phase-14 deterministic-copy config arrays and
 * asserts none contains a banned assertive / guaranteed-savings phrase. Banned
 * terms live HERE (test data), never inlined into any template file. Matching is
 * case-insensitive. Config arrays not yet present are gracefully skipped so the
 * test is green at this wave and auto-covers later-added copy (14-09 monitor /
 * DOC-05) once it lands.
 */

// Banned assertive / guaranteed-savings phrases (SAFE-01 + educational-frame).
// Phrase-level (not bare words) to avoid false positives on "you may qualify".
function bannedPhraseList(): array
{
    return [
        'you should',
        'you must',
        'you qualify',
        'you owe',
        'you are entitled',
        'you will save',
        'will save you',
        'guaranteed',
        'guarantee',
        'free money',
        'risk-free',
        'no risk',
        'file jointly',
        'file separately',
    ];
}

// Phase-14 deterministic-copy config keys to scan. Missing arrays are skipped.
function phase14CopyConfigKeys(): array
{
    return [
        'optimization-objectives.question_templates',
        'optimizer-scenarios.tradeoff_templates',
        'optimizer-scenarios.checklist_templates',
        'optimization-report.finding_narration_templates',
        // Later-wave copy (14-09 monitor / DOC-05) — auto-covered once added:
        'optimization-report.change_monitor_templates',
        'optimization-report.doc_request_templates',
        'optimizer-scenarios.monitor_templates',
    ];
}

// Recursively collect every string leaf as "path => value".
function collectStringLeaves(mixed $node, string $path, array &$out): void
{
    if (is_array($node)) {
        foreach ($node as $key => $value) {
            collectStringLeaves($value, $path === '' ? (string) $key : "{$path}.{$key}", $out);
        }

        return;
    }

    if (is_string($node)) {
        $out[$path] = $node;
    }
}

it('has at least one present Phase-14 copy config array to scan (sanity)', function () {
    $present = collect(phase14CopyConfigKeys())
        ->filter(fn ($key) => is_array(config($key)))
        ->values();

    expect($present)->not->toBeEmpty();
});

it('contains no banned assertive / guaranteed-savings phrase in any present Phase-14 config template string', function () {
    $banned = bannedPhraseList();
    $violations = [];

    foreach (phase14CopyConfigKeys() as $configKey) {
        $arr = config($configKey);
        if (! is_array($arr)) {
            continue; // gracefully skip config arrays not yet present
        }

        $leaves = [];
        collectStringLeaves($arr, $configKey, $leaves);

        foreach ($leaves as $path => $text) {
            $haystack = mb_strtolower($text);
            foreach ($banned as $phrase) {
                if (str_contains($haystack, mb_strtolower($phrase))) {
                    $violations[] = "[{$phrase}] at {$path}: {$text}";
                }
            }
        }
    }

    expect($violations)->toBe([], "Banned phrase(s) found in Phase-14 template copy:\n".implode("\n", $violations));
});
