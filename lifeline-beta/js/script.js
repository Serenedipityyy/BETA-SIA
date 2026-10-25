
function renderRoleDashboard() {
    const u = state.user;
    if (!u) return;

    const r = u.role; // 'user', 'member', 'officer', 'admin'
    const studentView = document.getElementById("studentDashboardView");
    const medicView = document.getElementById("medicDashboardView");
    const officerView = document.getElementById("officerDashboardView");

    const badgeHeader = document.getElementById("roleBadgeHeader");
    const roleHeaderBadge = document.getElementById("roleHeaderBadge");
    const roleHeaderTitle = document.getElementById("roleHeaderTitle");
    const roleHeaderAction = document.getElementById("roleHeaderAction");
    const navDashText = document.getElementById("navDashText");

    if (badgeHeader) {
        if (r === "officer" || r === "admin") {
            badgeHeader.textContent = "OFFICER";
            badgeHeader.style.background = "#2563eb";
        } else if (r === "member") {
            badgeHeader.textContent = "MEDIC";
            badgeHeader.style.background = "#059669";
        } else {
            badgeHeader.textContent = "STUDENT";
            badgeHeader.style.background = "#dc2626";
        }
    }

    if (r === "officer" || r === "admin") {
        if (studentView) studentView.style.display = "none";
        if (medicView) medicView.style.display = "none";
        if (officerView) officerView.style.display = "block";

        if (roleHeaderBadge) {
            roleHeaderBadge.textContent = "DISPATCH CONTROL CENTER";
            roleHeaderBadge.style.background = "#dbeafe";
            roleHeaderBadge.style.color = "#1e40af";
        }
        if (roleHeaderTitle) roleHeaderTitle.textContent = "CPR Officer & Dispatch Command";
        if (navDashText) navDashText.textContent = "Dispatch Hub";
        if (roleHeaderAction) roleHeaderAction.innerHTML = `<button class="primary-button btn-sm" onclick="showSection('roster')">🛡️ Manage Roster</button>`;

    } else if (r === "member") {
        if (studentView) studentView.style.display = "none";
        if (officerView) officerView.style.display = "none";
        if (medicView) medicView.style.display = "block";

        if (roleHeaderBadge) {
            roleHeaderBadge.textContent = "CPR MEDIC RESPONDER VIEW";
            roleHeaderBadge.style.background = "#d1fae5";
            roleHeaderBadge.style.color = "#065f46";
        }
        if (roleHeaderTitle) roleHeaderTitle.textContent = "Active Responder Dispatch Queue";
        if (navDashText) navDashText.textContent = "Responder Hub";
        if (roleHeaderAction) roleHeaderAction.innerHTML = `<button class="primary-button btn-sm" onclick="showSection('emergency')">🚨 Send Alert</button>`;

    } else {
        if (medicView) medicView.style.display = "none";
        if (officerView) officerView.style.display = "none";
        if (studentView) studentView.style.display = "block";

        if (roleHeaderBadge) {
            roleHeaderBadge.textContent = "STUDENT EMERGENCY HUB";
            roleHeaderBadge.style.background = "#fef3c7";
            roleHeaderBadge.style.color = "#92400e";
        }
        if (roleHeaderTitle) roleHeaderTitle.textContent = "Student Safety & Emergency Assistance";
        if (navDashText) navDashText.textContent = "Student Hub";
        if (roleHeaderAction) roleHeaderAction.innerHTML = `<button class="primary-button btn-sm btn-red" onclick="showSection('emergency')">🚨 Request Help</button>`;
    }
}

async function toggleSelfDuty() {
    if (!state.user) return;
    try {
        await api("roster.php?action=toggle_duty", { method: "POST", body: { student_id: state.user.studentId } });
        toast("Duty status updated.", "success");
        await pollTick();
    } catch (err) {
        toast(err.message, "error");
    }
}

/* =====================================================================
   PRIORITY-BASED SYNTHESIZED EMERGENCY ALARMS
   ===================================================================== */
function playEmergencyAlarm(priority = "RED") {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        if (!audioCtx) audioCtx = new AudioCtx();
        if (audioCtx.state === "suspended") audioCtx.resume();
        const ctx = audioCtx;

        if (priority === "RED") {
            // Rapid 2-tone emergency siren
            let pulseCount = 0;
            const sirenInterval = setInterval(() => {
                if (pulseCount >= 6) {
                    clearInterval(sirenInterval);
                    return;
                }
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = "sawtooth";
                osc.frequency.setValueAtTime(pulseCount % 2 === 0 ? 880 : 660, ctx.currentTime);
                gain.gain.setValueAtTime(0.3, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.18);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.18);
                pulseCount++;
            }, 200);

        } else if (priority === "YELLOW") {
            // Pulsing double beep
            [0, 0.25].forEach(delay => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = "sine";
                osc.frequency.setValueAtTime(587.33, ctx.currentTime + delay);
                gain.gain.setValueAtTime(0.2, ctx.currentTime + delay);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + delay + 0.15);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(ctx.currentTime + delay);
                osc.stop(ctx.currentTime + delay + 0.15);
            });

        } else {
            // Soft single chime for Green/Standard
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = "sine";
            osc.frequency.setValueAtTime(440, ctx.currentTime);
            gain.gain.setValueAtTime(0.15, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        }
    } catch (e) {
        console.warn("Audio alarm blocked or unsupported:", e);
    }
}
/* =====================================================================
   Lifeline - front end (talks to the PHP + MySQL API in /api)
   All data now lives in MySQL. The browser only keeps UI preferences
   (sidebar collapsed, last tab, sound on/off) in localStorage.
   ===================================================================== */

const API_BASE = ".";
const POLL_MS = 5000;                       // how often the dashboard refreshes from the server
const ID_REGEX = /^2\d-\d{4}-\d{3}$/;

const LS = {
    sidebar: "lifelineSidebarCollapsed",
    section: "lifelineActiveSection",
    alerts: "lifelineAlertsEnabled"
};

const STATUS_LABEL = { DISPATCHED: "Dispatched", RESPONDING: "Responding", ON_SCENE: "On-scene", RESOLVED: "Resolved" };
const STATUS_CLASS = { DISPATCHED: "dispatched", RESPONDING: "responding", ON_SCENE: "onscene", RESOLVED: "resolved" };
const NEXT_STATUS = { DISPATCHED: "RESPONDING", RESPONDING: "ON_SCENE", ON_SCENE: "RESOLVED" };

const state = {
    user: null,
    meta: { buildings: [], squads: [], zones: [], types: [] },
    active: [],          // unresolved incidents (responders only)
    mine: [],            // my own recent requests
    recent: [],          // latest 5 incidents (anonymised)
    roster: [],
    announcements: [],
    training: { sessions: [], mine: [] },
    stats: null,
    charts: {}
};

/* =====================================================================
   HELPERS
   ===================================================================== */
let redirecting = false;
function goToLogin() {
    if (redirecting) return;
    redirecting = true;
    window.location.replace("index.html");
}

async function api(path, opts = {}) {
    const { method = "GET", body } = opts;
    const headers = { "X-Requested-With": "lifeline" };
    if (body !== undefined) headers["Content-Type"] = "application/json";

    let res;
    try {
        res = await fetch(`${API_BASE}/${path}`, {
            method,
            headers,
            credentials: "same-origin",
            body: body !== undefined ? JSON.stringify(body) : undefined
        });
    } catch (e) {
        const err = new Error("Cannot reach the server. Check your connection.");
        err.network = true;
        throw err;
    }

    let data = null;
    try { data = await res.json(); } catch (e) { /* non-JSON response */ }

    if (!res.ok) {
        const err = new Error((data && data.error) || `Request failed (${res.status})`);
        err.status = res.status;
        err.code = data && data.code;
        if (res.status === 401 && document.getElementById("dashboard")) goToLogin();
        throw err;
    }
    return data;
}

// Escape user-supplied text before putting it inside innerHTML (prevents XSS)
function esc(value) {
    return String(value ?? "").replace(/[&<>"']/g, c => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    }[c]));
}

function timeAgo(ts) {
    if (!ts) return "—";
    const sec = Math.max(0, Math.floor((Date.now() - ts) / 1000));
    if (sec < 60) return "Just now";
    const min = Math.floor(sec / 60);
    if (min < 60) return `${min} min ago`;
    const hr = Math.floor(min / 60);
    if (hr < 24) return `${hr} hr ago`;
    return new Date(ts).toLocaleDateString();
}

function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

function role() { return state.user ? state.user.role : "user"; }
function isOfficer() { return role() === "officer" || role() === "admin"; }
function isResponder() { return ["member", "officer", "admin"].includes(role()); }

// Non-blocking replacement for alert()
function toast(message, type = "info", duration = 4000) {
    let box = document.getElementById("toastContainer");
    if (!box) {
        box = document.createElement("div");
        box.id = "toastContainer";
        box.setAttribute("aria-live", "polite");
        document.body.appendChild(box);
    }
    const t = document.createElement("div");
    t.className = `toast toast-${type}`;
    t.textContent = message;
    box.appendChild(t);
    setTimeout(() => {
        t.classList.add("hide");
        setTimeout(() => t.remove(), 300);
    }, duration);
}

function fillSelect(select, items, placeholder) {
    if (!select) return;
    const prev = select.value;
    select.innerHTML = "";
    if (placeholder !== undefined) {
        const o = document.createElement("option");
        o.value = "";
        o.textContent = placeholder;
        select.appendChild(o);
    }
    items.forEach(it => {
        const o = document.createElement("option");
        o.value = it.id;
        o.textContent = it.name;
        select.appendChild(o);
    });
    if (prev && [...select.options].some(o => o.value === prev)) select.value = prev;
}

/* =====================================================================
   PROFILE DROPDOWN
   ===================================================================== */
function toggleProfileDropdown(event) {
    if (event) event.stopPropagation();
    const menu = document.getElementById("profileDropdownMenu");
    if (menu) menu.classList.toggle("show");
}

function closeProfileDropdown() {
    const menu = document.getElementById("profileDropdownMenu");
    if (menu) menu.classList.remove("show");
}

document.addEventListener("click", function (event) {
    const container = document.getElementById("userProfileMenuContainer");
    if (container && !container.contains(event.target)) closeProfileDropdown();
});

/* =====================================================================
   AUTH: LOGIN & REGISTER (index.html)
   ===================================================================== */
function switchAuthTab(tab) {
    const loginTabBtn = document.getElementById("loginTabBtn");
    const registerTabBtn = document.getElementById("registerTabBtn");
    const loginContainer = document.getElementById("loginFormContainer");
    const registerContainer = document.getElementById("registerFormContainer");

    if (tab === "register") {
        if (loginTabBtn) loginTabBtn.classList.remove("active");
        if (registerTabBtn) registerTabBtn.classList.add("active");
        if (loginContainer) loginContainer.style.display = "none";
        if (registerContainer) registerContainer.style.display = "block";
    } else {
        if (registerTabBtn) registerTabBtn.classList.remove("active");
        if (loginTabBtn) loginTabBtn.classList.add("active");
        if (registerContainer) registerContainer.style.display = "none";
        if (loginContainer) loginContainer.style.display = "block";
    }
}

const loginForm = document.getElementById("loginForm");
if (loginForm) {
    loginForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        const studentId = document.getElementById("studentId").value.trim();
        const password = document.getElementById("password").value;
        const btn = loginForm.querySelector("button[type=submit]");

        if (!ID_REGEX.test(studentId)) {
            toast("Invalid Student ID format. Use 2X-XXXX-XXX (e.g. 23-1234-567).", "error");
            return;
        }

        btn.disabled = true;
        try {
            await api("auth.php?action=login", { method: "POST", body: { student_id: studentId, password } });
            localStorage.setItem(LS.section, "dashboard");
            window.location.href = "dashboard.html";
        } catch (err) {
            if (err.code === "not_registered" || err.code === "needs_activation") {
                toast(err.message, "error", 6000);
                switchAuthTab("register");
                const reg = document.getElementById("regStudentId");
                if (reg) reg.value = studentId;
            } else {
                toast(err.message, "error");
            }
        } finally {
            btn.disabled = false;
        }
    });
}

const registerForm = document.getElementById("registerForm");
if (registerForm) {
    registerForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        const btn = registerForm.querySelector("button[type=submit]");

        const payload = {
            full_name: document.getElementById("regFullName").value.trim(),
            student_id: document.getElementById("regStudentId").value.trim(),
            role: document.getElementById("regRole") ? document.getElementById("regRole").value : "user",
            course: document.getElementById("regCourse").value.trim(),
            password: document.getElementById("regPassword").value,
            activation_code: (document.getElementById("regActivationCode")?.value || "").trim()
        };

        if (!ID_REGEX.test(payload.student_id)) {
            toast("Invalid Student ID format. Use 2X-XXXX-XXX (e.g. 23-1234-567).", "error");
            return;
        }
        if (payload.password.length < 6) {
            toast("Password must be at least 6 characters.", "error");
            return;
        }

        btn.disabled = true;
        try {
            const res = await api("auth.php?action=register", { method: "POST", body: payload });
            toast(res.activated
                ? `Welcome, ${res.name}! Your ${res.role === "officer" ? "CPR Officer" : "CPR Member"} account is activated. Please sign in.`
                : `Account created for ${res.name}. Please sign in.`, "success", 6000);
            registerForm.reset();
            const loginId = document.getElementById("studentId");
            if (loginId) loginId.value = payload.student_id;
            switchAuthTab("login");
        } catch (err) {
            if (err.code === "already_registered") {
                toast(err.message, "error");
                switchAuthTab("login");
                const loginId = document.getElementById("studentId");
                if (loginId) loginId.value = payload.student_id;
            } else {
                toast(err.message, "error", 6000);
            }
        } finally {
            btn.disabled = false;
        }
    });
}

async function logout() {
    try { await api("auth.php?action=logout", { method: "POST" }); } catch (e) { /* ignore */ }
    localStorage.removeItem(LS.section);
    goToLogin();
}

/* =====================================================================
   NAVIGATION
   ===================================================================== */
function toggleSidebarCollapse() {
    const sidebar = document.getElementById("appSidebar");
    if (sidebar) {
        sidebar.classList.toggle("collapsed");
        localStorage.setItem(LS.sidebar, sidebar.classList.contains("collapsed"));
    }
}

function toggleSidebar() {
    const sidebar = document.querySelector(".sidebar");
    if (sidebar) sidebar.classList.toggle("open");
}

function showSection(sectionId, isInitialLoad = false) {
    if (!sectionId) return;
    if (sectionId === "roster" && !isOfficer()) sectionId = "dashboard";
    if (!document.getElementById(sectionId)) sectionId = "dashboard";

    localStorage.setItem(LS.section, sectionId);

    document.querySelectorAll(".content-section").forEach(s => s.classList.remove("active-section"));
    document.getElementById(sectionId).classList.add("active-section");
    document.querySelectorAll(".nav-item").forEach(item => {
        item.classList.toggle("active", item.dataset.section === sectionId);
    });

    const titleMap = {
        emergency: "Emergency Alert & Dispatch Hub",
        roster: "CPR Team & Volunteer Management",
        announcements: "Announcement Board",
        training: "Training & Seminar Hub",
        analytics: "Patient Analytics & Statistics",
        profile: "My Profile"
    };
    const subtitleMap = {
        dashboard: "Campus safety overview and emergency response dispatch hub.",
        emergency: "Send emergency dispatches or respond to active campus medical calls.",
        roster: "Manage volunteer medic deployment, team assignments, and shift rosters.",
        announcements: "Important university health and safety announcements.",
        training: "CPR certification, first aid workshops, and refresher courses.",
        analytics: "Anonymized campus health and emergency statistics.",
        profile: "View and manage your personal student information."
    };

    const titleEl = document.getElementById("pageTitle");
    if (titleEl) {
        if (sectionId === "dashboard") {
            titleEl.textContent = "Welcome back, ";
            const span = document.createElement("span");
            span.id = "welcomeUserName";
            span.textContent = state.user ? state.user.name : "User";
            titleEl.appendChild(span);
            titleEl.appendChild(document.createTextNode("!"));
        } else {
            titleEl.textContent = titleMap[sectionId] || "Lifeline";
        }
    }
    setText("pageSubtitle", subtitleMap[sectionId] || "");

    const sidebar = document.querySelector(".sidebar");
    if (sidebar) sidebar.classList.remove("open");
    if (!isInitialLoad) window.scrollTo({ top: 0, behavior: "smooth" });

    if (sectionId === "analytics") loadAnalytics();
    if (sectionId === "training") loadTraining();
}

/* =====================================================================
   ALERT SOUND + BROWSER NOTIFICATIONS (for responders)
   ===================================================================== */
let audioCtx = null;
const knownIncidentIds = new Set();
let firstPollDone = false;

function unlockAudio() {
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === "suspended") audioCtx.resume();
    } catch (e) { /* audio not supported */ }
}

function enableAlerts() {
    unlockAudio();
    if ("Notification" in window && Notification.permission === "default") Notification.requestPermission();
    localStorage.setItem(LS.alerts, "true");
    updateAlertsButton();
    beep(false);
    toast("Sound & desktop notifications enabled.", "success");
}

function updateAlertsButton() {
    const btn = document.getElementById("enableAlertsBtn");
    if (!btn) return;
    const on = localStorage.getItem(LS.alerts) === "true" && audioCtx;
    btn.style.display = on ? "none" : "inline-block";
}

function beep(urgent) {
    if (!audioCtx) return;
    try {
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = "square";
        osc.frequency.value = urgent ? 880 : 660;
        gain.gain.value = 0.07;
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + (urgent ? 0.7 : 0.3));
    } catch (e) { /* ignore */ }
}

// Called after each poll: alert responders about incidents they have not seen yet
function notifyNewIncidents(active) {
    const fresh = active.filter(i => !knownIncidentIds.has(i.id));
    active.forEach(i => knownIncidentIds.add(i.id));
    if (!firstPollDone || !isResponder()) return;

    fresh
        .filter(i => i.status === "DISPATCHED" && i.reporterId !== state.user.studentId)
        .forEach(i => {
            toast(`🚨 ${i.priority} alert: ${i.building} – ${i.room}`, "error", 8000);
            playEmergencyAlarm(i.priority);
            if ("Notification" in window && Notification.permission === "granted") {
                new Notification(`Lifeline ${i.priority} emergency`, {
                    body: `${i.building} – ${i.room} • ${i.patients} patient(s)`
                });
            }
        });
}

/* =====================================================================
   EMERGENCY DISPATCH
   ===================================================================== */
function updateRoomDropdown() {
    const buildingSelect = document.getElementById("building");
    const roomSelect = document.getElementById("room");
    if (!buildingSelect || !roomSelect) return;

    roomSelect.innerHTML = '<option value="">Select Room / Area</option>';
    const b = state.meta.buildings.find(x => String(x.id) === buildingSelect.value);
    (b ? b.rooms : []).forEach(room => {
        const option = document.createElement("option");
        option.value = room.id;
        option.textContent = room.name;
        roomSelect.appendChild(option);
    });
}

const emergencyForm = document.getElementById("emergencyForm");
if (emergencyForm) {
    emergencyForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        const priority = document.querySelector('input[name="priority"]:checked');
        const buildingSel = document.getElementById("building");
        const roomSel = document.getElementById("room");
        const patients = parseInt(document.getElementById("patients").value, 10) || 1;
        const others = document.getElementById("others").value.trim();
        const typeId = document.getElementById("incidentType").value;

        if (!priority) { toast("Please select the emergency priority.", "error"); return; }
        if (!buildingSel.value || !roomSel.value) { toast("Please select the building and room.", "error"); return; }

        const buildingName = buildingSel.options[buildingSel.selectedIndex].text;
        const roomName = roomSel.options[roomSel.selectedIndex].text;

        // Accident guard
        if (!confirm(`Send a ${priority.value} emergency alert for ${buildingName} – ${roomName}?\n\nFalse alarms delay help for real emergencies.`)) return;

        const btn = emergencyForm.querySelector(".dispatch-button");
        btn.disabled = true;
        try {
            const { incident } = await api("incidents.php?action=create", {
                method: "POST",
                body: {
                    priority: priority.value,
                    building_id: Number(buildingSel.value),
                    room_id: Number(roomSel.value),
                    patients,
                    incident_type_id: typeId || null,
                    details: others
                }
            });
            knownIncidentIds.add(incident.id);

            setText("alertMessage",
                `${incident.priority} priority emergency reported at ${incident.building} (${incident.room}). Patients: ${incident.patients}. ` +
                `Reference ID: ${incident.id}. Available CPR responders have been notified.`);
            playEmergencyAlarm(priority.value);
            document.getElementById("alertModal").classList.add("show");

            emergencyForm.reset();
            updateRoomDropdown();
            pollTick();
        } catch (err) {
            toast(err.message, err.code === "duplicate" ? "info" : "error", 6000);
        } finally {
            btn.disabled = false;
        }
    });
}

function canAdvance(inc) {
    if (!isResponder() || inc.status === "RESOLVED") return false;
    if (inc.status === "DISPATCHED") return true;
    return isOfficer() || inc.responderId === state.user.studentId;
}

function renderDispatchAlerts() {
    const container = document.getElementById("responderDispatchQueue");
    const list = document.getElementById("dispatchAlertsList");
    if (!container || !list) return;

    if (!isResponder()) {
        container.style.display = "none";
        return;
    }
    container.style.display = "block";

    const active = state.active;
    setText("activeAlertCount", `● ${active.length} Active Alert${active.length === 1 ? "" : "s"}`);

    if (active.length === 0) {
        list.innerHTML = `<p class="empty-note">No active emergency dispatches requiring response.</p>`;
        return;
    }

    list.innerHTML = active.map(inc => {
        let action;
        if (canAdvance(inc)) {
            const next = NEXT_STATUS[inc.status];
            const label = { RESPONDING: "Accept & Respond", ON_SCENE: "Mark Arrived On-Scene", RESOLVED: "Mark Resolved" }[next];
            const cls = { RESPONDING: "", ON_SCENE: "btn-yellow", RESOLVED: "btn-green" }[next];
            action = `<button class="primary-button btn-sm ${cls}" onclick="respondToDispatch('${esc(inc.id)}', '${next}', this)">${label}</button>`;
        } else {
            action = `<span class="handled-note">Handled by ${esc(inc.responderName || "another responder")}</span>`;
        }
        const pr = esc(String(inc.priority).toLowerCase());
        return `
        <div class="card dispatch-card ${pr}-card">
            <div class="dispatch-card-top">
                <strong>${esc(inc.id)} • ${esc(inc.reporterName)} (${esc(inc.reporterId)})</strong>
                <span class="priority ${pr}-priority">${esc(inc.priority)}</span>
            </div>
            <p class="dispatch-loc">📍 ${esc(inc.building)} - ${esc(inc.room)}</p>
            <p class="dispatch-meta">${inc.typeName ? esc(inc.typeName) + " | " : ""}Patients: ${esc(inc.patients)} | ${esc(inc.details)}</p>
            <p class="dispatch-meta">Reported ${esc(timeAgo(inc.createdAt))}</p>
            <p class="dispatch-status-line">Status: <span class="status ${STATUS_CLASS[inc.status] || "responding"}">${esc(STATUS_LABEL[inc.status] || inc.status)}</span>
                ${inc.responderName ? `(Medic: ${esc(inc.responderName)})` : ""}</p>
            <div class="dispatch-actions">${action}</div>
        </div>`;
    }).join("");
}

async function respondToDispatch(id, newStatus, btn) {
    if (btn) btn.disabled = true;
    try {
        await api("incidents.php?action=advance", { method: "POST", body: { id, status: newStatus } });
        toast(`Incident ${id} is now ${STATUS_LABEL[newStatus]}.`, "success");
    } catch (err) {
        toast(err.message, "error", 6000);
    }
    await pollTick();
}

// Student-side live tracker for their own requests
function renderMyRequests() {
    const card = document.getElementById("myRequestsCard");
    const list = document.getElementById("myRequestsList");
    if (!card || !list) return;

    const mine = state.mine;
    if (mine.length === 0) {
        card.style.display = "none";
        return;
    }
    card.style.display = "block";

    const steps = ["DISPATCHED", "RESPONDING", "ON_SCENE", "RESOLVED"];
    const labels = ["Reported", "Responding", "On-scene", "Resolved"];

    list.innerHTML = mine.map(inc => {
        const idx = steps.indexOf(inc.status);
        const stepper = steps.map((s, i) =>
            `<div class="dispatch-step ${i <= idx ? "active" : ""}"><span>${i + 1}</span><p>${labels[i]}</p></div>` +
            (i < steps.length - 1 ? `<div class="dispatch-line ${i < idx ? "done" : ""}"></div>` : "")
        ).join("");
        const who = inc.responderName ? ` • Medic: ${esc(inc.responderName)}` : " • Waiting for a responder…";
        const pr = esc(String(inc.priority).toLowerCase());
        return `
        <div class="my-request">
            <div class="my-request-head">
                <strong>${esc(inc.id)}</strong>
                <span class="priority ${pr}-priority">${esc(inc.priority)}</span>
            </div>
            <p class="dispatch-meta">${esc(inc.building)} - ${esc(inc.room)} • ${esc(timeAgo(inc.createdAt))}${inc.status === "RESOLVED" ? "" : who}</p>
            <div class="dispatch-status">${stepper}</div>
        </div>`;
    }).join("");
}

function renderDashboardStats() {
    const s = state.stats;
    if (s) {
        setText("statTodayIncidents", s.today);
        const diff = s.today - s.yesterday;
        setText("statTodayDelta", diff === 0 ? "Same as yesterday" : `${diff > 0 ? "+" : ""}${diff} from yesterday`);
        setText("activeRespondersCount", s.activeResponders);
        if (s.avgResponseMin !== null) {
            setText("statAvgResponse", `${s.avgResponseMin.toFixed(1)} min`);
            setText("statAvgNote", `From ${s.responseSamples} response${s.responseSamples === 1 ? "" : "s"} (30 days)`);
        } else {
            setText("statAvgResponse", "—");
            setText("statAvgNote", "No responses yet");
        }
        if (s.monthTotal > 0) {
            setText("statResolved", `${Math.round((s.monthResolved / s.monthTotal) * 100)}%`);
            setText("statResolvedNote", `${s.monthResolved} of ${s.monthTotal} this month`);
        } else {
            setText("statResolved", "—");
            setText("statResolvedNote", "No cases this month");
        }
    }

    const body = document.getElementById("recentIncidentsBody");
    if (body) {
        if (state.recent.length === 0) {
            body.innerHTML = `<tr><td colspan="6" class="empty-note">No incidents recorded yet.</td></tr>`;
        } else {
            body.innerHTML = state.recent.map(i => {
                const pr = esc(String(i.priority).toLowerCase());
                return `
                <tr>
                    <td>${esc(i.typeName || "Emergency")}<br><small class="muted-id">${esc(i.id)}</small></td>
                    <td>${esc(i.building)} - ${esc(i.room)}</td>
                    <td><span class="priority ${pr}-priority">${esc(i.priority)}</span></td>
                    <td>${esc(i.patients)}</td>
                    <td><span class="status ${STATUS_CLASS[i.status] || "responding"}">${esc(STATUS_LABEL[i.status] || i.status)}</span></td>
                    <td>${esc(timeAgo(i.createdAt))}</td>
                </tr>`;
            }).join("");
        }
    }
}

function updateNotificationBell() {
    const el = document.getElementById("notifCount");
    if (!el) return;
    const n = isResponder()
        ? state.active.filter(i => i.status === "DISPATCHED").length
        : state.mine.filter(i => i.status !== "RESOLVED").length;
    el.textContent = n;
    el.style.display = n > 0 ? "flex" : "none";
}

function closeModal() {
    const modal = document.getElementById("alertModal");
    if (modal) modal.classList.remove("show");
}

const alertModalEl = document.getElementById("alertModal");
if (alertModalEl) alertModalEl.addEventListener("click", e => { if (e.target === alertModalEl) closeModal(); });
document.addEventListener("keydown", e => {
    if (e.key === "Escape") { closeModal(); closeProfileEdit(); closeCodeModal(); }
});

/* =====================================================================
   ANNOUNCEMENTS
   ===================================================================== */
function renderAnnouncements() {
    const list = state.announcements;

    const officerCard = document.getElementById("officerAnnouncementCard");
    if (officerCard) officerCard.style.display = isOfficer() ? "block" : "none";

    // Dashboard card: 3 latest
    const latest = document.getElementById("latestAnnouncementsList");
    if (latest) {
        latest.innerHTML = list.length === 0
            ? `<p class="empty-note">No announcements yet.</p>`
            : list.slice(0, 3).map(a => `
                <div class="announcement-item"><div class="announcement-icon">${esc(a.icon || "📢")}</div>
                <div><strong>${esc(a.title)}</strong><p>${esc(a.dateLabel)}</p></div></div>`).join("");
    }

    const grid = document.getElementById("announcementBoardGrid");
    if (!grid) return;

    const del = ann => isOfficer()
        ? `<button class="ann-delete" onclick="deleteAnnouncement(${Number(ann.id)})" aria-label="Delete announcement">✕</button>` : "";

    grid.innerHTML =
        list.filter(a => a.featured).map(ann => `
            <div class="large-announcement">
                ${del(ann)}
                <span class="announcement-label">IMPORTANT</span>
                <h2>${esc(ann.title)}</h2>
                <p>${esc(ann.details)}</p>
                <span>${esc(ann.dateLabel)}</span>
            </div>`).join("") +
        list.filter(a => !a.featured).map(ann => `
            <div class="card announcement-card">
                ${del(ann)}
                <div class="announcement-big-icon">${esc(ann.icon || "📢")}</div>
                <h3>${esc(ann.title)}</h3>
                <p>${esc(ann.details)}</p>
                <strong>${esc(ann.dateLabel)}</strong>
            </div>`).join("");
}

async function loadAnnouncements() {
    const data = await api("announcements.php?action=list");
    state.announcements = data.announcements;
    renderAnnouncements();
}

async function deleteAnnouncement(id) {
    if (!isOfficer()) return;
    if (!confirm("Delete this announcement?")) return;
    try {
        await api("announcements.php?action=delete", { method: "POST", body: { id } });
        toast("Announcement deleted.", "success");
        await loadAnnouncements();
    } catch (err) {
        toast(err.message, "error");
    }
}

const createAnnouncementForm = document.getElementById("createAnnouncementForm");
if (createAnnouncementForm) {
    createAnnouncementForm.addEventListener("submit", async function (e) {
        e.preventDefault();
        if (!isOfficer()) { toast("Only CPR Officers can post announcements.", "error"); return; }

        const title = document.getElementById("annTitle").value.trim();
        const payload = {
            title,
            icon: document.getElementById("annIcon").value,
            featured: document.getElementById("annFeatured").value === "true",
            date_label: document.getElementById("annDate").value.trim(),
            details: document.getElementById("annDetails").value.trim()
        };
        try {
            await api("announcements.php?action=create", { method: "POST", body: payload });
            toast(`"${title}" published to the campus board.`, "success");
            createAnnouncementForm.reset();
            await loadAnnouncements();
        } catch (err) {
            toast(err.message, "error");
        }
    });
}

/* =====================================================================
   CPR ROSTER
   ===================================================================== */
function renderSquadOverview() {
    const box = document.getElementById("squadOverviewList");
    if (!box) return;
    box.innerHTML = state.meta.squads.map(s => `
        <div style="padding:12px; background:#f8f9fb; border-radius:8px; border-left:4px solid ${esc(s.color)};">
            <strong style="font-size:13px; display:block;">${esc(s.name)} • ${esc(s.label)}</strong>
            <span style="font-size:11px; color:var(--muted);">Coverage: ${esc(s.coverage)}</span>
        </div>`).join("");
}

function renderCPRRoster() {
    const roster = state.roster;
    const tbody = document.getElementById("cprRosterTableBody");
    const container = document.getElementById("dynamicRespondersList");

    if (tbody && isOfficer()) {
        tbody.innerHTML = roster.map(m => {
            let statusColor = "available";
            if (m.status === "Responding" || m.status === "On-Scene") statusColor = "busy";
            if (m.status === "Off Duty") statusColor = "status-dot";
            const options = state.meta.squads.map(s =>
                `<option value="${s.id}" ${s.id === m.squadId ? "selected" : ""}>${esc(s.name)}</option>`).join("");
            return `
            <tr>
                <td><strong>${esc(m.name)}</strong>${m.activated === false ? ' <small class="muted-id">(not activated)</small>' : ""}</td>
                <td><code>${esc(m.studentId)}</code></td>
                <td><select class="roster-select" onchange="updateMedicSquad('${esc(m.studentId)}', this.value)">${options}</select></td>
                <td>${esc(m.zone)}</td>
                <td><span class="${statusColor}">● ${esc(m.status)}</span></td>
                <td><button class="secondary-button btn-xs" onclick="toggleMedicDuty('${esc(m.studentId)}')">Toggle Duty</button></td>
            </tr>`;
        }).join("");
    }

    if (container) {
        const active = roster.filter(m => ["On Duty", "Responding", "On-Scene"].includes(m.status));
        container.innerHTML = active.length === 0
            ? `<p class="empty-note">No responders are on duty right now.</p>`
            : active.map(m => {
                const initials = (m.name || "?").split(" ").filter(Boolean).map(n => n[0]).join("").substring(0, 2).toUpperCase();
                const roleTitle = m.role === "officer" ? "CPR Officer" : "CPR Member";
                const badge = (m.status === "Responding" || m.status === "On-Scene") ? "busy" : "available";
                return `
                <div class="responder">
                    <div class="responder-avatar">${esc(initials)}</div>
                    <div class="responder-details">
                        <strong>${esc(m.name)} ${m.isSelf ? '<small class="self-tag">(You)</small>' : ""}</strong>
                        <span>${roleTitle} • ${esc(m.squadName)}</span>
                    </div>
                    <span class="${badge}">● ${esc(m.status)}</span>
                </div>`;
            }).join("");
        setText("activeRespondersBadge", `${active.length} Online`);
    }
}

async function updateMedicSquad(studentId, squadId) {
    if (!isOfficer()) return;
    try {
        await api("roster.php?action=set_squad", { method: "POST", body: { student_id: studentId, squad_id: Number(squadId) } });
        toast("Squad updated.", "success");
        await pollTick();
    } catch (err) {
        toast(err.message, "error");
    }
}

async function toggleMedicDuty(studentId) {
    if (!isOfficer()) return;
    try {
        await api("roster.php?action=toggle_duty", { method: "POST", body: { student_id: studentId } });
        await pollTick();
    } catch (err) {
        toast(err.message, "error");
    }
}

function closeCodeModal() {
    const m = document.getElementById("codeModal");
    if (m) m.classList.remove("show");
}

const addMemberForm = document.getElementById("addMemberForm");
if (addMemberForm) {
    addMemberForm.addEventListener("submit", async function (e) {
        e.preventDefault();
        if (!isOfficer()) { toast("Only CPR Officers can add members.", "error"); return; }

        const name = document.getElementById("newMemberName").value.trim();
        const id = document.getElementById("newMemberId").value.trim();
        if (!ID_REGEX.test(id)) { toast("Invalid Student ID format.", "error"); return; }

        try {
            const res = await api("roster.php?action=add", {
                method: "POST",
                body: {
                    student_id: id,
                    full_name: name,
                    role: document.getElementById("newMemberRole").value,
                    squad_id: Number(document.getElementById("newMemberTeam").value),
                    zone_id: Number(document.getElementById("newMemberZone").value)
                }
            });
            addMemberForm.reset();
            if (res.activationCode) {
                setText("codeModalText", `${name} (${id}) was added. They must activate their account with this one-time code:`);
                setText("codeModalCode", res.activationCode);
                document.getElementById("codeModal").classList.add("show");
            } else {
                toast(`${name || id} added to the CPR roster. They can sign in with their existing password.`, "success", 6000);
            }
            await pollTick();
        } catch (err) {
            toast(err.message, "error", 6000);
        }
    });
}

/* =====================================================================
   TRAINING & SEMINARS
   ===================================================================== */
async function loadTraining() {
    try {
        state.training = await api("training.php?action=list");
        renderTraining();
    } catch (err) {
        if (!err.network) toast(err.message, "error");
    }
}

function renderTraining() {
    const grid = document.getElementById("trainingGrid");
    if (grid) {
        const sessions = state.training.sessions;
        grid.innerHTML = sessions.length === 0
            ? `<p class="empty-note">No upcoming training sessions yet.</p>`
            : sessions.map(s => {
                const left = Math.max(0, s.slots - s.taken);
                let btn;
                if (s.myStatus === "Registered") {
                    btn = `<button class="secondary-button" onclick="cancelTraining(${s.id})">✓ Registered – Cancel</button>`;
                } else if (s.myStatus === "Attended") {
                    btn = `<button class="secondary-button" disabled>✓ Attended</button>`;
                } else if (left === 0) {
                    btn = `<button class="secondary-button" disabled>Full</button>`;
                } else {
                    btn = `<button class="secondary-button" onclick="registerTraining(${s.id})">Register</button>`;
                }
                return `
                <div class="card training-card"><span class="training-tag">${esc(s.tag)}</span>
                    <h3>${esc(s.title)}</h3><p>${esc(s.description)}</p>
                    <div class="training-info">📅 ${esc(s.date)}</div>
                    <div class="training-info">📍 ${esc(s.location)}</div>
                    <div class="training-info">👥 ${left} of ${s.slots} Slots left</div>
                    ${btn}</div>`;
            }).join("");
    }

    const att = document.getElementById("attendanceList");
    if (att) {
        const mine = state.training.mine;
        att.innerHTML = mine.length === 0
            ? `<p class="empty-note">You have not registered for any training yet.</p>`
            : mine.map(r => {
                const done = r.status === "Attended";
                const label = done ? "Completed" : r.status === "Absent" ? "Missed" : "Upcoming";
                return `
                <div class="attendance-row"><div><strong>${esc(r.title)}</strong><span>${label} • ${esc(r.date)}</span></div>
                <div class="progress"><div class="progress-bar ${done ? "completed" : "upcoming"}"></div></div>
                <strong>${done ? "100%" : "0%"}</strong></div>`;
            }).join("");
    }
}

async function registerTraining(id) {
    try {
        await api("training.php?action=register", { method: "POST", body: { session_id: id } });
        toast("You are registered for this session.", "success");
    } catch (err) {
        toast(err.message, "error");
    }
    loadTraining();
}

async function cancelTraining(id) {
    if (!confirm("Cancel your registration for this session?")) return;
    try {
        await api("training.php?action=cancel", { method: "POST", body: { session_id: id } });
        toast("Registration cancelled.", "success");
    } catch (err) {
        toast(err.message, "error");
    }
    loadTraining();
}

/* =====================================================================
   ANALYTICS
   ===================================================================== */
function upsertChart(key, canvasId, config) {
    if (typeof Chart === "undefined") return;
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    if (state.charts[key]) {
        state.charts[key].data = config.data;
        state.charts[key].update();
    } else {
        state.charts[key] = new Chart(canvas, config);
    }
}

async function loadAnalytics() {
    let d;
    try {
        d = await api("analytics.php");
    } catch (err) {
        if (!err.network) toast(err.message, "error");
        return;
    }

    setText("anTotal", d.total);
    setText("anCommon", d.mostCommon ? d.mostCommon.name : "—");
    setText("anCommonNote", d.mostCommon ? `${d.mostCommon.count} cases` : "No data yet");
    setText("anResponse", d.avgResponseMin !== null ? `${d.avgResponseMin.toFixed(1)}m` : "—");
    setText("anActive", d.activeResponders);

    const health = document.getElementById("healthList");
    if (health) {
        const max = Math.max(1, ...d.topList.map(t => t.count));
        health.innerHTML = d.topList.length === 0
            ? `<p class="empty-note">No incidents in the last 30 days.</p>`
            : d.topList.map(t => `
                <div class="health-item"><span>${esc(t.name)}</span><strong>${t.count}</strong>
                <div class="health-progress"><div style="width:${Math.round((t.count / max) * 100)}%"></div></div></div>`).join("");
    }

    const monthLabels = d.months.map(m => m.label);
    upsertChart("type", "incidentChart", {
        type: "doughnut",
        data: { labels: d.byType.map(t => t.name), datasets: [{ data: d.byType.map(t => t.count) }] },
        options: { responsive: true, plugins: { legend: { position: "bottom" } } }
    });
    upsertChart("monthly", "monthlyChart", {
        type: "line",
        data: { labels: monthLabels, datasets: [{ label: "Incidents", data: d.months.map(m => m.count), tension: 0.3, fill: false }] },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });
    upsertChart("location", "locationChart", {
        type: "bar",
        data: { labels: d.byLocation.map(l => l.name), datasets: [{ label: "Incidents", data: d.byLocation.map(l => l.count) }] },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });
    upsertChart("response", "responseChart", {
        type: "bar",
        data: { labels: monthLabels, datasets: [{ label: "Minutes", data: d.months.map(m => m.avgMin) }] },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });
}

/* =====================================================================
   PROFILE
   ===================================================================== */
function renderProfile() {
    const u = state.user;
    if (!u) return;

    const initials = u.name.split(" ").filter(Boolean).map(n => n[0]).join("").substring(0, 2).toUpperCase() || "UD";
    const roleNames = { member: "CPR Member", officer: "CPR Officer", admin: "Administrator" };
    const roleName = roleNames[u.role] || "Student / User";

    setText("headerUserName", u.name);
    setText("headerAvatar", initials);
    setText("headerUserRole", roleName);
    setText("profileFullName", u.name);
    setText("profileAvatar", initials);
    setText("profileRoleBadge", u.squad ? `${roleName} • ${u.squad}` : roleName);
    setText("profileStudentId", u.studentId);
    setText("profileCourse", u.course);
    setText("profileBirthdate", u.birthdate
        ? new Date(u.birthdate + "T00:00:00").toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" })
        : "Not set");
    setText("profileAddress", u.address || "Not set");
    setText("profileEmergency", u.emergencyContact || "Not set");

    const cprNav = document.getElementById("cprRosterNavBtn");
    if (cprNav) cprNav.style.display = isOfficer() ? "flex" : "none";
}

function openProfileEdit() {
    const u = state.user;
    if (!u) return;
    document.getElementById("editCourse").value = u.course || "";
    document.getElementById("editBirthdate").value = u.birthdate || "";
    document.getElementById("editAddress").value = u.address || "";
    document.getElementById("editContact").value = u.emergencyContact || "";
    document.getElementById("profileEditModal").classList.add("show");
}

function closeProfileEdit() {
    const m = document.getElementById("profileEditModal");
    if (m) m.classList.remove("show");
}

const profileEditForm = document.getElementById("profileEditForm");
if (profileEditForm) {
    profileEditForm.addEventListener("submit", async function (e) {
        e.preventDefault();
        try {
            const { user } = await api("auth.php?action=update_profile", {
                method: "POST",
                body: {
                    course: document.getElementById("editCourse").value,
                    birthdate: document.getElementById("editBirthdate").value,
                    address: document.getElementById("editAddress").value,
                    emergency_contact: document.getElementById("editContact").value
                }
            });
            state.user = user;
            renderProfile(); renderRoleDashboard();
            closeProfileEdit();
            toast("Profile updated.", "success");
        } catch (err) {
            toast(err.message, "error");
        }
    });
}

/* =====================================================================
   LIVE REFRESH (polling) + STARTUP
   ===================================================================== */
let polling = false;
async function pollTick() {
    if (polling) return;
    polling = true;
    try {
        const calls = [
            api("incidents.php?action=list&scope=recent"),
            api("incidents.php?action=list&scope=mine"),
            api("incidents.php?action=stats"),
            api("roster.php?action=list"),
            api("announcements.php?action=list")
        ];
        if (isResponder()) calls.push(api("incidents.php?action=list&scope=active"));

        const [recent, mine, stats, roster, ann, active] = await Promise.all(calls);
        state.recent = recent.incidents;
        state.mine = mine.incidents;
        state.stats = stats.stats;
        state.roster = roster.roster;
        state.announcements = ann.announcements;
        state.active = active ? active.incidents : [];

        notifyNewIncidents(state.active);
        firstPollDone = true;

        renderDispatchAlerts();
        renderMyRequests();
        renderDashboardStats();
        renderCPRRoster();
        renderAnnouncements();
        updateNotificationBell();
    } catch (err) {
        if (!err.network && err.status !== 401) console.error(err);
    } finally {
        polling = false;
    }
}

async function startDashboard() {
    if (localStorage.getItem(LS.sidebar) === "true") {
        const sidebar = document.getElementById("appSidebar");
        if (sidebar) sidebar.classList.add("collapsed");
    }

    let me;
    try {
        me = await api("auth.php?action=me");
    } catch (err) {
        if (err.status !== 401) toast(err.message, "error", 8000);
        return;                                  // 401 already redirected to the login page
    }
    state.user = me.user;
    renderProfile(); renderRoleDashboard();

    try {
        state.meta = await api("meta.php");
    } catch (err) {
        toast("Could not load campus data: " + err.message, "error", 8000);
    }

    fillSelect(document.getElementById("building"), state.meta.buildings, "Select Building");
    fillSelect(document.getElementById("incidentType"), state.meta.types, "Not sure / Other");
    fillSelect(document.getElementById("newMemberTeam"), state.meta.squads);
    fillSelect(document.getElementById("newMemberZone"), state.meta.zones);
    updateRoomDropdown();
    renderSquadOverview();

    await pollTick();

    if (localStorage.getItem(LS.alerts) === "true") {
        document.addEventListener("click", function once() {
            unlockAudio();
            updateAlertsButton();
            document.removeEventListener("click", once);
        });
    }
    updateAlertsButton();

    showSection(localStorage.getItem(LS.section) || "dashboard", true);
    setInterval(pollTick, POLL_MS);
}

async function startLoginPage() {
    // Already signed in? Skip the login screen.
    try {
        await api("auth.php?action=me");
        window.location.replace("dashboard.html");
    } catch (e) { /* not signed in: stay here */ }
}

function initApp() {
    if (document.getElementById("dashboard")) startDashboard();
    else startLoginPage();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initApp);
} else {
    initApp();
}


let isOtpVerified = false;

async function requestOTP() {
    const studentId = document.getElementById("regStudentId").value.trim();
    if (!studentId || !ID_REGEX.test(studentId)) {
        toast("Please enter a valid Student ID first (e.g. 23-1234-567).", "error");
        return;
    }

    const btn = document.getElementById("sendOtpBtn");
    btn.disabled = true;
    try {
        const res = await api("auth.php?action=send_otp", {
            method: "POST",
            body: { student_id: studentId }
        });
        document.getElementById("otpSection").style.display = "block";
        toast(`OTP Sent! (Demo OTP code: ${res.demo_otp})`, "success", 8000);
    } catch (err) {
        toast(err.message || "Failed to send OTP.", "error");
    } finally {
        btn.disabled = false;
    }
}

async function verifyOTP() {
    const studentId = document.getElementById("regStudentId").value.trim();
    const otpCode = document.getElementById("regOtpCode").value.trim();

    if (!otpCode || otpCode.length !== 6) {
        toast("Please enter a 6-digit OTP code.", "error");
        return;
    }

    const btn = document.getElementById("verifyOtpBtn");
    btn.disabled = true;
    try {
        const res = await api("auth.php?action=verify_otp", {
            method: "POST",
            body: { student_id: studentId, otp_code: otpCode }
        });

        if (res.verified) {
            isOtpVerified = true;
            document.getElementById("otpStatusMsg").style.display = "block";
            const submitBtn = document.getElementById("regSubmitBtn");
            submitBtn.disabled = false;
            submitBtn.style.opacity = "1";
            submitBtn.style.cursor = "pointer";
            toast("OTP verified successfully! You may now complete your registration.", "success");
        }
    } catch (err) {
        toast(err.message || "Invalid or expired OTP code.", "error");
    } finally {
        btn.disabled = false;
    }
}
