import type { ButtonHTMLAttributes } from "react";

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: "primary" | "secondary";
}

// Ledger style (see the `.ledger` tokens in globals.css): the primary action is near-black
// (inverted to white on dark), the secondary is the surface with a 1px edge — no brand colour on
// controls. Literal zinc values rather than the `--l-*` variables because buttons render on every
// screen, most of which sit outside a `.ledger` scope. Disabled stays legible (no opacity fade).
const VARIANT_CLASSES: Record<NonNullable<ButtonProps["variant"]>, string> = {
  primary:
    "bg-zinc-950 text-white hover:bg-zinc-800 disabled:bg-zinc-300 disabled:text-zinc-600 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 dark:disabled:bg-zinc-800 dark:disabled:text-zinc-400",
  secondary:
    "border border-zinc-300 bg-white text-zinc-900 hover:border-zinc-500 hover:bg-zinc-50 disabled:text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:border-zinc-500 dark:hover:bg-zinc-800 dark:disabled:text-zinc-500",
};

export function Button({ variant = "primary", className = "", ...props }: ButtonProps) {
  return (
    <button
      className={`inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-950 disabled:cursor-not-allowed dark:focus-visible:outline-white ${VARIANT_CLASSES[variant]} ${className}`}
      {...props}
    />
  );
}
