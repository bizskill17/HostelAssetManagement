/*
 * Hostel Asset Management — front end.
 * Talks to api/index.php. Every stock figure on screen is worked out from the stock entries
 * (ha_txns) the API returns, so the inventory, the ledger and the counts always agree.
 */
"use strict";
(() => {
const API = "api/index.php";
const POLL_MS = 30000;
const PAGE = 50;
const $ = (s, r = document) => r.querySelector(s);
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const nf = (n) => Number(n || 0).toLocaleString("en-IN");
const cmp = (a, b) => String(a ?? "").localeCompare(String(b ?? ""), undefined, { numeric: true, sensitivity: "base" });

/* ---------- icons ---------- */
const P = {
  home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
  box: '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
  book: '<path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v17H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M8 7h8M8 11h6"/>',
  in: '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
  send: '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>',
  check: '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/>',
  wrench: '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
  alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
  shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
  building: '<path d="M3 21h18"/><path d="M5 21V5l7-3 7 3v16"/><path d="M9 9h1M14 9h1M9 13h1M14 13h1M10 21v-4h4v4"/>',
  tag: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><path d="M7.5 7.5h.01"/>',
  folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  trash: '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  list: '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
  chev: '<path d="m6 9 6 6 6-6"/>',
  filter: '<path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/>',
  move: '<path d="M17 3l4 4-4 4"/><path d="M3 7h18"/><path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>',
  undo: '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  eyeoff: '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
  bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
};
const ic = (n, cls = "i") => `<svg class="${cls}" viewBox="0 0 24 24">${P[n] || ""}</svg>`;

/* ---------- state ---------- */
const S = { me: null, d: null, view: "home", f: {}, sort: {}, page: {}, ledger: { h: "", i: "" } };
const M = {}; // derived lookups and totals
const CSV = {}; // per view: { name, cols, rows }

/* ---------- API ---------- */
async function api(a, body) {
  let res, j;
  try {
    res = await fetch(API + "?a=" + encodeURIComponent(a), {
      method: body ? "POST" : "GET", credentials: "same-origin",
      headers: body ? { "Content-Type": "application/json", "X-Req": "1" } : { "X-Req": "1" },
      body: body ? JSON.stringify(body) : undefined,
    });
    j = await res.json();
  } catch (_) {
    throw { message: "The server can't be reached. Check your connection and try again." };
  }
  if (!res.ok || j.error) {
    const e = j.error || { message: "Server error." };
    if (res.status === 401 && a !== "login") { S.me = null; showAuth("login"); }
    throw e;
  }
  return j;
}

/* ---------- dates ---------- */
const fmtD = (s) => { if (!s) return "—"; const [y, m, d] = String(s).slice(0, 10).split("-"); return `${d}/${m}/${y}`; };
const fmtTs = (ms) => { if (!ms) return "—"; const d = new Date(ms); return fmtD(isoOf(d)); };
const isoOf = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const today = () => (S.d && S.d.today) || isoOf(new Date());
const daysBetween = (a, b) => Math.round((Date.parse(b) - Date.parse(a)) / 86400000);
const addDays = (iso, n) => { const d = new Date(iso + "T00:00:00"); d.setDate(d.getDate() + n); return isoOf(d); };

/* ---------- derived data ---------- */
const KIND = {
  opening: { l: "Opening stock", c: "blue" }, in: { l: "Received", c: "green" }, transfer_in: { l: "Transfer in", c: "cyan" },
  transfer_out: { l: "Transfer out", c: "violet" }, repair_out: { l: "Sent for repair", c: "amber" },
  repair_return: { l: "Back from repair", c: "green" }, damaged: { l: "Damaged", c: "red" },
};
const ISSUE_KIND = { transfer: { l: "Transfer", c: "violet" }, repair: { l: "Repair", c: "amber" }, damage: { l: "Damage", c: "red" } };
const STATUS = {
  pending: { l: "Awaiting receipt", c: "amber" }, received: { l: "Received", c: "green" }, short: { l: "Received short", c: "amber" }, rejected: { l: "Not received", c: "red" }, approved: { l: "Received", c: "green" },
  cancelled: { l: "Cancelled", c: "" }, at_repair: { l: "At repair", c: "amber" }, partly_returned: { l: "Part returned", c: "cyan" },
  closed: { l: "Closed", c: "green" }, done: { l: "Approved", c: "green" }, void: { l: "Void", c: "" },
};
// Damage uses its own words for the shared statuses.
const DMG_STATUS = { pending: { l: "Awaiting approval", c: "amber" }, done: { l: "Approved", c: "green" }, rejected: { l: "Rejected", c: "red" }, cancelled: { l: "Cancelled", c: "" } };
const issueStatus = (i) => (i.kind === "damage" ? DMG_STATUS[i.status] : STATUS[i.status]) || { l: i.status, c: "" };
const badge = (o, fallback) => `<span class="b ${o ? o.c : ""}">${esc(o ? o.l : fallback)}</span>`;

function derive() {
  const d = S.d;
  M.admin = d.me.role === "Admin";
  M.myH = d.me.hostelId;
  M.H = new Map(d.hostels.map((x) => [x.id, x]));
  M.I = new Map(d.items.map((x) => [x.id, x]));
  M.C = new Map(d.categories.map((x) => [x.id, x]));
  M.U = new Map(d.users.map((x) => [x.id, x]));
  M.scopeH = M.admin ? d.hostels.map((h) => h.id) : [M.myH];
  const inv = new Map();
  const get = (h, i) => {
    const k = h + "|" + i;
    let r = inv.get(k);
    if (!r) inv.set(k, (r = { hostelId: h, itemId: i, opening: 0, in: 0, issued: 0, damaged: 0, current: 0, atRepair: 0, pendingOut: 0, pendingIn: 0, pendingDmg: 0 }));
    return r;
  };
  for (const t of d.txns) {
    const r = get(t.hostelId, t.itemId);
    if (t.kind === "opening") r.opening += t.qty;
    else if (t.kind === "in" || t.kind === "transfer_in" || t.kind === "repair_return") r.in += t.qty;
    else if (t.kind === "transfer_out" || t.kind === "repair_out") r.issued += t.qty;
    else if (t.kind === "damaged") r.damaged += t.qty;
    r.current += t.dir * t.qty;
  }
  for (const i of d.issues) {
    if (i.kind === "transfer" && i.status === "pending") {
      if (M.admin || i.hostelId === M.myH) get(i.hostelId, i.itemId).pendingOut += i.qty;
      if (M.admin || i.toHostelId === M.myH) get(i.toHostelId, i.itemId).pendingIn += i.qty;
    }
    if (i.kind === "damage" && i.status === "pending" && (M.admin || i.hostelId === M.myH)) get(i.hostelId, i.itemId).pendingDmg += i.qty;
    if (i.kind === "repair" && (i.status === "at_repair" || i.status === "partly_returned")) get(i.hostelId, i.itemId).atRepair += i.qty - i.returnedQty - i.scrappedQty;
  }
  M.inv = inv;
  M.incoming = d.issues.filter((i) => i.kind === "transfer" && i.status === "pending" && (M.admin || i.toHostelId === M.myH));
  M.outgoing = d.issues.filter((i) => i.kind === "transfer" && i.status === "pending" && (M.admin || i.hostelId === M.myH));
  M.dmgPending = d.issues.filter((i) => i.kind === "damage" && i.status === "pending");
  M.openRepairs = d.issues.filter((i) => i.kind === "repair" && (i.status === "at_repair" || i.status === "partly_returned"));
  M.warr = d.warranties.map((w) => ({ ...w, daysLeft: daysBetween(today(), w.expiryAt) }));
  M.exp30 = M.warr.filter((w) => w.daysLeft >= 0 && w.daysLeft <= 30).length;
}
const hName = (id) => (M.H.get(id) || {}).name || "—";
const iName = (id) => (M.I.get(id) || {}).name || "—";
const iUnit = (id) => (M.I.get(id) || {}).unit || "";
const iCat = (id) => (M.C.get((M.I.get(id) || {}).categoryId) || {}).name || "—";
const uName = (id) => (id ? (M.U.get(id) || {}).name || "—" : "System");
const available = (h, i) => { const r = M.inv.get(h + "|" + i); return r ? r.current - r.pendingOut - r.pendingDmg : 0; };
const outstanding = (i) => i.qty - i.returnedQty - i.scrappedQty;
const isOverdue = (i) => i.expectedAt && i.expectedAt < today() && outstanding(i) > 0;

/* ---------- filters ---------- */
function fv(key) { return (S.f[key] = S.f[key] || {}); }
/* Filter bar: the search box, then the other filters. On phones and tablets the other filters sit
   behind a filter icon beside the search box; applied filters show as chips while it is closed. */
S.fopen = {};
function filterBar(key, defs) {
  const f = fv(key);
  const ctl = (d) => {
    const v = f[d.k] ?? "";
    if (d.type === "search") return `<div class="search">${ic("search")}<input class="inp" data-fkey="${key}" data-f="${d.k}" value="${esc(v)}" placeholder="${esc(d.ph || "Search")}" autocomplete="off"></div>`;
    if (d.type === "date") return `<label class="flab">${esc(d.l)}<input type="date" data-fkey="${key}" data-f="${d.k}" value="${esc(v)}"></label>`;
    return `<select data-fkey="${key}" data-f="${d.k}" aria-label="${esc(d.l || "")}">${d.all !== false ? `<option value="">${esc(d.all || "All")}</option>` : ""}${d.opts.map(([ov, ol]) => `<option value="${esc(ov)}"${String(ov) === String(v) ? " selected" : ""}>${esc(ol)}</option>`).join("")}</select>`;
  };
  const shown = defs.filter((d) => !d.hide);
  const search = shown.find((d) => d.type === "search");
  const rest = shown.filter((d) => d !== search);
  const active = rest.filter((d) => f[d.k] !== "" && f[d.k] != null);
  const any = Object.values(f).some((x) => x !== "" && x != null);
  const open = !!S.fopen[key];
  const label = (d) => {
    const v = f[d.k];
    if (d.type === "date") return `${d.l} ${fmtD(v)}`;
    const o = (d.opts || []).find(([ov]) => String(ov) === String(v));
    return o ? o[1] : String(v);
  };
  const toggle = rest.length ? `<button type="button" class="fbtn${open || active.length ? " on" : ""}" data-act="ftoggle" data-key="${key}" aria-expanded="${open}" aria-label="Filters" title="Filters">${ic("filter")}${active.length ? `<span class="fcount">${active.length}</span>` : ""}</button>` : "";
  const chips = active.length && !open ? `<div class="fchips">${active.map((d) => `<span class="fchip">${esc(label(d))}<button type="button" data-act="fchip" data-key="${key}" data-k="${d.k}" aria-label="Remove filter">${ic("x")}</button></span>`).join("")}</div>` : "";
  return `<div class="filters${open ? " fopen" : ""}">${search ? `<div class="srow">${ctl(search)}${toggle}</div>` : toggle}<div class="fpanel">${rest.map(ctl).join("")}${any ? `<button class="clear" data-act="clearf" data-key="${key}">Clear all</button>` : ""}</div>${chips}</div>`;
}
const hostelOpts = () => S.d.hostels.filter((h) => M.scopeH.includes(h.id)).map((h) => [h.id, h.name]);
const catOpts = () => S.d.categories.map((c) => [c.id, c.name]);
const itemOpts = () => S.d.items.map((i) => [i.id, i.name]);
const match = (q, ...vals) => !q || vals.some((v) => String(v ?? "").toLowerCase().includes(q.toLowerCase()));
const inRange = (iso, f) => (!f.from || iso >= f.from) && (!f.to || iso <= f.to);

/* ---------- tables ---------- */
function table(key, cols, rows, o = {}) {
  CSV[key] = { name: o.csv || key, cols, rows };
  const st = S.sort[key];
  if (st && cols[st.i]) {
    const c = cols[st.i];
    rows = rows.slice().sort((a, b) => { const x = c.t(a), y = c.t(b); const r = c.num ? (Number(x) || 0) - (Number(y) || 0) : cmp(x, y); return st.dir * r; });
  }
  if (!rows.length) return `<div class="card">${o.title || ""}<div class="empty">${ic(o.emptyIcon || "inbox")}<div>${esc(o.empty || "Nothing to show yet.")}</div></div></div>`;
  const pages = Math.max(1, Math.ceil(rows.length / PAGE));
  const pg = Math.min(S.page[key] || 0, pages - 1);
  const shown = o.all ? rows : rows.slice(pg * PAGE, pg * PAGE + PAGE);
  const head = cols.map((c, i) => {
    if (c.nosort || !c.h) return `<th class="${c.num ? "num" : ""} ns">${esc(c.h)}</th>`;
    const on = st && st.i === i;
    return `<th class="${c.num ? "num" : ""}${on ? " sorted" : ""}" data-sort="${key}" data-i="${i}" title="Sort by ${esc(c.h)}">${esc(c.h)}<span class="ar">${on ? (st.dir > 0 ? "▲" : "▼") : "⇅"}</span></th>`;
  }).join("");
  const sortable = cols.map((c, i) => [c, i]).filter(([c]) => c.h && !c.nosort);
  const sortBar = sortable.length ? `<div class="sortbar"><span>Sort by</span><select data-sortsel="${key}"><option value="">Default order</option>${sortable.map(([c, i]) => `<option value="${i}"${st && st.i === i ? " selected" : ""}>${esc(c.h)}</option>`).join("")}</select>${st ? `<button class="btn sm" data-act="sortdir" data-key="${key}">${st.dir > 0 ? "▲ Low to high" : "▼ High to low"}</button>` : ""}</div>` : "";
  const body = shown.map((r) => `<tr>${cols.map((c) => `<td data-l="${esc(c.h)}" class="${c.num ? "num " : ""}${c.cls || ""}${c.h ? "" : " acts-td"}"><span>${c.html ? c.html(r) : esc(c.t(r))}</span></td>`).join("")}</tr>`).join("");
  const foot = o.foot ? `<tfoot><tr>${cols.map((c, i) => `<td data-l="${esc(c.h)}" class="${c.num ? "num" : ""}${c.foot || i === 0 ? "" : " nofoot"}">${c.foot ? esc(c.foot(rows)) : i === 0 ? "Total" : ""}</td>`).join("")}</tr></tfoot>` : "";
  const pager = !o.all && pages > 1 ? `<div class="pager"><span>Showing ${pg * PAGE + 1}–${pg * PAGE + shown.length} of ${nf(rows.length)}</span><span class="acts"><button class="btn sm" data-act="page" data-key="${key}" data-p="${pg - 1}" ${pg === 0 ? "disabled" : ""}>Previous</button><button class="btn sm" data-act="page" data-key="${key}" data-p="${pg + 1}" ${pg >= pages - 1 ? "disabled" : ""}>Next</button></span></div>` : "";
  return `<div class="card">${o.title || ""}${sortBar}<div class="tw"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody>${foot}</table></div>${pager}</div>`;
}
function downloadCSV(key) {
  const c = CSV[key];
  if (!c) return;
  const cols = c.cols.filter((x) => !x.nocsv);
  const q = (v) => { const s = String(v ?? ""); return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
  const lines = [cols.map((x) => q(x.h)).join(",")].concat(c.rows.map((r) => cols.map((x) => q(x.csv ? x.csv(r) : x.t(r))).join(",")));
  const blob = new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = `${c.name}-${today()}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 3000);
  toast(`Downloaded ${nf(c.rows.length)} rows`);
}

/* ---------- page pieces ---------- */
// Page title with its buttons. Pages carry no tagline under the title.
const head = (title, _sub, acts = "") => `<div class="ph"><div><h1>${esc(title)}</h1></div><div class="acts">${acts}</div></div>`;
const csvBtn = (key) => `<button class="btn dl" data-act="csv" data-key="${key}" title="Download as CSV">${ic("download")}<span class="l">Download</span></button>`;
// Count cards appear only on the Dashboard.
const stats = (list, onDashboard) => !onDashboard ? "" : `<div class="stats dash">${list.map((s) => `<div class="stat t-${s.tone || "brand"}${s.go ? " click" : ""}" ${s.go ? `data-go="${s.go}"` : ""}><div class="l">${esc(s.l)}</div><div class="v">${esc(s.v)}</div></div>`).join("")}</div>`;
const scopeText = () => (M.admin ? `All ${S.d.hostels.length} hostels` : hName(M.myH));
const canWriteHostel = (h) => M.admin || h === M.myH;

/* =================================================================== views */
const V = {};

/* ---------- dashboard ---------- */
V.home = () => {
  const rows = [...M.inv.values()].filter((r) => M.scopeH.includes(r.hostelId));
  const total = rows.reduce((s, r) => s + r.current, 0);
  const atRep = M.openRepairs.reduce((s, i) => s + outstanding(i), 0);
  const overdue = M.openRepairs.filter(isOverdue).length;
  const monthStart = today().slice(0, 8) + "01";
  const dmgMonth = S.d.txns.filter((t) => t.kind === "damaged" && t.at >= monthStart).reduce((s, t) => s + t.qty, 0);
  const incoming = M.incoming.slice(0, 6);
  const recent = S.d.txns.filter((t) => t.kind !== "opening").slice().sort((a, b) => b.createdAt - a.createdAt || b.id - a.id).slice(0, 8);
  const repairs = M.openRepairs.slice().sort((a, b) => cmp(a.expectedAt || "9999", b.expectedAt || "9999")).slice(0, 5);
  const warr = M.warr.filter((w) => w.daysLeft <= 90).sort((a, b) => a.daysLeft - b.daysLeft).slice(0, 5);
  const byH = M.admin ? S.d.hostels.map((h) => ({ h, n: rows.filter((r) => r.hostelId === h.id).reduce((s, r) => s + r.current, 0) })) : [];
  const maxH = Math.max(1, ...byH.map((x) => x.n));

  const apprList = incoming.length ? `<ul class="list">${incoming.map((i) => `<li><div class="ic violet">${ic("move")}</div><div class="tx"><b>${esc(iName(i.itemId))} × ${nf(i.qty)} ${esc(iUnit(i.itemId))}</b><span>${esc(hName(i.hostelId))} → ${esc(hName(i.toHostelId))} · ${fmtD(i.at)} · <span class="mono">${esc(i.no)}</span></span></div><div class="rt acts">${canWriteHostel(i.toHostelId) ? `<button class="btn sm pri" data-act="receive" data-id="${i.id}">${ic("edit")}Update</button>` : ""}</div></li>`).join("")}</ul>`
    : `<div class="empty">${ic("check")}<div>No incoming transfers waiting to be received.</div></div>`;
  const recentList = recent.length ? `<ul class="list">${recent.map((t) => { const k = KIND[t.kind]; const icn = { in: "in", transfer_in: "move", transfer_out: "move", repair_out: "wrench", repair_return: "wrench", damaged: "alert" }[t.kind] || "box"; return `<li><div class="ic ${k.c === "blue" ? "blue" : k.c}">${ic(icn)}</div><div class="tx"><b>${esc(iName(t.itemId))}</b><span>${esc(k.l)} · ${esc(hName(t.hostelId))}${t.otherHostelId ? (t.dir > 0 ? " from " : " to ") + esc(hName(t.otherHostelId)) : ""} · ${fmtD(t.at)}</span></div><div class="rt ${t.dir > 0 ? "qin" : "qout"}">${t.dir > 0 ? "+" : "−"}${nf(t.qty)}</div></li>`; }).join("")}</ul>`
    : `<div class="empty">${ic("inbox")}<div>No movements yet.</div></div>`;
  const repList = repairs.length ? `<ul class="list">${repairs.map((i) => `<li><div class="ic amber">${ic("wrench")}</div><div class="tx"><b>${esc(iName(i.itemId))} × ${nf(outstanding(i))}</b><span>${esc(hName(i.hostelId))}${i.place ? " · " + esc(i.place) : ""} · sent ${fmtD(i.at)}</span></div><div class="rt">${isOverdue(i) ? `<span class="b red">Overdue</span>` : i.expectedAt ? `<span class="b">Due ${fmtD(i.expectedAt)}</span>` : ""}</div></li>`).join("")}</ul>`
    : `<div class="empty">${ic("wrench")}<div>Nothing is at repair.</div></div>`;
  const warList = warr.length ? `<ul class="list">${warr.map((w) => `<li><div class="ic ${w.daysLeft < 0 ? "red" : "amber"}">${ic("shield")}</div><div class="tx"><b>${esc(w.title || iName(w.itemId))}</b><span>${esc(w.hostelId ? hName(w.hostelId) : "All hostels")} · ${esc(w.warrantyNo || "No number")}</span></div><div class="rt">${daysBadge(w.daysLeft)}</div></li>`).join("")}</ul>`
    : `<div class="empty">${ic("shield")}<div>No warranties expire in the next 90 days.</div></div>`;
  const hostelBars = M.admin ? `<div class="card"><div class="ch"><h3>Stock by hostel</h3><span class="sub">Current quantity</span></div><ul class="list">${byH.map((x) => `<li><div class="tx"><b class="lnk" data-act="invHostel" data-id="${x.h.id}">${esc(x.h.name)}</b><div style="height:8px;background:var(--line2);border-radius:99px;margin-top:7px;overflow:hidden"><div style="height:100%;width:${(x.n / maxH) * 100}%;background:linear-gradient(90deg,#5b7cfa,#2f54eb);border-radius:99px"></div></div></div><div class="rt strong" style="min-width:60px;font-weight:700">${nf(x.n)}</div></li>`).join("")}</ul></div>` : "";

  return head("Dashboard", `${esc(scopeText())} · ${fmtD(today())}`)
    + stats([
      { v: nf(M.incoming.length), l: "Transfer Approval", tone: M.incoming.length ? "amber" : "slate", go: "transfer" },
      { v: nf(M.dmgPending.length), l: "Damage Approval", tone: M.dmgPending.length ? "red" : "slate", go: "damage" },
      { v: nf(atRep), l: overdue ? `At Repair · ${overdue} overdue` : "At Repair", tone: overdue ? "red" : "amber", go: "repairs" },
      { v: nf(dmgMonth), l: "Damage This Month", tone: "red", go: "damaged" },
      { v: nf(M.exp30), l: "Warranties expiring in 30 days", tone: M.exp30 ? "red" : "green", go: "warranties" },
    ], true)
    + `<div class="grid2"><div><div class="card"><div class="ch"><h3>Recent movements</h3><a href="#issues" class="sub">Issue register</a></div>${recentList}</div></div>`
    + `<div>${hostelBars}<div class="card"><div class="ch"><h3>At repair</h3><a href="#repairs" class="sub">Track repairs</a></div>${repList}</div><div class="card"><div class="ch"><h3>Warranties expiring soon</h3><a href="#warranties" class="sub">All warranties</a></div>${warList}</div></div></div>`;
};
function daysBadge(n) {
  if (n < 0) return `<span class="b red">Expired ${nf(-n)}d ago</span>`;
  if (n <= 30) return `<span class="b red">${n === 0 ? "Expires today" : nf(n) + " days left"}</span>`;
  if (n <= 90) return `<span class="b amber">${nf(n)} days left</span>`;
  return `<span class="b green">${nf(n)} days left</span>`;
}

/* ---------- inventory ---------- */
V.inventory = () => {
  const f = fv("inventory");
  const combine = f.view === "all";
  let rows = [...M.inv.values()].filter((r) => M.scopeH.includes(r.hostelId));
  if (f.h) rows = rows.filter((r) => r.hostelId === +f.h);
  if (combine) {
    const m = new Map();
    for (const r of rows) {
      let x = m.get(r.itemId);
      if (!x) m.set(r.itemId, (x = { hostelId: 0, itemId: r.itemId, opening: 0, in: 0, issued: 0, damaged: 0, current: 0, atRepair: 0, pendingOut: 0, pendingIn: 0, pendingDmg: 0 }));
      for (const k of ["opening", "in", "issued", "damaged", "current", "atRepair", "pendingOut", "pendingIn", "pendingDmg"]) x[k] += r[k];
    }
    rows = [...m.values()];
  }
  rows = rows.filter((r) => match(f.q, iName(r.itemId), iCat(r.itemId), r.hostelId ? hName(r.hostelId) : ""))
    .filter((r) => !f.c || (M.I.get(r.itemId) || {}).categoryId === +f.c)
    .filter((r) => !f.stock || (f.stock === "in" ? r.current > 0 : f.stock === "zero" ? r.current <= 0 : r.atRepair > 0 || r.pendingOut > 0 || r.pendingIn > 0))
    .sort((a, b) => cmp(a.hostelId ? hName(a.hostelId) : "", b.hostelId ? hName(b.hostelId) : "") || cmp(iName(a.itemId), iName(b.itemId)));
  const sum = (k) => rows.reduce((s, r) => s + r[k], 0);
  const cols = [
    { h: "Hostel", t: (r) => (r.hostelId ? hName(r.hostelId) : "All hostels"), cls: "nw" },
    { h: "Item", t: (r) => iName(r.itemId), html: (r) => `<span class="lnk" data-act="ledger" data-h="${r.hostelId || ""}" data-i="${r.itemId}">${esc(iName(r.itemId))}</span>` },
    { h: "Unit", t: (r) => iUnit(r.itemId), cls: "dim" },
    { h: "Category", t: (r) => iCat(r.itemId), cls: "dim nw" },
    { h: "Opening", t: (r) => r.opening, num: 1, foot: () => nf(sum("opening")) },
    { h: "In", t: (r) => r.in, num: 1, html: (r) => r.in ? `<span class="qin">${nf(r.in)}</span>` : "0", foot: () => nf(sum("in")) },
    { h: "Issued", t: (r) => r.issued, num: 1, html: (r) => r.issued ? `<span class="qout">${nf(r.issued)}</span>` : "0", foot: () => nf(sum("issued")) },
    { h: "Damaged", t: (r) => r.damaged, num: 1, html: (r) => r.damaged ? `<span class="qout">${nf(r.damaged)}</span>` : "0", foot: () => nf(sum("damaged")) },
    { h: "Current inventory", t: (r) => r.current, num: 1, cls: "strong", html: (r) => `<b>${nf(r.current)}</b>`, foot: () => nf(sum("current")) },
    { h: "At repair", t: (r) => r.atRepair, num: 1, cls: "dim", foot: () => nf(sum("atRepair")) },
    { h: "Damage pending", t: (r) => r.pendingDmg || "", num: 1, cls: "dim" },
    { h: "Transfer pending", t: (r) => (r.pendingOut ? `−${r.pendingOut}` : "") + (r.pendingOut && r.pendingIn ? " / " : "") + (r.pendingIn ? `+${r.pendingIn}` : ""), cls: "dim nw", nosort: 1 },
  ];
  return head("Inventory", `${esc(combine ? "All hostels combined" : scopeText())} · click an item to see its history`, `${csvBtn("inventory")}<button class="btn pri" data-act="issue">${ic("send")}<span class="l">Issue asset</span></button>`)
    + stats([
      { v: nf(rows.length), l: combine ? "Items" : "Hostel × item lines" },
      { v: nf(sum("opening")), l: "Opening count", tone: "slate" },
      { v: nf(sum("in")), l: "In count", tone: "green" },
      { v: nf(sum("issued")), l: "Issued count", tone: "violet" },
      { v: nf(sum("damaged")), l: "Damaged count", tone: "red" },
      { v: nf(sum("current")), l: "Current inventory", tone: "cyan" },
    ])
    + filterBar("inventory", [
      { k: "q", type: "search", ph: "Search item, category or hostel" },
      { k: "h", l: "Hostel", opts: hostelOpts(), all: "All hostels", hide: !M.admin },
      { k: "c", l: "Category", opts: catOpts(), all: "All categories" },
      { k: "stock", l: "Stock", opts: [["in", "In stock"], ["zero", "Zero stock"], ["moving", "At repair / transfer pending"]], all: "Any stock" },
      { k: "view", l: "View", opts: [["all", "All hostels combined"]], all: "By hostel", hide: !M.admin },
    ])
    + table("inventory", cols, rows, { csv: "inventory", foot: 1, empty: "No stock yet. Add opening stock to get started." });
};

/* ---------- item ledger ---------- */
V.ledger = () => {
  const f = fv("ledger");
  const L = S.ledger;
  if (!M.admin) L.h = String(M.myH);
  if (!L.i && S.d.items.length) {
    const first = [...M.inv.values()].find((r) => M.scopeH.includes(r.hostelId));
    if (first) { L.i = String(first.itemId); if (M.admin && !L.h) L.h = ""; }
  }
  const hSel = M.admin ? `<select data-led="h"><option value="">All hostels</option>${hostelOpts().map(([v, l]) => `<option value="${v}"${String(v) === L.h ? " selected" : ""}>${esc(l)}</option>`).join("")}</select>` : "";
  const iSel = `<select data-led="i"><option value="">Choose an item</option>${itemOpts().map(([v, l]) => `<option value="${v}"${String(v) === L.i ? " selected" : ""}>${esc(l)}</option>`).join("")}</select>`;
  const bar = `<div class="filters">${hSel}${iSel}<label class="flab">From<input type="date" data-fkey="ledger" data-f="from" value="${esc(f.from || "")}"></label><label class="flab">To<input type="date" data-fkey="ledger" data-f="to" value="${esc(f.to || "")}"></label><div class="search">${ic("search")}<input class="inp" data-fkey="ledger" data-f="q" value="${esc(f.q || "")}" placeholder="Search entries"></div></div>`;
  if (!L.i) return head("Item ledger", "Every entry for one item, from opening stock to today") + bar + `<div class="card"><div class="empty">${ic("book")}<div>Choose an item to see its ledger.</div></div></div>`;
  const item = +L.i, hid = L.h ? +L.h : 0;
  const { rows, shown, open, inQ, outQ, balance, cols } = ledgerModel(item, hid, f);
  const it = M.I.get(item) || {};
  return head("Item ledger", "Every entry for one item, from opening stock to today", csvBtn("ledger"))
    + bar
    + stats([
      { v: nf(open), l: f.from ? "Opening (incl. brought forward)" : "Opening count", tone: "slate" },
      { v: nf(inQ), l: "Quantity in", tone: "green" },
      { v: nf(outQ), l: "Quantity out", tone: "red" },
      { v: nf(balance), l: f.to ? `Balance on ${fmtD(f.to)}` : "Current balance", tone: "cyan" },
    ])
    + table("ledger", cols, shown, { csv: `ledger-${(it.name || "item").replace(/\W+/g, "-")}${hid ? "-" + hName(hid).replace(/\W+/g, "-") : ""}`, all: 1,
      title: `<div class="lhead"><div class="ic blue" style="width:40px;height:40px;border-radius:11px;display:grid;place-items:center">${ic("box")}</div><div><h2>${esc(it.name || "")}</h2><div class="meta">${esc(iCat(item))} · Unit: ${esc(it.unit || "")} · ${esc(hid ? hName(hid) : M.admin ? "All hostels" : hName(M.myH))}</div></div></div>`,
      empty: "No entries for this item yet." });
};

/* ---------- ledger model: shared by the Item ledger page and the history pop-up ---------- */
function ledgerModel(item, hid, f) {
  const order = { opening: 0, in: 1, transfer_in: 2, repair_return: 3, transfer_out: 4, repair_out: 5, damaged: 6 };
  const all = S.d.txns.filter((t) => t.itemId === item && (hid ? t.hostelId === hid : M.scopeH.includes(t.hostelId)))
    .sort((a, b) => cmp(a.at, b.at) || order[a.kind] - order[b.kind] || a.id - b.id);
  let bal = 0, bf = 0;
  const rows = [];
  for (const t of all) {
    bal += t.dir * t.qty;
    if (f.from && t.at < f.from) { bf = bal; continue; }
    if (f.to && t.at > f.to) continue;
    rows.push({ ...t, bal });
  }
  if (f.from && (bf || all.some((t) => t.at < f.from))) rows.unshift({ id: 0, at: f.from, kind: "bf", dir: 1, qty: 0, bal: bf, hostelId: hid, notes: "" });
  const issueNo = (id) => (S.d.issues.find((i) => i.id === id) || {}).no || "";
  const party = (t) => t.kind === "transfer_in" ? "From " + hName(t.otherHostelId) : t.kind === "transfer_out" ? "To " + hName(t.otherHostelId) : t.party || "";
  const shown = rows.filter((t) => t.kind === "bf" || match(f.q, KIND[t.kind].l, party(t), t.notes, issueNo(t.issueId), hName(t.hostelId)));
  const inQ = rows.filter((t) => t.kind !== "bf" && t.dir > 0 && t.kind !== "opening").reduce((s, t) => s + t.qty, 0);
  const outQ = rows.filter((t) => t.kind !== "bf" && t.dir < 0).reduce((s, t) => s + t.qty, 0);
  const open = rows.filter((t) => t.kind === "opening").reduce((s, t) => s + t.qty, 0) + (rows[0] && rows[0].kind === "bf" ? bf : 0);
  const cols = [
    { h: "Date", t: (t) => t.at, html: (t) => fmtD(t.at), cls: "nw" },
    { h: "Hostel", t: (t) => (t.kind === "bf" ? "" : hName(t.hostelId)), cls: "nw" },
    { h: "Entry", t: (t) => (t.kind === "bf" ? "Balance brought forward" : KIND[t.kind].l), html: (t) => (t.kind === "bf" ? "<b>Balance brought forward</b>" : badge(KIND[t.kind])) },
    { h: "Reference", t: (t) => issueNo(t.issueId), html: (t) => `<span class="mono">${esc(issueNo(t.issueId))}</span>` },
    { h: "From / to", t: party, cls: "nw" },
    { h: "Qty in", t: (t) => (t.kind !== "bf" && t.dir > 0 ? t.qty : ""), num: 1, html: (t) => (t.kind !== "bf" && t.dir > 0 ? `<span class="qin">${nf(t.qty)}</span>` : "") },
    { h: "Qty out", t: (t) => (t.dir < 0 ? t.qty : ""), num: 1, html: (t) => (t.dir < 0 ? `<span class="qout">${nf(t.qty)}</span>` : "") },
    { h: "Balance", t: (t) => t.bal, num: 1, cls: "strong", html: (t) => `<b>${nf(t.bal)}</b>` },
    { h: "By", t: (t) => (t.kind === "bf" ? "" : uName(t.createdBy)), cls: "dim nw" },
    { h: "Notes", t: (t) => t.notes || "", cls: "wrap" },
  ];
  const balance = rows.length ? rows[rows.length - 1].bal : bf;
  return { rows, shown, bf, open, inQ, outQ, balance, cols };
}

/* ---------- item history pop-up ---------- */
function historyPopup(item, hid) {
  S.hist = { item, hid, f: {} };
  closeDlg();
  const w = document.createElement("div");
  w.className = "dlg hist";
  w.innerHTML = `<div class="box wide"></div>`;
  document.body.appendChild(w);
  w.addEventListener("mousedown", (e) => { if (e.target === w) closeDlg(); });
  drawHist();
}
function drawHist() {
  const box = document.querySelector(".dlg.hist .box");
  if (!box || !S.hist) return;
  const { item, hid, f } = S.hist;
  const it = M.I.get(item) || {};
  const where = hid ? hName(hid) : M.admin ? "All hostels" : hName(M.myH);
  const m = ledgerModel(item, hid, f);
  const hostelSel = M.admin ? `<select data-hist="hid"><option value="">All hostels</option>${hostelOpts().map(([v, l]) => `<option value="${v}"${v === hid ? " selected" : ""}>${esc(l)}</option>`).join("")}</select>` : "";
  const keep = document.activeElement && document.activeElement.dataset ? document.activeElement.dataset.hist : null;
  box.innerHTML = `<div class="dh"><div style="display:flex;gap:12px;align-items:center"><div class="ic blue" style="width:42px;height:42px;border-radius:12px;display:grid;place-items:center;flex:none">${ic("book")}</div><div><h2>${esc(it.name || "")}</h2><p>${esc(iCat(item))} · Unit: ${esc(it.unit || "")} · ${esc(where)}</p></div></div><button type="button" class="iconbtn" data-act="closehist" aria-label="Close">${ic("x")}</button></div>
    <div class="hbody">
      ${stats([
        { v: nf(m.open), l: f.from ? "Opening (incl. brought forward)" : "Opening", tone: "slate" },
        { v: nf(m.inQ), l: "Quantity in", tone: "green" },
        { v: nf(m.outQ), l: "Quantity out", tone: "red" },
        { v: nf(m.balance), l: f.to ? `Balance on ${fmtD(f.to)}` : "Current balance", tone: "cyan" },
      ])}
      <div class="filters">${hostelSel}<label class="flab">From<input type="date" data-hist="from" value="${esc(f.from || "")}"></label><label class="flab">To<input type="date" data-hist="to" value="${esc(f.to || "")}"></label><div class="search">${ic("search")}<input class="inp" data-hist="q" value="${esc(f.q || "")}" placeholder="Search entries"></div></div>
      ${table("hist", m.cols, m.shown, { csv: `history-${(it.name || "item").replace(/\W+/g, "-")}${hid ? "-" + hName(hid).replace(/\W+/g, "-") : ""}`, all: 1, empty: "No entries for this item yet." })}
    </div>
    <div class="df"><button class="btn dl" data-act="csv" data-key="hist" title="Download as CSV">${ic("download")}Download</button><button class="btn pri" data-act="closehist">Close</button></div>`;
  if (keep) { const el = box.querySelector(`[data-hist="${keep}"]`); if (el) { el.focus(); if (el.type !== "date") { const n = el.value.length; try { el.setSelectionRange(n, n); } catch (_) {} } } }
}

/* ---------- stock in ---------- */
V.stock = () => {
  const f = fv("stock");
  let rows = S.d.txns.filter((t) => (t.kind === "opening" || t.kind === "in") && M.scopeH.includes(t.hostelId))
    .filter((t) => (!f.h || t.hostelId === +f.h) && (!f.kind || t.kind === f.kind) && (!f.c || (M.I.get(t.itemId) || {}).categoryId === +f.c) && inRange(t.at, f))
    .filter((t) => match(f.q, iName(t.itemId), t.party, t.notes, hName(t.hostelId)))
    .sort((a, b) => cmp(b.at, a.at) || b.id - a.id);
  const sum = (k) => rows.filter((t) => !k || t.kind === k).reduce((s, t) => s + t.qty, 0);
  const cols = [
    { h: "Date", t: (t) => t.at, html: (t) => fmtD(t.at), cls: "nw" },
    { h: "Hostel", t: (t) => hName(t.hostelId), cls: "nw" },
    { h: "Item", t: (t) => iName(t.itemId), html: (t) => `<span class="lnk" data-act="ledger" data-h="${t.hostelId}" data-i="${t.itemId}">${esc(iName(t.itemId))}</span>` },
    { h: "Type", t: (t) => KIND[t.kind].l, html: (t) => badge(KIND[t.kind]) },
    { h: "Qty", t: (t) => t.qty, num: 1, html: (t) => `<span class="qin">${nf(t.qty)}</span>` },
    { h: "Unit", t: (t) => iUnit(t.itemId), cls: "dim" },
    { h: "Notes", t: (t) => t.notes || "", cls: "wrap" },
    { h: "Entered by", t: (t) => uName(t.createdBy), cls: "dim nw" },
  ];
  if (M.admin) cols.push({ h: "", t: () => "", nocsv: 1, nosort: 1, html: (t) => `<div class="rowacts"><button class="iconbtn del" title="Delete entry" data-act="deltxn" data-id="${t.id}">${ic("trash")}</button></div>` });
  return head("Stock in", "Opening stock and items received at each hostel", `${csvBtn("stock")}<button class="btn pri" data-act="stockin">${ic("plus")}<span class="l">Add stock</span></button>`)
    + stats([
      { v: nf(rows.length), l: "Entries" },
      { v: nf(sum("opening")), l: "Opening quantity", tone: "slate" },
      { v: nf(sum("in")), l: "Received quantity", tone: "green" },
      { v: nf(new Set(rows.map((t) => t.itemId)).size), l: "Items", tone: "cyan" },
    ])
    + filterBar("stock", [
      { k: "q", type: "search", ph: "Search item, supplier or notes" },
      { k: "h", l: "Hostel", opts: hostelOpts(), all: "All hostels", hide: !M.admin },
      { k: "kind", l: "Type", opts: [["opening", "Opening stock"], ["in", "Received"]], all: "All types" },
      { k: "c", l: "Category", opts: catOpts(), all: "All categories" },
      { k: "from", type: "date", l: "From" }, { k: "to", type: "date", l: "To" },
    ])
    + table("stock", cols, rows, { csv: "stock-in", empty: "No stock entered yet." });
};

/* ---------- issue register ---------- */
function issueCols(withActs = true) {
  const cols = [
    { h: "No.", t: (i) => i.no, html: (i) => `<span class="mono">${esc(i.no)}</span>`, cls: "nw" },
    { h: "Date", t: (i) => i.at, html: (i) => fmtD(i.at), cls: "nw" },
    { h: "Type", t: (i) => ISSUE_KIND[i.kind].l, html: (i) => badge(ISSUE_KIND[i.kind]) },
    { h: "From hostel", t: (i) => hName(i.hostelId), cls: "nw" },
    { h: "Item", t: (i) => iName(i.itemId), html: (i) => `<span class="lnk" data-act="ledger" data-h="${i.hostelId}" data-i="${i.itemId}">${esc(iName(i.itemId))}</span>` },
    { h: "Qty", t: (i) => i.qty, num: 1, html: (i) => `<b>${nf(i.qty)}</b> <span class="dim">${esc(iUnit(i.itemId))}</span>` },
    { h: "Received", t: (i) => (i.kind === "transfer" && i.receivedQty != null ? i.receivedQty : ""), num: 1, html: recvHtml },
    { h: "To / sent to", t: (i) => (i.kind === "transfer" ? hName(i.toHostelId) : i.place || ""), cls: "nw" },
    { h: "Status", t: (i) => issueStatus(i).l, html: (i) => badge(issueStatus(i)) },
    { h: "By", t: (i) => uName(i.createdBy), cls: "dim nw" },
    { h: "Notes", t: (i) => [i.notes, i.decisionNote && "Receiver: " + i.decisionNote].filter(Boolean).join(" · "), cls: "wrap" },
  ];
  if (withActs) cols.push({ h: "", t: () => "", nocsv: 1, nosort: 1, html: issueActs });
  return cols;
}
function recvHtml(i) {
  if (i.kind !== "transfer") return "";
  if (i.receivedQty == null) return i.status === "pending" ? `<span class="dim">—</span>` : "";
  return i.receivedQty < i.qty ? `<span class="qout">${nf(i.receivedQty)}</span>` : `<span class="qin">${nf(i.receivedQty)}</span>`;
}
const isReceived = (i) => i.kind === "transfer" && ["received", "short", "approved"].includes(i.status);
function issueActs(i) {
  const b = [];
  if (i.kind === "transfer" && i.status === "pending") {
    if (canWriteHostel(i.toHostelId)) b.push(`<button class="btn sm pri" data-act="receive" data-id="${i.id}">${ic("edit")}Update</button>`);
    if (canWriteHostel(i.hostelId)) b.push(`<button class="btn sm" data-act="cancel" data-id="${i.id}">Cancel</button>`);
  }
  if (i.kind === "damage" && i.status === "pending") {
    if (M.admin) b.push(`<button class="btn sm ok" data-act="dmgok" data-id="${i.id}">Approve</button><button class="btn sm bad" data-act="dmgno" data-id="${i.id}">Reject</button>`);
    else if (canWriteHostel(i.hostelId)) b.push(`<button class="btn sm" data-act="cancel" data-id="${i.id}">Cancel</button>`);
  }
  if (i.kind === "repair" && outstanding(i) > 0 && (i.status === "at_repair" || i.status === "partly_returned") && canWriteHostel(i.hostelId)) b.push(`<button class="btn sm ok" data-act="return" data-id="${i.id}">Record return</button>`);
  if (M.admin) b.push(isReceived(i)
    ? `<button class="iconbtn" disabled title="Received transfers can't be deleted">${ic("trash")}</button>`
    : `<button class="iconbtn del" title="Delete this issue" data-act="delissue" data-id="${i.id}">${ic("trash")}</button>`);
  return `<div class="rowacts">${b.join("")}</div>`;
}
V.issues = () => {
  const f = fv("issues");
  const rows = S.d.issues.filter((i) => (!f.kind || i.kind === f.kind) && (!f.st || i.status === f.st) && (!f.h || i.hostelId === +f.h || i.toHostelId === +f.h) && inRange(i.at, f))
    .filter((i) => match(f.q, i.no, iName(i.itemId), hName(i.hostelId), i.toHostelId ? hName(i.toHostelId) : "", i.place, i.notes));
  const n = (k) => rows.filter((i) => i.kind === k).length;
  return head("Issue register", "Every transfer, repair and damage entry", `${csvBtn("issues")}<button class="btn pri" data-act="issue">${ic("send")}<span class="l">Issue asset</span></button>`)
    + stats([
      { v: nf(rows.length), l: "Issues" },
      { v: nf(n("transfer")), l: "Transfers", tone: "violet" },
      { v: nf(n("repair")), l: "Repairs", tone: "amber" },
      { v: nf(n("damage")), l: "Damage entries", tone: "red" },
      { v: nf(rows.filter((i) => i.status === "pending").length), l: "Awaiting receipt", tone: "cyan" },
      { v: nf(rows.filter((i) => !["void", "rejected", "cancelled"].includes(i.status)).reduce((s, i) => s + i.qty, 0)), l: "Quantity issued", tone: "slate" },
    ])
    + filterBar("issues", [
      { k: "q", type: "search", ph: "Search no., item, hostel or notes" },
      { k: "kind", l: "Type", opts: [["transfer", "Transfer"], ["repair", "Repair"], ["damage", "Damage"]], all: "All types" },
      { k: "st", l: "Status", opts: Object.entries(STATUS).map(([k, v]) => [k, v.l]), all: "All statuses" },
      { k: "h", l: "Hostel", opts: S.d.hostels.map((h) => [h.id, h.name]), all: "All hostels", hide: !M.admin },
      { k: "from", type: "date", l: "From" }, { k: "to", type: "date", l: "To" },
    ])
    + table("issues", issueCols(), rows, { csv: "issue-register", empty: "No issues yet." });
};

/* ---------- approvals ---------- */
/* Approvals: three pages in the left menu, one per view. */
const APPROVAL_VIEWS = {
  receive: { show: "in", title: "Incoming transfers", empty: "No incoming transfers waiting to be received.", csv: "incoming-transfers" },
  sent: { show: "out", title: "Outgoing transfers", empty: "No outgoing transfers waiting to be received.", csv: "outgoing-transfers" },
  transfers: { show: "all", title: "All Transfers", empty: "No transfers yet.", csv: "transfers-all" },
  transfer: { show: "pending", title: "Transfer", empty: "No transfers waiting to be received.", csv: "transfers-pending" },
};
function approvalsView(key) {
  const A = APPROVAL_VIEWS[key];
  const f = fv(key);
  const all = S.d.issues.filter((i) => i.kind === "transfer");
  const pending = all.filter((i) => i.status === "pending");
  let rows = A.show === "in" ? M.incoming : A.show === "out" ? M.outgoing : A.show === "pending" ? pending : all;
  rows = rows.filter((i) => (!f.st || i.status === f.st || (f.st === "received" && i.status === "approved")) && (!f.h || i.hostelId === +f.h || i.toHostelId === +f.h) && inRange(i.at, f) && match(f.q, i.no, iName(i.itemId), hName(i.hostelId), hName(i.toHostelId), i.notes, i.decisionNote));
  const short = (i) => (i.receivedQty == null ? "" : i.qty - i.receivedQty);
  const cols = [
    { h: "No.", t: (i) => i.no, html: (i) => `<span class="mono">${esc(i.no)}</span>`, cls: "nw" },
    { h: "Sent on", t: (i) => i.at, html: (i) => fmtD(i.at), cls: "nw" },
    { h: "From hostel", t: (i) => hName(i.hostelId), cls: "nw" },
    { h: "To hostel", t: (i) => hName(i.toHostelId), cls: "nw" },
    { h: "Item", t: (i) => iName(i.itemId), html: (i) => `<span class="lnk" data-act="ledger" data-h="${i.toHostelId}" data-i="${i.itemId}">${esc(iName(i.itemId))}</span>` },
    { h: "Unit", t: (i) => iUnit(i.itemId), cls: "dim" },
    { h: "Send qty", t: (i) => i.qty, num: 1, html: (i) => `<b>${nf(i.qty)}</b>` },
    { h: "Received qty", t: (i) => i.receivedQty ?? "", num: 1, html: recvHtml },
    { h: "Short", t: short, num: 1, html: (i) => (short(i) ? `<span class="qout">${nf(short(i))}</span>` : i.receivedQty == null ? "" : "0") },
    { h: "Status", t: (i) => issueStatus(i).l, html: (i) => badge(issueStatus(i)) },
    { h: "Sent by", t: (i) => uName(i.createdBy), cls: "dim nw" },
    { h: "Updated", t: (i) => (i.decidedAt ? fmtTs(i.decidedAt) + " · " + uName(i.decidedBy) : ""), cls: "dim nw" },
    { h: "Notes", t: (i) => [i.notes, i.decisionNote && "Receiver: " + i.decisionNote].filter(Boolean).join(" · "), cls: "wrap" },
    { h: "", t: () => "", nocsv: 1, nosort: 1, html: issueActs },
  ];
  // Waiting/sent pages only hold transfers still awaiting receipt, so the status filter is only on All transfers.
  return head(A.title, "", csvBtn(key))
    + filterBar(key, [
      { k: "q", type: "search", ph: "Search no., item or hostel" },
      { k: "st", l: "Status", opts: [["pending", "Awaiting receipt"], ["received", "Received"], ["short", "Received short"], ["rejected", "Not received"], ["cancelled", "Cancelled"]], all: "All statuses", hide: A.show !== "all" },
      { k: "h", l: "Hostel", opts: S.d.hostels.map((h) => [h.id, h.name]), all: "All hostels", hide: !M.admin },
      { k: "from", type: "date", l: "From" }, { k: "to", type: "date", l: "To" },
    ])
    + table(key, cols, rows, { csv: A.csv, emptyIcon: "check", empty: A.empty });
}
Object.keys(APPROVAL_VIEWS).forEach((k) => (V[k] = () => approvalsView(k)));

/* ---------- damage approval ---------- */
V.damage = () => {
  const f = fv("damage");
  const rows = M.dmgPending
    .filter((i) => (!f.h || i.hostelId === +f.h) && inRange(i.at, f))
    .filter((i) => match(f.q, i.no, iName(i.itemId), hName(i.hostelId), i.place, i.notes, i.decisionNote));
  const cols = [
    { h: "No.", t: (i) => i.no, html: (i) => `<span class="mono">${esc(i.no)}</span>`, cls: "nw" },
    { h: "Date", t: (i) => i.at, html: (i) => fmtD(i.at), cls: "nw" },
    { h: "Hostel", t: (i) => hName(i.hostelId), cls: "nw" },
    { h: "Item", t: (i) => iName(i.itemId), html: (i) => `<span class="lnk" data-act="ledger" data-h="${i.hostelId}" data-i="${i.itemId}">${esc(iName(i.itemId))}</span>` },
    { h: "Unit", t: (i) => iUnit(i.itemId), cls: "dim" },
    { h: "Reported qty", t: (i) => i.qty, num: 1, html: (i) => `<b>${nf(i.qty)}</b>` },
    { h: "Approved qty", t: (i) => i.receivedQty ?? "", num: 1, html: (i) => (i.receivedQty == null ? `<span class="dim">—</span>` : i.receivedQty < i.qty ? `<span class="qout">${nf(i.receivedQty)}</span>` : nf(i.receivedQty)) },
    { h: "Sent to", t: (i) => i.place || "", cls: "nw" },
    { h: "Status", t: (i) => issueStatus(i).l, html: (i) => badge(issueStatus(i)) },
    { h: "Reported by", t: (i) => uName(i.createdBy), cls: "dim nw" },
    { h: "Decided", t: (i) => (i.decidedAt && i.status !== "pending" ? fmtTs(i.decidedAt) + " · " + uName(i.decidedBy) : ""), cls: "dim nw" },
    { h: "Notes", t: (i) => [i.notes, i.decisionNote && "Admin: " + i.decisionNote].filter(Boolean).join(" · "), cls: "wrap" },
    { h: "", t: () => "", nocsv: 1, nosort: 1, html: issueActs },
  ];
  return head("Damage", "", `${csvBtn("damage")}<button class="btn pri" data-act="issue" data-kind="damage">${ic("alert")}<span class="l">Mark damaged</span></button>`)
    + filterBar("damage", [
      { k: "q", type: "search", ph: "Search no., item, hostel or notes" },
      { k: "h", l: "Hostel", opts: hostelOpts(), all: "All hostels", hide: !M.admin },
      { k: "from", type: "date", l: "From" }, { k: "to", type: "date", l: "To" },
    ])
    + table("damage", cols, rows, { csv: "damage-approvals", emptyIcon: "alert", empty: "No damage waiting for approval." });
};
function damageDecideForm(id, approve) {
  const i = S.d.issues.find((x) => x.id === id);
  if (!i) return;
  const u = iUnit(i.itemId);
  const info = { k: "info", type: "info", html: () => `${esc(hName(i.hostelId))} reported <b>${nf(i.qty)} ${esc(u)}</b> of ${esc(iName(i.itemId))} as damaged on ${fmtD(i.at)}${i.place ? `, to be sent to <b>${esc(i.place)}</b>` : ""}. Reported by ${esc(uName(i.createdBy))}.${i.notes ? `<br>“${esc(i.notes)}”` : ""}` };
  if (approve) {
    openForm({
      title: "Approve damage", sub: `<span class="mono">${esc(i.no)}</span> · ${esc(iName(i.itemId))} · ${esc(hName(i.hostelId))}`,
      fields: [info,
        { k: "qty", l: "Approved qty", type: "number", req: 1, half: 1, val: String(i.qty),
          help: (v) => { const q = +v.qty; if (v.qty === "" || isNaN(q)) return ""; if (q > i.qty) return { t: `Can't be more than ${nf(i.qty)}`, c: "warn" }; if (q < i.qty && q > 0) return { t: `${nf(i.qty - q)} stay in stock`, c: "warn" }; return ""; } },
        { k: "note", l: "Note", type: "textarea", ph: "Reason for approving fewer, remarks…" }],
      submit: "Approve",
      onSubmit: async (v) => { await api("damage.decide", { id, decision: "approve", qty: +v.qty, note: v.note }); return `${i.no}: ${nf(+v.qty)} ${u} approved as damaged`; },
    });
  } else {
    openForm({
      title: "Reject damage", sub: `<span class="mono">${esc(i.no)}</span> · ${esc(iName(i.itemId))} · ${esc(hName(i.hostelId))}`, danger: true,
      fields: [info, { k: "note", l: "Reason", type: "textarea", req: 1, ph: "Tell the hostel why" }],
      submit: "Reject",
      onSubmit: async (v) => { await api("damage.decide", { id, decision: "reject", note: v.note }); return `${i.no} rejected`; },
    });
  }
}

/* ---------- repairs ---------- */
// Approvals Pending → Repair shows open jobs only; Operations → All Repairs shows everything.
function repairsView(key, pendingOnly) {
  const f = fv(key);
  const st = pendingOnly ? f.st || "open" : f.st ?? "";
  const rows = S.d.issues.filter((i) => i.kind === "repair" && i.status !== "void")
    .filter((i) => st === "" || (st === "open" ? outstanding(i) > 0 && i.status !== "closed" : st === "overdue" ? isOverdue(i) : i.status === "closed"))
    .filter((i) => (!f.h || i.hostelId === +f.h) && inRange(i.at, f) && match(f.q, i.no, iName(i.itemId), hName(i.hostelId), i.place, i.notes));
  const open = M.openRepairs;
  const cols = [
    { h: "No.", t: (i) => i.no, html: (i) => `<span class="mono">${esc(i.no)}</span>`, cls: "nw" },
    { h: "Sent on", t: (i) => i.at, html: (i) => fmtD(i.at), cls: "nw" },
    { h: "Hostel", t: (i) => hName(i.hostelId), cls: "nw" },
    { h: "Item", t: (i) => iName(i.itemId), html: (i) => `<span class="lnk" data-act="ledger" data-h="${i.hostelId}" data-i="${i.itemId}">${esc(iName(i.itemId))}</span>` },
    { h: "Sent to", t: (i) => i.place || "", cls: "nw" },
    { h: "Sent", t: (i) => i.qty, num: 1 },
    { h: "Returned", t: (i) => i.returnedQty, num: 1, html: (i) => (i.returnedQty ? `<span class="qin">${nf(i.returnedQty)}</span>` : "0") },
    { h: "Not repairable", t: (i) => i.scrappedQty, num: 1, html: (i) => (i.scrappedQty ? `<span class="qout">${nf(i.scrappedQty)}</span>` : "0") },
    { h: "Still out", t: (i) => outstanding(i), num: 1, html: (i) => `<b>${nf(outstanding(i))}</b>` },
    { h: "Expected back", t: (i) => i.expectedAt || "", html: (i) => (i.expectedAt ? (isOverdue(i) ? `<span class="qout" title="Overdue">${fmtD(i.expectedAt)}</span>` : fmtD(i.expectedAt)) : `<span class="dim">—</span>`), cls: "nw" },
    { h: "Days out", t: (i) => (outstanding(i) > 0 ? daysBetween(i.at, today()) : ""), num: 1, cls: "dim" },
    { h: "Status", t: (i) => issueStatus(i).l, html: (i) => (isOverdue(i) ? `<span class="b red">Overdue</span>` : badge(issueStatus(i))) },
    { h: "Notes", t: (i) => i.notes || "", cls: "wrap" },
    { h: "", t: () => "", nocsv: 1, nosort: 1, html: issueActs },
  ];
  return head(pendingOnly ? "Repair" : "All Repairs", "", `${csvBtn(key)}<button class="btn pri" data-act="issue" data-kind="repair">${ic("wrench")}<span class="l">Send for repair</span></button>`)
    + stats([
      { v: nf(open.reduce((s, i) => s + outstanding(i), 0)), l: "Quantity at repair now", tone: "amber" },
      { v: nf(open.length), l: "Open repair jobs", tone: "cyan" },
      { v: nf(open.filter(isOverdue).length), l: "Overdue", tone: "red", go: key + "?overdue" },
      { v: nf(rows.reduce((s, i) => s + i.returnedQty, 0)), l: "Returned (shown)", tone: "green" },
      { v: nf(rows.reduce((s, i) => s + i.scrappedQty, 0)), l: "Not repairable (shown)", tone: "slate" },
    ])
    + filterBar(key, [
      { k: "q", type: "search", ph: "Search no., item, repairer or notes" },
      pendingOnly ? { k: "st", l: "Status", opts: [["overdue", "Overdue only"]], all: "Still at repair" }
        : { k: "st", l: "Status", opts: [["open", "Still at repair"], ["overdue", "Overdue"], ["closed", "Closed"]], all: "All repairs" },
      { k: "h", l: "Hostel", opts: hostelOpts(), all: "All hostels", hide: !M.admin },
      { k: "from", type: "date", l: "From" }, { k: "to", type: "date", l: "To" },
    ])
    + table(key, cols, rows, { csv: pendingOnly ? "repairs-pending" : "repairs-all", emptyIcon: "wrench", empty: st === "open" ? "Nothing is at repair right now." : "No repairs to show." });
}
V.repairs = () => repairsView("repairs", true);
V.allrepairs = () => repairsView("allrepairs", false);

/* ---------- damaged ---------- */
V.damaged = () => {
  const f = fv("damaged");
  const rows = S.d.txns.filter((t) => t.kind === "damaged" && M.scopeH.includes(t.hostelId))
    .filter((t) => (!f.h || t.hostelId === +f.h) && (!f.c || (M.I.get(t.itemId) || {}).categoryId === +f.c) && (!f.p || t.party === f.p) && inRange(t.at, f))
    .filter((t) => match(f.q, iName(t.itemId), t.party, t.notes, hName(t.hostelId)))
    .sort((a, b) => cmp(b.at, a.at) || b.id - a.id);
  const monthStart = today().slice(0, 8) + "01";
  const issueNo = (id) => (S.d.issues.find((i) => i.id === id) || {}).no || "";
  const places = [...new Set(S.d.damagePlaces.map((p) => p.name).concat(S.d.txns.filter((t) => t.kind === "damaged" && t.party).map((t) => t.party)))].sort(cmp);
  const cols = [
    { h: "Date", t: (t) => t.at, html: (t) => fmtD(t.at), cls: "nw" },
    { h: "Reference", t: (t) => issueNo(t.issueId), html: (t) => `<span class="mono">${esc(issueNo(t.issueId))}</span>` },
    { h: "Hostel", t: (t) => hName(t.hostelId), cls: "nw" },
    { h: "Item", t: (t) => iName(t.itemId), html: (t) => `<span class="lnk" data-act="ledger" data-h="${t.hostelId}" data-i="${t.itemId}">${esc(iName(t.itemId))}</span>` },
    { h: "Category", t: (t) => iCat(t.itemId), cls: "dim nw" },
    { h: "Qty", t: (t) => t.qty, num: 1, html: (t) => `<span class="qout">${nf(t.qty)}</span>` },
    { h: "Unit", t: (t) => iUnit(t.itemId), cls: "dim" },
    { h: "Sent to", t: (t) => t.party || "", cls: "nw" },
    { h: "Notes", t: (t) => t.notes || "", cls: "wrap" },
    { h: "Entered by", t: (t) => uName(t.createdBy), cls: "dim nw" },
  ];
  return head("All Damaged", "Items written off as damaged, including those not repairable", `${csvBtn("damaged")}<button class="btn pri" data-act="issue" data-kind="damage">${ic("alert")}<span class="l">Mark damaged</span></button>`)
    + stats([
      { v: nf(rows.length), l: "Entries" },
      { v: nf(rows.reduce((s, t) => s + t.qty, 0)), l: "Quantity damaged", tone: "red" },
      { v: nf(rows.filter((t) => t.at >= monthStart).reduce((s, t) => s + t.qty, 0)), l: "This month", tone: "amber" },
      { v: nf(new Set(rows.map((t) => t.itemId)).size), l: "Items affected", tone: "slate" },
    ])
    + filterBar("damaged", [
      { k: "q", type: "search", ph: "Search item, notes or destination" },
      { k: "h", l: "Hostel", opts: hostelOpts(), all: "All hostels", hide: !M.admin },
      { k: "c", l: "Category", opts: catOpts(), all: "All categories" },
      { k: "p", l: "Sent to", opts: places.map((p) => [p, p]), all: "Any destination" },
      { k: "from", type: "date", l: "From" }, { k: "to", type: "date", l: "To" },
    ])
    + table("damaged", cols, rows, { csv: "damaged", emptyIcon: "alert", empty: "No damaged items recorded." });
};

/* ---------- warranties ---------- */
V.warranties = () => {
  const f = fv("warranties");
  const rows = M.warr.filter((w) => (!f.h || w.hostelId === +f.h) && (!f.c || w.categoryId === +f.c))
    .filter((w) => !f.st || (f.st === "30" ? w.daysLeft >= 0 && w.daysLeft <= 30 : f.st === "90" ? w.daysLeft >= 0 && w.daysLeft <= 90 : f.st === "exp" ? w.daysLeft < 0 : w.daysLeft > 90))
    .filter((w) => match(f.q, w.title, w.itemId ? iName(w.itemId) : "", w.warrantyNo, w.supplier, w.notes, w.hostelId ? hName(w.hostelId) : ""))
    .sort((a, b) => a.daysLeft - b.daysLeft);
  const cols = [
    { h: "Item", t: (w) => w.title || iName(w.itemId), html: (w) => `<b>${esc(w.title || iName(w.itemId))}</b>${w.title && w.itemId ? `<div class="dim" style="font-size:12px">${esc(iName(w.itemId))}</div>` : ""}` },
    { h: "Category", t: (w) => (M.C.get(w.categoryId) || {}).name || "", cls: "dim nw" },
    { h: "Hostel", t: (w) => (w.hostelId ? hName(w.hostelId) : "All hostels"), cls: "nw" },
    { h: "Warranty no.", t: (w) => w.warrantyNo || "", html: (w) => `<span class="mono">${esc(w.warrantyNo || "")}</span>` },
    { h: "Supplier", t: (w) => w.supplier || "", cls: "nw" },
    { h: "Qty", t: (w) => w.qty ?? "", num: 1 },
    { h: "Start", t: (w) => w.startAt || "", html: (w) => fmtD(w.startAt), cls: "nw" },
    { h: "Expiry", t: (w) => w.expiryAt, html: (w) => `<b>${fmtD(w.expiryAt)}</b>`, cls: "nw" },
    { h: "Status", t: (w) => w.daysLeft, csv: (w) => (w.daysLeft < 0 ? "Expired" : w.daysLeft + " days left"), num: 1, html: (w) => daysBadge(w.daysLeft) },
    { h: "Notes", t: (w) => w.notes || "", cls: "wrap" },
    { h: "", t: () => "", nocsv: 1, nosort: 1, html: (w) => (canWriteHostel(w.hostelId) ? `<div class="rowacts"><button class="iconbtn" title="Edit" data-act="warranty" data-id="${w.id}">${ic("edit")}</button>${M.admin ? `<button class="iconbtn del" title="Delete" data-act="delwarranty" data-id="${w.id}">${ic("trash")}</button>` : ""}</div>` : "") },
  ];
  const inN = (a, b) => M.warr.filter((w) => w.daysLeft >= a && w.daysLeft <= b).length;
  return head("Warranties", "Track warranties and see what is about to expire", `${csvBtn("warranties")}<button class="btn pri" data-act="warranty">${ic("plus")}<span class="l">Add warranty</span></button>`)
    + stats([
      { v: nf(M.warr.length), l: "Warranties" },
      { v: nf(inN(0, 30)), l: "Expiring in 30 days", tone: "red", go: "warranties?30" },
      { v: nf(inN(0, 90)), l: "Expiring in 90 days", tone: "amber", go: "warranties?90" },
      { v: nf(M.warr.filter((w) => w.daysLeft < 0).length), l: "Expired", tone: "slate", go: "warranties?exp" },
      { v: nf(M.warr.filter((w) => w.daysLeft > 90).length), l: "Active, over 90 days", tone: "green" },
    ])
    + filterBar("warranties", [
      { k: "q", type: "search", ph: "Search item, warranty no. or supplier" },
      { k: "st", l: "Status", opts: [["30", "Expiring in 30 days"], ["90", "Expiring in 90 days"], ["exp", "Expired"], ["ok", "Active, over 90 days"]], all: "Any status" },
      { k: "c", l: "Category", opts: catOpts(), all: "All categories" },
      { k: "h", l: "Hostel", opts: hostelOpts(), all: "All hostels", hide: !M.admin },
    ])
    + table("warranties", cols, rows, { csv: "warranties", emptyIcon: "shield", empty: "No warranties yet." });
};

/* ---------- masters (Admin) ---------- */
const ADD_CATEGORY = { title: "Add category", label: "Category name", ph: "e.g. Furniture", coll: "categories" };
const ADD_UNIT = { title: "Add unit", label: "Unit", ph: "e.g. Nos, Sets, Kg", coll: "units", value: (r, name) => name };
function unitOpts(current) {
  const names = (S.d.units || []).map((u) => u.name);
  if (current && !names.includes(current)) names.push(current);
  return names.sort(cmp).map((n) => [n, n]);
}
const MASTERS = {
  hostels: {
    title: "Hostels", one: "hostel", sub: "Each hostel keeps its own stock", icon: "building",
    rows: () => S.d.hostels,
    search: (r) => [r.name, r.address],
    cols: [
      { h: "Hostel", t: (r) => r.name, html: (r) => `<span class="lnk" data-act="invHostel" data-id="${r.id}">${esc(r.name)}</span>` },
      { h: "Address", t: (r) => r.address, cls: "wrap" },
      { h: "Telegram group", t: (r) => r.tgChat || "", html: (r) => (r.tgChat ? `<span class="b green">Connected</span>` : `<span class="dim">—</span>`) },
      { h: "Wardens", t: (r) => S.d.users.filter((u) => u.hostelId === r.id && u.role === "Warden").map((u) => u.name).join(", "), cls: "wrap" },
      { h: "Items in stock", t: (r) => [...M.inv.values()].filter((x) => x.hostelId === r.id).reduce((s, x) => s + x.current, 0), num: 1 },
      { h: "Status", t: (r) => (r.active ? "Active" : "Inactive"), html: (r) => (r.active ? `<span class="b green">Active</span>` : `<span class="b">Inactive</span>`) },
    ],
    fields: [{ k: "name", l: "Hostel name", req: 1 }, { k: "address", l: "Address / location" },
      { k: "tgChat", l: "Telegram group ID", ph: "-1001234567890", help: () => "This hostel's own group gets only this hostel's messages. Leave blank to use only the main group." },
      { k: "active", l: "Active (can receive transfers)", type: "check", val: true }],
  },
  items: {
    title: "Items", one: "item", sub: "The asset types you count, with their unit and category", icon: "tag",
    rows: () => S.d.items,
    search: (r) => [r.name, iCat(r.id), r.unit, r.notes],
    cols: [
      { h: "Item", t: (r) => r.name, html: (r) => `<span class="lnk" data-act="ledger" data-h="" data-i="${r.id}">${esc(r.name)}</span>` },
      { h: "Category", t: (r) => iCat(r.id), cls: "nw" },
      { h: "Unit", t: (r) => r.unit },
      { h: "Total stock", t: (r) => [...M.inv.values()].filter((x) => x.itemId === r.id).reduce((s, x) => s + x.current, 0), num: 1 },
      { h: "Notes", t: (r) => r.notes, cls: "wrap" },
      { h: "Status", t: (r) => (r.active ? "Active" : "Inactive"), html: (r) => (r.active ? `<span class="b green">Active</span>` : `<span class="b">Inactive</span>`) },
    ],
    fields: [{ k: "name", l: "Item name", req: 1, ph: "e.g. Single bed, steel" },
      { k: "categoryId", l: "Category", type: "select", half: 1, opts: () => catOpts(), blank: "— None —", add: ADD_CATEGORY },
      { k: "unit", l: "Unit", type: "select", half: 1, val: "Nos", opts: (v) => unitOpts(v.unit), add: ADD_UNIT },
      { k: "notes", l: "Notes" }, { k: "active", l: "Active (shown in forms)", type: "check", val: true }],
  },
  categories: {
    title: "Categories", one: "category", sub: "Groups for items and warranties", icon: "folder",
    rows: () => S.d.categories, search: (r) => [r.name],
    cols: [{ h: "Category", t: (r) => r.name, cls: "strong" }, { h: "Items", t: (r) => S.d.items.filter((i) => i.categoryId === r.id).length, num: 1 }],
    fields: [{ k: "name", l: "Category name", req: 1 }],
  },
  units: {
    title: "Units", one: "unit", sub: "Units items are counted in", icon: "box", coll: "units",
    rows: () => S.d.units || [], search: (r) => [r.name],
    cols: [{ h: "Unit", t: (r) => r.name, cls: "strong" }, { h: "Items", t: (r) => S.d.items.filter((i) => i.unit === r.name).length, num: 1 }],
    fields: [{ k: "name", l: "Unit", req: 1, ph: "e.g. Nos, Sets, Kg" }],
  },
  places: {
    title: "Damage destinations", one: "destination", sub: "Where damaged items are sent", icon: "trash", coll: "damagePlaces",
    rows: () => S.d.damagePlaces, search: (r) => [r.name],
    cols: [{ h: "Destination", t: (r) => r.name, cls: "strong" }, { h: "Times used", t: (r) => S.d.txns.filter((t) => t.kind === "damaged" && t.party === r.name).length, num: 1 }],
    fields: [{ k: "name", l: "Destination", req: 1, ph: "e.g. Scrap dealer" }],
  },
  users: {
    title: "Users", one: "user", sub: "Admins see every hostel; wardens see only their own hostel", icon: "users",
    rows: () => S.d.users, search: (r) => [r.name, r.email, r.role, r.hostelId ? hName(r.hostelId) : "", r.phone],
    cols: [
      { h: "Name", t: (r) => r.name, html: (r) => `<div style="display:flex;align-items:center;gap:9px"><span class="av" style="width:30px;height:30px;font-size:11px">${esc(initials(r.name))}</span><b>${esc(r.name)}</b></div>` },
      { h: "Email", t: (r) => r.email || "" },
      { h: "Role", t: (r) => r.role, html: (r) => `<span class="b ${r.role === "Admin" ? "violet" : "blue"}">${esc(r.role)}</span>` },
      { h: "Hostel", t: (r) => (r.role === "Admin" ? "All hostels" : hName(r.hostelId)), cls: "nw" },
      { h: "Phone", t: (r) => r.phone || "", cls: "nw" },
      { h: "Can sign in", t: (r) => (r.hasLogin && r.active ? "Yes" : "No"), html: (r) => (!r.active ? `<span class="b">Inactive</span>` : r.hasLogin ? `<span class="b green">Yes</span>` : `<span class="b amber">No password</span>`) },
    ],
    fields: [
      { k: "name", l: "Full name", req: 1 }, { k: "email", l: "Email (used to sign in)", type: "email", req: 1 },
      { k: "role", l: "Role", type: "seg", opts: () => [["Warden", "Warden"], ["Admin", "Admin"]], val: "Warden" },
      { k: "hostelId", l: "Hostel", type: "select", opts: () => S.d.hostels.map((h) => [h.id, h.name]), blank: "— Choose —", show: (v) => v.role === "Warden", half: 1 },
      { k: "phone", l: "Phone", half: 1 },
      { k: "password", l: "Password", type: "password", ph: "At least 8 characters", help: "Leave blank to keep the current password." },
      { k: "active", l: "Active (can sign in)", type: "check", val: true },
    ],
  },
};
const initials = (n) => String(n || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join("");
function masterView(key) {
  const m = MASTERS[key];
  const f = fv(key);
  const rows = m.rows().filter((r) => match(f.q, ...m.search(r))).filter((r) => !f.st || (f.st === "a" ? r.active !== false : r.active === false)).filter((r) => !f.role || r.role === f.role).slice().sort((a, b) => cmp(a.name, b.name));
  const cols = m.cols.concat([{ h: "", t: () => "", nocsv: 1, nosort: 1, html: (r) => `<div class="rowacts"><button class="iconbtn" title="Edit" data-act="mEdit" data-m="${key}" data-id="${r.id}">${ic("edit")}</button><button class="iconbtn del" title="Delete" data-act="mDel" data-m="${key}" data-id="${r.id}">${ic("trash")}</button></div>` }]);
  const all = m.rows();
  const st = [{ v: nf(all.length), l: m.title }];
  if (key === "hostels" || key === "items") st.push({ v: nf(all.filter((r) => r.active).length), l: "Active", tone: "green" }, { v: nf(all.filter((r) => !r.active).length), l: "Inactive", tone: "slate" });
  if (key === "users") st.push({ v: nf(all.filter((r) => r.role === "Admin").length), l: "Admins", tone: "violet" }, { v: nf(all.filter((r) => r.role === "Warden").length), l: "Wardens", tone: "cyan" }, { v: nf(all.filter((r) => !r.hasLogin || !r.active).length), l: "Cannot sign in", tone: "amber" });
  const fdefs = [{ k: "q", type: "search", ph: `Search ${m.title.toLowerCase()}` }];
  if (key === "hostels" || key === "items" || key === "users") fdefs.push({ k: "st", l: "Status", opts: [["a", "Active"], ["i", "Inactive"]], all: "Any status" });
  if (key === "users") fdefs.push({ k: "role", l: "Role", opts: [["Admin", "Admin"], ["Warden", "Warden"]], all: "All roles" });
  return head(m.title, m.sub, `${csvBtn(key)}<button class="btn pri" data-act="mEdit" data-m="${key}">${ic("plus")}<span class="l">Add ${m.one}</span></button>`)
    + stats(st) + filterBar(key, fdefs) + table(key, cols, rows, { csv: key, emptyIcon: m.icon, empty: `No ${m.title.toLowerCase()} yet.` });
}
["hostels", "items", "categories", "units", "places", "users"].forEach((k) => (V[k] = () => masterView(k)));

/* ---------- settings ---------- */
V.settings = () => {
  const s = S.d.settings || {};
  const off = new Set(s.tgOff || []);
  const ev = Object.entries(s.tgEvents || {});
  const hostelGroups = S.d.hostels.filter((h) => h.tgChat).length;
  return head("Settings")
    + `<div class="card" style="max-width:760px"><div class="ch"><h3>Telegram notifications</h3><span class="sub">${hostelGroups ? `Main group + ${hostelGroups} hostel group${hostelGroups > 1 ? "s" : ""}` : "Main group"}</span></div>
    <form id="setForm" class="db" style="padding:18px 20px">
      <div class="fld"><label class="chk"><input type="checkbox" name="telegramOn" ${s.telegramOn ? "checked" : ""}> Send Telegram notifications</label><div class="help">Create a bot with <b>@BotFather</b>, add it to your group, then paste its token and the group's chat ID (it starts with <b>-100</b>). Each hostel can also have its own group: set it in Masters → Hostels.</div></div>
      <div class="fld half"><label>Bot token</label><input name="telegramToken" type="password" autocomplete="new-password" placeholder="${s.telegramTokenSet ? "Saved — leave blank to keep" : "123456:ABC…"}"></div>
      <div class="fld half"><label>Main group chat ID</label><input name="telegramChat" value="${esc(s.telegramChat)}" placeholder="-1001234567890"><div class="help">Gets every message from all hostels.</div></div>
      <div class="fld"><label>App address (added as a link in messages)</label><input name="appUrl" value="${esc(s.appUrl || location.origin + location.pathname.replace(/index\.html$/, ""))}"></div>
      <div class="fld" style="border-top:1px solid var(--line2);padding-top:14px;margin-top:4px"><label>Messages to send</label>
        <div class="evgrid">${ev.map(([k, l]) => `<label class="chk"><input type="checkbox" name="ev_${k}" ${off.has(k) ? "" : "checked"}> ${esc(l)}</label>`).join("")}</div></div>
      <div class="fld half"><label>Daily messages time</label><input name="dailyTime" type="time" value="${esc(s.dailyTime || "09:00")}"><div class="help">Repairs overdue, warranties expiring and the morning summary go out once a day after this time.${s.lastDaily ? ` Last sent ${fmtD(s.lastDaily)}.` : ""}</div></div>
      <div class="err" id="setErr"></div>
      <div class="fld" style="flex-direction:row;gap:8px;flex-wrap:wrap"><button class="btn pri" type="submit">Save settings</button><button class="btn" type="button" data-act="testNotify">${ic("bell")}Send a test</button><button class="btn" type="button" data-act="dailyNow">${ic("clock")}Send daily messages now</button>${s.telegramTokenSet ? `<button class="btn bad" type="button" data-act="clearToken">Remove bot token</button>` : ""}</div>
      <div id="testOut" class="fld"></div>
      <div class="fld" style="border-top:1px solid var(--line2);padding-top:14px"><label>Cron job (recommended)</label><div class="help">Daily messages also go out when someone opens the app after the set time. To send them even if nobody opens the app, add a cron job in Hostinger hPanel → Advanced → Cron Jobs, type <b>PHP</b>, every hour, with this command:</div>
        <input readonly value="${esc(s.cronCmd || "")}" onclick="this.select()" style="font-family:var(--mono,monospace);font-size:12px">
        <div class="help">Or as a URL, if your cron needs one: <span class="mono" style="word-break:break-all">${esc(s.cronUrl || "")}</span></div></div>
    </form></div>
    <div class="card" style="max-width:760px"><div class="ch"><h3>Sample data</h3><span class="sub">4 hostels with stock, transfers, repairs and warranties to try things out</span></div><div style="padding:16px 20px"><button class="btn" data-act="seed">${ic("box")}Load sample data</button></div></div>`;
};

/* =================================================================== forms */
function openForm(o) {
  closeDlg();
  const vals = {};
  for (const fd of o.fields) vals[fd.k] = fd.val !== undefined ? (typeof fd.val === "function" ? fd.val() : fd.val) : "";
  if (o.values) Object.assign(vals, o.values);
  const wrap = document.createElement("div");
  wrap.className = "dlg";
  wrap.innerHTML = `<form novalidate><div class="dh"><div><h2>${esc(o.title)}</h2>${o.sub ? `<p>${o.sub}</p>` : ""}</div><button type="button" class="iconbtn" data-close aria-label="Close">${ic("x")}</button></div><div class="db"></div><div class="df"><button type="button" class="btn" data-close>Cancel</button><button type="submit" class="btn ${o.danger ? "danger" : "pri"}">${esc(o.submit || "Save")}</button></div></form>`;
  document.body.appendChild(wrap);
  const form = $("form", wrap), body = $(".db", wrap);
  const read = () => {
    for (const fd of o.fields) {
      if (fd.type === "info") continue;
      const el = form.elements[fd.k];
      if (!el) continue;
      vals[fd.k] = fd.type === "check" ? el.checked : el.value;
    }
    return vals;
  };
  const draw = () => {
    body.innerHTML = o.fields.map((fd) => {
      if (fd.show && !fd.show(vals)) return "";
      const v = vals[fd.k] ?? "";
      const lab = `<label for="f_${fd.k}">${labText(fd)}</label>`;
      const helpH = `<div class="help" data-help="${fd.k}"></div>`;
      let ctl;
      if (fd.type === "info") return `<div class="summary">${fd.html(vals)}</div>`;
      if (fd.type === "select") {
        ctl = `<select id="f_${fd.k}" name="${fd.k}">${fd.blank !== undefined ? `<option value="">${esc(fd.blank)}</option>` : ""}${fd.opts(vals).map(([ov, ol]) => `<option value="${esc(ov)}"${String(ov) === String(v) ? " selected" : ""}>${esc(ol)}</option>`).join("")}</select>`;
        if (fd.add && M.admin) ctl = `<div class="inrow">${ctl}<button type="button" class="btn addbtn" data-addfor="${fd.k}" title="${esc(fd.add.title)}" aria-label="${esc(fd.add.title)}">${ic("plus")}</button></div>`;
      }
      else if (fd.type === "textarea") ctl = `<textarea id="f_${fd.k}" name="${fd.k}" placeholder="${esc((typeof fd.ph === "function" ? fd.ph(vals) : fd.ph) || "")}">${esc(v)}</textarea>`;
      else if (fd.type === "check") return `<div class="fld"><label class="chk"><input type="checkbox" name="${fd.k}" ${v ? "checked" : ""}> ${esc(fd.l)}</label>${helpH}</div>`;
      else if (fd.type === "seg") ctl = `<input type="hidden" name="${fd.k}" value="${esc(v)}"><div class="seg">${fd.opts(vals).map(([ov, ol, icn]) => `<button type="button" data-seg="${fd.k}" data-v="${esc(ov)}" class="${String(ov) === String(v) ? "on" : ""}">${icn ? ic(icn) : ""}${esc(ol)}</button>`).join("")}</div>`;
      else ctl = `<input id="f_${fd.k}" name="${fd.k}" type="${fd.type || "text"}" value="${esc(v)}" placeholder="${esc((typeof fd.ph === "function" ? fd.ph(vals) : fd.ph) || "")}" ${fd.type === "number" ? 'min="0" step="1" inputmode="numeric"' : ""} ${fd.ro ? "readonly tabindex=\"-1\" style=\"background:var(--line2);font-weight:700\"" : ""} ${fd.list ? `list="l_${fd.k}"` : ""} autocomplete="${fd.type === "password" ? "new-password" : "off"}">${fd.list ? `<datalist id="l_${fd.k}">${fd.list.map((x) => `<option value="${esc(x)}">`).join("")}</datalist>` : ""}`;
      return `<div class="fld${fd.half ? " half" : ""}${bad[fd.k] ? " bad" : ""}" data-fk="${fd.k}">${lab}${ctl}${bad[fd.k] ? `<div class="ferr">${esc(bad[fd.k])}</div>` : ""}${helpH}</div>`;
    }).join("") + `<div class="err" role="alert"></div>`;
    refresh();
  };
  // Field-level errors: red border and a message under each field that needs attention.
  const bad = {};
  const clearBad = (k) => {
    if (!bad[k]) return;
    delete bad[k];
    const f = body.querySelector(`.fld[data-fk="${k}"]`);
    if (f) { f.classList.remove("bad"); f.querySelector(".ferr")?.remove(); }
    if (!Object.keys(bad).length) setErr("");
  };
  const setErr = (msg) => {
    const e = $(".err", form);
    if (!e) return;
    e.innerHTML = msg ? `${ic("alert")}<span>${esc(msg)}</span>` : "";
    if (msg) e.scrollIntoView({ block: "nearest", behavior: "smooth" });
  };
  // Labels and hints change with the values; update them in place so typing is never interrupted.
  const isReq = (fd) => (typeof fd.req === "function" ? fd.req(vals) : fd.req);
  function labText(fd) { return `${esc(typeof fd.l === "function" ? fd.l(vals) : fd.l)}${isReq(fd) ? ' <span class="req">*</span>' : ""}`; }
  function refresh() {
    for (const fd of o.fields) {
      const h = body.querySelector(`[data-help="${fd.k}"]`);
      if (h) {
        const help = typeof fd.help === "function" ? fd.help(vals) : fd.help;
        h.className = "help " + (help && typeof help === "object" ? help.c || "" : "");
        h.innerHTML = help ? (typeof help === "object" ? help.t : esc(help)) : "";
        h.hidden = !help;
      }
      const l = typeof fd.l === "function" && body.querySelector(`label[for="f_${fd.k}"]`);
      if (l) l.innerHTML = labText(fd);
    }
  }
  draw();
  // Only choices that can show or hide other fields (dropdowns, checkboxes) rebuild the form.
  const rebuilds = (el) => el.tagName === "SELECT" || el.type === "checkbox";
  const onField = (e) => {
    read();
    if (o.onChange) o.onChange(vals, e.target.name);
    if (rebuilds(e.target)) { const n = e.target.name; draw(); const el = form.elements[n]; if (el && el.focus) el.focus(); }
    else refresh();
  };
  form.addEventListener("change", (e) => { if (e.target.name) clearBad(e.target.name); if (rebuilds(e.target)) onField(e); else { read(); refresh(); } });
  form.addEventListener("input", (e) => { if (e.target.name) clearBad(e.target.name); if (!rebuilds(e.target)) onField(e); });
  form.addEventListener("click", (e) => {
    const ab = e.target.closest("[data-addfor]");
    if (ab) {
      const fd = o.fields.find((x) => x.k === ab.dataset.addfor);
      read();
      promptBox({
        title: fd.add.title, label: fd.add.label, ph: fd.add.ph,
        onSave: async (name) => {
          const r = await api("master.save", { coll: fd.add.coll, data: { name } });
          await reload();
          vals[fd.k] = String(fd.add.value ? fd.add.value(r, name) : r.id);
          draw();
          toast(`${name} added`);
        },
      });
      return;
    }
    const sb = e.target.closest("[data-seg]");
    if (sb) { read(); clearBad(sb.dataset.seg); vals[sb.dataset.seg] = sb.dataset.v; if (o.onChange) o.onChange(vals, sb.dataset.seg); draw(); return; }
    if (e.target.closest("[data-close]")) closeDlg();
  });
  wrap.addEventListener("mousedown", (e) => { if (e.target === wrap) closeDlg(); });
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    read();
    for (const k of Object.keys(bad)) delete bad[k];
    for (const fd of o.fields) {
      if (fd.type === "info" || (fd.show && !fd.show(vals))) continue;
      const v = String(vals[fd.k] ?? "").trim();
      const name = (typeof fd.l === "function" ? fd.l(vals) : fd.l).replace(/\s*\(.*?\)/g, "").toLowerCase();
      if (isReq(fd) && v === "") bad[fd.k] = fd.type === "select" || fd.type === "seg" ? `Choose ${name}.` : `Enter ${name}.`;
      else if (fd.type === "number" && v !== "" && (!/^\d+$/.test(v) || (isReq(fd) && +v <= 0 && fd.k === "qty"))) bad[fd.k] = "Enter a whole number greater than 0.";
      else if (fd.type === "email" && v !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) bad[fd.k] = "Enter a valid email address.";
    }
    const n = Object.keys(bad).length;
    if (n) {
      draw();
      setErr(n === 1 ? "Please fix the highlighted field." : `Please fix the ${n} highlighted fields.`);
      const first = body.querySelector(".fld.bad");
      if (first) { first.scrollIntoView({ block: "center", behavior: "smooth" }); const c = first.querySelector("input:not([type=hidden]), textarea, .ss-btn, select"); if (c && c.focus) c.focus({ preventScroll: true }); }
      return;
    }
    const btn = $('button[type="submit"]', form);
    btn.disabled = true; setErr("");
    try {
      const msg = await o.onSubmit(vals);
      closeDlg();
      if (msg) toast(msg);
      await reload();
    } catch (ex) {
      const m = ex.message || "Couldn't save. Try again.";
      // A duplicate name or email points at that field too.
      const k = /already exists/.test(m) && (form.elements.email && /email/.test(m) ? "email" : form.elements.name ? "name" : "");
      if (k) { bad[k] = "Already used. Choose a different one."; draw(); }
      setErr(m);
    }
    finally { btn.disabled = false; }
  });
  setTimeout(() => { const first = form.querySelector(".db input:not([type=hidden]):not([type=checkbox]), .db select, .db textarea"); if (first && window.innerWidth > 900) first.focus(); }, 40);
}
function promptBox(o) {
  const w = document.createElement("div");
  w.className = "dlg mini";
  w.innerHTML = `<form novalidate class="box"><div class="dh"><div><h2>${esc(o.title)}</h2></div><button type="button" class="iconbtn" data-x aria-label="Close">${ic("x")}</button></div>
    <div class="db"><div class="fld"><label>${esc(o.label)} <span class="req">*</span></label><input name="v" placeholder="${esc(o.ph || "")}" autocomplete="off"></div><div class="err"></div></div>
    <div class="df"><button type="button" class="btn" data-x>Cancel</button><button type="submit" class="btn pri">Add</button></div></form>`;
  document.body.appendChild(w);
  const f = $("form", w), err = $(".err", f);
  const close = () => w.remove();
  w.addEventListener("click", (e) => { if (e.target.closest("[data-x]")) close(); });
  w.addEventListener("mousedown", (e) => { if (e.target === w) close(); });
  f.addEventListener("submit", async (e) => {
    e.preventDefault();
    const v = f.v.value.trim();
    if (!v) { f.querySelector(".fld").classList.add("bad"); err.innerHTML = `${ic("alert")}<span>Enter ${esc(o.label.toLowerCase())}.</span>`; f.v.focus(); return; }
    const btn = $('button[type="submit"]', f);
    btn.disabled = true; err.textContent = "";
    try { await o.onSave(v); close(); } catch (ex) { err.innerHTML = `${ic("alert")}<span>${esc(ex.message || "Couldn't add. Try again.")}</span>`; btn.disabled = false; }
  });
  setTimeout(() => f.v.focus(), 30);
}
function closeDlg() { document.querySelectorAll(".dlg").forEach((d) => d.remove()); }
function confirmBox(title, text, ok = "Confirm", danger = false) {
  return new Promise((res) => {
    closeDlg();
    const w = document.createElement("div");
    w.className = "dlg";
    w.innerHTML = `<div class="box"><div class="dh"><div><h2>${esc(title)}</h2><p>${text}</p></div></div><div class="df"><button class="btn" data-no>Cancel</button><button class="btn ${danger ? "danger" : "pri"}" data-yes>${esc(ok)}</button></div></div>`;
    document.body.appendChild(w);
    w.addEventListener("click", (e) => { if (e.target.closest("[data-yes]")) { w.remove(); res(true); } else if (e.target.closest("[data-no]") || e.target === w) { w.remove(); res(false); } });
    setTimeout(() => $("[data-yes]", w).focus(), 30);
  });
}

/* ---------- stock in form ---------- */
function stockForm() {
  const hs = hostelOpts().filter(([id]) => (M.H.get(id) || {}).active);
  const items = S.d.items.filter((i) => i.active);
  if (!items.length) return toast(M.admin ? "Add items under Masters → Items first." : "Ask an Admin to add items first.", true);
  if (!hs.length) return toast("Add a hostel first.", true);
  const hasOpening = (h, i) => S.d.txns.some((t) => t.kind === "opening" && t.hostelId === +h && t.itemId === +i);
  openForm({
    title: "Add stock",
    fields: [
      { k: "kind", l: "Type", type: "seg", opts: () => [["in", "Received", "in"], ["opening", "Opening stock", "box"]], val: "in" },
      { k: "hostelId", l: "Hostel", type: "select", opts: () => hs, val: String(M.admin ? hs[0][0] : M.myH), show: () => M.admin, req: 1 },
      { k: "itemId", l: "Item", type: "select", blank: "— Choose item —", opts: () => groupedItems(items), req: 1,
        help: (v) => (v.itemId ? (v.kind === "opening" && hasOpening(v.hostelId, v.itemId) ? { t: "Opening stock is already entered for this item at this hostel. Use Received instead.", c: "warn" } : { t: `In stock now: <b>${nf((M.inv.get(v.hostelId + "|" + v.itemId) || {}).current || 0)}</b> ${esc(iUnit(+v.itemId))}` }) : "") },
      { k: "qty", l: "Quantity", type: "number", req: 1, half: 1 },
      { k: "at", l: (v) => (v.kind === "opening" ? "As of date" : "Date received"), type: "date", req: 1, val: today(), half: 1 },
      { k: "notes", l: "Notes", type: "textarea", req: (v) => v.kind === "in", ph: (v) => (v.kind === "in" ? "Where it came from, bill number…" : "Remarks") },
    ],
    submit: "Add stock",
    onSubmit: async (v) => { await api("stock.add", { ...v, hostelId: +v.hostelId }); return `Added ${nf(v.qty)} ${iUnit(+v.itemId)} of ${iName(+v.itemId)}`; },
  });
}
function groupedItems(items) {
  return items.slice().sort((a, b) => cmp(iCat(a.id), iCat(b.id)) || cmp(a.name, b.name)).map((i) => [i.id, `${i.name} · ${iCat(i.id)}`]);
}

/* ---------- issue form ---------- */
function issueForm(kind = "transfer") {
  const hs = hostelOpts().filter(([id]) => (M.H.get(id) || {}).active);
  if (!hs.length) return toast("Add a hostel first.", true);
  const startH = M.admin ? hs[0][0] : M.myH;
  const itemsFor = (h) => S.d.items.filter((i) => available(+h, i.id) > 0);
  openForm({
    title: "Issue asset",
    fields: [
      { k: "kind", l: "What is happening?", type: "seg", opts: () => [["transfer", "Transfer", "move"], ["repair", "Repair", "wrench"], ["damage", "Damaged", "alert"]], val: kind },
      { k: "hostelId", l: "From hostel", type: "select", req: 1, opts: () => hs, val: String(startH), show: () => M.admin },
      { k: "itemId", l: "Item", type: "select", blank: "— Choose item —", req: 1, opts: (v) => groupedItems(itemsFor(v.hostelId)),
        help: (v) => (itemsFor(v.hostelId).length ? (v.itemId ? { t: `Available to issue: <b>${nf(available(+v.hostelId, +v.itemId))}</b> ${esc(iUnit(+v.itemId))}`, c: "good" } : "") : { t: "This hostel has no stock to issue yet.", c: "warn" }) },
      { k: "qty", l: "Quantity", type: "number", req: 1, half: 1,
        help: (v) => (v.qty && v.itemId && +v.qty > available(+v.hostelId, +v.itemId) ? { t: "More than available", c: "warn" } : "") },
      { k: "at", l: "Date", type: "date", req: 1, val: today(), half: 1 },
      { k: "toHostelId", l: "To hostel", type: "select", blank: "— Choose hostel —", req: 1, show: (v) => v.kind === "transfer",
        opts: (v) => S.d.hostels.filter((h) => h.active && h.id !== +v.hostelId).map((h) => [h.id, h.name]) },
      { k: "place", l: "Sent to (repairer)", req: 1, ph: "e.g. Sharma Electricals", show: (v) => v.kind === "repair", half: 1 },
      { k: "expectedAt", l: "Expected back", type: "date", req: 1, show: (v) => v.kind === "repair", half: 1 },
      { k: "dplace", l: "Sent to after damage", type: "select", blank: "— Choose —", req: 1, show: (v) => v.kind === "damage", opts: () => S.d.damagePlaces.map((p) => [p.name, p.name]) },
      { k: "notes", l: "Notes", type: "textarea", ph: "Reason, condition, bill…" },
    ],
    onChange: (v, k) => { if (k === "hostelId") { v.itemId = ""; v.toHostelId = ""; } },
    submit: "Issue",
    onSubmit: async (v) => {
      const r = await api("issue.create", { kind: v.kind, hostelId: +v.hostelId, itemId: +v.itemId, qty: +v.qty, at: v.at, toHostelId: v.toHostelId ? +v.toHostelId : null, place: v.kind === "damage" ? v.dplace : v.place, expectedAt: v.expectedAt, notes: v.notes });
      return v.kind === "transfer" ? `${r.issue.no} sent to ${hName(+v.toHostelId)} for approval` : v.kind === "repair" ? `${r.issue.no}: sent for repair` : r.issue.status === "pending" ? `${r.issue.no}: sent to Admin for approval` : `${r.issue.no}: marked damaged`;
    },
  });
}

/* ---------- transfer receipt form ---------- */
function receiveForm(id) {
  const i = S.d.issues.find((x) => x.id === id);
  if (!i) return;
  const u = iUnit(i.itemId);
  openForm({
    title: "Update received quantity", sub: `<span class="mono">${esc(i.no)}</span> · ${esc(iName(i.itemId))} · ${esc(hName(i.hostelId))} → ${esc(hName(i.toHostelId))}`,
    fields: [
      { k: "info", type: "info", html: () => `${esc(hName(i.hostelId))} sent <b>${nf(i.qty)} ${esc(u)}</b> on ${fmtD(i.at)}${i.notes ? ` · “${esc(i.notes)}”` : ""}. Sent by ${esc(uName(i.createdBy))}.` },
      { k: "sent", l: "Send qty", type: "text", half: 1, val: String(i.qty), ro: 1 },
      { k: "receivedQty", l: "Received qty", type: "number", half: 1, req: 1, val: String(i.qty),
        help: (v) => { const r = +v.receivedQty; if (v.receivedQty === "" || isNaN(r)) return ""; if (r > i.qty) return { t: `Can't be more than ${nf(i.qty)}`, c: "warn" }; if (r === i.qty) return { t: "All received", c: "good" }; if (r === 0) return { t: "Nothing received: all stay with the sending hostel", c: "warn" }; return { t: `${nf(i.qty - r)} short: they stay with the sending hostel`, c: "warn" }; } },
      { k: "note", l: (v) => (+v.receivedQty < i.qty ? "Reason for the difference" : "Note"), type: "textarea", req: 0, ph: "e.g. 2 broken in transit" },
    ],
    submit: "Update",
    onSubmit: async (v) => {
      const r = +v.receivedQty;
      if (r < i.qty && !String(v.note || "").trim()) throw { message: "Fewer items arrived than were sent. Add a note explaining the difference." };
      await api("issue.receive", { id, receivedQty: r, note: v.note });
      return r === i.qty ? `${i.no}: ${nf(r)} ${u} received — stock updated` : r === 0 ? `${i.no}: marked not received` : `${i.no}: ${nf(r)} of ${nf(i.qty)} received — stock updated`;
    },
  });
}

/* ---------- repair return form ---------- */
function returnForm(id) {
  const i = S.d.issues.find((x) => x.id === id);
  if (!i) return;
  const out = outstanding(i);
  openForm({
    title: "Record return from repair", sub: `<span class="mono">${esc(i.no)}</span> · ${esc(iName(i.itemId))} · ${esc(hName(i.hostelId))}`,
    fields: [
      { k: "info", type: "info", html: () => `Sent <b>${nf(i.qty)}</b> on ${fmtD(i.at)}${i.place ? " to <b>" + esc(i.place) + "</b>" : ""}. Already back: <b>${nf(i.returnedQty)}</b>, not repairable: <b>${nf(i.scrappedQty)}</b>. <b>${nf(out)}</b> still at repair.` },
      { k: "okQty", l: "Repaired and returned", type: "number", half: 1, val: String(out), help: "Goes back into stock." },
      { k: "scrapQty", l: "Not repairable", type: "number", half: 1, val: "0", help: M.admin ? "Counted as damaged." : "Sent to an Admin for damage approval." },
      { k: "place", l: "Not repairable items sent to", type: "select", blank: "— Not sent anywhere —", opts: () => S.d.damagePlaces.map((p) => [p.name, p.name]), show: (v) => +v.scrapQty > 0 },
      { k: "at", l: "Date returned", type: "date", req: 1, val: today() },
      { k: "notes", l: "Notes", type: "textarea", ph: "Repair bill, remarks…" },
    ],
    submit: "Save return",
    onSubmit: async (v) => { await api("repair.return", { id, okQty: +v.okQty || 0, scrapQty: +v.scrapQty || 0, place: v.place, at: v.at, notes: v.notes }); return "Return recorded"; },
  });
}

/* ---------- warranty form ---------- */
function warrantyForm(id) {
  const w = id ? S.d.warranties.find((x) => x.id === id) : null;
  openForm({
    title: w ? "Edit warranty" : "Add warranty", sub: "Track what's covered and when it expires",
    values: w ? { ...w, hostelId: w.hostelId ? String(w.hostelId) : "", categoryId: w.categoryId ? String(w.categoryId) : "", itemId: w.itemId ? String(w.itemId) : "", qty: w.qty ?? "", startAt: w.startAt || "" } : null,
    fields: [
      { k: "itemId", l: "Item", type: "select", blank: "— Choose item —", req: 1, opts: () => groupedItems(S.d.items), half: 1 },
      { k: "categoryId", l: "Category", type: "select", blank: "— Choose —", opts: () => catOpts(), half: 1, add: ADD_CATEGORY },
      { k: "hostelId", l: "Hostel", type: "select", blank: "All hostels", opts: () => S.d.hostels.map((h) => [h.id, h.name]), show: () => M.admin, half: 1, val: "" },
      { k: "qty", l: "Quantity covered", type: "number", half: 1 },
      { k: "warrantyNo", l: "Warranty / AMC number", half: 1 },
      { k: "supplier", l: "Supplier / service provider", half: 1 },
      { k: "startAt", l: "Start / purchase date", type: "date", half: 1 },
      { k: "expiryAt", l: "Expiry date", type: "date", req: 1, half: 1 },
      { k: "notes", l: "Notes", type: "textarea", ph: "Contact for claims, terms…" },
    ],
    onChange: (v, k) => { if (k === "itemId" && v.itemId && !v.categoryId) { const it = M.I.get(+v.itemId); if (it && it.categoryId) v.categoryId = String(it.categoryId); } },
    submit: w ? "Save" : "Add warranty",
    onSubmit: async (v) => { await api("warranty.save", { ...v, id: id || null }); return w ? "Warranty updated" : "Warranty added"; },
  });
}

/* ---------- master form ---------- */
function masterForm(key, id) {
  const m = MASTERS[key];
  const rec = id ? m.rows().find((r) => r.id === id) : null;
  const values = rec ? Object.fromEntries(m.fields.map((fd) => [fd.k, fd.k === "password" ? "" : rec[fd.k] == null ? "" : typeof rec[fd.k] === "boolean" ? rec[fd.k] : String(rec[fd.k])])) : null;
  const fields = m.fields.map((fd) => (fd.k === "password" && !rec ? { ...fd, req: 1, help: "They sign in with their email and this password." } : fd));
  openForm({
    title: rec ? `Edit ${m.one}` : `Add ${m.one}`, values, fields, submit: rec ? "Save" : `Add ${m.one}`,
    onSubmit: async (v) => { await api("master.save", { coll: m.coll || key, id: id || null, data: v }); return rec ? "Saved" : `${m.one[0].toUpperCase() + m.one.slice(1)} added`; },
  });
}

/* =================================================================== actions */
const ACT = {
  issue: (el) => issueForm(el.dataset.kind || "transfer"),
  stockin: () => stockForm(),
  csv: (el) => downloadCSV(el.dataset.key),
  ftoggle: (el) => { S.fopen[el.dataset.key] = !S.fopen[el.dataset.key]; render(); },
  fchip: (el) => { fv(el.dataset.key)[el.dataset.k] = ""; S.page[el.dataset.key] = 0; render(); },
  dmgok: (el) => damageDecideForm(+el.dataset.id, true),
  dmgno: (el) => damageDecideForm(+el.dataset.id, false),
  sortdir: (el) => { const st = S.sort[el.dataset.key]; if (st) st.dir = -st.dir; if (el.dataset.key === "hist") drawHist(); else render(); },
  page: (el) => { S.page[el.dataset.key] = +el.dataset.p; render(); window.scrollTo({ top: 0, behavior: "smooth" }); },
  clearf: (el) => { S.f[el.dataset.key] = {}; S.page[el.dataset.key] = 0; render(); },
  ledger: (el) => historyPopup(+el.dataset.i, el.dataset.h ? +el.dataset.h : M.admin ? 0 : M.myH),
  closehist: () => { S.hist = null; closeDlg(); },
  fullledger: () => { const h = S.hist; S.hist = null; closeDlg(); S.ledger = { h: h.hid ? String(h.hid) : "", i: String(h.item) }; S.f.ledger = {}; go("ledger"); },
  invHostel: (el) => { S.f.inventory = { h: el.dataset.id }; go("inventory"); },
  receive: (el) => receiveForm(+el.dataset.id),
  cancel: async (el) => {
    const i = S.d.issues.find((x) => x.id === +el.dataset.id);
    const dmg = i.kind === "damage";
    if (await confirmBox(dmg ? "Cancel this damage report?" : "Cancel this transfer?", dmg ? `${esc(i.no)} will be withdrawn. Stock is unchanged.` : `${esc(i.no)} to ${esc(hName(i.toHostelId))} will be withdrawn. No stock has moved yet.`, dmg ? "Cancel report" : "Cancel transfer", true)) await run(() => api("issue.cancel", { id: i.id }), dmg ? "Damage report cancelled" : "Transfer cancelled");
  },
  delissue: async (el) => {
    const i = S.d.issues.find((x) => x.id === +el.dataset.id);
    const moved = i.kind === "transfer" ? (i.receivedQty ? `${esc(hName(i.toHostelId))} will go down by ${nf(i.receivedQty)} and ${esc(hName(i.hostelId))} back up by ${nf(i.receivedQty)}.` : "No stock has moved for it.")
      : `All its stock entries are removed, so ${esc(hName(i.hostelId))}'s stock goes back to how it was before this was entered.`;
    if (await confirmBox(`Delete ${i.no}?`, `<b>${esc(iName(i.itemId))} × ${nf(i.qty)}</b> (${esc(ISSUE_KIND[i.kind].l.toLowerCase())}). ${moved} This can't be undone.`, "Delete", true)) await run(() => api("issue.delete", { id: i.id }), `${i.no} deleted`);
  },
  return: (el) => returnForm(+el.dataset.id),
  deltxn: async (el) => { if (await confirmBox("Delete this stock entry?", "Use this to correct a wrong opening or received entry.", "Delete", true)) await run(() => api("txn.delete", { id: +el.dataset.id }), "Entry deleted"); },
  warranty: (el) => warrantyForm(el.dataset.id ? +el.dataset.id : null),
  delwarranty: async (el) => { if (await confirmBox("Delete this warranty?", "This can't be undone.", "Delete", true)) await run(() => api("warranty.delete", { id: +el.dataset.id }), "Warranty deleted"); },
  mEdit: (el) => masterForm(el.dataset.m, el.dataset.id ? +el.dataset.id : null),
  mDel: async (el) => {
    const m = MASTERS[el.dataset.m];
    const r = m.rows().find((x) => x.id === +el.dataset.id);
    if (await confirmBox(`Delete ${m.one}?`, `<b>${esc(r.name)}</b> will be removed.`, "Delete", true)) await run(() => api("master.delete", { coll: m.coll || el.dataset.m, id: r.id }), "Deleted");
  },
  testNotify: async () => {
    const out = $("#testOut");
    out.innerHTML = `<div class="help">Sending…</div>`;
    try {
      await saveSettings(true);
      const r = (await api("settings.test", {})).result;
      out.innerHTML = r.map((x) => `<div class="help ${x.ok ? "good" : "warn"}">${esc(x.name)}: ${esc(x.message || (x.ok ? "OK" : "Failed"))}</div>`).join("");
    } catch (e) { out.innerHTML = `<div class="help warn">${esc(e.message)}</div>`; }
  },
  dailyNow: async () => {
    const out = $("#testOut");
    try { await saveSettings(true); const r = await api("daily.run", {}); out.innerHTML = `<div class="help ${r.sent ? "good" : "warn"}">${esc(r.message)}</div>`; }
    catch (e) { out.innerHTML = `<div class="help warn">${esc(e.message)}</div>`; }
  },
  clearToken: async () => { if (await confirmBox("Remove the bot token?", "Telegram messages will stop until you add a token again.", "Remove", true)) await run(() => api("settings.save", { ...settingsVals(), clearToken: 1 }), "Bot token removed"); },
  seed: async () => { if (await confirmBox("Load sample data?", "Adds 4 sample hostels with items, stock, transfers, repairs and warranties. Sample wardens can't sign in until you set a password.", "Load")) await run(() => api("seed"), "Sample data loaded"); },
  logout: async () => { try { await api("logout", {}); } catch (_) {} location.reload(); },
  menu: () => document.body.classList.toggle("drawer"),
};
async function run(fn, msg) {
  try { await fn(); if (msg) toast(msg); await reload(); } catch (e) { toast(e.message || "Something went wrong.", true); }
}
const settingsVals = () => { const f = $("#setForm"); return { tgOff: Object.keys(S.d.settings.tgEvents || {}).filter((k) => !f["ev_" + k].checked), dailyTime: f.dailyTime.value, telegramOn: f.telegramOn.checked, telegramChat: f.telegramChat.value, telegramToken: f.telegramToken.value, appUrl: f.appUrl.value }; };
async function saveSettings(quiet) { await api("settings.save", settingsVals()); if (!quiet) { toast("Settings saved"); await reload(); } }

/* =================================================================== shell */
const NAV = [
  { g: "Overview" }, { v: "home", l: "Dashboard", i: "home" }, { v: "inventory", l: "Inventory", i: "box" },
  { g: "Operations" }, { v: "stock", l: "Stock in", i: "in" }, { v: "issues", l: "Issue register", i: "send" },
  { v: "damaged", l: "All Damaged", i: "alert" }, { v: "transfers", l: "All Transfers", i: "move" }, { v: "allrepairs", l: "All Repairs", i: "wrench" },
  { g: "Approvals Pending" }, { v: "transfer", l: "Transfer", i: "move", n: () => M.incoming.length, hot: 1 },
  { v: "damage", l: "Damage", i: "alert", n: () => M.dmgPending.length, hot: 1 },
  { v: "repairs", l: "Repair", i: "wrench", n: () => M.openRepairs.length },
  { g: "Records" }, { v: "warranties", l: "Warranties", i: "shield", n: () => M.exp30, hot: 1 },
  { g: "Masters", admin: 1 }, { v: "hostels", l: "Hostels", i: "building", admin: 1 }, { v: "items", l: "Items", i: "tag", admin: 1 }, { v: "categories", l: "Categories", i: "folder", admin: 1 }, { v: "units", l: "Units", i: "box", admin: 1 },
  { v: "places", l: "Damage destinations", i: "trash", admin: 1 }, { v: "users", l: "Users", i: "users", admin: 1 }, { v: "settings", l: "Settings", i: "settings", admin: 1 },
];
function renderShell() {
  // Each menu section sits on its own soft colour block.
  const SEC_TONE = { Overview: "blue", Operations: "teal", "Approvals Pending": "amber", Records: "violet", Masters: "slate" };
  let html = "", open = false;
  for (const n of NAV.filter((x) => !x.admin || M.admin)) {
    if (n.g) {
      if (open) html += "</div>";
      html += `<div class="sec sec-${SEC_TONE[n.g] || "slate"}"><h6>${esc(n.g)}</h6>`;
      open = true;
      continue;
    }
    const c = n.n ? n.n() : 0;
    html += `<a href="#${n.v}" class="${S.view === n.v ? "on" : ""}">${ic(n.i)}${esc(n.l)}${c ? `<span class="n${n.hot ? " hot" : ""}">${nf(c)}</span>` : ""}</a>`;
  }
  $("#nav").innerHTML = html + (open ? "</div>" : "");
  $("#meBox").innerHTML = `<span class="av">${esc(initials(S.me.name))}</span><div class="t"><b>${esc(S.me.name)}</b><span>${esc(S.me.role)}${M.admin ? "" : " · " + esc(hName(M.myH))}</span></div><button class="iconbtn" title="Log out" data-act="logout">${ic("logout")}</button>`;
  $("#orgLine").textContent = M.admin ? `${S.d.hostels.length} hostels` : hName(M.myH);
  const inc = M.incoming.length;
  const b = (v, l, i) => `<a href="#${v}" class="${S.view === v ? "on" : ""}">${ic(i)}${l}${v === "transfer" && inc ? `<span class="dot">${inc}</span>` : ""}</a>`;
  $("#bnav").innerHTML = b("home", "Home", "home") + b("inventory", "Inventory", "box") + `<a class="mid" data-act="issue"><span class="c">${ic("plus")}</span>Issue</a>` + b("transfer", "Approvals", "check") + `<a data-act="menu">${ic("menu")}More</a>`;
  const nav = NAV.find((n) => n.v === S.view);
  $("#topTitle").textContent = M.admin ? "Hostel Assets" : hName(M.myH);
}
function render() {
  if (!S.d) return;
  renderShell();
  const act = document.activeElement;
  const keep = act && act.dataset && act.dataset.f ? { k: act.dataset.fkey, f: act.dataset.f, pos: act.selectionStart } : null;
  const fn = V[S.view] || V.home;
  $("#view").innerHTML = fn();
  if (keep) {
    const el = document.querySelector(`[data-fkey="${keep.k}"][data-f="${keep.f}"]`);
    if (el) { el.focus(); try { el.setSelectionRange(keep.pos, keep.pos); } catch (_) {} }
  }
}
function go(v) { if (location.hash.slice(1) === v) { S.view = v; render(); } else location.hash = v; }
function route() {
  let h = location.hash.slice(1) || "home";
  const legacy = { ledger: "inventory", approvals: "transfer", "approvals?in": "transfer", "approvals?out": "transfer", "approvals?all": "transfers", receive: "transfer", sent: "transfer" };
  if (legacy[h]) { history.replaceState(null, "", "#" + legacy[h]); h = legacy[h]; }
  const [v, arg] = h.split("?");
  const nav = NAV.find((n) => n.v === v);
  S.view = nav && (!nav.admin || M.admin) ? v : "home";
  if (arg && (S.view === "repairs" || S.view === "allrepairs")) S.f[S.view] = { st: arg };
  if (arg && S.view === "warranties") S.f.warranties = { st: arg };
  if (arg) history.replaceState(null, "", "#" + S.view);
  document.body.classList.remove("drawer");
  render();
  window.scrollTo(0, 0);
}
async function reload() { S.d = await api("data"); S.me = S.d.me; derive(); render(); }

/* ---------- events ---------- */
document.addEventListener("click", (e) => {
  const a = e.target.closest("[data-act]");
  if (a) { e.preventDefault(); const fn = ACT[a.dataset.act]; if (fn) fn(a); return; }
  const th = e.target.closest("th[data-sort]");
  if (th) { const k = th.dataset.sort, i = +th.dataset.i; const s = S.sort[k]; S.sort[k] = !s || s.i !== i ? { i, dir: 1 } : s.dir > 0 ? { i, dir: -1 } : null; S.page[k] = 0; if (k === "hist") drawHist(); else render(); return; }
  const g = e.target.closest("[data-go]");
  if (g) { go(g.dataset.go); return; }
  if (e.target.id === "scrim") document.body.classList.remove("drawer");
});
let ft;
document.addEventListener("input", (e) => {
  const el = e.target;
  if (el.dataset.hist === "q" && S.hist) { clearTimeout(ft); ft = setTimeout(() => { S.hist.f.q = el.value; drawHist(); }, 180); return; }
  if (el.dataset.f) {
    clearTimeout(ft);
    const apply = () => { fv(el.dataset.fkey)[el.dataset.f] = el.value; S.page[el.dataset.fkey] = 0; render(); };
    if (el.tagName === "INPUT" && el.type !== "date") ft = setTimeout(apply, 180); else apply();
  }
});
document.addEventListener("change", (e) => {
  const el = e.target;
  if (el.dataset.led) { S.ledger[el.dataset.led] = el.value; render(); }
  else if (el.dataset.sortsel) { const k = el.dataset.sortsel; S.sort[k] = el.value === "" ? null : { i: +el.value, dir: (S.sort[k] && S.sort[k].dir) || 1 }; S.page[k] = 0; if (k === "hist") drawHist(); else render(); }
  else if (el.dataset.hist && S.hist && (el.tagName === "SELECT" || el.type === "date")) { const k = el.dataset.hist; if (k === "hid") S.hist.hid = el.value ? +el.value : 0; else S.hist.f[k] = el.value; drawHist(); }
  else if (el.dataset.f && (el.tagName === "SELECT" || el.type === "date")) { fv(el.dataset.fkey)[el.dataset.f] = el.value; S.page[el.dataset.fkey] = 0; render(); }
});
document.addEventListener("submit", async (e) => {
  if (e.target.id === "setForm") { e.preventDefault(); try { await saveSettings(); } catch (ex) { $("#setErr").innerHTML = `${ic("alert")}<span>${esc(ex.message)}</span>`; $("#setErr").scrollIntoView({ block: "nearest" }); } }
});
// Enter never saves a form (dialogs, Settings); only the Save button does. Enter still adds a new line in notes boxes.
document.addEventListener("keydown", (e) => {
  if (e.key !== "Enter" || e.isComposing) return;
  const t = e.target;
  if (!t.closest || !t.closest(".dlg form, #setForm")) return;
  if (t.tagName === "TEXTAREA" || t.tagName === "BUTTON") return;
  e.preventDefault();
}, true);
document.addEventListener("keydown", (e) => { if (e.key === "Escape" && document.querySelector(".dlg.mini")) { document.querySelector(".dlg.mini").remove(); return; } if (e.key === "Escape") { S.hist = null; closeDlg(); document.body.classList.remove("drawer"); } });
// Desktop: hide or show the left menu (remembered in this browser). Phones: open the slide-out menu.
const desktop = () => window.innerWidth > 900;
const setNavHidden = (hide) => { document.body.classList.toggle("navhidden", hide); try { localStorage.setItem("ham_navhidden", hide ? "1" : "0"); } catch (_) {} };
try { if (localStorage.getItem("ham_navhidden") === "1") document.body.classList.add("navhidden"); } catch (_) {}
$("#menuBtn").addEventListener("click", () => (desktop() ? setNavHidden(false) : document.body.classList.toggle("drawer")));
$("#hideNav").addEventListener("click", () => setNavHidden(true));
window.addEventListener("hashchange", route);

/* ---------- searchable dropdowns ----------
   Every <select> in the app gets a button that opens a list with a search box. The real <select>
   stays (hidden) and keeps the value, so forms and filters work as before: picking an option sets it
   and fires the usual input/change events. */
let SSO = null;
function closeSS() {
  if (!SSO) return;
  SSO.pop.remove();
  document.removeEventListener("mousedown", SSO.outside, true);
  window.removeEventListener("scroll", SSO.scrolled, true);
  window.removeEventListener("resize", closeSS);
  SSO = null;
}
const hl = (t, q) => { if (!q) return esc(t); const i = t.toLowerCase().indexOf(q); return i < 0 ? esc(t) : esc(t.slice(0, i)) + "<b>" + esc(t.slice(i, i + q.length)) + "</b>" + esc(t.slice(i + q.length)); };
function openSS(sel, btn, initial = "") {
  closeSS();
  const pop = document.createElement("div");
  pop.className = "ss-pop";
  pop.innerHTML = `<div class="ss-s">${ic("search")}<input placeholder="Search…" autocomplete="off" aria-label="Search options"></div><div class="ss-l" role="listbox"></div>`;
  document.body.appendChild(pop);
  const inp = $("input", pop), list = $(".ss-l", pop);
  const opts = [...sel.options].map((o, i) => ({ i, t: o.text }));
  let shown = [], act = 0;
  const draw = () => {
    const q = inp.value.trim().toLowerCase();
    // Matches that start with what was typed come first, then matches of a word start, then the rest.
    const rank = (t) => { t = t.toLowerCase(); return t.startsWith(q) ? 0 : (" " + t).includes(" " + q) ? 1 : 2; };
    shown = !q ? opts : opts.filter((o) => o.t.toLowerCase().includes(q)).sort((a, b) => rank(a.t) - rank(b.t) || a.i - b.i);
    act = Math.min(Math.max(act, 0), Math.max(shown.length - 1, 0));
    list.innerHTML = shown.length
      ? shown.map((o, k) => `<div class="ss-o${k === act ? " act" : ""}${o.i === sel.selectedIndex ? " sel" : ""}" data-k="${k}" role="option">${hl(o.t, q)}</div>`).join("")
      : `<div class="ss-none">No matches</div>`;
    const a = $(".act", list);
    if (a) a.scrollIntoView({ block: "nearest" });
  };
  const place = () => {
    const r = btn.getBoundingClientRect();
    const w = Math.max(r.width, 230);
    pop.style.width = w + "px";
    pop.style.left = Math.max(8, Math.min(r.left, innerWidth - w - 8)) + "px";
    const h = pop.offsetHeight, below = innerHeight - r.bottom;
    if (below < h + 8 && r.top > below) { pop.style.top = ""; pop.style.bottom = innerHeight - r.top + 4 + "px"; }
    else { pop.style.bottom = ""; pop.style.top = r.bottom + 4 + "px"; }
  };
  const pick = (o) => {
    if (!o) return;
    closeSS();
    if (sel.selectedIndex !== o.i) {
      sel.selectedIndex = o.i;
      sel.dispatchEvent(new Event("input", { bubbles: true }));
      sel.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (btn.isConnected) btn.focus();
  };
  inp.addEventListener("input", () => { act = 0; draw(); });
  inp.addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown") { e.preventDefault(); act++; draw(); }
    else if (e.key === "ArrowUp") { e.preventDefault(); act--; draw(); }
    else if (e.key === "Enter") { e.preventDefault(); e.stopPropagation(); pick(shown[act]); }
    else if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); closeSS(); btn.focus(); }
    else if (e.key === "Tab") closeSS();
  });
  list.addEventListener("mousedown", (e) => e.preventDefault());
  list.addEventListener("click", (e) => { const o = e.target.closest(".ss-o"); if (o) pick(shown[+o.dataset.k]); });
  const outside = (e) => { if (!pop.contains(e.target) && !btn.contains(e.target)) closeSS(); };
  const scrolled = (e) => { if (!pop.contains(e.target)) closeSS(); };
  SSO = { pop, outside, scrolled };
  document.addEventListener("mousedown", outside, true);
  window.addEventListener("scroll", scrolled, true);
  window.addEventListener("resize", closeSS);
  inp.value = initial;
  act = Math.max(0, initial ? 0 : opts.findIndex((o) => o.i === sel.selectedIndex));
  draw(); place();
  inp.focus();
}
function enhanceSelect(sel) {
  if (sel.dataset.ss) return;
  sel.dataset.ss = "1";
  const wrap = document.createElement("div");
  wrap.className = "ss";
  sel.parentNode.insertBefore(wrap, sel);
  wrap.appendChild(sel);
  sel.classList.add("ss-native");
  sel.tabIndex = -1;
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "ss-btn";
  btn.setAttribute("aria-haspopup", "listbox");
  const lab = sel.getAttribute("aria-label") || (sel.id && document.querySelector(`label[for="${sel.id}"]`)?.textContent.replace("*", "").trim());
  if (lab) btn.setAttribute("aria-label", lab);
  wrap.appendChild(btn);
  const sync = () => { const o = sel.options[sel.selectedIndex]; btn.innerHTML = `<span class="ss-v${!o || o.value === "" ? " ss-ph" : ""}">${esc(o ? o.text : "")}</span>${ic("chev")}`; btn.disabled = sel.disabled; };
  sync();
  sel.addEventListener("change", sync);
  btn.addEventListener("click", () => (SSO ? closeSS() : openSS(sel, btn)));
  btn.addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") { e.preventDefault(); openSS(sel, btn); }
    else if (e.key.length === 1 && e.key !== " " && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); openSS(sel, btn, e.key); }
  });
  if (sel.id) { const l = document.querySelector(`label[for="${sel.id}"]`); if (l) l.addEventListener("click", (e) => { e.preventDefault(); btn.focus(); }); }
}
const enhanceAll = (root = document) => root.querySelectorAll("select:not([data-ss])").forEach(enhanceSelect);
new MutationObserver(() => enhanceAll()).observe(document.body, { childList: true, subtree: true });
enhanceAll();

/* ---------- toast ---------- */
function toast(t, bad) {
  document.querySelectorAll(".toast").forEach((x) => x.remove());
  const el = document.createElement("div");
  el.className = "toast" + (bad ? " bad" : "");
  el.textContent = t;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3200);
}

/* =================================================================== sign in */
let authMode = "login";
function showAuth(mode) {
  authMode = mode;
  $("#app").hidden = true; $("#boot").hidden = true;
  let a = $("#auth");
  if (!a) { a = document.createElement("div"); a.id = "auth"; a.className = "auth"; document.body.appendChild(a); }
  const setup = mode === "setup";
  a.innerHTML = `<form novalidate><div class="band">${setup ? "Create the admin account" : "Hostel Asset Management"}</div><div class="body">
    ${setup ? `<p class="lead">First-time setup. This account manages hostels, items and users.</p><div class="fld"><label>Your name</label><div class="iw"><input name="name" autocomplete="name"></div></div>` : ""}
    <div class="fld"><label>Email</label><div class="iw">${ic("mail")}<input name="email" type="email" autocomplete="username" placeholder="name@hostel.com"></div></div>
    <div class="fld"><label>Password</label><div class="iw">${ic("lock")}<input name="password" type="password" autocomplete="${setup ? "new-password" : "current-password"}" placeholder="${setup ? "At least 8 characters" : "••••••••"}"><button type="button" class="eye" aria-label="Show password">${ic("eye")}</button></div></div>
    <div class="err"></div><button class="btn pri" type="submit">${setup ? "Create account" : "Sign in"}</button></div></form>`;
  a.hidden = false;
  const f = $("form", a);
  setTimeout(() => f.elements[setup ? "name" : "email"].focus(), 30);
  $(".eye", f).addEventListener("click", (e) => { const pw = f.password, show = pw.type === "password"; pw.type = show ? "text" : "password"; e.currentTarget.innerHTML = ic(show ? "eyeoff" : "eye"); e.currentTarget.setAttribute("aria-label", show ? "Hide password" : "Show password"); });
  f.addEventListener("submit", async (e) => {
    e.preventDefault();
    const btn = $("button[type=submit]", f), err = $(".err", f);
    btn.disabled = true; err.textContent = "";
    try {
      const body = { email: f.email.value.trim(), password: f.password.value };
      if (setup) body.name = f.name.value.trim();
      await api(setup ? "setup" : "login", body);
      a.hidden = true;
      await start();
    } catch (ex) { err.innerHTML = `${ic("alert")}<span>${esc(ex.message || "Couldn't sign in.")}</span>`; }
    finally { btn.disabled = false; }
  });
}
let pollT;
async function start() {
  try {
    const s = await api("status");
    if (!s.user) return showAuth(s.needsSetup ? "setup" : "login");
    await reload();
    $("#boot").hidden = true; $("#app").hidden = false;
    route();
    clearInterval(pollT);
    pollT = setInterval(() => { if (document.visibilityState === "visible" && !document.querySelector(".dlg")) reload().catch(() => {}); }, POLL_MS);
  } catch (e) {
    $("#boot").innerHTML = `<div style="text-align:center;max-width:420px;padding:20px"><h2>Can't load the app</h2><p>${esc(e.message || "")}</p><button class="btn pri" onclick="location.reload()">Try again</button></div>`;
  }
}
document.addEventListener("visibilitychange", () => { if (document.visibilityState === "visible" && S.d && !document.querySelector(".dlg")) reload().catch(() => {}); });
start();
})();
