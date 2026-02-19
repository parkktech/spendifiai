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

const FORMAT_OPTIONS = [
  { value: 'xlsx', label: 'Excel Workbook (.xlsx)', group: 'Universal', desc: 'Full workbook with 5 tabs' },
  { value: 'pdf', label: 'PDF Summary', group: 'Universal', desc: 'Multi-page tax report for accountants' },
  { value: 'csv', label: 'Detailed CSV', group: 'Universal', desc: 'All transactions with categories' },
  { value: 'txf', label: 'TurboTax / H&R Block (.txf)', group: 'Tax Software', desc: 'Schedule C lines mapped to TXF format' },
  { value: 'qbo_csv', label: 'QuickBooks Online (.csv)', group: 'Accounting', desc: '3-column format for QBO import' },
  { value: 'ofx', label: 'Xero / Wave / General (.ofx)', group: 'Accounting', desc: 'OFX bank transaction format' },
] as const;

type FormatValue = typeof FORMAT_OPTIONS[number]['value'];

export default function ExportModal({ open, onClose, year, mode, onExport }: ExportModalProps) {
  const [selectedFormats, setSelectedFormats] = useState<Set<FormatValue>>(new Set(['xlsx', 'pdf', 'csv']));
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const toggleFormat = (fmt: FormatValue) => {
    setSelectedFormats((prev) => {
      const next = new Set(prev);
      if (next.has(fmt)) {
        next.delete(fmt);
      } else {
        next.add(fmt);
      }
      return next;
    });
  };

  const handleClose = () => {
    setSuccess(false);
    setError(null);
    setLoading(false);
    onClose();
  };

  const handleExport = async () => {
    if (selectedFormats.size === 0) return;
    setLoading(true);
    setError(null);

    try {
      if (mode === 'download') {
        const response = await axios.post('/api/v1/tax/export', { year });

        // Download each selected format
        for (const fmt of selectedFormats) {
          if (response.data?.downloads?.[fmt]) {
            const dlResponse = await axios.get(`/api/v1/tax/download/${year}/${fmt}`, {
              responseType: 'blob',
            });
            const blob = new Blob([dlResponse.data]);
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = response.data.downloads[fmt].filename || `SpendifiAI_Tax_${year}.${fmt}`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
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

  // Group formats
  const groups = ['Universal', 'Tax Software', 'Accounting'] as const;

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

              {/* Format selection — grouped */}
              <div className="mb-4">
                <label className="block text-xs text-sw-muted font-medium mb-2">
                  Export Formats
                </label>
                <div className="space-y-3 max-h-64 overflow-y-auto">
                  {groups.map((group) => {
                    const groupFormats = FORMAT_OPTIONS.filter((f) => f.group === group);
                    return (
                      <div key={group}>
                        <div className="text-[10px] font-semibold text-sw-dim uppercase tracking-wider mb-1.5">
                          {group}
                        </div>
                        <div className="space-y-1">
                          {groupFormats.map((fmt) => (
                            <label
                              key={fmt.value}
                              htmlFor={`export-format-${fmt.value}`}
                              className={`flex items-start gap-2.5 p-2 rounded-lg cursor-pointer transition border ${
                                selectedFormats.has(fmt.value)
                                  ? 'border-sw-accent/40 bg-sw-accent/5'
                                  : 'border-transparent hover:bg-sw-card-hover'
                              }`}
                            >
                              <input
                                id={`export-format-${fmt.value}`}
                                type="checkbox"
                                checked={selectedFormats.has(fmt.value)}
                                onChange={() => toggleFormat(fmt.value)}
                                className="w-3.5 h-3.5 mt-0.5 rounded border-sw-border bg-sw-bg text-sw-accent focus:ring-sw-accent focus:ring-offset-0"
                              />
                              <div className="flex-1 min-w-0">
                                <div className="text-xs font-medium text-sw-text">{fmt.label}</div>
                                <div className="text-[10px] text-sw-dim">{fmt.desc}</div>
                              </div>
                            </label>
                          ))}
                        </div>
                      </div>
                    );
                  })}
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
                  disabled={loading || selectedFormats.size === 0}
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
                      Generate {selectedFormats.size} File{selectedFormats.size !== 1 ? 's' : ''}
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
