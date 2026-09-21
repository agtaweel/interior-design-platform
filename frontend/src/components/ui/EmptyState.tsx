export function EmptyState({ message }: { message: string }) {
  return (
    <p className="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{message}</p>
  );
}
