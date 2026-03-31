import { AlertCircle, CheckCircle, XCircle, Upload, Calendar, User } from 'lucide-react';
import Badge from '@/Components/SpendifiAI/Badge';
import type { DocumentRequest } from '@/types/spendifiai';

interface DocumentRequestCardProps {
    request: DocumentRequest;
    onUpload?: () => void;
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

const statusConfig: Record<string, { icon: typeof AlertCircle; variant: 'warning' | 'success' | 'neutral'; label: string; borderColor: string }> = {
    pending: { icon: AlertCircle, variant: 'warning', label: 'Pending', borderColor: 'border-l-amber-400' },
    uploaded: { icon: CheckCircle, variant: 'success', label: 'Uploaded', borderColor: 'border-l-emerald-400' },
    dismissed: { icon: XCircle, variant: 'neutral', label: 'Dismissed', borderColor: 'border-l-slate-300' },
};

export default function DocumentRequestCard({ request, onUpload }: DocumentRequestCardProps) {
    const config = statusConfig[request.status] ?? statusConfig.pending;
    const StatusIcon = config.icon;

    return (
        <div className={`rounded-lg border border-sw-border bg-sw-card p-4 border-l-4 ${config.borderColor}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex-1 min-w-0">
                    {/* Header with status */}
                    <div className="flex items-center gap-2 mb-1.5">
                        <StatusIcon size={14} className={request.status === 'pending' ? 'text-amber-500' : request.status === 'uploaded' ? 'text-emerald-500' : 'text-slate-400'} />
                        <span className="text-sm font-medium text-sw-text truncate">{request.description}</span>
                        <Badge variant={config.variant}>{config.label}</Badge>
                    </div>

                    {/* Details */}
                    <div className="flex flex-wrap items-center gap-3 text-xs text-sw-muted">
                        <span className="flex items-center gap-1">
                            <User size={11} /> {request.accountant_name}
                        </span>
                        {request.tax_year && (
                            <span className="flex items-center gap-1">
                                <Calendar size={11} /> {request.tax_year}
                            </span>
                        )}
                        {request.category_label && (
                            <span className="px-1.5 py-0.5 rounded bg-sw-surface text-sw-muted text-[10px] font-medium">
                                {request.category_label}
                            </span>
                        )}
                        <span>{formatDate(request.created_at)}</span>
                    </div>
                </div>

                {/* Upload button for pending requests */}
                {request.status === 'pending' && onUpload && (
                    <button
                        onClick={onUpload}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition shrink-0"
                    >
                        <Upload size={12} /> Upload Document
                    </button>
                )}
            </div>
        </div>
    );
}
