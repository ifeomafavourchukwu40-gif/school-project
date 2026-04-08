export const db = {
  read(key, fallback) {
    try {
      const item = localStorage.getItem(key);
      return item ? JSON.parse(item) : fallback;
    } catch (e) {
      return fallback;
    }
  },
  write(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
    }
  }
};

export function getSession() {
  return db.read("session", null);
}

export function setSession(session) {
  db.write("session", session);
}

export async function logout() {
  try {
    await fetch('../api/auth.php?action=logout', { method: 'POST' });
  } catch(e) {}
  localStorage.removeItem("session");
  location.href = "../pages/login.html";
}

// Base fetch utilities hitting the real PHP backend
export async function apiGet(url) {
  const finalUrl = url.includes('?') ? `${url}&_t=${Date.now()}` : `${url}?_t=${Date.now()}`;
  const res = await fetch(finalUrl, { headers: { 'Cache-Control': 'no-store, no-cache, must-revalidate' } });
  const text = await res.text();
  try {
    const data = JSON.parse(text);
    if (!res.ok) throw new Error(data.error || res.statusText);
    return data;
  } catch(e) {
    if (!res.ok) throw new Error(res.statusText || 'Server Error');
    throw e;
  }
}

export async function apiPost(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  const text = await res.text();
  try {
    const data = JSON.parse(text);
    if (!res.ok) throw new Error(data.error || res.statusText);
    return data;
  } catch(e) {
    if (!res.ok) throw new Error(res.statusText || 'Server Error');
    throw e;
  }
}