import { useState, useRef, useCallback, useMemo } from 'react';
import { Upload, FileText, X, AlertCircle } from 'lucide-react';

interface FileDropZoneProps {
  onFileSelect: (file: File) => void;
  maxSizeMb?: number;
  selectedFile: File | null;
  onClear: () => void;
  // Multi-file support
  multiple?: boolean;
  onFilesSelect?: (files: File[]) => void;
  selectedFiles?: File[];
  onClearFile?: (index: number) => void;
  maxFiles?: number;
  // Configurable accepted types (override defaults when provided)
  acceptedExtensions?: string[];
  acceptedMimes?: string[];
  // Upload progress callback
  onProgress?: (percent: number) => void;
}

const DEFAULT_EXTENSIONS = ['.pdf', '.csv'];
const DEFAULT_MIMES = [
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
  if (file.type.startsWith('image/') || /\.(jpg|jpeg|png)$/i.test(file.name)) return 'IMG';
  return 'CSV';
}

function getFileIconStyle(fileType: string): string {
  if (fileType === 'PDF') return 'bg-red-50 text-red-600 border border-red-200';
  if (fileType === 'IMG') return 'bg-blue-50 text-blue-600 border border-blue-200';
  return 'bg-emerald-50 text-emerald-600 border border-emerald-200';
}

/** Build the accept string for <input> from extensions + mimes */
function buildAcceptString(extensions: string[], mimes: string[]): string {
  return [...extensions, ...mimes].join(',');
}

/** Human-readable label for accepted extensions */
function extensionLabels(extensions: string[]): { label: string; style: string }[] {
  const labels: { label: string; style: string }[] = [];
  const seen = new Set<string>();
  for (const ext of extensions) {
    const upper = ext.replace('.', '').toUpperCase();
    if (upper === 'JPG' || upper === 'JPEG') {
      if (!seen.has('JPG')) {
        labels.push({ label: 'JPG', style: 'bg-blue-50 border-blue-200 text-blue-600' });
        seen.add('JPG');
      }
    } else if (upper === 'PNG') {
      if (!seen.has('PNG')) {
        labels.push({ label: 'PNG', style: 'bg-blue-50 border-blue-200 text-blue-600' });
        seen.add('PNG');
      }
    } else if (upper === 'PDF') {
      if (!seen.has('PDF')) {
        labels.push({ label: 'PDF', style: 'bg-red-50 border-red-200 text-red-600' });
        seen.add('PDF');
      }
    } else if (upper === 'CSV') {
      if (!seen.has('CSV')) {
        labels.push({ label: 'CSV', style: 'bg-emerald-50 border-emerald-200 text-emerald-600' });
        seen.add('CSV');
      }
    }
  }
  return labels;
}

export default function FileDropZone({
  onFileSelect,
  maxSizeMb = 25,
  selectedFile,
  onClear,
  multiple = false,
  onFilesSelect,
  selectedFiles = [],
  onClearFile,
  maxFiles = 24,
  acceptedExtensions,
  acceptedMimes,
  onProgress: _onProgress,
}: FileDropZoneProps) {
  const [isDragging, setIsDragging] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const extensions = acceptedExtensions || DEFAULT_EXTENSIONS;
  const mimes = acceptedMimes || DEFAULT_MIMES;
  const acceptString = useMemo(() => buildAcceptString(extensions, mimes), [extensions, mimes]);
  const typeBadges = useMemo(() => extensionLabels(extensions), [extensions]);

  const validateFile = useCallback(
    (file: File): string | null => {
      const extension = '.' + file.name.split('.').pop()?.toLowerCase();
      if (!extensions.includes(extension)) {
        const allowed = extensions.map(e => e.replace('.', '').toUpperCase()).join(', ');
        return `"${file.name}" is not a supported file type. Accepted: ${allowed}.`;
      }
      if (file.size > maxSizeMb * 1024 * 1024) {
        return `"${file.name}" is too large. Maximum size is ${maxSizeMb}MB.`;
      }
      return null;
    },
    [maxSizeMb, extensions],
  );

  // Single file handler
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

  // Multi-file handler
  const handleFiles = useCallback(
    (newFiles: FileList | File[]) => {
      const fileArray = Array.from(newFiles);
      const validFiles: File[] = [];
      const errors: string[] = [];

      const remaining = maxFiles - selectedFiles.length;
      if (remaining <= 0) {
        setError(`Maximum ${maxFiles} files allowed.`);
        return;
      }

      const toProcess = fileArray.slice(0, remaining);
      if (fileArray.length > remaining) {
        errors.push(`Only ${remaining} more file(s) can be added (max ${maxFiles}).`);
      }

      for (const file of toProcess) {
        const validationError = validateFile(file);
        if (validationError) {
          errors.push(validationError);
        } else {
          // Skip files already selected (by name + size)
          const alreadySelected = selectedFiles.some(
            (f) => f.name === file.name && f.size === file.size,
          );
          if (!alreadySelected) {
            validFiles.push(file);
          }
        }
      }

      if (errors.length > 0) {
        setError(errors[0]);
      } else {
        setError(null);
      }

      if (validFiles.length > 0 && onFilesSelect) {
        onFilesSelect([...selectedFiles, ...validFiles]);
      }
    },
    [validateFile, onFilesSelect, selectedFiles, maxFiles],
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
        if (multiple) {
          handleFiles(files);
        } else {
          handleFile(files[0]);
        }
      }
    },
    [multiple, handleFile, handleFiles],
  );

  const handleInputChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const files = e.target.files;
      if (files && files.length > 0) {
        if (multiple) {
          handleFiles(files);
        } else {
          handleFile(files[0]);
        }
      }
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    },
    [multiple, handleFile, handleFiles],
  );

  // --- Multi-file selected state ---
  if (multiple && selectedFiles.length > 0) {
    return (
      <div className="space-y-3">
        {/* File list */}
        <div className="max-h-56 overflow-y-auto space-y-1.5 rounded-xl border border-sw-border bg-sw-bg p-2">
          {selectedFiles.map((file, index) => {
            const fileType = getFileIcon(file);
            return (
              <div
                key={`${file.name}-${file.size}`}
                className="flex items-center gap-3 px-3 py-2 rounded-lg bg-sw-card border border-sw-border/50"
              >
                <span
                  className={`shrink-0 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide ${getFileIconStyle(fileType)}`}
                >
                  {fileType}
                </span>
                <span className="text-xs font-medium text-sw-text truncate flex-1">
                  {file.name}
                </span>
                <span className="text-[10px] text-sw-dim shrink-0">
                  {formatFileSize(file.size)}
                </span>
                {onClearFile && (
                  <button
                    onClick={() => onClearFile(index)}
                    className="p-1 rounded text-sw-dim hover:text-sw-danger hover:bg-sw-danger-light transition shrink-0"
                    aria-label={`Remove ${file.name}`}
                  >
                    <X size={12} />
                  </button>
                )}
              </div>
            );
          })}
        </div>

        {/* Summary + add more */}
        <div className="flex items-center justify-between">
          <span className="text-xs font-medium text-sw-muted">
            {selectedFiles.length} file{selectedFiles.length !== 1 ? 's' : ''} selected
            {selectedFiles.length < maxFiles && (
              <span className="text-sw-dim"> &middot; drop more to add</span>
            )}
          </span>
          {onFilesSelect && (
            <button
              onClick={() => onFilesSelect([])}
              className="text-[11px] text-sw-danger hover:underline"
            >
              Clear all
            </button>
          )}
        </div>

        {/* Compact drop zone for adding more files */}
        {selectedFiles.length < maxFiles && (
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
            className={`flex items-center justify-center gap-2 p-3 rounded-lg border-2 border-dashed transition cursor-pointer ${
              isDragging
                ? 'border-sw-accent bg-sw-accent-light'
                : 'border-sw-border/60 hover:border-sw-accent/50 hover:bg-sw-accent-light/30'
            }`}
          >
            <Upload size={14} className={isDragging ? 'text-sw-accent' : 'text-sw-dim'} />
            <span className="text-xs text-sw-muted">
              Drop more files or{' '}
              <span className="text-sw-accent font-medium">browse</span>
            </span>
          </div>
        )}

        <input
          ref={inputRef}
          type="file"
          accept={acceptString}
          onChange={handleInputChange}
          className="hidden"
          aria-hidden="true"
          multiple
        />

        {error && (
          <div className="flex items-center gap-2 rounded-lg border border-sw-danger/20 bg-sw-danger-light p-3">
            <AlertCircle size={14} className="text-sw-danger shrink-0" />
            <span className="text-xs text-sw-danger">{error}</span>
          </div>
        )}
      </div>
    );
  }

  // --- Single file selected state ---
  if (!multiple && selectedFile) {
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

  // --- Empty drop zone state ---
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
        aria-label={multiple ? 'Drop files here or click to browse' : 'Drop a file here or click to browse'}
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
            {isDragging
              ? `Drop your file${multiple ? 's' : ''} here`
              : multiple
                ? 'Drag and drop your files'
                : 'Drag and drop your file'}
          </p>
          <p className="text-xs text-sw-muted mt-1">
            or{' '}
            <span className="text-sw-accent font-medium underline underline-offset-2">
              browse files
            </span>
            {multiple && (
              <span className="text-sw-dim"> &middot; up to {maxFiles} files</span>
            )}
          </p>
        </div>

        <div className="flex items-center gap-3 mt-1">
          {typeBadges.map((badge) => (
            <span
              key={badge.label}
              className={`inline-flex items-center gap-1 px-2 py-1 rounded-md border text-[10px] font-semibold uppercase tracking-wide ${badge.style}`}
            >
              {badge.label}
            </span>
          ))}
          <span className="text-[11px] text-sw-dim">
            Up to {maxSizeMb}MB each
          </span>
        </div>

        <input
          ref={inputRef}
          type="file"
          accept={acceptString}
          onChange={handleInputChange}
          className="hidden"
          aria-hidden="true"
          {...(multiple ? { multiple: true } : {})}
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
