import { useState, useRef, useCallback } from 'react';
import { Upload, FileText, X, AlertCircle } from 'lucide-react';

interface FileDropZoneProps {
  onFileSelect: (file: File) => void;
  acceptedTypes?: string[];
  maxSizeMb?: number;
  selectedFile: File | null;
  onClear: () => void;
}

const ACCEPTED_EXTENSIONS = ['.pdf', '.csv'];
const ACCEPTED_MIMES = [
  'application/pdf',
  'text/csv',
  'application/vnd.ms-excel',
  'text/plain',
];

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function getFileIcon(file: File): string {
  if (file.type === 'application/pdf' || file.name.endsWith('.pdf')) return 'PDF';
  return 'CSV';
}

export default function FileDropZone({
  onFileSelect,
  maxSizeMb = 25,
  selectedFile,
  onClear,
}: FileDropZoneProps) {
  const [isDragging, setIsDragging] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const validateFile = useCallback(
    (file: File): string | null => {
      const extension = '.' + file.name.split('.').pop()?.toLowerCase();
      if (!ACCEPTED_EXTENSIONS.includes(extension)) {
        return `Unsupported file type. Please upload a PDF or CSV file.`;
      }
      if (file.size > maxSizeMb * 1024 * 1024) {
        return `File is too large. Maximum size is ${maxSizeMb}MB.`;
      }
      return null;
    },
    [maxSizeMb],
  );

  const handleFile = useCallback(
    (file: File) => {
      const validationError = validateFile(file);
      if (validationError) {
        setError(validationError);
        return;
      }
      setError(null);
      onFileSelect(file);
    },
    [validateFile, onFileSelect],
  );

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(true);
  }, []);

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(false);
  }, []);

  const handleDrop = useCallback(
    (e: React.DragEvent) => {
      e.preventDefault();
      e.stopPropagation();
      setIsDragging(false);

      const files = e.dataTransfer.files;
      if (files.length > 0) {
        handleFile(files[0]);
      }
    },
    [handleFile],
  );

  const handleInputChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const files = e.target.files;
      if (files && files.length > 0) {
        handleFile(files[0]);
      }
      // Reset input so same file can be re-selected
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    },
    [handleFile],
  );

  // File selected state
  if (selectedFile) {
    const fileType = getFileIcon(selectedFile);
    return (
      <div className="space-y-3">
        <div className="flex items-center gap-4 p-4 rounded-xl border border-sw-accent/30 bg-sw-accent-light">
          <div className="w-12 h-12 rounded-lg bg-sw-accent/10 border border-sw-accent/20 flex items-center justify-center shrink-0">
            <FileText size={22} className="text-sw-accent" />
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2">
              <span className="text-sm font-semibold text-sw-text truncate">
                {selectedFile.name}
              </span>
              <span className="shrink-0 px-2 py-0.5 rounded-md bg-sw-accent/10 text-sw-accent text-[10px] font-bold uppercase tracking-wide">
                {fileType}
              </span>
            </div>
            <span className="text-xs text-sw-muted mt-0.5 block">
              {formatFileSize(selectedFile.size)}
            </span>
          </div>
          <button
            onClick={onClear}
            className="p-2 rounded-lg text-sw-muted hover:text-sw-danger hover:bg-sw-danger-light transition"
            aria-label="Remove selected file"
          >
            <X size={16} />
          </button>
        </div>

        {error && (
          <div className="flex items-center gap-2 text-sw-danger text-xs">
            <AlertCircle size={14} className="shrink-0" />
            <span>{error}</span>
          </div>
        )}
      </div>
    );
  }

  // Drop zone state
  return (
    <div className="space-y-3">
      <div
        role="button"
        tabIndex={0}
        onClick={() => inputRef.current?.click()}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            inputRef.current?.click();
          }
        }}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
        className={`relative flex flex-col items-center justify-center gap-3 p-8 sm:p-10 rounded-xl border-2 border-dashed transition-all duration-200 cursor-pointer ${
          isDragging
            ? 'border-sw-accent bg-sw-accent-light scale-[1.01]'
            : error
              ? 'border-sw-danger/40 bg-sw-danger-light'
              : 'border-sw-border hover:border-sw-accent/50 hover:bg-sw-accent-light/50'
        }`}
        aria-label="Drop a file here or click to browse"
      >
        <div
          className={`w-14 h-14 rounded-2xl flex items-center justify-center transition-colors ${
            isDragging
              ? 'bg-sw-accent/10 text-sw-accent'
              : 'bg-sw-surface text-sw-dim'
          }`}
        >
          <Upload size={24} />
        </div>

        <div className="text-center">
          <p className="text-sm font-semibold text-sw-text">
            {isDragging ? 'Drop your file here' : 'Drag and drop your statement'}
          </p>
          <p className="text-xs text-sw-muted mt-1">
            or{' '}
            <span className="text-sw-accent font-medium underline underline-offset-2">
              browse files
            </span>
          </p>
        </div>

        <div className="flex items-center gap-3 mt-1">
          <span className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-red-50 border border-red-200 text-[10px] font-semibold text-red-600 uppercase tracking-wide">
            PDF
          </span>
          <span className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-emerald-50 border border-emerald-200 text-[10px] font-semibold text-emerald-600 uppercase tracking-wide">
            CSV
          </span>
          <span className="text-[11px] text-sw-dim">
            Up to {maxSizeMb}MB
          </span>
        </div>

        <input
          ref={inputRef}
          type="file"
          accept=".pdf,.csv,application/pdf,text/csv"
          onChange={handleInputChange}
          className="hidden"
          aria-hidden="true"
        />
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-sw-danger/20 bg-sw-danger-light p-3">
          <AlertCircle size={14} className="text-sw-danger shrink-0" />
          <span className="text-xs text-sw-danger">{error}</span>
        </div>
      )}
    </div>
  );
}
