import { Check } from 'lucide-react';

interface Step {
  label: string;
  description?: string;
}

interface StepIndicatorProps {
  steps: Step[];
  currentStep: number;
}

export default function StepIndicator({ steps, currentStep }: StepIndicatorProps) {
  return (
    <nav aria-label="Upload progress" className="mb-8">
      <ol className="flex items-center w-full">
        {steps.map((step, index) => {
          const isComplete = index < currentStep;
          const isCurrent = index === currentStep;
          const isUpcoming = index > currentStep;

          return (
            <li
              key={step.label}
              className={`flex items-center ${index < steps.length - 1 ? 'flex-1' : ''}`}
            >
              <div className="flex flex-col items-center gap-1.5">
                {/* Circle */}
                <div
                  className={`w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold transition-all duration-300 shrink-0 ${
                    isComplete
                      ? 'bg-sw-accent text-white'
                      : isCurrent
                        ? 'bg-sw-accent text-white ring-4 ring-sw-accent-muted'
                        : 'bg-sw-surface text-sw-dim border border-sw-border'
                  }`}
                  aria-current={isCurrent ? 'step' : undefined}
                >
                  {isComplete ? <Check size={16} /> : index + 1}
                </div>

                {/* Label */}
                <span
                  className={`text-[11px] font-medium text-center leading-tight hidden sm:block ${
                    isComplete || isCurrent ? 'text-sw-text' : 'text-sw-dim'
                  }`}
                >
                  {step.label}
                </span>
              </div>

              {/* Connector line */}
              {index < steps.length - 1 && (
                <div
                  className={`flex-1 h-0.5 mx-2 sm:mx-3 transition-colors duration-300 ${
                    isComplete ? 'bg-sw-accent' : 'bg-sw-border'
                  }`}
                  aria-hidden="true"
                />
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
