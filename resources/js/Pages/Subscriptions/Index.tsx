import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';

export default function SubscriptionsIndex() {
  return (
    <AuthenticatedLayout
      header={
        <div>
          <h1 className="text-xl font-bold text-sw-text tracking-tight">Subscriptions</h1>
          <p className="text-xs text-sw-dim mt-0.5">Track and manage all recurring charges</p>
        </div>
      }
    >
      <Head title="Subscriptions" />
      <div className="rounded-2xl border border-sw-border bg-sw-card p-12 text-center">
        <CreditCard size={40} className="mx-auto text-sw-dim mb-3" />
        <h3 className="text-sm font-semibold text-sw-text mb-1">Subscriptions</h3>
        <p className="text-xs text-sw-muted">
          Subscription management is coming in a future update.
        </p>
      </div>
    </AuthenticatedLayout>
  );
}
