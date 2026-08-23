import { chmodSync, copyFileSync, existsSync, mkdirSync, mkdtempSync, renameSync, rmSync, unlinkSync } from "node:fs";
import { tmpdir } from "node:os";
import { basename, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";

function run(command, args, cwd = process.cwd()) {
  const result = spawnSync(command, args, { cwd, stdio: "inherit" });
  if (result.status !== 0) throw new Error(`${command} failed with exit code ${result.status ?? 1}`);
}

export function publishBuiltAssets(output, dist, options = {}) {
  const source = resolve(output);
  const destination = resolve(dist);
  const sourceIndex = join(source, "index.html");
  if (!existsSync(sourceIndex)) throw new Error(`Built entry not found: ${sourceIndex}`);

  mkdirSync(destination, { recursive: true });
  chmodSync(source, 0o755);
  const stagedIndex = join(destination, `.index.html.${process.pid}.${Date.now()}.tmp`);
  try {
    // Old content hashes remain valid while new assets are copied.
    run("rsync", ["-a", "--exclude=/index.html", "--exclude=/.user.ini", `${source}/`, `${destination}/`]);
    options.beforeIndexSwap?.({ source, destination });
    copyFileSync(sourceIndex, stagedIndex);
    chmodSync(stagedIndex, 0o644);
    renameSync(stagedIndex, join(destination, "index.html"));
    chmodSync(destination, 0o755);
  } finally {
    if (existsSync(stagedIndex)) unlinkSync(stagedIndex);
  }
}

function buildAndPublish() {
  const projectRoot = process.cwd();
  const vite = join(projectRoot, "node_modules", ".bin", "vite");
  const output = mkdtempSync(join(tmpdir(), "kps-web-build-"));
  const additionalTargets = process.argv.slice(2)
    .filter((argument) => argument.startsWith("--target="))
    .map((argument) => resolve(argument.slice("--target=".length)));
  const targets = [...new Set([resolve(projectRoot, "dist"), ...additionalTargets])];

  try {
    if (!existsSync(vite)) throw new Error(`Vite executable not found: ${vite}`);
    chmodSync(output, 0o755);
    run(vite, ["build", "--outDir", output, "--emptyOutDir"], projectRoot);
    for (const target of targets) {
      publishBuiltAssets(output, target);
      console.log(`Published ${basename(projectRoot)} to ${target}; preserved ${join(target, ".user.ini")}`);
    }
  } finally {
    rmSync(output, { recursive: true, force: true });
  }
}

const invokedFile = process.argv[1] ? resolve(process.argv[1]) : "";
if (invokedFile === fileURLToPath(import.meta.url)) buildAndPublish();
