import { useState, useEffect, useCallback, useRef } from 'react';
import { Search, X } from 'lucide-react';

export interface FilterState {
  date_from?: string;
  date_to?: string;
  category?: string;
  account_purpose?: string;
  search?: string;
}

interface FilterBarProps {
  filters: FilterState;
  onChange: (filters: FilterState) => void;
  categories?: string[];
}

export default function FilterBar({ filters, onChange, categories = [] }: FilterBarProps) {
  const [localSearch, setLocalSearch] = useState(filters.search || '');
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const handleSearchChange = useCallback(
    (value: string) => {
      setLocalSearch(value);
      if (debounceRef.current !== null) clearTimeout(debounceRef.current);
      debounceRef.current = setTimeout(() => {
        onChange({ ...filters, search: value || undefined });
      }, 300);
    },
    [filters, onChange]
  );

  useEffect(() => {
    return () => {
      if (debounceRef.current !== null) clearTimeout(debounceRef.current);
    };
  }, []);

  const update = (key: keyof FilterState, value: string) => {
    onChange({ ...filters, [key]: value || undefined });
  };

  const clearAll = () => {
    setLocalSearch('');
    onChange({});
  };

  const hasFilters =
    filters.date_from || filters.date_to || filters.category || filters.account_purpose || filters.search;

  const inputClasses =
    'px-3 py-2 rounded-lg border border-sw-border bg-sw-card text-sw-text text-xs focus:outline-none focus:border-sw-accent transition';

  return (
    <div className="flex flex-wrap items-center gap-3 mb-6">
      {/* Search */}
      <div className="relative flex-1 min-w-[200px]">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-sw-dim" />
        <input
          type="text"
          value={localSearch}
          onChange={(e) => handleSearchChange(e.target.value)}
          placeholder="Search transactions..."
          aria-label="Search transactions"
          className={`${inputClasses} w-full pl-9`}
        />
      </div>

      {/* Date from */}
      <input
        type="date"
        value={filters.date_from || ''}
        onChange={(e) => update('date_from', e.target.value)}
        aria-label="Start date"
        className={inputClasses}
        placeholder="From"
      />

      {/* Date to */}
      <input
        type="date"
        value={filters.date_to || ''}
        onChange={(e) => update('date_to', e.target.value)}
        aria-label="End date"
        className={inputClasses}
        placeholder="To"
      />

      {/* Category dropdown */}
      {categories.length > 0 && (
        <select
          value={filters.category || ''}
          onChange={(e) => update('category', e.target.value)}
          className={inputClasses}
        >
          <option value="">All Categories</option>
          {categories.map((cat) => (
            <option key={cat} value={cat}>{cat}</option>
          ))}
        </select>
      )}

      {/* Business/Personal toggle */}
      <div className="flex rounded-lg border border-sw-border overflow-hidden">
        {[
          { label: 'All', value: '' },
          { label: 'Personal', value: 'personal' },
          { label: 'Business', value: 'business' },
        ].map((opt) => (
          <button
            key={opt.value}
            onClick={() => update('account_purpose', opt.value)}
            className={`px-3 py-2 text-xs font-medium transition ${
              (filters.account_purpose || '') === opt.value
                ? 'bg-sw-accent/10 text-sw-accent'
                : 'bg-sw-card text-sw-muted hover:text-sw-text'
            }`}
          >
            {opt.label}
          </button>
        ))}
      </div>

      {/* Clear filters */}
      {hasFilters && (
        <button
          onClick={clearAll}
          className="flex items-center gap-1 px-3 py-2 text-xs text-sw-muted hover:text-sw-danger transition"
        >
          <X size={12} /> Clear
        </button>
      )}
    </div>
  );
}
