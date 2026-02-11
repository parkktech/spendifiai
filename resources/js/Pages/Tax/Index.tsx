import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { FileText } from 'lucide-react';

export default function TaxIndex() {
  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Tax</h1>
          <p className="text-xs text-sw-dim mt-0.5">Track deductible expenses for tax season</p>
        </div>
      }
    >
      <Head title="Tax" />
      <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center">
        <FileText size={40} className="mx-auto text-sw-dim mb-3" />
        <h3 className="text-sm font-semibold text-sw-text mb-1">Tax Center</h3>
        <p className="text-xs text-sw-muted">
          Tax deduction tracking is coming in a future update.
        </p>
      </div>
    </AuthenticatedLayout>
  );
}
