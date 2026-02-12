import { Disclosure, DisclosureButton, DisclosurePanel } from '@headlessui/react';
import { ChevronDown } from 'lucide-react';

interface FAQItem {
    question: string;
    answer: string;
}

interface FAQAccordionProps {
    items: FAQItem[];
}

export default function FAQAccordion({ items }: FAQAccordionProps) {
    return (
        <div className="divide-y divide-sw-border rounded-2xl border border-sw-border">
            {items.map((item, idx) => (
                <Disclosure key={idx}>
                    {({ open }) => (
                        <div>
                            <DisclosureButton className="flex w-full items-center justify-between px-6 py-5 text-left transition-colors hover:bg-sw-card-hover">
                                <span className="text-base font-medium text-sw-text">
                                    {item.question}
                                </span>
                                <ChevronDown
                                    className={`h-5 w-5 shrink-0 text-sw-dim transition-transform duration-200 ${
                                        open ? 'rotate-180' : ''
                                    }`}
                                />
                            </DisclosureButton>
                            <DisclosurePanel className="px-6 pb-5 text-sm leading-relaxed text-sw-muted">
                                {item.answer}
                            </DisclosurePanel>
                        </div>
                    )}
                </Disclosure>
            ))}
        </div>
    );
}
