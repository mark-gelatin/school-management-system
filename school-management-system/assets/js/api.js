/**
 * Shared API helpers for fetch-based CRUD operations.
 */
(function () {
  "use strict";

  function normalizePath(path) {
    if (!path) return "";
    return String(path).replace(/^\/+/, "");
  }

  window.resolveAppUrl = function resolveAppUrl(path) {
    const base = (window.APP_BASE_URL || "").replace(/\/+$/, "");
    const normalized = normalizePath(path);
    if (!normalized) return base || "";
    return `${base}/${normalized}`;
  };

  window.apiRequest = async function apiRequest(path, options = {}) {
    const url = window.resolveAppUrl(path);
    const opts = {
      method: options.method || "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        ...(options.headers || {}),
      },
    };

    if (options.body instanceof FormData) {
      opts.body = options.body;
    } else if (options.body !== undefined) {
      opts.body = JSON.stringify(options.body);
      opts.headers["Content-Type"] = "application/json";
    }

    try {
      const response = await fetch(url, opts);
      const json = await response.json().catch(() => ({
        success: false,
        message: "Invalid JSON response from server.",
        data: {},
      }));

      if (!response.ok && json.success !== false) {
        return {
          success: false,
          message: json.message || `Request failed (${response.status}).`,
          data: json.data || {},
        };
      }
      return json;
    } catch (error) {
      return {
        success: false,
        message: error && error.message ? error.message : "Network request failed.",
        data: {},
      };
    }
  };
})();
