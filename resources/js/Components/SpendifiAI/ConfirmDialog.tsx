import { Dialog, DialogPanel, DialogTitle, DialogBackdrop } from '@headlessui/react';
import { AlertTriangle } from 'lucide-react';

interface ConfirmDialogProps {
  open: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  title: string;
  message: string;
  confirmText?: string;
  variant?: 'danger' | 'default';
}

export default function ConfirmDialog({
  open,
  onConfirm,
  onCancel,
  title,
  message,
  confirmText = 'Confirm',
  variant = 'default',
}: ConfirmDialogProps) {
  const isDanger = variant === 'danger';

  return (
    <Dialog open={open} onClose={onCancel} className="relative z-50">
      <DialogBackdrop className="fixed inset-0 bg-black/20 backdrop-blur-sm" />

      <div className="fixed inset-0 flex items-center justify-center p-4">
        <DialogPanel className="w-full max-w-md rounded-2xl border border-sw-border bg-sw-card p-6 shadow-xl">
          <div className="flex items-start gap-4">
            {isDanger && (
              <div className="w-10 h-10 rounded-lg flex items-center justify-center bg-sw-danger/10 border border-sw-danger/20 shrink-0">
                <AlertTriangle size={20} className="text-sw-danger" />
              </div>
            )}
            <div className="flex-1">
              <DialogTitle className="text-lg font-semibold text-sw-text">{title}</DialogTitle>
              <p className="mt-2 text-sm text-sw-muted leading-relaxed">{message}</p>
            </div>
          </div>

          <div className="mt-6 flex justify-end gap-3">
            <button
              onClick={onCancel}
              className="px-4 py-2 rounded-lg border border-sw-border bg-transparent text-sw-muted text-sm font-medium hover:bg-sw-card-hover transition"
            >
              Cancel
            </button>
            <button
              onClick={onConfirm}
              className={`px-4 py-2 rounded-lg border-0 text-sm font-semibold transition ${
                isDanger
                  ? 'bg-sw-danger text-white hover:bg-red-600'
                  : 'bg-sw-accent text-white hover:bg-sw-accent-hover'
              }`}
            >
              {confirmText}
            </button>
          </div>
        </DialogPanel>
      </div>
    </Dialog>
  );
}
