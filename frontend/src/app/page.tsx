import { redirect } from "next/navigation";

/**
 * Root path just forwards to the dashboard. Auth is enforced client-side by
 * app/(protected)/layout.tsx (token lives in localStorage — see lib/auth), which bounces
 * unauthenticated visitors to /login.
 */
export default function RootPage() {
  redirect("/dashboard");
}
