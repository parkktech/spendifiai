import { useState, useCallback, useEffect, useRef } from 'react';
import {
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  ChevronDown,
  Loader2,
  Sparkles,
  AlertTriangle,
  LayoutDashboard,
  Upload,
} from 'lucide-react';
import StepIndicator from './StepIndicator';
import FileDropZone from './FileDropZone';
import ProcessingStatus from './ProcessingStatus';
import TransactionReviewTable from './TransactionReviewTable';
import type {
  ParsedTransaction,
  StatementUploadResult,
  StatementProcessingStatus,
  StatementImportResult,
} from '@/types/spendifiai';
import { useApiPost } from '@/hooks/useApi';

interface StatementUploadWizardProps {
  onComplete: () => void;
  onCancel: () => void;
}

const STEPS = [
  { label: 'Account Info' },
  { label: 'Upload File' },
  { label: 'Processing' },
  { label: 'Review' },
  { label: 'Done' },
];

const COMMON_BANKS = [
  'Chase',
  'Bank of America',
  'Wells Fargo',
  'Citi',
  'Capital One',
  'US Bank',
  'PNC Bank',
  'TD Bank',
  'Ally Bank',
  'Charles Schwab',
  'Discover',
  'American Express',
  'USAA',
  'Navy Federal Credit Union',
  'Marcus by Goldman Sachs',
];

const ACCOUNT_TYPES = [
  { value: 'checking', label: 'Checking' },
  { value: 'savings', label: 'Savings' },
  { value: 'credit', label: 'Credit Card' },
  { value: 'investment', label: 'Investment' },
];

export default function StatementUploadWizard({
  onComplete,
  onCancel,
}: StatementUploadWizardProps) {
  // Step tracking
  const [currentStep, setCurrentStep] = useState(0);

  // Step 1: Account info
  const [bankName, setBankName] = useState('');
  const [customBankName, setCustomBankName] = useState('');
  const [accountType, setAccountType] = useState('checking');
  const [accountNickname, setAccountNickname] = useState('');

  // Step 2: File upload
  const [selectedFile, setSelectedFile] = useState<File | null>(null);

  // Step 3: Processing
  const [processingStatus, setProcessingStatus] = useState<StatementProcessingStatus>({
    upload_id: 0,
    status: 'uploading',
    progress: 0,
    message: 'Preparing upload...',
  });

  // Step 4: Review
  const [transactions, setTransactions] = useState<ParsedTransaction[]>([]);
  const [uploadResult, setUploadResult] = useState<StatementUploadResult | null>(null);

  // Step 5: Complete
  const [importResult, setImportResult] = useState<StatementImportResult | null>(null);

  // API hooks
  const { submit: uploadFile, loading: uploading } = useApiPost<StatementUploadResult>(
    '/api/v1/statements/upload',
  );
  const { submit: importTransactions, loading: importing } =
    useApiPost<StatementImportResult>('/api/v1/statements/import');

  // Error state
  const [error, setError] = useState<string | null>(null);

  const effectiveBankName = bankName === 'Other' ? customBankName : bankName;

  // Validate step 1
  const isStep1Valid =
    effectiveBankName.trim().length > 0 && accountType.length > 0;

  // --- Step navigation ---

  const goToStep = useCallback((step: number) => {
    setError(null);
    setCurrentStep(step);
  }, []);

  const handleBack = useCallback(() => {
    if (currentStep === 3) {
      // Going back from review to upload (skip processing)
      goToStep(1);
    } else if (currentStep > 0) {
      goToStep(currentStep - 1);
    }
  }, [currentStep, goToStep]);

  // --- Step 2 -> Step 3: Upload and process ---

  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const handleUpload = useCallback(async () => {
    if (!selectedFile) return;
    setError(null);
    goToStep(2);

    // Simulate processing stages for a polished UX.
    // In production this would be driven by polling the server.
    const stages: Array<{
      status: StatementProcessingStatus['status'];
      message: string;
      duration: number;
    }> = [
      { status: 'uploading', message: 'Uploading your statement...', duration: 800 },
      {
        status: 'parsing',
        message: 'Reading the document structure...',
        duration: 1200,
      },
      {
        status: 'extracting',
        message: 'Extracting transaction rows...',
        duration: 2000,
      },
      {
        status: 'analyzing',
        message: 'AI is detecting duplicates and cleaning merchant names...',
        duration: 1500,
      },
    ];

    let progress = 0;
    for (const stage of stages) {
      setProcessingStatus({
        upload_id: 0,
        status: stage.status,
        progress,
        message: stage.message,
      });
      await new Promise((r) => setTimeout(r, stage.duration));
      progress += 25;
    }

    // Actually send the file
    const formData = new FormData();
    formData.append('file', selectedFile);
    formData.append('bank_name', effectiveBankName);
    formData.append('account_type', accountType);
    if (accountNickname) {
      formData.append('nickname', accountNickname);
    }

    try {
      const result = await uploadFile(formData as never);
      if (result) {
        setUploadResult(result);
        setTransactions(result.transactions);
        setProcessingStatus({
          upload_id: result.upload_id,
          status: 'complete',
          progress: 100,
          message: `Found ${result.total_extracted} transactions (${result.duplicates_found} duplicates).`,
          transactions_found: result.total_extracted,
        });
        // Brief pause on success before moving to review
        setTimeout(() => goToStep(3), 600);
      } else {
        throw new Error('Upload failed. Please try again.');
      }
    } catch (err) {
      const message =
        err instanceof Error
          ? err.message
          : 'Something went wrong processing your statement. Please try again.';
      setProcessingStatus({
        upload_id: 0,
        status: 'error',
        progress: 0,
        message,
      });
      setError(message);
    }
  }, [
    selectedFile,
    effectiveBankName,
    accountType,
    accountNickname,
    goToStep,
    uploadFile,
  ]);

  // Cleanup polling on unmount
  useEffect(() => {
    return () => {
      if (pollingRef.current) {
        clearInterval(pollingRef.current);
      }
    };
  }, []);

  // --- Step 4: Review actions ---

  const handleUpdateTransaction = useCallback(
    (rowIndex: number, updates: Partial<ParsedTransaction>) => {
      setTransactions((prev) =>
        prev.map((t) => (t.row_index === rowIndex ? { ...t, ...updates } : t)),
      );
    },
    [],
  );

  const handleRemoveTransaction = useCallback((rowIndex: number) => {
    setTransactions((prev) => prev.filter((t) => t.row_index !== rowIndex));
  }, []);

  // --- Step 4 -> Step 5: Import ---

  const handleImport = useCallback(async () => {
    if (!uploadResult) return;
    setError(null);

    const nonDuplicates = transactions.filter((t) => !t.is_duplicate);
    const result = await importTransactions({
      upload_id: uploadResult.upload_id,
      transactions: nonDuplicates,
    } as never);

    if (result) {
      setImportResult(result);
      goToStep(4);
    } else {
      setError('Import failed. Please try again.');
    }
  }, [uploadResult, transactions, importTransactions, goToStep]);

  // --- Render each step ---

  const renderStep = () => {
    switch (currentStep) {
      // === STEP 1: Account Info ===
      case 0:
        return (
          <div className="space-y-5">
            <div className="text-center mb-2">
              <h3 className="text-base font-semibold text-sw-text">
                Tell us about this account
              </h3>
              <p className="text-xs text-sw-muted mt-1">
                This helps us parse your statement correctly and organize your
                transactions.
              </p>
            </div>

            {/* Bank selection */}
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">
                Bank or Institution
              </label>
              <div className="relative">
                <select
                  value={bankName}
                  onChange={(e) => {
                    setBankName(e.target.value);
                    if (e.target.value !== 'Other') {
                      setCustomBankName('');
                    }
                  }}
                  className="w-full px-3 py-2.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm appearance-none focus:outline-none focus:border-sw-accent pr-8"
                >
                  <option value="">Select your bank...</option>
                  {COMMON_BANKS.map((bank) => (
                    <option key={bank} value={bank}>
                      {bank}
                    </option>
                  ))}
                  <option value="Other">Other (type manually)</option>
                </select>
                <ChevronDown
                  size={14}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-sw-dim pointer-events-none"
                />
              </div>
            </div>

            {/* Custom bank name */}
            {bankName === 'Other' && (
              <div>
                <label className="block text-xs font-medium text-sw-muted mb-1.5">
                  Bank Name
                </label>
                <input
                  type="text"
                  value={customBankName}
                  onChange={(e) => setCustomBankName(e.target.value)}
                  placeholder="Enter your bank or institution name"
                  className="w-full px-3 py-2.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent"
                />
              </div>
            )}

            {/* Account type */}
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">
                Account Type
              </label>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                {ACCOUNT_TYPES.map((type) => (
                  <button
                    key={type.value}
                    onClick={() => setAccountType(type.value)}
                    className={`px-3 py-2.5 rounded-lg border text-sm font-medium transition ${
                      accountType === type.value
                        ? 'border-sw-accent bg-sw-accent-light text-sw-accent'
                        : 'border-sw-border bg-sw-card text-sw-muted hover:border-sw-border-strong hover:text-sw-text'
                    }`}
                  >
                    {type.label}
                  </button>
                ))}
              </div>
            </div>

            {/* Optional nickname */}
            <div>
              <label className="block text-xs font-medium text-sw-muted mb-1.5">
                Account Nickname{' '}
                <span className="text-sw-dim font-normal">(optional)</span>
              </label>
              <input
                type="text"
                value={accountNickname}
                onChange={(e) => setAccountNickname(e.target.value)}
                placeholder='e.g. "My Business Checking", "Joint Savings"'
                className="w-full px-3 py-2.5 rounded-lg border border-sw-border bg-sw-card text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent"
              />
            </div>

            {/* Navigation */}
            <div className="flex justify-between pt-2">
              <button
                onClick={onCancel}
                className="px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
              >
                Cancel
              </button>
              <button
                onClick={() => goToStep(1)}
                disabled={!isStep1Valid}
                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Continue
                <ArrowRight size={14} />
              </button>
            </div>
          </div>
        );

      // === STEP 2: File Upload ===
      case 1:
        return (
          <div className="space-y-5">
            <div className="text-center mb-2">
              <h3 className="text-base font-semibold text-sw-text">
                Upload your bank statement
              </h3>
              <p className="text-xs text-sw-muted mt-1">
                Download a statement from your bank's website (usually under "Documents"
                or "Statements") and upload the PDF or CSV file here.
              </p>
            </div>

            <FileDropZone
              onFileSelect={setSelectedFile}
              selectedFile={selectedFile}
              onClear={() => setSelectedFile(null)}
            />

            {/* Help tips */}
            <div className="rounded-xl border border-sw-border bg-sw-bg p-4 space-y-2.5">
              <p className="text-xs font-semibold text-sw-text">Tips for best results</p>
              <ul className="space-y-1.5">
                {[
                  'Download the official statement from your bank (PDF preferred over CSV for better merchant name detection)',
                  'Make sure the statement covers a full month for the most useful analysis',
                  'For credit cards, the statement should include individual transaction details',
                  'Password-protected PDFs are supported -- you\'ll be prompted for the password if needed',
                ].map((tip) => (
                  <li key={tip} className="flex items-start gap-2">
                    <CheckCircle2
                      size={12}
                      className="text-sw-success shrink-0 mt-0.5"
                    />
                    <span className="text-[11px] text-sw-muted leading-relaxed">
                      {tip}
                    </span>
                  </li>
                ))}
              </ul>
            </div>

            {/* Navigation */}
            <div className="flex justify-between pt-2">
              <button
                onClick={handleBack}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
              >
                <ArrowLeft size={14} />
                Back
              </button>
              <button
                onClick={handleUpload}
                disabled={!selectedFile || uploading}
                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {uploading ? (
                  <Loader2 size={14} className="animate-spin" />
                ) : (
                  <Sparkles size={14} />
                )}
                Extract Transactions
              </button>
            </div>
          </div>
        );

      // === STEP 3: Processing ===
      case 2:
        return (
          <div className="space-y-5">
            <ProcessingStatus status={processingStatus} />

            {processingStatus.status === 'error' && (
              <div className="flex justify-center gap-3 pt-2">
                <button
                  onClick={() => goToStep(1)}
                  className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
                >
                  <ArrowLeft size={14} />
                  Try Again
                </button>
              </div>
            )}
          </div>
        );

      // === STEP 4: Review ===
      case 3:
        return (
          <div className="space-y-5">
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
              <div>
                <h3 className="text-base font-semibold text-sw-text">
                  Review extracted transactions
                </h3>
                <p className="text-xs text-sw-muted mt-0.5">
                  Check the transactions below and fix any mistakes before importing.
                  {uploadResult?.date_range && (
                    <span className="text-sw-text font-medium">
                      {' '}
                      Covering{' '}
                      {new Intl.DateTimeFormat('en-US', {
                        month: 'short',
                        day: 'numeric',
                      }).format(new Date(uploadResult.date_range.from))}{' '}
                      &mdash;{' '}
                      {new Intl.DateTimeFormat('en-US', {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                      }).format(new Date(uploadResult.date_range.to))}
                      .
                    </span>
                  )}
                </p>
              </div>
            </div>

            {/* Processing notes */}
            {uploadResult?.processing_notes &&
              uploadResult.processing_notes.length > 0 && (
                <div className="rounded-xl border border-blue-200 bg-blue-50/50 p-4">
                  <p className="text-xs font-semibold text-sw-text mb-1.5">
                    Processing notes
                  </p>
                  <ul className="space-y-1">
                    {uploadResult.processing_notes.map((note, i) => (
                      <li key={i} className="text-[11px] text-sw-muted leading-relaxed">
                        {note}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

            <TransactionReviewTable
              transactions={transactions}
              onUpdate={handleUpdateTransaction}
              onRemove={handleRemoveTransaction}
              duplicatesCount={uploadResult?.duplicates_found ?? 0}
            />

            {error && (
              <div className="flex items-center gap-2 rounded-lg border border-sw-danger/20 bg-sw-danger-light p-3">
                <AlertTriangle size={14} className="text-sw-danger shrink-0" />
                <span className="text-xs text-sw-danger">{error}</span>
              </div>
            )}

            {/* Navigation */}
            <div className="flex justify-between pt-2">
              <button
                onClick={() => goToStep(1)}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
              >
                <ArrowLeft size={14} />
                Upload Different File
              </button>
              <button
                onClick={handleImport}
                disabled={
                  importing ||
                  transactions.filter((t) => !t.is_duplicate).length === 0
                }
                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {importing ? (
                  <Loader2 size={14} className="animate-spin" />
                ) : (
                  <CheckCircle2 size={14} />
                )}
                Import{' '}
                {transactions.filter((t) => !t.is_duplicate).length} Transactions
              </button>
            </div>
          </div>
        );

      // === STEP 5: Success ===
      case 4:
        return (
          <div className="flex flex-col items-center text-center py-8 space-y-5">
            <div className="w-20 h-20 rounded-full bg-sw-success-light border-2 border-emerald-200 flex items-center justify-center">
              <CheckCircle2 size={36} className="text-sw-success" />
            </div>

            <div>
              <h3 className="text-xl font-bold text-sw-text">
                {importResult?.imported ?? 0} transactions imported
              </h3>
              <p className="text-sm text-sw-muted mt-2 max-w-md">
                Your transactions from {effectiveBankName} are now in SpendifiAI. AI
                categorization will start automatically. Head to your dashboard to see
                your spending analysis.
              </p>
            </div>

            {importResult && importResult.skipped > 0 && (
              <div className="flex items-center gap-2 px-4 py-2 rounded-full bg-amber-50 border border-amber-200">
                <span className="text-xs text-sw-warning font-medium">
                  {importResult.skipped} duplicate
                  {importResult.skipped !== 1 ? 's' : ''} skipped
                </span>
              </div>
            )}

            <div className="flex items-center gap-3 pt-4">
              <a
                href="/dashboard"
                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition"
              >
                <LayoutDashboard size={14} />
                View Dashboard
              </a>
              <button
                onClick={() => {
                  // Reset wizard for another upload
                  setCurrentStep(0);
                  setBankName('');
                  setCustomBankName('');
                  setAccountNickname('');
                  setSelectedFile(null);
                  setTransactions([]);
                  setUploadResult(null);
                  setImportResult(null);
                  setError(null);
                }}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
              >
                <Upload size={14} />
                Upload Another
              </button>
            </div>
          </div>
        );

      default:
        return null;
    }
  };

  return (
    <div>
      {/* Step indicator -- hide on success step */}
      {currentStep < 4 && (
        <StepIndicator steps={STEPS} currentStep={currentStep} />
      )}

      {renderStep()}
    </div>
  );
}
