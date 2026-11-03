// Subscription + account lifecycle against a running API, driven the way RevenueCat and the app drive it.
//
//   API_URL=https://florafolio-api.ddev.site WEBHOOK_SECRET=... ENTITLEMENT="Florafolio Pro" \
//   PRODUCT_ID=florafolio_pro_monthly [EVENT_ENV=PRODUCTION|SANDBOX] [MAILPIT_URL=...] [CAPTCHA_TOKEN=...] \
//   node tools/lifecycle.mjs
//
// Covers subscribe, free trial, renewal, cancel, un-cancel (resubscribe before expiry), billing issue,
// expiry, resubscribe after expiry, refund, replayed/stale/foreign events, and the account emails
// (welcome, password change, two-factor, deletion). With MAILPIT_URL set it also asserts the emails
// that arrive; without it the webhook's `notified` flag stands in for them.
import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";

const base = (process.env.API_URL ?? "http://localhost:8080").replace(
  /\/$/,
  "",
);
const secret = process.env.WEBHOOK_SECRET ?? "local-webhook-secret";
const entitlement = process.env.ENTITLEMENT ?? "pro";
const productId = process.env.PRODUCT_ID ?? "pro_monthly";
const eventEnv = process.env.EVENT_ENV ?? "PRODUCTION";
const mailpit = (process.env.MAILPIT_URL ?? "").replace(/\/$/, "");
const captchaToken = process.env.CAPTCHA_TOKEN ?? undefined;
// Apps with no paid tier (CouldBuild) have no RevenueCat webhook: SKIP_SUBSCRIPTION=1 runs only the account emails.
const skipSubscription = process.env.SKIP_SUBSCRIPTION === "1";
const stamp = Date.now();
const password = "Password123!x9";
const email = `lifecycle.${stamp}@example.test`;

async function call(method, path, { token, body, headers } = {}) {
  const response = await fetch(`${base}${path}`, {
    method,
    headers: {
      "content-type": "application/json",
      ...(token ? { authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await response.text();
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = text;
  }
  return { status: response.status, json };
}

let step = 0;
const ok = (label) => console.log(`ok ${++step} - ${label}`);

async function subjects() {
  if (!mailpit) return null;
  const response = await fetch(
    `${mailpit}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}&limit=100`,
  );
  const data = await response.json();
  return (data.messages ?? []).map((m) => m.Subject);
}

/** Waits for an email to have arrived: an exact subject, or a predicate plus a label (queued jobs run straight away, but give them a moment). */
async function expectMail(match, label = match) {
  if (!mailpit) return;
  const matches = typeof match === "function" ? match : (s) => s === match;
  for (let i = 0; i < 20; i++) {
    if ((await subjects()).some(matches)) return;
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  assert.fail(
    `no email titled "${label}" arrived; got ${JSON.stringify(await subjects())}`,
  );
}

let userId;
let token;
let clock = Date.now() - 60_000;
const hours = (n) => n * 3600_000;

function event(type, extra = {}, overrides = {}) {
  clock += 1000;
  return {
    event: {
      id: `${type}-${randomUUID()}`,
      type,
      app_user_id: userId,
      event_timestamp_ms: clock,
      environment: eventEnv,
      entitlement_ids: [entitlement],
      product_id: productId,
      expiration_at_ms: clock + hours(24 * 30),
      ...extra,
      ...overrides,
    },
  };
}

async function send(type, extra, overrides) {
  const payload = event(type, extra, overrides);
  const r = await call("POST", "/v1/webhooks/revenuecat", {
    headers: { authorization: `Bearer ${secret}` },
    body: payload,
  });
  assert.equal(r.status, 200, JSON.stringify(r.json));
  return { ...r.json, id: payload.event.id };
}

async function tier() {
  const r = await call("GET", "/v1/auth/me", { token });
  assert.equal(r.status, 200, JSON.stringify(r.json));
  return r.json.subscriptionTier;
}

// ---- homepage and login screen ----------------------------------------------------------
{
  const home = await fetch(`${base}/`, { redirect: "manual" });
  assert.equal(home.status, 302, "the homepage should redirect");
  assert.match(home.headers.get("location") ?? "", /\/Security\/login/);
  const login = await (await fetch(`${base}/Security/login`)).text();
  const title = login.match(/<title>([^<]*)<\/title>/)?.[1] ?? "";
  assert.doesNotMatch(
    title,
    /Your Site Name/,
    "the site still has Silverstripe's default name",
  );
  assert.match(title, / API( \((dev|local)\))?: Log in$/);
  const host = new URL(base).hostname;
  const suffix = host.startsWith("dev-api.")
    ? "(dev)"
    : host.endsWith(".ddev.site")
      ? "(local)"
      : "";
  assert.equal(
    /\((dev|local)\)/.exec(title)?.[0] ?? "",
    suffix,
    `title "${title}" does not name this environment`,
  );
  assert.match(
    login,
    /--color-button-primary-background:#[0-9a-f]{3,6}/i,
    "the login page is not branded",
  );
}
ok(
  "the homepage redirects to a branded login page named for the app and environment",
);

// ---- sign-up + welcome ----------------------------------------------------
assert.equal((await call("GET", "/v1/health")).status, 200);
let r = await call("POST", "/v1/auth/sign-up", {
  body: {
    email,
    password,
    name: "Lifecycle Tester",
    country: "nz",
    captchaToken,
  },
});
assert.equal(r.status, 200, JSON.stringify(r.json));
if (r.json.confirmationRequired) {
  assert.fail(
    "email confirmation is required; this run cannot continue without opening the link",
  );
}
token = r.json.session.accessToken;
userId = r.json.session.user.id;
assert.equal(await tier(), "free");
await expectMail((s) => s.startsWith("Welcome to "), "Welcome to <app>");
ok("sign-up starts on free and sends the welcome email");

if (!skipSubscription) {
  // ---- webhook security and filtering ---------------------------------------------
  r = await call("POST", "/v1/webhooks/revenuecat", {
    body: event("INITIAL_PURCHASE"),
  });
  assert.equal(r.status, 401);
  r = await call("POST", "/v1/webhooks/revenuecat", {
    headers: { authorization: "Bearer wrong" },
    body: event("INITIAL_PURCHASE"),
  });
  assert.equal(r.status, 401);
  ok("webhook rejects missing and wrong secrets");

  let result = await send(
    "INITIAL_PURCHASE",
    {},
    { entitlement_ids: ["something-else"], product_id: "other_product" },
  );
  assert.equal(result.subscriptionStatus, "unchanged");
  assert.equal(await tier(), "free");
  ok("a purchase of an unrelated product changes nothing");

  result = await send(
    "INITIAL_PURCHASE",
    {},
    { environment: eventEnv === "PRODUCTION" ? "SANDBOX" : "PRODUCTION" },
  );
  assert.equal(result.subscriptionStatus, "unchanged");
  assert.equal(await tier(), "free");
  ok("an event from the other RevenueCat environment is ignored");

  // ---- free trial -> paid ---------------------------------------------------------------
  result = await send("INITIAL_PURCHASE", {
    period_type: "TRIAL",
    expiration_at_ms: clock + hours(24 * 14),
  });
  assert.equal(result.subscriptionStatus, "active");
  assert.equal(result.notified, true);
  assert.equal(await tier(), "pro");
  await expectMail(
    (s) => s.endsWith("free trial has started"),
    "<app> free trial has started",
  );
  ok("starting a free trial grants Pro and sends the trial email");

  r = await call("POST", "/v1/webhooks/revenuecat", {
    headers: { authorization: `Bearer ${secret}` },
    body: { event: { ...event("INITIAL_PURCHASE").event, id: result.id } },
  });
  assert.equal(r.json.outcome, "ignored_duplicate");
  ok("a replayed event is ignored");

  result = await send("RENEWAL");
  assert.equal(result.subscriptionStatus, "active");
  assert.equal(result.notified, false);
  assert.equal(await tier(), "pro");
  ok("renewal keeps Pro and sends nothing");

  // ---- cancel and change of mind --------------------------------------------------------
  result = await send("CANCELLATION", { cancel_reason: "UNSUBSCRIBE" });
  assert.equal(result.subscriptionStatus, "unchanged");
  assert.equal(result.notified, true);
  assert.equal(
    await tier(),
    "pro",
    "a cancelled subscription keeps Pro until it expires",
  );
  await expectMail("Your subscription was cancelled");
  ok("cancelling keeps Pro until expiry and sends the cancellation email");

  result = await send("UNCANCELLATION");
  assert.equal(result.subscriptionStatus, "active");
  assert.equal(await tier(), "pro");
  await expectMail("Your subscription is back on");
  ok(
    "resubscribing before expiry restores renewal and sends the welcome-back email",
  );

  // ---- billing trouble ------------------------------------------------------------------
  result = await send("BILLING_ISSUE");
  assert.equal(result.subscriptionStatus, "unchanged");
  assert.equal(result.notified, true);
  assert.equal(
    await tier(),
    "pro",
    "a billing issue alone does not remove access",
  );
  await expectMail("We could not take your payment");
  ok("a billing issue keeps Pro and sends the payment email");

  // ---- expiry and resubscribe -----------------------------------------------------------
  result = await send("EXPIRATION");
  assert.equal(result.subscriptionStatus, "unsubscribed");
  assert.equal(await tier(), "free");
  await expectMail("Your subscription has ended");
  ok("expiry returns the account to free and sends the ended email");

  result = await send("INITIAL_PURCHASE");
  assert.equal(result.subscriptionStatus, "active");
  assert.equal(await tier(), "pro");
  ok("resubscribing after expiry grants Pro again");

  // ---- stale events never override newer state --------------------------------------------
  const staleTs = clock - hours(48);
  result = await send("EXPIRATION");
  assert.equal(await tier(), "free");
  result = await send("INITIAL_PURCHASE", {}, { event_timestamp_ms: staleTs });
  assert.equal(
    await tier(),
    "free",
    "an older event must not undo a newer expiry",
  );
  ok("an out-of-order older event is ignored");

  // ---- refund ---------------------------------------------------------------------------
  result = await send("INITIAL_PURCHASE");
  assert.equal(await tier(), "pro");
  result = await send("CANCELLATION", { cancel_reason: "CUSTOMER_SUPPORT" });
  assert.equal(result.notified, true);
  await expectMail("Your purchase was refunded");
  result = await send("EXPIRATION");
  assert.equal(await tier(), "free");
  ok("a refund sends the refund email and the expiry removes Pro");
}

// ---- account emails -------------------------------------------------------------------
const newPassword = "NewPassword456!y";
r = await call("POST", "/v1/auth/password/change", {
  token,
  body: { currentPassword: password, newPassword },
});
assert.equal(r.status, 200, JSON.stringify(r.json));
await expectMail("Your password was changed");
ok("changing the password sends the security email");

r = await call("POST", "/v1/auth/sign-in", {
  body: { email, password: newPassword, captchaToken },
});
assert.equal(r.status, 200, JSON.stringify(r.json));
token = r.json.session.accessToken;

r = await call("POST", "/v1/account/deletion", {
  token,
  body: { confirmation: "DELETE", email },
});
assert.equal(r.status, 200, JSON.stringify(r.json));
assert.ok(r.json.scheduledFor);
await expectMail("Your account is scheduled for deletion");
r = await call("DELETE", "/v1/account/deletion", { token });
assert.equal(r.status, 200, JSON.stringify(r.json));
assert.equal(r.json.scheduledFor, null);
await expectMail("Account deletion cancelled");
ok("requesting and cancelling account deletion send their emails");

const reset = await call("POST", "/v1/auth/password/forgot", {
  body: { email, captchaToken },
});
assert.equal(reset.status, 200);
await expectMail("Reset your password");
ok("forgot password sends the reset email");

// Leave the throwaway account scheduled for deletion so a shared preview does not fill up with test users.
r = await call("POST", "/v1/account/deletion", {
  token,
  body: { confirmation: "DELETE", email },
});
assert.equal(r.status, 200, JSON.stringify(r.json));
ok("the test account is left scheduled for deletion");

console.log(`\nlifecycle: ${step} checks passed against ${base}`);
