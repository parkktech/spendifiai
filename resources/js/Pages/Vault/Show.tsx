import { useState, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Archive, RefreshCw, Loader2, AlertTriangle, FileText, Image, Link2 } from 'lucide-react';
import { useApi, useApiPost } from '@/hooks/useApi';
import ExtractionPanel from '@/Components/SpendifiAI/ExtractionPanel';
import AuditLogTable from '@/Components/SpendifiAI/AuditLogTable';
import ConfidenceBadge from '@/Components/SpendifiAI/ConfidenceBadge';
import AnnotationThread from '@/Components/SpendifiAI/AnnotationThread';
import LinkedExpensesPanel from '@/Components/SpendifiAI/LinkedExpensesPanel';
import type { TaxDocument, TaxVaultAuditEntry, DocumentAnnotation, IntelligenceResult } from '@/types/spendifiai';

interface ShowProps {
  documentId: number;
}

type TabKey = 'document' | 'fields' | 'expenses' | 'audit' | 'comments';

function buildTabs(category: string | null, status: string | null): { key: TabKey; label: string }[] {
  const tabs: { key: TabKey; label: string }[] = [
    { key: 'document', label: 'Document' },
    { key: 'fields', label: 'Extracted Fields' },
  ];
  // Show linked transactions tab for all ready documents
  if (status === 'ready') {
    tabs.push({ key: 'expenses', label: 'Linked Transactions' });
  }
  tabs.push({ key: 'audit', label: 'Audit Log' });
  tabs.push({ key: 'comments', label: 'Comments' });
  return tabs;
}

function StatusBadge({ status }: { status: string }) {
  const styles: Record<string, string> = {
    ready: 'bg-emerald-500/20 text-emerald-400',
    classifying: 'bg-amber-500/20 text-amber-400',
    extracting: 'bg-amber-500/20 text-amber-400',
    upload: 'bg-blue-500/20 text-blue-400',
    failed: 'bg-red-500/20 text-red-400',
  };
  return (
    <span className={`inline-flex items-center text-xs px-2 py-0.5 rounded-full font-medium ${styles[status] ?? 'bg-sw-border text-sw-muted'}`}>
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  );
}

function DocumentViewer({ document }: { document: TaxDocument }) {
  const [blobUrl, setBlobUrl] = useState<string | null>(null);
  const [loadError, setLoadError] = useState(false);
  const isPdf = document.mime_type === 'application/pdf';
  const isImage = document.mime_type.startsWith('image/');

  useEffect(() => {
    let revoke: string | null = null;
    if (isPdf || isImage) {
      axios
        .get(`/api/v1/tax-vault/documents/${document.id}/stream`, { responseType: 'blob' })
        .then((res) => {
          const url = URL.createObjectURL(res.data);
          revoke = url;
          setBlobUrl(url);
        })
        .catch(() => setLoadError(true));
    }
    return () => {
      if (revoke) URL.revokeObjectURL(revoke);
    };
  }, [document.id, isPdf, isImage]);

  if (loadError) {
    return (
      <div className="flex flex-col items-center justify-center h-full text-center p-8">
        <AlertTriangle size={32} className="text-sw-dim mb-3" />
        <p className="text-sm text-sw-muted">Could not load document preview.</p>
      </div>
    );
  }

  if ((isPdf || isImage) && !blobUrl) {
    return (
      <div className="flex items-center justify-center h-full">
        <Loader2 size={24} className="animate-spin text-sw-accent" />
      </div>
    );
  }

  if (isPdf && blobUrl) {
    return (
      <iframe
        src={blobUrl}
        className="w-full h-full border-0 rounded-lg"
        title={document.original_filename}
      />
    );
  }

  if (isImage && blobUrl) {
    return (
      <img
        src={blobUrl}
        alt={document.original_filename}
        className="w-full h-full object-contain rounded-lg"
      />
    );
  }

  return (
    <div className="flex flex-col items-center justify-center h-full text-center p-8">
      <FileText size={48} className="text-sw-dim mb-3" />
      <p className="text-sm text-sw-muted">Preview not available for this file type.</p>
    </div>
  );
}

export default function VaultShow({ documentId }: ShowProps) {
  const [activeTab, setActiveTab] = useState<TabKey>('document');
  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const { data: docData, loading, error, refresh } = useApi<{ data: TaxDocument }>(
    `/api/v1/tax-vault/documents/${documentId}`,
  );

  const { data: auditData, loading: auditLoading, refresh: refreshAudit } = useApi<{ data: TaxVaultAuditEntry[] }>(
    `/api/v1/tax-vault/documents/${documentId}/audit-log`,
    { immediate: false },
  );

  const { data: annotationsData, refresh: refreshAnnotations } = useApi<{ data: DocumentAnnotation[] }>(
    `/api/v1/tax-vault/documents/${documentId}/annotations`,
    { immediate: false },
  );

  const { data: intelligence } = useApi<IntelligenceResult>(
    `/api/v1/tax-vault/intelligence?year=${docData?.data?.tax_year ?? new Date().getFullYear()}`,
    { enabled: !!docData?.data },
  );

  const linkedTransaction = (intelligence?.transaction_links ?? []).find(
    (link) => link.document_id === documentId,
  );

  const { submit: retryExtraction, loading: retrying } = useApiPost(
    `/api/v1/tax-vault/documents/${documentId}/retry-extraction`,
  );

  const document = docData?.data ?? null;

  // Poll for status changes when classifying/extracting
  useEffect(() => {
    if (document && (document.status === 'classifying' || document.status === 'extracting')) {
      pollingRef.current = setInterval(() => {
        refresh();
      }, 5000);
    } else if (pollingRef.current) {
      clearInterval(pollingRef.current);
      pollingRef.current = null;
    }

    return () => {
      if (pollingRef.current) {
        clearInterval(pollingRef.current);
      }
    };
  }, [document?.status]); // eslint-disable-line react-hooks/exhaustive-deps

  // Fetch audit log when tab switches to audit
  useEffect(() => {
    if (activeTab === 'audit') {
      refreshAudit();
    }
    if (activeTab === 'comments') {
      refreshAnnotations();
    }
  }, [activeTab]); // eslint-disable-line react-hooks/exhaustive-deps

  const handleFieldUpdated = () => {
    refresh();
  };

  const handleRetryExtraction = async () => {
    await retryExtraction();
    refresh();
  };

  const isProcessing = document?.status === 'classifying' || document?.status === 'extracting';
  const isFailed = document?.status === 'failed';

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center gap-2">
          <Archive size={20} className="text-sw-accent" />
          <h1 className="text-lg font-bold text-sw-text">Document Detail</h1>
        </div>
      }
    >
      <Head title={document?.original_filename ?? 'Document'} />

      <div className="max-w-7xl mx-auto space-y-4">
        {/* Header bar */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href="/vault" className="text-sw-muted hover:text-sw-accent transition-colors">
              <ArrowLeft size={18} />
            </Link>
            {document && (
              <>
                <h2 className="text-sm font-semibold text-sw-text truncate max-w-md">
                  {document.original_filename}
                </h2>
                {document.category_label && (
                  <span className="text-xs px-2 py-0.5 rounded-full bg-sw-card border border-sw-border text-sw-muted">
                    {document.category_label}
                  </span>
                )}
                <StatusBadge status={document.status} />
              </>
            )}
          </div>
        </div>

        {/* Loading state */}
        {loading && !document && (
          <div className="flex items-center justify-center py-20">
            <Loader2 size={24} className="animate-spin text-sw-accent" />
          </div>
        )}

        {/* Error state */}
        {error && (
          <div className="text-center py-12">
            <AlertTriangle size={32} className="mx-auto text-red-400 mb-2" />
            <p className="text-sm text-red-400">{error}</p>
            <button onClick={refresh} className="mt-2 text-sm text-sw-accent hover:underline">
              Try again
            </button>
          </div>
        )}

        {/* Processing indicator */}
        {document && isProcessing && (
          <div className="flex items-center gap-3 px-4 py-3 rounded-lg bg-amber-500/10 border border-amber-500/20">
            <Loader2 size={16} className="animate-spin text-amber-400" />
            <p className="text-sm text-amber-400">
              {document.status === 'classifying' ? 'Classifying document...' : 'Extracting data...'}
              {' '}This page will update automatically.
            </p>
          </div>
        )}

        {/* Failed state */}
        {document && isFailed && (
          <div className="flex items-center justify-between px-4 py-3 rounded-lg bg-red-500/10 border border-red-500/20">
            <div className="flex items-center gap-3">
              <AlertTriangle size={16} className="text-red-400" />
              <p className="text-sm text-red-400">Extraction failed. You can retry the extraction process.</p>
            </div>
            <button
              onClick={handleRetryExtraction}
              disabled={retrying}
              className="flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-red-500/20 text-red-400 hover:bg-red-500/30 disabled:opacity-50 transition-colors"
            >
              {retrying ? <Loader2 size={12} className="animate-spin" /> : <RefreshCw size={12} />}
              Retry Extraction
            </button>
          </div>
        )}

        {/* Tabs */}
        {document && (
          <>
            <div className="flex gap-1 border-b border-sw-border">
              {buildTabs(document.category, document.status).map((tab) => (
                <button
                  key={tab.key}
                  onClick={() => setActiveTab(tab.key)}
                  className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                    activeTab === tab.key
                      ? 'border-sw-accent text-sw-accent'
                      : 'border-transparent text-sw-muted hover:text-sw-text'
                  }`}
                >
                  {tab.label}
                </button>
              ))}
            </div>

            {/* Document tab: split panel */}
            {activeTab === 'document' && (
              <div className="flex gap-6 h-[calc(100vh-280px)]">
                {/* Left panel: document viewer */}
                <div className="w-1/2 bg-sw-card rounded-lg border border-sw-border overflow-hidden">
                  <DocumentViewer document={document} />
                </div>

                {/* Right panel: extraction panel */}
                <div className="w-1/2 bg-sw-card rounded-lg border border-sw-border overflow-hidden">
                  <ExtractionPanel
                    fields={document.extracted_data?.fields}
                    overallConfidence={document.extracted_data?.overall_confidence}
                    documentId={document.id}
                    onFieldUpdated={handleFieldUpdated}
                  />
                </div>
              </div>
            )}

            {/* Extracted Fields tab: full width */}
            {activeTab === 'fields' && (
              <div className="bg-sw-card rounded-lg border border-sw-border min-h-[400px]">
                <ExtractionPanel
                  fields={document.extracted_data?.fields}
                  overallConfidence={document.extracted_data?.overall_confidence}
                  documentId={document.id}
                  onFieldUpdated={handleFieldUpdated}
                />
              </div>
            )}

            {/* Linked Transactions tab — all document types */}
            {activeTab === 'expenses' && (
              <div className="bg-sw-card rounded-lg border border-sw-border">
                <LinkedExpensesPanel
                  documentId={document.id}
                  documentCategory={document.category ?? 'other'}
                  grossIncome={(() => {
                    const f = document.extracted_data?.fields ?? {};
                    return Number(
                      f.nonemployee_compensation?.value ?? f.gross_amount?.value
                      ?? f.wages?.value ?? f.interest_income?.value
                      ?? f.ordinary_dividends?.value ?? f.gross_distribution?.value
                      ?? f.gross_proceeds?.value ?? f.unemployment_compensation?.value
                      ?? f.total_amount?.value ?? 0,
                    );
                  })()}
                />
              </div>
            )}

            {/* Audit Log tab */}
            {activeTab === 'audit' && (
              <AuditLogTable
                entries={auditData?.data ?? []}
                isLoading={auditLoading}
              />
            )}

            {/* Comments tab */}
            {activeTab === 'comments' && (
              <div className="bg-sw-card rounded-lg border border-sw-border min-h-[300px]">
                <AnnotationThread
                  documentId={documentId}
                  annotations={annotationsData?.data ?? []}
                  onAnnotationAdded={refreshAnnotations}
                />
              </div>
            )}
          </>
        )}

      </div>
    </AuthenticatedLayout>
  );
}
