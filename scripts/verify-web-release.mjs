import assert from "node:assert/strict";
import http from "node:http";

const sites = [
  { name: "SAAS", host: "kpsadmin.tzgpt.top", port: 5999 },
  { name: "用户端", host: "kpsuser.tzgpt.top", port: 5998 },
  { name: "原代理端", host: "kpsag.tzgpt.top", port: 5997 },
  { name: "股东端", host: "shareholder.tzgpt.top", port: 5996 },
  { name: "总监端", host: "director.tzgpt.top", port: 5995 },
  { name: "总代理端", host: "general-agent.tzgpt.top", port: 5994 },
  { name: "代理兼容端", host: "agent.tzgpt.top", port: 5993 },
];

function get(site, path) {
  return new Promise((resolve, reject) => {
    const request = http.get({ hostname: "127.0.0.1", port: site.port, path, headers: { Host: site.host } }, (response) => {
      const chunks = [];
      response.on("data", (chunk) => chunks.push(chunk));
      response.on("end", () => resolve({ status: response.statusCode, body: Buffer.concat(chunks).toString("utf8") }));
    });
    request.on("error", reject);
  });
}

for (const site of sites) {
  const entry = await get(site, "/");
  assert.equal(entry.status, 200, `${site.name} entry returned ${entry.status}`);
  const assets = [...entry.body.matchAll(/(?:src|href)=["'](\/assets\/[^"']+\.(?:js|css))["']/g)].map((match) => match[1]);
  assert.ok(assets.some((asset) => asset.endsWith(".js")), `${site.name} entry has no JavaScript asset`);
  assert.ok(assets.some((asset) => asset.endsWith(".css")), `${site.name} entry has no CSS asset`);
  for (const asset of assets) {
    const response = await get(site, asset);
    assert.equal(response.status, 200, `${site.name} ${asset} returned ${response.status}`);
  }
  console.log(`${site.name} ${site.host}:${site.port} entry=200 assets=${assets.length} all=200`);
}
