/**
 * Brand mark: a blocky "F" (evokes stacked flooring planks / a floor-plan corner) in a rounded
 * amber badge — amber is scoped to this mark only, not introduced as a UI accent color, since
 * the rest of the design system (buttons, badges, active nav state) is deliberately grayscale.
 */

interface FitoutLogoProps {
  variant?: "full" | "icon";
  className?: string;
}

export function FitoutLogo({ variant = "full", className = "" }: FitoutLogoProps) {
  const icon = (
    <svg viewBox="0 0 32 32" className="h-full w-full" aria-hidden="true">
      <rect width="32" height="32" rx="8" fill="#D97706" />
      <rect x="9" y="7" width="5" height="18" rx="1" fill="#FFFFFF" />
      <rect x="9" y="7" width="15" height="5" rx="1" fill="#FFFFFF" />
      <rect x="9" y="15" width="11" height="5" rx="1" fill="#FFFFFF" />
    </svg>
  );

  if (variant === "icon") {
    return <span className={`inline-block ${className}`}>{icon}</span>;
  }

  return (
    <span className={`inline-flex items-center gap-2 ${className}`}>
      <span className="h-6 w-6 shrink-0">{icon}</span>
      <span className="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
        Fitout
      </span>
    </span>
  );
}
