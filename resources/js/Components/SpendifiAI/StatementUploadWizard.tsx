import { useState, useCallback, useEffect, useRef } from 'react';
import axios from 'axios';
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
  Wifi,
  Building2,
} from 'lucide-react';
import StepIndicator from './StepIndicator';
import FileDropZone from './FileDropZone';
import ProcessingStatus from './ProcessingStatus';
import BatchProcessingStatus from './BatchProcessingStatus';
import TransactionReviewTable from './TransactionReviewTable';
import Badge from './Badge';
import type {
  BankAccount,
  ParsedTransaction,
  StatementUploadResult,
  StatementProcessingStatus,
  StatementImportResult,
  BatchStatusResponse,
  BatchTransactionsResponse,
} from '@/types/spendifiai';
import { useApiPost } from '@/hooks/useApi';

interface StatementUploadWizardProps {
  onComplete: () => void;
  onCancel: () => void;
  existingAccounts?: BankAccount[];
  resumeUploadIds?: number[];
}

const STEPS = [
  { label: 'Account Info' },
  { label: 'Upload Files' },
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

/** Map server status to user-friendly message and progress */
const STATUS_MAP: Record<string, { message: string; progress: number }> = {
  queued: { message: 'Queued for processing...', progress: 5 },
  parsing: { message: 'Reading the document structure...', progress: 15 },
  extracting: { message: 'AI is extracting transactions from your statement...', progress: 35 },
  analyzing: { message: 'Detecting duplicates and cleaning merchant names...', progress: 75 },
  complete: { message: 'Processing complete!', progress: 100 },
};

function formatAccountType(type: string, subtype: string | null): string {
  if (subtype === 'credit card') return 'Credit Card';
  if (subtype) return subtype.charAt(0).toUpperCase() + subtype.slice(1);
  return type.charAt(0).toUpperCase() + type.slice(1);
}

export default function StatementUploadWizard({
  onComplete,
  onCancel,
  existingAccounts,
  resumeUploadIds,
}: StatementUploadWizardProps) {
  const hasExistingAccounts = existingAccounts && existingAccounts.length > 0;

  // Step tracking
  const [currentStep, setCurrentStep] = useState(0);

  // Step 1: Account info — mode selection
  const [uploadMode, setUploadMode] = useState<'existing' | 'new'>(
    hasExistingAccounts ? 'existing' : 'new',
  );
  const [selectedAccountId, setSelectedAccountId] = useState<number | null>(null);

  // Step 1: New account fields
  const [bankName, setBankName] = useState('');
  const [customBankName, setCustomBankName] = useState('');
  const [accountType, setAccountType] = useState('checking');
  const [accountNickname, setAccountNickname] = useState('');

  // Step 2: File upload — supports single and multi
  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);

  // Step 3: Processing — single file
  const [processingStatus, setProcessingStatus] = useState<StatementProcessingStatus>({
    upload_id: 0,
    status: 'uploading',
    progress: 0,
    message: 'Preparing upload...',
  });

  // Step 3: Processing — batch
  const [batchStatus, setBatchStatus] = useState<BatchStatusResponse | null>(null);
  const [uploadIds, setUploadIds] = useState<number[]>([]);

  // Step 4: Review
  const [transactions, setTransactions] = useState<ParsedTransaction[]>([]);
  const [uploadResult, setUploadResult] = useState<StatementUploadResult | null>(null);
  // Batch review metadata
  const [batchMeta, setBatchMeta] = useState<{
    duplicates_found: number;
    db_duplicates: number;
    cross_file_duplicates: number;
    date_range: { from: string; to: string };
    processing_notes: string[];
    files_included: number;
  } | null>(null);

  // Step 5: Complete
  const [importResult, setImportResult] = useState<StatementImportResult | null>(null);

  // API hooks
  const { submit: uploadFile, loading: uploading } = useApiPost<{ upload_id: number; status: string }>(
    '/api/v1/statements/upload',
  );
  const { submit: importTransactions, loading: importing } =
    useApiPost<StatementImportResult>('/api/v1/statements/import');

  // Error state
  const [error, setError] = useState<string | null>(null);
  // Track how many files are currently being uploaded (not yet queued)
  const [uploadingCount, setUploadingCount] = useState(0);

  const isResumeMode = Boolean(resumeUploadIds && resumeUploadIds.length > 0);
  const isBatch = selectedFiles.length > 1 || (isResumeMode && (resumeUploadIds?.length ?? 0) > 1);
  const effectiveBankName = bankName === 'Other' ? customBankName : bankName;

  // Validate step 1
  const isStep1Valid =
    uploadMode === 'existing'
      ? selectedAccountId !== null
      : effectiveBankName.trim().length > 0 && accountType.length > 0;

  // Target account name for display
  const targetAccountName =
    uploadMode === 'existing'
      ? existingAccounts?.find((a) => a.id === selectedAccountId)?.name ?? 'your account'
      : effectiveBankName;

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

  // --- Polling for async processing status ---

  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const mountedRef = useRef(true);

  const stopPolling = useCallback(() => {
    if (pollingRef.current) {
      clearInterval(pollingRef.current);
      pollingRef.current = null;
    }
  }, []);

  // --- Single-file polling ---
  const startPolling = useCallback(
    (uploadId: number) => {
      stopPolling();

      const poll = async () => {
        if (!mountedRef.current) return;

        try {
          const response = await axios.get<{
            upload_id: number;
            status: string;
            file_name: string;
            error_message: string | null;
            processing_notes: string[];
            total_extracted?: number;
            duplicates_found?: number;
            transactions?: ParsedTransaction[];
            date_range?: { from: string; to: string };
          }>(`/api/v1/statements/${uploadId}/status`);

          if (!mountedRef.current) return;

          const data = response.data;
          const statusInfo = STATUS_MAP[data.status] ?? {
            message: 'Processing...',
            progress: 50,
          };

          setProcessingStatus({
            upload_id: uploadId,
            status: data.status as StatementProcessingStatus['status'],
            progress: statusInfo.progress,
            message: statusInfo.message,
            transactions_found: data.total_extracted,
          });

          if (data.status === 'complete') {
            stopPolling();

            const result: StatementUploadResult = {
              upload_id: uploadId,
              file_name: data.file_name,
              total_extracted: data.total_extracted ?? 0,
              duplicates_found: data.duplicates_found ?? 0,
              transactions: data.transactions ?? [],
              date_range: data.date_range ?? { from: '', to: '' },
              processing_notes: data.processing_notes ?? [],
            };

            setUploadResult(result);
            setTransactions(result.transactions);

            setProcessingStatus((prev) => ({
              ...prev,
              status: 'complete',
              progress: 100,
              message: `Found ${result.total_extracted} transactions (${result.duplicates_found} duplicates).`,
              transactions_found: result.total_extracted,
            }));

            // Brief pause on success before moving to review
            setTimeout(() => goToStep(3), 600);
          } else if (data.status === 'error') {
            stopPolling();
            const msg = data.error_message || 'Processing failed. Please try again.';
            setProcessingStatus({
              upload_id: uploadId,
              status: 'error',
              progress: 0,
              message: msg,
            });
            setError(msg);
          }
        } catch {
          // Network error during polling — don't stop, just retry
        }
      };

      // Initial poll immediately, then every 3 seconds
      poll();
      pollingRef.current = setInterval(poll, 3000);
    },
    [stopPolling, goToStep],
  );

  // --- Batch polling ---
  const startBatchPolling = useCallback(
    (ids: number[]) => {
      stopPolling();

      const poll = async () => {
        if (!mountedRef.current) return;

        try {
          const response = await axios.post<BatchStatusResponse>(
            '/api/v1/statements/batch-status',
            { upload_ids: ids },
          );

          if (!mountedRef.current) return;

          const data = response.data;
          setBatchStatus(data);

          if (data.summary.all_done) {
            stopPolling();

            // If we have successful files, fetch merged transactions
            if (data.summary.completed > 0) {
              const completedIds = data.uploads
                .filter((u) => u.status === 'complete')
                .map((u) => u.upload_id);

              try {
                const txResponse = await axios.post<BatchTransactionsResponse>(
                  '/api/v1/statements/batch-transactions',
                  { upload_ids: completedIds },
                );

                if (!mountedRef.current) return;

                const txData = txResponse.data;
                setTransactions(txData.transactions);
                setBatchMeta({
                  duplicates_found: txData.duplicates_found,
                  db_duplicates: txData.db_duplicates,
                  cross_file_duplicates: txData.cross_file_duplicates,
                  date_range: txData.date_range,
                  processing_notes: txData.processing_notes,
                  files_included: txData.files_included,
                });
                // Store completed IDs for import
                setUploadIds(completedIds);
              } catch {
                setError('Failed to load merged transactions. Please try again.');
              }
            }
          }
        } catch {
          // Network error during polling — don't stop, just retry
        }
      };

      poll();
      pollingRef.current = setInterval(poll, 3000);
    },
    [stopPolling],
  );

  // Cleanup on unmount
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      stopPolling();
    };
  }, [stopPolling]);

  // Resume flow: start at processing step with existing upload IDs
  const resumeInitiatedRef = useRef(false);
  useEffect(() => {
    if (!resumeUploadIds || resumeUploadIds.length === 0 || resumeInitiatedRef.current) return;
    resumeInitiatedRef.current = true;

    setUploadIds(resumeUploadIds);
    setCurrentStep(2);

    if (resumeUploadIds.length === 1) {
      startPolling(resumeUploadIds[0]);
    } else {
      startBatchPolling(resumeUploadIds);
    }
  }, [resumeUploadIds, startPolling, startBatchPolling]);

  // --- Step 2 -> Step 3: Upload and process ---

  const handleUpload = useCallback(async () => {
    if (selectedFiles.length === 0) return;
    setError(null);
    goToStep(2);

    const isMulti = selectedFiles.length > 1;

    if (isMulti) {
      // --- Batch upload ---
      setBatchStatus(null);
      setBatchMeta(null);
      setUploadingCount(selectedFiles.length);

      const collectedIds: number[] = [];
      const errors: string[] = [];

      for (const file of selectedFiles) {
        const formData = new FormData();
        formData.append('file', file);

        if (uploadMode === 'existing' && selectedAccountId) {
          formData.append('bank_account_id', String(selectedAccountId));
        } else {
          formData.append('bank_name', effectiveBankName);
          formData.append('account_type', accountType);
          if (accountNickname) {
            formData.append('nickname', accountNickname);
          }
        }

        try {
          const result = await uploadFile(formData as never);
          if (result && result.upload_id) {
            collectedIds.push(result.upload_id);
          } else {
            errors.push(`Failed to upload ${file.name}`);
          }
        } catch {
          errors.push(`Failed to upload ${file.name}`);
        }

        setUploadingCount((prev) => prev - 1);
      }

      if (collectedIds.length === 0) {
        setError(errors[0] || 'All uploads failed. Please try again.');
        return;
      }

      setUploadIds(collectedIds);

      // Initialize batch status with all queued
      setBatchStatus({
        uploads: collectedIds.map((id, i) => ({
          upload_id: id,
          status: 'queued',
          file_name: selectedFiles[i]?.name ?? null,
        })),
        summary: {
          total: collectedIds.length,
          completed: 0,
          failed: 0,
          processing: collectedIds.length,
          all_done: false,
          total_extracted: 0,
          total_duplicates: 0,
        },
      });

      startBatchPolling(collectedIds);
    } else {
      // --- Single file upload (same as before) ---
      const file = selectedFiles[0];
      setProcessingStatus({
        upload_id: 0,
        status: 'uploading',
        progress: 0,
        message: 'Uploading your statement...',
      });

      const formData = new FormData();
      formData.append('file', file);

      if (uploadMode === 'existing' && selectedAccountId) {
        formData.append('bank_account_id', String(selectedAccountId));
      } else {
        formData.append('bank_name', effectiveBankName);
        formData.append('account_type', accountType);
        if (accountNickname) {
          formData.append('nickname', accountNickname);
        }
      }

      try {
        const result = await uploadFile(formData as never);
        if (result && result.upload_id) {
          setProcessingStatus({
            upload_id: result.upload_id,
            status: 'queued',
            progress: 5,
            message: 'Queued for processing...',
          });
          startPolling(result.upload_id);
        } else {
          throw new Error('Upload failed. Please try again.');
        }
      } catch (err) {
        const message =
          err instanceof Error
            ? err.message
            : 'Something went wrong uploading your statement. Please try again.';
        setProcessingStatus({
          upload_id: 0,
          status: 'error',
          progress: 0,
          message,
        });
        setError(message);
      }
    }
  }, [
    selectedFiles,
    uploadMode,
    selectedAccountId,
    effectiveBankName,
    accountType,
    accountNickname,
    goToStep,
    uploadFile,
    startPolling,
    startBatchPolling,
  ]);

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
    setError(null);

    const nonDuplicates = transactions.filter((t) => !t.is_duplicate);

    // Use upload_ids for batch, upload_id for single
    const payload: Record<string, unknown> = {
      transactions: nonDuplicates,
    };

    if (uploadIds.length > 1) {
      payload.upload_ids = uploadIds;
    } else if (uploadIds.length === 1) {
      payload.upload_id = uploadIds[0];
    } else if (uploadResult) {
      payload.upload_id = uploadResult.upload_id;
    }

    const result = await importTransactions(payload as never);

    if (result) {
      setImportResult(result);
      goToStep(4);
    } else {
      setError('Import failed. Please try again.');
    }
  }, [uploadIds, uploadResult, transactions, importTransactions, goToStep]);

  // --- Reset wizard for another upload ---
  const resetWizard = useCallback(() => {
    stopPolling();
    setCurrentStep(0);
    setUploadMode(hasExistingAccounts ? 'existing' : 'new');
    setSelectedAccountId(null);
    setBankName('');
    setCustomBankName('');
    setAccountNickname('');
    setSelectedFiles([]);
    setTransactions([]);
    setUploadResult(null);
    setBatchStatus(null);
    setBatchMeta(null);
    setUploadIds([]);
    setImportResult(null);
    setError(null);
    setUploadingCount(0);
  }, [hasExistingAccounts, stopPolling]);

  // --- Render each step ---

  const renderStep = () => {
    switch (currentStep) {
      // === STEP 1: Account Info ===
      case 0:
        return (
          <div className="space-y-5">
            <div className="text-center mb-2">
              <h3 className="text-base font-semibold text-sw-text">
                Where should we import?
              </h3>
              <p className="text-xs text-sw-muted mt-1">
                Upload bank statements to add older transactions or fill in gaps.
              </p>
            </div>

            {/* Mode toggle — only show when there are existing accounts */}
            {hasExistingAccounts && (
              <div className="flex rounded-lg border border-sw-border overflow-hidden">
                <button
                  onClick={() => {
                    setUploadMode('existing');
                    setSelectedAccountId(null);
                  }}
                  className={`flex-1 px-4 py-2.5 text-sm font-medium transition ${
                    uploadMode === 'existing'
                      ? 'bg-sw-accent text-white'
                      : 'bg-sw-card text-sw-muted hover:text-sw-text hover:bg-sw-bg'
                  }`}
                >
                  Existing Account
                </button>
                <button
                  onClick={() => {
                    setUploadMode('new');
                    setSelectedAccountId(null);
                  }}
                  className={`flex-1 px-4 py-2.5 text-sm font-medium transition ${
                    uploadMode === 'new'
                      ? 'bg-sw-accent text-white'
                      : 'bg-sw-card text-sw-muted hover:text-sw-text hover:bg-sw-bg'
                  }`}
                >
                  New Account
                </button>
              </div>
            )}

            {/* Existing account selector */}
            {uploadMode === 'existing' && hasExistingAccounts && (
              <div className="space-y-2">
                <label className="block text-xs font-medium text-sw-muted">
                  Select an account to add transactions to
                </label>
                <div className="space-y-2 max-h-64 overflow-y-auto">
                  {existingAccounts.map((account) => (
                    <button
                      key={account.id}
                      onClick={() => setSelectedAccountId(account.id)}
                      className={`w-full flex items-center gap-3 p-3 rounded-xl border text-left transition ${
                        selectedAccountId === account.id
                          ? 'border-sw-accent bg-sw-accent-light ring-1 ring-sw-accent'
                          : 'border-sw-border bg-sw-bg hover:border-sw-border-strong'
                      }`}
                    >
                      <div
                        className={`w-9 h-9 rounded-lg flex items-center justify-center shrink-0 ${
                          selectedAccountId === account.id
                            ? 'bg-sw-accent text-white'
                            : 'bg-sw-surface border border-sw-border text-sw-dim'
                        }`}
                      >
                        {account.connection?.is_plaid ? (
                          <Wifi size={16} />
                        ) : (
                          <Building2 size={16} />
                        )}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <span className="text-sm font-semibold text-sw-text truncate">
                            {account.name}
                          </span>
                          {account.mask && (
                            <span className="text-xs text-sw-dim">****{account.mask}</span>
                          )}
                        </div>
                        <div className="flex items-center gap-2 mt-0.5">
                          {account.institution_name && (
                            <span className="text-[11px] text-sw-dim">
                              {account.institution_name}
                            </span>
                          )}
                          <Badge variant="neutral">
                            {formatAccountType(account.type, account.subtype ?? null)}
                          </Badge>
                          {account.connection?.is_plaid && (
                            <Badge variant="info">Plaid</Badge>
                          )}
                        </div>
                      </div>
                      {selectedAccountId === account.id && (
                        <CheckCircle2 size={18} className="text-sw-accent shrink-0" />
                      )}
                    </button>
                  ))}
                </div>
                {selectedAccountId !== null && (
                  <p className="text-[11px] text-sw-muted mt-1">
                    Duplicates will be automatically detected and skipped during import.
                  </p>
                )}
              </div>
            )}

            {/* New account form — same as original */}
            {uploadMode === 'new' && (
              <>
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
              </>
            )}

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
                Upload your bank statements
              </h3>
              <p className="text-xs text-sw-muted mt-1">
                {uploadMode === 'existing' ? (
                  <>
                    Uploading to{' '}
                    <span className="font-semibold text-sw-text">{targetAccountName}</span>.{' '}
                    Drop a full year of monthly statements to backfill your history.
                  </>
                ) : (
                  <>
                    Download statements from your bank's website (usually under "Documents"
                    or "Statements") and upload the PDF or CSV files here.
                  </>
                )}
              </p>
            </div>

            <FileDropZone
              multiple
              onFileSelect={(file) => setSelectedFiles([file])}
              selectedFile={selectedFiles.length === 1 ? selectedFiles[0] : null}
              onClear={() => setSelectedFiles([])}
              onFilesSelect={setSelectedFiles}
              selectedFiles={selectedFiles}
              onClearFile={(index) =>
                setSelectedFiles((prev) => prev.filter((_, i) => i !== index))
              }
              maxFiles={24}
            />

            {/* Help tips */}
            <div className="rounded-xl border border-sw-border bg-sw-bg p-4 space-y-2.5">
              <p className="text-xs font-semibold text-sw-text">Tips for best results</p>
              <ul className="space-y-1.5">
                {[
                  'Download the official statement from your bank (PDF preferred over CSV for better merchant name detection)',
                  'You can upload up to 24 statements at once — each PDF takes 2\u20133 minutes to process',
                  'Overlapping statement periods are handled automatically with cross-file duplicate detection',
                  'For credit cards, the statement should include individual transaction details',
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
                disabled={selectedFiles.length === 0 || uploading}
                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {uploading ? (
                  <Loader2 size={14} className="animate-spin" />
                ) : (
                  <Sparkles size={14} />
                )}
                {selectedFiles.length > 1
                  ? `Extract from ${selectedFiles.length} Files`
                  : 'Extract Transactions'}
              </button>
            </div>
          </div>
        );

      // === STEP 3: Processing ===
      case 2:
        return (
          <div className="space-y-5">
            {/* Uploading indicator (batch only, while files are still being sent to server) */}
            {isBatch && uploadingCount > 0 && !batchStatus && (
              <div className="flex flex-col items-center text-center py-6">
                <div className="w-16 h-16 rounded-2xl flex items-center justify-center mb-4 bg-sw-accent-light border border-blue-200">
                  <Loader2 size={28} className="text-sw-accent animate-spin" />
                </div>
                <h3 className="text-base font-semibold text-sw-text">
                  Uploading {selectedFiles.length} files...
                </h3>
                <p className="text-sm text-sw-muted mt-1">
                  {uploadingCount} remaining
                </p>
              </div>
            )}

            {/* Batch processing status */}
            {isBatch && batchStatus && (
              <BatchProcessingStatus
                batchStatus={batchStatus}
                onContinue={() => goToStep(3)}
              />
            )}

            {/* Single file processing status */}
            {!isBatch && <ProcessingStatus status={processingStatus} />}

            {/* Error retry for single file */}
            {!isBatch && processingStatus.status === 'error' && (
              <div className="flex justify-center gap-3 pt-2">
                <button
                  onClick={() => {
                    stopPolling();
                    isResumeMode ? onCancel() : goToStep(1);
                  }}
                  className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
                >
                  <ArrowLeft size={14} />
                  {isResumeMode ? 'Close' : 'Try Again'}
                </button>
              </div>
            )}

            {/* Error state for batch (when all uploads fail before polling starts) */}
            {isBatch && !batchStatus && uploadingCount === 0 && error && (
              <div className="space-y-4">
                <div className="flex items-center gap-2 rounded-lg border border-sw-danger/20 bg-sw-danger-light p-3">
                  <AlertTriangle size={14} className="text-sw-danger shrink-0" />
                  <span className="text-xs text-sw-danger">{error}</span>
                </div>
                <div className="flex justify-center">
                  <button
                    onClick={() => {
                      stopPolling();
                      isResumeMode ? onCancel() : goToStep(1);
                    }}
                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
                  >
                    <ArrowLeft size={14} />
                    {isResumeMode ? 'Close' : 'Try Again'}
                  </button>
                </div>
              </div>
            )}
          </div>
        );

      // === STEP 4: Review ===
      case 3: {
        const duplicatesCount = isBatch
          ? (batchMeta?.duplicates_found ?? 0)
          : (uploadResult?.duplicates_found ?? 0);

        const dateRange = isBatch
          ? batchMeta?.date_range
          : uploadResult?.date_range;

        const processingNotes = isBatch
          ? (batchMeta?.processing_notes ?? [])
          : (uploadResult?.processing_notes ?? []);

        return (
          <div className="space-y-5">
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
              <div>
                <h3 className="text-base font-semibold text-sw-text">
                  Review extracted transactions
                </h3>
                <p className="text-xs text-sw-muted mt-0.5">
                  Check the transactions below and fix any mistakes before importing.
                  {dateRange && dateRange.from && (
                    <span className="text-sw-text font-medium">
                      {' '}
                      Covering{' '}
                      {new Intl.DateTimeFormat('en-US', {
                        month: 'short',
                        day: 'numeric',
                      }).format(new Date(dateRange.from))}{' '}
                      &mdash;{' '}
                      {new Intl.DateTimeFormat('en-US', {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                      }).format(new Date(dateRange.to))}
                      .
                    </span>
                  )}
                  {isBatch && batchMeta && (
                    <span className="text-sw-dim">
                      {' '}
                      From {batchMeta.files_included} file
                      {batchMeta.files_included !== 1 ? 's' : ''}.
                    </span>
                  )}
                </p>
              </div>
            </div>

            {/* Cross-file duplicate note */}
            {isBatch && batchMeta && batchMeta.cross_file_duplicates > 0 && (
              <div className="flex items-start gap-2 rounded-xl border border-blue-200 bg-blue-50/50 p-3">
                <CheckCircle2 size={14} className="text-sw-accent shrink-0 mt-0.5" />
                <p className="text-xs text-sw-muted leading-relaxed">
                  <span className="font-semibold text-sw-text">
                    {batchMeta.cross_file_duplicates} cross-file duplicate
                    {batchMeta.cross_file_duplicates !== 1 ? 's' : ''}
                  </span>{' '}
                  detected from overlapping statement periods and marked for skipping.
                </p>
              </div>
            )}

            {/* Processing notes */}
            {processingNotes.length > 0 && (
              <div className="rounded-xl border border-blue-200 bg-blue-50/50 p-4">
                <p className="text-xs font-semibold text-sw-text mb-1.5">
                  Processing notes
                </p>
                <ul className="space-y-1">
                  {processingNotes.map((note, i) => (
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
              duplicatesCount={duplicatesCount}
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
                onClick={() => isResumeMode ? onCancel() : goToStep(1)}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
              >
                <ArrowLeft size={14} />
                {isResumeMode ? 'Close' : 'Upload Different Files'}
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
      }

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
                Your transactions from {targetAccountName} are now in SpendifiAI. AI
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
                onClick={resetWizard}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-sw-border text-sw-muted text-sm font-medium hover:text-sw-text hover:bg-sw-card-hover transition"
              >
                <Upload size={14} />
                Upload More
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
