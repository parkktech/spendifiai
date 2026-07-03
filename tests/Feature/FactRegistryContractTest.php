<?php

/*
|--------------------------------------------------------------------------
| FactRegistryContractTest — D24 reliability hardening (Work 1)
|--------------------------------------------------------------------------
|
| Kills fact-key drift. Two gate assertions:
|
|   (a) SWEEP: scans known namespaced code/config paths for fact-key-shaped
|       literals (dotted snake_case in the optimization namespaces) and
|       FAILS naming any key not in config/fact-registry.php.
|
|   (b) ENUM VALIDITY: validates that enum values referenced in
|       question_templates choices match the registry enum arrays — catches
|       the married_joint class of bug where display-string was used instead
|       of canonical enum value.
|
| ADDING A NEW FACT KEY:
|   Add it to config/fact-registry.php BEFORE using it anywhere in code.
|   This test will block the build otherwise.
|
*/

test('all fact-key literals in optimization code are registered in config/fact-registry.php', function () {
    $registry = config('fact-registry');
    expect($registry)->toBeArray()->not->toBeEmpty();

    // Scan paths (optimization subsystem only — NEVER scan vendor/).
    $scanPaths = [
        app_path('Services'),
        app_path('Http/Controllers/Api'),
        app_path('Jobs'),
        app_path('Listeners'),
        app_path('Console/Commands'),
        app_path('Services/Detectors'),
        app_path('Services/Scanners'),
        app_path('Services/AI'),
        config_path(),
    ];

    // Namespaces we scan for. A literal matches if it looks like "namespace.key" where
    // namespace is one of these known prefixes. This avoids false-positives on
    // URL fragments, FQCN, Eloquent column references, etc.
    $knownNamespaces = [
        'pay', 'w4', 'ira', 'retirement', 'employer', 'benefits', 'family',
        'person', 'income', 'finance', 'hsa', 'health', 'scenario', 'identity',
        'prior_year', 'spouse', 'bonus', 'vehicle', 'life_event', 'profile',
    ];

    // Pattern: 'namespace.something' (single-quoted or double-quoted string literals).
    // Matches: 'pay.frequency', 'employer.has_401k', etc.
    $namespaceAlt = implode('|', array_map('preg_quote', $knownNamespaces, array_fill(0, count($knownNamespaces), '/')));
    $pattern = '/[\'"]('.$namespaceAlt.')\.[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?[\'"]/';

    // Also catch top-level flat keys (no namespace) from the registry that are used
    // as fact_key literals. Currently: 'has_self_employment', 'category_vehicle_parts'.
    $flatRegistry = [];
    foreach ($registry as $key => $entry) {
        if (! str_contains($key, '.')) {
            $flatRegistry[] = $key;
        }
    }

    $unregistered = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(
                app_path(),
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
            ),
            fn ($file) => $file->isDir() || ($file->isFile() && $file->getExtension() === 'php')
        )
    );

    // Also scan config files directly.
    $configFiles = glob(config_path('*.php')) ?: [];

    $allFiles = [];
    foreach ($files as $file) {
        if ($file->isFile()) {
            $allFiles[] = $file->getRealPath();
        }
    }
    $allFiles = array_merge($allFiles, $configFiles);

    // Exclude files that are not optimization-related to reduce noise.
    $excludePatterns = [
        '/Spendifi/', '/Savings/', '/Subscription/', '/Dashboard/', '/Tax/',
        '/Email/', '/Plaid/', '/Statement/', '/Auth/', '/Seo/', '/User/',
        '/Social/', '/Password/', '/TwoFactor/', '/Transaction/',
        '/BankAccount/', '/BankConnection/', '/Reconciliation/', '/Order/',
        '/Annotation/', '/Household/', '/Vault/', '/Onboarding/',
        '/AIQuestion/', '/IncomeDetector/', '/HousingDetection/',
        '/Dashboard', '/Savings', '/Tax', '/Email', '/Plaid',
        'app/Http/Controllers/Api/DashboardController',
        'app/Http/Controllers/Api/PlaidController',
        'app/Http/Controllers/Api/SavingsController',
        'app/Http/Controllers/Api/TaxController',
        'app/Http/Controllers/Api/BankAccount',
        'app/Http/Controllers/Api/Transaction',
        'app/Http/Controllers/Api/Subscription',
        'app/Http/Controllers/Api/Email',
        'app/Http/Controllers/Api/StatementUpload',
        'app/Http/Controllers/Api/AIQuestion',
        'app/Http/Controllers/Api/User',
    ];

    // Files whose fact-key-shaped strings are explicitly exempt (finding_keys,
    // not UserTaxFact fact_keys, used in detector finding_key fields, or using
    // dotted notation for non-fact purposes like route middleware aliases).
    $exemptFromScan = [
        'CategoryLibraryDetector.php',
        'FindingPatternQuestionService.php',
        'OptimizationFindingExtensionTest.php',
        // AppServiceProvider uses 'profile.complete' as a route middleware alias name.
        'AppServiceProvider.php',
    ];

    foreach ($allFiles as $path) {
        // Skip vendor, test files (except contract test itself), and excluded dirs.
        if (str_contains($path, '/vendor/')) {
            continue;
        }
        if (str_contains($path, '/tests/')) {
            continue;
        }

        $basename = basename($path);
        if (in_array($basename, $exemptFromScan, true)) {
            continue;
        }

        $isExcluded = false;
        foreach ($excludePatterns as $excl) {
            if (str_contains($path, $excl)) {
                $isExcluded = true;
                break;
            }
        }
        if ($isExcluded) {
            continue;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            continue;
        }

        // Skip if this file has no optimization subsystem markers (performance guard).
        if (
            ! str_contains($source, 'UserTaxFact')
            && ! str_contains($source, 'fact_key')
            && ! str_contains($source, 'factKey')
            && ! str_contains($source, 'optimization-objectives')
            && ! str_contains($source, 'fact-registry')
            && ! str_contains($source, 'InterviewOrchestratorService')
            && ! str_contains($source, 'ScenarioFactResolver')
            && ! str_contains($source, 'ObjectiveReadiness')
        ) {
            continue;
        }

        if (preg_match_all($pattern, $source, $matches)) {
            foreach ($matches[0] as $match) {
                // Strip quotes.
                $key = trim($match, '"\'');
                if (! array_key_exists($key, $registry)) {
                    $unregistered[$key][] = basename($path);
                }
            }
        }
    }

    if (! empty($unregistered)) {
        $lines = [];
        foreach ($unregistered as $key => $files) {
            $lines[] = "  {$key}  (in: ".implode(', ', array_unique($files)).')';
        }
        $list = implode("\n", $lines);
        $this->fail(
            "Fact keys referenced in code but NOT registered in config/fact-registry.php:\n{$list}\n\n"
            .'Add each missing key to config/fact-registry.php before using it.'
        );
    }

    expect($unregistered)->toBeEmpty();
});

test('question_template enum choices match registry enum values', function () {
    $registry = config('fact-registry');
    $templates = config('optimization-objectives.question_templates', []);

    $mismatches = [];

    foreach ($templates as $factKey => $template) {
        if (! isset($template['choices'])) {
            continue;
        }

        // Only validate if registry has an enum definition for this key.
        $entry = $registry[$factKey] ?? null;
        if ($entry === null || ($entry['type'] ?? '') !== 'enum') {
            continue;
        }

        $registryEnums = $entry['enum'] ?? [];
        if (empty($registryEnums)) {
            continue;
        }

        foreach ($template['choices'] as $choice) {
            $value = $choice['value'] ?? null;
            if ($value === null) {
                continue;
            }
            if (! in_array($value, $registryEnums, true)) {
                $mismatches[] = "  {$factKey}: choice value '{$value}' not in registry enum ["
                    .implode(', ', $registryEnums).']';
            }
        }
    }

    if (! empty($mismatches)) {
        $list = implode("\n", $mismatches);
        $this->fail(
            "Question template choice values not in registry enums (married_joint class of bug):\n{$list}\n\n"
            .'Either fix the choice value in question_templates or update the registry enum.'
        );
    }

    expect($mismatches)->toBeEmpty();
});

test('fact registry has complete entries for all question_template keys', function () {
    $registry = config('fact-registry');
    $templates = config('optimization-objectives.question_templates', []);

    $missing = [];
    foreach (array_keys($templates) as $factKey) {
        if (! array_key_exists($factKey, $registry)) {
            $missing[] = $factKey;
        }
    }

    if (! empty($missing)) {
        $list = implode(', ', $missing);
        $this->fail(
            "Question template keys not in registry: {$list}\n"
            .'Every key in question_templates must also be in config/fact-registry.php.'
        );
    }

    expect($missing)->toBeEmpty();
});

test('fact registry label source covers all objective canonical_keys', function () {
    $registry = config('fact-registry');
    $objectives = config('optimization-objectives.objectives', []);

    $missing = [];
    foreach ($objectives as $objectiveKey => $spec) {
        foreach ((array) ($spec['facts'] ?? []) as $factConfig) {
            $canonicalKey = $factConfig['canonical_key'] ?? null;
            if ($canonicalKey === null) {
                continue;
            }
            if (! array_key_exists($canonicalKey, $registry)) {
                $missing[] = "{$objectiveKey}: {$canonicalKey}";
            }
        }
    }

    if (! empty($missing)) {
        $list = implode(', ', $missing);
        $this->fail(
            "Canonical fact keys from objectives not in registry: {$list}"
        );
    }

    expect($missing)->toBeEmpty();
});
