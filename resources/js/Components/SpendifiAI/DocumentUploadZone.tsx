import { useState, useCallback } from 'react';
import axios from 'axios';
import { AlertCircle } from 'lucide-react';
import FileDropZone from '@/Components/SpendifiAI/FileDropZone';

interface DocumentUploadZoneProps {
  taxYear: number;
  onUploadComplete: () => void;
}

interface UploadProgress {
  fileName: string;
  percent: number;
  error: string | null;
  done: boolean;
}

export default function DocumentUploadZone({ taxYear, onUploadComplete }: DocumentUploadZoneProps) {
  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
  const [uploading, setUploading] = useState(false);
  const [uploads, setUploads] = useState<UploadProgress[]>([]);

  const handleFilesSelect = useCallback((files: File[]) => {
    setSelectedFiles(files);
  }, []);

  const handleClearFile = useCallback((index: number) => {
    setSelectedFiles((prev) => prev.filter((_, i) => i !== index));
  }, []);

  const handleUpload = useCallback(async () => {
    if (selectedFiles.length === 0) return;
    setUploading(true);

    const progressState: UploadProgress[] = selectedFiles.map((f) => ({
      fileName: f.name,
      percent: 0,
      error: null,
      done: false,
    }));
    setUploads([...progressState]);

    const promises = selectedFiles.map(async (file, idx) => {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('tax_year', String(taxYear));

      try {
        await axios.post('/api/v1/tax-vault/documents', formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: (e) => {
            if (e.total) {
              const pct = Math.round((e.loaded / e.total) * 100);
              progressState[idx] = { ...progressState[idx], percent: pct };
              setUploads([...progressState]);
            }
          },
        });
        progressState[idx] = { ...progressState[idx], percent: 100, done: true };
        setUploads([...progressState]);
      } catch (err: any) {
        const msg = err?.response?.data?.message || err?.message || 'Upload failed';
        progressState[idx] = { ...progressState[idx], error: msg, done: true };
        setUploads([...progressState]);
      }
    });

    await Promise.all(promises);
    setUploading(false);
    setSelectedFiles([]);

    // Notify parent to refresh documents
    onUploadComplete();

    // Clear progress after a delay so user can see results
    setTimeout(() => setUploads([]), 3000);
  }, [selectedFiles, taxYear, onUploadComplete]);

  return (
    <div className="space-y-4">
      <FileDropZone
        onFileSelect={(file) => setSelectedFiles([file])}
        selectedFile={null}
        onClear={() => setSelectedFiles([])}
        multiple
        onFilesSelect={handleFilesSelect}
        selectedFiles={selectedFiles}
        onClearFile={handleClearFile}
        maxSizeMb={100}
        maxFiles={24}
        acceptedExtensions={['.pdf', '.jpg', '.jpeg', '.png']}
        acceptedMimes={['application/pdf', 'image/jpeg', 'image/png']}
      />

      {/* Upload button */}
      {selectedFiles.length > 0 && !uploading && (
        <button
          onClick={handleUpload}
          className="w-full py-2.5 px-4 rounded-lg bg-sw-accent text-white text-sm font-semibold hover:bg-sw-accent/90 transition"
        >
          Upload {selectedFiles.length} file{selectedFiles.length !== 1 ? 's' : ''}
        </button>
      )}

      {/* Upload progress */}
      {uploads.length > 0 && (
        <div className="space-y-2">
          {uploads.map((up, idx) => (
            <div key={idx} className="space-y-1">
              <div className="flex items-center justify-between">
                <span className="text-xs text-sw-text font-medium truncate">{up.fileName}</span>
                {up.error ? (
                  <span className="flex items-center gap-1 text-xs text-sw-danger">
                    <AlertCircle size={12} /> Failed
                  </span>
                ) : (
                  <span className="text-xs text-sw-dim">{up.percent}%</span>
                )}
              </div>
              <div className="h-1.5 rounded-full bg-sw-border overflow-hidden">
                <div
                  className={`h-full rounded-full transition-all duration-300 ${
                    up.error ? 'bg-sw-danger' : up.done ? 'bg-sw-success' : 'bg-sw-accent'
                  }`}
                  style={{ width: `${up.percent}%` }}
                />
              </div>
              {up.error && (
                <p className="text-[10px] text-sw-danger">{up.error}</p>
              )}
            </div>
          ))}
        </div>
      )}

      {uploading && (
        <p className="text-xs text-sw-muted text-center">Uploading documents...</p>
      )}
    </div>
  );
}
