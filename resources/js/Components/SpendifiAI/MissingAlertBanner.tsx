import { useState } from 'react';
import { AlertTriangle, ChevronDown, ChevronUp } from 'lucide-react';

interface MissingAlert {
  message: string;
  details?: string;
}

interface MissingAlertBannerProps {
  alerts: MissingAlert[];
}

export default function MissingAlertBanner({ alerts }: MissingAlertBannerProps) {
  const [expanded, setExpanded] = useState(false);

  if (alerts.length === 0) return null;

  return (
    <div className="bg-amber-50 border border-amber-200 rounded-lg p-3">
      <div className="flex items-center gap-3">
        <AlertTriangle size={18} className="text-amber-600 shrink-0" />
        <div className="flex-1">
          <p className="text-sm font-semibold text-amber-900">
            {alerts.length} expected document{alerts.length !== 1 ? 's' : ''} missing
          </p>
        </div>
        <button
          onClick={() => setExpanded(!expanded)}
          className="flex items-center gap-1 text-xs text-amber-700 font-medium hover:text-amber-900 transition"
        >
          {expanded ? 'Hide' : 'Details'}
          {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
        </button>
      </div>

      {expanded && (
        <div className="mt-3 space-y-2 pl-8">
          {alerts.map((alert, idx) => (
            <div key={idx} className="text-sm text-amber-800">
              <p className="font-medium">{alert.message}</p>
              {alert.details && (
                <p className="text-xs text-amber-600 mt-0.5">{alert.details}</p>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
