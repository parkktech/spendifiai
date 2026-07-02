# Phase 12: Optimization Report, Document Intake & Feature Surface - Pattern Map

**Mapped:** 2026-07-02
**Files analyzed:** 10 new/modified files
**Analogs found:** 9 / 10

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Models/OptimizationReport.php` | model | CRUD | `app/Models/OptimizationFinding.php` | exact |
| `database/migrations/*_create_optimization_reports_table.php` | migration | schema | `2026_07_01_110000_add_optimization_finding_columns.php` | role-match |
| `app/Services/OptimizationReportGeneratorService.php` | service | request-response + AI | `app/Services/NarrationService.php` | role-match |
| `app/Jobs/GenerateOptimizationReport.php` | job | background | `app/Jobs/BuildIncomeOptimizationProfile.php` | exact |
| `app/Http/Controllers/Api/OptimizationReportController.php` | controller | CRUD + request-response | `app/Http/Controllers/Api/DurableFactsController.php` | role-match |
| `app/Enums/TaxDocumentCategory.php` (new cases) | enum | config | `app/Enums/TaxDocumentCategory.php` (existing) | exact |
| `resources/js/Pages/OptimizeIncome/Index.tsx` | page | request-response | `resources/js/Pages/Questions/Index.tsx` | pattern-match |
| `resources/js/Components/OptimizationReportCard.tsx` | component | display | `resources/js/Components/SpendifiAI/SuggestedConfirmCard.tsx` | pattern-match |
| `resources/js/Pages/Profile/Index.tsx` (extended) | page | CRUD | `resources/js/Pages/Settings/Index.tsx` | pattern-match |
| `resources/js/Components/DocumentUploadFlow.tsx` | component | file-I/O | `resources/js/Components/SpendifiAI/StatementUploadWizard.tsx` | pattern-match |

---

## Pattern Assignments

### `app/Models/OptimizationReport.php` (model, CRUD)

**Analog:** `app/Models/OptimizationFinding.php` (lines 1–110)

**Model structure with $fillable + $hidden + casts** (lines 26–91):
```php
class OptimizationReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tax_year',
        'report_type',
        'findings',           // JSON array of finding IDs
        'status',             // 'pending' | 'complete'
        'generated_at',
        'expires_at',
        // Add any encrypted sensitive fields to TEXT columns
    ];

    protected $hidden = [
        // Hide any encrypted financial fields from API responses
    ];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            // Use 'encrypted' cast for sensitive monetary fields on TEXT columns
        ];
    }
}
```

**Security scope pattern** (lines 106–109):
```php
public function scopeForUser(Builder $query, int $userId): Builder
{
    return $query->where('user_id', $userId);
}
```

**Relationship to User** (lines 95–98):
```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```

---

### `app/Services/OptimizationReportGeneratorService.php` (service, AI/Claude calls)

**Analog:** `app/Services/NarrationService.php` (lines 29–183)

**Claude API call pattern with system prompt + error handling** (lines 139–182):
```php
protected function callClaude(string $systemPrompt, string $userMessage): ?string
{
    $maxRetries = 2;

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 256,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $userMessage]],
            ]);

            if (! $response->successful()) {
                if ($attempt < $maxRetries) {
                    sleep(1);
                    continue;
                }
                Log::error('Service: API error', ['status' => $response->status()]);
                return null;
            }

            return $response->json('content.0.text');

        } catch (\Exception $e) {
            if ($attempt < $maxRetries) {
                sleep(1);
                continue;
            }
            Log::error('Service: exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    return null;
}
```

**Educational system prompt (no assertive language)** (lines 37–51):
```php
protected const SYSTEM_PROMPT = <<<'SYS'
You are an educational financial assistant. Your role is to explain a pre-computed tax finding in plain English.

RULES (non-negotiable):
1. Use "may", "could", "consider", "might", "worth exploring" language — always educational, never assertive.
2. NEVER state dollar amounts. Use "a meaningful amount" or "a significant difference" instead.
3. NEVER use first-person from the IRS perspective ("you owe", "you qualify", "you are entitled").
SYS;
```

---

### `app/Jobs/GenerateOptimizationReport.php` (job, background)

**Analog:** `app/Jobs/BuildIncomeOptimizationProfile.php` (lines 38–105)

**Job structure with Queueable, $tries, $timeout** (lines 38–51):
```php
class GenerateOptimizationReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int $userId,
        public readonly int $taxYear,
    ) {
        $this->onQueue('optimization');
    }
}
```

**Handle method with service injection + event firing** (lines 53–95):
```php
public function handle(
    OptimizationReportGeneratorService $generator,
): void {
    Log::info('GenerateOptimizationReport starting', [
        'user_id' => $this->userId,
        'tax_year' => $this->taxYear,
    ]);

    $user = User::findOrFail($this->userId);
    $report = $generator->generateReport($user, $this->taxYear);

    event(new OptimizationReportGenerated($user->id, $this->taxYear));

    Log::info('GenerateOptimizationReport complete', [
        'user_id' => $this->userId,
        'tax_year' => $this->taxYear,
    ]);
}
```

**Failed handler with error logging** (lines 97–104):
```php
public function failed(Throwable $exception): void
{
    Log::error('GenerateOptimizationReport failed', [
        'user_id' => $this->userId,
        'tax_year' => $this->taxYear,
        'error' => $exception->getMessage(),
    ]);
}
```

---

### `app/Http/Controllers/Api/OptimizationReportController.php` (controller, CRUD + request-response)

**Analog:** `app/Http/Controllers/Api/DurableFactsController.php` (lines 22–131)

**Index endpoint with security filtering** (lines 34–59):
```php
public function index(Request $request): JsonResponse
{
    $userId = $request->user()->id;
    $year = (int) $request->input('year', now()->year);

    $reports = OptimizationReport::forUser($userId)
        ->where('tax_year', $year)
        ->orderByDesc('generated_at')
        ->get(['id', 'tax_year', 'report_type', 'status', 'generated_at']);

    return response()->json([
        'reports' => $reports,
    ]);
}
```

**Confirm/Supersede pattern (user-initiated state changes)** (lines 72–130):
```php
public function confirm(Request $request, OptimizationReport $report): JsonResponse
{
    // Owner-only authorization
    if ($report->user_id !== $request->user()->id) {
        abort(403, 'You are not authorized to access this report.');
    }

    $report->update(['status' => 'confirmed']);

    return response()->json([
        'message' => 'Report confirmed.',
        'report' => $report->only(['id', 'tax_year', 'status', 'confirmed_at']),
    ]);
}
```

---

### `app/Enums/TaxDocumentCategory.php` (enum, config — new cases)

**Analog:** `app/Enums/TaxDocumentCategory.php` (existing enum, lines 5–76)

**Add new enum cases alongside existing ones** (lines 5–31):
```php
enum TaxDocumentCategory: string
{
    case W2 = 'w2';
    case NEC_1099 = '1099_nec';
    case INT_1099 = '1099_int';
    // ... existing cases ...
    case PropertyTax = 'property_tax';
    case CharitableDonation = 'charitable_donation';
    // NEW CASES FOR PHASE 12:
    case BankStatement = 'bank_statement';
    case IncomeStatement = 'income_statement';
    case ExpenseReport = 'expense_report';
    case InvoiceReceipt = 'invoice_receipt';
}
```

**Label method supporting new cases** (lines 33–62):
```php
public function label(): string
{
    return match ($this) {
        self::W2 => 'W-2',
        // ... existing cases ...
        self::BankStatement => 'Bank Statement',
        self::IncomeStatement => 'Income Statement',
        self::ExpenseReport => 'Expense Report',
        self::InvoiceReceipt => 'Invoice / Receipt',
    };
}
```

---

### `resources/js/Layouts/AuthenticatedLayout.tsx` (layout, nav update)

**Analog:** `resources/js/Layouts/AuthenticatedLayout.tsx` (lines 96–113)

**Add nav item to the navItems array** (lines 96–113):
```typescript
const navItems: NavItemDef[] = [
  { label: 'Dashboard', href: '/dashboard', routeName: 'dashboard', icon: <LayoutDashboard size={18} /> },
  { label: 'Transactions', href: '/transactions', routeName: 'transactions', icon: <Receipt size={18} /> },
  { label: 'Subscriptions', href: '/subscriptions', routeName: 'subscriptions', icon: <CreditCard size={18} /> },
  { label: 'Savings', href: '/savings', routeName: 'savings', icon: <PiggyBank size={18} /> },
  { label: 'Tax', href: '/tax', routeName: 'tax', icon: <FileText size={18} /> },
  { label: 'Tax Vault', href: '/vault', routeName: 'vault', icon: <Archive size={18} /> },
  // NEW FOR PHASE 12:
  { label: 'Optimize Income', href: '/optimize-income', routeName: 'optimize-income', icon: <TrendingUp size={18} />, badge: optimizationQuestionCount },
  { label: 'Connect', href: '/connect', routeName: 'connect', icon: <Link2 size={18} /> },
  // ... rest of nav ...
];
```

**Badge rendering in NavItem** (lines 55–59):
```typescript
{!collapsed && item.badge !== undefined && item.badge > 0 && (
  <span className="ml-auto bg-sw-danger text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center">
    {item.badge}
  </span>
)}
```

---

### `resources/js/Pages/OptimizeIncome/Index.tsx` (page, request-response)

**Analog:** `resources/js/Pages/Questions/Index.tsx` (lines 23–165)

**Page structure with auth guard + data loading + state management** (lines 23–50):
```typescript
export default function OptimizeIncomeIndex() {
  const { auth } = usePage().props as unknown as { auth: { hasBankConnected: boolean } };
  const { data: findings, loading, error, refresh } = useApi<OptimizationFinding[]>(
    '/api/v1/optimizer/findings',
    { enabled: auth.hasBankConnected }
  );

  const [viewMode, setViewMode] = useState<'findings' | 'interview' | 'report'>('findings');
  const [selectedFinding, setSelectedFinding] = useState<number | null>(null);

  if (!auth.hasBankConnected) {
    return <AuthenticatedLayout><ConnectBankPrompt /></AuthenticatedLayout>;
  }

  if (loading) {
    return <Loader2 className="animate-spin" />;
  }

  return (
    <AuthenticatedLayout
      header={<h1 className="text-xl font-bold">Optimize My Income</h1>}
    >
      {/* Tab switching logic */}
      {viewMode === 'interview' && <InterviewCard />}
      {viewMode === 'findings' && <FindingsGrid findings={findings} />}
    </AuthenticatedLayout>
  );
}
```

---

### `resources/js/Components/SpendifiAI/OptimizationReportCard.tsx` (component, display)

**Analog:** `resources/js/Components/SpendifiAI/SuggestedConfirmCard.tsx` (lines 42–168)

**Card with status badge + action buttons** (lines 42–88):
```typescript
export default function OptimizationReportCard({
  report,
  onView,
  disabled = false,
}: OptimizationReportCardProps) {
  const [confirming, setConfirming] = useState(false);

  const handleConfirm = async () => {
    setConfirming(true);
    try {
      await axios.post(`/api/v1/optimizer/reports/${report.id}/confirm`);
      onView?.();
    } finally {
      setConfirming(false);
    }
  };

  return (
    <div className="rounded-xl border border-sw-border bg-sw-card overflow-hidden shadow-sm">
      {/* Status header */}
      <div className="flex items-center justify-between px-4 pt-3 pb-2 border-b border-sw-border/60">
        <span className="text-[11px] font-semibold text-sw-text-secondary uppercase">
          Report
        </span>
        <Badge variant={report.status === 'complete' ? 'success' : 'warning'}>
          {report.status === 'complete' ? 'Ready' : 'Processing'}
        </Badge>
      </div>

      <div className="p-4 space-y-3">
        <p className="text-sm text-sw-text">{report.title}</p>
        <div className="flex gap-2">
          <button onClick={handleConfirm} disabled={confirming} className="...">
            {confirming ? <Loader2 className="animate-spin" /> : <Check />}
            Confirm
          </button>
          <button onClick={() => onView?.()} className="...">
            View Report
          </button>
        </div>
      </div>
    </div>
  );
}
```

---

### `resources/js/Pages/Profile/Index.tsx` (extended page, CRUD)

**Analog:** `resources/js/Pages/Settings/Index.tsx` (lines 42–150) + `resources/js/Components/SpendifiAI/EnhancedProfileSection.tsx`

**Multi-section page layout with form management** (lines 42–110):
```typescript
export default function ProfileIndex() {
  const { data: profileData } = useApi<UserFinancialProfileResponse>('/api/v1/profile/financial');
  const profile = profileData?.profile ?? null;
  const { submit: saveProfile, loading: saving } = useApiPost('/api/v1/profile/financial', 'POST');

  const [form, setForm] = useState({
    // ... form fields ...
  });

  useEffect(() => {
    if (profile) {
      setForm({ /* populate from profile */ });
    }
  }, [profile]);

  const handleSave = async () => {
    await saveProfile(form);
    setSuccess(true);
    setTimeout(() => setSuccess(false), 3000);
  };

  return (
    <AuthenticatedLayout>
      <EnhancedProfileSection />
      <LearnedTaxFactsSection />
      {/* New family profile sections here */}
    </AuthenticatedLayout>
  );
}
```

---

### `resources/js/Components/DocumentUploadFlow.tsx` (component, file-I/O)

**Analog:** `resources/js/Components/SpendifiAI/StatementUploadWizard.tsx` (lines 89–400)

**Multi-step wizard with FileDropZone** (lines 89–175):
```typescript
export default function DocumentUploadFlow({
  onComplete,
  onCancel,
  documentTypes,
}: DocumentUploadFlowProps) {
  const [currentStep, setCurrentStep] = useState(0);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [documentType, setDocumentType] = useState('');
  const [uploading, setUploading] = useState(false);

  const STEPS = [
    { label: 'Document Type' },
    { label: 'Upload File' },
    { label: 'Processing' },
    { label: 'Review' },
    { label: 'Done' },
  ];

  return (
    <div className="space-y-6">
      <StepIndicator currentStep={currentStep} steps={STEPS} />

      {currentStep === 1 && (
        <FileDropZone
          onFileSelect={setSelectedFile}
          selectedFile={selectedFile}
          onClear={() => setSelectedFile(null)}
          acceptedExtensions={['.pdf', '.png', '.jpg', '.jpeg']}
        />
      )}

      {/* Navigation */}
      <div className="flex gap-3">
        <button onClick={() => setCurrentStep(c => c - 1)}>Back</button>
        <button onClick={() => setCurrentStep(c => c + 1)}>Next</button>
      </div>
    </div>
  );
}
```

**FileDropZone usage** (embedded within wizard):
```typescript
<FileDropZone
  onFileSelect={(file) => {
    setSelectedFile(file);
    validateAndUpload(file);
  }}
  selectedFile={selectedFile}
  onClear={() => setSelectedFile(null)}
  maxSizeMb={50}
  acceptedExtensions={['.pdf', '.png', '.jpg', '.jpeg', '.csv']}
/>
```

---

### `app/Http/Controllers/Api/DocumentUploadController.php` (controller, file-I/O + request-response)

**Analog:** `app/Http/Controllers/Api/StatementUploadController.php` pattern (implied from usage)

**Upload endpoint with file validation + queuing** (pseudo-code from TaxExportService pattern):
```php
class DocumentUploadController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,png,jpg,jpeg,csv|max:50000',
            'document_type' => 'required|string|in:bank_statement,income_statement,expense_report,invoice_receipt',
            'tax_year' => 'required|integer|between:2020,'.now()->year,
        ]);

        $file = $request->file('file');
        $path = Storage::disk('private')
            ->putFileAs("documents/{$request->user()->id}", $file, $file->hashName());

        $document = TaxDocument::create([
            'user_id' => $request->user()->id,
            'tax_year' => $request->input('tax_year'),
            'document_type' => $request->input('document_type'),
            'file_path' => $path,
            'status' => DocumentStatus::Pending,
        ]);

        // Queue extraction job
        ProcessDocumentExtraction::dispatch($document->id);

        return response()->json([
            'id' => $document->id,
            'status' => 'processing',
        ]);
    }
}
```

---

## Shared Patterns

### Model Structure
**Source:** `app/Models/OptimizationFinding.php`
**Apply to:** All new models (OptimizationReport, etc.)

- Use `$fillable` for all mass-assignable attributes
- Use `$hidden` to exclude encrypted/sensitive fields from API responses
- Use protected `casts()` method (Laravel 12 syntax) for encryption and type casting
- Include `scopeForUser()` security scope on all user-scoped models
- Use `BelongsTo` relationships for owner references

### Claude API Integration
**Source:** `app/Services/NarrationService.php` (lines 139–182)
**Apply to:** All services making Claude calls

- Use `Http::withHeaders()` with 'x-api-key', 'anthropic-version', 'content-type'
- POST to `https://api.anthropic.com/v1/messages`
- Include retry loop with exponential backoff (sleep 1 second between attempts, max 2 retries)
- Check `$response->successful()` before extracting JSON
- Extract text via `$response->json('content.0.text')`
- Log errors with context (user_id, resource_id, error message)
- Return `null` on any failure (graceful degradation)

### Job Structure
**Source:** `app/Jobs/BuildIncomeOptimizationProfile.php` (lines 38–105)
**Apply to:** All background jobs (GenerateOptimizationReport, etc.)

- Set `public int $tries = 3` and `public int $timeout = 180`
- Constructor takes primitive scalars (int, not models) with readonly properties
- Call `$this->onQueue('optimization')` in constructor
- Use dependency injection in `handle()` method
- Log start/completion with relevant IDs
- Fire events for listeners to attach (e.g., `event(new OptimizationProfileBuilt(...))`)
- Implement `failed()` handler with error logging

### React Page Structure
**Source:** `resources/js/Pages/Questions/Index.tsx` (lines 23–86)
**Apply to:** All new pages (OptimizeIncome, Profile, etc.)

- Use `useApi()` hook for GET requests with `enabled` option to skip calls when preconditions not met
- Guard pages with `auth.hasBankConnected` check; show `<ConnectBankPrompt />` if false
- Use `usePage()` to extract auth props ONLY inside components (never in providers)
- Show loading spinner when data is loading
- Use `AuthenticatedLayout` with header prop for title + subtitle
- Render content in main area

### React Component Card Pattern
**Source:** `resources/js/Components/SpendifiAI/SuggestedConfirmCard.tsx` (lines 42–168)
**Apply to:** All standalone card components (OptimizationReportCard, etc.)

- Use sw-* design tokens only (no hardcoded colors)
- Include header with Badge for status
- Include action buttons at the bottom
- Show loading state on buttons with `disabled` + Loader2 spinner
- Include educational disclaimer at bottom
- Use shadow-sm for subtle elevation
- Spacing rhythm: 8px gaps, 4px padding increments

### Upload Wizard Pattern
**Source:** `resources/js/Components/SpendifiAI/StatementUploadWizard.tsx` (lines 89–400) + `resources/js/Components/SpendifiAI/FileDropZone.tsx`
**Apply to:** Document upload flows

- Use StepIndicator component to show progress
- Embed FileDropZone for file selection
- Validate file type + size before sending to API
- Queue background job for processing
- Poll for status or use websocket for real-time progress
- Show ProcessingStatus component during extraction

### API Response Filtering
**Source:** `app/Http/Controllers/Api/DurableFactsController.php` (lines 34–58)
**Apply to:** All list endpoints

- Always scope queries through `scopeForUser()` security method
- Return only necessary fields: `get(['id', 'field1', 'field2', ...])`
- Use `only()` method on single resources to exclude hidden fields: `->only(['id', 'field1', ...])`
- Separate confirmed and proposal states in response JSON

---

## No Analog Found

Files with patterns that are new to the codebase (use RESEARCH.md or create new from first principles):

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `config/tax-document-extraction.php` | config | config | New extraction prompt config module for Phase 12 |
| `app/Services/TaxDocumentExtractionService.php` | service | AI | No existing document-specific extraction service; adapt from statement parsing patterns |

---

## Metadata

**Analog search scope:** `app/Models`, `app/Services`, `app/Jobs`, `app/Http/Controllers/Api`, `app/Enums`, `resources/js/Pages`, `resources/js/Components/SpendifiAI`, `resources/js/Layouts`

**Files scanned:** 35+ source files

**Pattern extraction date:** 2026-07-02
