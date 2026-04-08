import { getSession, logout, apiGet, apiPost } from "./storage.js";

/* =========================
THEME (default: LIGHT)
========================= */

export function initTheme() {
  const theme = localStorage.getItem("theme") || "light";
  document.documentElement.setAttribute("data-theme", theme);
}

export function toggleTheme() {
  const current = document.documentElement.getAttribute("data-theme") || "light";
  const next = current === "dark" ? "light" : "dark";
  document.documentElement.setAttribute("data-theme", next);
  localStorage.setItem("theme", next);
}

/* =========================
AUTH
========================= */

export function requireAuth() {
  initTheme();
  const session = getSession();
  if (!session) {
    location.href = "./login.html";
    return null;
  }
  return session;
}

export async function currentUser() {
  try {
    const data = await apiGet('../api/auth.php?action=me');
    return data.user;
  } catch (e) {
    return null;
  }
}

export async function updateCurrentUser(patch) {
  await apiPost('../api/auth.php?action=update_profile', patch);
}

/* =========================
SCHOOL SETTINGS + ACTIVITIES
========================= */

export async function schoolSettings() {
  try {
    return await apiGet('../api/settings.php?action=school');
  } catch (e) {
    return { name: "My School Name", address: "School Address Here" };
  }
}

export async function saveSchoolSettings({ name, address }) {
  await apiPost('../api/settings.php?action=save_school', { name, address });
}

export async function addActivity({ title, date }) {
  await apiPost('../api/settings.php?action=add_activity', { title, date });
}

export async function getActivities() {
  try {
    return await apiGet('../api/settings.php?action=activities');
  } catch (e) {
    return [];
  }
}

/* =========================
SIDEBAR
========================= */

export async function renderSidebar() {
  initTheme();
  const user = await currentUser();
  const avatar = user?.avatarDataUrl || "../assets/images/logo.png";
  const theme = document.documentElement.getAttribute("data-theme") || "light";

  const el = document.getElementById("sidebar");
  if (!el) return;

  el.innerHTML = `
    <div class="theme-toggle">
      <div style="font-weight:900">Theme</div>
      <button id="themeBtn" type="button">
        ${theme === "dark" ? "Dark" : "Light"}
      </button>
    </div>

    <div class="profile-mini">
      <div class="avatar"><img src="${avatar}" alt="avatar"></div>
      <div class="meta">
        <div class="name">${user?.fullName || "User"}</div>
        <div class="email">${user?.email || ""}</div>
      </div>
    </div>

    <nav class="nav">
      <a href="./home.html">Home</a>
      <a href="./profile.html">Edit Profile</a>
      ${user?.role === 'student' ? `
      <a href="./pay-fees.html">Pay Fees</a>
      <a href="./my-invoices.html">My Invoices</a>
      <a href="./documents.html">Print Documents</a>
      ` : ''}
      ${user?.role === 'admin' ? `
      <a href="./terms.html">Academic Session</a>
      <a href="./manage-settings.html">App Settings</a>
      <a href="./students.html">Manage Students</a>
      <a href="./fees-config.html">Fee Details</a>
      <a href="./payments.html">Payment Approval</a>
      <a href="./tracking.html">Payment Tracking</a>
      <a href="./reports.html">Reports & Stats</a>
      <a href="./invoice.html">Invoices</a>
      ` : ''}
      <a href="#" id="logoutBtn" class="danger">Logout</a>
    </nav>

    <div class="card">
      <b>School Activities</b>
      <div id="activityList" class="muted" style="margin-top:8px"></div>
    </div>
  `;

  document.getElementById("logoutBtn")?.addEventListener("click", (e) => {
    e.preventDefault();
    logout();
  });

  document.getElementById("themeBtn")?.addEventListener("click", () => {
    toggleTheme();
    renderSidebar();
  });

  const acts = await getActivities();
  const list = document.getElementById("activityList");
  if (list) {
    list.innerHTML = acts.length
      ? acts.slice(0, 6).map(a => `
          <div style="margin-bottom:8px">
            <b>${a.title || ""}</b>
            <div class="muted">${a.date || ""}</div>
          </div>
        `).join("")
      : "No activities yet.";
  }
}