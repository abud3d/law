#!/usr/bin/env node
// Validate that each imported legislation name is covered by a reviewed classification rule.
const fs = require("node:fs");
const assert = require("node:assert/strict");
const html = fs.readFileSync("library/index.html", "utf8");
const data = JSON.parse(fs.readFileSync("library/data/saudi-legal-moj.json", "utf8"));
const start = html.indexOf("const categoryOverrides=[");
const end = html.indexOf("const classifyLaw=", start);
assert(start >= 0 && end > start, "Classification rules missing");
const block = html.slice(start, end);
const rules = [...block.matchAll(/\[\/(.+?)\/,"([^"]+)","([^"]+)"\]/g)]
  .map((match) => ({ pattern: new RegExp(match[1]), category: match[2], sub: match[3] }));
assert(rules.length >= 12, "Classification rule set unexpectedly reduced");
const names = [...new Set(data.map((r) => String(r.law_name || "").trim().replace(/[.،؛\s]+$/g, "")))];
const counts = {};
const missing = [];
for (const name of names) {
  const rule = rules.find((r) => r.pattern.test(name));
  if (!rule) { missing.push(name); continue; }
  counts[rule.category] = (counts[rule.category] || 0) + 1;
}
console.log("Imported records:", data.length);
console.log("Unique legislation names:", names.length);
console.log("Reviewed classification counts:", counts);
console.log("Unmatched names:", missing);
assert.equal(data.length, 3185);
assert.equal(names.length, 69);
assert.deepEqual(missing, [], "Some imported legislation titles lack an explicit category");
