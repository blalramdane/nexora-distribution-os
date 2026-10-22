import { spawnSync } from "node:child_process";
import { cpSync, existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";

const apiPort = process.env.E2E_API_PORT ?? "8018";
const env = {
  ...process.env,
  NEXORA_E2E: "1",
  NEXT_PUBLIC_API_URL: `http://127.0.0.1:${apiPort}/api/v1`,
};

const nextCli = resolve("node_modules/next/dist/bin/next");
const generatedFiles = ["next-env.d.ts", "tsconfig.json"];
const originals = new Map(generatedFiles.map((file) => [file, readFileSync(file, "utf8")]));
let result;

try {
  result = spawnSync(process.execPath, [nextCli, "build"], {
    cwd: process.cwd(),
    env,
    stdio: "inherit",
  });
} finally {
  // Next.js may rewrite these shared files for the selected distDir. Restore
  // their exact pre-build contents so E2E cannot perturb the dev workspace.
  for (const [file, contents] of originals) writeFileSync(file, contents, "utf8");
}

if (result.error) {
  console.error("Unable to start the isolated Next.js E2E build:", result.error.message);
  process.exit(1);
}

if (result.status === 0) {
  // With output: "standalone", server.js is the supported production entry
  // point. Because this build uses the custom distDir ".next-e2e", the server
  // resolves browser assets from standalone/.next-e2e/static (not standalone/.next/static).
  // Next.js does not copy public/ or the static assets into that folder by
  // default, so include both in the isolated artifact just as Docker does.
  const standalone = resolve(".next-e2e/standalone");
  const staticFiles = resolve(".next-e2e/static");
  const publicFiles = resolve("public");
  if (!existsSync(resolve(standalone, "server.js"))) {
    console.error("The standalone E2E build did not emit server.js.");
    process.exit(1);
  }
  if (!existsSync(staticFiles) || !existsSync(publicFiles)) {
    console.error("The standalone E2E build is missing public/ or .next-e2e/static/.");
    process.exit(1);
  }
  mkdirSync(resolve(standalone, ".next-e2e"), { recursive: true });
  cpSync(staticFiles, resolve(standalone, ".next-e2e/static"), { recursive: true, force: true });
  cpSync(publicFiles, resolve(standalone, "public"), { recursive: true, force: true });
}

process.exit(result.status ?? 1);
