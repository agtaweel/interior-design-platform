import type { ButtonHTMLAttributes } from "react";

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: "primary" | "secondary";
}

const VARIANT_CLASSES: Record<NonNullable<ButtonProps["variant"]>, string> = {
  primary:
    "bg-amber-600 text-white hover:bg-amber-700 disabled:bg-zinc-400 dark:bg-amber-500 dark:text-zinc-950 dark:hover:bg-amber-400",
  secondary:
    "bg-transparent border border-zinc-300 text-zinc-900 hover:border-amber-400 hover:bg-amber-50 dark:border-zinc-700 dark:text-zinc-100 dark:hover:border-amber-500 dark:hover:bg-amber-950/30",
};

export function Button({ variant = "primary", className = "", ...props }: ButtonProps) {
  return (
    <button
      className={`inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-70 ${VARIANT_CLASSES[variant]} ${className}`}
      {...props}
    />
  );
}
