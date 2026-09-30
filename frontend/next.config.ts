import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Standalone output: `next build` traces the actual module dependencies of each route and
  // emits a minimal self-contained server (`.next/standalone/server.js` + only the node_modules
  // files it actually needs) instead of requiring the full node_modules tree at runtime. This is
  // the officially recommended mode for memory/disk-constrained container deploys (free-tier
  // PaaS runners) — see frontend/Dockerfile.production, which copies only this trimmed output
  // into the final image rather than `npm ci`'s full install.
  output: "standalone",
  // Hides the floating Next.js dev-mode badge (bottom-left "N" indicator) — this is a demo/
  // client-facing app, not a screen developers are meant to poke at while it's running.
  devIndicators: false,
};

export default nextConfig;
