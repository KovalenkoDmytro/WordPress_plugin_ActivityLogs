(function () {
  const app = document.querySelector(".wp-activity-logger-app");
  const config = window.wpActivityLoggerConfig;

  if (!app || !config) {
    return;
  }

  const form = app.querySelector("[data-filter-form]");
  const rowsTarget = app.querySelector("[data-log-rows]");
  const refreshButton = app.querySelector("[data-refresh-now]");
  const refreshStatus = app.querySelector("[data-refresh-status]");
  const lastUpdated = app.querySelector("[data-last-updated]");
  const pageCount = app.querySelector("[data-page-count]");
  const paginationSummary = app.querySelector("[data-pagination-summary]");
  const prevButton = app.querySelector('[data-page-direction="prev"]');
  const nextButton = app.querySelector('[data-page-direction="next"]');
  const refreshIntervalSelect = app.querySelector("[data-refresh-interval]");
  const metricTargets = {
    totalLogs: app.querySelector('[data-metric="total-logs"]'),
    uniqueUsers: app.querySelector('[data-metric="unique-users"]'),
    uniqueIps: app.querySelector('[data-metric="unique-ips"]'),
    latestActivity: app.querySelector('[data-metric="latest-activity"]'),
  };

  const initialStateNode = document.getElementById("wp-activity-logger-initial-state");
  const initialState = initialStateNode?.textContent ? JSON.parse(initialStateNode.textContent) : null;

  const state = {
    currentPage: initialState?.pagination?.currentPage ?? 1,
    totalPages: initialState?.pagination?.totalPages ?? 1,
    topLogId: initialState?.items?.[0]?.id ?? null,
    timerId: null,
    isLoading: false,
  };

  const syncPaginationButtons = (isLoading = state.isLoading) => {
    prevButton.disabled = isLoading || state.currentPage <= 1;
    nextButton.disabled = isLoading || state.currentPage >= state.totalPages;
  };

  const getFilters = () => ({
    start_date: form.start_date.value.trim(),
    end_date: form.end_date.value.trim(),
    username: form.username.value.trim(),
    search: form.search.value.trim(),
    ip_address: form.ip_address.value.trim(),
    order_by: form.order_by.value,
    order: form.order.value,
    paged: String(state.currentPage),
    per_page: "25",
  });

  const updateQueryString = (filters) => {
    const nextUrl = new URL(config.pageUrl, window.location.origin);
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== "" && key !== "per_page") {
        nextUrl.searchParams.set(key, value);
      }
    });
    window.history.replaceState({}, "", nextUrl);
  };

  const renderRows = (items, highlightNew) => {
    rowsTarget.innerHTML = "";

    if (!items.length) {
      const row = document.createElement("tr");
      row.dataset.emptyState = "1";
      const cell = document.createElement("td");
      cell.colSpan = 5;
      cell.textContent = config.strings.empty;
      row.appendChild(cell);
      rowsTarget.appendChild(row);
      return;
    }

    items.forEach((item) => {
      const row = document.createElement("tr");
      row.dataset.logId = String(item.id);

      if (highlightNew && typeof state.topLogId === "number" && item.id > state.topLogId) {
        row.classList.add("is-new");
      }

      const idCell = document.createElement("td");
      idCell.textContent = String(item.id);

      const userCell = document.createElement("td");
      const userPill = document.createElement("span");
      userPill.className = "wp-activity-logger-user-pill";
      userPill.textContent = item.user;
      userCell.appendChild(userPill);

      const activityCell = document.createElement("td");
      activityCell.textContent = item.activity;

      const ipCell = document.createElement("td");
      const code = document.createElement("code");
      code.textContent = item.ipAddress;
      ipCell.appendChild(code);

      const createdAtCell = document.createElement("td");
      createdAtCell.textContent = item.createdAt;

      row.append(idCell, userCell, activityCell, ipCell, createdAtCell);
      rowsTarget.appendChild(row);
    });
  };

  const renderPayload = (payload, highlightNew = false) => {
    renderRows(payload.items, highlightNew);

    metricTargets.totalLogs.textContent = String(payload.metrics.totalLogs);
    metricTargets.uniqueUsers.textContent = String(payload.metrics.uniqueUsers);
    metricTargets.uniqueIps.textContent = String(payload.metrics.uniqueIps);
    metricTargets.latestActivity.textContent = payload.metrics.latestActivity;

    pageCount.textContent = `Page ${payload.pagination.currentPage} of ${payload.pagination.totalPages}`;
    paginationSummary.textContent = `Showing ${payload.pagination.totalLogs} logs`;
    state.currentPage = payload.pagination.currentPage;
    state.totalPages = payload.pagination.totalPages;
    state.topLogId = payload.items[0]?.id ?? state.topLogId;
    lastUpdated.textContent = new Date().toLocaleString();
    syncPaginationButtons(false);
    refreshStatus.textContent = config.strings.updated;
  };

  const setLoading = (value) => {
    state.isLoading = value;
    refreshButton.disabled = value;
    syncPaginationButtons(value);
    if (value) {
      refreshStatus.textContent = config.strings.loading;
    }
  };

  const fetchPayload = async (options = {}) => {
    if (state.isLoading) {
      return;
    }

    const previousPage = state.currentPage;
    if (options.resetPage) {
      state.currentPage = 1;
    }

    setLoading(true);
    const filters = getFilters();
    updateQueryString(filters);

    try {
      const body = new URLSearchParams({
        action: "wp_activity_logger_fetch_logs",
        nonce: config.nonce,
        ...filters,
      });

      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body,
      });

      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result?.data?.message || config.strings.error);
      }

      renderPayload(result.data, Boolean(options.highlightNew));
    } catch (error) {
      state.currentPage = previousPage;
      refreshStatus.textContent = error instanceof Error ? error.message : config.strings.error;
    } finally {
      setLoading(false);
    }
  };

  const restartAutoRefresh = () => {
    if (state.timerId) {
      window.clearInterval(state.timerId);
      state.timerId = null;
    }

    const seconds = Number(refreshIntervalSelect.value);
    if (!Number.isFinite(seconds) || seconds <= 0) {
      return;
    }

    state.timerId = window.setInterval(() => {
      fetchPayload({ highlightNew: true });
    }, seconds * 1000);
  };

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    fetchPayload({ resetPage: true });
  });

  refreshButton.addEventListener("click", () => {
    fetchPayload({ highlightNew: true });
  });

  prevButton.addEventListener("click", () => {
    if (state.currentPage <= 1) {
      return;
    }

    state.currentPage -= 1;
    fetchPayload();
  });

  nextButton.addEventListener("click", () => {
    state.currentPage += 1;
    fetchPayload();
  });

  refreshIntervalSelect.addEventListener("change", restartAutoRefresh);

  if (initialState) {
    renderPayload(initialState);
  }

  restartAutoRefresh();
})();
