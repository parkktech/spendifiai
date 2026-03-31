import { useState, useEffect, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import TaxYearTabs from '@/Components/SpendifiAI/TaxYearTabs';
import DocumentCard from '@/Components/SpendifiAI/DocumentCard';
import DocumentUploadZone from '@/Components/SpendifiAI/DocumentUploadZone';
import MissingAlertBanner from '@/Components/SpendifiAI/MissingAlertBanner';
import DocumentRequestCard from '@/Components/SpendifiAI/DocumentRequestCard';
import type { TaxDocument, TaxDocumentCategory, VaultCategoryCard, DocumentRequest, IntelligenceResult } from '@/types/spendifiai';

const currentYear = new Date().getFullYear();

/** All category definitions with human-readable labels */
const CATEGORY_DEFS: { category: TaxDocumentCategory; label: string }[] = [
  { category: 'w2', label: 'W-2' },
  { category: '1099_nec', label: '1099-NEC' },
  { category: '1099_int', label: '1099-INT' },
  { category: '1099_misc', label: '1099-MISC' },
  { category: '1099_div', label: '1099-DIV' },
  { category: '1098', label: '1098' },
  { category: 'receipts', label: 'Receipts' },
  { category: 'other', label: 'Other' },
];

/** Generate year list from current year back 5 years */
function generateYears(): number[] {
  const years: number[] = [];
  for (let y = currentYear; y >= currentYear - 5; y--) {
    years.push(y);
  }
  return years;
}

/** Group documents by category into VaultCategoryCard objects */
function buildCategoryCards(documents: TaxDocument[]): VaultCategoryCard[] {
  const grouped = new Map<TaxDocumentCategory, TaxDocument[]>();

  for (const doc of documents) {
    const cat = doc.category || 'other';
    if (!grouped.has(cat)) grouped.set(cat, []);
    grouped.get(cat)!.push(doc);
  }

  return CATEGORY_DEFS.map(({ category, label }) => {
    const docs = grouped.get(category) || [];
    const ready = docs.filter((d) => d.status === 'ready').length;
    const processing = docs.filter((d) => d.status === 'classifying' || d.status === 'extracting' || d.status === 'upload').length;
    const failed = docs.filter((d) => d.status === 'failed').length;

    return {
      category,
      label,
      count: docs.length,
      statuses: { ready, processing, failed },
      documents: docs,
    };
  });
}

const years = generateYears();

export default function VaultIndex() {
  const [selectedYear, setSelectedYear] = useState(currentYear);
  const [expandedCards, setExpandedCards] = useState<Set<TaxDocumentCategory>>(new Set());

  const { data, loading, error, refresh } = useApi<{ data: TaxDocument[] }>(
    `/api/v1/tax-vault/documents?year=${selectedYear}`,
  );

  const { data: requestsData } = useApi<{ data: DocumentRequest[] }>('/api/v1/document-requests');

  const { data: intelligence, refresh: refreshIntelligence } = useApi<IntelligenceResult>(
    `/api/v1/tax-vault/intelligence?year=${selectedYear}`,
  );

  useEffect(() => {
    refresh();
    refreshIntelligence();
  }, [selectedYear]); // eslint-disable-line react-hooks/exhaustive-deps

  const documents = data?.data || [];
  const categoryCards = useMemo(() => buildCategoryCards(documents), [documents]);
  const pendingRequests = (requestsData?.data ?? []).filter((r) => r.status === 'pending');

  const missingAlerts = useMemo(() => [
    ...(intelligence?.missing_documents ?? []).map((m) => ({ message: m.message, details: m.details })),
    ...(intelligence?.anomalies ?? []).map((a) => ({ message: a.message, details: a.details })),
  ], [intelligence]);

  const toggleCard = (category: TaxDocumentCategory) => {
    setExpandedCards((prev) => {
      const next = new Set(prev);
      if (next.has(category)) {
        next.delete(category);
      } else {
        next.add(category);
      }
      return next;
    });
  };

  const handleUploadComplete = () => {
    refresh();
  };

  return (
    <AuthenticatedLayout
      header={
        <div className="flex items-center gap-2">
          <Archive size={20} className="text-sw-accent" />
          <h1 className="text-lg font-bold text-sw-text">Tax Vault</h1>
        </div>
      }
    >
      <Head title="Tax Vault" />

      <div className="max-w-6xl mx-auto space-y-6">
        {/* Page description */}
        <div>
          <p className="text-sm text-sw-muted">
            Upload and organize your tax documents by year and category. Documents are securely stored and available for export or sharing with your accountant.
          </p>
        </div>

        {/* Document request alerts from accountant */}
        {pendingRequests.length > 0 && (
          <div className="space-y-2">
            <h3 className="text-sm font-semibold text-sw-text">Document Requests from Your Accountant</h3>
            {pendingRequests.map((req) => (
              <DocumentRequestCard
                key={req.id}
                request={req}
                onUpload={() => {
                  const uploadZone = document.getElementById('upload-zone');
                  if (uploadZone) uploadZone.scrollIntoView({ behavior: 'smooth' });
                }}
              />
            ))}
          </div>
        )}

        {/* Missing document + anomaly alerts from intelligence */}
        <MissingAlertBanner alerts={missingAlerts} />

        {/* Year tabs */}
        <TaxYearTabs
          years={years}
          selectedYear={selectedYear}
          onChange={setSelectedYear}
        />

        {/* Loading state */}
        {loading && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="h-20 rounded-lg bg-sw-card border border-sw-border animate-pulse" />
            ))}
          </div>
        )}

        {/* Error state */}
        {error && (
          <div className="text-center py-8">
            <p className="text-sm text-sw-danger">{error}</p>
            <button
              onClick={refresh}
              className="mt-2 text-sm text-sw-accent hover:underline"
            >
              Try again
            </button>
          </div>
        )}

        {/* Category grid */}
        {!loading && !error && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {categoryCards.map((card) => (
              <DocumentCard
                key={card.category}
                category={card}
                isExpanded={expandedCards.has(card.category)}
                onToggle={() => toggleCard(card.category)}
              />
            ))}
          </div>
        )}

        {/* Upload zone */}
        <div id="upload-zone" className="bg-sw-card rounded-lg border border-sw-border p-5">
          <h2 className="text-sm font-semibold text-sw-text mb-3">Upload Documents</h2>
          <DocumentUploadZone
            taxYear={selectedYear}
            onUploadComplete={handleUploadComplete}
          />
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
