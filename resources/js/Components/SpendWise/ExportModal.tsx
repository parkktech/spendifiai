import { useState } from 'react';
import { Dialog, DialogPanel, DialogTitle, DialogBackdrop } from '@headlessui/react';
import { Download, Send, Loader2, CheckCircle } from 'lucide-react';
import axios from 'axios';

interface ExportModalProps {
  open: boolean;
  onClose: () => void;
  year: number;
  mode: 'download' | 'email';
  onExport?: () => void;
}

export default function ExportModal({ open, onClose, year, mode, onExport }: ExportModalProps) {
  const [formats, setFormats] = useState({ excel: true, pdf: true, csv: true });
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const selectedFormats = Object.entries(formats)
    .filter(([, v]) => v)
    .map(([k]) => k);

  const toggleFormat = (fmt: 'excel' | 'pdf' | 'csv') => {
    setFormats((prev) => ({ ...prev, [fmt]: !prev[fmt] }));
  };

  const handleClose = () => {
    setSuccess(false);
    setError(null);
    setLoading(false);
    onClose();
  };

  const handleExport = async () => {
    if (selectedFormats.length === 0) return;
    setLoading(true);
    setError(null);

    try {
      if (mode === 'download') {
        const response = await axios.post('/api/v1/tax/export', { year });
        // Download each selected format using the API download route
        const formatMap: Record<string, string> = { excel: 'xlsx', pdf: 'pdf', csv: 'csv' };
        for (const fmt of selectedFormats) {
          const type = formatMap[fmt] ?? fmt;
          if (response.data?.downloads?.[type]) {
            window.open(`/api/v1/tax/download/${year}/${type}`, '_blank');
          }
        }
        setSuccess(true);
        onExport?.();
      } else {
        if (!email.trim()) {
          setError('Please enter an email address.');
          setLoading(false);
          return;
        }
        await axios.post('/api/v1/tax/send-to-accountant', {
          year,
          accountant_email: email.trim(),
          message: message.trim() || undefined,
        });
        setSuccess(true);
        onExport?.();
      }
    } catch (err) {
      const axiosErr = err as { response?: { data?: { message?: string } }; message?: string };
      setError(axiosErr.response?.data?.message || axiosErr.message || 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

  const isDownload = mode === 'download';

  return (
    <Dialog open={open} onClose={handleClose} className="relative z-50">
      <DialogBackdrop className="fixed inset-0 bg-sw-bg/80 backdrop-blur-sm" />

      <div className="fixed inset-0 flex items-center justify-center p-4">
        <DialogPanel className="w-full max-w-md rounded-2xl border border-sw-border bg-sw-card p-6 shadow-xl">
          <DialogTitle className="flex items-center gap-3 text-lg font-semibold text-sw-text mb-4">
            {isDownload ? (
              <>
                <Download size={20} className="text-sw-accent" />
                Export Tax Package
              </>
            ) : (
              <>
                <Send size={20} className="text-sw-accent" />
                Send to Accountant
              </>
            )}
          </DialogTitle>

          {success ? (
            <div className="text-center py-6">
              <CheckCircle size={40} className="mx-auto text-sw-accent mb-3" />
              <p className="text-sm text-sw-text font-medium">
                {isDownload ? 'Export generated successfully!' : 'Tax package sent successfully!'}
              </p>
              <button
                onClick={handleClose}
                className="mt-4 px-4 py-2 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-sm font-semibold transition"
              >
                Done
              </button>
            </div>
          ) : (
            <>
              {/* Year display */}
              <div className="text-xs text-sw-muted mb-4">
                Tax Year: <span className="font-semibold text-sw-text">{year}</span>
              </div>

              {/* Email fields (email mode only) */}
              {!isDownload && (
                <div className="space-y-3 mb-4">
                  <div>
                    <label htmlFor="export-accountant-email" className="block text-xs text-sw-muted font-medium mb-1">
                      Accountant Email
                    </label>
                    <input
                      id="export-accountant-email"
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      placeholder="accountant@example.com"
                      className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent"
                    />
                  </div>
                  <div>
                    <label htmlFor="export-message" className="block text-xs text-sw-muted font-medium mb-1">
                      Message (optional)
                    </label>
                    <textarea
                      id="export-message"
                      value={message}
                      onChange={(e) => setMessage(e.target.value)}
                      placeholder="Here are my expense reports for the year..."
                      rows={3}
                      className="w-full px-3 py-2 rounded-lg border border-sw-border bg-sw-bg text-sw-text text-sm placeholder:text-sw-dim focus:outline-none focus:border-sw-accent resize-none"
                    />
                  </div>
                </div>
              )}

              {/* Format selection */}
              <div className="mb-4">
                <label className="block text-xs text-sw-muted font-medium mb-2">
                  Export Formats
                </label>
                <div className="flex gap-3">
                  {(['excel', 'pdf', 'csv'] as const).map((fmt) => (
                    <label
                      key={fmt}
                      htmlFor={`export-format-${fmt}`}
                      className="flex items-center gap-2 cursor-pointer text-sm text-sw-text"
                    >
                      <input
                        id={`export-format-${fmt}`}
                        type="checkbox"
                        checked={formats[fmt]}
                        onChange={() => toggleFormat(fmt)}
                        className="w-4 h-4 rounded border-sw-border bg-sw-bg text-sw-accent focus:ring-sw-accent focus:ring-offset-0"
                      />
                      {fmt.toUpperCase()}
                    </label>
                  ))}
                </div>
              </div>

              {/* Error */}
              {error && (
                <div className="text-xs text-sw-danger mb-3">{error}</div>
              )}

              {/* Actions */}
              <div className="flex justify-end gap-3">
                <button
                  onClick={handleClose}
                  className="px-4 py-2 rounded-lg border border-sw-border bg-transparent text-sw-muted text-sm font-medium hover:bg-sw-card-hover transition"
                >
                  Cancel
                </button>
                <button
                  onClick={handleExport}
                  disabled={loading || selectedFormats.length === 0}
                  className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-sw-accent hover:bg-sw-accent-hover text-white text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {loading ? (
                    <>
                      <Loader2 size={14} className="animate-spin" />
                      {isDownload ? 'Generating...' : 'Sending...'}
                    </>
                  ) : isDownload ? (
                    <>
                      <Download size={14} />
                      Generate Export
                    </>
                  ) : (
                    <>
                      <Send size={14} />
                      Send
                    </>
                  )}
                </button>
              </div>
            </>
          )}
        </DialogPanel>
      </div>
    </Dialog>
  );
}
