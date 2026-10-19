import { PublicGuide } from "@/components/guide/PublicGuide";

/** Always shows the guide, signed in or not — it's also linked from /login and /signup. */
export default function GuidePage() {
  return <PublicGuide />;
}
