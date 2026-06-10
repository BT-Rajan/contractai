/**
 * ContractAI – API Client
 * Centralized fetch layer. Auto token refresh. No hardcoded URLs.
 */

const API = (() => {

  // ── Base URL detection ──────────────────────────────────────
  // Works in any XAMPP subfolder by reading the script's own src path
  function detectBase() {
    // Find this script's src to extract base path
    const scripts = Array.from(document.querySelectorAll('script[src]'));
    for (const s of scripts) {
      // Look for api.js path like: /contractai/assets/js/api.js
      const match = s.src.match(/^(https?:\/\/[^\/]+)(\/.*?)\/assets\/js\/api\.js/i);
      if (match) return match[1] + match[2]; // e.g. http://localhost/contractai
    }
    // Fallback: strip /assets/js/api.js from current script URL
    const cur = document.currentScript?.src || '';
    if (cur) return cur.replace(/\/assets\/js\/api\.js.*$/, '');
    // Last resort
    return window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
  }

  const BASE = detectBase();

  // ── Token storage ────────────────────────────────────────────
  const store = {
    get access()  { return sessionStorage.getItem('cai_at') || localStorage.getItem('cai_at') || ''; },
    get refresh() { return localStorage.getItem('cai_rt') || ''; },
    get user()    {
      try { return JSON.parse(localStorage.getItem('cai_user') || 'null'); }
      catch { return null; }
    },
    save(tokens, user) {
      if (tokens?.access_token) {
        sessionStorage.setItem('cai_at', tokens.access_token);
        localStorage.setItem('cai_at', tokens.access_token);
      }
      if (tokens?.refresh_token) localStorage.setItem('cai_rt', tokens.refresh_token);
      if (user) localStorage.setItem('cai_user', JSON.stringify(user));
    },
    clear() {
      ['cai_at','cai_user'].forEach(k => { sessionStorage.removeItem(k); localStorage.removeItem(k); });
      localStorage.removeItem('cai_rt');
    },
    isLoggedIn() { return !!this.access; }
  };

  // ── Core request ─────────────────────────────────────────────
  let refreshPromise = null;

  async function req(path, opts = {}) {
    const url     = `${BASE}/api/${path}`;
    const headers = { Accept: 'application/json' };

    if (!(opts.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }
    if (store.access) headers['Authorization'] = 'Bearer ' + store.access;

    let res = await fetch(url, { ...opts, headers });

    // Auto-refresh on 401
    if (res.status === 401 && store.refresh) {
      if (!refreshPromise) refreshPromise = doRefresh();
      const ok = await refreshPromise;
      refreshPromise = null;
      if (ok) {
        headers['Authorization'] = 'Bearer ' + store.access;
        res = await fetch(url, { ...opts, headers });
      } else {
        store.clear();
        App.go('login');
        return { success: false, message: 'Session expired. Please log in again.' };
      }
    }

    const ct = res.headers.get('content-type') || '';
    if (!ct.includes('application/json')) return res; // binary (PDF etc.)

    let data;
    try { data = await res.json(); }
    catch { data = { success: false, message: 'Invalid server response' }; }
    return data;
  }

  async function doRefresh() {
    try {
      const r = await fetch(`${BASE}/api/auth.php?action=refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: store.refresh })
      });
      const d = await r.json();
      if (d.success) { store.save(d.data, null); return true; }
    } catch {}
    return false;
  }

  // ── Method shorthands ────────────────────────────────────────
  const get  = (path)       => req(path, { method: 'GET' });
  const post = (path, body) => req(path, {
    method: 'POST',
    body: body instanceof FormData ? body : JSON.stringify(body || {})
  });
  const put  = (path, body) => req(path, { method: 'PUT',    body: JSON.stringify(body || {}) });
  const del  = (path)       => req(path, { method: 'DELETE' });

  // ── Auth ─────────────────────────────────────────────────────
  const auth = {
    async login(email, password) {
      const d = await post('auth.php?action=login', { email, password });
      if (d.success && d.data) store.save(d.data, d.data.user);
      return d;
    },
    register : (data)  => post('auth.php?action=register', data),
    async logout() {
      try { await post('auth.php?action=logout', { refresh_token: store.refresh }); } catch {}
      store.clear();
    },
    me       : ()      => get('auth.php?action=me'),
    forgot   : (email) => post('auth.php?action=forgot', { email }),
    reset    : (data)  => post('auth.php?action=reset', data),
  };

  // ── Contracts ─────────────────────────────────────────────────
  const contracts = {
    list     : (p = {}) => get('contracts.php?' + new URLSearchParams(p)),
    get      : (id)     => get(`contracts.php?id=${id}`),
    generate : (data)   => post('contracts.php', data),
    save     : (id, d)  => post(`contracts.php?id=${id}`, { ...d, _method: 'PUT' }),
    finalize : (id)     => post(`contracts.php?action=finalize&id=${id}`, {}),
    delete   : (id)     => post(`contracts.php?id=${id}`, { _method: 'DELETE' }),
    pdfUrl      : (id, lang = 'en') => `${BASE}/api/contracts.php?action=pdf&id=${id}&lang=${lang}`,
    pdfDownload : async (id, lang = 'en', filename = 'contract') => {
      // Use req() so auth headers, token refresh and error handling
      // are all handled consistently with every other API call.
      const res = await req(
        `contracts.php?action=pdf&id=${id}&lang=${lang}`,
        { method: 'GET', headers: { Accept: 'application/pdf' } }
      );

      // req() returns raw Response for non-JSON content types (line ~80 in req)
      if (res instanceof Response) {
        if (!res.ok) {
          let msg = `HTTP ${res.status}`;
          try { const e = await res.json(); msg = e.message || msg; } catch {}
          console.error('[PDF] Server error:', res.status);
          return { success: false, message: msg };
        }
        const blob = await res.blob();
        const a    = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = `${filename.replace(/[^a-z0-9_\-]/gi,'_')}_${lang}.pdf`;
        document.body.appendChild(a); a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
        return { success: true };
      }

      // res is already a parsed JSON error object (auth failure, 404, etc.)
      console.error('[PDF] API error:', res);
      return { success: false, message: res.message || 'PDF generation failed' };
    },
  };

  // ── Templates ─────────────────────────────────────────────────
  const templates = {
    list   : (p = {}) => get('templates.php?' + new URLSearchParams(p)),
    get    : (id)     => get(`templates.php?id=${id}`),
    create : (data)   => post('templates.php', data),
    update : (id, d)  => post(`templates.php?id=${id}`, { ...d, _method: 'PUT' }),
    delete : (id)     => post(`templates.php?id=${id}`, { _method: 'DELETE' }),
  };

  // ── Counterparties ────────────────────────────────────────────
  const counterparties = {
    list   : (p = {}) => get('counterparties.php?' + new URLSearchParams(p)),
    get    : (id)     => get(`counterparties.php?id=${id}`),
    create : (data)   => post('counterparties.php', data),
    update : (id, d)  => post(`counterparties.php?id=${id}`, { ...d, _method: 'PUT' }),
    delete : (id)     => post(`counterparties.php?id=${id}`, { _method: 'DELETE' }),
  };

  // ── Users / Settings / Dashboard ─────────────────────────────
  const users = {
    dashboard    : ()     => get('users.php?action=dashboard'),
    list         : ()     => get('users.php?action=list'),
    invite       : (d)    => post('users.php?action=invite', d),
    acceptInvite : (d)    => post('users.php?action=accept', d),
    toggle       : (id)   => post(`users.php?action=toggle`, { id }),
    updateProfile: (d)    => post('users.php?action=profile', d),
    getSettings  : ()     => get('users.php?action=settings'),
    saveSettings : (d)    => post('users.php?action=settings', d),
    uploadLogo   : (fd)   => post('users.php?action=upload_logo', fd),
  };

  return { BASE, store, auth, contracts, templates, counterparties, users };

})();
