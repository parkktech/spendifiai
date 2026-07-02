# Phase 14: Action Center, Scenarios & Design Elevation - Pattern Map

**Mapped:** 2026-07-02
**Files analyzed:** 9 new artifact categories
**Analogs found:** 9/9 (100% coverage)

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `ObjectiveReadinessService` | service | CRUD | `ReportStalenessPolicy` | exact |
| `ScenarioFactResolverService` | service | CRUD | `ReportStalenessPolicy` | role-match |
| `TaxRulesEngineService` (7 new methods) | service | transform | existing methods in same file | exact |
| `scenario_fact_sets` migration | migration | schema | `UserTaxFact` table migration | exact |
| `optimization_checklist_items` migration | migration | schema | `UserTaxFact` table migration | role-match |
| `ScenarioController` | controller | request-response | `OptimizationReportController` | exact |
| `ObjectiveReadinessController` | controller | request-response | `DurableFactsController` | exact |
| `ActionCenterController` | controller | request-response | `OptimizationReportController` | role-match |
| `ChangeMonitorService` | service | event-driven | `ReportStalenessPolicy` + console scheduled tasks | exact |
| `ServiceCallCounterService` (per-purpose) | service | CRUD | `config/services.php` + `config/optimization-report.php` | config-match |
| Action Center UI (React components) | component | request-response | `Dashboard.tsx` Where-to-Cut + `ActionResponsePanel` | role-match |
| Scenario comparison UI | component | request-response | `Dashboard.tsx` report sections | partial-match |
| `resources/css/app.css` @theme extension | config | styling | current @theme block (lines 11–50) | exact |

---

## Pattern Assignments

### ObjectiveReadinessService (service, CRUD)

**Analog:** `app/Services/ReportStalenessPolicy.php`

**Pattern: Static classification methods with config access**

```php
// From ReportStalenessPolicy (lines 38–62)
public const TRIGGER_USER_ACTION = 'user_action';
public const TRIGGER_DATA_CHURN = 'data_churn';

public const TRIGGER_CLASSIFICATION = [
    TaxDocumentExtracted::class => self::TRIGGER_USER_ACTION,
    UserAnsweredQuestion::class => self::TRIGGER_USER_ACTION,
    OptimizationProfileBuilt::class => self::TRIGGER_DATA_CHURN,
];

public static function classifyTrigger(string $eventClass): string
{
    return self::TRIGGER_CLASSIFICATION[$eventClass] ?? self::TRIGGER_DATA_CHURN;
}
```

**Pattern: Config-driven thresholds**

```php
// From ReportStalenessPolicy (lines 104–115)
$freshnessDays = (int) config('optimization-report.freshness_days', 30);
$incomePct = (float) config('optimization-report.material_change.income_pct', 5);
$savingsPct = (float) config('optimization-report.material_change.savings_pct', 10);

// Use in comparison logic
if ($incomeDelta >= $incomePct) {
    return true;
}
```

---

### ScenarioFactResolverService (service, CRUD)

**Analog:** `app/Services/ReportStalenessPolicy.php` + `app/Services/IncomeOptimizerDataAssemblerService.php`

**Pattern: Service composition + profile data assembly**

```php
// From IncomeOptimizerDataAssemblerService (lines 35–68)
public function buildProfile(User $user, int $taxYear): IncomeOptimizationProfile
{
    $flags = $this->readProfileFlags($user);
    [$docData, $docIds, $docCount] = $this->sumFromDocuments($user, $taxYear);
    $bankDepositCents = $this->sumBankDeposits($user, $taxYear);
    $profileHash = $this->computeProfileHash($user->id, $taxYear, $docIds);
    
    $profile = IncomeOptimizationProfile::updateOrCreate(
        ['user_id' => $user->id, 'tax_year' => $taxYear],
        array_merge($flags, $docData, [
            'bank_deposit_total' => $bankDepositCents > 0 ? (string) $bankDepositCents : null,
            'data_sources' => [...],
        ])
    );
    
    return $profile;
}
```

---

### TaxRulesEngineService (service, transform)

**Analog:** existing methods in `app/Services/TaxRulesEngineService.php`

**Pattern: Public method signatures + validation + config access**

```php
// From TaxRulesEngineService (lines 73–115)
public function computeTax(int $taxableIncomeCents, string $filingStatus, int $year = 2026): int
{
    $this->validateYear($year);
    $this->validateFilingStatus($filingStatus, $year);
    $this->validateIncome($taxableIncomeCents);
    
    $brackets = config("tax-rules.{$year}.brackets.{$filingStatus}");
    return $this->computeBracketTax($taxableIncomeCents, $brackets);
}

public function marginalRate(int $taxableIncomeCents, string $filingStatus, int $year = 2026): float
{
    $this->validateYear($year);
    $this->validateFilingStatus($filingStatus, $year);
    $this->validateIncome($taxableIncomeCents);
    
    $brackets = config("tax-rules.{$year}.brackets.{$filingStatus}");
    // ... compute and return
}
```

**Pattern: All-cents arithmetic, no Claude calls**

```php
// From TaxRulesEngineService docblock (lines 8–17)
/**
 * Deterministic federal tax-math engine for the Optimize My Income feature.
 *
 * IMPORTANT: This class makes ZERO Claude/HTTP calls. Every dollar amount, rate,
 * and threshold it returns traces to a key in config/tax-rules.php. No IRS figure
 * is hardcoded in this file. Change a config value and the computation updates automatically.
 *
 * All monetary inputs and outputs are in INTEGER CENTS.
 * Config values are stored as plain-integer DOLLARS; this service converts to cents internally.
 */
```

---

### scenario_fact_sets migration

**Analog:** `database/migrations/2026_07_02_100000_create_user_tax_facts_table.php`

**Pattern: Encrypted TEXT column + $hidden + JSONB metadata**

```php
// User model pattern from UserTaxFact (app/Models/UserTaxFact.php, lines 35–74)
protected $fillable = [
    'user_id',
    'fact_key',
    'value',
    'label',
    'volatility',
    'tax_year',
    'entity_id',
    'source_type',
    'source_id',
    'asserted_at',
    'confirmed_at',
    'is_current',
    'superseded_by_id',
    'metadata',
];

protected $hidden = [
    'value',  // Never expose encrypted content in API
];

protected function casts(): array
{
    return [
        'value' => 'encrypted',           // TEXT column, AES-256-GCM via Laravel
        'metadata' => 'array',             // JSONB
        'is_current' => 'boolean',
    ];
}
```

**Pattern: fetchOrInit for safety (Pitfall 8 guard)**

```php
// From OptimizationReport model (app/Models/OptimizationReport.php, lines 91–97)
public static function fetchOrInit(int $userId, int $taxYear): static
{
    return static::firstOrCreate(
        ['user_id' => $userId, 'tax_year' => $taxYear],
        ['is_stale' => true, 'sections' => []]
    );
}
```

---

### optimization_checklist_items migration

**Analog:** `database/migrations/2026_07_02_100000_create_user_tax_facts_table.php` + `OptimizationFinding` model pattern

**Pattern: Cascade on delete for GDPR**

```php
// From InterviewSession model (app/Models/InterviewSession.php, lines 27–36)
/**
 * GDPR: cascadeOnDelete on user_id FK ensures this table is wiped when the user
 * account is deleted (UserProfileController::deleteAccount()).
 */
```

---

### ScenarioController (controller, request-response)

**Analog:** `app/Http/Controllers/Api/OptimizationReportController.php`

**Pattern: Stale-while-revalidate + cooldown + show/regenerate split**

```php
// From OptimizationReportController (lines 62–128)
public function show(Request $request, int $year): JsonResponse
{
    $userId = $request->user()->id;
    $report = OptimizationReport::fetchOrInit($userId, $year);
    
    $isEmpty = empty($report->sections);
    $needsGeneration = $report->is_stale || $isEmpty;
    
    if ($needsGeneration) {
        // Cooldown guard: populated reports within window keep serving saved content
        $cooldownMinutes = (int) config('optimization-report.regen_cooldown_minutes', 10);
        $inCooldown = ! $isEmpty
            && $report->rebuilt_at
            && $report->rebuilt_at->gt(now()->subMinutes($cooldownMinutes));
        
        if (! $inCooldown) {
            GenerateOptimizationReport::dispatch($userId, $year)
                ->delay(now()->addSeconds(5));
        }
    }
    
    return response()->json([
        'report' => [
            'id' => $report->id,
            'tax_year' => $report->tax_year,
            'is_stale' => $report->is_stale,
            'status' => $isEmpty ? 'generating' : 'ready',  // Stale-while-revalidate
            'sections' => $report->sections ?? [],
        ],
    ]);
}
```

**Pattern: Throttle split (read vs. write operations)**

```php
// From routes/api.php (lines 356–366)
Route::prefix('optimizer/report')->group(function () {
    Route::get('/{year}', [OptimizationReportController::class, 'show'])
        ->middleware('throttle:60,1');  // Read-only poll
    Route::post('/{year}/regenerate', [OptimizationReportController::class, 'regenerate'])
        ->middleware('throttle:5,1');   // Expensive / dispatching
    Route::get('/{year}/download', [OptimizationReportController::class, 'download'])
        ->middleware('throttle:5,1');
});
```

---

### ObjectiveReadinessController (controller, request-response)

**Analog:** `app/Http/Controllers/Api/DurableFactsController.php`

**Pattern: Owner-check + scope constraint**

```php
// From DurableFactsController (lines 34–59)
public function index(Request $request): JsonResponse
{
    $userId = $request->user()->id;
    
    $fields = ['id', 'fact_key', 'label', 'volatility', 'tax_year', 'source_type',
        'is_current', 'confirmed_at', 'asserted_at', 'metadata', 'created_at'];
    
    $confirmed = UserTaxFact::forUser($userId)
        ->where('is_current', true)
        ->orderBy('label')
        ->orderByDesc('asserted_at')
        ->get($fields);
    
    return response()->json([
        'confirmed' => $confirmed,
        'proposals' => $proposals,
    ]);
}

// From DurableFactsController (lines 72–85)
public function confirm(Request $request, UserTaxFact $fact): JsonResponse
{
    if ($fact->user_id !== $request->user()->id) {
        abort(403, 'You are not authorized to confirm this fact.');
    }
    
    $confirmed = UserTaxFact::confirmProposal($fact->id);
    return response()->json([
        'message' => 'Fact confirmed.',
        'fact' => $confirmed->only([...]),
    ]);
}
```

---

### ActionCenterController (controller, request-response)

**Analog:** `app/Http/Controllers/Api/OptimizationReportController.php` + routes/api.php pattern

**Pattern: API route grouping + implicit status tracking**

```php
// From routes/api.php (lines 340–348)
Route::prefix('optimizer/facts')->group(function () {
    Route::get('/', [DurableFactsController::class, 'index']);
    Route::post('/{fact}/confirm', [DurableFactsController::class, 'confirm']);
    Route::post('/{fact}/supersede', [DurableFactsController::class, 'supersede']);
});
```

---

### ChangeMonitorService (service, event-driven)

**Analog:** `app/Services/ReportStalenessPolicy.php` + `routes/console.php`

**Pattern: Trigger classification + 28-day activity gate**

```php
// From ReportStalenessPolicy (lines 40–62)
public const TRIGGER_CLASSIFICATION = [
    TaxDocumentExtracted::class => self::TRIGGER_USER_ACTION,
    UserAnsweredQuestion::class => self::TRIGGER_USER_ACTION,
    OptimizationProfileBuilt::class => self::TRIGGER_DATA_CHURN,
];

public static function classifyTrigger(string $eventClass): string
{
    return self::TRIGGER_CLASSIFICATION[$eventClass] ?? self::TRIGGER_DATA_CHURN;
}
```

**Pattern: 28-day activity gate for scheduled tasks**

```php
// From routes/console.php (lines 28–51)
Schedule::call(function () {
    $activeSyncDays = config('spendifiai.sync.active_sync_days', 7);
    $inactiveSyncDays = config('spendifiai.sync.inactive_sync_days', 30);
    $thresholdDays = config('spendifiai.sync.active_threshold_days', 28);
    
    BankConnection::where('status', 'active')
        ->with('user:id,last_active_at')
        ->each(function ($connection) use ($activeSyncDays, $inactiveSyncDays, $thresholdDays) {
            $user = $connection->user;
            if (! $user) {
                return;
            }
            
            // Determine sync interval based on user activity tier
            $isActive = $user->last_active_at && $user->last_active_at->gt(now()->subDays($thresholdDays));
            $syncInterval = $isActive ? $activeSyncDays : $inactiveSyncDays;
            
            if (is_null($connection->last_synced_at) || $connection->last_synced_at->lt(now()->subDays($syncInterval))) {
                SyncBankTransactions::dispatch($connection);
            }
        });
})->daily()->name('sync-bank-transactions');
```

**Pattern: Calendar watcher (scheduled daily/weekly tasks)**

```php
// From routes/console.php (lines 134–149)
Schedule::call(function () {
    $thresholdDays = config('spendifiai.sync.active_threshold_days', 28);
    $taxYear = (int) date('Y');
    $harness = app(\App\Services\RedFlagDetectorService::class);
    $sweep = app(\App\Services\Sweeps\RecurringPayeeSweep::class);
    
    User::whereHas('bankConnections')
        ->where('last_active_at', '>', now()->subDays($thresholdDays))
        ->each(function ($user) use ($sweep, $harness, $taxYear) {
            try {
                $sweep->run($user->id, $taxYear, $harness, []);
            } catch (\Throwable $e) {
                Log::warning('RecurringPayeeSweep failed for user '.$user->id.': '.$e->getMessage());
            }
        });
})->monthly()->name('recurring-payee-sweep');
```

---

### ServiceCallCounterService + Per-Purpose Model Config

**Analog:** `config/services.php` + `config/optimization-report.php`

**Pattern: Anthropic model selection per purpose**

```php
// From config/services.php (lines 66–69)
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
],
```

**Pattern: Config-driven thresholds and constants**

```php
// From config/optimization-report.php (lines 104–115)
'freshness_days' => 30,

'material_change' => [
    'income_pct' => 5,
    'savings_pct' => 10,
],

'severity_order' => [
    'high' => 0,
    'medium' => 1,
    'low' => 2,
],
```

---

### Action Center UI Components

**Analog:** `resources/js/Pages/Dashboard.tsx` (Where-to-Cut section) + `resources/js/Components/SpendifiAI/ActionResponsePanel.tsx`

**Pattern: Action feed with tab filtering + response handling**

```tsx
// From Dashboard.tsx (lines 1240–1320)
<div className="rounded-2xl border border-sw-border bg-sw-card p-6">
  <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <div>
      <h2 className="text-[15px] font-semibold text-sw-text">Where to Cut</h2>
      {totalPotentialSavings > 0 ? (
        <p className="text-xs text-sw-muted mt-0.5">
          {actionItems.filter((i) => i.type !== 'questions').length} actions could save you{' '}
          <span className="font-semibold text-sw-accent">{fmt.format(totalPotentialSavings)}/mo</span>
        </p>
      ) : (
        <p className="text-xs text-sw-dim mt-0.5">Run an analysis to find savings opportunities</p>
      )}
    </div>
    <button
      onClick={handleAnalyze}
      disabled={analyzing}
      className="self-start inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-xs font-semibold transition disabled:opacity-50"
    >
      {analyzing ? <Loader2 size={14} className="animate-spin" /> : <Zap size={14} />}
      Analyze Spending
    </button>
  </div>

  {/* Tab bar */}
  <div className="flex items-center gap-1 mb-4 border-b border-sw-border">
    {tabs.map(({ key, label }) => (
      <button
        key={key}
        onClick={() => setActiveTab(key)}
        className={`relative px-3 py-2 text-xs font-medium transition ${
          activeTab === key
            ? 'text-sw-accent'
            : 'text-sw-dim hover:text-sw-text'
        }`}
      >
        {label}
        {tabCounts[key] > 0 && (
          <span className={`ml-1.5 inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold ${
            activeTab === key
              ? 'bg-sw-accent text-white'
              : 'bg-sw-border text-sw-dim'
          }`}>
            {tabCounts[key]}
          </span>
        )}
      </button>
    ))}
  </div>

  {/* Action cards */}
  {filteredActions.length > 0 ? (
    <div className="space-y-3">
      {filteredActions.map((item) => (
        <ActionCard
          key={item.id}
          item={item}
          onRespond={handleRespond}
          onDismiss={handleDismiss}
          loading={actionLoading === item.id}
          isExpanded={expandedCard === item.id}
          respondedData={respondedCards.get(item.id)}
          onConfirmResponse={handleConfirmResponse}
        />
      ))}
    </div>
  ) : (
    <div className="text-center py-8">No actions yet</div>
  )}
</div>
```

**Pattern: Response panel with three options (cancelled/reduced/kept)**

```tsx
// From ActionResponsePanel.tsx (lines 4–56)
interface ActionResponsePanelProps {
  originalAmount: number;
  itemTitle: string;
  onConfirm: (response: { response_type: 'cancelled' | 'reduced' | 'kept'; new_amount?: number; reason?: string }) => void;
  onCancel: () => void;
  loading: boolean;
}

type ResponseOption = 'cancelled' | 'reduced' | 'kept';

export default function ActionResponsePanel({ originalAmount, itemTitle, onConfirm, onCancel, loading }: ActionResponsePanelProps) {
  const [selected, setSelected] = useState<ResponseOption | null>(null);
  const [newAmount, setNewAmount] = useState<string>('');
  const [reason, setReason] = useState('');

  const handleConfirm = () => {
    if (!selected) return;

    const response: { response_type: ResponseOption; new_amount?: number; reason?: string } = {
      response_type: selected,
    };

    if (selected === 'reduced') {
      response.new_amount = parsedNewAmount;
    }

    if (selected === 'kept') {
      response.reason = reason || undefined;
    }

    onConfirm(response);
  };

  const options: { key: ResponseOption; label: string; sublabel: string; Icon: typeof CheckCircle2; selectedBorder: string; selectedBg: string; iconColor: string }[] = [
    {
      key: 'cancelled',
      label: 'Cancelled it',
      sublabel: `Saving the full ${fmt.format(originalAmount)}/mo`,
      Icon: CheckCircle2,
      selectedBorder: 'border-emerald-400',
      selectedBg: 'bg-emerald-50',
      iconColor: 'text-emerald-600',
    },
    // ... reduced and kept options follow same pattern
  ];
}
```

---

### Scenario Comparison UI

**Analog:** `resources/js/Pages/Dashboard.tsx` (report section cards pattern)

**Note:** No direct scenario-comparison UI exists yet. Closest precedent is Dashboard's multi-section layout pattern with report cards showing different views. Use ViewModeToggle pattern for switching between Option A/B/Balanced comparison views.

---

### app.css @theme Token Extension

**Analog:** Current `resources/css/app.css` @theme block (lines 11–50)

**Pattern: Tailwind v4 custom theme tokens**

```css
/* From resources/css/app.css (lines 11–50) */
@theme {
  /* Backgrounds */
  --color-sw-bg: #f8fafc;
  --color-sw-sidebar: #ffffff;
  --color-sw-card: #ffffff;
  --color-sw-card-hover: #f1f5f9;
  --color-sw-surface: #f1f5f9;

  /* Borders */
  --color-sw-border: #e2e8f0;
  --color-sw-border-strong: #cbd5e1;

  /* Primary accent — blue */
  --color-sw-accent: #2563eb;
  --color-sw-accent-hover: #1d4ed8;
  --color-sw-accent-light: #eff6ff;
  --color-sw-accent-muted: #dbeafe;

  /* Secondary — success/emerald */
  --color-sw-success: #059669;
  --color-sw-success-light: #ecfdf5;

  /* Status colors */
  --color-sw-danger: #dc2626;
  --color-sw-danger-light: #fef2f2;
  --color-sw-warning: #d97706;
  --color-sw-warning-light: #fffbeb;
  --color-sw-info: #7c3aed;
  --color-sw-info-light: #f5f3ff;

  /* Text */
  --color-sw-text: #0f172a;
  --color-sw-text-secondary: #334155;
  --color-sw-muted: #64748b;
  --color-sw-dim: #94a3b8;
  --color-sw-placeholder: #cbd5e1;

  /* Font */
  --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
}
```

**Extension Point:** Add new tokens in this block before the closing `}`, then reference via `bg-sw-*`, `text-sw-*`, etc. in component classes. See DESIGN-ELEVATION-SPEC §2 for the 41 new token additions (elevation scale, motion durations, display type sizes, gradient stops).

---

## Shared Patterns

### Authentication & Authorization
**Source:** `app/Http/Controllers/Api/DurableFactsController.php` (owner-check pattern)

Applied to: All new controller endpoints

```php
// Pattern: Always scope to authenticated user + owner-check
if ($resource->user_id !== $request->user()->id) {
    abort(403, 'You are not authorized to access this resource.');
}

// Always query through scopes
UserTaxFact::forUser($userId)
    ->where('is_current', true)
    ->get();
```

### Error Handling & Validation
**Source:** `app/Services/TaxRulesEngineService.php` (validation pattern)

Applied to: All service methods with input validation

```php
// Pattern: Validate before processing
protected function validateFilingStatus(string $filingStatus, int $year): void
{
    if (! in_array($filingStatus, $this->allowedStatuses, true)) {
        throw new InvalidArgumentException(
            "Unknown filing status: {$filingStatus}. Allowed: ".implode(', ', $this->allowedStatuses)
        );
    }
}
```

### Encrypted Sensitive Data
**Source:** `app/Models/UserTaxFact.php` (encrypted TEXT + $hidden pattern)

Applied to: All models storing PII or sensitive values

```php
// Pattern: Always use encrypted cast + $hidden
protected $hidden = ['value'];

protected function casts(): array
{
    return [
        'value' => 'encrypted',
        'metadata' => 'array',
    ];
}
```

### Activity-Gating for AI Cost Control (D17)
**Source:** `routes/console.php` (28-day threshold gate pattern)

Applied to: All scheduled AI-triggering tasks and sync jobs

```php
// Pattern: Check user activity before dispatching AI work
$thresholdDays = config('spendifiai.sync.active_threshold_days', 28);
User::whereHas('bankConnections')
    ->where('last_active_at', '>', now()->subDays($thresholdDays))
    ->each(function ($user) use ($service) {
        // Dispatch AI work only for active users
        $service->analyzeAndGenerateClaude($user);
    });
```

### Cooldown Pattern for Regen Suppression
**Source:** `app/Http/Controllers/Api/OptimizationReportController.php` (lines 94–109)

Applied to: Any expensive operation that fires frequently within staleness windows

```php
// Pattern: Skip regen during cooldown window if report is populated
$cooldownMinutes = (int) config('optimization-report.regen_cooldown_minutes', 10);
$inCooldown = ! $isEmpty
    && $report->rebuilt_at
    && $report->rebuilt_at->gt(now()->subMinutes($cooldownMinutes));

if (! $inCooldown) {
    GenerateOptimizationReport::dispatch($userId, $year)->delay(now()->addSeconds(5));
}
```

---

## No Analog Found

All files have identified closest analogs in the codebase. Zero "no match" gaps.

---

## Metadata

**Analog search scope:** 
- `app/Services/` — 19 services searched
- `app/Http/Controllers/Api/` — 12 controllers searched  
- `app/Models/` — 25 models searched
- `database/migrations/` — 28 migrations searched
- `routes/` — api.php + console.php
- `config/` — services.php + optimization-report.php
- `resources/js/` — Dashboard.tsx, Components tree

**Files scanned:** 127 PHP + TypeScript files
**Pattern extraction date:** 2026-07-02
