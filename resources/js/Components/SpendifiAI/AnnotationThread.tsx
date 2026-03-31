import { useState } from 'react';
import { MessageSquare, Reply, Send, Loader2 } from 'lucide-react';
import { useApiPost } from '@/hooks/useApi';
import Badge from '@/Components/SpendifiAI/Badge';
import type { DocumentAnnotation } from '@/types/spendifiai';

interface AnnotationThreadProps {
    documentId: number;
    annotations: DocumentAnnotation[];
    onAnnotationAdded: () => void;
    apiPrefix?: string;
}

function formatRelativeTime(dateStr: string): string {
    const d = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function AnnotationCard({
    annotation,
    documentId,
    onAnnotationAdded,
    apiPrefix,
    depth = 0,
}: {
    annotation: DocumentAnnotation;
    documentId: number;
    onAnnotationAdded: () => void;
    apiPrefix: string;
    depth?: number;
}) {
    const [showReplyForm, setShowReplyForm] = useState(false);
    const [replyText, setReplyText] = useState('');
    const { submit, loading } = useApiPost<unknown, { body: string; parent_id: number }>(
        `${apiPrefix}/documents/${documentId}/annotations`,
    );

    const handleReply = async () => {
        if (!replyText.trim()) return;
        const result = await submit({ body: replyText.trim(), parent_id: annotation.id });
        if (result) {
            setReplyText('');
            setShowReplyForm(false);
            onAnnotationAdded();
        }
    };

    const isAccountant = annotation.author.user_type === 'accountant';

    return (
        <div className={depth > 0 ? 'ml-6 border-l-2 border-sw-border pl-4' : ''}>
            <div className="rounded-lg border border-sw-border bg-sw-card p-3">
                {/* Author line */}
                <div className="flex items-center gap-2 mb-1.5">
                    <span className="text-sm font-medium text-sw-text">{annotation.author.name}</span>
                    <Badge variant={isAccountant ? 'info' : 'neutral'}>
                        {isAccountant ? 'Accountant' : 'Client'}
                    </Badge>
                    <span className="text-xs text-sw-muted">{formatRelativeTime(annotation.created_at)}</span>
                </div>

                {/* Body */}
                <p className="text-sm text-sw-text whitespace-pre-wrap">{annotation.body}</p>

                {/* Reply button -- only at depth 0 or 1 (max depth 2) */}
                {depth < 2 && (
                    <button
                        onClick={() => setShowReplyForm(!showReplyForm)}
                        className="mt-2 inline-flex items-center gap-1 text-xs text-sw-muted hover:text-sw-accent transition"
                    >
                        <Reply size={12} /> Reply
                    </button>
                )}

                {/* Inline reply form */}
                {showReplyForm && (
                    <div className="mt-2 flex gap-2">
                        <input
                            type="text"
                            value={replyText}
                            onChange={(e) => setReplyText(e.target.value)}
                            placeholder="Write a reply..."
                            className="flex-1 px-3 py-1.5 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition"
                            onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleReply(); } }}
                        />
                        <button
                            onClick={handleReply}
                            disabled={loading || !replyText.trim()}
                            className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-sw-accent text-white text-xs font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
                        >
                            {loading ? <Loader2 size={12} className="animate-spin" /> : <Send size={12} />}
                        </button>
                    </div>
                )}
            </div>

            {/* Replies */}
            {annotation.replies && annotation.replies.length > 0 && (
                <div className="mt-2 space-y-2">
                    {annotation.replies.map((reply) => (
                        <AnnotationCard
                            key={reply.id}
                            annotation={reply}
                            documentId={documentId}
                            onAnnotationAdded={onAnnotationAdded}
                            apiPrefix={apiPrefix}
                            depth={Math.min(depth + 1, 2)}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

export default function AnnotationThread({ documentId, annotations, onAnnotationAdded, apiPrefix }: AnnotationThreadProps) {
    const [newComment, setNewComment] = useState('');
    const resolvedPrefix = apiPrefix || '/api/v1/tax-vault';
    const { submit, loading } = useApiPost<unknown, { body: string }>(
        `${resolvedPrefix}/documents/${documentId}/annotations`,
    );

    const topLevel = annotations.filter((a) => a.parent_id === null);

    const handleSubmit = async () => {
        if (!newComment.trim()) return;
        const result = await submit({ body: newComment.trim() });
        if (result) {
            setNewComment('');
            onAnnotationAdded();
        }
    };

    return (
        <div className="p-4 space-y-4">
            {/* Empty state */}
            {topLevel.length === 0 && (
                <div className="text-center py-8">
                    <MessageSquare size={32} className="mx-auto text-sw-dim mb-2" />
                    <p className="text-sm text-sw-muted">No comments yet. Start the conversation.</p>
                </div>
            )}

            {/* Annotation list */}
            {topLevel.length > 0 && (
                <div className="space-y-3">
                    {topLevel.map((annotation) => (
                        <AnnotationCard
                            key={annotation.id}
                            annotation={annotation}
                            documentId={documentId}
                            onAnnotationAdded={onAnnotationAdded}
                            apiPrefix={resolvedPrefix}
                        />
                    ))}
                </div>
            )}

            {/* New comment form */}
            <div className="border-t border-sw-border pt-4">
                <div className="flex gap-2">
                    <textarea
                        value={newComment}
                        onChange={(e) => setNewComment(e.target.value)}
                        placeholder="Add a comment..."
                        rows={2}
                        className="flex-1 px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm focus:outline-none focus:border-sw-accent transition resize-none"
                    />
                    <button
                        onClick={handleSubmit}
                        disabled={loading || !newComment.trim()}
                        className="self-end inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent-hover transition disabled:opacity-50"
                    >
                        {loading ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}
                        Send
                    </button>
                </div>
            </div>
        </div>
    );
}
