# Phase 11: Red-Flag Detection, Guided Interview & AI Feed Integration - Pattern Map

**Mapped:** 2026-07-01
**Files analyzed:** 10 new/modified files
**Analogs found:** 10 / 10

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Services/RedFlagDetectorService.php` | service | pattern-matching + CRUD | `app/Services/SubscriptionDetectorService.php` | exact |
| `config/tax-rules.php` (additions) | config | rule storage | `config/tax-rules.php` + `config/spendifiai.php` | exact |
| `app/Models/InterviewSession.php` | model | CRUD | `app/Models/AIQuestion.php` | role-match |
| `app/Models/OptimizationQuestion.php` | model | CRUD | `app/Models/AIQuestion.php` | exact |
| `app/Models/UserTaxFact.php` | model | CRUD + encrypted fields | `app/Models/UserFinancialProfile.php` + `app/Models/IncomeOptimizationProfile.php` | exact |
| `app/Services/InterviewOrchestratorService.php` | service | AI/Claude calls + batching | `app/Services/AI/TransactionCategorizerService.php` | exact |
| `app/Enums/QuestionType.php` (Optimization case) | enum | status | `app/Enums/QuestionType.php` | exact |
| `app/Listeners/SurfaceHighPriorityRedFlags.php` | listener | event-driven | `app/Listeners/UpdateTransactionCategory.php` | role-match |
| `app/Listeners/UpdateOptimizationFromAnswer.php` | listener | event-driven | `app/Listeners/UpdateTransactionCategory.php` | exact |
| `app/Http/Controllers/Api/IncomeOptimizerController.php` | controller | request-response | `app/Http/Controllers/Api/AIQuestionController.php` + `app/Http/Controllers/Api/SavingsController.php` | role-match |
| `resources/js/Pages/Interview/Index.tsx` | component | form state | `resources/js/Pages/Questions/Index.tsx` + `resources/js/Components/SpendifiAI/StatementUploadWizard.tsx` | role-match |
| `tests/Unit/Services/RedFlagDetectorServiceTest.php` | test | behavior verification | `tests/Unit/Services/SubscriptionDetectorServiceTest.php` | exact |

---

## Pattern Assignments

### `app/Services/RedFlagDetectorService.php` (service, pattern-matching + CRUD)

**Analog:** `app/Services/SubscriptionDetectorService.php`

**Imports pattern** (lines 1-12):
```php
<?php

namespace App\Services;

use App\Models\CancellationProvider;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
```

**Class structure** (lines 14-47):
```php
class SubscriptionDetectorService
{
    protected ?Collection $providerAliasCache = null;

    protected array $excludedCategories = [
        'TRANSFER_IN', 'BANK_FEES', 'LOAN_PAYMENTS',
        'ATM', 'INCOME', 'RENT', 'MORTGAGE',
    ];

    protected array $knownApiCompanies = [
        'ANTHROPIC', 'OPENAI', 'AWS', 'GOOGLE CLOUD', 'AZURE',
    ];
```

**Detection pattern** (lines 52-66):
```php
public function detectSubscriptions(int $userId): array
{
    $since = Carbon::now()->subMonths(6);
    $transactions = Transaction::where('user_id', $userId)
        ->where('transaction_date', '>=', $since)
        ->where('amount', '>', 0)
        ->get();
    
    $merchantGroups = $transactions->groupBy(function ($tx) {
        return $this->normalizeMerchant($tx->merchant_name);
    });
```

---

### `config/tax-rules.php` (config, rule storage)

**Analog:** `config/tax-rules.php` (year-keyed structure)

**Year-keyed structure** (lines 8-51):
```php
return [
    2026 => [
        'brackets' => [
            'single' => [
                ['rate' => 0.10, 'from' => 0,       'to' => 12_400],
                ['rate' => 0.12, 'from' => 12_400,   'to' => 50_400],
                // ... more brackets
            ],
            'married_joint' => [
                // ... brackets for married filing jointly
            ],
        ],
        'standard_deduction' => [
            'single'            => 16_100,
            'married_joint'     => 32_200,
        ],
    ],
];
```

**Secondary analog:** `config/spendifiai.php` (config nesting pattern, lines 10-31):
```php
'ai' => [
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
    'api_key' => env('ANTHROPIC_API_KEY'),
    'batch_size' => 25,
    'rate_limit_ms' => 500,
    'confidence_thresholds' => [
        'auto_accept' => 0.85,
        'flag_review' => 0.60,
        'ask_question' => 0.40,
    ],
],
```

---

### `app/Models/OptimizationQuestion.php` (model, CRUD)

**Analog:** `app/Models/AIQuestion.php`

**Model structure + casts** (lines 11-32):
```php
class AIQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'transaction_id', 'question', 'options', 'question_type',
        'ai_confidence', 'ai_best_guess', 'user_answer', 'status', 'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'ai_confidence' => 'decimal:2',
            'question_type' => QuestionType::class,
            'status' => QuestionStatus::class,
            'answered_at' => 'datetime',
        ];
    }
```

**Relationships** (lines 34-42):
```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}

public function transaction(): BelongsTo
{
    return $this->belongsTo(Transaction::class);
}
```

---

### `app/Models/UserTaxFact.php` (model, CRUD + encrypted fields)

**Analog 1:** `app/Models/UserFinancialProfile.php` (encrypted fields + $hidden pattern)

**Encrypted fields + $hidden** (lines 28-54):
```php
protected $hidden = [
    'estimated_tax_bracket',
    'spouse_income',
    'childcare_annual_cost',
];

protected function casts(): array
{
    return [
        'has_home_office' => 'boolean',
        'monthly_income' => 'encrypted',
        'monthly_savings_goal' => 'decimal:2',
        'custom_rules' => 'encrypted:array',
        'spouse_income' => 'encrypted',
        'childcare_annual_cost' => 'encrypted',
    ];
}
```

**Analog 2:** `app/Models/IncomeOptimizationProfile.php` (encrypted cents storage pattern, lines 86-118):
```php
/**
 * All money columns use 'encrypted' cast on TEXT DB columns.
 * Stores integer CENTS as strings: '7250000' == $72,500.00
 */
protected function casts(): array
{
    return [
        'w2_wages' => 'encrypted',
        'self_employment_income' => 'encrypted',
        'interest_income' => 'encrypted',
        'dividend_income' => 'encrypted',
        'bank_deposit_total' => 'encrypted',
        'has_home_office' => 'boolean',
        'data_sources' => 'array',
        'built_at' => 'datetime',
    ];
}
```

---

### `app/Services/InterviewOrchestratorService.php` (service, AI/Claude calls + batching)

**Analog:** `app/Services/AI/TransactionCategorizerService.php`

**Constructor + Claude API pattern** (lines 14-32):
```php
class TransactionCategorizerService
{
    protected ?string $apiKey;
    protected string $model;

    const CONFIDENCE_AUTO = 0.85;
    const CONFIDENCE_CONFIRM = 0.60;
    const CONFIDENCE_ASK = 0.40;
    const CONFIDENCE_UNKNOWN = 0.00;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key') ?? '';
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }
```

**Batch processing + Claude call** (lines 39-93):
```php
public function categorizeBatch(Collection $transactions, int $userId): array
{
    $profile = UserFinancialProfile::where('user_id', $userId)->first();
    $systemPrompt = $this->buildCategorizationPrompt($profile);

    $txData = $transactions->map(function (Transaction $tx) use ($matchedOrders) {
        return [
            'id' => $tx->id,
            'merchant' => $tx->merchant_name,
            'amount' => $tx->amount,
            'date' => $tx->transaction_date->format('Y-m-d'),
        ];
    })->toArray();

    $response = $this->callClaude($systemPrompt, json_encode($txData));

    if (isset($response['error'])) {
        Log::error('Transaction categorization failed', $response);
        return ['error' => $response['error'], 'processed' => 0];
    }

    return $this->processCategorizationResults($response, $transactions, $userId);
}
```

---

### `app/Enums/QuestionType.php` (enum, status)

**Analog:** `app/Enums/QuestionType.php`

**Enum structure + cases** (lines 5-11):
```php
enum QuestionType: string
{
    case Category = 'category';
    case BusinessPersonal = 'business_personal';
    case Split = 'split';
    case Confirm = 'confirm';
}
```

---

### `app/Listeners/SurfaceHighPriorityRedFlags.php` (listener, event-driven)

**Analog:** `app/Listeners/UpdateTransactionCategory.php`

**Listener pattern + event dispatch** (lines 10-32):
```php
class UpdateTransactionCategory implements ShouldQueue
{
    /**
     * Handle post-answer side effects only.
     */
    public function handle(UserAnsweredQuestion $event): void
    {
        // Re-check subscription patterns now that the transaction is categorized
        $detector = app(SubscriptionDetectorService::class);
        $detector->detectSubscriptions($event->user->id);

        Log::info('User answered AI question', [
            'user_id' => $event->user->id,
            'question_id' => $event->question->id,
            'answer' => $event->question->user_answer,
        ]);
    }
}
```

---

### `app/Http/Controllers/Api/IncomeOptimizerController.php` (controller, request-response)

**Analog 1:** `app/Http/Controllers/Api/AIQuestionController.php`

**Constructor + dependency injection** (lines 19-23):
```php
class AIQuestionController extends Controller
{
    public function __construct(
        private readonly TransactionCategorizerService $categorizer,
    ) {}
```

**Index method pattern + authorization** (lines 28-51):
```php
public function index(): JsonResponse
{
    $userIds = auth()->user()->householdUserIds();

    AIQuestion::whereIn('user_id', $userIds)
        ->where('status', 'pending')
        ->get();

    return response()->json(AIQuestionResource::collection($questions));
}
```

**Answer method + event dispatch** (lines 56-65):
```php
public function answer(AnswerQuestionRequest $request, AIQuestion $question): JsonResponse
{
    $this->categorizer->handleUserAnswer($question, $request->validated('answer'));

    UserAnsweredQuestion::dispatch($question->fresh(), $request->user());

    return response()->json([
        'message' => 'Answer recorded',
        'transaction' => new TransactionResource($question->transaction->fresh()),
    ]);
}
```

---

### `app/Http/Requests/AnswerQuestionRequest.php` (Form Request pattern)

**Analog:** `app/Http/Requests/AnswerQuestionRequest.php`

**Authorization + validation** (lines 7-20):
```php
class AnswerQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('question'));
    }

    public function rules(): array
    {
        return [
            'answer' => 'required|string|max:200',
        ];
    }
}
```

---

### `app/Policies/AIQuestionPolicy.php` (Policy pattern)

**Analog:** `app/Policies/AIQuestionPolicy.php`

**Household-aware authorization** (lines 8-23):
```php
class AIQuestionPolicy
{
    public function view(User $user, AIQuestion $q): bool
    {
        return $user->id === $q->user_id || $user->isInSameHousehold($q->user_id);
    }

    public function update(User $user, AIQuestion $q): bool
    {
        return $user->id === $q->user_id || $user->isInSameHousehold($q->user_id);
    }

    public function answer(User $user, AIQuestion $q): bool
    {
        return $user->id === $q->user_id || $user->isInSameHousehold($q->user_id);
    }
}
```

---

### `resources/js/Pages/Questions/Index.tsx` (React page pattern)

**Analog:** `resources/js/Pages/Questions/Index.tsx` + `resources/js/Components/SpendifiAI/StatementUploadWizard.tsx`

**useApi hook pattern + conditional rendering** (Questions/Index.tsx):
```typescript
const { data: questions, loading } = useApi<AIQuestion[]>(
    '/api/v1/questions',
    { immediate: true }
);

if (loading) return <LoadingSpinner />;

return (
    <AuthenticatedLayout>
        <div className="space-y-4">
            {questions?.map(q => (
                <QuestionCard key={q.id} question={q} />
            ))}
        </div>
    </AuthenticatedLayout>
);
```

---

### `resources/js/Components/SpendifiAI/InterviewCard.tsx` (component pattern)

**Analog:** `resources/js/Components/SpendifiAI/QuestionCard.tsx` + `resources/js/Components/SpendifiAI/RecommendationCard.tsx`

**Component structure + hooks** (QuestionCard pattern):
```typescript
interface QuestionCardProps {
    question: AIQuestion;
}

export function QuestionCard({ question }: QuestionCardProps) {
    const [answer, setAnswer] = useState('');
    const { submit, loading } = useApiPost(`/api/v1/questions/${question.id}/answer`);

    const handleSubmit = async () => {
        await submit({ answer });
    };

    return (
        <Card className="p-4">
            <p className="text-sm font-medium">{question.question}</p>
            {/* input/options rendering */}
            <button onClick={handleSubmit} disabled={loading}>
                Submit Answer
            </button>
        </Card>
    );
}
```

---

### `tests/Unit/Services/RedFlagDetectorServiceTest.php` (test pattern)

**Analog:** `tests/Unit/Services/SubscriptionDetectorServiceTest.php`

**Test setup + helper functions** (lines 1-26):
```php
<?php

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\CancellationProvider;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SubscriptionDetectorService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createDetectorTestData(): array
{
    $user = User::factory()->create();
    $connection = BankConnection::factory()->create(['user_id' => $user->id]);
    $account = BankAccount::factory()->create(['user_id' => $user->id]);

    return compact('user', 'connection', 'account');
}

it('detects monthly subscriptions from recurring charges', function () {
    ['user' => $user, 'account' => $account] = createDetectorTestData();
```

---

## Shared Patterns

### Event Listener Registration
**Source:** `app/Providers/AppServiceProvider.php` (lines 57-61)

**Apply to:** All new listeners (SurfaceHighPriorityRedFlags, UpdateOptimizationFromAnswer)

```php
Event::listen(
    \App\Events\UserAnsweredQuestion::class,
    \App\Listeners\UpdateTransactionCategory::class,
);
```

**Pattern for registration in AppServiceProvider:**
```php
// In boot() method:
Event::listen(
    \App\Events\RedFlagSurfaced::class,
    \App\Listeners\SurfaceHighPriorityRedFlags::class,
);

Event::listen(
    \App\Events\OptimizationAnswered::class,
    \App\Listeners\UpdateOptimizationFromAnswer::class,
);
```

---

### Route Registration
**Source:** `routes/api.php` (question endpoints)

**Apply to:** All new IncomeOptimizerController endpoints

```php
Route::get('/questions', [AIQuestionController::class, 'index']);
Route::post('/questions/{question}/answer', [AIQuestionController::class, 'answer']);
Route::post('/questions/{question}/chat', [AIQuestionController::class, 'chat']);
```

**Pattern for Interview routes:**
```php
Route::prefix('optimizer')->group(function () {
    Route::get('/interview', [IncomeOptimizerController::class, 'startInterview']);
    Route::get('/questions', [IncomeOptimizerController::class, 'getPendingQuestions']);
    Route::post('/answer', [IncomeOptimizerController::class, 'answerQuestion']);
});
```

---

### Model Binding
**Source:** `app/Providers/AppServiceProvider.php` (lines 63-75)

**Apply to:** InterviewSession + OptimizationQuestion + UserTaxFact models

```php
Route::model('transaction', Transaction::class);
Route::model('question', AIQuestion::class);
Route::model('subscription', Subscription::class);
```

**Add for new models:**
```php
Route::model('interview', InterviewSession::class);
Route::model('opt-question', OptimizationQuestion::class);
Route::model('tax-fact', UserTaxFact::class);
```

---

### Policy Registration
**Source:** `app/Providers/AppServiceProvider.php` (lines 83-94)

**Apply to:** All new model policies

```php
Gate::policy(Transaction::class, TransactionPolicy::class);
Gate::policy(AIQuestion::class, AIQuestionPolicy::class);
Gate::policy(Subscription::class, SubscriptionPolicy::class);
```

**Add for new models:**
```php
Gate::policy(InterviewSession::class, InterviewSessionPolicy::class);
Gate::policy(OptimizationQuestion::class, OptimizationQuestionPolicy::class);
Gate::policy(UserTaxFact::class, UserTaxFactPolicy::class);
```

---

## No Analog Found

All new artifacts have clear analogs in the codebase. All patterns are based on existing proven patterns.

---

## Metadata

**Analog search scope:** `app/Services/`, `app/Models/`, `app/Http/Controllers/Api/`, `app/Listeners/`, `app/Policies/`, `app/Enums/`, `config/`, `resources/js/`, `tests/Unit/`

**Files scanned:** 25 core analog files + configuration files

**Pattern extraction date:** 2026-07-01

**Key findings:**
1. All detectors follow SubscriptionDetectorService pattern (6-month lookback, groupBy normalization, business logic guards)
2. All AI services mirror TransactionCategorizerService (Claude API calls, confidence thresholds, batch processing)
3. All encrypted models use 'encrypted' cast + $hidden pattern; cent storage as integer strings per IncomeOptimizationProfile
4. All listeners implement ShouldQueue + use app() service resolution
5. All controllers use constructor dependency injection + Form Requests for authorization
6. All React pages use useApi/useApiPost hooks for data fetching
7. Event registration centralized in AppServiceProvider::boot()
8. Policy authorization pattern: `$user->id === $model->user_id || $user->isInSameHousehold($model->user_id)`
9. Tax config uses year-keyed structure (2026 => [...]) for IRS rule versioning
10. Test pattern: helper functions + uses(RefreshDatabase::class) + Pest it() syntax
