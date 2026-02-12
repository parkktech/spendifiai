interface StatItem {
    value: string;
    label: string;
}

interface StatsCounterProps {
    items: StatItem[];
}

export default function StatsCounter({ items }: StatsCounterProps) {
    return (
        <div className="grid grid-cols-2 gap-8 lg:grid-cols-4">
            {items.map((item) => (
                <div key={item.label} className="text-center">
                    <div className="text-3xl font-bold text-sw-text sm:text-4xl">
                        {item.value}
                    </div>
                    <div className="mt-2 text-sm font-medium text-sw-muted">{item.label}</div>
                </div>
            ))}
        </div>
    );
}
