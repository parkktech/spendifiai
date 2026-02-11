import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { PiggyBank } from 'lucide-react';

export default function SavingsIndex() {
  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Savings</h1>
          <p className="text-xs text-sw-dim mt-0.5">AI-powered recommendations to cut costs</p>
        </div>
      }
    >
      <Head title="Savings" />
      <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center">
        <PiggyBank size={40} className="mx-auto text-sw-dim mb-3" />
        <h3 className="text-sm font-semibold text-sw-text mb-1">Savings</h3>
        <p className="text-xs text-sw-muted">
          Savings recommendations are coming in a future update.
        </p>
      </div>
    </AuthenticatedLayout>
  );
}
