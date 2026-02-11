import { Globe, User, Briefcase } from 'lucide-react';

interface ViewModeToggleProps {
  value: 'all' | 'personal' | 'business';
  onChange: (mode: 'all' | 'personal' | 'business') => void;
}

const modes: Array<{ key: 'all' | 'personal' | 'business'; label: string; Icon: typeof Globe }> = [
  { key: 'all', label: 'All', Icon: Globe },
  { key: 'personal', label: 'Personal', Icon: User },
  { key: 'business', label: 'Business', Icon: Briefcase },
];

export default function ViewModeToggle({ value, onChange }: ViewModeToggleProps) {
  return (
    <div role="radiogroup" className="inline-flex items-center rounded-lg border border-sw-border bg-sw-card p-0.5">
      {modes.map(({ key, label, Icon }) => {
        const active = value === key;
        return (
          <button
            key={key}
            onClick={() => onChange(key)}
            role="radio"
            aria-checked={active}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition ${
              active
                ? 'bg-sw-accent text-white'
                : 'bg-transparent text-sw-muted hover:text-sw-text'
            }`}
          >
            <Icon size={13} />
            {label}
          </button>
        );
      })}
    </div>
  );
}
