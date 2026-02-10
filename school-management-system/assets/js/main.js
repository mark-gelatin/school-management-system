/**
 * Global UI utilities and interactions.
 */
(function () {
  "use strict";

  function setupSidebarToggle() {
    const toggle = document.getElementById("sidebarToggle");
    const sidebar = document.getElementById("appSidebar");
    if (!toggle || !sidebar) return;

    toggle.addEventListener("click", () => {
      document.body.classList.toggle("sidebar-open");
    });

    document.addEventListener("click", (event) => {
      if (!document.body.classList.contains("sidebar-open")) return;
      if (sidebar.contains(event.target) || toggle.contains(event.target)) return;
      document.body.classList.remove("sidebar-open");
    });

    window.addEventListener("resize", () => {
      if (window.innerWidth >= 769) {
        document.body.classList.remove("sidebar-open");
      }
    });
  }

  function getToastContainer() {
    let container = document.querySelector(".toast-container");
    if (!container) {
      container = document.createElement("div");
      container.className = "toast-container";
      document.body.appendChild(container);
    }
    return container;
  }

  window.showToast = function showToast(message, type = "info") {
    const container = getToastContainer();
    const toast = document.createElement("div");
    toast.className = `toast ${type}`;
    toast.textContent = message || "Notification";
    container.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = "0";
      toast.style.transform = "translateY(8px)";
      setTimeout(() => toast.remove(), 240);
    }, 2600);
  };

  document.addEventListener("DOMContentLoaded", () => {
    setupSidebarToggle();
  });
})();
