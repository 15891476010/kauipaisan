import assert from "node:assert/strict";
import { existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import { publishBuiltAssets } from "./build-web.mjs";

function fixture() {
  const root = mkdtempSync(join(tmpdir(), "kps-publish-test-"));
  const output = join(root, "output");
  const dist = join(root, "dist");
  mkdirSync(join(output, "assets"), { recursive: true });
  mkdirSync(join(dist, "assets"), { recursive: true });
  writeFileSync(join(output, "index.html"), '<script src="/assets/new.js"></script><link href="/assets/new.css">');
  writeFileSync(join(output, "assets", "new.js"), "new-js");
  writeFileSync(join(output, "assets", "new.css"), "new-css");
  writeFileSync(join(dist, "index.html"), '<script src="/assets/old.js"></script><link href="/assets/old.css">');
  writeFileSync(join(dist, "assets", "old.js"), "old-js");
  writeFileSync(join(dist, "assets", "old.css"), "old-css");
  writeFileSync(join(dist, ".user.ini"), "immutable-site-config");
  return { root, output, dist };
}

test("assets exist before the atomic entry switch and old hashes remain", () => {
  const value = fixture();
  try {
    const oldIndex = readFileSync(join(value.dist, "index.html"), "utf8");
    publishBuiltAssets(value.output, value.dist, { beforeIndexSwap: () => {
      assert.equal(readFileSync(join(value.dist, "index.html"), "utf8"), oldIndex);
      for (const file of ["old.js", "old.css", "new.js", "new.css"]) assert.equal(existsSync(join(value.dist, "assets", file)), true);
    } });
    assert.match(readFileSync(join(value.dist, "index.html"), "utf8"), /new\.js/);
    assert.equal(existsSync(join(value.dist, "assets", "old.js")), true);
    assert.equal(readFileSync(join(value.dist, ".user.ini"), "utf8"), "immutable-site-config");
  } finally { rmSync(value.root, { recursive: true, force: true }); }
});

test("failure before rename leaves the old entry and references intact", () => {
  const value = fixture();
  try {
    const oldIndex = readFileSync(join(value.dist, "index.html"), "utf8");
    assert.throws(() => publishBuiltAssets(value.output, value.dist, { beforeIndexSwap: () => { throw new Error("injected publish failure"); } }), /injected publish failure/);
    assert.equal(readFileSync(join(value.dist, "index.html"), "utf8"), oldIndex);
    assert.equal(existsSync(join(value.dist, "assets", "old.js")), true);
    assert.equal(existsSync(join(value.dist, "assets", "old.css")), true);
    assert.equal(readFileSync(join(value.dist, ".user.ini"), "utf8"), "immutable-site-config");
  } finally { rmSync(value.root, { recursive: true, force: true }); }
});
