/**
 * ContractAI – SPA Application
 * Hash-based routing: index.html#/login, #/dashboard, etc.
 * Pages object defined BEFORE router so ROUTES map works correctly.
 */
'use strict';

// ════════════════════════════════════════════════════════════
// UTILITIES
// ════════════════════════════════════════════════════════════

const _$ = (sel, ctx) => (ctx || document).querySelector(sel);
const _$$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));

/** HTML-escape for safe output */
function esc(v) {
  return String(v == null ? '' : v)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/** Show toast notification */
function toast(msg, type = 'info', ms = 3500) {
  const stack = document.getElementById('toast-stack');
  if (!stack) return;
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  const icons = { success:'✅', error:'❌', info:'ℹ️', warn:'⚠️' };
  el.innerHTML = `<span>${icons[type]||'ℹ️'}</span><span style="flex:1">${esc(msg)}</span>`;
  stack.appendChild(el);
  setTimeout(() => el.style.opacity = '0', ms - 300);
  setTimeout(() => el.remove(), ms);
}

/** Show full-screen loading overlay */
function showLoading(msg) {
  let el = document.getElementById('cai-loading');
  if (!el) {
    el = document.createElement('div');
    el.id = 'cai-loading'; el.className = 'loading-overlay';
    document.body.appendChild(el);
  }
  el.innerHTML = `<div class="spinner"></div><div class="loading-msg">${esc(msg || 'Loading…')}</div>`;
  el.style.display = 'flex';
}

function hideLoading() {
  const el = document.getElementById('cai-loading');
  if (el) el.style.display = 'none';
}

/** Render a modal dialog */
function showModal(title, bodyHtml, footerHtml, wide = false) {
  let mask = document.getElementById('cai-modal');
  if (!mask) {
    mask = document.createElement('div');
    mask.id = 'cai-modal'; mask.className = 'modal-mask';
    document.body.appendChild(mask);
    mask.addEventListener('click', ev => { if (ev.target === mask) closeModal(); });
  }
  mask.innerHTML = `
    <div class="modal-box${wide ? ' wide' : ''}">
      <div class="modal-hd">
        <h3>${esc(title)}</h3>
        <button class="btn btn-ghost btn-icon" onclick="closeModal()">✕</button>
      </div>
      <div class="modal-body">${bodyHtml}</div>
      ${footerHtml ? `<div class="modal-ft">${footerHtml}</div>` : ''}
    </div>`;
  mask.style.display = 'flex';
  return mask;
}

function closeModal() {
  const el = document.getElementById('cai-modal');
  if (el) el.style.display = 'none';
}

/** Display API validation errors on form fields */
function showErrors(errorsObj) {
  if (!errorsObj) return;
  Object.entries(errorsObj).forEach(([field, msgs]) => {
    const msg = Array.isArray(msgs) ? msgs[0] : msgs;
    // Try matching by name, id, or data-field
    const input = document.querySelector(`[name="${field}"], #${field}, [data-field="${field}"]`);
    if (input) {
      input.classList.add('error');
      // Insert error message after input
      let errEl = input.nextElementSibling;
      if (!errEl || !errEl.classList.contains('form-error')) {
        errEl = document.createElement('div');
        errEl.className = 'form-error';
        input.after(errEl);
      }
      errEl.textContent = msg;
    }
  });
}

/** Clear form errors */
function clearErrors(form) {
  _$$('.form-error', form).forEach(el => el.remove());
  _$$('.error', form).forEach(el => el.classList.remove('error'));
}

/** Simple date formatter */
function fmtDate(dt) {
  if (!dt) return '—';
  return new Date(dt).toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' });
}

/** Render pagination buttons */
function renderPagination(meta, onPageFn) {
  if (!meta || meta.last_page <= 1) return '';
  let html = '<div class="pagination">';
  for (let i = 1; i <= meta.last_page; i++) {
    html += `<button class="pg-btn${i === meta.page ? ' active' : ''}"
      onclick="${onPageFn}(${i})">${i}</button>`;
  }
  return html + '</div>';
}

// ════════════════════════════════════════════════════════════
// SVG ICON LIBRARY
// ════════════════════════════════════════════════════════════

const SVG = {
  grid:     '<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/>',
  file:     '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
  layout:   '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
  users:    '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
  plus:     '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
  edit:     '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
  trash:    '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/><path d="M9 6V4h6v2"/>',
  eye:      '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
  zap:      '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
  logout:   '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
  menu:     '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
  check:    '<polyline points="20 6 9 17 4 12"/>',
  lock:     '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  tag:      '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
  list:     '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
  globe:    '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
  clock:    '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
  save:     '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
  book:     '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
  check2:   '<polyline points="20 6 9 17 4 12"/>',
};

function icon(name, size) {
  const sz = size || 18;
  const path = SVG[name] || '';
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${sz}" height="${sz}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
}

// ════════════════════════════════════════════════════════════
// PAGE RENDERERS  (defined BEFORE ROUTES map)
// ════════════════════════════════════════════════════════════

const Pages = {};

// ── helpers used by page renderers ───────────────────────────
function setTitle(t) {
  const el = document.getElementById('page-title');
  if (el) el.textContent = t;
  document.title = t ? `${t} – ContractAI` : 'ContractAI';
}

function setPage(html) {
  const el = document.getElementById('page-content');
  if (el) el.innerHTML = html;
}

// Global PDF download handler — uses fetch() with Authorization header so
// the JWT is sent correctly. Plain <a href> can't attach headers.
window._downloadPdf = async function(id, lang, btn) {
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
  const title = btn.closest('tr')?.querySelector('td')?.textContent?.trim() || `contract_${id}`;
  const res = await API.contracts.pdfDownload(id, lang, title);
  if (!res.success) toast(res.message || 'PDF generation failed', 'error');
  btn.disabled = false;
  btn.innerHTML = orig;
};

function quotaBar(used, max) {
  const pct = max > 0 ? Math.round(used / max * 100) : 0;
  const cls = pct >= 90 ? 'danger' : pct >= 70 ? 'warn' : '';
  return `
    <div class="quota-bar">
      <div class="quota-fill ${cls}" style="width:${pct}%"></div>
    </div>`;
}

// ── LOGIN ─────────────────────────────────────────────────────
Pages.login = function() {
  document.getElementById('app').innerHTML = `
    <div class="auth-page">
      <div class="auth-card">
        <div class="auth-logo">
          <div class="brand-icon" style="width:40px;height:40px;font-size:18px">C</div>
          <div class="auth-logo-name">ContractAI</div>
        </div>
        <div class="auth-title">Welcome back</div>
        <div class="auth-sub">Sign in to your workspace</div>
        <div id="login-err" style="display:none;font-size:13px;margin-bottom:16px;padding:11px 14px;background:#fef2f2;border-radius:10px;border:1.5px solid #fca5a5;color:#991b1b;font-weight:500"></div>
        <form id="login-form" autocomplete="on">
          <div class="form-group">
            <label for="l-email">Email Address</label>
            <input id="l-email" name="email" type="email" class="form-control" placeholder="you@company.com" required autofocus autocomplete="email">
          </div>
          <div class="form-group" style="margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
              <label style="margin:0">Password</label>
              <a href="#/forgot" style="font-size:12px;color:var(--slate);font-weight:500">Forgot password?</a>
            </div>
            <input id="l-pass" name="password" type="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">Sign In</button>
        </form>
        <div style="text-align:center;margin-top:22px;font-size:13px;color:var(--slate)">
          No account? <a href="#/register" style="color:var(--ink);font-weight:600">Start free trial →</a>
        </div>
      </div>
    </div>`;

  document.getElementById('login-form').addEventListener('submit', async function(ev) {
    ev.preventDefault();
    const btn = this.querySelector('button[type=submit]');
    const errEl = document.getElementById('login-err');
    btn.disabled = true; btn.textContent = 'Signing in…';
    errEl.style.display = 'none';

    const d = await API.auth.login(
      document.getElementById('l-email').value.trim(),
      document.getElementById('l-pass').value
    );

    if (d.success) {
      App.go('dashboard');
    } else {
      errEl.innerHTML = esc(d.message || 'Login failed');
      if (d.message && d.message.includes('verify')) {
        errEl.innerHTML += '<br><span style="font-size:12px;font-weight:400">Check your email for the verification link, or re-register to get a new one.</span>';
      }
      errEl.style.display = 'block';
      btn.disabled = false; btn.textContent = 'Sign In';
    }
  });
};

// ── REGISTERER ──────────────────────────────────────────────────
Pages.register = function() {
  document.getElementById('app').innerHTML = `
    <div class="auth-page">
      <div class="auth-card" style="max-width:480px">
        <div class="auth-logo">
          <div class="brand-icon" style="width:40px;height:40px;font-size:18px">C</div>
          <div class="auth-logo-name">ContractAI</div>
        </div>
        <div class="auth-title">Start free trial</div>
        <div class="auth-sub">14 days free · No credit card required</div>
        <form id="reg-form">
          <div class="form-row">
            <div class="form-group">
              <label>Company / Law Firm Name *</label>
              <input name="company_name" class="form-control" placeholder="Al Noor Legal" required>
            </div>
            <div class="form-group">
              <label>Your Full Name *</label>
              <input name="full_name" class="form-control" placeholder="Ahmed Al-Rashidi" required>
            </div>
          </div>
          <div class="form-group">
            <label>Work Email *</label>
            <input name="email" type="email" class="form-control" placeholder="ahmed@alnoor.ae" required>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Password *</label>
              <input name="password" type="password" class="form-control" placeholder="Min 8 characters" required>
            </div>
            <div class="form-group">
              <label>Confirm Password *</label>
              <input name="password_confirmation" type="password" class="form-control" placeholder="Repeat" required>
            </div>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg" style="margin-top:8px">Create Account</button>
        </form>
        <div style="text-align:center;margin-top:20px;font-size:13px;color:var(--slate)">
          Already have an account? <a href="#/login" style="color:var(--ink);font-weight:600">Sign in →</a>
        </div>
      </div>
    </div>`;

  document.getElementById('reg-form').addEventListener('submit', async function(ev) {
    ev.preventDefault();
    clearErrors(this);
    const btn = this.querySelector('button[type=submit]');
    btn.disabled = true; btn.textContent = 'Creating account…';

    const data = Object.fromEntries(new FormData(this));
    const d = await API.auth.register(data);

    if (d.success) {
      const verifyLink = d.data && d.data.verify_link ? d.data.verify_link : null;
      document.getElementById('app').innerHTML = `
        <div class="auth-page">
          <div class="auth-card" style="text-align:center;max-width:480px">
            <div style="font-size:56px;margin-bottom:16px">✅</div>
            <div class="auth-title">Account Created!</div>
            <p style="color:var(--slate);font-size:13px;margin:12px 0 16px">
              Your workspace is ready. Please verify your email address before logging in.
            </p>
            ${verifyLink ? `
            <div style="background:#fef9c3;border:1px solid #fde047;border-radius:10px;padding:14px 16px;margin-bottom:16px;text-align:left">
              <div style="font-size:12px;font-weight:600;color:#713f12;margin-bottom:8px">
                📧 Development Mode — No email server needed
              </div>
              <div style="font-size:12px;color:#713f12;margin-bottom:8px">
                Click the link below to verify your account instantly:
              </div>
              <a href="${verifyLink}" style="font-size:12px;word-break:break-all;color:#1a3c5e">${verifyLink}</a>
            </div>` : ''}
            <a href="#/login" class="btn btn-primary w-100">Go to Login</a>
          </div>
        </div>`;
    } else {
      if (d.errors) showErrors(d.errors);
      else toast(d.message || 'Registration failed. Check console for details.', 'error');
      btn.disabled = false; btn.textContent = 'Create Account';
    }
  });
};

// ── FORGOT PASSWORD ───────────────────────────────────────────
Pages.forgot = function() {
  document.getElementById('app').innerHTML = `
    <div class="auth-page">
      <div class="auth-card">
        <div class="auth-logo">
          <div class="brand-icon" style="width:44px;height:44px;font-size:22px">C</div>
          <div class="auth-logo-name">ContractAI</div>
        </div>
        <div class="auth-title">Reset password</div>
        <div class="auth-sub">Enter your email to receive a reset link</div>
        <div id="forgot-msg" style="margin-bottom:12px"></div>
        <form id="forgot-form">
          <div class="form-group">
            <label>Email Address</label>
            <input name="email" type="email" class="form-control" required autofocus>
          </div>
          <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
        </form>
        <div style="text-align:center;margin-top:16px;font-size:13px">
          <a href="#/login">← Back to Sign In</a>
        </div>
      </div>
    </div>`;

  document.getElementById('forgot-form').addEventListener('submit', async function(ev) {
    ev.preventDefault();
    const btn = this.querySelector('button[type=submit]');
    btn.disabled = true; btn.textContent = 'Sending…';
    const d = await API.auth.forgot(this.email.value.trim());
    const color = d.success ? '#16a34a' : '#dc2626';
    document.getElementById('forgot-msg').innerHTML =
      `<div style="padding:10px 12px;border-radius:8px;font-size:13px;color:${color};background:${d.success?'#f0fdf4':'#fef2f2'};border:1px solid ${d.success?'#86efac':'#fca5a5'}">${esc(d.message)}</div>`;
    btn.disabled = false; btn.textContent = 'Send Reset Link';
  });
};

// ── RESET PASSWORD ────────────────────────────────────────────
Pages.resetPassword = function(params) {
  const token = (params && params.token) || '';
  document.getElementById('app').innerHTML = `
    <div class="auth-page">
      <div class="auth-card">
        <div class="auth-logo">
          <div class="brand-icon" style="width:44px;height:44px;font-size:22px">C</div>
          <div class="auth-logo-name">ContractAI</div>
        </div>
        <div class="auth-title">Choose new password</div>
        <form id="reset-form">
          <input type="hidden" name="token" value="${esc(token)}">
          <div class="form-group">
            <label>New Password *</label>
            <input name="password" type="password" class="form-control" placeholder="Min 8 characters" required>
          </div>
          <div class="form-group">
            <label>Confirm Password *</label>
            <input name="password_confirmation" type="password" class="form-control" required>
          </div>
          <button type="submit" class="btn btn-primary w-100">Reset Password</button>
        </form>
      </div>
    </div>`;

  document.getElementById('reset-form').addEventListener('submit', async function(ev) {
    ev.preventDefault();
    clearErrors(this);
    const d = await API.auth.reset(Object.fromEntries(new FormData(this)));
    if (d.success) {
      toast('Password reset successfully. Please log in.', 'success');
      setTimeout(() => App.go('login'), 1500);
    } else {
      if (d.errors) showErrors(d.errors);
      else toast(d.message || 'Reset failed', 'error');
    }
  });
};

// ── EMAIL VERIFIED ────────────────────────────────────────────
Pages.verified = function(params) {
  const ok = params && params.status === 'ok';
  document.getElementById('app').innerHTML = `
    <div class="auth-page">
      <div class="auth-card" style="text-align:center">
        <div style="font-size:56px;margin-bottom:16px">${ok ? '✅' : '❌'}</div>
        <div class="auth-title">${ok ? 'Email Verified!' : 'Link Invalid or Expired'}</div>
        <p style="color:var(--slate);font-size:13px;margin:12px 0 20px">
          ${ok ? 'Your account is active. You can now sign in.' : 'This verification link has expired or already been used.'}
        </p>
        <a href="#/login" class="btn btn-primary">Go to Login</a>
      </div>
    </div>`;
};

// ── ACCEPT INVITE ─────────────────────────────────────────────
Pages.acceptInvite = function(params) {
  const token = (params && params.token) || '';
  document.getElementById('app').innerHTML = `
    <div class="auth-page">
      <div class="auth-card">
        <div class="auth-logo">
          <div class="brand-icon" style="width:44px;height:44px;font-size:22px">C</div>
          <div class="auth-logo-name">ContractAI</div>
        </div>
        <div class="auth-title">Accept Invitation</div>
        <div class="auth-sub">Create your account to join the workspace</div>
        <form id="invite-form">
          <input type="hidden" name="token" value="${esc(token)}">
          <div class="form-group">
            <label>Full Name *</label>
            <input name="full_name" class="form-control" required autofocus>
          </div>
          <div class="form-group">
            <label>Password *</label>
            <input name="password" type="password" class="form-control" placeholder="Min 8 characters" required>
          </div>
          <div class="form-group">
            <label>Confirm Password *</label>
            <input name="password_confirmation" type="password" class="form-control" required>
          </div>
          <button type="submit" class="btn btn-primary w-100 btn-lg">Accept &amp; Create Account</button>
        </form>
      </div>
    </div>`;

  document.getElementById('invite-form').addEventListener('submit', async function(ev) {
    ev.preventDefault();
    clearErrors(this);
    const d = await API.users.acceptInvite(Object.fromEntries(new FormData(this)));
    if (d.success) {
      toast('Account created! Please log in.', 'success');
      setTimeout(() => App.go('login'), 1500);
    } else {
      if (d.errors) showErrors(d.errors);
      else toast(d.message || 'Failed', 'error');
    }
  });
};

// ── DASHBOARD ─────────────────────────────────────────────────
Pages.dashboard = async function() {
  setTitle('Dashboard'); showLoading();
  const d = await API.users.dashboard();
  hideLoading();
  if (!d.success) { setPage(`<p class="text-muted">Failed to load dashboard: ${esc(d.message)}</p>`); return; }

  const { stats, sub, recent } = d.data;

  setPage(`
    <div class="page-header">
      <div class="page-header-left">
        <h2>Dashboard</h2>
        <p>Overview of your workspace activity</p>
      </div>
      <button class="btn btn-gold" onclick="App.go('contracts/new')">${icon('zap',15)} Generate Contract</button>
    </div>

    <div class="stats-grid">
      ${[
        { v: stats.total_contracts,      l: 'Total Contracts',  i: 'file',   c: 'navy'  },
        { v: stats.draft_contracts,      l: 'In Draft',         i: 'edit',   c: 'gold'  },
        { v: stats.final_contracts,      l: 'Finalised',        i: 'check',  c: 'green' },
        { v: stats.total_counterparties, l: 'Counterparties',   i: 'users',  c: 'blue'  },
      ].map(s => `
        <div class="stat-card">
          <div class="stat-icon ${s.c}">${icon(s.i, 22)}</div>
          <div>
            <div class="stat-val">${s.v}</div>
            <div class="stat-lbl">${s.l}</div>
          </div>
        </div>`).join('')}
    </div>

    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
      <div class="card" style="flex:1;min-width:280px">
        <div class="card-hd">
          Recent Contracts
          <a href="#/contracts" style="font-size:12px;font-weight:500;color:var(--slate)">View all →</a>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Title</th><th>Status</th><th>Date</th><th></th></tr></thead>
            <tbody>
              ${!recent || !recent.length
                ? `<tr><td colspan="4"><div class="empty">
                    <div class="empty-icon">📄</div>
                    <h3>No contracts yet</h3>
                    <p>Generate your first AI-drafted contract in seconds</p>
                    <button class="btn btn-primary btn-sm" onclick="App.go('contracts/new')">${icon('zap',14)} Get Started</button>
                   </div></td></tr>`
                : recent.map(c => `
                  <tr style="cursor:pointer" onclick="App.go('contract',{id:${c.id}})">
                    <td class="fw-600">${esc(c.title)}</td>
                    <td><span class="badge badge-${esc(c.status)}">${esc(c.status)}</span></td>
                    <td class="text-muted">${fmtDate(c.created_at)}</td>
                    <td>${icon('eye', 14)}</td>
                  </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>

      <div style="width:260px;min-width:230px;display:flex;flex-direction:column;gap:14px">
        <div class="card">
          <div class="card-hd">Plan Usage</div>
          <div class="card-body">
            ${sub ? `
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                <span style="font-weight:700;font-size:14px">${esc(sub.plan_name)}</span>
                <span class="badge badge-${esc(sub.status)}">${esc(sub.status)}</span>
              </div>
              <div style="margin-bottom:13px">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px">
                  <span style="color:var(--slate)">Contracts</span>
                  <span style="font-weight:600">${sub.contracts_used} / ${sub.max_contracts}</span>
                </div>
                ${quotaBar(sub.contracts_used, sub.max_contracts)}
              </div>
              <div>
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px">
                  <span style="color:var(--slate)">AI Calls</span>
                  <span style="font-weight:600">${sub.ai_calls_used} / ${sub.max_ai_calls}</span>
                </div>
                ${quotaBar(sub.ai_calls_used, sub.max_ai_calls)}
              </div>` : '<p class="text-muted" style="font-size:13px">No subscription found</p>'}
          </div>
        </div>

        <div class="card">
          <div class="card-hd">Quick Actions</div>
          <div style="padding:8px">
            ${[
              { l:'Generate Contract',  r:'contracts/new',  i:'zap'    },
              { l:'New Template',       r:'templates',      i:'layout' },
              { l:'Add Counterparty',   r:'counterparties', i:'users'  },
              { l:'Invite Team Member', r:'team',           i:'users'  },
            ].map(a => `
              <div onclick="App.go('${a.r}')"
                style="display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;color:var(--ink-muted);transition:all .15s"
                onmouseover="this.style.background='var(--surface)';this.style.color='var(--ink)'"
                onmouseout="this.style.background='';this.style.color='var(--ink-muted)'">
                <span style="color:var(--slate)">${icon(a.i, 15)}</span>${a.l}
              </div>`).join('')}
          </div>
        </div>
      </div>
    </div>`);
};

// ── CONTRACTS LISTST ────────────────────────────────────────────
Pages.contracts = async function(params) {
  params = params || {};
  setTitle('Contracts'); showLoading();
  const status = params.status || '';
  const d = await API.contracts.list({ status, page: params.page || 1 });
  hideLoading();
  if (!d.success) { setPage('<p class="text-muted">Failed to load contracts.</p>'); return; }

  const rows = d.data.data || [];
  const meta = d.data.pagination;

  setPage(`
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
      <div style="display:flex;gap:6px">
        ${['','draft','final'].map(s => `
          <button class="btn btn-sm ${status===s ? 'btn-primary' : 'btn-outline'}"
            onclick="App.go('contracts',{status:'${s}'})">
            ${s || 'All'}
          </button>`).join('')}
      </div>
      <button class="btn btn-gold" onclick="App.go('contracts/new')">
        ${icon('zap',16)} Generate with AI
      </button>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Title</th><th>Status</th><th>Tone</th><th>Counterparty</th><th>Created</th><th style="width:100px">Actions</th></tr>
          </thead>
          <tbody>
            ${!rows.length
              ? `<tr><td colspan="6"><div class="empty">
                  <div class="empty-icon">📄</div><h3>No contracts</h3>
                  <p>Generate your first AI contract</p>
                  <button class="btn btn-primary btn-sm" onclick="App.go('contracts/new')">Get Started</button>
                 </div></td></tr>`
              : rows.map(c => `
                <tr>
                  <td><span class="fw-600" style="cursor:pointer" onclick="App.go('contract',{id:${c.id}})">${esc(c.title)}</span></td>
                  <td><span class="badge badge-${esc(c.status)}">${esc(c.status)}</span></td>
                  <td><span class="badge badge-${esc(c.tone)}">${esc(c.tone)}</span></td>
                  <td class="text-muted">${esc(c.counterparty_name || '—')}</td>
                  <td class="text-muted">${fmtDate(c.created_at)}</td>
                  <td>
                    <div style="display:flex;gap:4px">
                      <button class="btn btn-ghost btn-icon btn-sm" onclick="App.go('contract',{id:${c.id}})" title="Open">${icon('eye',14)}</button>
                      <button class="btn btn-ghost btn-icon btn-sm" onclick="window._downloadPdf(${c.id},'en',this)" title="Download PDF">${icon('download',14)}</button>
                      ${c.status==='draft' ? `<button class="btn btn-ghost btn-icon btn-sm" onclick="window._delContract(${c.id},this)" title="Delete">${icon('trash',14)}</button>` : ''}
                    </div>
                  </td>
                </tr>`).join('')}
          </tbody>
        </table>
      </div>
      ${renderPagination(meta, `App.go.bind(null,'contracts',{status:'${status}',page:`)}</div>`);

  // exposed for onclick
  window._delContract = async function(id, btn) {
    if (!confirm('Delete this draft contract? This cannot be undone.')) return;
    btn.disabled = true;
    const d = await API.contracts.delete(id);
    if (d.success) { toast('Deleted','success'); btn.closest('tr').remove(); }
    else { toast(d.message,'error'); btn.disabled=false; }
  };
};

// ── CONTRACT WIZARD ───────────────────────────────────────────
Pages.contractWizard = async function() {
  setTitle('Generate Contract'); showLoading();
  const [tR, cpR] = await Promise.all([
    API.templates.list(),
    API.counterparties.list({ per_page: 200 })
  ]);
  hideLoading();

  const templates = (tR.data && tR.data.data) ? tR.data.data : [];
  const cps       = (cpR.data && cpR.data.data) ? cpR.data.data : [];
  const cats      = (tR.data && tR.data.categories) ? tR.data.categories : [];

  const byCategory = {};
  templates.forEach(t => { (byCategory[t.category] = byCategory[t.category]||[]).push(t); });

  setPage(`
    <div class="wizard-bar">
      <div class="wizard-step active" id="ws1"><span class="step-num">1</span> Details</div>
      <div class="wizard-step" id="ws2"><span class="step-num">2</span> Questions</div>
      <div class="wizard-step" id="ws3"><span class="step-num">3</span> Tone &amp; Generate</div>
    </div>

    <div id="wiz-step1">
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
        <div class="card" style="flex:1;min-width:260px">
          <div class="card-hd">Contract Details</div>
          <div class="card-body">
            <div class="form-group">
              <label>Contract Title *</label>
              <input id="wiz-title" class="form-control" placeholder="e.g. Service Agreement – TechCorp UAE" required>
            </div>
            <div class="form-group">
              <label>Template *</label>
              <select id="wiz-tpl" class="form-control" required>
                <option value="">— Select template —</option>
                ${cats.map(cat => `<optgroup label="${esc(cat)}">
                  ${(byCategory[cat]||[]).map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}
                </optgroup>`).join('')}
              </select>
              ${!templates.length ? `<div class="form-hint">No templates yet. <a href="#/templates">Create one first →</a></div>` : ''}
            </div>
            <div class="form-group">
              <label>Counterparty <span class="text-muted">(optional)</span></label>
              <select id="wiz-cp" class="form-control">
                <option value="">— None —</option>
                ${cps.map(cp => `<option value="${cp.id}">${esc(cp.company_name)}</option>`).join('')}
              </select>
            </div>
          </div>
        </div>
        <div class="card" style="width:240px">
          <div class="card-hd">How it works</div>
          <div class="card-body" style="font-size:13px;color:var(--slate);line-height:1.7">
            <p>Gemini AI drafts your contract using:</p>
            <ul style="padding-left:16px;margin-top:8px">
              <li>Template structure</li>
              <li>Your questionnaire answers</li>
              <li>Counterparty details</li>
              <li>Selected tone</li>
              <li>Workspace AI rules</li>
            </ul>
          </div>
        </div>
      </div>
      <div style="display:flex;justify-content:space-between;margin-top:16px">
        <button class="btn btn-outline" onclick="App.go('contracts')">Cancel</button>
        <button class="btn btn-primary" onclick="window._wizNext()">Continue →</button>
      </div>
    </div>

    <div id="wiz-step2" style="display:none">
      <div class="card">
        <div class="card-hd">Contract Questionnaire</div>
        <div class="card-body" id="wiz-q-body"><div class="text-muted" style="font-size:13px">Loading questions…</div></div>
      </div>
      <div style="display:flex;justify-content:space-between;margin-top:16px">
        <button class="btn btn-outline" onclick="window._wizGo(1)">← Back</button>
        <button class="btn btn-primary" onclick="window._wizGo(3)">Continue →</button>
      </div>
    </div>

    <div id="wiz-step3" style="display:none">
      <div class="card" style="margin-bottom:16px">
        <div class="card-hd">Select Tone</div>
        <div class="card-body">
          <div class="tone-grid">
            ${[
              { k:'strong',   e:'⚖️', n:'Strong',       d:'Firm, protective legal language.' },
              { k:'friendly', e:'🤝', n:'Friendly',     d:'Professional and balanced tone.'  },
              { k:'casual',   e:'💬', n:'Plain English', d:'Simple language, no jargon.'     },
            ].map(t => `
              <div class="tone-card${t.k==='strong'?' selected':''}" data-tone="${t.k}" onclick="window._pickTone(this)">
                <div class="tone-icon">${t.e}</div>
                <div class="tone-name">${t.n}</div>
                <div class="tone-desc">${t.d}</div>
              </div>`).join('')}
          </div>
          <input type="hidden" id="wiz-tone" value="strong">
        </div>
      </div>
      <div style="display:flex;justify-content:space-between">
        <button class="btn btn-outline" onclick="window._wizGo(2)">← Back</button>
        <button class="btn btn-gold" id="wiz-gen-btn" onclick="window._wizGenerate()">
          ${icon('zap',18)} Generate Contract with AI
        </button>
      </div>
    </div>`);

  window._wizGo = function(n) {
    [1,2,3].forEach(i => {
      const step = document.getElementById('wiz-step'+i);
      const tab  = document.getElementById('ws'+i);
      if (step) step.style.display = i===n ? 'block' : 'none';
      if (tab) {
        tab.classList.remove('active','done');
        if (i < n) tab.classList.add('done');
        if (i === n) tab.classList.add('active');
      }
    });
  };

  window._pickTone = function(el) {
    document.querySelectorAll('.tone-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('wiz-tone').value = el.dataset.tone;
  };

  window._wizNext = async function() {
    const title = document.getElementById('wiz-title').value.trim();
    const tplId = document.getElementById('wiz-tpl').value;
    if (!title) { toast('Please enter a contract title','error'); return; }
    if (!tplId) { toast('Please select a template','error');      return; }

    window._wizGo(2);
    const body = document.getElementById('wiz-q-body');
    body.innerHTML = '<div class="text-muted" style="font-size:13px">Loading…</div>';

    const d = await API.templates.get(parseInt(tplId));
    if (!d.success) { body.innerHTML = '<p class="form-error">Failed to load template</p>'; return; }

    const schema  = d.data.questionnaire_schema || {};
    const entries = Object.entries(schema);

    if (!entries.length) {
      body.innerHTML = '<p class="text-muted" style="font-size:13px">No questions for this template. Continue to generate.</p>';
      return;
    }

    body.innerHTML = '<div class="form-row" style="flex-wrap:wrap">' +
      entries.map(([k, def]) => {
        const type = typeof def === 'string' ? def : (def.type || 'text');
        const opts = (typeof def === 'object' && Array.isArray(def.options)) ? def.options : [];
        const lbl  = k.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
        // Only textarea is full-width; all other types split into two columns
        const wide = type === 'textarea';
        const w    = wide ? '100%' : 'calc(50% - 7px)';
        let inp = '';
        if (type === 'select' && opts.length) {
          inp = `<select name="ans_${k}" class="form-control"><option value="">— Select —</option>${opts.map(o=>`<option value="${esc(o)}">${esc(o)}</option>`).join('')}</select>`;
        } else if (type === 'select') {
          // select declared but no options — fall back to text input
          inp = `<input type="text" name="ans_${k}" class="form-control">`;
        } else if (type === 'textarea') {
          inp = `<textarea name="ans_${k}" class="form-control" rows="3"></textarea>`;
        } else {
          const htmlType = {number:'number',date:'date',email:'email',url:'url'}[type] || 'text';
          inp = `<input type="${htmlType}" name="ans_${k}" class="form-control">`;
        }
        return `<div class="form-group" style="width:${w}"><label>${esc(lbl)}</label>${inp}</div>`;
      }).join('') + '</div>';
  };

  window._wizGenerate = async function() {
    const btn   = document.getElementById('wiz-gen-btn');
    const title = document.getElementById('wiz-title').value.trim();
    const tplId = document.getElementById('wiz-tpl').value;
    const cpId  = document.getElementById('wiz-cp').value;
    const tone  = document.getElementById('wiz-tone').value;

    const answers = {};
    document.querySelectorAll('#wiz-q-body [name^="ans_"]').forEach(el => {
      answers[el.name.replace('ans_','')] = el.value;
    });

    btn.disabled = true;
    showLoading('We are carefully drafting your contract… This may take 20–30 seconds.');

    const d = await API.contracts.generate({
      title, template_id: tplId,
      counterparty_id: cpId || null,
      tone, answers
    });

    hideLoading(); btn.disabled = false;
    if (d.success && d.data) {
      toast('Contract generated!', 'success');
      App.go('contract', { id: d.data.id });
    } else {
      toast(d.message || 'Generation failed. Check your Gemini API key.', 'error');
    }
  };
};

// ── CONTRACT EDITOR ───────────────────────────────────────────
Pages.contractEditor = async function(params) {
  const id = params && parseInt(params.id);
  if (!id) { App.go('contracts'); return; }

  setTitle('Contract'); showLoading();
  const d = await API.contracts.get(id);
  hideLoading();
  if (!d.success) { setPage('<p class="text-muted">Contract not found.</p>'); return; }

  const c     = d.data;
  const isFin = c.status === 'final';
  const html  = c.edited_html || c.generated_html || '';
  setTitle(c.title);

  setPage(`
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
      <div style="font-size:13px">
        <span onclick="App.go('contracts')" style="color:var(--slate-l);cursor:pointer">← Contracts</span>
        <span style="color:var(--slate-xl);margin:0 8px">/</span>
        <span>${esc(c.title)}</span>
        <span class="badge badge-${esc(c.status)}" style="margin-left:8px">${esc(c.status)}</span>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        ${!isFin ? `<button class="btn btn-outline btn-sm" id="save-btn" onclick="window._saveCon(${id})">Save Draft</button>` : ''}
        <button class="btn btn-outline btn-sm" onclick="window._downloadPdf(${id},'en',this)">${icon('download',14)} PDF (EN)</button>
        ${c.language !== 'en' ? `<button class="btn btn-outline btn-sm" onclick="window._downloadPdf(${id},'ar',this)">PDF (AR)</button>` : ''}
        ${!isFin ? `<button class="btn btn-primary btn-sm" onclick="window._finCon(${id})">Finalise</button>` : ''}
      </div>
    </div>

    <input id="con-title" class="form-control" value="${esc(c.title)}"
      style="font-size:18px;font-weight:700;border:none;border-bottom:2px solid var(--slate-xl);border-radius:0;padding:6px 0;margin-bottom:10px"
      ${isFin ? 'readonly' : ''}>

    <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--slate-l);margin-bottom:14px">
      ${c.template_name    ? `<span>📋 ${esc(c.template_name)}</span>`    : ''}
      ${c.counterparty_name? `<span>🏢 ${esc(c.counterparty_name)}</span>`: ''}
      <span>🎯 ${esc(c.tone)}</span>
      <span>👤 ${esc(c.created_by_name)}</span>
      <span>📅 ${fmtDate(c.created_at)}</span>
      <span id="save-status" style="margin-left:auto"></span>
    </div>

    ${!isFin ? `
    <div class="editor-wrap">
      <div class="editor-toolbar">
        <button class="tb-btn" onclick="document.execCommand('bold')"         title="Bold">       <b>B</b>  </button>
        <button class="tb-btn" onclick="document.execCommand('italic')"       title="Italic">     <i>I</i>  </button>
        <button class="tb-btn" onclick="document.execCommand('underline')"    title="Underline">  <u>U</u>  </button>
        <div class="tb-sep"></div>
        <button class="tb-btn" onclick="document.execCommand('formatBlock',false,'h1')" title="H1">H1</button>
        <button class="tb-btn" onclick="document.execCommand('formatBlock',false,'h2')" title="H2">H2</button>
        <button class="tb-btn" onclick="document.execCommand('formatBlock',false,'h3')" title="H3">H3</button>
        <button class="tb-btn" onclick="document.execCommand('formatBlock',false,'p')"  title="Paragraph">P</button>
        <div class="tb-sep"></div>
        <button class="tb-btn" onclick="document.execCommand('insertOrderedList')"   title="Numbered">1.</button>
        <button class="tb-btn" onclick="document.execCommand('insertUnorderedList')" title="Bullets">•</button>
        <div class="tb-sep"></div>
        <button class="tb-btn" onclick="document.execCommand('justifyLeft')"   title="Left">  ≡L</button>
        <button class="tb-btn" onclick="document.execCommand('justifyCenter')" title="Center">≡C</button>
        <button class="tb-btn" onclick="document.execCommand('justifyRight')"  title="Right"> ≡R</button>
        <div class="tb-sep"></div>
        <button class="tb-btn" onclick="document.execCommand('undo')" title="Undo">↩</button>
        <button class="tb-btn" onclick="document.execCommand('redo')" title="Redo">↪</button>
      </div>
      <div id="editor-body" class="editor-body" contenteditable="true" spellcheck="true">${html}</div>
    </div>` : `
    <div class="card">
      <div class="card-body" style="font-family:Georgia,serif;font-size:14px;line-height:1.85;max-width:800px;margin:0 auto;padding:36px 44px">
        ${html}
      </div>
    </div>`}
  `);

  if (!isFin) {
    let autoTimer;
    document.getElementById('editor-body').addEventListener('input', () => {
      document.getElementById('save-status').textContent = 'Unsaved changes…';
      clearTimeout(autoTimer);
      autoTimer = setTimeout(() => window._saveCon(id), 4000);
    });
  }

  window._saveCon = async function(cid) {
    const btn  = document.getElementById('save-btn');
    const body = document.getElementById('editor-body');
    const titl = document.getElementById('con-title');
    if (!body) return;
    if (btn) { btn.disabled=true; btn.textContent='Saving…'; }

    const d = await API.contracts.save(cid, {
      html:  body.innerHTML,
      title: titl ? titl.value : ''
    });

    if (btn) { btn.disabled=false; btn.textContent='Save Draft'; }
    const st = document.getElementById('save-status');
    if (d.success) { if (st) st.textContent = 'Saved ' + new Date().toLocaleTimeString(); }
    else toast(d.message || 'Save failed', 'error');
  };

  window._finCon = async function(cid) {
    if (!confirm('Finalise this contract? It will be locked for editing.')) return;
    await window._saveCon(cid);
    const d = await API.contracts.finalize(cid);
    if (d.success) { toast('Contract finalised!', 'success'); App.go('contract', { id: cid }); }
    else toast(d.message || 'Finalise failed', 'error');
  };
};

// ── TEMPLATES ─────────────────────────────────────────────────
// ── CLAUSE LIBRARY ──────────────────────────────────────────
Pages.clauses = async function() {
  setTitle('Clause Library'); showLoading();
  const d = await API.clauses.list({ per_page: 200 });
  hideLoading();
  const rows    = d.data?.data       || [];
  const cats    = d.data?.categories || [];
  const me      = API.store.user;
  const canEdit = me && (me.role === 'owner' || me.role === 'admin');

  window._clauseData = rows;
  window._clauseCats = cats;

  setPage(`
    <div class="page-header">
      <div class="page-header-left">
        <h2>Clause Library</h2>
        <p>${rows.length} clause${rows.length !== 1 ? 's' : ''} across ${cats.length} categor${cats.length !== 1 ? 'ies' : 'y'}</p>
      </div>
      <div style="display:flex;gap:8px">
        ${canEdit ? `
        <button class="btn btn-outline" onclick="window._manageCategories()">${icon('tag',14)} Categories</button>
        <button class="btn btn-primary" onclick="window._openClauseForm()">${icon('plus',15)} New Clause</button>` : ''}
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
      <input id="clause-search" class="form-control" placeholder="Search title, tags, ID…"
        style="max-width:260px" oninput="window._filterClauses()">
      <select id="clause-cat-filter" class="form-control" style="max-width:180px"
        onchange="window._filterClauses()">
        <option value="">All Categories</option>
        ${cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('')}
      </select>
      <span id="clause-count" style="font-size:12px;color:var(--slate-l)">${rows.length} clauses</span>
    </div>

    ${!rows.length ? `
    <div class="card"><div class="empty">
      <div class="empty-icon">📚</div>
      <h3>No clauses yet</h3>
      <p>Build your reusable clause library. Each clause gets a unique ID (CL-0001) and can be combined into templates.</p>
      ${canEdit ? `<button class="btn btn-primary btn-sm" onclick="window._openClauseForm()">${icon('plus',14)} Add First Clause</button>` : ''}
    </div></div>` : `
    <div class="card">
      <div class="table-wrap">
        <table id="clause-table">
          <thead><tr>
            <th style="width:95px">ID</th>
            <th>Title</th>
            <th style="width:140px">Category</th>
            <th>Tags</th>
            <th style="width:50px;text-align:center">Ver</th>
            <th style="width:100px"></th>
          </tr></thead>
          <tbody>
            ${rows.map(c => `
            <tr data-title="${esc(c.title.toLowerCase())}"
                data-cat="${esc(c.category.toLowerCase())}"
                data-tags="${esc((c.tags||'').toLowerCase())}"
                data-uid="${esc(c.clause_uid.toLowerCase())}">
              <td>
                <code style="font-size:11px;background:var(--surface-1);padding:2px 8px;border-radius:5px;font-weight:700;color:var(--ink-muted);letter-spacing:.3px">${esc(c.clause_uid)}</code>
              </td>
              <td>
                <div class="fw-600" style="font-size:13.5px">${esc(c.title)}</div>
                ${c.title_ar ? `<div style="font-size:11px;color:var(--slate-l);direction:rtl;text-align:right;margin-top:2px">${esc(c.title_ar)}</div>` : ''}
              </td>
              <td><span class="badge badge-draft">${esc(c.category)}</span></td>
              <td style="font-size:11.5px;color:var(--slate)">
                ${(c.tags||'').split(',').filter(Boolean).map(t =>
                  `<span style="background:var(--surface-1);padding:1px 7px;border-radius:4px;margin-right:3px;white-space:nowrap">${esc(t.trim())}</span>`
                ).join('')}
              </td>
              <td style="text-align:center;color:var(--slate-l);font-size:12px">v${c.version}</td>
              <td>
                <div style="display:flex;gap:2px;justify-content:flex-end">
                  <button class="btn btn-ghost btn-icon btn-sm" onclick="window._previewClause(${c.id})" title="Preview">${icon('eye',13)}</button>
                  ${canEdit ? `
                  <button class="btn btn-ghost btn-icon btn-sm" onclick="window._openClauseForm(${c.id})" title="Edit">${icon('edit',13)}</button>
                  <button class="btn btn-ghost btn-icon btn-sm" onclick="window._deleteClause(${c.id},this)" title="Delete" style="color:var(--slate-l)">${icon('trash',13)}</button>` : ''}
                </div>
              </td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>`}`);

  window._filterClauses = function() {
    const q   = (document.getElementById('clause-search')?.value || '').toLowerCase();
    const cat = (document.getElementById('clause-cat-filter')?.value || '').toLowerCase();
    let visible = 0;
    document.querySelectorAll('#clause-table tbody tr').forEach(tr => {
      const ok = (!q   || tr.dataset.title.includes(q) || tr.dataset.tags.includes(q) || tr.dataset.uid.includes(q))
              && (!cat || tr.dataset.cat === cat);
      tr.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });
    const cnt = document.getElementById('clause-count');
    if (cnt) cnt.textContent = visible + ' clause' + (visible !== 1 ? 's' : '');
  };
};

// ── CLAUSE CRUD — module-level so always available ─────────────
window._previewClause = async function(id) {
  const d = await API.clauses.get(id);
  if (!d.success) { toast(d.message, 'error'); return; }
  const c = d.data;
  showModal(
    `${c.clause_uid} — ${c.title}`,
    `<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
       <span class="badge badge-draft">${esc(c.category)}</span>
       <span style="font-size:11px;color:var(--slate-l)">v${c.version}</span>
       ${c.tags ? `<span style="font-size:11.5px;color:var(--slate)">${esc(c.tags)}</span>` : ''}
     </div>
     <div style="background:var(--surface);border-radius:8px;padding:18px 20px;font-family:Georgia,serif;font-size:13.5px;line-height:1.9;max-height:420px;overflow-y:auto;border:1px solid var(--slate-xl)">
       ${c.body_html}
     </div>`,
    `<button class="btn btn-outline" onclick="closeModal()">Close</button>`
  );
};

window._deleteClause = async function(id, btn) {
  if (!confirm('Delete this clause?\nNote: clauses used in active templates cannot be deleted.')) return;
  btn.disabled = true;
  const d = await API.clauses.delete(id);
  if (d.success) { toast('Clause deleted', 'success'); Pages.clauses(); }
  else { toast(d.message, 'error'); btn.disabled = false; }
};

window._manageCategories = function() {
  const cats  = window._clauseCats || [];
  showModal(
    'Manage Categories',
    `<p style="font-size:13px;color:var(--slate);margin-bottom:14px">
       Categories group clauses in the library and clause picker. Add new ones here or rename by editing a clause.
     </p>
     <div id="cat-list" style="margin-bottom:12px">
       ${cats.map(c => `
         <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
           <span class="badge badge-draft" style="flex:1;justify-content:flex-start;font-size:12px;padding:4px 10px">${esc(c)}</span>
           <span style="font-size:11px;color:var(--slate-l)">${(window._clauseData||[]).filter(cl=>cl.category===c).length} clause(s)</span>
         </div>`).join('')}
       ${!cats.length ? '<p style="font-size:13px;color:var(--slate-l)">No categories yet. They are created automatically when you save a clause with a new category name.</p>' : ''}
     </div>
     <div class="divider"></div>
     <p style="font-size:12px;color:var(--slate);margin-top:8px">
       ${icon('info',13)} Categories are managed through clauses — set the category when creating or editing a clause. Common categories are suggested automatically.
     </p>`,
    `<button class="btn btn-outline" onclick="closeModal()">Close</button>
     <button class="btn btn-primary" onclick="closeModal();window._openClauseForm()">${icon('plus',14)} New Clause</button>`
  );
};

window._openClauseForm = async function(id) {
  let c = {};
  if (id) {
    showLoading();
    const d = await API.clauses.get(id);
    hideLoading();
    if (!d.success) { toast(d.message, 'error'); return; }
    c = d.data;
  }

  const cats = window._clauseCats || [
    'General','Definitions','Payment','Termination','Confidentiality',
    'Liability','Governing Law','Dispute Resolution','IP Rights',
  ];

  showModal(
    id ? `Edit — ${c.clause_uid}` : 'New Clause',
    `<!-- Title row -->
     <div class="form-row">
       <div class="form-group" style="flex:2">
         <label>Title (English) *</label>
         <input id="cf-title" class="form-control" value="${esc(c.title||'')}" placeholder="e.g. Governing Law">
         <div class="form-error" id="cf-title-err" style="display:none"></div>
       </div>
       <div class="form-group" style="flex:1">
         <label>Category *</label>
         <input id="cf-cat" list="cf-catlist" class="form-control" value="${esc(c.category||'General')}" placeholder="Type or pick">
         <datalist id="cf-catlist">
           ${cats.map(cat => `<option value="${esc(cat)}">`).join('')}
           <option value="General"><option value="Definitions"><option value="Payment">
           <option value="Termination"><option value="Confidentiality"><option value="Liability">
           <option value="Governing Law"><option value="Dispute Resolution"><option value="IP Rights">
           <option value="Warranties"><option value="Indemnification"><option value="Force Majeure">
         </datalist>
         <div class="form-hint">Type a new name to create a category</div>
         <div class="form-error" id="cf-cat-err" style="display:none"></div>
       </div>
     </div>
     <!-- Arabic title -->
     <div class="form-group">
       <label>Title (Arabic) <span class="form-hint" style="display:inline;text-transform:none;letter-spacing:0">optional</span></label>
       <input id="cf-title-ar" class="form-control rtl-input" dir="rtl" value="${esc(c.title_ar||'')}" placeholder="العنوان بالعربية">
     </div>
     <!-- Tags -->
     <div class="form-group">
       <label>Tags <span class="form-hint" style="display:inline;text-transform:none;letter-spacing:0">comma-separated, used for search</span></label>
       <input id="cf-tags" class="form-control" value="${esc(c.tags||'')}" placeholder="nda, liability, uae">
     </div>
     <!-- Body EN -->
     <div class="form-group">
       <label>Clause Body *</label>
       <div class="editor-wrap">
         <div class="editor-toolbar">
           ${[['B','bold'],['I','italic'],['U','underline']].map(([l,cmd])=>`<button type="button" class="tb-btn" onclick="document.execCommand('${cmd}',false)"><${l==='B'?'b':l==='I'?'i':'u'}>${l}</${l==='B'?'b':l==='I'?'i':'u'}></button>`).join('')}
           <div class="tb-sep"></div>
           ${[['H2','h2'],['H3','h3'],['P','p']].map(([l,t])=>`<button type="button" class="tb-btn" onclick="document.execCommand('formatBlock',false,'${t}')">${l}</button>`).join('')}
           <div class="tb-sep"></div>
           <button type="button" class="tb-btn" onclick="document.execCommand('insertUnorderedList',false)">• List</button>
           <button type="button" class="tb-btn" onclick="document.execCommand('insertOrderedList',false)">1. List</button>
         </div>
         <div id="cf-body" class="editor-body" style="min-height:180px;padding:14px 18px"
           contenteditable="true">${c.body_html||''}</div>
       </div>
       <div class="form-error" id="cf-body-err" style="display:none"></div>
     </div>
     <!-- Body AR -->
     <div class="form-group">
       <label>Clause Body Arabic <span class="form-hint" style="display:inline;text-transform:none;letter-spacing:0">optional</span></label>
       <div id="cf-body-ar" class="form-control editor-body rtl-input"
         style="min-height:90px;padding:12px 16px;font-family:Cairo,serif;font-size:13px;direction:rtl"
         contenteditable="true" dir="rtl">${c.body_html_ar||''}</div>
     </div>`,
    `<button class="btn btn-outline" onclick="closeModal()">Cancel</button>
     <button class="btn btn-primary" id="cf-save-btn" onclick="window._saveClause(${id||'null'})">
       ${icon(id?'save':'plus',14)} ${id ? 'Update' : 'Create'} Clause
     </button>`
  );
};

window._saveClause = async function(id) {
  // Client-side validation with visible feedback
  const titleEl   = document.getElementById('cf-title');
  const catEl     = document.getElementById('cf-cat');
  const bodyEl    = document.getElementById('cf-body');
  const titleErr  = document.getElementById('cf-title-err');
  const catErr    = document.getElementById('cf-cat-err');
  const bodyErr   = document.getElementById('cf-body-err');
  const saveBtn   = document.getElementById('cf-save-btn');

  // Reset errors
  [titleErr, catErr, bodyErr].forEach(el => { el.style.display='none'; el.textContent=''; });
  [titleEl, catEl, bodyEl].forEach(el => el.classList.remove('error'));

  const title    = titleEl.value.trim();
  const category = catEl.value.trim();
  const bodyHtml = bodyEl.innerHTML.replace(/<br\s*\/?>/gi,'').trim();

  let valid = true;
  if (!title) {
    titleErr.textContent = 'Title is required'; titleErr.style.display='block';
    titleEl.classList.add('error'); titleEl.focus(); valid = false;
  }
  if (!category) {
    catErr.textContent = 'Category is required'; catErr.style.display='block';
    catEl.classList.add('error'); valid = false;
  }
  if (!bodyHtml || bodyHtml === '<br>' || bodyHtml.length < 5) {
    bodyErr.textContent = 'Clause body is required (min 5 characters)'; bodyErr.style.display='block';
    bodyEl.classList.add('error'); valid = false;
  }
  if (!valid) return;

  saveBtn.disabled = true;
  saveBtn.textContent = id ? 'Updating…' : 'Creating…';

  const data = {
    title,
    title_ar:     document.getElementById('cf-title-ar').value.trim(),
    category,
    tags:         document.getElementById('cf-tags').value.trim(),
    body_html:    bodyHtml,
    body_html_ar: document.getElementById('cf-body-ar').innerHTML.replace(/<br\s*\/?>/gi,'').trim(),
  };

  const d = id ? await API.clauses.update(id, data) : await API.clauses.create(data);
  if (d.success) {
    toast(id ? 'Clause updated' : `Clause ${d.data?.clause_uid||''} created`, 'success');
    closeModal();
    Pages.clauses();
  } else {
    saveBtn.disabled = false;
    saveBtn.textContent = id ? 'Update Clause' : 'Create Clause';
    if (d.errors) {
      // Map server field names to our element IDs
      const map = { title: 'cf-title-err', category: 'cf-cat-err', body_html: 'cf-body-err' };
      Object.entries(d.errors).forEach(([f, msgs]) => {
        const errId = map[f];
        if (errId) {
          const el = document.getElementById(errId);
          if (el) { el.textContent = Array.isArray(msgs) ? msgs[0] : msgs; el.style.display='block'; }
        }
      });
    } else {
      toast(d.message || 'Save failed', 'error');
    }
  }
};


Pages.templates = async function() {
  setTitle('Templates'); showLoading();
  const [td, cd] = await Promise.all([
    API.templates.list(),
    API.clauses.list({ per_page: 200 }),
  ]);
  hideLoading();
  const rows      = (td.data && td.data.data) ? td.data.data : [];
  const allClauses= (cd.data && cd.data.data) ? cd.data.data : [];
  const me        = API.store.user;
  const canEdit   = me && (me.role === 'owner' || me.role === 'admin');

  // Cache clauses for the picker
  window._allClauses = allClauses;

  setPage(`
    <div class="page-header">
      <div class="page-header-left">
        <h2>Templates</h2>
        <p>${rows.length} template${rows.length !== 1 ? 's' : ''} — built from Clause Library blocks</p>
      </div>
      ${canEdit ? `<button class="btn btn-primary" onclick="window._openTplForm()">${icon('plus',15)} New Template</button>` : ''}
    </div>

    ${!rows.length ? `
    <div class="card">
      <div class="empty">
        <div class="empty-icon">📋</div>
        <h3>No templates yet</h3>
        <p>Build templates by picking clauses from your Clause Library, then add questionnaire fields for dynamic data.</p>
        ${canEdit ? `
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
          ${!allClauses.length ? `<button class="btn btn-outline btn-sm" onclick="App.go('clauses')">${icon('book',14)} Build Clause Library First</button>` : ''}
          <button class="btn btn-primary btn-sm" onclick="window._openTplForm()">${icon('plus',14)} Create Template</button>
        </div>` : ''}
      </div>
    </div>` : `
    <div class="tpl-grid">
      ${rows.map(t => `
        <div class="tpl-card">
          <div class="tpl-card-top">
            <div class="tpl-card-cat">${icon('tag',11)} ${esc(t.category)}</div>
            <div class="tpl-card-name">${esc(t.name)}</div>
            ${t.name_ar ? `<div class="tpl-card-name-ar">${esc(t.name_ar)}</div>` : ''}
            <div class="tpl-card-meta">
              <span>${icon('book',11)} ${t.clause_count||0} clause${t.clause_count!=1?'s':''}</span>
              <span>${icon('globe',11)} ${esc(t.language).toUpperCase()}</span>
              <span>${icon('clock',11)} v${t.version}</span>
            </div>
          </div>
          <div class="tpl-card-foot">
            <button class="btn btn-gold btn-sm" onclick="App.go('contracts/new')">${icon('zap',13)} Use</button>
            ${canEdit ? `<div style="display:flex;gap:4px">
              <button class="btn btn-outline btn-sm" onclick="window._openTplForm(${t.id})">${icon('edit',13)} Edit</button>
              <button class="btn btn-ghost btn-icon btn-sm" onclick="window._delTpl(${t.id},this)">${icon('trash',13)}</button>
            </div>` : ''}
          </div>
        </div>`).join('')}
    </div>`}`);

  window._delTpl = async function(id, btn) {
    if (!confirm('Delete this template?')) return;
    btn.disabled = true;
    const d = await API.templates.delete(id);
    if (d.success) { toast('Template deleted','success'); Pages.templates(); }
    else { toast(d.message,'error'); btn.disabled = false; }
  };

  window._openTplForm = async function(id) {
    let tpl = {};
    if (id) {
      showLoading();
      const d = await API.templates.get(id);
      hideLoading();
      if (d.success) tpl = d.data;
    }
    const schema        = tpl.questionnaire_schema || {};
    const fields        = Object.entries(schema);
    const selectedIds   = new Set((tpl.clauses||[]).map(c => c.id));
    const orderedClauses= (tpl.clauses||[]).slice(); // already ordered from server
    const available     = (window._allClauses||[]);

    // Group available clauses by category
    const byCat = {};
    available.forEach(c => { (byCat[c.category] = byCat[c.category]||[]).push(c); });

    showModal(
      id ? 'Edit Template' : 'New Template',
      `<!-- Basic Info -->
      <div class="form-row">
        <div class="form-group" style="flex:2">
          <label>Name (English) *</label>
          <input id="tf-name" class="form-control" value="${esc(tpl.name||'')}" placeholder="e.g. NDA Agreement">
        </div>
        <div class="form-group" style="flex:1">
          <label>Language</label>
          <select id="tf-lang" class="form-control">
            <option value="en" ${tpl.language==='en'?'selected':''}>English</option>
            <option value="ar" ${tpl.language==='ar'?'selected':''}>Arabic</option>
            <option value="bilingual" ${tpl.language==='bilingual'?'selected':''}>Bilingual</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Name (Arabic)</label>
          <input id="tf-name-ar" class="form-control rtl-input" dir="rtl" value="${esc(tpl.name_ar||'')}">
        </div>
        <div class="form-group">
          <label>Category *</label>
          <input id="tf-cat" list="tf-catlist" class="form-control" value="${esc(tpl.category||'General')}">
          <datalist id="tf-catlist">
            <option>General</option><option>NDA</option><option>Employment</option>
            <option>Service Agreement</option><option>Sale &amp; Purchase</option>
            <option>Lease</option><option>Partnership</option><option>Consultancy</option>
          </datalist>
        </div>
      </div>

      <!-- Clause Picker -->
      <div style="border:1.5px solid var(--slate-xl);border-radius:10px;overflow:hidden;margin-bottom:16px">
        <div style="background:var(--surface);padding:10px 14px;border-bottom:1px solid var(--slate-xl);display:flex;justify-content:space-between;align-items:center">
          <div style="font-weight:700;font-size:13px">${icon('book',14)} Clauses</div>
          <span style="font-size:11px;color:var(--slate-l)">Check to include · drag to reorder</span>
        </div>
        <div style="display:flex;gap:0;min-height:220px">
          <!-- Left: available clauses by category -->
          <div style="flex:1;border-right:1px solid var(--slate-xl);overflow-y:auto;max-height:320px">
            <div style="padding:8px 10px;border-bottom:1px solid var(--surface-1)">
              <input id="clause-picker-search" class="form-control" style="font-size:12px;padding:5px 9px"
                placeholder="Search clauses…" oninput="window._pickerFilter(this.value)">
            </div>
            ${!available.length
              ? `<div style="padding:20px;text-align:center;color:var(--slate-l);font-size:12px">
                   No clauses in library yet.<br>
                   <a href="#/clauses" onclick="closeModal()" style="color:var(--ink);font-weight:600">Add clauses first →</a>
                 </div>`
              : Object.entries(byCat).map(([cat, cs]) => `
                <div class="picker-group" data-cat="${esc(cat.toLowerCase())}">
                  <div style="padding:5px 12px;font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--slate-l);background:var(--surface-1)">${esc(cat)}</div>
                  ${cs.map(c => `
                    <label class="picker-row" data-search="${esc(c.title.toLowerCase())} ${esc((c.tags||'').toLowerCase())}"
                      style="display:flex;align-items:center;gap:9px;padding:7px 12px;cursor:pointer;border-bottom:1px solid var(--surface-1);font-size:12.5px;transition:background .12s"
                      onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">
                      <input type="checkbox" class="clause-pick-cb" data-id="${c.id}" data-title="${esc(c.title)}"
                        data-uid="${esc(c.clause_uid)}"
                        ${selectedIds.has(c.id) ? 'checked' : ''}
                        onchange="window._pickerToggle(this)">
                      <span style="flex:1"><span class="fw-600">${esc(c.title)}</span><code style="font-size:10px;color:var(--slate-l);margin-left:6px">${esc(c.clause_uid)}</code></span>
                    </label>`).join('')}
                </div>`).join('')}
          </div>

          <!-- Right: selected clauses (ordered, draggable) -->
          <div style="width:220px;display:flex;flex-direction:column">
            <div style="padding:8px 12px;font-size:11px;font-weight:700;color:var(--slate);background:var(--surface-1);border-bottom:1px solid var(--slate-xl)">
              SELECTED ORDER
            </div>
            <div id="selected-clauses" style="flex:1;overflow-y:auto;padding:6px">
              ${orderedClauses.map((c,i) => window_clauseOrderItem ? '' : `
                <div class="selected-clause-item" draggable="true" data-id="${c.id}"
                  style="display:flex;align-items:center;gap:6px;padding:6px 8px;background:var(--white);border:1px solid var(--slate-xl);border-radius:6px;margin-bottom:4px;font-size:12px;cursor:grab">
                  ${icon('menu',12)}
                  <span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(c.title)}</span>
                  <button type="button" onclick="window._pickerRemove(${c.id},this.closest('.selected-clause-item'))" style="background:none;border:none;color:var(--slate-l);cursor:pointer;padding:0;line-height:1">×</button>
                </div>`).join('')}
            </div>
            <div style="padding:8px;border-top:1px solid var(--slate-xl);font-size:11px;color:var(--slate-l);text-align:center">
              <span id="selected-count">${orderedClauses.length}</span> selected
            </div>
          </div>
        </div>
      </div>

      <!-- Questionnaire Fields -->
      <div style="border:1.5px solid var(--slate-xl);border-radius:10px;padding:14px;margin-bottom:16px;background:var(--surface)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <div style="font-weight:700;font-size:13px;color:var(--ink)">Questionnaire Fields</div>
          <button type="button" class="btn btn-outline btn-sm" onclick="window._addTplField()">${icon('plus',13)} Add Field</button>
        </div>
        <div id="tf-fields">
          ${fields.map(([k,def]) => {
            const type = typeof def==='string' ? def : (def.type||'text');
            return `<div class="field-row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center">
              <input class="form-control tf-fkey" style="font-family:monospace;font-size:12px;max-width:150px" value="${esc(k)}" placeholder="field_key">
              <select class="form-control tf-ftype" style="max-width:110px;font-size:12px">
                ${['text','textarea','date','number','select'].map(t=>`<option value="${t}"${type===t?' selected':''}>${t}</option>`).join('')}
              </select>
              <code style="flex:1;font-size:11px;color:var(--slate-l)">{{${esc(k)}}}</code>
              <button type="button" class="btn btn-ghost btn-icon btn-sm" onclick="this.closest('.field-row').remove()">${icon('trash',13)}</button>
            </div>`;
          }).join('')}
        </div>
        <div class="form-hint" style="margin-top:6px">Use <code style="background:var(--surface-1);padding:1px 5px;border-radius:4px;font-size:11px">{{field_key}}</code> in clause bodies for dynamic content</div>
      </div>

      <!-- AI Prompt -->
      <div class="form-group">
        <label>AI Instructions <span class="form-hint" style="display:inline;text-transform:none;letter-spacing:0">optional</span></label>
        <textarea id="tf-ai" class="form-control" rows="2" placeholder="e.g. Apply UAE law. Favour first party.">${esc(tpl.ai_prompt||'')}</textarea>
      </div>`,
      `<button class="btn btn-outline" onclick="closeModal()">Cancel</button>
       <button class="btn btn-primary" onclick="window._saveTpl(${id||'null'})">
         ${icon(id?'save':'plus',14)} ${id ? 'Update' : 'Create'} Template
       </button>`,
      true  // wide modal
    );

    // Populate selected order panel from checked boxes
    window._refreshSelectedPanel();

    // Setup drag-and-drop reorder in selected panel
    window._initDragDrop();
  };

  window._pickerFilter = function(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.picker-row').forEach(row => {
      row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
    });
    document.querySelectorAll('.picker-group').forEach(g => {
      const anyVisible = [...g.querySelectorAll('.picker-row')].some(r => r.style.display !== 'none');
      g.style.display = anyVisible ? '' : 'none';
    });
  };

  window._pickerToggle = function(cb) {
    window._refreshSelectedPanel();
  };

  window._pickerRemove = function(id, el) {
    // Uncheck the matching checkbox
    const cb = document.querySelector('.clause-pick-cb[data-id="'+id+'"]');
    if (cb) cb.checked = false;
    el.remove();
    window._updateSelectedCount();
  };

  window._refreshSelectedPanel = function() {
    const panel    = document.getElementById('selected-clauses');
    if (!panel) return;
    // Collect currently checked, preserving existing order
    const existing = [...panel.querySelectorAll('.selected-clause-item')].map(el => el.dataset.id);
    const checked  = new Set([...document.querySelectorAll('.clause-pick-cb:checked')].map(cb => String(cb.dataset.id)));
    // Remove unchecked from panel
    panel.querySelectorAll('.selected-clause-item').forEach(el => {
      if (!checked.has(el.dataset.id)) el.remove();
    });
    // Add newly checked (not already in panel)
    const inPanel = new Set([...panel.querySelectorAll('.selected-clause-item')].map(el => el.dataset.id));
    document.querySelectorAll('.clause-pick-cb:checked').forEach(cb => {
      if (!inPanel.has(cb.dataset.id)) {
        const div = document.createElement('div');
        div.className = 'selected-clause-item';
        div.draggable = true;
        div.dataset.id = cb.dataset.id;
        div.style.cssText = 'display:flex;align-items:center;gap:6px;padding:6px 8px;background:var(--white);border:1px solid var(--slate-xl);border-radius:6px;margin-bottom:4px;font-size:12px;cursor:grab';
        div.innerHTML = icon('menu',12) +
          '<span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(cb.dataset.title) + '</span>' +
          '<button type="button" onclick="window._pickerRemove(' + cb.dataset.id + ',this.closest(\'.selected-clause-item\'))" style="background:none;border:none;color:var(--slate-l);cursor:pointer;padding:0;line-height:1">×</button>';
        panel.appendChild(div);
        window._initDragDrop();
      }
    });
    window._updateSelectedCount();
  };

  window._updateSelectedCount = function() {
    const el = document.getElementById('selected-count');
    if (el) el.textContent = document.querySelectorAll('.selected-clause-item').length;
  };

  window._initDragDrop = function() {
    const panel = document.getElementById('selected-clauses');
    if (!panel) return;
    let dragged = null;
    panel.querySelectorAll('.selected-clause-item').forEach(item => {
      item.ondragstart = e => { dragged = item; item.style.opacity = '.4'; };
      item.ondragend   = e => { item.style.opacity = '1'; dragged = null; };
      item.ondragover  = e => { e.preventDefault(); };
      item.ondrop      = e => {
        e.preventDefault();
        if (dragged && dragged !== item) panel.insertBefore(dragged, item);
      };
    });
  };

  window._addTplField = function() {
    const n   = document.querySelectorAll('.field-row').length + 1;
    const row = document.createElement('div');
    row.className = 'field-row';
    row.style.cssText = 'display:flex;gap:6px;margin-bottom:6px;align-items:center';
    row.innerHTML = `
      <input class="form-control tf-fkey" style="font-family:monospace;font-size:12px;max-width:150px" value="field_${n}" placeholder="field_key">
      <select class="form-control tf-ftype" style="max-width:110px;font-size:12px">
        ${['text','textarea','date','number','select'].map(t=>`<option value="${t}">${t}</option>`).join('')}
      </select>
      <code style="flex:1;font-size:11px;color:var(--slate-l)">{{field_${n}}}</code>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" onclick="this.closest('.field-row').remove()">${icon('trash',13)}</button>`;
    document.getElementById('tf-fields').appendChild(row);
  };

  window._saveTpl = async function(id) {
    // Collect ordered clause IDs from selected panel
    const clauseIds = [...document.querySelectorAll('.selected-clause-item')].map(el => parseInt(el.dataset.id));
    // Collect questionnaire schema
    const schema = {};
    document.querySelectorAll('.field-row').forEach(row => {
      const k = row.querySelector('.tf-fkey')?.value.trim().replace(/\W+/g,'_');
      const t = row.querySelector('.tf-ftype')?.value;
      if (k) schema[k] = t;
    });
    const data = {
      name:                 document.getElementById('tf-name').value,
      name_ar:              document.getElementById('tf-name-ar').value,
      category:             document.getElementById('tf-cat').value,
      language:             document.getElementById('tf-lang').value,
      ai_prompt:            document.getElementById('tf-ai').value,
      questionnaire_schema: schema,
      clause_ids:           clauseIds,
      contract_body:        '', // assembled server-side from clauses
    };
    if (!clauseIds.length && !data.contract_body) {
      toast('Select at least one clause', 'error'); return;
    }
    const d = id ? await API.templates.update(id, data) : await API.templates.create(data);
    if (d.success) { toast(id ? 'Template updated' : 'Template created','success'); closeModal(); Pages.templates(); }
    else { if (d.errors) showErrors(d.errors); else toast(d.message||'Save failed','error'); }
  };
};

// ── COUNTERPARTIESES ────────────────────────────────────────────
Pages.counterparties = async function(params) {
  params = params || {};
  setTitle('Counterparties'); showLoading();
  const d = await API.counterparties.list({ page: params.page || 1, q: params.q || '' });
  hideLoading();
  const rows = (d.data && d.data.data) ? d.data.data : [];
  const meta = d.data && d.data.pagination;

  setPage(`
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
      <div style="display:flex;gap:6px">
        <input id="cp-q" class="form-control" style="width:220px" placeholder="Search companies…" value="${esc(params.q||'')}">
        <button class="btn btn-outline btn-sm" onclick="App.go('counterparties',{q:document.getElementById('cp-q').value})">Search</button>
        ${params.q ? `<button class="btn btn-ghost btn-sm" onclick="App.go('counterparties')">Clear</button>` : ''}
      </div>
      <button class="btn btn-primary" onclick="window._openCpForm()">${icon('plus',16)} Add Counterparty</button>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Company</th><th>Arabic Name</th><th>Country</th><th>Email</th><th>Actions</th></tr>
          </thead>
          <tbody>
            ${!rows.length ? `<tr><td colspan="5"><div class="empty">
              <div class="empty-icon">🏢</div>
              <h3>No counterparties yet</h3>
              <button class="btn btn-primary btn-sm" onclick="window._openCpForm()">Add First</button>
            </div></td></tr>` :
            rows.map(cp => `
              <tr>
                <td class="fw-600">${esc(cp.company_name)}</td>
                <td style="direction:rtl;text-align:right">${esc(cp.company_name_ar||'—')}</td>
                <td class="text-muted">${esc(cp.country)}</td>
                <td class="text-muted">${esc(cp.email||'—')}</td>
                <td>
                  <div style="display:flex;gap:4px">
                    <button class="btn btn-ghost btn-icon btn-sm" onclick="window._openCpForm(${cp.id})" title="Edit">${icon('edit',14)}</button>
                    <button class="btn btn-danger btn-icon btn-sm" onclick="window._delCp(${cp.id},this)" title="Delete">${icon('trash',14)}</button>
                  </div>
                </td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
      ${renderPagination(meta, `App.go.bind(null,'counterparties',{q:'${esc(params.q||'')}',page:`)}}
    </div>`);

  window._delCp = async function(id, btn) {
    if (!confirm('Delete this counterparty?')) return;
    btn.disabled = true;
    const d = await API.counterparties.delete(id);
    if (d.success) { toast('Deleted','success'); Pages.counterparties(); }
    else { toast(d.message,'error'); btn.disabled=false; }
  };

  window._openCpForm = async function(id) {
    let cp = {};
    if (id) {
      showLoading(); const d = await API.counterparties.get(id); hideLoading();
      if (d.success) cp = d.data;
    }
    const countries = [['AE','UAE'],['SA','Saudi Arabia'],['KW','Kuwait'],['QA','Qatar'],['BH','Bahrain'],['OM','Oman'],['EG','Egypt'],['JO','Jordan'],['GB','UK'],['US','USA']];
    showModal(
      id ? 'Edit Counterparty' : 'Add Counterparty',
      `<div class="form-row">
        <div class="form-group"><label>Company Name (EN) *</label><input id="cf-name" class="form-control" value="${esc(cp.company_name||'')}" required></div>
        <div class="form-group"><label>Company Name (AR)</label><input id="cf-name-ar" class="form-control" dir="rtl" value="${esc(cp.company_name_ar||'')}"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Registration No. <span class="text-muted" style="font-size:10px">(encrypted)</span></label><input id="cf-reg" class="form-control" value="${esc(cp.reg_number||'')}"></div>
        <div class="form-group"><label>Tax / VAT No. <span class="text-muted" style="font-size:10px">(encrypted)</span></label><input id="cf-tax" class="form-control" value="${esc(cp.tax_number||'')}"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Country *</label>
          <select id="cf-country" class="form-control">
            ${countries.map(([k,v])=>`<option value="${k}"${(cp.country||'AE')===k?' selected':''}>${v}</option>`).join('')}
          </select>
        </div>
        <div class="form-group"><label>Email</label><input id="cf-email" class="form-control" value="${esc(cp.email||'')}"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Signatory Name <span class="text-muted" style="font-size:10px">(encrypted)</span></label><input id="cf-sig-name" class="form-control" value="${esc(cp.signatory_name||'')}"></div>
        <div class="form-group"><label>Signatory Title</label><input id="cf-sig-title" class="form-control" value="${esc(cp.signatory_title||'')}"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Address</label><input id="cf-addr" class="form-control" value="${esc(cp.address||'')}"></div>
        <div class="form-group"><label>Phone</label><input id="cf-phone" class="form-control" value="${esc(cp.phone||'')}"></div>
      </div>`,
      `<button class="btn btn-outline" onclick="closeModal()">Cancel</button>
       <button class="btn btn-primary" onclick="window._saveCp(${id||'null'})">Save</button>`
    );
  };

  window._saveCp = async function(id) {
    const data = {
      company_name: document.getElementById('cf-name').value,
      company_name_ar: document.getElementById('cf-name-ar').value,
      reg_number: document.getElementById('cf-reg').value,
      tax_number: document.getElementById('cf-tax').value,
      country: document.getElementById('cf-country').value,
      email: document.getElementById('cf-email').value,
      signatory_name: document.getElementById('cf-sig-name').value,
      signatory_title: document.getElementById('cf-sig-title').value,
      address: document.getElementById('cf-addr').value,
      phone: document.getElementById('cf-phone').value,
    };
    const d = id ? await API.counterparties.update(id, data) : await API.counterparties.create(data);
    if (d.success) { toast(id?'Updated':'Created','success'); closeModal(); Pages.counterparties(); }
    else { if (d.errors) showErrors(d.errors); else toast(d.message||'Save failed','error'); }
  };
};

// ── TEAM ──────────────────────────────────────────────────────
Pages.team = async function() {
  setTitle('Team Members'); showLoading();
  const d = await API.users.list();
  hideLoading();
  const rows     = (d.data && d.data.data) ? d.data.data : [];
  const me       = API.store.user;
  const canManage = me && (me.role === 'owner' || me.role === 'admin');

  setPage(`
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <span style="color:var(--slate);font-size:13px">${rows.length} member${rows.length!==1?'s':''}</span>
      ${canManage ? `<button class="btn btn-primary btn-sm" onclick="window._openInvite()">${icon('plus',14)} Invite Member</button>` : ''}
    </div>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th>${canManage?'<th></th>':''}</tr>
          </thead>
          <tbody>
            ${rows.map(u => `
              <tr id="urow-${u.id}">
                <td class="fw-600">${esc(u.full_name)} ${u.id===(me&&me.id)?'<span class="badge badge-lawyer" style="font-size:10px">You</span>':''}</td>
                <td class="text-muted">${esc(u.email)}</td>
                <td><span class="badge badge-${esc(u.role)}">${esc(u.role)}</span></td>
                <td><span id="ustatus-${u.id}" class="badge ${u.is_active?'badge-active':'badge-past_due'}">${u.is_active?'Active':'Disabled'}</span></td>
                <td class="text-muted">${fmtDate(u.last_login_at)}</td>
                ${canManage ? `<td>${u.id!==(me&&me.id) ? `<button class="btn btn-outline btn-sm" id="utoggle-${u.id}" onclick="window._toggleUser(${u.id})">${u.is_active?'Disable':'Enable'}</button>` : ''}</td>` : ''}
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>`);

  window._openInvite = function() {
    showModal(
      'Invite Team Member',
      `<div id="inv-msg" style="margin-bottom:8px"></div>
       <div class="form-group"><label>Email Address *</label><input id="inv-email" class="form-control" type="email" placeholder="colleague@company.com" autofocus></div>
       <div class="form-group"><label>Role</label>
         <select id="inv-role" class="form-control">
           ${me && me.role==='owner' ? '<option value="admin">Admin – can manage templates & users</option>' : ''}
           <option value="lawyer">Lawyer – can create & edit contracts</option>
         </select>
       </div>`,
      `<button class="btn btn-outline" onclick="closeModal()">Cancel</button>
       <button class="btn btn-primary" onclick="window._sendInvite()">Send Invitation</button>`
    );
  };

  window._sendInvite = async function() {
    const email = document.getElementById('inv-email').value.trim();
    const role  = document.getElementById('inv-role').value;
    const d = await API.users.invite({ email, role });
    const el = document.getElementById('inv-msg');
    if (d.success) {
      const link = d.data && d.data.invite_link ? d.data.invite_link : null;
      el.innerHTML = `<div style="background:#f0fdf4;border:1px solid #86efac;color:#14532d;border-radius:8px;padding:10px 12px;font-size:13px">
        ✅ Invitation created for <strong>${esc(email)}</strong>.<br><br>
        ${link
          ? `<strong>Share this link with the user:</strong><br>
             <a href="${esc(link)}" target="_blank" style="word-break:break-all;color:#1a3c5e;font-size:12px">${esc(link)}</a>`
          : 'Invite email sent (if mail server is configured).'}
      </div>`;
      document.getElementById('inv-email').value = '';
    } else {
      el.innerHTML = `<div style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:10px 12px;font-size:13px">❌ ${esc(d.message)}</div>`;
    }
  };

  window._toggleUser = async function(id) {
    const btn = document.getElementById('utoggle-'+id);
    if (btn) btn.disabled = true;
    const d = await API.users.toggle(id);
    if (d.success) {
      const badge = document.getElementById('ustatus-'+id);
      const active = d.data.is_active;
      if (badge) { badge.textContent = active?'Active':'Disabled'; badge.className=`badge ${active?'badge-active':'badge-past_due'}`; }
      if (btn) { btn.disabled=false; btn.textContent=active?'Disable':'Enable'; }
    } else { toast(d.message,'error'); if(btn) btn.disabled=false; }
  };
};

// ── SETTINGS ──────────────────────────────────────────────────
Pages.settings = async function() {
  setTitle('Settings'); showLoading();
  const d = await API.users.getSettings();
  hideLoading();
  if (!d.success) { setPage('<p class="text-muted">Failed to load settings.</p>'); return; }

  const { tenant, sub } = d.data;
  const me = API.store.user;

  setPage(`
    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">

      <div class="card" style="flex:1;min-width:280px">
        <div class="card-hd">Workspace Settings</div>
        <div class="card-body">
          <div id="ws-msg" style="margin-bottom:8px"></div>
          <div class="form-row">
            <div class="form-group"><label>Company Name *</label><input id="ws-name" class="form-control" value="${esc(tenant.name)}"></div>
            <div class="form-group"><label>Language</label>
              <select id="ws-lang" class="form-control">
                <option value="en" ${tenant.language==='en'?'selected':''}>English (LTR)</option>
                <option value="ar" ${tenant.language==='ar'?'selected':''}>Arabic / عربي (RTL)</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Timezone</label>
              <select id="ws-tz" class="form-control">
                ${[['Asia/Dubai','UAE (GST +4)'],['Asia/Riyadh','Saudi Arabia (+3)'],['Asia/Kuwait','Kuwait (+3)'],
                   ['Asia/Qatar','Qatar (+3)'],['Asia/Muscat','Oman (+4)'],['UTC','UTC']]
                  .map(([v,l])=>`<option value="${v}"${tenant.timezone===v?' selected':''}>${l}</option>`).join('')}
              </select>
            </div>
            <div class="form-group"><label>Brand Color</label>
              <input id="ws-color" type="color" class="form-control color-input" value="${esc(tenant.primary_color||'#1a3c5e')}">
            </div>
          </div>
          <div class="form-group">
            <label>AI Instructions <span class="text-muted">(applied to every contract)</span></label>
            <textarea id="ws-ai" class="form-control" rows="4"
              placeholder="e.g. Always use UAE law. Include DIAC arbitration. Use AED as currency.">${esc(tenant.ai_prompt||'')}</textarea>
          </div>
          <div class="form-group">
            <label>Logo <span class="text-muted" style="font-size:11px">PNG/JPG/WebP max 2MB</span></label>
            <input type="file" id="ws-logo" class="form-control" accept="image/jpeg,image/png,image/webp">
            ${tenant.logo_path ? `<img src="${API.BASE}/uploads/${esc(tenant.logo_path)}" height="38" style="margin-top:8px;border-radius:4px" alt="Logo">` : ''}
          </div>
          <button class="btn btn-primary" onclick="window._saveWS()">Save Workspace Settings</button>
        </div>
      </div>

      <div style="width:280px;min-width:240px">
        <div class="card mb-3">
          <div class="card-hd">My Profile</div>
          <div class="card-body">
            <div id="prof-msg" style="margin-bottom:8px"></div>
            <div class="form-group"><label>Full Name</label><input id="prof-name" class="form-control" value="${esc(me&&me.full_name||'')}"></div>
            <div class="form-group">
              <label>Email</label>
              <input class="form-control" value="${esc(me&&me.email||'')}" style="background:var(--surface);color:var(--slate-l)" readonly>
            </div>
            <hr style="border:none;border-top:1px solid var(--slate-xl);margin:12px 0">
            <div style="font-size:12px;font-weight:600;color:var(--slate);margin-bottom:8px">Change Password</div>
            <div class="form-group"><label>Current Password</label><input id="prof-cur" type="password" class="form-control"></div>
            <div class="form-group"><label>New Password</label><input id="prof-new" type="password" class="form-control"></div>
            <div class="form-group"><label>Confirm New Password</label><input id="prof-conf" type="password" class="form-control"></div>
            <button class="btn btn-primary w-100 btn-sm" onclick="window._saveProfile()">Update Profile</button>
          </div>
        </div>

        <div class="card">
          <div class="card-hd">Plan &amp; Usage</div>
          <div class="card-body">
            ${sub ? `
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
                <span class="fw-600">${esc(sub.plan_name)}</span>
                <span class="badge badge-${esc(sub.status)}">${esc(sub.status)}</span>
              </div>
              <div style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                  <span>Contracts</span><span>${sub.contracts_used} / ${sub.max_contracts}</span>
                </div>
                ${quotaBar(sub.contracts_used, sub.max_contracts)}
              </div>
              <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                  <span>AI Calls</span><span>${sub.ai_calls_used} / ${sub.max_ai_calls}</span>
                </div>
                ${quotaBar(sub.ai_calls_used, sub.max_ai_calls)}
              </div>
              <a href="mailto:sales@contractai.com?subject=Upgrade%20Plan" class="btn btn-gold w-100 btn-sm">Upgrade Plan</a>
            ` : '<p class="text-muted" style="font-size:13px">No subscription found</p>'}
          </div>
        </div>
      </div>
    </div>`);

  window._saveWS = async function() {
    const btn = document.querySelector('[onclick="window._saveWS()"]');
    btn.disabled=true; btn.textContent='Saving…';

    const logo = document.getElementById('ws-logo').files[0];
    if (logo) {
      const fd = new FormData(); fd.append('logo', logo);
      await API.users.uploadLogo(fd);
    }

    const d = await API.users.saveSettings({
      name:          document.getElementById('ws-name').value,
      language:      document.getElementById('ws-lang').value,
      timezone:      document.getElementById('ws-tz').value,
      primary_color: document.getElementById('ws-color').value,
      ai_prompt:     document.getElementById('ws-ai').value,
    });

    btn.disabled=false; btn.textContent='Save Workspace Settings';
    if (d.success) { toast('Saved!','success'); }
    else toast(d.message||'Save failed','error');
  };

  window._saveProfile = async function() {
    const d = await API.users.updateProfile({
      full_name:             document.getElementById('prof-name').value,
      current_password:      document.getElementById('prof-cur').value,
      new_password:          document.getElementById('prof-new').value,
      new_password_confirmation: document.getElementById('prof-conf').value,
    });
    const el = document.getElementById('prof-msg');
    if (d.success) {
      el.innerHTML = `<div style="background:#f0fdf4;border:1px solid #86efac;color:#14532d;border-radius:8px;padding:9px 12px;font-size:13px">✅ Profile updated</div>`;
      document.getElementById('prof-cur').value = '';
      document.getElementById('prof-new').value = '';
      document.getElementById('prof-conf').value = '';
    } else {
      if (d.errors) showErrors(d.errors);
      else el.innerHTML = `<div style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:9px 12px;font-size:13px">❌ ${esc(d.message)}</div>`;
    }
  };
};

// ════════════════════════════════════════════════════════════
// SHELL (sidebar + topbar) — rendered once after login
// ════════════════════════════════════════════════════════════
function renderShell(user) {
  const u        = user || {};
  const initials = (u.full_name||'U').split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase();

  const navItems = [
    { r:'dashboard',      l:'Dashboard',      i:'grid',    section: 'workspace' },
    { r:'contracts',      l:'Contracts',      i:'file',    section: 'workspace' },
    { r:'clauses',        l:'Clause Library', i:'book',    section: 'workspace' },
    { r:'templates',      l:'Templates',      i:'layout',  section: 'workspace' },
    { r:'counterparties', l:'Counterparties', i:'users',   section: 'workspace' },
    { r:'team',           l:'Team',           i:'users',   section: 'manage'    },
    { r:'settings',       l:'Settings',       i:'settings',section: 'manage'    },
  ];

  const workspaceItems = navItems.filter(n => n.section === 'workspace');
  const manageItems    = navItems.filter(n => n.section === 'manage');

  document.getElementById('app').innerHTML = `
    <nav class="sidebar" id="sidebar">
      <a class="sidebar-brand" href="#/dashboard">
        <div class="brand-icon">C</div>
        <div>
          <div class="brand-name">ContractAI</div>
          <div class="brand-sub">Legal Intelligence</div>
        </div>
      </a>

      <div class="nav-section">
        <div class="nav-label">Workspace</div>
        ${workspaceItems.map(n => `
          <div class="nav-item" id="nav-${n.r}" onclick="App.go('${n.r}')">
            ${icon(n.i, 16)} <span>${n.l}</span>
          </div>`).join('')}
      </div>

      <div class="nav-section">
        <div class="nav-label">Manage</div>
        ${manageItems.map(n => `
          <div class="nav-item" id="nav-${n.r}" onclick="App.go('${n.r}')">
            ${icon(n.i, 16)} <span>${n.l}</span>
          </div>`).join('')}
      </div>

      <div class="sidebar-footer">
        <div class="user-chip">
          <div class="user-avatar">${initials}</div>
          <div class="user-info">
            <div class="user-name">${esc(u.full_name||'User')}</div>
            <div class="user-role">${esc(u.role||'')}</div>
          </div>
          <button class="logout-btn" onclick="App.logout()" title="Sign out">${icon('logout',16)}</button>
        </div>
      </div>
    </nav>

    <div class="main" id="main">
      <div class="topbar">
        <button class="btn btn-ghost btn-icon" id="mob-menu-btn"
          onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebar-overlay').classList.toggle('hidden')"
          style="display:none">
          ${icon('menu')}
        </button>
        <div class="topbar-title" id="page-title">Dashboard</div>
        <div class="topbar-actions">
          <button class="btn btn-gold btn-sm" onclick="App.go('contracts/new')">
            ${icon('zap',14)} New Contract
          </button>
        </div>
      </div>
      <div class="page" id="page-content">
        <div style="text-align:center;padding:60px 20px;color:var(--slate-l)">Loading…</div>
      </div>
    </div>

    <div id="sidebar-overlay" class="hidden" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.add('hidden')"
      style="position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:199;backdrop-filter:blur(2px)"></div>`;

  const handleResize = () => {
    const btn = document.getElementById('mob-menu-btn');
    if (btn) btn.style.display = window.innerWidth < 768 ? '' : 'none';
  };
  handleResize();
  window.addEventListener('resize', handleResize);
}

function updateNav(route) {
  document.querySelectorAll('.nav-item').forEach(el => {
    const r = el.id ? el.id.replace('nav-','') : '';
    el.classList.toggle('active', route === r || route.startsWith(r + '/'));
  });
}

// ════════════════════════════════════════════════════════════
// ROUTER
// ════════════════════════════════════════════════════════════

// Routes map — Pages is fully defined above so this is safe
const ROUTES = {
  'login':          Pages.login,
  'register':       Pages.register,
  'forgot':         Pages.forgot,
  'reset-password': Pages.resetPassword,
  'verified':       Pages.verified,
  'accept-invite':  Pages.acceptInvite,
  'dashboard':      Pages.dashboard,
  'contracts':      Pages.contracts,
  'contracts/new':  Pages.contractWizard,
  'contract':       Pages.contractEditor,
  'clauses':        Pages.clauses,
  'templates':      Pages.templates,
  'counterparties': Pages.counterparties,
  'team':           Pages.team,
  'settings':       Pages.settings,
};

const PUBLIC_ROUTES = new Set(['login','register','forgot','reset-password','verified','accept-invite']);

const App = {
  /** Navigate to a route */
  go(route, params) {
    const qs = params && Object.keys(params).length
      ? '?' + new URLSearchParams(params).toString()
      : '';
    window.location.hash = '#/' + route + qs;
  },

  /** Parse current hash into { route, params } */
  parse() {
    const hash   = window.location.hash.replace(/^#\/?/, '');
    const [path, qs] = hash.split('?');
    return { route: path || 'login', params: Object.fromEntries(new URLSearchParams(qs||'')) };
  },

  /** Main render dispatcher */
  async render() {
    const { route, params } = this.parse();

    // ── Public route ─────────────────────────────────────────
    if (PUBLIC_ROUTES.has(route)) {
      document.getElementById('app').innerHTML = '';
      const fn = ROUTES[route];
      if (fn) fn.call(Pages, params);
      else this.go('login');
      return;
    }

    // ── Auth guard ───────────────────────────────────────────
    if (!API.store.isLoggedIn()) {
      this.go('login');
      return;
    }

    // ── Render shell once ────────────────────────────────────
    if (!document.getElementById('sidebar')) {
      renderShell(API.store.user);
    }
    updateNav(route);

    // ── Render page ──────────────────────────────────────────
    const fn = ROUTES[route] || ROUTES['dashboard'];
    if (fn) await fn.call(Pages, params);
  },

  async logout() {
    showLoading('Signing out…');
    await API.auth.logout();
    hideLoading();
    document.getElementById('app').innerHTML = '';
    this.go('login');
  }
};

// ── Boot ─────────────────────────────────────────────────────
window.addEventListener('hashchange', () => App.render());

document.addEventListener('DOMContentLoaded', () => {
  const hash = window.location.hash;
  // If no hash or just '#', decide where to send user
  if (!hash || hash === '#' || hash === '#/') {
    App.go(API.store.isLoggedIn() ? 'dashboard' : 'login');
  } else {
    App.render();
  }
});
