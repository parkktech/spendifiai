interface TaxYearTabsProps {
  years: number[];
  selectedYear: number;
  onChange: (year: number) => void;
}

export default function TaxYearTabs({ years, selectedYear, onChange }: TaxYearTabsProps) {
  return (
    <div className="flex gap-1 border-b border-sw-border overflow-x-auto">
      {years.map((year) => (
        <button
          key={year}
          onClick={() => onChange(year)}
          className={`px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors ${
            year === selectedYear
              ? 'border-b-2 border-sw-accent text-sw-accent font-semibold'
              : 'text-sw-muted hover:text-sw-text border-b-2 border-transparent'
          }`}
        >
          {year}
        </button>
      ))}
    </div>
  );
}
