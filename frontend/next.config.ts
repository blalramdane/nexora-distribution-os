import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  output: "standalone",
  // The frontend is a self-contained project even though a tiny root package-lock
  // also exists in the repository. Keep tracing and module resolution within it.
  outputFileTracingRoot: process.cwd(),
  turbopack: { root: process.cwd() },
  // Keep browser-test artifacts separate from the development server's .next directory.
  distDir: process.env.NEXORA_DIST_DIR || (process.env.NEXORA_E2E === "1" ? ".next-e2e" : ".next"),
  // Keep API traffic on the same public origin (important for temporary tunnels).
  // In Docker, API_INTERNAL_URL points to the private backend service; local dev
  // defaults to the isolated NEXORA API port.
  async rewrites() {
    const apiOrigin = (process.env.API_INTERNAL_URL || "http://127.0.0.1:8017").replace(/\/$/, "");
    return [{ source: "/api/:path*", destination: `${apiOrigin}/api/:path*` }];
  },
};

export default nextConfig;
