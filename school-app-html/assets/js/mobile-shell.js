import { currentUser, getActivities, initTheme, toggleTheme, renderSidebar } from "./app.js";
import { logout } from "./storage.js";

const TITLES = {
  home: { title: "Dashboard", sub: "Welcome back" },
  profile: { title: "Profile", sub: "Edit your details" },
  term: { title: "Terms", sub: "Select a term" },
  enroll: { title: "Manage Students", sub: "Manage users" },
  invoice: { title: "Invoices", sub: "Manage payments" },
  delete: { title: "Delete Account", sub: "Confirm password" },
};

export async function mountMobileShell(activeKey = "home") {
  const host = document.getElementById("mobileShell");
  if (!host) return;

  // Load mobile header
  const headerRes = await fetch("../pages/components/mobile-shell.html");
  host.innerHTML = await headerRes.text();

  // Apply theme
  initTheme();

  // Set page title
  const t = TITLES[activeKey] || TITLES.home;
  const titleEl = document.getElementById("mPageTitle");
  const subEl = document.getElementById("mPageSub");
  if (titleEl) titleEl.textContent = t.title;
  if (subEl) subEl.textContent = t.sub;

  // Theme toggle
  const sw = document.getElementById("mThemeSwitch");
  if (sw) {
    sw.checked =
      (document.documentElement.getAttribute("data-theme") || "light") === "dark";
    sw.addEventListener("change", () => {
      toggleTheme();
      // Re-render sidebar if open
      if (document.querySelector('.mobile-sidebar.show')) {
        renderMobileSidebar();
      }
    });
  }

  // Avatar
  const user = await currentUser();
  const avatar = user?.avatarDataUrl || "../assets/images/logo.png";
  const img = document.getElementById("mProfileImg");
  if (img) img.src = avatar;

  // Profile click
  document.getElementById("mProfileBtn")?.addEventListener("click", () => {
    closeMobileSidebar();
    location.href = "./profile.html";
  });

  // Menu button click - open mobile sidebar
  document.getElementById("mMenuBtn")?.addEventListener("click", () => {
    openMobileSidebar();
  });

  // Initialize mobile sidebar
  initMobileSidebar();
}

function initMobileSidebar() {
  // Create mobile sidebar overlay and container
  const sidebarHTML = `
    <div class="mobile-sidebar-overlay" id="mobileSidebarOverlay"></div>
    <div class="mobile-sidebar" id="mobileSidebar">
      <button class="mobile-sidebar-close" id="mobileSidebarClose">
        <svg viewBox="0 0 24 24">
          <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
      </button>
      <div id="mobileSidebarContent"></div>
    </div>
  `;
  
  document.body.insertAdjacentHTML('beforeend', sidebarHTML);
  
  // Setup event listeners
  document.getElementById('mobileSidebarClose').addEventListener('click', closeMobileSidebar);
  document.getElementById('mobileSidebarOverlay').addEventListener('click', closeMobileSidebar);
  
  // Render sidebar content
  renderMobileSidebar();
}

function openMobileSidebar() {
  document.getElementById('mobileSidebar').classList.add('active');
  document.getElementById('mobileSidebarOverlay').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeMobileSidebar() {
  document.getElementById('mobileSidebar').classList.remove('active');
  document.getElementById('mobileSidebarOverlay').classList.remove('show');
  document.body.style.overflow = '';
}

async function renderMobileSidebar() {
  const content = document.getElementById('mobileSidebarContent');
  if (!content) return;
  
  const user = await currentUser();
  const avatar = user?.avatarDataUrl || "../assets/images/logo.png";
  const theme = document.documentElement.getAttribute("data-theme") || "light";
  
  content.innerHTML = `
    <div style="margin-top: 40px;">
      <div class="theme-toggle">
        <div style="font-weight:900">Theme</div>
        <button id="mobileThemeBtn" type="button">
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
        <a href="./home.html" onclick="closeMobileSidebar()">Home</a>
        <a href="./profile.html" onclick="closeMobileSidebar()">Edit Profile</a>
        ${user?.role === 'admin' ? `
        <a href="./terms.html" onclick="closeMobileSidebar()">Academic Session</a>
        <a href="./students.html" onclick="closeMobileSidebar()">Manage Students</a>
        <a href="./fees-config.html" onclick="closeMobileSidebar()">Fee Details</a>
        <a href="./tracking.html" onclick="closeMobileSidebar()">Payment Tracking</a>
        <a href="./reports.html" onclick="closeMobileSidebar()">Reports & Stats</a>
        <a href="./invoice.html" onclick="closeMobileSidebar()">Invoices</a>
        ` : ''}
        <a href="#" id="mobileLogoutBtn" class="danger" onclick="closeMobileSidebar()">Logout</a>
      </nav>

      <div class="card">
        <b>School Activities</b>
        <div id="mobileActivityList" class="muted" style="margin-top:8px"></div>
      </div>
    </div>
  `;
  
  // Add activity list
  const activities = await getActivities();
  const list = document.getElementById("mobileActivityList");
  list.innerHTML = activities.length
    ? activities.slice(0, 6).map(a => `
        <div style="margin-bottom:8px">
          <b>${a.title || ""}</b>
          <div class="muted">${a.date || ""}</div>
        </div>
      `).join("")
    : "No activities yet.";
  
  // Theme button
  document.getElementById("mobileThemeBtn").addEventListener("click", () => {
    toggleTheme();
    renderMobileSidebar(); // refresh button text + apply
  });
  
  // Logout button
  document.getElementById("mobileLogoutBtn").addEventListener("click", (e) => {
    e.preventDefault();
    closeMobileSidebar();
    setTimeout(() => logout(), 300);
  });
  
  // Close sidebar when clicking any nav link
  const navLinks = content.querySelectorAll('.nav a[href]:not([href="#"])');
  navLinks.forEach(link => {
    link.addEventListener('click', closeMobileSidebar);
  });
}

// Expose close function globally for onclick handlers
window.closeMobileSidebar = closeMobileSidebar;