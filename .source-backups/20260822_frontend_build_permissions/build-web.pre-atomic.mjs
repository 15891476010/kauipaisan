import { chmodSync, existsSync, mkdirSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { spawnSync } from "node:child_process";

const projectRoot = process.cwd();
const vite = join(projectRoot, "node_modules", ".bin", "vite");
const output = mkdtempSync(join(tmpdir(), "kps-web-build-"));
const dist = resolve(projectRoot, "dist");

function run(command, args) {
  const result = spawnSync(command, args, { cwd: projectRoot, stdio: "inherit" });
  if (result.status !== 0) throw new Error(`${command} failed with exit code ${result.status ?? 1}`);
}

try {
  if (!existsSync(vite)) throw new Error(`Vite executable not found: ${vite}`);
  mkdirSync(dist, { recursive: true });
  chmodSync(output, 0o755);
  run(vite, ["build", "--outDir", output, "--emptyOutDir"]);
  run("rsync", ["-a", "--delete", "--delay-updates", "--exclude=.user.ini", `${output}/`, `${dist}/`]);
  console.log(`Production assets synchronized to ${dist}; preserved dist/.user.ini`);
} finally {
  rmSync(output, { recursive: true, force: true });
}
