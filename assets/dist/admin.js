(function () {
    'use strict';
    var cfg = window.nobashBackup || {};
    var mount = document.getElementById('nobash-backup-app');
    if (!mount)
        return;

    function buildNav() {
        return [
            { id: 'dashboard', label: 'Overview', icon: 'dashboard' },
            { id: 'backup', label: 'Export', icon: 'backup' },
            { id: 'backups', label: 'Backups', icon: 'archive' },
            { id: 'restore', label: 'Restore', icon: 'restore' },
            { id: 'import', label: 'Import', icon: 'upload' },
            { separator: true, label: 'Management' },
            { id: 'automation', label: 'Schedules', icon: 'schedule' },
            { id: 'reports', label: 'Activity & Reports', icon: 'reports' },
            { id: 'settings', label: 'Settings', icon: 'settings' },
            { id: 'help', label: 'Help & Support', icon: 'info', hidden: true }
        ];
    }
    var NAV = buildNav();
    function uiStorageKey(name) {
        return 'nobash-backup-ui:' + String(cfg.homeUrl || window.location.host || 'site') + ':' + name;
    }
    function readStoredFlag(name) {
        try {
            return window.localStorage.getItem(uiStorageKey(name)) === '1';
        }
        catch (e) {
            return false;
        }
    }
    function writeStoredFlag(name, value) {
        try {
            window.localStorage.setItem(uiStorageKey(name), value ? '1' : '0');
        }
        catch (e) { /* Storage is optional. */ }
    }
    var modalWasOpen = false;
    var modalReturnFocus = null;
    var pendingConfirmResolve = null;
    var state = {
        page: normalizePage(cfg.screen || 'dashboard'),
        loading: true,
        allDataLoaded: false,
        loadError: null,
        busy: false,
        navOpen: false,
        notice: null,
        onboardingDismissed: readStoredFlag('onboarding-dismissed'),
        settingsDraft: {},
        settingsDirty: false,
        modal: null,
        job: null,
        jobAction: '',
        data: {
            status: null,
            backups: [],
            settings: {},
            basicSchedule: {},
            schedules: [],
            profiles: [],
            profileSchedules: [],
            reports: [],
            health: null,
            notificationRules: [],
            chainResult: null
        },
        selectedRestore: '',
        restorePasswordRequiredFor: '',
        transfer: null,
        importLocalFile: null,
        importTab: 'file', importUrlDraft: { url: '', sha256: '' },
        settingsTab: 'general',
        backupFilter: 'all', backupQuery: '', backupSort: 'newest', selectedBackups: [],
        backupsPage: 1,
        backupsPerPage: 10,
        openBackupMenu: '',
        reportFilters: { query: '', type: 'all', status: 'all' },
        reportPagination: { jobs: 1, audit: 1 },
        helpTopic: normalizeHelpTopic((new URLSearchParams(window.location.search)).get('topic') || 'docs')
    };

    function normalizePage(page) {
        var aliases = { export: 'backup', reset: 'settings' };
        page = aliases[page] || page;
        return NAV.some(function (item) { return item.id === page; }) ? page : 'dashboard';
    }
    function normalizeHelpTopic(topic) {
        var allowed = ['docs', 'overview', 'export', 'backups', 'restore', 'import', 'schedules', 'activity', 'settings', 'support'];
        topic = String(topic || 'docs').toLowerCase();
        return allowed.indexOf(topic) !== -1 ? topic : 'docs';
    }
    function helpTopicForPage(page) {
        var map = {
            dashboard: 'overview', backup: 'export', backups: 'backups', restore: 'restore',
            import: 'import', automation: 'schedules', reports: 'activity', settings: 'settings'
        };
        return map[page] || 'docs';
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function attr(value) { return esc(value); }
    function yes(value) { return !!value; }
    function array(value) { return Array.isArray(value) ? value : []; }
    function object(value) { return value && typeof value === 'object' && !Array.isArray(value) ? value : {}; }
    function number(value, fallback) { var n = Number(value); return isFinite(n) ? n : (fallback || 0); }
    function checked(value) { return value ? ' checked' : ''; }
    function selected(a, b) { return String(a) === String(b) ? ' selected' : ''; }
    function icon(name) {
        if (name === 'database') return backupsMockupIcon('database');
        var paths = {
            play: '<path d="m8 4 12 8-12 8z"/>',
            pencil: '<path d="m15 4 5 5M4 20l1-5L17 3l4 4L9 19z"/>',
            home: '<path d="m3 10 9-7 9 7M5 9v12h5v-7h4v7h5V9"/>',
            'file-export': '<path d="M13 3H5v18h8M13 3v5h5l-5-5M18 12v9m-4-4 4 4 4-4"/>',
            'upload-tray': '<path d="M12 3v12m-5-7 5-5 5 5M5 13l-2 8h18l-2-8M7 17h10"/>',
            arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
            calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 11h18M7 15h.01M12 15h.01M17 15h.01M7 18h.01M12 18h.01M17 18h.01"/>',
            dashboard: '<path d="M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z"/>',
            backup: '<path d="M12 3v12m0-12 4 4m-4-4L8 7M5 15v4h14v-4"/>',
            archive: '<path d="M4 6h16v14H4zM3 3h18v4H3zm6 8h6"/>',
            folder: '<path d="M3 6h7l2 2h9v11H3z"/>',
            restore: '<path d="M5 8V3m0 0h5M5 3l4 4a8 8 0 1 1-2 9"/>',
            upload: '<path d="M12 21V9m0 0-4 4m4-4 4 4M5 5h14"/>',
            migration: '<path d="M4 7h13m0 0-4-4m4 4-4 4M20 17H7m0 0 4 4m-4-4 4-4"/>',
            schedule: '<circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/>',
            reports: '<path d="M4 20V10m6 10V4m6 16v-7m4 7H2"/>',
            settings: '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.5-2.4 1a7 7 0 0 0-1.7-1L14.5 3h-5L9 6a7 7 0 0 0-1.7 1L5 6 3 9.5 5.1 11a7 7 0 0 0 0 2L3 14.5 5 18l2.3-1a7 7 0 0 0 1.7 1l.5 3h5l.5-3a7 7 0 0 0 1.7-1l2.3 1 2-3.5-2.1-1.5a7 7 0 0 0 .1-1z"/>',
            health: '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
            shield: '<path d="M12 3c2.4 1.2 5.3 1.8 8 1.8v6.5c0 4.6-2.9 8.4-8 10.7-5.1-2.3-8-6.1-8-10.7V4.8C6.7 4.8 9.6 4.2 12 3z"/><path d="m8.5 12 2.2 2.2 4.8-5"/>',
            database: '<ellipse cx="12" cy="5" rx="7" ry="3"/><path d="M5 5v6c0 1.7 3.1 3 7 3s7-1.3 7-3V5M5 11v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
            file: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v5h5M9 13h6M9 17h5"/>',
            clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            layers: '<path d="m12 3 9 5-9 5-9-5 9-5zM3 12l9 5 9-5M3 16l9 5 9-5"/>',
            bolt: '<path d="m13 2-8 12h7l-1 8 8-12h-7l1-8z"/>',
            chevron: '<path d="m9 18 6-6-6-6"/>',
            server: '<rect x="4" y="4" width="16" height="6" rx="1"/><rect x="4" y="14" width="16" height="6" rx="1"/><path d="M8 7h.01M8 17h.01"/>',
            lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            eye: '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="2.5"/>',
            'eye-off': '<path d="m3 3 18 18M10.6 6.2A10.5 10.5 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-3 3.7M6.5 6.6C3.9 8.4 2.5 12 2.5 12s3.5 6 9.5 6c1.2 0 2.3-.2 3.3-.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
            check: '<path d="m4 12 5 5L20 6"/>',
            warning: '<path d="M12 3 2 21h20L12 3z"/><path d="M12 9v5m0 3v.01"/>',
            plus: '<path d="M12 5v14M5 12h14"/>',
            refresh: '<path d="M20 7v5h-5M4 17v-5h5M6 8a7 7 0 0 1 12-2l2 2M18 16a7 7 0 0 1-12 2l-2-2"/>',
            trash: '<path d="M4 7h16M9 7V4h6v3m-9 0 1 14h10l1-14M10 11v6m4-6v6"/>',
            link: '<path d="M10 13a5 5 0 0 0 7.1.1l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1M14 11a5 5 0 0 0-7.1-.1l-2 2A5 5 0 0 0 12 20l1.1-1.1"/>',
            download: '<path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"/>',
            info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-9v.01"/>',
            mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
            search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
            menu: '<path d="M4 7h16M4 12h16M4 17h16"/>'
        };
        return '<svg class="nbh-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (paths[name] || paths.dashboard) + '</svg>';
    }

    function formatBytes(bytes) {
        bytes = number(bytes, 0);
        if (bytes <= 0)
            return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return (bytes / Math.pow(1024, i)).toFixed(i ? 1 : 0) + ' ' + units[i];
    }
    function formatDate(value) {
        if (!value)
            return 'Never';
        var d = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
        return isNaN(d.getTime()) ? esc(value) : d.toLocaleString();
    }


    function apiBase() {
        return String(cfg.root || '').replace(/\/$/, '');
    }
    function apiRequestUrl(path) {
        var base = apiBase();
        if (!cfg.restRouteMode)
            return base + path;
        var rawPath = String(path || '');
        var queryAt = rawPath.indexOf('?');
        var routePath = queryAt === -1 ? rawPath : rawPath.slice(0, queryAt);
        var routeQuery = queryAt === -1 ? '' : rawPath.slice(queryAt + 1);
        var route = '/nobash-backup/v1/' + routePath.replace(/^\/+/, '');
        var url = new URL(String(cfg.restRouteBase || cfg.siteUrl || window.location.origin).replace(/\/$/, '') + (cfg.restRouteBase ? '' : '/index.php'), window.location.href);
        url.searchParams.set('rest_route', route.replace(/\/{2,}/g, '/'));
        if (routeQuery) {
            new URLSearchParams(routeQuery).forEach(function (value, key) { url.searchParams.append(key, value); });
        }
        return url.toString();
    }
    async function request(path, options) {
        options = options || {};
        if (!apiBase())
            throw new Error('Nobash Backup API is unavailable.');
        var headers = Object.assign({ 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' }, options.headers || {});
        var init = { method: options.method || 'GET', credentials: 'same-origin', headers: headers };
        if (options.body instanceof FormData || options.raw === true || options.body instanceof Blob || options.body instanceof ArrayBuffer) {
            init.body = options.body;
        } else if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        var response;
        try { response = await fetch(apiRequestUrl(path), init); }
        catch (networkError) {
            var connectionError = new Error('The server could not be reached.');
            connectionError.code = 'network_error'; connectionError.status = 0; connectionError.path = path; connectionError.original = networkError;
            throw connectionError;
        }
        if (options.blob) {
            if (!response.ok) { var downloadError = new Error('Download failed (' + response.status + ').'); downloadError.status = response.status; downloadError.path = path; throw downloadError; }
            return response.blob();
        }
        var text = await response.text();
        var data = text ? (function () { try { return JSON.parse(text); } catch (e) { return { message: text }; } }()) : {};
        if (!response.ok) {
            var error = new Error((data && data.message) || ('Request failed (' + response.status + ').'));
            error.code = data && data.code; error.data = data && data.data; error.status = response.status; error.path = path; error.response = data;
            throw error;
        }
        return data;
    }

    function updateToolbarBackupCount(count) {
        var label = ' (' + Number(count).toLocaleString() + ')';
        document.querySelectorAll('.nobash-backup-toolbar-count').forEach(function (node) {
            node.textContent = label;
        });
    }
    function core(path, options) {
        return request(path, options).then(function (data) {
            if (path === '/backups' && data && Array.isArray(data.items))
                updateToolbarBackupCount(data.items.length);
            return data;
        });
    }

    function notify(type, message, action) {
        state.notice = { type: type || 'info', message: message, action: action || null };
        render();
        window.setTimeout(function () {
            if (state.notice && state.notice.message === message) {
                state.notice = null;
                render();
            }
        }, action ? 15000 : 5000);
    }
    function errorText(error) {
        if (!error)
            return '';
        if (typeof error === 'string')
            return error;
        if (error.message)
            return String(error.message);
        return String(error);
    }
    function friendlyImportError(error, source) {
        var raw = errorText(error) || 'The import could not be completed.';
        var lower = raw.toLowerCase();
        var status = number(error && error.status, 0);
        var code = String(error && error.code || '').toLowerCase();
        var local = source === 'local';
        if (status === 401 || status === 403 || lower.indexOf('forbidden') !== -1 || lower.indexOf('unauthorized') !== -1)
            return local ? 'The server rejected the upload. Check your WordPress permissions and try again.' : 'The remote server denied access to the archive. Use a public or valid signed HTTPS URL.';
        if (status === 404 || lower.indexOf('http 404') !== -1 || lower.indexOf('not found') !== -1)
            return 'The archive was not found. Check the file or URL and try again.';
        if (status === 413 || lower.indexOf('too large') !== -1 || lower.indexOf('exceeds') !== -1)
            return local ? 'This archive exceeds the current upload limit. Increase the server limit or use secure URL import.' : 'The archive exceeds the configured safe import limit.';
        if (status === 429 || lower.indexOf('rate limit') !== -1 || lower.indexOf('too many') !== -1)
            return 'Too many import requests were made. Wait a few minutes and try again.';
        if (status === 408 || status === 504 || code === 'network_error' || lower.indexOf('timed out') !== -1 || lower.indexOf('timeout') !== -1 || lower.indexOf('failed to fetch') !== -1)
            return 'The source could not be reached or timed out. Check the connection and try again.';
        if (lower.indexOf('checksum mismatch') !== -1 || lower.indexOf('sha-256 verification failed') !== -1)
            return 'SHA-256 verification failed. Confirm that the hash matches this exact archive and try again.';
        if (lower.indexOf('invalid archive') !== -1 || lower.indexOf('not a valid nbh') !== -1 || lower.indexOf('archive header') !== -1 || lower.indexOf('archive format') !== -1)
            return 'The selected file is not a valid .nbh archive.';
        if (lower.indexOf('offset mismatch') !== -1)
            return 'The resumable upload position no longer matches the server. Select the same archive again to resume safely.';
        if (lower.indexOf('transfer') !== -1 && lower.indexOf('expired') !== -1)
            return 'The saved resumable transfer expired. Select the archive again to start a new transfer.';
        if (status >= 500)
            return 'The WordPress server could not complete the import. Check server logs and try again.';
        return raw;
    }

    function validateRemoteImport(values) {
        values = object(values);
        var url = String(values.url || '').trim();
        if (!url) {
            var required = new Error('URL required');
            required.code = 'import_url_required';
            throw required;
        }
        var parsed;
        try {
            parsed = new URL(url);
        }
        catch (e) {
            var invalid = new Error('Invalid URL');
            invalid.code = 'import_url_required';
            throw invalid;
        }
        if (parsed.protocol !== 'https:') {
            var httpsError = new Error('HTTPS required');
            httpsError.code = 'import_url_https';
            throw httpsError;
        }
        if (parsed.username || parsed.password || !parsed.hostname) {
            var credentialsError = new Error('Invalid URL');
            credentialsError.code = 'import_url_required';
            throw credentialsError;
        }
        var sha = String(values.sha256 || '').trim().toLowerCase();
        if (sha && !/^[a-f0-9]{64}$/.test(sha)) {
            var shaError = new Error('Invalid SHA-256');
            shaError.code = 'import_sha256_invalid';
            throw shaError;
        }
        return { url: parsed.toString(), sha256: sha };
    }
    function modalImportError(message, title) {
        if (!message)
            return '';
        return '<div class="nbh-callout is-error nbh-import-inline-error" role="alert">' + icon('warning') + '<div><strong>' + esc(title || 'Import could not start') + '</strong><p>' + esc(message) + '</p></div></div>';
    }
    function jobErrorMessage(job) {
        job = object(job);
        var raw = typeof job.error === 'string' ? job.error : errorText(job.error);
        if (!raw && job.error_message)
            raw = job.error_message;
        if (!raw)
            return '';
        return job.type === 'remote_import' ? friendlyImportError({ message: raw, code: job.error_code }, 'url') : raw;
    }
    async function loadData() {
        state.loading = true;
        state.loadError = null;
        render();
        var jobs = [
            ['status', core('/status')], ['backups', core('/backups')], ['settings', core('/settings')],
            ['profiles', core('/profiles')], ['profileSchedules', core('/schedules')], ['health', core('/reports')]
        ];
        var results = await Promise.allSettled(jobs.map(function (job) { return job[1]; }));
        var failures = [];
        results.forEach(function (result, index) {
            var key = jobs[index][0];
            if (result.status !== 'fulfilled') {
                failures.push({ key: key, message: result.reason && result.reason.message ? result.reason.message : 'Request failed.' });
                return;
            }
            var value = result.value;
            if (key === 'backups') state.data.backups = array(value.items);
            else if (key === 'settings') {
                state.data.settings = object(value.settings);
                state.data.encryption = object(value.encryption);
                if (!state.settingsDirty) state.settingsDraft = JSON.parse(JSON.stringify(state.data.settings));
                state.data.basicSchedule = object(value.schedules);
                state.data.schedules = array(value.schedule_status);
                if (array(value.profiles).length) state.data.profiles = array(value.profiles);
            }
            else if (key === 'profiles') state.data.profiles = array(value.profiles);
            else if (key === 'profileSchedules') {
                state.data.profileSchedules = array(value.schedules);
                state.data.schedules = array(value.schedules);
            }
            else state.data[key] = value;
        });
        var criticalFailures = failures.filter(function (failure) { return ['status', 'backups', 'settings'].indexOf(failure.key) !== -1; });
        if (criticalFailures.length) {
            state.loadError = {
                fatal: criticalFailures.length >= 2,
                message: criticalFailures.length >= 2 ? 'Nobash Backup could not connect to enough of its local WordPress API to render safely.' : 'Some protection data could not be loaded.',
                detail: criticalFailures.map(function (failure) { return failure.key + ': ' + failure.message; }).join(' · ')
            };
        }
        state.loading = false;
        state.allDataLoaded = true;
        render();
    }
    async function loadInitialPageData() {
        await loadData();
    }

    function ensureFullDataLoaded() {
        if (state.allDataLoaded || state.loading)
            return;
        loadData().catch(function (error) {
            state.loading = false;
            notify('error', error.message || 'Nobash Backup could not load this section.');
        });
    }

    function pageMeta() {
        var map = {
            dashboard: ['Overview', 'Monitor your backups, system status and take action quickly.'],
            backup: ['Export', 'Create a portable backup archive of your website that you can download or store elsewhere.'],
            backups: ['Backups', 'Your local backups, ready when you need them.'],
            restore: ['Restore', 'Recover the site with verification, safety backup and rollback protection.'],
            import: ['Import', 'Import a backup archive for recovery or migration to this site.'],
            automation: ['Schedules', 'Create and manage automatic backup schedules for this site.'],
            reports: ['Activity & Reports', 'Track backup activity, view logs and download reports.'],
            settings: ['Settings', 'Manage backup defaults, retention, exclusions and notifications.'],
            help: ['Help & Support', 'Documentation for every Nobash Backup workflow.']
        };
        return map[state.page] || map.dashboard;
    }
    function pageIconName() {
        var icons = {
            dashboard: 'dashboard', backup: 'backup', backups: 'archive', restore: 'restore',
            import: 'upload', automation: 'schedule', reports: 'reports', settings: 'settings', help: 'info'
        };
        return icons[state.page] || 'dashboard';
    }
    function render() {
        var meta = pageMeta();
        var openingModal = !!state.modal && !modalWasOpen;
        var closingModal = !state.modal && modalWasOpen;
        if (openingModal) modalReturnFocus = document.activeElement;
        mount.innerHTML = '';
        mount.className = 'nbh-mount nbh-unified-mount';
        var shell = document.createElement('div');
        shell.className = 'nbh-unified';
        shell.innerHTML =
            '<a class="nbh-skip-link" href="#nbh-main">Skip to main content</a>' +
            '<div class="nbh-layout' + (state.navOpen ? ' is-nav-open' : '') + '">' +
            '<button class="nbh-sidebar-scrim" data-action="close-nav" aria-label="Close navigation"></button>' +
            '<aside class="nbh-sidebar" id="nbh-sidebar" aria-label="Nobash Backup navigation">' +
            '<div class="nbh-sidebar-brand"><img class="nbh-sidebar-brand__shield" src="' + attr(cfg.logoUrl || '') + '" alt="" width="128" height="128"><span class="nbh-sidebar-brand__wordmark"><img class="nbh-sidebar-brand__final-logo" src="' + attr(cfg.brandWordmarkUrl || '') + '" alt="Nobash Backup" width="2172" height="724"></span></div>' +
            '<nav>' + renderNav() + '</nav>' +
            '<section class="nbh-sidebar-support-card" aria-labelledby="nbh-sidebar-support-card-title"><div class="nbh-sidebar-support-card__head"><span>' + icon('info') + '</span><strong id="nbh-sidebar-support-card-title">Need help?</strong></div><p>Get friendly, professional help from the Nobash team when you need it.</p><a href="https://nobash.com/" target="_blank" rel="noopener noreferrer">Get Help' + icon('chevron') + '</a></section>' +
            '<div class="nbh-sidebar__footer"><span>Version ' + esc(cfg.version || '1.0.0.1') + '</span><span>Nobash Backup</span></div>' +
            '</aside><div class="nbh-content">' +
            '<header class="nbh-mobile-topbar"><button class="nbh-mobile-menu" data-action="toggle-nav" aria-controls="nbh-sidebar" aria-expanded="' + (state.navOpen ? 'true' : 'false') + '" aria-label="' + (state.navOpen ? 'Close navigation' : 'Open navigation') + '">' + icon('menu') + '</button><div class="nbh-mobile-brand"><img src="' + attr(cfg.logoUrl || '') + '" alt=""></div><button class="nbh-icon-button" data-action="navigate" data-page="backup" aria-label="Create backup">' + icon('plus') + '</button></header>' +
            '<main class="nbh-main nbh-page--' + attr(state.page) + '" id="nbh-main" tabindex="-1" aria-busy="' + (state.loading && state.page !== 'help' ? 'true' : 'false') + '">' +
            '<div class="nbh-live-region" role="status" aria-live="polite" aria-atomic="true">' + (state.notice ? esc(state.notice.message) : '') + '</div>' +
            (state.notice ? renderNotice(state.notice) : '') +
            (state.page === 'dashboard' && !state.loading && !(state.loadError && state.loadError.fatal) ? '' : '<div class="nbh-page-head"><div class="nbh-page-head__identity"><span class="nbh-page-head__icon">' + icon(pageIconName()) + '</span><div class="nbh-page-head__copy"><div class="nbh-title-row"><h1>' + esc(meta[0]) + '</h1></div><p>' + esc(meta[1]) + '</p></div></div><div class="nbh-page-head__actions">' + pageHeaderActions() + '</div></div>') +
            ((state.loading && state.page !== 'help') ? renderSkeleton() : ((state.page !== 'help' && state.loadError && state.loadError.fatal) ? renderLoadError() : (((state.page !== 'help' && state.loadError) ? renderLoadWarning() : '') + renderPage()))) +
            '</main></div></div><div id="nbh-modal-root">' + renderModal() + '</div>' +
            (state.job && !(state.modal && state.modal.type === 'job-warnings') ? renderJobOverlay() : '') +
            (state.transfer ? renderTransferOverlay() : '');
        mount.appendChild(shell);
        document.body.classList.toggle('nbh-modal-open', !!state.modal || !!state.job || !!state.transfer);
        document.title = meta[0] + ' ‹ Nobash Backup';
        modalWasOpen = !!state.modal;
        window.requestAnimationFrame(function () {
            if (openingModal) {
                var modal = mount.querySelector('[data-modal-panel]');
                var focusable = modal && modal.querySelector('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]');
                if (focusable) focusable.focus();
                else if (modal) { modal.setAttribute('tabindex', '-1'); modal.focus(); }
            }
            if (closingModal && modalReturnFocus && document.contains(modalReturnFocus)) modalReturnFocus.focus();
        });
    }
    function renderNav() {
        var navIcons = { dashboard: 'home', backup: 'file-export', backups: 'database', import: 'upload-tray', automation: 'calendar' };
        return NAV.map(function (item) {
            if (item.hidden) return '';
            if (item.separator) return '<div class="nbh-nav-label" role="presentation">' + esc(item.label) + '</div>';
            if (item.href) return '<a class="nbh-nav-item nbh-nav-item--pro" href="' + attr(item.href) + '" target="_blank" rel="noopener noreferrer">' + icon(item.icon) + '<span>' + esc(item.label) + '</span>' + icon('chevron') + '</a>';
            var badge = item.badge ? '<em>' + esc(item.badge) + '</em>' : '';
            var count = item.id === 'backups' && state.data.backups.length ? '<b class="nbh-nav-count">' + esc(state.data.backups.length) + '</b>' : '';
            return '<button class="nbh-nav-item' + (state.page === item.id ? ' is-active' : '') + '" data-action="navigate" data-page="' + attr(item.id) + '" aria-current="' + (state.page === item.id ? 'page' : 'false') + '">' + icon(navIcons[item.id] || item.icon) + '<span>' + esc(item.label) + '</span>' + count + badge + '</button>';
        }).join('');
    }


    function pageHeaderActions() {
        if (state.page === 'backups' || state.page === 'automation') return pageActions();
        var html = pageActions();
        if (state.page !== 'help' && state.page !== 'dashboard') {
            html += '<button class="nbh-button nbh-help-button" data-action="open-help-doc" data-topic="' + attr(helpTopicForPage(state.page)) + '">' + icon('info') + (state.page === 'backup' ? ' Help &amp; Documentation' : ' Help') + '</button>';
        }
        return html;
    }
    function pageActions() {
        if (state.page === 'dashboard')
            return '<button class="nbh-button nbh-button--primary" data-action="navigate" data-page="backup">' + icon('database') + ' Create Backup' + icon('chevron') + '</button>';
        if (state.page === 'backups')
            return '<button class="nbh-button" data-action="refresh">' + icon('refresh') + ' Refresh</button><button class="nbh-button nbh-button--primary" data-action="navigate" data-page="backup">' + backupsMockupIcon('create') + ' Create Backup</button>';
        if (state.page === 'automation') return '<button class="nbh-button nbh-button--primary" data-action="open-schedule-editor">' + icon('calendar') + ' Create schedule' + icon('chevron') + '</button>';
        if (state.page === 'reports')
            return '<details class="nbh-activity-options"><summary class="nbh-button">Screen Options ' + icon('chevron') + '</summary><div><button class="nbh-button" data-action="report-toggle-chart">' + icon('reports') + (object(state.reportSections).chart === false ? ' Show activity chart' : ' Hide activity chart') + '</button><button class="nbh-button" data-action="refresh">' + icon('refresh') + ' Refresh data</button></div></details>';
        return '';
    }
    function renderNotice(notice) {
        var action = notice.action ? '<button class="nbh-button nbh-button--small nbh-notice__action" data-action="' + attr(notice.action.action) + '">' + esc(notice.action.label) + '</button>' : '';
        return '<div class="nbh-notice is-' + attr(notice.type) + '" role="' + (notice.type === 'error' ? 'alert' : 'status') + '"><span>' + (notice.type === 'error' ? icon('warning') : icon('check')) + '</span><p>' + esc(notice.message) + '</p>' + action + '<button aria-label="Dismiss notification" data-action="dismiss-notice">×</button></div>';
    }
    function renderSkeleton() {
        return '<div class="nbh-skeleton-page" aria-label="Loading dashboard data"><div class="nbh-skeleton-metrics"><i></i><i></i><i></i><i></i></div><div class="nbh-skeleton-grid"><div></div><div></div></div><div class="nbh-skeleton-wide"></div></div>';
    }
    function renderLoadError() {
        var error = object(state.loadError);
        return '<section class="nbh-error-state">' + icon('warning') + '<h2>Protection data is temporarily unavailable</h2><p>' + esc(error.message || 'Nobash Backup could not load this screen.') + '</p>' + (error.detail ? '<small>' + esc(error.detail) + '</small>' : '') + '<div class="nbh-button-row"><button class="nbh-button nbh-button--primary" data-action="retry-load">Try again</button></div></section>';
    }
    function renderLoadWarning() {
        var error = object(state.loadError);
        return '<div class="nbh-callout is-warning nbh-partial-warning">' + icon('warning') + '<div><strong>Some data is unavailable</strong><p>' + esc(error.detail || error.message || 'A local API request failed.') + '</p></div><button class="nbh-button nbh-button--small" data-action="retry-load">Retry</button></div>';
    }
    function renderPage() {
        var pages = {
            dashboard: renderDashboard, backup: renderBackup, backups: renderBackups, restore: renderRestore,
            import: renderImport, automation: renderAutomation, reports: renderReports,
            settings: renderSettings, help: renderHelpSupport
        };
        try {
            return (pages[state.page] || renderDashboard)();
        } catch (error) {
            if (window.console && typeof window.console.error === 'function') window.console.error('Nobash Backup page render failed:', error);
            return '<section class="nbh-error-state">' + icon('warning') + '<h2>This screen could not be rendered</h2><p>' + esc((error && error.message) || 'An unexpected interface error occurred.') + '</p><div class="nbh-button-row"><button class="nbh-button nbh-button--primary" data-action="retry-load">Reload this screen</button></div></section>';
        }
    }
    function overviewNextScheduleLabel(schedules) {
        var active = array(schedules).filter(function (schedule) { return schedule && schedule.enabled !== false && schedule.status !== 'disabled'; });
        if (!active.length)
            return 'No active schedule';
        var next = active[0] || {};
        return next.next_run_human || next.next_run_display || next.next_run_local || overviewRelativeDate(scheduleDate(next), true) || (active.length + ' active schedule' + (active.length === 1 ? '' : 's'));
    }
    function overviewRelativeDate(raw, future) {
        if (!raw) return '';
        var date = typeof raw === 'number' || /^\d+$/.test(String(raw)) ? new Date(Number(raw) * 1000) : new Date(raw);
        if (isNaN(date.getTime())) return '';
        var seconds = Math.abs(date.getTime() - Date.now()) / 1000;
        var unit = seconds >= 86400 ? 'day' : (seconds >= 3600 ? 'hour' : 'minute');
        var amount = Math.max(1, (future ? Math.ceil : Math.floor)(seconds / (unit === 'day' ? 86400 : unit === 'hour' ? 3600 : 60)));
        return (future ? 'in ' : '') + amount + ' ' + unit + (amount === 1 ? '' : 's') + (future ? '' : ' ago');
    }
    function renderDashboard() {
        var status = object(state.data.status);
        var backups = array(state.data.backups).slice().sort(function (a, b) {
            return number(b.modified || b.created || b.timestamp, 0) - number(a.modified || a.created || a.timestamp, 0);
        });
        var schedules = array(state.data.profileSchedules).length ? array(state.data.profileSchedules) : array(state.data.schedules);
        var enabledSchedules = schedules.filter(function (schedule) { return schedule && schedule.enabled !== false && schedule.status !== 'disabled'; });
        var blockers = array(object(status.compatibility).blockers);
        var latestBackup = backups[0] || null;
        var latestParts = dashboardDateParts(latestBackup || status.last_backup, 'No backup yet');
        var totalBytes = backups.reduce(function (sum, backup) { return sum + number(backup.size, 0); }, 0);
        var protectedState = backups.length > 0 && enabledSchedules.length > 0 && blockers.length === 0;
        var attentionPage = blockers.length ? 'settings' : (!backups.length ? 'backup' : (!enabledSchedules.length ? 'automation' : 'backups'));
        var protectionDetail = protectedState ? 'Backups are running as scheduled and your site is healthy.' : (blockers.length ? 'Review the system checks before relying on recovery.' : (!backups.length ? 'Create your first backup to establish a recovery point.' : 'Add a schedule to keep recovery points current.'));
        var nextBackup = overviewNextScheduleLabel(enabledSchedules);
        var nextSchedule = enabledSchedules[0] || {};
        var nextParts = dashboardDateParts(scheduleDate(nextSchedule), 'Automatic backup schedule');
        var nextDetail = nextSchedule.next_run_display || nextSchedule.next_run_local || [nextParts.primary, nextParts.secondary].filter(Boolean).join(' at ');
        var frequency = String(nextSchedule.recurrence || nextSchedule.frequency || '').replace(/[_-]/g, ' ');
        var scheduleBadge = 'Scheduled' + (frequency ? ' (' + frequency.charAt(0).toUpperCase() + frequency.slice(1) + ')' : '');
        var freeBytes = Number(object(status.storage).free_space);
        var storageMeter = Number.isFinite(freeBytes) && freeBytes >= 0 ? '<span class="nbh-overview-storage"><span class="nbh-overview-storage__track"><i style="width:' + Math.min(100, totalBytes / Math.max(1,totalBytes + freeBytes) * 100) + '%"></i></span><small>' + esc(formatBytes(freeBytes)) + ' remaining on disk</small></span>' : ''; 
        return '<section class="nbh-overview-main">' +
            '<header class="nbh-overview-hero"><div class="nbh-overview-hero__copy"><h1>Overview</h1><p>A complete snapshot of your website&#39;s backup health and activity.</p></div><div class="nbh-overview-hero__action">' + pageActions() + '</div></header>' +
            '<section class="nbh-overview-protection ' + (protectedState ? 'is-protected' : 'needs-attention') + '"><span class="nbh-overview-protection__shield" aria-hidden="true">' + icon(protectedState ? 'shield' : 'warning') + '</span><div class="nbh-overview-protection__copy"><h2>' + esc(protectedState ? 'Your site is protected!' : 'Protection needs attention') + '</h2><p>' + esc(protectedState ? 'Automatic backups are enabled and running on schedule.' : protectionDetail) + '</p></div>' + (protectedState ? '<span class="nbh-overview-protection__review is-status"><i aria-hidden="true"></i>No action needed</span>' : '<button class="nbh-overview-protection__review" data-action="navigate" data-page="' + attr(attentionPage) + '">Review protection' + icon('chevron') + '</button>') + '</section>' +
            '<section class="nbh-overview-main__metrics" aria-label="Backup summary">' +
                overviewPremiumMetric('database', 'Last backup', overviewRelativeDate(dashboardDateRaw(latestBackup), false) || latestParts.primary, backups.length ? ([latestParts.primary, latestParts.secondary].filter(Boolean).join(' at ') || 'Completed successfully') : 'Create your first backup', backups.length ? 'success' : 'neutral', backups.length ? 'backups' : 'backup') +
                overviewPremiumMetric('calendar', 'Next backup', nextBackup, enabledSchedules.length ? nextDetail : 'Add an automatic backup schedule', enabledSchedules.length ? 'scheduled' : 'neutral', 'automation', '', scheduleBadge) +
                overviewPremiumMetric('server', 'Storage used', formatBytes(totalBytes), backups.length ? backups.length + ' local backup' + (backups.length === 1 ? '' : 's') : 'No local backups yet', 'storage', 'backups', storageMeter) +
            '</section>' +
            '<section class="nbh-overview-main__quick">' +
                '<div class="nbh-overview-main__quick-head"><div><h2>Quick Actions</h2><p>Common tasks to keep your website safe and manageable.</p></div></div>' +
                '<div class="nbh-overview-main__quick-grid">' +
                    overviewQuickAction('database', 'Create Backup', 'Create a new backup of your website right now.', 'backup', 'primary') +
                    overviewQuickAction('restore', 'Restore Site', 'Restore your website from a previous backup.', 'restore', 'orange') +
                    overviewQuickAction('upload-tray', 'Import Backup', 'Import a backup file from your computer or another source.', 'import', 'purple') +
                    overviewQuickAction('calendar', 'Manage Schedules', 'Set up and manage automated backup schedules.', 'automation', 'green') +
                '</div>' +
            '</section>' +
        '</section>';
    }
    function overviewPremiumMetric(iconName, label, value, detail, tone, page, extra, badgeLabel) {
        return '<button class="nbh-overview-main__metric is-' + attr(tone) + '" data-action="navigate" data-page="' + attr(page) + '">' +
            '<span class="nbh-overview-main__metric-icon">' + icon(iconName) + '</span>' +
            '<span class="nbh-overview-main__metric-copy"><small>' + esc(label) + '</small><strong>' + esc(value) + '</strong><em>' + esc(detail || '') + '</em></span>' +
            (tone === 'success' || tone === 'scheduled' ? '<span class="nbh-overview-main__metric-badge">' + icon(tone === 'success' ? 'shield' : 'schedule') + esc(tone === 'success' ? 'Completed successfully' : (badgeLabel || 'Scheduled backups')) + '</span>' : '') +
            (extra || '') +
        '</button>';
    }
    function overviewQuickAction(iconName, title, detail, page, tone) {
        return '<button class="nbh-overview-main__quick-action is-' + attr(tone) + '" data-action="navigate" data-page="' + attr(page) + '">' +
            '<span class="nbh-overview-main__quick-icon">' + icon(iconName) + '</span>' +
            '<span class="nbh-overview-main__quick-copy"><strong>' + esc(title) + '</strong><small>' + esc(detail) + '</small></span>' +
            '<span class="nbh-overview-main__quick-arrow">' + icon('arrow') + '</span>' +
        '</button>';
    }


    function dashboardDateParts(value, fallback) {
        var raw = dashboardDateRaw(value);
        if (!raw)
            return { primary: fallback || 'Unavailable', secondary: '' };
        var date = typeof raw === 'number' ? new Date(raw * 1000) : new Date(raw);
        if (isNaN(date.getTime()))
            return { primary: String(raw), secondary: '' };
        return {
            primary: date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }),
            secondary: date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
        };
    }
    function dashboardDateRaw(value) {
        if (!value)
            return '';
        if (typeof value !== 'object')
            return value;
        var candidates = ['modified', 'completed_at', 'finished_at', 'created_at', 'created', 'date', 'timestamp', 'time'];
        for (var i = 0; i < candidates.length; i += 1) {
            if (value[candidates[i]])
                return dashboardDateRaw(value[candidates[i]]);
        }
        return '';
    }


    function metric(label, value, hint) {
        return '<div class="nbh-metric"><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong><small>' + esc(hint || '') + '</small></div>';
    }


    function renderBackup() {
        var profiles = array(state.data.profiles).filter(function (profile) { return profile && profile.enabled !== false; });
        var fallbackProfiles = [
            { id: 0, profile_key: 'full', name: 'Full site', content_type: 'full', backup_mode: 'full' },
            { id: 0, profile_key: 'database', name: 'Database only', content_type: 'database', backup_mode: 'full' },
            { id: 0, profile_key: 'files', name: 'Files only', content_type: 'files', backup_mode: 'full' },
            { id: 0, profile_key: 'uploads', name: 'Media uploads', content_type: 'uploads', backup_mode: 'full' },
            { id: 0, profile_key: 'plugins', name: 'Plugins only', content_type: 'plugins', backup_mode: 'full' },
            { id: 0, profile_key: 'themes', name: 'Themes only', content_type: 'themes', backup_mode: 'full' }
        ];
        if (!profiles.length)
            profiles = fallbackProfiles;
        var preferredKey = state.data.settings.default_profile || 'full';
        var preferredProfile = profiles.find(function (profile) { return profile.profile_key === preferredKey || profile.content_type === preferredKey; }) || profiles[0];
        var preferredValue = preferredProfile.id ? String(preferredProfile.id) : 'legacy:' + preferredProfile.profile_key;
        var preferredMode = preferredProfile.backup_mode || 'full';
        var backups = array(state.data.backups);
        var localBytes = backups.reduce(function (sum, backup) { return sum + number(backup.size, 0); }, 0);
        var storage = object(object(state.data.status).storage);
        var localPath = storage.path || 'Private storage is not initialized';
        var localDisplayPath = String(localPath).replace(/\\/g, '/');
        var contentPathStart = localDisplayPath.indexOf('wp-content/');
        if (contentPathStart !== -1) localDisplayPath = localDisplayPath.slice(contentPathStart);
        function profileCard(profile) {
            var value = profile.id ? String(profile.id) : 'legacy:' + profile.profile_key;
            var profileName = profile.name || 'Export profile';
            var profileType = profile.content_type || profile.type || 'full';
            var cardDescriptions = { full: 'Include your entire website files and database.', files: 'Only export website files (themes, plugins, uploads).', database: 'Only export the database (content, settings, users).' };
            var cardIcons = { full: 'database', files: 'folder', database: 'server', custom: 'settings', uploads: 'upload', plugins: 'layers', themes: 'file' };
            return '<button type="button" class="nbh-export-content-card is-' + attr(profileType) + (value === preferredValue ? ' is-selected' : '') + '" role="option" aria-selected="' + String(value === preferredValue) + '" data-action="select-export-content" data-value="' + attr(value) + '" data-profile-name="' + attr(profileName) + '" data-profile-type="' + attr(profileType) + '" data-profile-description="' + attr(exportProfileDescription(profileType)) + '"><span class="nbh-export-content-card__icon">' + icon(cardIcons[profileType] || 'layers') + '</span><strong>' + esc(profileName) + '</strong><small>' + esc(cardDescriptions[profileType] || exportProfileDescription(profileType)) + '</small><span class="nbh-export-content-card__check">' + icon('check') + '</span></button>';
        }
        var coreTypes = ['full', 'files', 'database'];
        var primaryProfiles = coreTypes.map(function (type) { return profiles.find(function (profile) { return (profile.content_type || profile.type) === type; }); }).filter(Boolean);
        var customProfile = profiles.find(function (profile) { return (profile.content_type || profile.type) === 'custom'; });
        if (customProfile) primaryProfiles.push(customProfile);
        var primaryCards = primaryProfiles.map(profileCard).join('');
        if (!customProfile) primaryCards += '<button type="button" class="nbh-export-content-card is-custom" data-action="add-profile"><span class="nbh-export-content-card__icon">' + icon('settings') + '</span><strong>Custom</strong><small>Choose specific files, folders or database tables.</small><span class="nbh-export-content-card__check">' + icon('plus') + '</span></button>';
        var additionalProfiles = profiles.filter(function (profile) { return primaryProfiles.indexOf(profile) === -1; });
        var additionalCards = additionalProfiles.length ? '<details class="nbh-export-extra-profiles"' + (additionalProfiles.indexOf(preferredProfile) !== -1 ? ' open' : '') + '><summary>More content profiles</summary><div class="nbh-export-content-grid">' + additionalProfiles.map(profileCard).join('') + '</div></details>' : '';
        return '<section class="nbh-export-r15 nbh-export-v19 nbh-export-v21">' +
            '<form id="nbh-backup-form" class="nbh-export-r15__form">' +
            '<input type="hidden" name="scope" value="site"><input type="hidden" name="chain_id" value="">' +
            '<div class="nbh-export-r15__layout">' +
            '<div class="nbh-export-r15__main">' +
            exportStepStart('1', 'Export content', 'Choose what you want to include in the export.') +
            '<div class="nbh-export-content-picker" data-export-content-picker>' +
            '<input type="hidden" name="profile_id" data-export-summary="profile" data-profile-name="' + attr(preferredProfile.name || 'Full site') + '" data-profile-type="' + attr(preferredProfile.content_type || preferredProfile.type || 'full') + '" data-profile-description="' + attr(exportProfileDescription(preferredProfile.content_type || preferredProfile.type || 'full')) + '" value="' + attr(preferredValue) + '">' +
            '<div class="nbh-export-content-grid" role="listbox" aria-label="Export content">' + primaryCards + '</div>' + additionalCards +
            '</div></section>' +
            exportStepStart('2', 'Export type', 'Choose the type of export that best fits your needs.') +
            '<div class="nbh-export-r15__mode-list">' +
            exportModeChoiceR15('full', 'database', 'Full export', 'Creates one complete, independent archive.', preferredMode, false) +
            exportModeChoiceR15('incremental', 'layers', 'Incremental', 'Backs up only changes since the last backup.', preferredMode, false) +
            exportModeChoiceR15('differential', 'file', 'Differential', 'Backs up changes since the last full backup.', preferredMode, false) +
            '</div>' +
            '</section>' +
            exportStepStart('3', 'Archive protection', 'Secure your export with a password (optional).') +
            '<div class="nbh-export-v19__protection">' +
            '<div class="nbh-export-v19__protection-row"><div><strong>Enable password protection</strong><small>Encrypt this archive with a password of at least 10 characters.</small></div><label class="nbh-switch"><input type="checkbox" name="password_protected" data-toggle-target="backup-password"><span></span></label></div>' +
            '<div id="backup-password" class="nbh-export-r15__password nbh-export-v19__password" hidden>' +
            passwordField('Password', 'password', 'Enter password', '', { id: 'nbh-export-password', minlength: 10, autocomplete: 'new-password' }) +
            passwordField('Confirm password', 'confirm_password', 'Confirm password', '', { id: 'nbh-export-confirm-password', minlength: 10, autocomplete: 'new-password' }) +
            '</div>' +
            '</div>' +
            '</section>' +
            exportStepStart('4', 'Destination', 'Choose where to store the exported backup archive.') +
            '<input type="hidden" name="destination_type" value="local">' +
            '<div class="nbh-export-r15__destination-grid">' +
            '<button type="button" class="nbh-export-r15__destination is-selected" data-action="select-export-destination" data-mode="local" title="' + attr(localPath) + '"><span>' + icon('server') + '</span><div><strong>Local backup directory</strong><small>' + esc(localDisplayPath) + '</small></div><em data-destination-marker data-idle="Choose">' + icon('check') + '</em></button>' +
            '</div>' +
            '</section>' +
            '</div>' +
            '<aside class="nbh-export-r15__side">' +
            '<section class="nbh-export-r15__summary">' +
            '<div class="nbh-export-r15__side-head"><div><h3>Export summary</h3><p>Review your export settings before proceeding.</p></div></div>' +
            '<dl>' +
            '<div><dt>' + icon('database') + '<span>Content type</span></dt><dd id="nbh-export-summary-profile">' + esc(preferredProfile.name || 'Full site') + '</dd></div>' +
            '<div><dt>' + icon('layers') + '<span>Export type</span></dt><dd id="nbh-export-summary-mode">' + esc(exportModeLabel(preferredMode)) + '</dd></div>' +
            '<div><dt>' + icon('folder') + '<span>Destination</span></dt><dd id="nbh-export-summary-destination" data-local-name="Local backup directory" data-local-path="' + attr(localDisplayPath) + '" data-local-full-path="' + attr(localPath) + '">Local backup directory<small title="' + attr(localPath) + '">' + esc(localDisplayPath) + '</small></dd></div>' +
            '<div><dt>' + icon('lock') + '<span>Protection</span></dt><dd id="nbh-export-summary-protection">No password</dd></div>' +
            '<div><dt>' + icon('shield') + '<span>Verification</span></dt><dd>Automatic</dd></div>' +
            '</dl>' +
            '<button class="nbh-button nbh-button--primary nbh-button--large nbh-export-r15__submit" type="submit">' + icon('database') + ' Create export' + icon('chevron') + '</button>' +
            '<p class="nbh-export-r15__summary-note">This will create a new backup archive based on your settings. The process may take a few minutes depending on your site size.</p><p class="nbh-export-r15__summary-note">' + esc(backups.length ? (backups.length + ' existing restore point' + (backups.length === 1 ? '' : 's') + ' · ' + formatBytes(localBytes) + ' used locally') : 'The completed archive will appear under Backups.') + '</p>' +
            '</section>' +
            '</aside>' +
            '</div>' +
            '</form>' +
            '</section>';
    }
    function exportStepStart(numberLabel, title, description) {
        return '<section class="nbh-export-r15__step"><div class="nbh-export-r15__step-head"><span>' + esc(numberLabel) + '</span><div><h3>' + esc(title) + '</h3>' + (description ? '<p>' + esc(description) + '</p>' : '') + '</div></div>';
    }

    function exportProfileDescription(type) {
        var descriptions = {
            full: 'Includes the database, media, plugins, themes, WordPress files and settings.',
            database: 'Includes WordPress database tables without site files.',
            files: 'Includes site files without database tables.',
            uploads: 'Includes only the WordPress media uploads library.',
            plugins: 'Includes installed plugin files only.',
            themes: 'Includes installed theme files only.',
            custom: 'Uses the folders, database tables and exclusions saved in this custom profile.'
        };
        return descriptions[type] || descriptions.full;
    }
    function exportProfileInclusionBadges(type) {
        var groups = {
            full: ['Database', 'Media', 'Plugins', 'Themes', 'WordPress files'],
            database: ['Database only'],
            files: ['WordPress files', 'Plugins', 'Themes', 'Media'],
            uploads: ['Media uploads'],
            plugins: ['Plugins'],
            themes: ['Themes'],
            custom: ['Custom selection']
        };
        return (groups[type] || groups.full).map(function (label) {
            return '<span>' + icon('check') + esc(label) + '</span>';
        }).join('');
    }
    function exportModeLabel(mode) {
        if (mode === 'incremental')
            return 'Incremental export';
        if (mode === 'differential')
            return 'Differential export';
        return 'Full export';
    }
    function exportModeChoiceR15(value, iconName, title, description, selectedMode, recommended) {
        return '<label class="nbh-export-r15__mode"><input type="radio" name="backup_mode" value="' + attr(value) + '"' + checked(selectedMode === value) + '><span class="nbh-export-r15__mode-icon">' + icon(iconName) + '</span><span class="nbh-export-r15__mode-copy"><strong>' + esc(title) + (recommended ? '<em>Recommended</em>' : '') + '</strong><small>' + esc(description) + '</small></span><span class="nbh-export-r15__mode-check">' + icon('check') + '</span></label>';
    }

    function updateExportSummary() {
        var form = mount.querySelector('#nbh-backup-form');
        if (!form)
            return;
        var profileSelect = form.querySelector('[name="profile_id"]');
        var modeInput = form.querySelector('[name="backup_mode"]:checked');
        var passwordToggle = form.querySelector('[name="password_protected"]');
        var profileTarget = mount.querySelector('#nbh-export-summary-profile');
        var modeTarget = mount.querySelector('#nbh-export-summary-mode');
        var protectionTarget = mount.querySelector('#nbh-export-summary-protection');
        var destinationTarget = mount.querySelector('#nbh-export-summary-destination');
        var destinationTypeInput = form.querySelector('[name="destination_type"]');
        var recommended = mount.querySelector('#nbh-export-summary-recommended');
        var profileDescription = mount.querySelector('#nbh-export-profile-description');
        var profileTitle = mount.querySelector('#nbh-export-profile-title');
        var profileInclusions = mount.querySelector('#nbh-export-profile-inclusions');
        if (profileSelect && profileTarget) {
            var profileName = profileSelect.getAttribute('data-profile-name') || 'Export profile';
            var profileType = profileSelect.getAttribute('data-profile-type') || 'full';
            var description = profileSelect.getAttribute('data-profile-description') || '';
            profileTarget.textContent = profileName;
            if (profileTitle)
                profileTitle.textContent = profileName;
            if (profileDescription)
                profileDescription.textContent = description;
            if (profileInclusions)
                profileInclusions.innerHTML = exportProfileInclusionBadges(profileType);
        }
        var mode = modeInput ? modeInput.value : 'full';
        if (modeTarget) {
            modeTarget.childNodes[0].nodeValue = exportModeLabel(mode);
        }
        if (recommended)
            recommended.hidden = mode !== 'full';
        if (protectionTarget)
            protectionTarget.textContent = passwordToggle && passwordToggle.checked ? 'Password protected' : 'No password';
        if (destinationTarget) {
            var localName = destinationTarget.getAttribute('data-local-name') || 'Local backup directory';
            var localSummaryPath = destinationTarget.getAttribute('data-local-path') || '';
            destinationTarget.innerHTML = esc(localName) + (localSummaryPath ? '<small title="' + attr(destinationTarget.getAttribute('data-local-full-path') || localSummaryPath) + '">' + esc(localSummaryPath) + '</small>' : '');
        }
    }
    function closeExportContentMenu(focusTrigger) {
        var picker = mount.querySelector('[data-export-content-picker]');
        if (!picker)
            return false;
        var menu = picker.querySelector('#nbh-export-content-menu');
        var trigger = picker.querySelector('[data-action="toggle-export-content"]');
        if (!menu || menu.hidden)
            return false;
        menu.hidden = true;
        picker.classList.remove('is-open');
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
            if (focusTrigger)
                trigger.focus({ preventScroll: true });
        }
        return true;
    }
    function toggleExportContentMenu(trigger) {
        var picker = trigger && trigger.closest ? trigger.closest('[data-export-content-picker]') : null;
        if (!picker)
            return;
        var menu = picker.querySelector('#nbh-export-content-menu');
        if (!menu)
            return;
        var willOpen = menu.hidden;
        menu.hidden = !willOpen;
        picker.classList.toggle('is-open', willOpen);
        trigger.setAttribute('aria-expanded', String(willOpen));
        if (willOpen) {
            var selectedOption = menu.querySelector('[aria-selected="true"]') || menu.querySelector('[role="option"]');
            if (selectedOption)
                window.setTimeout(function () { selectedOption.focus({ preventScroll: true }); }, 0);
        }
    }
    function selectExportContentOption(optionButton) {
        var picker = optionButton && optionButton.closest ? optionButton.closest('[data-export-content-picker]') : null;
        if (!picker)
            return;
        var input = picker.querySelector('[name="profile_id"]');
        if (!input)
            return;
        input.value = optionButton.getAttribute('data-value') || '';
        input.setAttribute('data-profile-name', optionButton.getAttribute('data-profile-name') || 'Export profile');
        input.setAttribute('data-profile-type', optionButton.getAttribute('data-profile-type') || 'full');
        input.setAttribute('data-profile-description', optionButton.getAttribute('data-profile-description') || '');
        picker.querySelectorAll('[role="option"]').forEach(function (item) {
            var selectedItem = item === optionButton;
            item.classList.toggle('is-selected', selectedItem);
            item.setAttribute('aria-selected', String(selectedItem));
            var stateIcon = item.querySelector('.nbh-export-v20__option-state');
            if (stateIcon)
                stateIcon.innerHTML = icon(selectedItem ? 'check' : 'layers');
            var stateText = item.querySelector('em');
            if (stateText)
                stateText.textContent = selectedItem ? 'Selected' : 'Choose';
        });
        updateExportSummary();
        closeExportContentMenu(true);
    }


    function backupIntegrityStatus(backup) {
        return backup.integrity_status || (backup.verified_at ? 'verified' : (backup.encrypted ? 'password_required' : 'ready_for_verification'));
    }
    function backupRequiresPassword(backup) {
        backup = object(backup);
        return backup.password_required === true || backup.password_required === 1 || backup.password_required === '1' || backup.integrity_status === 'password_required';
    }
    function integrityBadge(backup) {
        var status = backupIntegrityStatus(backup);
        if (backupRequiresPassword(backup))
            return '<span class="nbh-status is-warning nbh-integrity-badge">' + icon('lock') + 'Password required</span>';
        if (status === 'verified')
            return '<span class="nbh-status is-success nbh-integrity-badge">' + icon('check') + 'Verified</span>';
        if (status === 'password_required')
            return '<span class="nbh-status is-warning nbh-integrity-badge">' + icon('lock') + 'Password required</span>';
        return '<span class="nbh-status is-info nbh-integrity-badge" title="This archive has not been pre-verified. Nobash Backup will validate its structure and checksums before changing the site.">' + icon('info') + 'Verify before restore</span>';
    }

    function importProfileFromFilename(filename) {
        var name = String(filename || '').toLowerCase();
        var match = name.match(/(?:^|[-_])(full|database|files|uploads|media|plugins|themes)(?:[-_.]|$)/i);
        if (!match)
            return '';
        return match[1] === 'media' ? 'uploads' : match[1];
    }
    function backupListType(backup) {
        backup = object(backup);
        if (String(backup.backup_mode || backup.mode || '').toLowerCase() === 'incremental') return { label: 'Incremental', tone: 'incremental' };
        var source = String(backup.source || '').toLowerCase();
        var profile = String(backup.profile || backup.profile_key || backup.profile_label || '').toLowerCase();
        if (!profile || profile === 'unknown')
            profile = importProfileFromFilename(backup.original_name || backup.filename || '');
        var types = {
            full: ['Full', 'full'],
            database: ['Database', 'database'],
            files: ['Files', 'files'],
            uploads: ['Media', 'uploads'],
            media: ['Media', 'uploads'],
            plugins: ['Plugins', 'plugins'],
            themes: ['Themes', 'themes'],
            custom: ['Custom', 'custom']
        };
        if (types[profile])
            return { label: types[profile][0], tone: types[profile][1] };
        if (source === 'migration')
            return { label: 'Migration', tone: 'migration' };
        return { label: 'Backup', tone: 'custom' };
    }
    function backupListStatus(backup) {
        return backupRequiresPassword(backup)
            ? '<span class="nbh-status is-warning nobash-backup-password-badge">' + icon('lock') + 'Password required</span>'
            : '';
    }
    function backupListDateParts(backup) {
        backup = object(backup);
        var date = String(backup.modified_date_human || backup.created_date_human || '').trim();
        var time = String(backup.modified_time_human || backup.created_time_human || '').trim();
        if (date || time)
            return { date: date, time: time };
        var raw = number(backup.modified || backup.created || backup.timestamp, 0);
        if (!raw)
            return { date: '', time: '' };
        var value = new Date(raw * 1000);
        if (isNaN(value.getTime()))
            return { date: '', time: '' };
        try {
            date = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: '2-digit' }).format(value);
            time = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(value);
        }
        catch (e) {
            date = value.toLocaleDateString();
            time = value.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        return { date: date, time: time };
    }
    function backupPagination(current, totalPages) {
        current = Math.max(1, Math.min(totalPages, current));
        var pages = [];
        var start = Math.max(1, current - 2);
        var end = Math.min(totalPages, start + 4);
        start = Math.max(1, end - 4);
        for (var page = start; page <= end; page++) {
            pages.push('<button type="button" class="nobash-backup-page-button' + (page === current ? ' is-active' : '') + '" data-action="backups-page" data-page="' + page + '"' + (page === current ? ' aria-current="page"' : '') + '>' + page + '</button>');
        }
        if (start > 1)
            pages.unshift('<span class="nobash-backup-page-ellipsis">…</span>');
        if (end < totalPages)
            pages.push('<span class="nobash-backup-page-ellipsis">…</span>');
        return '<nav class="nobash-backup-pagination" aria-label="Backup pages">' +
            '<button type="button" class="nobash-backup-page-button is-nav" data-action="backups-page" data-page="1" aria-label="First page"' + (current <= 1 ? ' disabled' : '') + '>«</button>' +
            '<button type="button" class="nobash-backup-page-button is-nav" data-action="backups-page" data-page="' + Math.max(1, current - 1) + '" aria-label="Previous page"' + (current <= 1 ? ' disabled' : '') + '>‹</button>' +
            '<div class="nobash-backup-page-numbers">' + pages.join('') + '</div>' +
            '<button type="button" class="nobash-backup-page-button is-nav" data-action="backups-page" data-page="' + Math.min(totalPages, current + 1) + '" aria-label="Next page"' + (current >= totalPages ? ' disabled' : '') + '>›</button>' +
            '<button type="button" class="nobash-backup-page-button is-nav" data-action="backups-page" data-page="' + totalPages + '" aria-label="Last page"' + (current >= totalPages ? ' disabled' : '') + '>»</button>' +
            '</nav>';
    }
    function backupsMockupIcon(name) {
        if (name === 'download') return '<svg class="nbh-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" stroke="none" d="M10 2h4v9l3-3 3 3-8 8-8-8 3-3 3 3V2zM2 15h3v5h14v-5h3v8H2z"/></svg>';
        if (name === 'help') return '<svg class="nbh-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 4.3 1.7c-1.2.9-1.8 1.1-1.8 3.3m0 3v.01"/></svg>';
        if (name === 'database' || name === 'create') return '<svg class="nbh-icon nbh-backups-database-icon" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path fill="currentColor" stroke="none" d="M4 8c0-3 5.4-5.5 12-5.5S28 5 28 8v17c0 3-5.4 5.5-12 5.5S4 28 4 25z"/><ellipse cx="16" cy="8" rx="12" ry="5.5" fill="currentColor" stroke="#b4eaff" stroke-width="1.6"/><path d="M4 15c0 3 5.4 5.5 12 5.5S28 18 28 15M4 22c0 3 5.4 5.5 12 5.5S28 25 28 22" fill="none" stroke="' + (name === 'create' ? '#006cff' : 'white') + '" stroke-width="1.5"/>' + (name === 'create' ? '<circle cx="25" cy="24" r="6" fill="currentColor" stroke="white" stroke-width="1.7"/><path d="M25 21v6m-3-3h6" stroke="white" stroke-width="1.7"/>' : '') + '</svg>';
        return icon(name);
    }
    function renderBackups() {
        var backups = array(state.data.backups).slice().sort(function(a,b) { return number(b.modified || b.created || b.timestamp,0) - number(a.modified || a.created || a.timestamp,0); });
        var perPage = number(state.backupsPerPage,10), total = backups.length;
        var totalPages = Math.max(1,Math.ceil(total/perPage)), currentPage = Math.max(1,Math.min(totalPages,number(state.backupsPage,1)));
        state.backupsPage = currentPage;
        var startIndex = (currentPage-1)*perPage, visible = backups.slice(startIndex,startIndex+perPage);
        var rows = visible.map(function(backup) {
            var type = backupListType(backup), created = backupListDateParts(backup), file = attr(backup.filename), menuOpen = state.openBackupMenu === backup.filename;
            function action(mark,label,name,tone) { return '<button type="button" class="nbh-backups-mockup-action is-' + tone + '" data-action="' + name + '" data-file="' + file + '" aria-label="' + label + ' ' + file + '">' + backupsMockupIcon(mark) + '<span>' + label + '</span></button>'; }
            return '<tr><td data-label="Backup"><div class="nbh-backups-mockup-name"><span class="nbh-backups-mockup-name__icon">' + backupsMockupIcon('database') + '</span><div><strong title="' + file + '">' + esc(backup.filename) + '</strong><span class="nbh-backups-mockup-type">' + icon('check') + esc(type.label) + '</span>' + backupListStatus(backup) + '</div></div></td><td data-label="Size">' + esc(backup.size_human || formatBytes(backup.size)) + '</td><td data-label="Date &amp; Time"><div class="nbh-backups-mockup-date">' + esc(created.date || '—') + (created.time ? '<small>at ' + esc(created.time) + '</small>' : '') + '</div></td><td data-label="Actions"><div class="nbh-backups-mockup-actions">' + action('info','Details','archive-details','details') + action('restore','Restore','restore-file','restore') + action('download','Download','download-backup','download') + '<div class="nobash-backup-more' + (menuOpen ? ' is-open' : '') + '"><button type="button" class="nbh-backups-mockup-action is-more" aria-label="More actions for ' + file + '" aria-expanded="' + menuOpen + '" data-action="toggle-backup-menu" data-file="' + file + '"><span class="nbh-backups-mockup-dots" aria-hidden="true">•••</span><span>More</span></button><div class="nobash-backup-more__menu"' + (menuOpen ? '' : ' hidden') + '><button data-action="delete-backup" data-file="' + file + '">' + icon('trash') + 'Delete backup</button></div></div></div></td></tr>';
        }).join('');
        if (!rows) rows = '<tr><td colspan="4"><div class="nbh-backups-empty"><strong>No backups available</strong><p>Create or import an archive to manage it here.</p></div></td></tr>';
        var footer = '<footer class="nbh-backups-mockup-footer"><span>Showing ' + (total ? startIndex+1 : 0) + ' to ' + Math.min(total,startIndex+visible.length) + ' of ' + total + ' backups</span><div class="nbh-backups-mockup-footer__controls"><select data-action="backups-per-page" aria-label="Backups per page">' + [5,10,25,50].map(function(value) { return '<option value="' + value + '"' + selected(perPage,value) + '>' + value + ' / page</option>'; }).join('') + '</select>' + backupPagination(currentPage,totalPages) + '</div></footer>';
        return '<section class="nbh-backups-mockup"><section class="nbh-backups-mockup-panel"><div class="nbh-backups-mockup-list"><table class="nbh-backups-mockup-table"><thead><tr><th scope="col">Backup</th><th scope="col">Size</th><th scope="col">Date &amp; Time</th><th scope="col">Actions</th></tr></thead><tbody>' + rows + '</tbody></table></div>' + footer + '</section><div class="nbh-backups-mockup-notice">' + icon('info') + '<span>Backups are stored locally on your server.</span></div></section>';
    }
    function restoreAvailableComponents(backup) {
        backup = object(backup);
        var allowed = ['database', 'files', 'uploads', 'plugins', 'themes'];
        var components = array(backup.components).map(function (value) { return String(value || '').toLowerCase(); }).filter(function (value) { return allowed.indexOf(value) !== -1; });
        if (components.length)
            return Array.from(new Set(components));
        var profile = String(backup.profile || '').toLowerCase();
        if (profile === 'database') return ['database'];
        if (profile === 'uploads') return ['uploads'];
        if (profile === 'plugins') return ['plugins'];
        if (profile === 'themes') return ['themes'];
        if (profile === 'files') return ['files'];
        return ['database', 'files'];
    }
    function renderRestoreComponents(backup) {
        var components = restoreAvailableComponents(backup);
        var meta = {
            database: ['DB', 'Database', 'Restore tables, settings and content.'],
            files: ['FILE', 'Files', 'Restore site files, plugins, themes and uploads.'],
            uploads: ['MEDIA', 'Media uploads', 'Restore only the Media Library uploads in this backup.'],
            plugins: ['PLUG', 'Plugins', 'Restore only plugin files in this backup.'],
            themes: ['THEME', 'Themes', 'Restore only theme files in this backup.']
        };
        return '<fieldset class="nbh-fieldset nbh-restore-components nbh-restore-components--mockup"><legend>Content to restore</legend><div class="nbh-component-options nbh-component-options--mockup">' + components.map(function (component) {
            var item = meta[component] || [component.toUpperCase(), humanizeSlug(component), 'Restore this backup component.'];
            return '<label class="nbh-component-option nbh-component-option--mockup"><input type="checkbox" name="components" value="' + attr(component) + '" checked><span class="nbh-component-option__mark">' + icon(component === 'database' ? 'database' : 'folder') + '</span><span><strong>' + esc(item[1]) + '</strong><small>' + esc(item[2]) + '</small></span></label>';
        }).join('') + '</div><p class="nbh-field-help">Only content included in this backup can be restored.</p></fieldset>';
    }
    function renderRestore() {
        var backups = array(state.data.backups);
        var selectedFile = state.selectedRestore || (backups[0] && backups[0].filename) || '';
        var selectedBackup = backups.find(function (backup) { return backup.filename === selectedFile; }) || backups[0] || null;
        var recovery = object(object(state.data.status).restore_recovery);
        var cleanupPending = !!recovery.cleanup_pending;
        var recoveryJobStatus = String(recovery.job_status || '').toLowerCase();
        var recoveryFailed = recovery.state === 'recovery_failed' || recovery.state === 'commit_cleanup_failed' || recoveryJobStatus === 'failed' || recoveryJobStatus === 'cancelled';
        var recoveryActions = recovery.job_id ? '<div class="nbh-callout__actions"><button class="nbh-button" data-action="resume-restore-recovery" data-id="' + attr(recovery.job_id) + '">' + esc(cleanupPending ? 'Retry cleanup' : 'Resume recovery') + '</button>' + (recoveryFailed ? '<button class="nbh-button" data-action="reset-restore-recovery" data-id="' + attr(recovery.job_id) + '">Reset recovery</button><button class="nbh-button nbh-button--danger" data-action="delete-restore-recovery" data-id="' + attr(recovery.job_id) + '">Delete failed job</button>' : '') + '</div>' : '';
        var recoveryError = recovery.last_error ? ' Error: ' + esc(recovery.last_error) : '';
        var recoveryBanner = recovery.job_id ? '<div class="nbh-callout ' + (recoveryFailed ? 'is-danger' : 'is-warning') + '">' + icon(cleanupPending ? 'check' : 'warning') + '<div><strong>' + esc(cleanupPending ? 'Finalizing restore cleanup' : (recoveryFailed ? 'Restore failed — recovery is available' : 'Restore recovery is active')) + '</strong><p>Job #' + attr(recovery.job_id) + ' · ' + esc(recovery.phase || 'recovery') + '. ' + esc(cleanupPending ? 'The restored site is committed; temporary rollback data is being removed safely.' : 'Rollback data is preserved until recovery finishes.') + recoveryError + (recoveryFailed ? ' Resume recovery, reset the recovery marker, or delete the failed job record.' : '') + '</p></div>' + recoveryActions + '</div>' : '';
        var verificationPending = !!(selectedBackup && backupIntegrityStatus(selectedBackup) === 'ready_for_verification');
        var restorePasswordRequired = !!(selectedBackup && (backupRequiresPassword(selectedBackup) || state.restorePasswordRequiredFor === selectedFile));
        var restorePasswordField = restorePasswordRequired ? '<div class="nbh-restore-password-required nbh-restore-password-required--card"><div class="nbh-restore-password-required__head">' + icon('lock') + '<div><strong>Archive password required</strong><small>Enter the password used when this archive was exported.</small></div></div>' + passwordField('Archive password', 'password', 'Enter archive password', 'The password is used only to unlock and verify this restore point.', { id: 'nbh-restore-password', autocomplete: 'current-password', required: true }) + '</div>' : '';
        var selectedCreated = selectedBackup ? backupListDateParts(selectedBackup) : { date: '', time: '' };
        var available = selectedBackup ? restoreAvailableComponents(selectedBackup) : [];
        var selectedType = selectedBackup ? backupListType(selectedBackup) : {label:'Backup'};
        var selectedDate = [selectedCreated.date,selectedCreated.time].filter(Boolean).join(', ');
        var pointOptions = backups.map(function(backup) {
            var parts = backupListDateParts(backup);
            var description = [backup.filename, backup.size_human || formatBytes(backup.size), [parts.date, parts.time].filter(Boolean).join(', ')].filter(Boolean).join(' — ');
            return '<option value="' + attr(backup.filename) + '"' + selected(backup.filename, selectedFile) + '>' + esc(description) + '</option>';
        }).join('');
        var restorePointSelect = '<div class="nbh-restore-point-select"><label for="nbh-restore-point">Select backup</label><select id="nbh-restore-point" name="filename"' + (!backups.length ? ' disabled' : '') + '>' + (pointOptions || '<option value="">No restore point available</option>') + '</select><small>' + backups.length + ' local backup' + (backups.length === 1 ? '' : 's') + ' available</small></div>';
        var summaryComponents = available.map(function(component) {
            return '<div data-restore-summary-component="' + attr(component) + '"><span>' + icon(component === 'database' ? 'database' : 'folder') + esc(humanizeSlug(component)) + '</span><small>' + icon('check') + '<b>Included</b></small></div>';
        }).join('');
        return recoveryBanner + '<form id="nbh-restore-form" class="nbh-restore-workspace"><div class="nbh-restore-workspace__main">' +
            '<div class="nbh-callout is-safe nbh-restore-safe nbh-restore-safe--mockup"><span class="nbh-callout__icon">' + icon('shield') + '</span><div><strong>Safe restore with automatic rollback</strong><p>Your site will be verified before changes are applied. If anything goes wrong, you can roll back to the current state.</p></div></div>' +
            '<section class="nbh-restore-step"><div class="nbh-restore-step__head"><span class="nbh-restore-step__badge">1</span><div><h3>Choose a restore point</h3><p>Select a backup from the list below to restore your site.</p></div><button class="nbh-button" type="button" data-action="navigate" data-page="backups">' + icon('database') + 'View all backups</button></div>' + restorePointSelect + '' + (verificationPending ? '<p class="nbh-restore-verification-note nbh-restore-verification-note--card">' + icon('info') + '<span>This archive will be verified before any database table or file is changed.</span></p>' : '') + restorePasswordField + '</section>' +
            '<section class="nbh-restore-step"><div class="nbh-restore-step__head"><span class="nbh-restore-step__badge">2</span><div><h3>What to restore</h3><p>Choose what you want to restore from the selected backup.</p></div></div>' + (selectedBackup ? renderRestoreComponents(selectedBackup) : '<p class="nbh-muted">Select a backup to see available content.</p>') + '</section>' +
            '<section class="nbh-restore-step"><div class="nbh-restore-step__head"><span class="nbh-restore-step__badge">3</span><div><h3>Safety checks</h3><p>We will run safety checks before starting the restore process.</p></div></div><div class="nbh-restore-checks"><article>' + icon('check') + '<div><strong>Backup verification</strong><small>Archive structure and checksums are checked before site changes.</small></div></article><label>' + icon('shield') + '<div><strong>Create pre-restore backup</strong><small>Optional extra copy of your current site. Takes longer.</small></div><input type="checkbox" name="safety_backup" aria-label="Create pre-restore backup"></label><article>' + icon('shield') + '<div><strong>Rollback protection</strong><small>Automatic rollback protects the current files and database.</small></div></article></div></section>' +
            '<details class="nbh-details nbh-restore-advanced nbh-restore-advanced--mockup"><summary><span class="nbh-restore-step__head"><span class="nbh-restore-step__badge">4</span><div><h3>Restore options <em>(optional)</em></h3><p>Additional options for your restore process.</p></div></span><span class="nbh-restore-advanced-trigger">' + icon('settings') + 'Advanced options' + icon('chevron') + '</span></summary><div class="nbh-details__body"><div class="nbh-field-row">' + fieldInput('Search URL','search','','url','Optional source URL') + fieldInput('Replace URL','replace','','url','Optional destination URL') + '</div><div class="nbh-field-row">' + fieldInput('Target home URL','target_home','','url','Keep current URL when empty') + fieldInput('Target site URL','target_siteurl','','url','Usually the same as home URL') + '</div><div class="nbh-field-row">' + fieldInput('Target table prefix','target_table_prefix','','text','Keep current prefix when empty') + '</div></div></details><div class="nbh-restore-footer-note">' + icon('shield') + 'Your current site is protected by the restore rollback workflow.</div></div>' +
            '<aside class="nbh-restore-summary"><h2>' + icon('file') + 'Restore summary</h2><section class="nbh-restore-summary__box"><h3>Selected backup</h3><div class="nbh-restore-summary__file">' + icon('database') + '<strong>' + esc(selectedFile || 'No backup selected') + '</strong>' + (selectedBackup ? integrityBadge(selectedBackup) : '') + '</div><dl><dt>Type:</dt><dd>' + esc(selectedType.label) + ' Backup</dd><dt>Size:</dt><dd>' + esc(selectedBackup ? selectedBackup.size_human || formatBytes(selectedBackup.size) : '—') + '</dd><dt>Date:</dt><dd>' + esc(selectedDate || '—') + '</dd><dt>Contains:</dt><dd>' + esc(available.map(humanizeSlug).join(' + ') || '—') + '</dd></dl></section>' +
            '<section class="nbh-restore-summary__box"><h3>Content to restore</h3><div class="nbh-restore-summary__components">' + (summaryComponents || '<small>No content available</small>') + '</div></section>' +
            '<section class="nbh-restore-summary__box"><h3>' + icon('shield') + 'Safety &amp; protection</h3><div class="nbh-restore-summary__safety"><div><span>Backup verification</span><small>Before restore</small></div><div><span>Pre-restore backup</span><small id="nbh-restore-summary-safety">Optional · Off</small></div><div><span>Automatic rollback</span><small>Enabled</small></div></div></section>' +
            '<div class="nbh-restore-summary__estimate">' + icon('info') + '<div><strong>Estimated restore size</strong><small>' + esc(selectedBackup ? selectedBackup.size_human || formatBytes(selectedBackup.size) : 'No backup selected') + ' archive selected. Actual restored size depends on selected content.</small></div></div><button type="submit" class="nbh-button nbh-button--primary nbh-restore-summary__start"' + (!selectedBackup ? ' disabled' : '') + '>' + icon('restore') + 'Start restore</button><button type="button" class="nbh-button nbh-restore-summary__details" data-action="archive-details" data-file="' + attr(selectedFile) + '"' + (!selectedBackup ? ' disabled' : '') + '>' + icon('check') + 'View verification details</button></aside></form>';
    }

    function updateRestoreSummary() {
        var form = document.getElementById('nbh-restore-form');
        if (!form) return;
        form.querySelectorAll('[data-restore-summary-component]').forEach(function(row) {
            var name = row.getAttribute('data-restore-summary-component');
            var input = Array.from(form.querySelectorAll('input[name="components"]')).find(function(item) { return item.value === name; });
            row.classList.toggle('is-excluded', !input || !input.checked);
            row.querySelector('b').textContent = input && input.checked ? 'Included' : 'Excluded';
        });
        var safety = form.querySelector('input[name="safety_backup"]');
        document.getElementById('nbh-restore-summary-safety').textContent = safety && safety.checked ? 'Enabled' : 'Optional · Off';
    }


    function renderImportUrlModal() {
        var modal = object(state.modal);
        var values = object(modal.values);
        return '<button class="nbh-modal-close" data-action="close-modal" aria-label="Close">×</button><div class="nbh-import-modal-head"><span class="nbh-source-button__icon is-url">' + icon('link') + '</span><div><span class="nbh-eyebrow">Import source</span><h2 id="nbh-modal-title">Import from URL</h2><p>Download a public HTTPS .nbh archive directly to this server with resumable checkpoints.</p></div></div><form id="nbh-import-url-form" class="nbh-modal-form nbh-import-modal-form">' + modalImportError(modal.error, modal.errorTitle) + fieldInput('Archive URL', 'url', values.url || '', 'url', 'https://example.com/backup.nbh') + fieldInput('Expected SHA-256', 'sha256', values.sha256 || '', 'text', 'Optional: 64-character integrity hash') + '<div class="nbh-callout is-safe">' + icon('check') + '<div><strong>Safe remote import</strong><p>The archive is downloaded with checkpoints and verified again before restore.</p></div></div><div class="nbh-modal-actions"><button class="nbh-button nbh-button--primary" type="submit">Start import</button><button class="nbh-button" type="button" data-action="close-modal">Cancel</button></div></form>';
    }


    function renderImportSuccessModal() {
        var modal = object(state.modal);
        var backup = object(modal.backup);
        var filename = backup.filename || modal.filename || '';
        var type = backupListType(backup);
        return '<div class="nbh-import-success"><span class="nbh-import-success__icon">' + icon('check') + '</span><span class="nbh-eyebrow">Import complete</span><h2 id="nbh-modal-title">Backup imported successfully</h2><p>The archive is ready. Choose what you want to do next.</p><div class="nbh-import-success__file"><span class="nobash-backup-type-badge is-' + attr(type.tone) + '">' + esc(type.label) + '</span><div><strong>' + esc(filename || 'Imported backup') + '</strong><small>' + esc(backup.size_human || formatBytes(backup.size || modal.size || 0)) + '</small></div></div><div class="nbh-import-success__actions"><button class="nbh-button nbh-button--primary" data-action="import-success-restore" data-file="' + attr(filename) + '">' + icon('restore') + 'Restore</button><button class="nbh-button" data-action="import-success-again">' + icon('upload') + 'Import again</button><button class="nbh-button nbh-button--danger" data-action="import-success-remove" data-file="' + attr(filename) + '">' + icon('trash') + 'Remove</button></div></div>';
    }
    function renderImport() {
        var file = state.importLocalFile, isUrl = state.importTab === 'url', draft = state.importUrlDraft;
        function stepHead(n,title,detail) { return '<div class="nbh-import-step-head"><span>' + n + '</span><div><h2>' + title + '</h2><p>' + detail + '</p></div></div>'; }
        var tabs = '<div class="nbh-import-tabs" role="tablist" aria-label="Import source"><button type="button" role="tab" aria-selected="' + !isUrl + '" data-action="import-source-tab" data-source="file" class="' + (!isUrl ? 'is-active' : '') + '">' + icon('database') + '<span><strong>Import File</strong><small>Upload a .nbh file from your computer</small></span></button><button type="button" role="tab" aria-selected="' + isUrl + '" data-action="import-source-tab" data-source="url" class="' + (isUrl ? 'is-active' : '') + '">' + icon('link') + '<span><strong>Import from URL</strong><small>Import using a secure HTTPS link</small></span></button></div>';
        var local = '<form class="nbh-import-workspace__panel" id="nbh-import-file-form">' + stepHead(1,'Upload backup archive','Select a Nobash Backup (.nbh) file from your computer to import.') + '<label class="nbh-dropzone nbh-import-dropzone nbh-import-workspace__dropzone' + (file ? ' has-file' : '') + '"><input type="file" name="file" accept=".nbh,application/octet-stream"><span class="nbh-import-workspace__file-icon">' + icon(file ? 'database' : 'file') + '</span><strong>' + esc(file ? file.name : 'Drag & drop your .nbh file here') + '</strong><small>' + (file ? esc(formatBytes(file.size || 0)) + ' · Ready to upload' : 'or click the button below to choose a file from your computer.') + '</small><span class="nbh-button nbh-button--primary">' + icon('folder') + (file ? 'Change .nbh file' : 'Choose .nbh file') + '</span><small>Only Nobash Backup (.nbh) archive files are accepted.</small></label>' + (file ? '<div class="nbh-import-workspace__submit"><button class="nbh-button nbh-button--primary" type="submit">' + icon('upload') + 'Start import</button></div>' : '') + '</form>';
        var remote = '<form class="nbh-import-workspace__panel" id="nbh-import-url-form">' + stepHead(1,'Import from secure URL','Provide a secure HTTPS link to a Nobash Backup (.nbh) archive.') + '<div class="nbh-import-url-card">' + '<label class="nbh-field"><span>HTTPS backup URL</span><span class="nbh-import-url-input">' + icon('link') + '<input type="url" name="url" value="' + attr(draft.url) + '" placeholder="https://example.com/backup.nbh" required></span></label>' + '<p class="nbh-field-help">Enter a direct public HTTPS link to a Nobash Backup (.nbh) file.</p><details class="nbh-import-url-options"><summary>' + icon('shield') + '<span><strong>Integrity check (optional)</strong><small>Provide a checksum to verify the downloaded file.</small></span>' + icon('chevron') + '</summary>' + fieldInput('Expected SHA-256','sha256',draft.sha256,'text','Optional: 64-character integrity hash') + '</details><div class="nbh-import-workspace__submit"><button class="nbh-button nbh-button--primary" type="submit">' + icon('link') + 'Start import</button><small>The archive is downloaded with resumable checkpoints and validated.</small></div></div></form>';
        var source = isUrl ? 'Secure URL' : file ? file.name : 'Not selected';
        function summaryRow(mark,label,value) { return '<div>' + icon(mark) + '<strong>' + label + '</strong><span>' + esc(value) + '</span></div>'; }
        var summary = '<aside class="nbh-import-workspace__summary"><div class="nbh-import-summary-head">' + icon('database') + '<div><h2>Import summary</h2><p>Details about the selected backup file.</p></div></div><div class="nbh-import-summary-rows">' + summaryRow('database','Source',source) + summaryRow('file','Format','.nbh (Nobash Backup)') + summaryRow('shield','Status','Not started') + summaryRow('check','Readiness',isUrl ? 'Waiting for verification' : 'Not verified') + (file && !isUrl ? summaryRow('server','Size',formatBytes(file.size || 0)) : '') + '</div></aside>';
        function check(mark,title,detail) { return '<article><span>' + icon(mark) + '</span><div><strong>' + title + '</strong><small>' + detail + '</small></div></article>'; }
        var checks = '<section class="nbh-import-workspace__checks">' + stepHead(2,'Archive checks','Your archive is validated as part of the import and before restoration.') + '<div>' + check('shield','File integrity','Check that the archive can be read and its integrity can be validated.') + check('database','Archive format','Confirm it is a valid Nobash Backup (.nbh) archive.') + check('settings','Restore compatibility','Review archive content and compatibility before site changes.') + '</div></section>';
        return '<section class="nbh-import-workspace">' + tabs + '<div class="nbh-import-workspace__grid">' + (isUrl ? remote : local) + summary + '</div>' + checks + '</section>';
    }

    function scheduleDate(item) {
        var raw = item.next_run_at || item.next_run || '';
        if (typeof raw === 'number') return new Date(raw * 1000).toISOString();
        return /^\d{4}-\d{2}-\d{2} \d{2}:/.test(String(raw)) ? String(raw).replace(' ','T') + 'Z' : raw;
    }
    function renderAutomation() {
        var profiles = array(state.data.profiles), settings = object(state.data.settings);
        var schedules = array(state.data.profileSchedules).length ? array(state.data.profileSchedules) : array(state.data.schedules);
        var enabled = schedules.filter(function(item) { return item.enabled !== false && item.status !== 'disabled'; });
        var next = enabled.filter(function(item) { return scheduleDate(item); }).sort(function(a,b) { return new Date(scheduleDate(a)) - new Date(scheduleDate(b)); })[0];
        var retention = number(settings.retention_count,10), reserve = number(settings.retention_min_free_mb,1024);
        function head(mark,title,detail) { return '<div class="nbh-schedules-head"><span>' + icon(mark) + '</span><div><h2>' + title + '</h2><p>' + detail + '</p></div></div>'; }
        function setting(mark,title,value,detail) { return '<div class="nbh-schedules-setting">' + icon(mark) + '<div><strong>' + title + '</strong><span>' + esc(value) + '</span><small>' + esc(detail) + '</small></div></div>'; }
        var timezone = schedules.length && schedules[0].timezone ? schedules[0].timezone : 'WordPress site timezone';
        return '<section class="nbh-schedules-workspace"><section class="nbh-schedules-summary nbh-schedules-summary--final">' +
            automationSummaryItem('calendar','Active schedules',String(enabled.length),'of ' + schedules.length + ' total schedules') +
            automationSummaryItem('schedule','Next run',next ? overviewRelativeDate(scheduleDate(next),true) : 'Not scheduled',next ? [dashboardDateParts(scheduleDate(next)).primary,dashboardDateParts(scheduleDate(next)).secondary].filter(Boolean).join(' at ') : 'Create a schedule to automate protection.') +
            automationSummaryItem('database','Retention period',number(settings.retention_days,30) + ' days','Automated cleanup') +
            automationSummaryItem('folder','Destinations','1','Local storage') + '</section>' +
            '<div class="nbh-schedules-grid"><div class="nbh-schedules-main"><section class="nbh-schedules-panel nbh-schedules-panel--final"><div class="nbh-schedules-final-head"><h2>Backup Schedules</h2><p>Manage your automated backup schedules. Create, edit or pause schedules at any time.</p></div>' + renderSchedules(schedules,profiles) + '</section>' +
            '<details class="nbh-schedules-panel nbh-schedules-guide"><summary>' + head('file','How scheduling works','Automatic backups run in the background to keep your site safe and up to date.') + '</summary><div class="nbh-schedules-guide__grid"><article>' + icon('schedule') + '<div><strong>Reliable timing</strong><small>Backups follow the configured timezone and WordPress cron availability.</small></div></article><article>' + icon('database') + '<div><strong>Retention cleanup</strong><small>Old backups are removed according to retention rules. Protected chains remain safe.</small></div></article><article>' + icon('shield') + '<div><strong>Recovery ready</strong><small>Local archives are available for download and recovery from the Backups page.</small></div></article></div></details></div>' +
            '<details class="nbh-schedules-panel nbh-schedules-settings"><summary>' + head('settings','Schedule settings','Global settings for scheduled backups.') + '</summary><div class="nbh-schedules-settings-grid">' +
            setting('schedule','Timezone',timezone,'Each schedule uses its configured timezone.') +
            setting('server','Low disk space reserve',reserve + ' MB','Your configured disk-space safety reserve.') +
            setting('schedule','Missed run recovery',settings.missed_schedule_action === 'skip' ? 'Skip missed runs' : 'Run when WordPress is available','Controls how missed automatic runs are handled.') +
            setting('database','Default retention',retention + ' backups','The newest protected backups are always kept.') +
            '</div><button class="nbh-button" data-action="navigate" data-page="settings">' + icon('settings') + 'Manage settings</button></details></div>' +
            '<details class="nbh-schedules-extra"><summary>Backup profiles &amp; retention tools</summary><section class="nbh-schedules-panel"><div class="nbh-panel__head"><div><h2>Backup profiles</h2><p>Reusable presets for automatic backups.</p></div><button class="nbh-button" data-action="add-profile">' + icon('plus') + 'New profile</button></div>' + renderProfiles(profiles) + '</section><section class="nbh-panel nbh-retention-panel"><div><h2>Local retention safety</h2><p>Preview cleanup before removing restore points.</p></div><div class="nbh-row-actions"><button class="nbh-button" data-action="retention-preview">Preview cleanup</button><button class="nbh-button" data-action="retention-run">Run cleanup</button></div><div id="nbh-retention-result"><p>No cleanup preview has been generated.</p></div></section></details></section>';
    }
    function scheduleSummaryIcon(name) {
        if (name === 'database') return backupsMockupIcon('database');
        if (name === 'folder') return '<svg class="nbh-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" stroke="none" d="M2 6a2 2 0 0 1 2-2h6l3 3h7a2 2 0 0 1 2 2v2H6L3 21H2z"/><path fill="currentColor" stroke="none" d="M6 12h17l-3 9H3z"/></svg>';
        return icon(name);
    }
    function automationSummaryItem(iconName, label, value, detail) {
        return '<article class="nbh-automation-summary__item"><span class="nbh-automation-summary__icon">' + scheduleSummaryIcon(iconName) + '</span><div><small>' + esc(label) + '</small><strong>' + esc(value) + '</strong><span>' + esc(detail) + '</span></div></article>';
    }
    function renderProfiles(profiles) {
        if (!profiles.length)
            return emptyState('No backup profiles', 'Create a reusable local backup profile before adding a schedule.', 'New profile', 'profile-modal');
        return '<div class="nbh-table-scroll"><table class="nbh-table nbh-profile-table"><thead><tr><th>Profile</th><th>Mode</th><th>Content</th><th>Status</th><th class="nbh-actions-col">Actions</th></tr></thead><tbody>' + profiles.map(function (profile) {
            var actions = profile.is_system ? '<span class="nbh-profile-built-in">Built in</span>' : '<div class="nbh-row-actions"><button class="nbh-button nbh-button--small" data-action="edit-profile" data-id="' + attr(profile.id) + '">Edit</button><button class="nbh-icon-button is-danger" data-action="delete-profile" data-id="' + attr(profile.id) + '" aria-label="Delete ' + attr(profile.name || 'profile') + '">' + icon('trash') + '</button></div>';
            var content = String(profile.type || profile.content_type || 'full').replace(/_/g, ' ');
            return '<tr><td><div class="nbh-profile-name"><strong>' + esc(profile.name) + '</strong><small>' + esc(profile.profile_key || (profile.is_system ? 'System profile' : 'Custom profile')) + '</small></div></td><td><span class="nbh-status is-muted">' + esc(profile.backup_mode || 'full') + '</span></td><td><span class="nbh-profile-content">' + esc(content) + '</span></td><td><span class="nbh-status ' + (profile.enabled ? 'is-success' : 'is-muted') + '">' + (profile.enabled ? 'Enabled' : 'Disabled') + '</span></td><td>' + actions + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    }
    function renderSchedules(schedules,profiles) {
        if (!schedules.length) return '<div class="nbh-schedule-empty nbh-schedule-empty--mockup">' + icon('calendar') + '<div><strong>No automatic schedules yet</strong><p>Create a schedule to run backups automatically.</p><button class="nbh-button" data-action="open-schedule-editor">Create schedule</button></div></div>';
        return '<div class="nbh-table-scroll"><table class="nbh-schedules-final-table"><thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Frequency</th><th scope="col">Destination</th><th scope="col">Next run</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>' + schedules.map(function(item) {
            var profile = profiles.find(function(p) { return Number(p.id) === Number(item.profile_id); });
            var content = item.profile || (profile && profile.content_type) || 'full', active = item.enabled !== false && item.status !== 'disabled', date = scheduleDate(item);
            var mode = String(item.backup_mode || (profile && profile.backup_mode) || 'full');
            var mark = mode === 'incremental' || mode === 'differential' || item.recurrence === 'weekly' ? 'layers' : 'database';
            var description = item.description || (profile && profile.name) || (content === 'full' ? 'Complete website backup' : humanizeSlug(content) + ' backup');
            var frequency = humanizeSlug(item.recurrence || 'daily');
            var clock = item.run_time || '';
            if (/^\d{2}:\d{2}$/.test(clock)) { var hours = Number(clock.slice(0,2)); clock = (hours % 12 || 12) + clock.slice(2) + (hours >= 12 ? ' PM' : ' AM'); }
            var weekday = item.recurrence === 'weekly' && item.weekday != null ? 'Every ' + ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][Number(item.weekday)] : '';
            var dateParts = date ? dashboardDateParts(date) : null;
            var dateText = dateParts ? [dateParts.primary,dateParts.secondary].filter(Boolean).join(' at ') : '—';
            var actions = item.id ? '<button type="button" class="nbh-schedule-final-edit" data-action="open-schedule-editor" data-id="' + attr(item.id) + '" aria-label="Edit ' + attr(item.name) + '">' + icon('pencil') + '<span>Edit</span></button><details class="nbh-schedule-row-menu"><summary aria-label="More actions for ' + attr(item.name) + '"><span aria-hidden="true">•••</span></summary><div class="nbh-schedule-final-menu"><button type="button" data-action="run-schedule" data-id="' + attr(item.id) + '">' + icon('play') + 'Run now</button><button type="button" data-action="delete-schedule" data-id="' + attr(item.id) + '">' + icon('trash') + 'Delete schedule</button></div></details>' : '<button class="nbh-button" data-action="navigate" data-page="settings">Manage</button>';
            return '<tr><td><div class="nbh-schedule-final-name"><span class="nbh-schedule-final-name__icon' + (mark === 'layers' ? ' is-orange' : '') + '">' + (mark === 'database' ? backupsMockupIcon('database') : icon(mark)) + '</span><div><strong>' + esc(item.name || 'Automatic backup') + '</strong><small>' + esc(description) + '</small></div></div></td><td><span class="nbh-schedule-final-type">' + esc(humanizeSlug(mode)) + '</span></td><td><div class="nbh-schedule-final-frequency">' + icon('calendar') + '<div><strong>' + esc(frequency) + '</strong><small>' + esc(weekday) + (weekday && clock ? '<br>' : '') + esc(clock ? 'at ' + clock : '') + '</small></div></div></td><td><span class="nbh-schedule-final-destination">' + backupsMockupIcon('database') + 'Local directory</span></td><td><strong>' + esc(date ? overviewRelativeDate(date,true) : 'Not scheduled') + '</strong><small>' + esc(dateText) + '</small></td><td><span class="nbh-schedule-final-status ' + (active ? 'is-active' : 'is-paused') + '"><i aria-hidden="true"></i>' + (active ? 'Active' : 'Paused') + '</span></td><td><div class="nbh-schedule-final-actions">' + actions + '</div></td></tr>';
        }).join('') + '</tbody></table></div>';
    }
    function renderScheduleCreatePanel(profiles, item) {
        item = object(item);
        var enabledProfiles = profiles.filter(function (profile) { return profile.enabled; });
        var options = enabledProfiles.map(function (profile) { return '<option value="' + attr(profile.id) + '"' + selected(item.profile_id,profile.id) + '>' + esc(profile.name) + '</option>'; }).join('');
        var profileNotice = options ? '' : '<div class="nbh-callout is-warning">' + icon('warning') + '<div><strong>No enabled backup profile</strong><p>Create or enable a backup profile before adding a schedule.</p></div></div>';
        return '<form id="nbh-schedule-form" class="nbh-schedule-create nbh-schedule-create--mockup">' +
            '<div class="nbh-schedule-create__head"><span class="nbh-schedule-create__icon">' + icon('plus') + '</span><div><h3>' + (item.id ? 'Edit schedule' : 'Create a schedule') + '</h3><p>Set up your automated backup.</p></div></div>' +
            profileNotice +
            '<div class="nbh-schedule-create__grid">' +
                fieldInput('Schedule name', 'name', item.name || '', 'text', 'Example: Daily full-site backup') +
                '<label class="nbh-field"><span>Backup profile</span><select name="profile_id" required><option value="">Choose a profile</option>' + options + '</select><small>The selected profile controls backup content.</small></label>' +
                fieldSelect('Frequency', 'recurrence', [['hourly', 'Hourly'], ['twicedaily', 'Twice daily'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly']], item.recurrence || 'daily', '') +
                fieldInput('Run time', 'run_time', item.run_time || '02:00', 'time', 'Uses the WordPress timezone') +
            '</div>' +
            '<details class="nbh-details nbh-schedule-advanced"><summary><span>Advanced schedule options</span><small>Optional</small></summary><div class="nbh-details__body"><div class="nbh-field-row">' +
                fieldSelect('Backup mode', 'backup_mode', [['full', 'Full'], ['incremental', 'Incremental'], ['differential', 'Differential']], item.backup_mode || 'full', '') +
                fieldSelect('When another run is active', 'overlap_policy', [['skip', 'Skip this run'], ['queue', 'Queue another run']], item.overlap_policy || 'skip', '') +
            '</div></div></details>' +
            '<input type="hidden" name="id" value="' + attr(item.id || '') + '"><input type="hidden" name="enabled" value="' + (item.enabled === false ? '0' : '1') + '">' +
            '<div class="nbh-form-actions"><span class="nbh-form-hint">' + (item.id ? 'Saved changes apply to this schedule.' : 'The schedule becomes active immediately.') + '</span><button class="nbh-button nbh-button--primary" type="submit"' + (!options ? ' disabled' : '') + '>' + (item.id ? 'Save schedule' : 'Create schedule') + '</button></div>' +
        '</form>';
    }
    function reportJobLabel(job) {
        job = object(job);
        var type = String(job.type || '').toLowerCase();
        if (type === 'backup') return 'Backup';
        if (type === 'restore' || type === 'chain_restore') return 'Restore';
        if (type === 'remote_import') return 'Remote import';
        if (type === 'migration') return 'Migration';
        if (type === 'import') return 'Import';
        return humanizeSlug(type || 'operation');
    }

    function humanizeSlug(value) {
        value = String(value || '').replace(/[_-]+/g, ' ').trim();
        return value ? value.charAt(0).toUpperCase() + value.slice(1) : 'Unknown';
    }
    function reportStatusLabel(status) {
        status = String(status || '').toLowerCase();
        var map = { completed: 'Completed', completed_with_warnings: 'Completed with warnings', failed: 'Failed', running: 'Running', queued: 'Queued', paused: 'Paused', cancelled: 'Cancelled', cancelling: 'Cancelling', verifying: 'Verifying', retry_scheduled: 'Retry scheduled', retrying: 'Retrying' };
        return map[status] || humanizeSlug(status || 'unknown');
    }
    function reportStatusClass(status) {
        status = String(status || '').toLowerCase();
        if (status === 'completed')
            return 'is-success';
        if (status === 'completed_with_warnings')
            return 'is-warning';
        if (status === 'failed')
            return 'is-error';
        if (['running', 'queued', 'verifying', 'retrying', 'retry_scheduled', 'cancelling'].indexOf(status) !== -1)
            return 'is-info';
        return 'is-muted';
    }
    function canCancelReportJob(job) {
        var status = String(object(job).status || '').toLowerCase();
        return ['created', 'queued', 'running', 'verifying', 'retrying', 'retry_scheduled', 'paused', 'cancelling', 'pausing'].indexOf(status) !== -1;
    }
    function canRemoveReportJob(job) {
        var status = String(object(job).status || '').toLowerCase();
        return ['completed', 'completed_with_warnings', 'failed', 'cancelled'].indexOf(status) !== -1;
    }
    function renderReportJobActions(job, includeDetails) {
        job = object(job);
        var buttons = [];
        if (includeDetails !== false) {
            buttons.push('<button class="nbh-button nbh-button--small" data-action="report-job-details" data-id="' + attr(job.id) + '">' + icon('info') + 'Details</button>');
        }
        if (canCancelReportJob(job)) {
            buttons.push('<button class="nbh-button nbh-button--small" data-action="report-job-cancel" data-id="' + attr(job.id) + '">' + icon('warning') + 'Cancel</button>');
        }
        if (canRemoveReportJob(job)) {
            buttons.push('<button class="nbh-button nbh-button--small nbh-button--danger" data-action="report-job-remove" data-id="' + attr(job.id) + '">' + icon('trash') + 'Remove</button>');
        }
        return '<div class="nbh-activity-inline-actions">' + buttons.join('') + '</div>';
    }
    function reportDuration(seconds) {
        seconds = number(seconds, -1);
        if (seconds < 0)
            return '—';
        if (seconds < 60)
            return Math.round(seconds) + ' sec';
        if (seconds < 3600)
            return Math.floor(seconds / 60) + 'm ' + Math.round(seconds % 60) + 's';
        return Math.floor(seconds / 3600) + 'h ' + Math.round((seconds % 3600) / 60) + 'm';
    }
    function reportMetric(iconName, label, value, detail, tone, badge, badgeDetail) {
        var mark = iconName === 'database' ? backupsMockupIcon('database') : icon(iconName);
        return '<article class="nbh-report-metric is-' + attr(tone || 'blue') + '"><span class="nbh-report-metric__icon is-' + attr(tone || 'blue') + '">' + mark + '</span><div><small>' + esc(label) + '</small><strong>' + esc(value) + '</strong><span>' + esc(detail || '') + '</span></div><footer><span class="nbh-report-metric__badge">' + icon(iconName) + esc(badge) + '</span><small>' + esc(badgeDetail) + '</small></footer></article>';
    }
    // Report timestamps are UTC; keep calendar buckets consistent with the API.
    function reportJobDate(job) {
        var value = String(job.started || job.created || '');
        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)) value = value.replace(' ', 'T') + 'Z';
        return new Date(value);
    }
    function reportPeriodJobs(report) {
        var days = number(state.reportPeriod, 30);
        var end = reportJobDate({ created: report.generated_at || new Date().toISOString() });
        if (isNaN(end.getTime())) end = new Date();
        var start = new Date(end); start.setUTCHours(0, 0, 0, 0); start.setUTCDate(start.getUTCDate() - days + 1);
        return array(report.recent_jobs).filter(function (job) {
            var date = reportJobDate(job);
            return !days || (!isNaN(date.getTime()) && date >= start && date <= end);
        });
    }
    function filteredReportJobs(report) {
        var filters = object(state.reportFilters);
        var query = String(filters.query || '').trim().toLowerCase();
        return reportPeriodJobs(report).filter(function (job) {
            var typeMatch = !filters.type || filters.type === 'all' || String(job.type) === String(filters.type);
            var statusMatch = !filters.status || filters.status === 'all' || String(job.status) === String(filters.status);
            var haystack = [job.id, reportJobLabel(job), job.type, job.status, job.step, job.phase, job.error, job.error_code].join(' ').toLowerCase();
            return typeMatch && statusMatch && (!query || haystack.indexOf(query) !== -1);
        });
    }
    function reportPeriodSelect() {
        return '<select data-action="report-period" aria-label="Activity period">' + [7, 14, 30, 0].map(function (days) {
            return '<option value="' + days + '"' + selected(days, state.reportPeriod == null ? 30 : state.reportPeriod) + '>' + (days ? 'Last ' + days + ' days' : 'All loaded activity') + '</option>';
        }).join('') + '</select>';
    }
    function renderReportChart(report) {
        var days = Math.min(14, number(state.reportPeriod, 30) || 14);
        var end = reportJobDate({ created: report.generated_at || new Date().toISOString() });
        if (isNaN(end.getTime())) end = new Date();
        end.setUTCHours(0, 0, 0, 0);
        var start = new Date(end); start.setUTCDate(start.getUTCDate() - days + 1);
        var buckets = [];
        for (var i = 0; i < days; i++) {
            var date = new Date(start); date.setUTCDate(date.getUTCDate() + i);
            buckets.push({ key: date.toISOString().slice(0, 10), date: date, success: 0, failed: 0, other: 0 });
        }
        reportPeriodJobs(report).forEach(function (job) {
            var date = reportJobDate(job);
            if (isNaN(date.getTime())) return;
            var bucket = buckets.find(function (item) { return item.key === date.toISOString().slice(0, 10); });
            if (bucket) bucket[['completed', 'completed_with_warnings'].indexOf(job.status) !== -1 ? 'success' : job.status === 'failed' ? 'failed' : 'other']++;
        });
        var maximum = Math.max(4, Math.ceil(Math.max.apply(null, buckets.map(function (item) { return item.success + item.failed + item.other; })) / 4) * 4);
        var bars = buckets.map(function (item, index) {
            var label = item.date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' });
            var description = label + ': ' + item.success + ' successful, ' + item.failed + ' failed, ' + item.other + ' other jobs';
            return '<div class="nbh-activity-chart__day"><div class="nbh-activity-chart__bar" role="img" aria-label="' + attr(description) + '" title="' + attr(description) + '"><i class="is-success" style="height:' + item.success / maximum * 100 + '%"></i><i class="is-failed" style="height:' + item.failed / maximum * 100 + '%"></i><i class="is-other" style="height:' + item.other / maximum * 100 + '%"></i></div><small>' + esc(index % Math.max(1, Math.ceil(days / 10)) === 0 || index === days - 1 ? label : '') + '</small></div>';
        }).join('');
        return '<div class="nbh-activity-chart"><div class="nbh-activity-chart__axis">' + [maximum, maximum * .75, maximum * .5, maximum * .25, 0].map(function (value) { return '<span>' + value + '</span>'; }).join('') + '</div><div class="nbh-activity-chart__plot">' + bars + '</div><div class="nbh-activity-chart__legend"><span><i class="is-success"></i>Successful</span><span><i class="is-failed"></i>Failed</span><span><i class="is-other"></i>Other</span></div></div><p class="nbh-activity-scope">Daily jobs · UTC · Based on the latest ' + array(report.recent_jobs).length + ' loaded jobs' + (number(state.reportPeriod, 30) === 0 || number(state.reportPeriod, 30) > 14 ? ' · Chart shows the last 14 days' : '') + '</p>';
    }
    function renderReportPagination(action, current, totalPages) {
        current = Math.max(1, number(current, 1));
        totalPages = Math.max(1, number(totalPages, 1));
        if (totalPages <= 1)
            return '';
        var start = Math.max(1, current - 2);
        var end = Math.min(totalPages, start + 4);
        start = Math.max(1, end - 4);
        var pages = [];
        for (var page = start; page <= end; page += 1) {
            pages.push('<button class="nbh-report-page-button' + (page === current ? ' is-active' : '') + '" type="button" data-action="' + attr(action) + '" data-page="' + page + '"' + (page === current ? ' aria-current="page"' : '') + '>' + page + '</button>');
        }
        return '<nav class="nbh-report-pagination" aria-label="Report pages"><button class="nbh-report-page-button is-nav" type="button" data-action="' + attr(action) + '" data-page="' + Math.max(1, current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '>Previous</button><div>' + pages.join('') + '</div><button class="nbh-report-page-button is-nav" type="button" data-action="' + attr(action) + '" data-page="' + Math.min(totalPages, current + 1) + '"' + (current >= totalPages ? ' disabled' : '') + '>Next</button></nav>';
    }
    function renderReportFinding(item) {
        item = object(item);
        var level = item.level || 'notice';
        var cls = level === 'critical' ? 'is-danger' : (level === 'warning' ? 'is-warning' : 'is-info');
        var action = item.action_label && item.action_page ? '<button class="nbh-button nbh-button--small" data-action="navigate" data-page="' + attr(item.action_page) + '">' + esc(item.action_label) + '</button>' : '';
        return '<article class="nbh-report-finding ' + cls + '"><span>' + icon(level === 'critical' ? 'warning' : (level === 'warning' ? 'warning' : 'info')) + '</span><div><strong>' + esc(item.title || humanizeSlug(level)) + '</strong><p>' + esc(item.message || '') + '</p></div>' + action + '</article>';
    }
    function clearReportFilters() {
        var form = mount.querySelector('#nbh-report-filters');
        if (form) {
            form.reset();
            var query = form.querySelector('[name="query"]');
            var type = form.querySelector('[name="type"]');
            var status = form.querySelector('[name="status"]');
            if (query)
                query.value = '';
            if (type)
                type.value = 'all';
            if (status)
                status.value = 'all';
        }
        state.reportFilters = { query: '', type: 'all', status: 'all' };
        state.reportPagination.jobs = 1;
        render();
    }
    function renderReportJobs(report) {
        var all = array(report.recent_jobs), jobs = filteredReportJobs(report), filters = object(state.reportFilters);
        var types = [];
        all.forEach(function (job) { if (types.indexOf(job.type) === -1) types.push(job.type); });
        var pageSize = 10, totalPages = Math.max(1, Math.ceil(jobs.length / pageSize));
        var currentPage = Math.min(totalPages, Math.max(1, number(object(state.reportPagination).jobs, 1)));
        state.reportPagination.jobs = currentPage;
        var startIndex = (currentPage - 1) * pageSize, pageJobs = jobs.slice(startIndex, startIndex + pageSize);
        var toolbar = '<form id="nbh-report-filters" class="nbh-activity-filters" onsubmit="return false"><select name="type" aria-label="Activity type"><option value="all">All activities</option>' + types.map(function (type) { return '<option value="' + attr(type) + '"' + selected(type, filters.type || 'all') + '>' + esc(reportJobLabel({ type: type })) + '</option>'; }).join('') + '</select><select name="status" aria-label="Activity status"><option value="all">All status</option>' + ['completed', 'completed_with_warnings', 'failed', 'running', 'queued', 'paused', 'cancelled'].map(function (status) { return '<option value="' + attr(status) + '"' + selected(status, filters.status || 'all') + '>' + esc(reportStatusLabel(status)) + '</option>'; }).join('') + '</select><label>' + icon('search') + '<input name="query" type="search" aria-label="Search logs" value="' + attr(filters.query || '') + '" placeholder="Search logs…"></label><button class="nbh-button nbh-button--small" type="button" data-action="report-apply-filters">Apply</button></form>';
        var heading = '<div class="nbh-activity-heading">' + icon('file') + '<div><h2>Activity log</h2><p>Detailed log of backup-related operations.</p></div>' + toolbar + '</div><div class="nbh-activity-log-tools"><span>Finished job records can be removed without deleting backup archives.</span><button type="button" class="nbh-button nbh-button--danger" data-action="report-clear-jobs">' + icon('trash') + 'Delete all finished logs</button></div>';
        if (!all.length) return heading + emptyState('No activity yet', 'Backup, restore and import operations will appear here.', '', '');
        var rows = pageJobs.map(function (job) {
            var date = reportJobDate(job), progress = Math.max(0, Math.min(100, number(job.progress)));
            var detail = job.error || (job.step || job.phase ? humanizeSlug(job.step || job.phase) : reportJobLabel(job));
            var mark = job.type === 'restore' || job.type === 'chain_restore' ? 'restore' : job.type === 'import' || job.type === 'remote_import' ? 'upload' : 'database';
            var tone = mark === 'restore' ? 'green' : mark === 'upload' ? 'purple' : 'blue';
            if (job.type === 'verify') { mark = 'shield'; tone = 'green'; }
            var statusMark = job.status === 'failed' ? 'warning' : ['completed', 'completed_with_warnings'].indexOf(job.status) !== -1 ? 'check' : 'clock';
            return '<tr><td><div class="nbh-activity-date"><span class="nbh-activity-event-icon is-' + tone + '">' + (mark === 'database' ? backupsMockupIcon('database') : icon(mark)) + '</span><div><time>' + esc(isNaN(date.getTime()) ? '—' : date.toLocaleDateString(undefined, {month:'short',day:'numeric',year:'numeric'})) + '</time><small>' + esc(isNaN(date.getTime()) ? '' : date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })) + '</small></div></div></td><td>' + esc(reportJobLabel(job)) + '</td><td><span class="nbh-status ' + reportStatusClass(job.status) + '">' + icon(statusMark) + esc(job.status === 'completed' ? 'Completed' : reportStatusLabel(job.status)) + '</span></td><td><span class="nbh-activity-detail' + (job.error ? ' is-error' : '') + '">' + esc(detail) + '</span><small>Job #' + attr(job.id) + ' · ' + (job.duration == null ? progress + '% progress' : esc(reportDuration(job.duration))) + (job.processed_bytes ? ' · ' + esc(formatBytes(job.processed_bytes)) : '') + '</small></td><td>' + renderReportJobActions(job, true) + '</td></tr>';
        }).join('');
        var table = pageJobs.length ? '<div class="nbh-table-scroll"><table class="nbh-table nbh-activity-log-table"><thead><tr><th>Date &amp; Time</th><th>Type</th><th>Status</th><th>Details</th><th>Actions</th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<div class="nbh-report-empty-filter">' + icon('search') + '<strong>No matching jobs</strong><p>Adjust the filters or activity period.</p><button class="nbh-button" data-action="report-clear-filters">Reset filters</button></div>';
        return heading + table + '<div class="nbh-report-table-footer"><span>Showing ' + (jobs.length ? startIndex + 1 : 0) + '–' + Math.min(startIndex + pageJobs.length, jobs.length) + ' of ' + jobs.length + ' matching jobs</span>' + renderReportPagination('report-jobs-page', currentPage, totalPages) + '</div>';
    }
    function renderReportAudit(report) {
        var audit = array(report.recent_audit);
        if (!audit.length)
            return '<div class="nbh-report-empty-filter">' + icon('reports') + '<strong>No audit events yet</strong><p>Administrative backup actions will be recorded here.</p></div>';
        var pageSize = 8;
        var totalPages = Math.max(1, Math.ceil(audit.length / pageSize));
        var currentPage = Math.min(totalPages, Math.max(1, number(object(state.reportPagination).audit, 1)));
        state.reportPagination.audit = currentPage;
        var startIndex = (currentPage - 1) * pageSize;
        var rows = audit.slice(startIndex, startIndex + pageSize);
        return '<div class="nbh-report-audit-list">' + rows.map(function (row) {
            return '<div class="nbh-report-audit-row"><span>' + icon('check') + '</span><div><strong>' + esc(humanizeSlug(row.action)) + '</strong><small>' + esc((row.object_type ? humanizeSlug(row.object_type) : 'System') + (row.object_id ? ' · ' + row.object_id : '')) + '</small></div><time>' + esc(formatDate(row.created_at)) + '</time></div>';
        }).join('') + '</div><div class="nbh-report-audit-footer"><span>Showing ' + (startIndex + 1) + '–' + Math.min(startIndex + rows.length, audit.length) + ' of ' + audit.length + ' events</span>' + renderReportPagination('report-audit-page', currentPage, totalPages) + '</div>';
    }
    function renderReports() {
        var report = object(state.data.health), findings = array(report.findings), sections = object(state.reportSections);
        var recent = reportPeriodJobs(report);
        var successful = recent.filter(function (job) { return ['completed', 'completed_with_warnings'].indexOf(job.status) !== -1; }).length;
        var failed = recent.filter(function (job) { return job.status === 'failed'; }).length;
        var terminal = successful + failed;
        var durations = recent.filter(function (job) { return job.duration != null; }).map(function (job) { return number(job.duration); });
        var periodLabel = number(state.reportPeriod, 30) ? 'Last ' + number(state.reportPeriod, 30) + ' days · loaded jobs' : 'All loaded jobs';
        function fold(key, mark, title, detail, badge, content, defaultOpen) {
            var open = sections[key] == null ? !!defaultOpen : sections[key];
            return '<section class="nbh-panel nbh-report-fold"><button type="button" class="nbh-report-disclosure" data-action="report-toggle-section" data-section="' + key + '" aria-expanded="' + open + '" aria-controls="nbh-report-section-' + key + '"><span class="nbh-report-section-icon">' + icon(mark) + '</span><span class="nbh-report-section-heading"><strong>' + title + '</strong><small>' + detail + '</small></span>' + badge + icon('chevron') + '</button><div class="nbh-report-fold-content" id="nbh-report-section-' + key + '"' + (open ? '' : ' hidden') + '>' + content + '</div></section>';
        }
        function summaryRow(mark, label, value, tone) {
            return '<div class="nbh-activity-summary-row"><span class="is-' + attr(tone || 'blue') + '">' + icon(mark) + '</span><small>' + esc(label) + '</small><strong>' + esc(value) + '</strong></div>';
        }
        return '<section class="nbh-stack nbh-reports-page nbh-activity-dashboard"><div class="nbh-report-metrics">' +
            reportMetric('database', 'Total backups', number(report.backup_count), 'All saved recovery points', 'blue', recent.length, 'Jobs in selected period') +
            reportMetric('check', 'Successful jobs', successful, 'Completed successfully', 'green', terminal ? Math.round(successful / terminal * 1000) / 10 + '%' : '—', 'Success rate') +
            reportMetric('warning', 'Failed jobs', failed, 'Encountered errors', 'orange', terminal ? Math.round(failed / terminal * 1000) / 10 + '%' : '—', 'Failure rate') +
            reportMetric('database', 'Storage used', formatBytes(report.backup_bytes), 'All local backup archives', 'purple', number(report.backup_count), 'Saved backups') + '</div><div class="nbh-activity-layout"><div class="nbh-activity-main">' +
            '<section class="nbh-panel nbh-activity-chart-panel"' + (sections.chart === false ? ' hidden' : '') + '><div class="nbh-activity-heading">' + icon('reports') + '<div><h2>Backup activity</h2><p>Recent backup activity and system operations.</p></div><div class="nbh-activity-period">' + icon('calendar') + reportPeriodSelect() + '</div></div>' + renderReportChart(report) + '</section>' +
            '<section class="nbh-panel nbh-activity-log">' + renderReportJobs(report) + '</section>' +
            fold('findings','shield','Protection findings','Clear actions based on actual backup, schedule, job and disk conditions.', '<span class="nbh-status ' + (findings.length ? 'is-warning' : 'is-success') + '">' + esc(findings.length ? findings.length + ' finding' + (findings.length === 1 ? '' : 's') : 'Healthy') + '</span>', findings.length ? '<div class="nbh-report-findings">' + findings.map(renderReportFinding).join('') + '</div>' : '<div class="nbh-callout is-safe">' + icon('check') + '<div><strong>No protection issues found</strong><p>Your current local backup configuration looks healthy.</p></div></div>', findings.length > 0) +
            fold('audit','file','Audit trail','Administrative actions recorded locally without exposing passwords, tokens or other secrets.', '<span class="nbh-status is-muted">' + array(report.recent_audit).length + ' events</span>', '<div class="nbh-report-audit-actions"><button class="nbh-button" data-action="report-export-audit">Export audit</button><button class="nbh-button nbh-button--danger" data-action="report-clear-audit">Clear audit trail</button></div>' + renderReportAudit(report), false) + '</div><aside class="nbh-activity-aside"><section class="nbh-panel"><div class="nbh-activity-heading">' + icon('file') + '<h2>Report summary</h2>' + reportPeriodSelect() + '</div>' +
            summaryRow('database', 'Total jobs', recent.length) + summaryRow('check', 'Successful', successful, 'green') + summaryRow('warning', 'Failed', failed, 'red') + summaryRow('clock', 'Active / other', recent.length - successful - failed) + summaryRow('archive', 'Saved backups', number(report.backup_count)) + summaryRow('server', 'Total archive size', formatBytes(report.backup_bytes)) + summaryRow('clock', 'Average job time', durations.length ? reportDuration(durations.reduce(function (sum, value) { return sum + value; }, 0) / durations.length) : '—') + '<p class="nbh-activity-scope">' + esc(periodLabel) + '. History includes up to 75 recent jobs.</p></section>' +
            '<section class="nbh-panel nbh-activity-quick"><div class="nbh-activity-heading">' + icon('file') + '<h2>Quick actions</h2></div><button class="nbh-button nbh-button--primary" data-action="report-export-csv">' + icon('download') + ' Download activity log</button><button class="nbh-button" data-action="report-export-json">' + icon('reports') + ' Generate report</button><p class="nbh-activity-scope">Exports matching jobs as CSV or JSON.</p></section>' +
            '<section class="nbh-panel nbh-activity-management"><div class="nbh-activity-heading">' + icon('database') + '<h2>Log management</h2></div><p>Activity is stored locally. Clear finished job records when you no longer need them.</p><button class="nbh-button nbh-button--danger" data-action="report-clear-jobs">' + icon('trash') + ' Clear completed activity</button></section></aside></div></section>';
    }

    function helpDocumentation() {
        return {
            overview: { icon: 'dashboard', title: 'Overview', summary: 'Understand local protection status and the next recommended action.', page: 'dashboard', sections: [
                { icon: 'health', title: 'Protection status', paragraphs: ['Overview summarizes local recovery points, automatic schedules and compatibility checks.'], bullets: [
                    { title: 'Protected', text: 'At least one usable backup exists, an automatic schedule is enabled and no critical blocker is active.' },
                    { title: 'Needs setup', text: 'Create a recovery point or enable a schedule before relying on automatic protection.' }
                ] }
            ] },
            export: { icon: 'backup', title: 'Export', summary: 'Create a portable local .nbh backup archive.', page: 'backup', sections: [
                { icon: 'archive', title: 'Create a backup', steps: [
                    { title: 'Choose content', text: 'Select a full-site or focused local backup profile.' },
                    { title: 'Choose a mode', text: 'Use Full for an independent recovery point unless you intentionally use a backup chain.' },
                    { title: 'Start the export', text: 'The resumable job processes the site in bounded slices.' },
                    { title: 'Verify the result', text: 'Open Backups and confirm the archive is usable before depending on it.' }
                ] },
                { icon: 'lock', title: 'Password protection', warning: { title: 'Keep the password separately', text: 'A protected archive cannot be restored without its exact password.' } }
            ] },
            backups: { icon: 'archive', title: 'Backups', summary: 'Inspect, verify, download, restore and remove local recovery points.', page: 'backups', sections: [
                { icon: 'check', title: 'Verify before recovery', paragraphs: ['Verification checks archive structure and integrity before destructive restore work begins.'] },
                { icon: 'download', title: 'Protect downloaded archives', warning: { title: 'Backups may contain private data', text: 'Store downloaded backup files in an access-controlled location.' } }
            ] },
            restore: { icon: 'restore', title: 'Restore', summary: 'Recover database and files with verification and rollback safeguards.', page: 'restore', sections: [
                { icon: 'restore', title: 'Safe restore flow', steps: [
                    { title: 'Choose a restore point', text: 'Select the archive and only the components you intend to recover.' },
                    { title: 'Review the restore plan', text: 'Resolve any safety or compatibility blocker before continuing.' },
                    { title: 'Confirm the operation', text: 'Nobash Backup verifies the archive before publishing restored content.' },
                    { title: 'Test the site', text: 'Confirm admin access, public pages and critical workflows after completion.' }
                ] }
            ] },
            import: { icon: 'upload', title: 'Import', summary: 'Import a native .nbh archive from your computer or a secure HTTPS URL.', page: 'import', sections: [
                { icon: 'upload', title: 'Local upload', paragraphs: ['Large local archives use resumable transfer checkpoints and are validated before being added to Backups.'] },
                { icon: 'link', title: 'URL import', paragraphs: ['Use a direct HTTPS URL. Optional SHA-256 verification can confirm the exact remote file.'] }
            ] },
            schedules: { icon: 'schedule', title: 'Schedules', summary: 'Automate local backups with reusable profiles.', page: 'automation', sections: [
                { icon: 'schedule', title: 'Reliable automation', bullets: [
                    { title: 'Keep a full baseline', text: 'Full backups remain independent recovery points.' },
                    { title: 'Review the next run', text: 'The displayed schedule follows the WordPress site timezone.' },
                    { title: 'Test manually', text: 'Run a new schedule once and verify the resulting archive.' }
                ] }
            ] },
            activity: { icon: 'reports', title: 'Activity & Reports', summary: 'Review local job health, failures and audit events.', page: 'reports', sections: [
                { icon: 'reports', title: 'Troubleshooting', paragraphs: ['Open the latest failed job, identify the last completed phase and address the reported storage, permission or resource problem before retrying.'] }
            ] },
            settings: { icon: 'settings', title: 'Settings', summary: 'Tune local backup performance and safety defaults.', page: 'settings', sections: [
                { icon: 'bolt', title: 'Resource mode', paragraphs: ['Balanced is the recommended default. Use Low resource when a host has strict CPU, memory or execution limits.'] },
                { icon: 'shield', title: 'Safety defaults', paragraphs: ['Keep archive verification and destructive-action confirmation enabled unless you have a specific operational reason to change them.'] }
            ] },
            support: { icon: 'info', title: 'Request Support', summary: 'Send a privacy-conscious support request without sharing secrets.', page: '', sections: [
                { icon: 'lock', title: 'Never share secrets', bullets: [
                    { title: 'Archive passwords', text: 'Do not include backup passwords in messages or screenshots.' },
                    { title: 'WordPress credentials', text: 'Do not send passwords, cookies or private login links.' },
                    { title: 'Private backup content', text: 'Share only the minimum redacted information required to diagnose the issue.' }
                ] }
            ] }
        };
    }

    function helpDocumentationGroups() {
        return [
            { title: 'Start here', topics: ['overview'] },
            { title: 'Backup and recovery', topics: ['export', 'backups', 'restore', 'import'] },
            { title: 'Automation and operations', topics: ['schedules', 'activity', 'settings'] },
            { title: 'Support', topics: ['support'] }
        ];
    }
    function helpDocumentationSection(section) {
        section = object(section);
        var html = '<section class="nbh-kb-post-section">' +
            '<div class="nbh-kb-post-section__heading"><span>' + icon(section.icon || 'info') + '</span><h3>' + esc(section.title || 'Guidance') + '</h3></div>';
        array(section.paragraphs).forEach(function (paragraph) { html += '<p>' + esc(paragraph) + '</p>'; });
        if (section.steps)
            html += '<ol class="nbh-kb-steps">' + array(section.steps).map(function (step) {
                step = typeof step === 'string' ? { title: '', text: step } : object(step);
                return '<li><div>' + (step.title ? '<strong>' + esc(step.title) + '</strong>' : '') + '<p>' + esc(step.text || '') + '</p></div></li>';
            }).join('') + '</ol>';
        if (section.bullets)
            html += '<ul class="nbh-kb-points">' + array(section.bullets).map(function (item) {
                item = typeof item === 'string' ? { title: '', text: item } : object(item);
                return '<li><span>' + icon('check') + '</span><div>' + (item.title ? '<strong>' + esc(item.title) + '</strong>' : '') + '<p>' + esc(item.text || '') + '</p></div></li>';
            }).join('') + '</ul>';
        if (section.tip) {
            var tip = object(section.tip);
            html += '<aside class="nbh-kb-note is-tip">' + icon('info') + '<div><strong>' + esc(tip.title || 'Tip') + '</strong><p>' + esc(tip.text || '') + '</p></div></aside>';
        }
        if (section.warning) {
            var warning = object(section.warning);
            html += '<aside class="nbh-kb-note is-warning">' + icon('warning') + '<div><strong>' + esc(warning.title || 'Important') + '</strong><p>' + esc(warning.text || '') + '</p></div></aside>';
        }
        return html + '</section>';
    }
    function renderSupportRequestForm() {
        var user = object(cfg.currentUser);
        return '<section class="nbh-support-request" aria-labelledby="nbh-support-request-title">' +
            '<h3 id="nbh-support-request-title">Email Nobash Backup Support</h3>' +
            '<p>Send your question directly to <strong>support@nobash.com</strong>. Do not include passwords, private backup files, access tokens or other secrets.</p>' +
            '<form id="nbh-support-form" class="nbh-support-form">' +
            '<div class="nbh-field-row">' +
            fieldInput('Your name', 'name', user.name || '', 'text', 'Name used for the support reply') +
            fieldInput('Reply email', 'email', user.email || '', 'email', 'Support will reply to this address') +
            '</div>' +
            fieldInput('Subject', 'subject', '', 'text', 'Briefly describe the issue') +
            textareaField('Message', 'message', '', 'Describe what you were doing, what happened and any exact error message. Do not include passwords or secret keys.') +
            '<div class="nbh-support-form__actions"><span>Email is sent through this WordPress site’s configured mail service.</span><button class="nbh-button nbh-button--primary" type="submit">Send support request</button></div>' +
            '</form>' +
            '</section>';
    }
    function renderHelpTopicAccordion(topic, doc, expanded) {
        return '<details class="nbh-kb-item" id="nbh-help-topic-' + attr(topic) + '"' + (expanded ? ' open' : '') + '>' +
            '<summary data-action="help-toggle-topic" data-topic="' + attr(topic) + '"><span class="nbh-kb-item__icon">' + icon(doc.icon || 'info') + '</span><strong>' + esc(doc.title) + '</strong><span class="nbh-kb-item__arrow">' + icon('chevron') + '</span></summary>' +
            '<article class="nbh-kb-post"><header class="nbh-kb-post__header"><span class="nbh-eyebrow">Nobash Backup documentation</span><h2>' + esc(doc.title) + '</h2><p>' + esc(doc.summary) + '</p>' + (doc.page ? '<button class="nbh-kb-open-page" data-action="navigate" data-page="' + attr(doc.page) + '">Open ' + esc(doc.title) + ' ' + icon('chevron') + '</button>' : '') + '</header><div class="nbh-kb-post__body">' + array(doc.sections).map(helpDocumentationSection).join('') + (topic === 'support' ? renderSupportRequestForm() : '') + '</div></article>' +
            '</details>';
    }
    function renderHelpSupport() {
        var docs = helpDocumentation();
        state.helpTopic = normalizeHelpTopic(state.helpTopic);
        var active = state.helpTopic !== 'docs' && docs[state.helpTopic] ? state.helpTopic : '';
        var groups = helpDocumentationGroups();
        var body = groups.map(function (group) {
            var topics = group.topics.filter(function (topic) { return docs[topic]; });
            if (!topics.length) return '';
            return '<section class="nbh-kb-group"><h2>' + esc(group.title) + '</h2><div class="nbh-kb-list">' + topics.map(function (topic) { return renderHelpTopicAccordion(topic, docs[topic], topic === active); }).join('') + '</div></section>';
        }).join('');
        return '<section class="nbh-kb"><header class="nbh-kb-intro"><h2>Documentation</h2><p>Simple guides for the core backup and restore workflow.</p></header>' + body + '</section>';
    }

    function settingsTabs() {
        return [
            { id: 'general', label: 'General', icon: 'settings', description: 'Backup defaults and admin shortcuts' },
            { id: 'retention', label: 'Retention', icon: 'archive', description: 'Local backup retention and cleanup' },
            { id: 'content', label: 'Exclusions', icon: 'folder', description: 'Paths and database tables to skip' },
            { id: 'performance', label: 'Processing', icon: 'bolt', description: 'Batch timing and server limits' },
            { id: 'notifications', label: 'Notifications', icon: 'mail', description: 'Email and signed webhook alerts' },
            { id: 'advanced', label: 'Advanced', icon: 'settings', description: 'Configuration tools and uninstall behavior' }
        ];
    }

    function renderSettings() {
        var settings = Object.assign({}, object(state.data.settings), object(state.settingsDraft));
        var tabs = settingsTabs();
        if (!tabs.some(function (tab) { return tab.id === state.settingsTab; }))
            state.settingsTab = 'general';
        var active = tabs.find(function (tab) { return tab.id === state.settingsTab; }) || tabs[0];
        return '<section class="nbh-settings-workspace">' +
            '<div class="nbh-settings-layout">' +
            '<nav class="nbh-settings-nav" role="tablist" aria-orientation="horizontal" aria-label="Settings sections">' + tabs.map(function (tab) {
            var selectedTab = state.settingsTab === tab.id;
            return '<button id="nbh-settings-tab-' + attr(tab.id) + '" type="button" role="tab" aria-controls="nbh-settings-panel" aria-selected="' + selectedTab + '" aria-label="' + attr(tab.label + ': ' + tab.description) + '" title="' + attr(tab.description) + '" tabindex="' + (selectedTab ? '0' : '-1') + '" class="' + (selectedTab ? 'is-active' : '') + '" data-action="settings-tab" data-tab="' + attr(tab.id) + '">' + icon(tab.icon) + '<span class="nbh-settings-nav__label">' + esc(tab.label) + '</span></button>';
        }).join('') + '</nav>' +
            '<form id="nbh-settings-form" class="nbh-settings-form">' +
            '<header class="nbh-settings-current"><div><span class="nbh-eyebrow">Settings</span><h2>' + esc(active.label) + '</h2><p>' + esc(active.description) + '</p></div><span class="nbh-settings-current__icon">' + icon(active.icon) + '</span></header>' +
            '<div class="nbh-settings-body" id="nbh-settings-panel" role="tabpanel" aria-labelledby="nbh-settings-tab-' + attr(state.settingsTab) + '">' + renderSettingsSection(state.settingsTab, settings) + '</div>' +
            '<footer class="nbh-sticky-actions' + (state.settingsDirty ? ' is-dirty' : '') + '"><div><strong>' + (state.settingsDirty ? 'Unsaved settings' : 'Settings are up to date') + '</strong><small>' + (state.settingsDirty ? 'Review and save your changes before leaving this page.' : 'Sensitive values are protected and never returned to the browser.') + '</small></div><button class="nbh-button nbh-button--primary" type="submit"' + (state.settingsDirty ? '' : ' disabled') + '>' + icon('check') + '<span>Save settings</span></button></footer>' +
            '</form>' +
            '</div>' +
            '</section>';
    }
    function renderSettingsSection(tab, s) {
        if (tab === 'retention') {
            return settingsCard('archive', 'Automatic retention', 'Choose how many recovery points to keep on this site.', '<div class="nbh-field-grid nbh-field-grid--2">' +
                numberField('Keep latest backups', 'retention_count', s.retention_count || 10, 1, 500, 'Minimum number of recent backups to keep.') +
                numberField('Maximum backup age', 'retention_days', s.retention_days || 30, 1, 3650, 'Older backups may be eligible for cleanup.', 'days') +
                numberField('Minimum free space', 'retention_min_free_mb', s.retention_min_free_mb == null ? 1024 : s.retention_min_free_mb, 0, 1048576, 'Free-space threshold for retention and health reports.', 'MB') +
                numberField('Temporary recovery files', 'temporary_retention_hours', s.temporary_retention_hours || 48, 6, 720, 'Retention for temporary restore and import files.', 'hours') + '</div>') +
                settingsCard('search', 'Review cleanup safely', 'Preview eligible backups before removing them.', '<div class="nbh-settings-actions"><div><strong>Retention preview</strong><small>Current changes are saved before preview or cleanup.</small></div><div class="nbh-button-row"><button type="button" class="nbh-button" data-action="retention-preview">' + icon('search') + ' Preview cleanup</button><button type="button" class="nbh-button nbh-button--danger-subtle" data-action="retention-run">' + icon('trash') + ' Run cleanup now</button></div></div><div id="nbh-retention-result" class="nbh-settings-result" aria-live="polite"></div>');
        }
        if (tab === 'content') {
            return settingsCard('folder', 'Excluded paths', 'Skip folders and files relative to your WordPress installation.', settingsSuggestions('exclude_paths', ['node_modules', '.git', 'cache', 'private-cache']) + textareaField('Excluded paths', 'exclude_paths', array(s.exclude_paths).join('\n'), 'One relative path per line. Absolute paths and parent-directory traversal are not allowed.')) +
                settingsCard('database', 'Excluded database tables', 'Only exact table names are excluded.', textareaField('Excluded tables', 'exclude_tables', array(s.exclude_tables).join('\n'), 'One complete database table name per line.')) +
                settingsCallout('info', 'Automatic exclusions', 'Backup archives and temporary work files are excluded automatically to prevent recursive backups.');
        }
        if (tab === 'performance') {
            var system = object(object(state.data.status).system);
            return settingsCard('bolt', 'Processing preset', 'Apply a preset for batch duration and pauses between backup requests.', '<div class="nbh-settings-choice-grid nbh-settings-choice-grid--compact">' + resourceChoice('low', 'Low Resource', 'Short batches with longer pauses.', s.resource_mode || 'balanced') + resourceChoice('balanced', 'Balanced', 'Standard batch timing.', s.resource_mode || 'balanced', true) + resourceChoice('high', 'High Throughput', 'Longer batches with no pause.', s.resource_mode || 'balanced') + '</div>') +
                settingsCard('server', 'Job processing', 'Server execution limits can reduce the requested batch duration.', '<div class="nbh-field-grid nbh-field-grid--2">' + numberField('Job slice', 'job_slice_seconds', s.job_slice_seconds || 25, 5, 120, 'Requested processing time per batch.', 'seconds') + numberField('Pause between slices', 'slice_pause_ms', s.slice_pause_ms == null ? 50 : s.slice_pause_ms, 0, 5000, 'Delay between backup requests in this browser. Restore requests run without this pause.', 'ms') + '</div>') +
                settingsCallout('server', 'Server limits', 'PHP memory: ' + (system.memory_limit || 'Unknown') + ' · Maximum execution: ' + (system.max_execution || 'Unknown') + ' seconds · Upload limit: ' + (system.upload_max || 'Unknown') + '.');
        }
        if (tab === 'notifications') {
            return settingsCard('mail', 'Email alerts', 'Use the mail service configured in WordPress.', fieldInput('Notification email', 'notify_email', s.notify_email || '', 'email', 'backups@example.com') + toggleRow('Notify after successful backups', 'notify_on_success', yes(s.notify_on_success), 'Send a message when a backup completes.') + toggleRow('Notify after failures', 'notify_on_failure', s.notify_on_failure !== false, 'Send alerts for failed jobs.')) +
                settingsCard('link', 'Signed webhook alerts', 'Send job notifications to a public HTTPS endpoint.', fieldInput('Webhook URL', 'webhook_url', s.webhook_url || '', 'url', 'Public HTTPS endpoint') + passwordField('Webhook signing secret', 'webhook_secret', s.webhook_secret_set ? 'Leave blank to keep the saved secret' : 'Optional signing secret', s.webhook_secret_set ? 'A signing secret is saved securely.' : 'Use when your receiving service verifies signatures.', { id: 'nbh-settings-webhook-secret', autocomplete: 'new-password', value: s.webhook_secret || '' }) + (s.webhook_secret_set ? '<button type="button" class="nbh-button" data-action="clear-webhook-secret">Remove saved secret</button>' : '')) +
                settingsCard('check', 'Delivery test', 'Save notification settings and send a test to configured channels.', '<button type="button" class="nbh-button" data-action="test-notifications">' + icon('check') + ' Save and send test</button>');
        }
        if (tab === 'advanced') {
            return settingsCard('download', 'Configuration tools', 'Download a secret-free JSON copy of the current settings or load settings from another Nobash Backup installation.', '<div class="nbh-settings-tool-grid"><button type="button" class="nbh-settings-tool" data-action="settings-export"><span>' + icon('download') + '</span><strong>Download settings</strong><small>Exports configuration only. Passwords and webhook secrets are excluded.</small></button><button type="button" class="nbh-settings-tool" data-action="settings-import-trigger"><span>' + icon('upload') + '</span><strong>Import settings</strong><small>Review imported values before saving them to this site.</small></button><button type="button" class="nbh-settings-tool" data-action="settings-restore-defaults"><span>' + icon('refresh') + '</span><strong>Recommended defaults</strong><small>Restores safe defaults without deleting backups or job history.</small></button></div>') +
                settingsCard('trash', 'Uninstall behavior', 'Choose whether WordPress should remove Nobash Backup configuration when the plugin is deleted.', toggleRow('Delete plugin data on uninstall', 'delete_data_on_uninstall', yes(s.delete_data_on_uninstall), 'Removes settings and metadata during uninstall. Local backup archives remain unless removed separately.'), 'is-warning') +
                '<section class="nbh-danger-zone nbh-settings-danger-zone"><div><span class="nbh-danger-zone__icon">' + icon('warning') + '</span><div><h2>Reset plugin data</h2><p>Reset settings and job metadata while preserving local backup archives.</p></div></div><button type="button" class="nbh-button nbh-button--danger" data-action="reset-data">Reset plugin data</button></section>';
        }
        return settingsCard('settings', 'Backup defaults', 'Choose the initial content selection for new exports.', fieldSelect('Default content type', 'default_profile', [['full', 'Full site'], ['database', 'Database only'], ['files', 'Files only'], ['uploads', 'Media uploads']], s.default_profile || 'full', 'You can change this before creating a backup.') + toggleRow('Show quick backup action in admin bar', 'show_admin_bar_action', s.show_admin_bar_action !== false, 'Keep the backup shortcut available in WordPress admin.')) +
            settingsCallout('folder', 'Local backup storage', 'Archives use this installation’s protected local backup directory. Manage recovery points from the Backups page.');
    }
    function settingsCard(iconName, title, description, content, extraClass) {
        return '<section class="nbh-settings-card ' + attr(extraClass || '') + '"><header><span>' + icon(iconName) + '</span><div><h3>' + esc(title) + '</h3><p>' + esc(description) + '</p></div></header><div class="nbh-settings-card__body">' + content + '</div></section>';
    }
    function settingsCallout(iconName, title, message) {
        var warning = iconName === 'warning';
        return '<aside class="nbh-settings-note ' + (warning ? 'is-warning' : '') + '"><span>' + icon(iconName) + '</span><div><strong>' + esc(title) + '</strong><p>' + esc(message) + '</p></div></aside>';
    }
    function numberField(label, name, value, min, max, hint, suffix) {
        return '<label class="nbh-field nbh-number-field"><span>' + esc(label) + '</span><span class="nbh-number-control"><input type="number" name="' + attr(name) + '" value="' + attr(value) + '" min="' + attr(min) + '" max="' + attr(max) + '" step="1" inputmode="numeric">' + (suffix ? '<em>' + esc(suffix) + '</em>' : '') + '</span>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
    }
    function resourceChoice(value, label, description, selectedValue, recommended) {
        var active = String(value) === String(selectedValue);
        return '<label class="nbh-settings-choice ' + (active ? 'is-selected' : '') + '"><input type="radio" name="resource_mode" value="' + attr(value) + '"' + checked(active) + '><span class="nbh-settings-choice__check">' + icon(active ? 'check' : 'server') + '</span><strong>' + esc(label) + (recommended ? '<em>Recommended</em>' : '') + '</strong><small>' + esc(description) + '</small></label>';
    }
    function settingsSuggestions(target, values) {
        return '<div class="nbh-settings-suggestions"><span>Quick add:</span>' + values.map(function (value) { return '<button type="button" data-action="settings-add-exclusion" data-target="' + attr(target) + '" data-value="' + attr(value) + '">+ ' + esc(value) + '</button>'; }).join('') + '</div>';
    }


    function emptyState(title, description, actionLabel, actionPage) {
        var action = actionLabel ? '<button class="nbh-button" data-action="' + (actionPage === 'profile-modal' ? 'add-profile' : 'navigate') + '"' + (actionPage !== 'profile-modal' ? ' data-page="' + attr(actionPage) + '"' : '') + '>' + esc(actionLabel) + '</button>' : '';
        return '<div class="nbh-empty"><span>' + icon('archive') + '</span><strong>' + esc(title) + '</strong><p>' + esc(description) + '</p>' + action + '</div>';
    }
    function fieldInput(label, name, value, type, hint) {
        return '<label class="nbh-field"><span>' + esc(label) + '</span><input type="' + attr(type || 'text') + '" name="' + attr(name) + '" value="' + attr(value) + '" placeholder="' + attr(hint || '') + '">' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
    }
    function passwordField(label, name, placeholder, hint, options) {
        options = object(options);
        var id = options.id || ('nbh-password-' + String(name || 'field').replace(/[^a-z0-9_-]/gi, '-'));
        var attrs = '';
        if (options.minlength)
            attrs += ' minlength="' + attr(options.minlength) + '"';
        if (options.required)
            attrs += ' required';
        if (options.autocomplete)
            attrs += ' autocomplete="' + attr(options.autocomplete) + '"';
        var valueAttr = options.value ? ' value="' + attr(options.value) + '"' : '';
        return '<label class="nbh-field nbh-password-field"><span>' + esc(label) + '</span><span class="nbh-password-control"><input id="' + attr(id) + '" type="password" name="' + attr(name) + '" placeholder="' + attr(placeholder || '') + '"' + valueAttr + attrs + '><button type="button" class="nbh-password-toggle" data-action="toggle-password-visibility" data-target="' + attr(id) + '" aria-controls="' + attr(id) + '" aria-pressed="false" aria-label="Show password">' + icon('eye') + '<span>Show</span></button></span>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
    }
    function fieldSelect(label, name, options, value, hint, extraClass) {
        var classes = ['nbh-field'];
        if (extraClass)
            classes.push(extraClass);
        return '<label class="' + attr(classes.join(' ')) + '"><span>' + esc(label) + '</span><select name="' + attr(name) + '">' + options.map(function (o) { return '<option value="' + attr(o[0]) + '"' + selected(o[0], value) + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
    }
    function textareaField(label, name, value, hint) {
        return '<label class="nbh-field"><span>' + esc(label) + '</span><textarea name="' + attr(name) + '" rows="5" placeholder="' + attr(hint || '') + '">' + esc(value) + '</textarea>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
    }
    function toggleRow(label, name, value, hint) {
        return '<div class="nbh-switch-row"><div><strong>' + esc(label) + '</strong><small>' + esc(hint || '') + '</small></div><label class="nbh-switch"><input type="checkbox" aria-label="' + attr(label) + '" name="' + attr(name) + '"' + checked(value) + '><span></span></label></div>';
    }
    function renderModal() {
        if (!state.modal) return '';
        var content = '';
        if (state.modal.type === 'export-help') content = renderExportHelpModal();
        if (state.modal.type === 'schedule-editor') content = '<button class="nbh-modal-close" data-action="close-modal" aria-label="Close">×</button><h2 id="nbh-modal-title">' + (state.modal.schedule ? 'Edit schedule' : 'Create schedule') + '</h2>' + renderScheduleCreatePanel(array(state.data.profiles),state.modal.schedule);
        if (state.modal.type === 'profile') content = renderProfileModal(state.modal.profile);
        if (state.modal.type === 'archive-details') content = renderArchiveDetailsModal(state.modal.details);
        if (state.modal.type === 'import-url') content = renderImportUrlModal();
        if (state.modal.type === 'import-success') content = renderImportSuccessModal();
        if (state.modal.type === 'confirm') content = renderConfirmModal(state.modal.options);
        if (state.modal.type === 'report-job-details') content = renderReportJobDetailsModal(state.modal.job, state.modal.events);
        if (state.modal.type === 'job-warnings') content = renderJobWarningsModal(state.job);
        return '<div class="nbh-modal-backdrop" data-action="close-modal"><div class="nbh-modal" role="dialog" aria-modal="true" aria-labelledby="nbh-modal-title" data-modal-panel>' + content + '</div></div>';
    }

    function renderExportHelpModal() {
        return '<div class="nbh-export-help">' +
            '<button class="nbh-modal-close" data-action="close-modal" aria-label="Close">×</button>' +
            '<div class="nbh-export-help__hero"><span>' + icon('backup') + '</span><div><span class="nbh-eyebrow">Help</span><h2 id="nbh-modal-title">Export documentation</h2><p>Learn what each export option does and which choice is recommended.</p></div></div>' +
            '<div class="nbh-export-help__content">' +
            '<section class="nbh-export-help__recommendation"><span>' + icon('info') + '</span><div><strong>Recommended for most users</strong><p>Choose <b>Full site</b> and <b>Full export</b>. This creates one independent archive that can be downloaded or restored without relying on an earlier chain.</p></div></section>' +
            '<section class="nbh-export-help__section"><h3>1. Export profiles</h3><div class="nbh-export-help__grid">' +
            exportHelpItem('database', 'Full site', 'Database, media, plugins, themes and WordPress files. Best for disaster recovery.') +
            exportHelpItem('database', 'Database only', 'Posts, pages, settings, users and other database content without site files.') +
            exportHelpItem('file', 'Files only', 'WordPress files without database tables.') +
            exportHelpItem('upload', 'Media uploads', 'Only the uploads library. Useful for large media sites.') +
            '</div></section>' +
            '<section class="nbh-export-help__section"><h3>2. Export modes</h3><div class="nbh-export-help__modes">' +
            exportHelpMode('Full export', 'Independent', 'Creates a complete standalone restore point. Use this for the first export and downloadable archives.') +
            exportHelpMode('Incremental', 'Smallest changes', 'Stores changes since the previous restore point. Keep every required archive in the chain.') +
            exportHelpMode('Differential', 'Simpler chain', 'Stores changes since the latest full export. Restoring normally needs the full export plus the selected differential archive.') +
            '</div></section>' +
            '<section class="nbh-export-help__section"><h3>3. Security and reliability</h3><div class="nbh-export-help__checklist">' +
            '<div>' + icon('lock') + '<span><strong>Password protection</strong><small>Use a unique password of at least 10 characters. Keep it safely; the password is required to open an encrypted archive.</small></span></div>' +
            '<div>' + icon('check') + '<span><strong>Verification</strong><small>Nobash Backup checks archive structure and integrity before marking an export complete.</small></span></div>' +
            '<div>' + icon('refresh') + '<span><strong>Resume support</strong><small>Long-running exports use durable checkpoints so supported interrupted jobs can continue safely.</small></span></div>' +
            '</div></section>' +
            '<section class="nbh-export-help__section"><h3>After the export completes</h3><p>The archive appears under <b>Backups</b>. From there you can review details, download it, restore the site or delete an archive you no longer need.</p></section>' +
            '</div>' +
            '<div class="nbh-modal-actions"><button class="nbh-button nbh-button--primary" data-action="close-modal">Got it</button></div>' +
            '</div>';
    }
    function exportHelpItem(iconName, title, description) {
        return '<div class="nbh-export-help__item"><span>' + icon(iconName) + '</span><div><strong>' + esc(title) + '</strong><small>' + esc(description) + '</small></div></div>';
    }
    function exportHelpMode(title, badge, description) {
        return '<div class="nbh-export-help__mode"><div><strong>' + esc(title) + '</strong><em>' + esc(badge) + '</em></div><p>' + esc(description) + '</p></div>';
    }


    function renderReportJobDetailsModal(job, events) {
        job = object(job);
        events = array(events);
        var result = object(job.result);
        var failure = jobErrorMessage(job);
        var restoreDiagnostic = object(job.restore_diagnostics);
        var restoreFailure = restoreDiagnostic.original_error ? '<section class="nbh-report-modal-section"><h3>Original restore failure</h3><p><strong>Step: ' + esc(humanizeSlug(restoreDiagnostic.failed_phase || 'Not recorded')) + '</strong></p><p>' + esc(restoreDiagnostic.original_error) + '</p></section>' : '';
        var rows = [
            ['Operation', reportJobLabel(job)], ['Job ID', '#' + number(job.id)], ['Status', reportStatusLabel(job.status)], ['Progress', number(job.progress) + '%'],
            ['Current step', humanizeSlug(job.step || object(job.cursor).phase || 'Not recorded')], ['Attempts', number(job.attempt) + ' / ' + number(job.max_attempts)],
            ['Started', job.started ? formatDate(job.started) : 'Not started'], ['Completed', job.completed ? formatDate(job.completed) : 'Not completed'],
            ['Processed data', formatBytes(job.processed_bytes || 0) + (job.total_bytes ? ' / ' + formatBytes(job.total_bytes) : '')]
        ];
        var resultRows = Object.keys(result).filter(function (key) { return result[key] !== '' && result[key] != null; }).map(function (key) { return '<div><span>' + esc(humanizeSlug(key)) + '</span><strong>' + esc(typeof result[key] === 'boolean' ? (result[key] ? 'Yes' : 'No') : result[key]) + '</strong></div>'; }).join('');
        var history = events.length ? '<div class="nbh-job-event-list">' + events.slice(0, 30).map(function (event) { return '<div><span class="nbh-job-event-dot ' + reportStatusClass(event.status) + '"></span><div><strong>' + esc(humanizeSlug(event.event || event.status || 'event')) + '</strong><small>' + esc((event.step ? humanizeSlug(event.step) + ' · ' : '') + (event.message || reportStatusLabel(event.status))) + '</small></div><time>' + esc(formatDate(event.created)) + '</time></div>'; }).join('') + '</div>' : '<p class="nbh-muted">No job event history is available.</p>';
        var actionButtons = [];
        if (canCancelReportJob(job)) {
            actionButtons.push('<button class="nbh-button" data-action="report-job-cancel" data-id="' + attr(job.id) + '">Cancel job</button>');
        }
        if (canRemoveReportJob(job)) {
            actionButtons.push('<button class="nbh-button nbh-button--danger" data-action="report-job-remove" data-id="' + attr(job.id) + '">Remove job</button>');
        }
        actionButtons.push('<button class="nbh-button nbh-button--primary" data-action="close-modal">Close</button>');
        return '<button class="nbh-modal-close" data-action="close-modal" aria-label="Close">×</button><span class="nbh-eyebrow">Activity details</span><h2 id="nbh-modal-title">' + esc(reportJobLabel(job)) + ' · Job #' + attr(job.id) + '</h2><p>Durable operation state and bounded event history.</p><div class="nbh-detail-list nbh-report-job-detail-list">' + rows.map(function (row) { return '<div><span>' + esc(row[0]) + '</span><strong>' + esc(row[1]) + '</strong></div>'; }).join('') + '</div>' + (failure ? '<div class="nbh-callout is-danger">' + icon('warning') + '<div><strong>' + esc(reportJobLabel(job) + ' failed') + '</strong><p>' + esc(failure) + '</p></div></div>' : '') + restoreFailure + (resultRows ? '<section class="nbh-report-modal-section"><h3>Result</h3><div class="nbh-detail-list">' + resultRows + '</div></section>' : '') + '<section class="nbh-report-modal-section"><h3>Event history</h3>' + history + '</section><div class="nbh-modal-actions">' + actionButtons.join('') + '</div>';
    }
    function renderArchiveDetailsModal(details) {
        details = object(details);
        var rows = [
            ['Format', details.format || 'Native NBH'], ['Profile', details.profile_label || details.profile || 'Full site'],
            ['Backup mode', details.backup_mode || 'full'], ['Size', formatBytes(details.size)],
            ['Source', details.source || 'local'], ['Integrity', details.integrity_status || 'ready_for_verification']
        ];
        if (details.chain_id)
            rows.push(['Chain ID', details.chain_id]);
        if (details.original_name)
            rows.push(['Original file', details.original_name]);
        return '<button class="nbh-modal-close" data-action="close-modal" aria-label="Close">×</button><span class="nbh-eyebrow">Backup details</span><h2 id="nbh-modal-title">' + esc(details.filename || 'Archive') + '</h2><div class="nbh-detail-list">' + rows.map(function (r) { return '<div><span>' + esc(r[0]) + '</span><strong>' + esc(r[1]) + '</strong></div>'; }).join('') + '</div><div class="nbh-callout ' + (details.integrity_status === 'verified' ? 'is-safe' : 'is-info') + '">' + icon(details.integrity_status === 'verified' ? 'check' : 'warning') + '<div><strong>' + esc(details.integrity_status === 'verified' ? 'Verified restore point' : 'Verification runs before restore') + '</strong><p>' + esc(details.verification_note || '') + '</p></div></div><div class="nbh-modal-actions"><button class="nbh-button nbh-button--primary" data-action="restore-file" data-file="' + attr(details.filename || '') + '">Restore this backup</button><button class="nbh-button" data-action="close-modal">Close</button></div>';
    }
    function renderConfirmModal(options) {
        options = object(options);
        var intent = options.intent || 'danger';
        var details = array(options.details);
        var note = options.note ? '<div class="nbh-confirm-note">' + icon(options.noteIcon || 'warning') + '<span>' + esc(options.note) + '</span></div>' : '';
        var toneClass = intent === 'danger' ? 'is-danger' : (intent === 'warning' ? 'is-warning' : 'is-info');
        var confirmClass = intent === 'danger' ? 'nbh-button--danger-solid' : 'nbh-button--primary';
        var iconName = options.icon || (intent === 'danger' ? 'warning' : (intent === 'warning' ? 'warning' : 'check'));
        var eyebrow = options.eyebrow || 'Please confirm';
        var shortcut = options.shortcut !== false ? '<div class="nbh-confirm-shortcut">Press <kbd>Esc</kbd> to cancel</div>' : '';
        var detailsClass = 'nbh-confirm-details' + (options.detailsLayout === 'stacked' ? ' is-stacked' : '');
        return '<button class="nbh-modal-close" data-action="close-modal" aria-label="Close">×</button><div class="nbh-confirm-head"><span class="nbh-confirm-icon ' + toneClass + '">' + icon(iconName) + '</span><div><span class="nbh-eyebrow">' + esc(eyebrow) + '</span><h2 id="nbh-modal-title">' + esc(options.title || 'Confirm action') + '</h2><p>' + esc(options.message || 'Please confirm this action.') + '</p></div></div>' + (details.length ? '<div class="' + detailsClass + '">' + details.map(confirmDetailsRow).join('') + '</div>' : '') + note + '<div class="nbh-modal-actions nbh-confirm-actions">' + shortcut + '<div class="nbh-confirm-buttons"><button class="nbh-button" data-action="close-modal">' + esc(options.cancelLabel || 'Cancel') + '</button><button class="nbh-button ' + confirmClass + '" data-action="confirm-modal">' + (options.confirmIcon ? icon(options.confirmIcon) : '') + '<span>' + esc(options.confirmLabel || 'Confirm') + '</span></button></div></div>';
    }

    function renderProfileModal(profile) {
        profile = profile || {};
        return '<button class="nbh-modal-close" data-action="close-modal" aria-label="Close">×</button><span class="nbh-eyebrow">Backup profile</span><h2 id="nbh-modal-title">' + esc(profile.id ? 'Edit profile' : 'Create profile') + '</h2><p>Profiles are reusable local backup presets and are fully included in Core.</p><form id="nbh-profile-form" class="nbh-modal-form"><input type="hidden" name="id" value="' + attr(profile.id || '') + '">' + fieldInput('Profile name', 'name', profile.name || '', 'text', 'e.g. Daily content backup') + '<div class="nbh-field-row">' + fieldSelect('Content', 'content_type', [['full', 'Full site'], ['database', 'Database only'], ['files', 'All files'], ['uploads', 'Media uploads'], ['plugins', 'Plugins'], ['themes', 'Themes'], ['custom', 'Custom selection']], profile.content_type || profile.type || 'full', '') + fieldSelect('Default mode', 'backup_mode', [['full', 'Full'], ['incremental', 'Incremental'], ['differential', 'Differential']], profile.backup_mode || 'full', '') + '</div>' + textareaField('Included folders', 'include_paths', array(profile.include_paths || profile.folders).join('\n'), 'Custom profile only; WordPress-relative paths, one per line') + textareaField('Included tables', 'include_tables', array(profile.include_tables || profile.tables).join('\n'), 'Custom profile only; full or WordPress-prefix-relative table names') + textareaField('Additional exclusions', 'exclusions', array(profile.exclusions).join('\n'), 'WordPress-relative paths, one per line') + toggleRow('Profile enabled', 'enabled', profile.enabled !== false, 'Disabled profiles cannot be selected by a schedule.') + '<div class="nbh-modal-actions"><button class="nbh-button nbh-button--primary" type="submit">Save profile</button><button class="nbh-button" type="button" data-action="close-modal">Cancel</button></div></form>';
    }

    function renderTransferOverlay() {
        var transfer = state.transfer || {};
        var progress = Math.max(0, Math.min(100, number(transfer.progress, 0)));
        var error = transfer.error ? '<div class="nbh-callout is-error">' + icon('warning') + '<div><strong>Import paused</strong><p>' + esc(transfer.error) + '</p></div></div>' : '';
        return '<div class="nbh-job-overlay"><div class="nbh-job-card nbh-job-card--activity" role="dialog" aria-modal="true"><div class="nbh-job-head"><span class="nbh-job-icon">' + icon('upload') + '</span><div><span class="nbh-eyebrow">Resumable import</span><h2>' + esc(transfer.filename || 'Uploading archive') + '</h2></div></div><div class="nbh-progress"><div style="width:' + progress + '%"></div></div><div class="nbh-job-progress"><strong>' + Math.round(progress) + '%</strong><span>' + esc(formatBytes(transfer.offset || 0) + ' of ' + formatBytes(transfer.size || 0)) + '</span></div>' + renderTransferActivity(transfer) + error + '<div class="nbh-modal-actions">' + (transfer.error ? '<button class="nbh-button nbh-button--primary" data-action="dismiss-transfer">Close & keep progress</button>' : '') + '<button class="nbh-button nbh-button--danger" data-action="cancel-transfer">Cancel import</button></div></div></div>';
    }
    function jobWarningItems(job) {
        job = object(job);
        var result = object(job.result);
        var cursor = object(job.cursor);
        var source = array(result.warnings).concat(array(cursor.warnings));
        if (!source.length && result.warning)
            source.push(result.warning);
        var seen = {};
        return source.map(function (item, index) {
            var row = typeof item === 'string' ? { message: item } : object(item);
            var message = String(row.message || row.warning || row.text || row.description || '').trim();
            if (!message)
                return null;
            var key = message.toLowerCase();
            if (seen[key])
                return null;
            seen[key] = true;
            return {
                title: String(row.title || row.label || ('Warning ' + (index + 1))),
                message: message,
                step: String(row.step || row.phase || row.stage || ''),
                target: String(row.file || row.path || row.table || row.target || ''),
                time: String(row.time || row.created_at || row.timestamp || '')
            };
        }).filter(Boolean);
    }
    function jobDisplayStatus(job) {
        return job && job.status === 'completed_with_warnings' && !jobWarningItems(job).length ? 'completed' : String(job && job.status || '');
    }
    function jobProgressMessage(job) {
        var status = jobDisplayStatus(job);
        if (status === 'completed_with_warnings')
            return 'Completed with warnings';
        if (status === 'completed')
            return 'Completed';
        if (status === 'failed')
            return 'Failed';
        if (status === 'cancelled')
            return 'Cancelled';
        return job.message || reportStatusLabel(job.status) || job.status || 'Processing';
    }
    function jobCurrentPhase(job) {
        job = object(job);
        var cursor = object(job.cursor);
        var phase = String(cursor.phase || job.step || job.current_step || '').toLowerCase();
        if (String(job.type || '').toLowerCase() === 'backup' && phase === 'package' && cursor.package_step)
            phase = 'package_' + String(cursor.package_step).toLowerCase();
        return phase || 'init';
    }
    function jobActivityPlan(job) {
        job = object(job);
        var type = String(job.type || 'backup');
        if (type === 'backup') {
            return [
                { title: 'Prepare backup', phases: ['init', 'database'] },
                { title: 'Export database', phases: ['database', 'database_objects'] },
                { title: 'Collect site files', phases: ['files_seed', 'files_discover', 'files_pack'] },
                { title: 'Build archive', phases: ['package', 'package_database', 'package_database_objects', 'package_checksums', 'package_deleted', 'package_metadata', 'package_manifest', 'package_finalize', 'package_encrypt_init', 'package_encrypt', 'package_publish'] },
                { title: 'Verify recovery point', phases: ['verify', 'completed'] }
            ];
        }
        if (type === 'restore' || type === 'chain_restore') {
            var restoreComponents = array(object(job.cursor).components);
            var restoreHasDatabase = !restoreComponents.length || restoreComponents.indexOf('database') !== -1;
            var restoreHasFiles = !restoreComponents.length || ['files', 'uploads', 'plugins', 'themes'].some(function (component) { return restoreComponents.indexOf(component) !== -1; });
            var restoreSteps = [
                { title: 'Validate archive', phases: ['init', 'decrypt', 'read_manifest'] },
                { title: 'Create safety backup', phases: ['safety_backup'] },
                { title: 'Extract backup contents', phases: ['extract'] }
            ];
            if (restoreHasDatabase) restoreSteps.push({ title: 'Restore database', phases: ['import_db_shadow', 'verify_db_shadow', 'search_replace_shadow', 'publish_db', 'prepare_db_objects', 'import_db_objects'] });
            if (restoreHasFiles) restoreSteps.push({ title: 'Restore site files', phases: ['deploy_files', 'delete_files'] });
            restoreSteps.push({ title: 'Finalize & verify site', phases: ['post_restore', 'extension_finalize', 'commit_restore', 'completed'] });
            return restoreSteps;
        }
        if (type === 'remote_import') {
            return [
                { title: 'Prepare remote import', phases: ['init'] },
                { title: 'Download archive', phases: ['download'] },
                { title: 'Verify downloaded archive', phases: ['finalize'] },
                { title: 'Add recovery point', phases: ['done', 'completed'] }
            ];
        }
        if (type === 'migration') {
            return [
                { title: 'Prepare migration', phases: ['init'] },
                { title: 'Transfer archive', phases: ['send', 'transfer'] },
                { title: 'Verify destination', phases: ['verify', 'finalize'] },
                { title: 'Complete migration', phases: ['done', 'completed'] }
            ];
        }
        return [
            { title: 'Prepare operation', phases: ['init', 'queued', 'created'] },
            { title: 'Process data', phases: ['running', 'process', 'processing'] },
            { title: 'Verify result', phases: ['verify', 'verifying'] },
            { title: 'Finalize operation', phases: ['finalize', 'done', 'completed'] }
        ];
    }

    function jobActivityPhaseMatches(stage, phase) {
        phase = String(phase || '');
        if (array(stage.phases).indexOf(phase) !== -1)
            return true;
        return array(stage.prefixes).some(function (prefix) { return phase.indexOf(String(prefix)) === 0; });
    }
    function jobActivityCurrentIndex(job, plan) {
        var phase = jobCurrentPhase(job);
        for (var i = 0; i < plan.length; i++) {
            if (jobActivityPhaseMatches(plan[i], phase))
                return i;
        }
        var progress = Math.max(0, Math.min(99, number(job && job.progress, 0)));
        return Math.min(plan.length - 1, Math.floor((progress / 100) * plan.length));
    }
    function jobActivityStageState(job, index, currentIndex) {
        var status = jobDisplayStatus(job);
        if (status === 'completed' || status === 'completed_with_warnings')
            return 'completed';
        if (index < currentIndex)
            return 'completed';
        if (index > currentIndex)
            return 'queued';
        if (status === 'failed')
            return 'failed';
        if (status === 'cancelled')
            return 'cancelled';
        if (status === 'paused')
            return 'paused';
        if (status === 'retrying' || status === 'retry_scheduled')
            return 'retrying';
        return 'running';
    }
    function jobActivityStateLabel(stateName) {
        var labels = { completed: 'Completed', running: 'Running', queued: 'Queued', failed: 'Failed', cancelled: 'Cancelled', paused: 'Paused', retrying: 'Retrying' };
        return labels[stateName] || humanizeSlug(stateName);
    }
    function jobActivityPhaseLabel(phase) {
        var labels = {
            init: 'Preparing operation', decrypt: 'Decrypting archive', safety_backup: 'Creating safety backup', extract: 'Extracting backup archive', read_manifest: 'Reading archive manifest',
            import_db_shadow: 'Importing database into protected shadow tables', verify_db_shadow: 'Verifying restored database', search_replace_shadow: 'Updating site URLs and paths', publish_db: 'Publishing restored database',
            prepare_db_objects: 'Preparing database objects', import_db_objects: 'Restoring database objects', deploy_files: 'Deploying site files', delete_files: 'Cleaning files not present in recovery point',
            post_restore: 'Running post-restore checks', extension_finalize: 'Finalizing protected application data', commit_restore: 'Committing restore safely',
            database: 'Exporting database', database_objects: 'Exporting database objects', files_seed: 'Preparing file inventory', files_discover: 'Scanning site files', files_pack: 'Packing site files', package: 'Building final archive',
            package_database: 'Writing database payload', package_database_objects: 'Writing database objects', package_checksums: 'Writing checksums', package_deleted: 'Recording deleted files', package_metadata: 'Writing archive metadata', package_manifest: 'Writing archive manifest', package_finalize: 'Finalizing archive', package_encrypt_init: 'Preparing archive encryption', package_encrypt: 'Encrypting archive', package_publish: 'Publishing recovery point',
            download: 'Downloading archive', send: 'Sending archive to destination', verify: 'Verifying operation result', finalize: 'Finalizing operation',
            rollback_files: 'Rolling back changed files', rollback_objects_drop: 'Removing changed database objects', rollback_db: 'Restoring previous database', rollback_objects_restore: 'Restoring database objects', recovery_finalize: 'Finalizing recovery'
        };
        return labels[phase] || humanizeSlug(phase || 'processing');
    }

    function jobActivityDetail(job) {
        job = object(job);
        var cursor = object(job.cursor);
        var phase = jobCurrentPhase(job);
        var parts = [jobActivityPhaseLabel(phase)];
        var totalFiles = number(cursor.total_files, 0);
        var processedFiles = number(cursor.packed_files, 0);
        if (!processedFiles && cursor.file_index !== undefined)
            processedFiles = number(cursor.file_index, 0);
        if (!processedFiles && cursor.scan_entries !== undefined)
            processedFiles = number(cursor.scan_entries, 0);
        if (phase === 'clone_db') {
            var dbTotal = number(cursor.db_table_total, 0);
            var dbIndex = number(cursor.db_table_index, 0);
            if (dbTotal > 0)
                parts.push('Table ' + Math.min(dbIndex + 1, dbTotal) + ' of ' + dbTotal + (cursor.db_current_table ? ': ' + String(cursor.db_current_table) : ''));
            if (number(cursor.db_rows, 0) > 0)
                parts.push(number(cursor.db_rows, 0).toLocaleString() + ' rows copied');
        }
        else if (totalFiles > 0 && processedFiles >= 0)
            parts.push(processedFiles.toLocaleString() + ' of ' + totalFiles.toLocaleString() + ' files');
        else if (number(job.total_bytes, 0) > 0)
            parts.push(formatBytes(job.processed_bytes || 0) + ' of ' + formatBytes(job.total_bytes));
        else if (number(cursor.total_file_bytes, 0) > 0)
            parts.push(formatBytes(cursor.packed_bytes || cursor.offset || 0) + ' of ' + formatBytes(cursor.total_file_bytes));
        var tables = array(cursor.tables);
        if (phase === 'migrate_urls') {
            var urlTableIndex = number(cursor.url_table_idx, 0);
            if (tables.length && urlTableIndex >= 0 && urlTableIndex < tables.length)
                parts.push('Table ' + Math.min(urlTableIndex + 1, tables.length) + ' of ' + tables.length + ': ' + String(tables[urlTableIndex] || 'database table'));
            if (number(cursor.url_rows_processed, 0) > 0)
                parts.push(number(cursor.url_rows_processed, 0).toLocaleString() + ' rows checked');
        } else if (phase !== 'clone_db') {
            var tableIndex = cursor.table_idx !== undefined ? number(cursor.table_idx, 0) : -1;
            if (tables.length && tableIndex >= 0 && tableIndex < tables.length)
                parts.push('Table ' + Math.min(tableIndex + 1, tables.length) + ' of ' + tables.length + ': ' + String(tables[tableIndex] || 'database table'));
            if (number(cursor.db_rows, 0) > 0)
                parts.push(number(cursor.db_rows, 0).toLocaleString() + ' database rows processed');
        }
        return parts.join(' · ');
    }
    function renderJobActivity(job) {
        var plan = jobActivityPlan(job);
        var currentIndex = jobActivityCurrentIndex(job, plan);
        var rows = plan.map(function (stage, index) {
            var stateName = jobActivityStageState(job, index, currentIndex);
            var marker = stateName === 'completed' ? icon('check') : (stateName === 'failed' ? icon('warning') : '');
            return '<div class="nbh-job-activity__step is-' + attr(stateName) + '"><span class="nbh-job-activity__marker">' + marker + '</span><strong>' + esc((index + 1) + '. ' + stage.title) + '</strong><span class="nbh-job-activity__state">' + esc(jobActivityStateLabel(stateName)) + '</span></div>';
        }).join('');
        var displayStatus = jobDisplayStatus(job);
        var terminal = ['completed', 'completed_with_warnings', 'failed', 'cancelled'].indexOf(displayStatus) !== -1;
        var active = !terminal && displayStatus !== 'paused';
        var activityHelp = displayStatus === 'paused' ? 'The job is paused at a safe checkpoint. Resume it when you are ready.' : ((displayStatus === 'retrying' || displayStatus === 'retry_scheduled') ? 'Nobash Backup is waiting for the next safe retry while keeping the last successful checkpoint.' : (active ? 'Nobash Backup is actively processing resumable checkpoints. Progress may pause briefly while a large file or database batch is being completed.' : 'The operation stages recorded for this job are shown below.'));
        return '<section class="nbh-job-activity"><div class="nbh-job-activity__head"><span class="nbh-job-activity__pulse' + (active ? ' is-live' : '') + '"></span><div><strong>Background activity</strong><small>' + esc(activityHelp) + '</small></div></div><div class="nbh-job-activity__steps">' + rows + '</div><div class="nbh-job-activity__live"><span>' + icon('reports') + '</span><strong>' + esc(jobActivityDetail(job)) + '</strong><em>' + esc(active ? 'Live' : jobActivityStateLabel(displayStatus)) + '</em></div></section>';
    }
    function renderTransferActivity(transfer) {
        transfer = object(transfer);
        var progress = Math.max(0, Math.min(100, number(transfer.progress, 0)));
        var hasSession = !!transfer.transfer_id;
        var failed = !!transfer.error;
        var steps = [
            { title: 'Prepare resumable upload', state: hasSession ? 'completed' : 'running' },
            { title: 'Upload archive chunks', state: failed ? 'paused' : (progress >= 100 ? 'completed' : 'running') },
            { title: 'Finalize & verify archive', state: progress >= 100 && !failed ? 'running' : 'queued' }
        ];
        var rows = steps.map(function (stage, index) {
            var marker = stage.state === 'completed' ? icon('check') : '';
            return '<div class="nbh-job-activity__step is-' + stage.state + '"><span class="nbh-job-activity__marker">' + marker + '</span><strong>' + esc((index + 1) + '. ' + stage.title) + '</strong><span class="nbh-job-activity__state">' + esc(jobActivityStateLabel(stage.state)) + '</span></div>';
        }).join('');
        var detail = (failed ? 'Upload paused' : 'Uploading archive') + ' · ' + formatBytes(transfer.offset || 0) + ' of ' + formatBytes(transfer.size || 0);
        return '<section class="nbh-job-activity nbh-job-activity--transfer"><div class="nbh-job-activity__head"><span class="nbh-job-activity__pulse' + (!failed ? ' is-live' : '') + '"></span><div><strong>Background activity</strong><small>Completed chunks are checkpointed, so a large upload can continue safely without appearing frozen.</small></div></div><div class="nbh-job-activity__steps">' + rows + '</div><div class="nbh-job-activity__live"><span>' + icon('upload') + '</span><strong>' + esc(detail) + '</strong><em>' + esc(failed ? 'Paused' : 'Live') + '</em></div></section>';
    }
    function renderJobWarningsModal(job) {
        var warnings = jobWarningItems(job);
        var operation = jobOperationLabel(job);
        var rows = warnings.length ? warnings.map(function (warning, index) {
            var meta = [];
            if (warning.step)
                meta.push('<span><b>Step:</b> ' + esc(warning.step.replace(/_/g, ' ')) + '</span>');
            if (warning.target)
                meta.push('<span><b>Affected:</b> ' + esc(warning.target) + '</span>');
            if (warning.time)
                meta.push('<span><b>Time:</b> ' + esc(formatDate(warning.time)) + '</span>');
            return '<li class="nbh-job-warning-item"><span class="nbh-job-warning-item__icon">' + icon('warning') + '</span><div><strong>' + esc(warning.title || ('Warning ' + (index + 1))) + '</strong><p>' + esc(warning.message) + '</p>' + (meta.length ? '<div class="nbh-job-warning-item__meta">' + meta.join('') + '</div>' : '') + '</div></li>';
        }).join('') : '<div class="nbh-callout is-warning">' + icon('info') + '<div><strong>No detailed warning text was stored</strong><p>The operation completed with a warning status, but this job did not return a detailed warning message. Open Activity & Reports and inspect the job event history for more context.</p></div></div>';
        return '<button class="nbh-modal-close" data-action="close-modal" aria-label="Close">×</button>' +
            '<div class="nbh-job-warnings-head"><span class="nbh-job-icon">' + icon('warning') + '</span><div><span class="nbh-eyebrow">' + esc(operation) + '</span><h2 id="nbh-modal-title">Warnings</h2><p>' + esc(warnings.length ? (warnings.length + ' warning' + (warnings.length === 1 ? '' : 's') + ' recorded for job #' + number(job.id) + '.') : ('Job #' + number(job.id) + ' completed with warnings.')) + '</p></div></div>' +
            (warnings.length ? '<ol class="nbh-job-warning-list">' + rows + '</ol>' : rows) +
            '<div class="nbh-modal-actions"><button class="nbh-button nbh-button--primary" data-action="close-modal">Back to result</button></div>';
    }
    function renderJobOverlay() {
        var job = state.job;
        var displayStatus = jobDisplayStatus(job);
        var progress = Math.max(0, Math.min(100, number(job.progress, 0)));
        var terminal = ['completed', 'completed_with_warnings', 'failed', 'cancelled'].indexOf(displayStatus) !== -1;
        var phaseForBytes = jobCurrentPhase(job);
        var bytes = number(job.total_bytes, 0) > 0 ? '<small class="nbh-progress-bytes">' + esc(formatBytes(job.processed_bytes || 0) + ' of ' + formatBytes(job.total_bytes)) + '</small>' : '';
        var result = object(job.result);
        var cursor = object(job.cursor);
        var failureMessage = jobErrorMessage(job);
        var completed = terminal && job.status.indexOf('completed') === 0 && result.destination_filename ? '<div class="nbh-callout is-safe">' + icon('check') + '<div><strong>Archive received by destination</strong><p>' + esc(result.destination_filename) + '</p></div></div>' : '';
        var warningButton = displayStatus === 'completed_with_warnings' && jobWarningItems(job).length ? '<button class="nbh-button" data-action="job-view-warnings">' + icon('warning') + ' View warnings</button>' : '';
        var operationLabel = jobOperationLabel(job);
        var jobIconName = displayStatus === 'failed' || displayStatus === 'completed_with_warnings' ? 'warning' : (operationLabel === 'Restore' ? 'restore' : (operationLabel === 'Migration' ? 'migration' : 'backup'));
        return '<div class="nbh-job-overlay"><div class="nbh-job-card nbh-job-card--activity" role="dialog" aria-modal="true" aria-labelledby="nbh-job-title"><div class="nbh-job-head"><span class="nbh-job-icon">' + icon(jobIconName) + '</span><div><span class="nbh-eyebrow">' + esc(operationLabel) + '</span><h2 id="nbh-job-title">' + esc(jobTitle(job)) + '</h2></div></div><div class="nbh-progress"><div style="width:' + progress + '%"></div></div><div class="nbh-job-progress"><strong>' + Math.round(progress) + '%</strong><span>' + esc(jobProgressMessage(job)) + '</span></div>' + bytes + renderJobActivity(job) + completed + (failureMessage ? '<div class="nbh-callout is-error">' + icon('warning') + '<div><strong>' + esc(job.type === 'remote_import' ? 'Remote import failed' : (jobOperationLabel(job) + ' failed')) + '</strong><p>' + esc(failureMessage) + '</p></div></div>' : '') + '<div class="nbh-modal-actions">' + (!terminal ? '<button class="nbh-button" data-action="job-pause"' + (job.status === 'paused' ? ' hidden' : '') + '>Pause</button><button class="nbh-button" data-action="job-resume"' + (job.status !== 'paused' ? ' hidden' : '') + '>Resume</button><button class="nbh-button nbh-button--danger" data-action="job-cancel">Cancel</button>' : warningButton + '<button class="nbh-button nbh-button--primary" data-action="job-close">Close</button>') + '</div></div></div>';
    }
    async function confirmDialog(options) {
        return new Promise(function (resolve) {
            pendingConfirmResolve = resolve;
            state.modal = { type: 'confirm', options: object(options) };
            render();
        });
    }
    function closeConfirmDialog(result) {
        var resolve = pendingConfirmResolve;
        pendingConfirmResolve = null;
        state.modal = null;
        render();
        if (resolve)
            resolve(!!result);
    }
    function confirmDetailsRow(detail) {
        detail = Array.isArray(detail) ? { icon: detail[0], label: detail[1], value: detail[2] } : object(detail);
        return '<div class="nbh-confirm-detail"><span class="nbh-confirm-detail__label">' + icon(detail.icon || 'archive') + '<span>' + esc(detail.label || '') + '</span></span><strong>' + esc(detail.value || '—') + '</strong></div>';
    }
    function jobOperationLabel(job) {
        var type = String(object(job).type || 'backup');
        if (type === 'restore') return 'Restore';
        if (type === 'remote_import') return 'Remote import';
        if (type === 'import') return 'Import';
        if (type === 'migration') return 'Migration';
        return 'Backup';
    }
    function jobTitle(job) {
        var status = jobDisplayStatus(job);
        var operation = jobOperationLabel(job);
        if (status === 'completed') return operation + ' completed';
        if (status === 'completed_with_warnings') return operation + ' completed with warnings';
        if (status === 'failed') return operation + ' failed';
        if (status === 'cancelled') return operation + ' cancelled';
        if (status === 'paused') return operation + ' paused';
        return operation + ' in progress';
    }

    function formObject(form) {
        var fd = new FormData(form);
        var out = {};
        fd.forEach(function (value, key) {
            if (out[key] !== undefined)
                out[key] = Array.isArray(out[key]) ? out[key].concat([value]) : [out[key], value];
            else
                out[key] = value;
        });
        form.querySelectorAll('input[type="checkbox"]').forEach(function (input) { if (!input.name)
            return; if (input.name === 'components')
            return; out[input.name] = input.checked; });
        return out;
    }
    function transferStorageKey(file) { return 'nbh-import-transfer:' + [file.name, file.size, file.lastModified].join(':'); }
    async function sha256Blob(blob) {
        if (!window.crypto || !window.crypto.subtle) {
            throw new Error('Secure browser checksum support is unavailable. Open WordPress Admin over HTTPS and try again.');
        }
        var buffer = await blob.arrayBuffer();
        var digest = await window.crypto.subtle.digest('SHA-256', buffer);
        return Array.from(new Uint8Array(digest)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }
    async function uploadArchiveResumable(file) {
        if (!/\.nbh$/i.test(file.name))
            throw new Error('Choose a valid .nbh archive.');
        var key = transferStorageKey(file);
        var session = null;
        try {
            session = JSON.parse(window.localStorage.getItem(key) || 'null');
        }
        catch (e) {
            session = null;
        }
        if (session && session.transfer_id) {
            try {
                var current = await core('/transfers/' + encodeURIComponent(session.transfer_id));
                if (current.status === 'completed' && current.published_filename) {
                    window.localStorage.removeItem(key);
                    return { filename: current.published_filename, status: 'completed', idempotent: true };
                }
                if (Number(current.expected_size) !== Number(file.size) || current.status === 'cancelled' || current.status === 'expired')
                    session = null;
                else
                    session.offset = Number(current.received_size || 0);
            }
            catch (e) {
                session = null;
            }
        }
        if (!session) {
            var created = await core('/transfers', { method: 'POST', body: { filename: file.name, size: file.size } });
            session = { transfer_id: created.transfer_id, chunk_size: created.chunk_size || 16777216, offset: created.offset || 0 };
            window.localStorage.setItem(key, JSON.stringify(session));
        }
        state.transfer = { transfer_id: session.transfer_id, storage_key: key, filename: file.name, size: file.size, offset: session.offset || 0, progress: Math.floor(((session.offset || 0) / Math.max(1, file.size)) * 100) };
        render();
        var offset = Number(session.offset || 0);
        var chunkSize = Math.max(1048576, Math.min(67108864, Number(session.chunk_size || 16777216)));
        while (offset < file.size) {
            if (!state.transfer || state.transfer.transfer_id !== session.transfer_id)
                throw new Error('Import cancelled.');
            var chunk = file.slice(offset, Math.min(file.size, offset + chunkSize));
            var chunkSha = await sha256Blob(chunk);
            var result = await core('/transfers/' + encodeURIComponent(session.transfer_id), { method: 'PUT', raw: true, body: chunk, headers: { 'Content-Type': 'application/octet-stream', 'X-NBH-Offset': String(offset), 'X-NBH-Chunk-SHA256': chunkSha } });
            offset = Number(result.offset || (offset + chunk.size));
            session.offset = offset;
            window.localStorage.setItem(key, JSON.stringify(session));
            if (!state.transfer || state.transfer.transfer_id !== session.transfer_id)
                throw new Error('Import cancelled.');
            state.transfer.offset = offset;
            state.transfer.progress = Math.floor((offset / Math.max(1, file.size)) * 100);
            render();
        }
        var finalized = await core('/transfers/' + encodeURIComponent(session.transfer_id) + '/finalize', { method: 'POST', body: {} });
        window.localStorage.removeItem(key);
        state.transfer = null;
        render();
        return finalized;
    }
    async function cancelActiveTransfer() {
        var transfer = state.transfer;
        if (!transfer)
            return;
        state.transfer = null;
        render();
        try {
            await core('/transfers/' + encodeURIComponent(transfer.transfer_id), { method: 'DELETE' });
        }
        catch (e) { }
        if (transfer.storage_key)
            window.localStorage.removeItem(transfer.storage_key);
        notify('info', 'Import cancelled.');
    }
    async function startJob(payload) {
        state.busy = true;
        try {
            var job = await core('/jobs', { method: 'POST', body: payload });
            state.job = job;
            state.busy = false;
            render();
            await runJob(job);
        }
        catch (e) {
            state.busy = false;
            var preflightDetail = '';
            if (e && e.data && Array.isArray(e.data.checks)) {
                var failedChecks = e.data.checks.filter(function (c) { return c && c.status === 'fail' && c.message; });
                preflightDetail = failedChecks.map(function (c) { return c.message; }).join(' ');
            }
            notify('error', (e.message || 'Could not start the operation.') + (preflightDetail ? ' ' + preflightDetail : ''));
        }
    }

    async function runJob(job) {
        var terminal = ['completed', 'completed_with_warnings', 'failed', 'cancelled'];
        var transportFailures = 0;
        while (job && terminal.indexOf(job.status) === -1) {
            if (job.status === 'paused') return;
            var settings = object(state.data && state.data.settings);
            var slicePause = job && job.type === 'restore' ? 0 : Math.max(0, Math.min(5000, Number(settings.slice_pause_ms == null ? 100 : settings.slice_pause_ms) || 0));
            await wait(job.status === 'retrying' ? 1200 : (transportFailures ? Math.min(2500, 500 + transportFailures * 250) : slicePause));
            try {
                job = await core('/jobs/' + encodeURIComponent(job.id) + '/step', { method: 'POST', body: {} });
                transportFailures = 0;
                state.job = job;
                render();
            } catch (e) {
                /* A long-running REST response can be interrupted while PHP has
                 * already persisted progress. Re-read durable server state and
                 * continue from the last authorized checkpoint. */
                transportFailures++;
                var refreshed = null;
                try { refreshed = await core('/jobs/' + encodeURIComponent(job.id)); } catch (refreshError) { }
                if (refreshed && refreshed.id) {
                    job = refreshed;
                    if (terminal.indexOf(job.status) === -1 && job.status !== 'paused') job.message = 'Connection interrupted — continuing from the last saved checkpoint…';
                    state.job = job; render(); continue;
                }
                var status = Number(e && e.status || 0);
                var code = String(e && e.code || '');
                var message = String(e && e.message || '').toLowerCase();
                var transient = !status || status === 408 || status === 429 || status >= 500 || code === 'network_error' || message.indexOf('timed out') !== -1 || message.indexOf('could not be reached') !== -1 || message.indexOf('failed to fetch') !== -1;
                state.job = Object.assign({}, job || state.job, { message: transient ? 'Connection interrupted — reconnecting automatically from the last saved checkpoint…' : 'The dashboard could not confirm the latest server state. Retrying safely…' });
                render();
                if (!transient && transportFailures >= 5) return;
            }
        }
        await loadData();
        if (job && job.type === 'remote_import' && ['completed', 'completed_with_warnings'].indexOf(job.status) !== -1) {
            var remoteFilename = object(job.result).filename || '';
            var remoteBackup = array(state.data.backups).find(function (item) { return item.filename === remoteFilename; }) || { filename: remoteFilename, size: object(job.result).size || 0, source: 'transfer' };
            state.job = null;
            state.modal = { type: 'import-success', filename: remoteFilename, size: object(job.result).size || 0, backup: remoteBackup };
            render();
        }
    }

    function wait(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }
    function captureSettingsDraft() {
        var form = mount.querySelector('#nbh-settings-form');
        if (!form)
            return;
        var current = formObject(form);
        Object.keys(current).forEach(function (key) { state.settingsDraft[key] = current[key]; });
    }
    function markSettingsDirty() {
        state.settingsDirty = true;
        captureSettingsDraft();
        var footer = mount.querySelector('.nbh-sticky-actions');
        if (footer) {
            footer.classList.add('is-dirty');
            var strong = footer.querySelector('strong');
            var small = footer.querySelector('small');
            var save = footer.querySelector('button[type="submit"]');
            if (strong)
                strong.textContent = 'Unsaved settings';
            if (small)
                small.textContent = 'Your changes are preserved while switching sections.';
            if (save)
                save.disabled = false;
        }
    }
    function navigate(page) {
        if (state.page === 'settings') captureSettingsDraft();
        state.page = normalizePage(page);
        state.navOpen = false;
        var url = cfg.pages && cfg.pages[state.page] ? cfg.pages[state.page] : null;
        if (url) window.history.pushState({ page: state.page }, '', url);
        render();
        ensureFullDataLoaded();
        var main = document.getElementById('nbh-main');
        if (main) main.focus({ preventScroll: true });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function openHelpDocumentation(topic) {
        if (state.page === 'settings') captureSettingsDraft();
        state.page = 'help';
        state.helpTopic = normalizeHelpTopic(topic);
        state.navOpen = false;
        state.modal = null;
        var base = cfg.pages && cfg.pages.help ? cfg.pages.help : window.location.href;
        try {
            var url = new URL(base, window.location.href);
            url.searchParams.set('view', 'help');
            url.searchParams.set('topic', state.helpTopic);
            window.history.pushState({ page: 'help', topic: state.helpTopic }, '', url.toString());
        } catch (e) { /* URL update is optional. */ }
        render();
        var main = document.getElementById('nbh-main');
        if (main) main.focus({ preventScroll: true });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function handleSubmit(event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement))
            return;
        // A control named id shadows the form.id property in browsers.
        var formId = form.getAttribute('id') || '';
        if (formId.indexOf('nbh-') !== 0)
            return;
        event.preventDefault();
        try {
            if (formId === 'nbh-support-form') {
                var support = formObject(form);
                support.name = String(support.name || '').trim();
                support.email = String(support.email || '').trim();
                support.subject = String(support.subject || '').trim();
                support.message = String(support.message || '').trim();
                if (support.name.length < 2)
                    throw new Error('Enter your name.');
                if (!/^\S+@\S+\.\S+$/.test(support.email))
                    throw new Error('Enter a valid reply email address.');
                if (support.subject.length < 5)
                    throw new Error('Enter a more descriptive subject.');
                if (support.message.length < 20)
                    throw new Error('Enter at least 20 characters in your message.');
                var submitButton = form.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = 'Sending…';
                }
                try {
                    var supportResult = await core('/support', { method: 'POST', body: support });
                    notify('success', supportResult.message || 'Support request sent.');
                    form.querySelector('[name="subject"]').value = '';
                    form.querySelector('[name="message"]').value = '';
                }
                finally {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Send support request';
                    }
                }
            }
            if (formId === 'nbh-backup-form') {
                var backup = formObject(form);
                if (backup.password_protected && String(backup.password || '').length < 10)
                    throw new Error('Use an archive password with at least 10 characters.');
                if (backup.password_protected && String(backup.password || '') !== String(backup.confirm_password || ''))
                    throw new Error('The archive passwords do not match.');
                var selectedProfile = array(state.data.profiles).find(function (profile) { return String(profile.id) === String(backup.profile_id); });
                var legacyProfile = String(backup.profile_id || '').indexOf('legacy:') === 0 ? String(backup.profile_id).replace('legacy:', '') : '';
                await startJob({
                    type: 'backup',
                    profile_id: selectedProfile ? Number(selectedProfile.id) : 0,
                    profile: selectedProfile ? selectedProfile.content_type : (legacyProfile || 'full'),
                    scope: backup.scope,
                    backup_mode: backup.backup_mode || (selectedProfile && selectedProfile.backup_mode) || 'full',
                    chain_id: backup.chain_id || '',
                    password_protected: !!backup.password_protected,
                    password: backup.password || ''
                });
            }
            if (formId === 'nbh-restore-form') {
                var restore = formObject(form);
                if (!restore.filename)
                    throw new Error('Choose a restore point.');
                var components = Array.from(form.querySelectorAll('input[name="components"]:checked')).map(function (i) { return i.value; });
                if (!components.length)
                    throw new Error('Choose at least one available backup component to restore.');
                var plan = await core('/restore-plan', { method: 'POST', body: { filename: restore.filename, components: components } });
                if (plan.plan && array(plan.plan.components).length)
                    components = array(plan.plan.components);
                if (!plan.can_proceed)
                    throw new Error('Restore safety checks failed. Review the archive and server readiness.');
                if (plan.password_required && !restore.password) {
                    state.restorePasswordRequiredFor = restore.filename;
                    var selectedRecord = array(state.data.backups).find(function (item) { return item.filename === restore.filename; });
                    if (selectedRecord)
                        selectedRecord.password_required = true;
                    render();
                    window.setTimeout(function () {
                        var passwordInput = document.getElementById('nbh-restore-password');
                        if (passwordInput)
                            passwordInput.focus({ preventScroll: true });
                    }, 0);
                    throw new Error('Enter the archive password before restoring.');
                }
                if (!(await confirmDialog({ eyebrow: 'Restore confirmation', title: 'Restore this backup?', message: restore.safety_backup ? 'An extra local safety backup and automatic rollback will protect the current site before publication starts.' : 'Fast restore will use the shadow database, verified archive checks and automatic per-file rollback without creating an extra full backup first.', confirmLabel: 'Start restore', confirmIcon: 'restore', intent: 'warning', details: [{ icon: 'archive', label: 'Backup file', value: restore.filename }, { icon: 'restore', label: 'Components', value: components.join(', ') || 'Database, files' }], note: restore.safety_backup ? 'Extra safety backup is enabled, so restore will take longer.' : 'Fast restore is enabled. Existing files and database changes are still protected by the automatic rollback workflow.' })))
                    return;
                await startJob({ type: 'restore', filename: restore.filename, restore_token: plan.restore_token, components: components, search: restore.search || '', replace: restore.replace || '', skip_safety_backup: !restore.safety_backup, target_home: restore.target_home || '', target_siteurl: restore.target_siteurl || '', target_table_prefix: restore.target_table_prefix || '', password: restore.password || '' });
            }
            if (formId === 'nbh-import-file-form') {
                var fileInput = form.querySelector('input[type=file]');
                var file = state.importLocalFile || (fileInput && fileInput.files && fileInput.files[0]);
                if (!file)
                    throw new Error('Choose an .nbh archive.');
                var imported = await uploadArchiveResumable(file);
                state.importLocalFile = null;
                await loadData();
                var importedBackup = array(state.data.backups).find(function (item) { return item.filename === imported.filename; }) || { filename: imported.filename, size: imported.size, original_name: file.name, source: 'transfer' };
                state.modal = { type: 'import-success', filename: imported.filename, size: imported.size, backup: importedBackup };
                render();
            }
            if (formId === 'nbh-import-url-form') {
                var remote = validateRemoteImport(formObject(form));
                state.modal = { type: 'import-url', values: remote };
                var remoteJob = await core('/import-url', { method: 'POST', body: remote });
                state.modal = null;
                state.job = remoteJob;
                render();
                await runJob(remoteJob);
            }
            
            
            if (formId === 'nbh-settings-form') {
                await saveSettings();
            }
            if (formId === 'nbh-basic-schedule-form') {
                var basicSchedule = formObject(form);
                basicSchedule.enabled = !!basicSchedule.enabled;
                var scheduleResult = await core('/settings', { method: 'POST', body: { schedules: basicSchedule } });
                state.data.basicSchedule = object(scheduleResult.schedules);
                state.data.schedules = array(scheduleResult.schedule_status);
                notify('success', basicSchedule.enabled ? 'Basic automatic backup schedule saved.' : 'Basic automatic backups disabled.');
            }
            
            
            
            
            
            
            
            
            
            
            if (formId === 'nbh-profile-form') {
                var profile = formObject(form);
                profile.id = number(profile.id);
                profile.include_paths = splitLines(profile.include_paths);
                profile.include_tables = splitLines(profile.include_tables);
                profile.exclusions = splitLines(profile.exclusions);
                await core('/profiles', { method: 'POST', body: profile });
                state.modal = null;
                notify('success', 'Backup profile saved.');
                await loadData();
            }
            if (formId === 'nbh-schedule-form') {
                var schedule = formObject(form);
                schedule.profile_id = number(schedule.profile_id);
                schedule.enabled = schedule.enabled !== '0';
                await core('/schedules', { method: 'POST', body: schedule });
                notify('success', 'Automatic backup schedule saved.');
                state.modal = null;
                await loadData();
            }
            
            
        }
        catch (e) {
            if (formId === 'nbh-import-file-form' && state.transfer) {
                state.transfer.error = friendlyImportError(e, 'local');
                render();
            }
            else if (formId === 'nbh-import-file-form')
                notify('error', friendlyImportError(e, 'local'));
            else if (formId === 'nbh-import-url-form') {
                var preserved = formObject(form);
                state.modal = { type: 'import-url', values: preserved, error: friendlyImportError(e, 'url'), errorTitle: 'Remote import could not start' };
                render();
            }
            else
                notify('error', e.message || 'The action could not be completed.');
        }
    }


    function splitLines(value) { return String(value || '').split(/\r?\n|,/).map(function (v) { return v.trim(); }).filter(Boolean); }

    async function handleClick(event) {
        var button = event.target.closest('[data-action]');
        if (!button)
            return;
        var action = button.getAttribute('data-action');
        if (action === 'navigate')
            navigate(button.getAttribute('data-page'));
        if (action === 'open-help-doc')
            openHelpDocumentation(button.getAttribute('data-topic'));
        
        
        if (action === 'help-toggle-topic') {
            event.preventDefault();
            var helpTopic = normalizeHelpTopic(button.getAttribute('data-topic'));
            state.helpTopic = state.helpTopic === helpTopic ? 'docs' : helpTopic;
            try {
                var helpUrl = new URL(window.location.href);
                helpUrl.searchParams.set('view', 'help');
                helpUrl.searchParams.set('topic', state.helpTopic);
                window.history.replaceState({ page: 'help', topic: state.helpTopic }, '', helpUrl.toString());
            }
            catch (e) { /* URL update is optional. */ }
            render();
            if (state.helpTopic !== 'docs')
                setTimeout(function () { var opened = document.getElementById('nbh-help-topic-' + state.helpTopic); if (opened)
                    opened.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 30);
            return;
        }
        if (action === 'toggle-nav') {
            state.navOpen = !state.navOpen;
            render();
        }
        if (action === 'close-nav') {
            state.navOpen = false;
            render();
        }
        if (action === 'dismiss-onboarding') {
            state.onboardingDismissed = true;
            writeStoredFlag('onboarding-dismissed', true);
            render();
        }
        if (action === 'retry-load')
            await loadData();
        if (action === 'dismiss-notice') {
            state.notice = null;
            render();
        }
        if (action === 'open-export-help') {
            state.modal = { type: 'export-help' };
            render();
        }
        if (action === 'toggle-export-content') {
            event.preventDefault();
            toggleExportContentMenu(button);
            return;
        }
        if (action === 'select-export-content') {
            event.preventDefault();
            selectExportContentOption(button);
            return;
        }
        if (action === 'select-export-destination') {
            var exportForm = target.closest('#nbh-backup-form');
            if (!exportForm) return;
            var destinationTypeInput = exportForm.querySelector('[name="destination_type"]');
            if (destinationTypeInput) destinationTypeInput.value = 'local';
            updateExportSummary();
            return;
        }
        
        
        if (action === 'toggle-export-advanced') {
            var advanced = mount.querySelector('#nbh-export-advanced');
            if (advanced) {
                advanced.hidden = !advanced.hidden;
                button.setAttribute('aria-expanded', String(!advanced.hidden));
                button.classList.toggle('is-open', !advanced.hidden);
            }
        }
        if (action === 'toggle-export-settings') {
            var settingsPanel = mount.querySelector('#nbh-export-processing-settings');
            if (settingsPanel) {
                settingsPanel.hidden = !settingsPanel.hidden;
                button.setAttribute('aria-expanded', String(!settingsPanel.hidden));
                button.classList.toggle('is-open', !settingsPanel.hidden);
            }
        }
        
        if (action === 'toggle-password-visibility') {
            var passwordInput = document.getElementById(button.getAttribute('data-target'));
            if (passwordInput) {
                var reveal = passwordInput.type === 'password';
                passwordInput.type = reveal ? 'text' : 'password';
                button.setAttribute('aria-pressed', String(reveal));
                button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
                var toggleText = button.querySelector('span');
                if (toggleText)
                    toggleText.textContent = reveal ? 'Hide' : 'Show';
                var toggleIcon = button.querySelector('svg');
                if (toggleIcon)
                    toggleIcon.outerHTML = icon(reveal ? 'eye-off' : 'eye');
                passwordInput.focus({ preventScroll: true });
            }
        }
        if (action === 'close-modal') {
            if (button.classList.contains('nbh-modal-backdrop') && event.target !== button)
                return;
            if (state.modal && state.modal.type === 'confirm') {
                closeConfirmDialog(false);
            }
            else {
                state.modal = null;
                render();
            }
        }
        if (action === 'confirm-modal') {
            closeConfirmDialog(true);
        }
        if (action === 'settings-tab') {
            captureSettingsDraft();
            state.settingsTab = button.getAttribute('data-tab');
            render();
        }
        if (action === 'settings-add-exclusion')
            addSettingsExclusion(button.getAttribute('data-target'), button.getAttribute('data-value'));
        if (action === 'settings-export')
            exportSettingsConfig();
        if (action === 'settings-import-trigger')
            promptSettingsImport();
        if (action === 'settings-restore-defaults')
            await restoreRecommendedSettings();
        if (action === 'clear-webhook-secret')
            await clearWebhookSecret();
        if (action === 'refresh')
            await loadData();
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        if (action === 'open-schedule-editor') { var scheduleId = button.getAttribute('data-id'); state.modal = { type: 'schedule-editor', schedule: scheduleId ? array(state.data.profileSchedules).find(function(item) { return String(item.id) === scheduleId; }) : null }; render(); return; }
        if (action === 'import-source-tab') { state.importTab = button.getAttribute('data-source') === 'url' ? 'url' : 'file'; render(); return; }
        if (action === 'backup-filter') { state.backupFilter = button.getAttribute('data-filter'); state.backupsPage = 1; render(); return; }
        if (action === 'backups-page') {
            state.backupsPage = Math.max(1, number(button.getAttribute('data-page'), 1));
            state.openBackupMenu = '';
            render();
        }
        if (action === 'toggle-backup-menu') {
            event.preventDefault();
            var menuFile = button.getAttribute('data-file') || '';
            state.openBackupMenu = state.openBackupMenu === menuFile ? '' : menuFile;
            render();
            return;
        }
        if (action === 'archive-details')
            await openArchiveDetails(button.getAttribute('data-file'));
        if (action === 'open-import-url-modal') {
            state.modal = { type: 'import-url' };
            render();
        }
        
        
        
        
        
        
        
        
        
        
        if (action === 'import-success-restore') {
            var importRestoreFile = button.getAttribute('data-file') || '';
            state.modal = null;
            state.importLocalFile = null;
            state.selectedRestore = importRestoreFile;
            navigate('restore');
        }
        if (action === 'import-success-again') {
            state.modal = null;
            state.importLocalFile = null;
            if (state.page !== 'import')
                navigate('import');
            else
                render();
            window.setTimeout(function () {
                var importInput = mount.querySelector('#nbh-import-file-form input[type="file"]');
                if (importInput) importInput.focus({ preventScroll: true });
            }, 0);
        }
        if (action === 'import-success-remove') {
            var importRemoveFile = button.getAttribute('data-file') || '';
            state.modal = null;
            render();
            if (importRemoveFile)
                await deleteBackup(importRemoveFile);
            state.importLocalFile = null;
            if (state.page !== 'import') navigate('import'); else render();
        }
        if (action === 'restore-file') {
            state.modal = null;
            state.selectedRestore = button.getAttribute('data-file');
            navigate('restore');
        }
        if (action === 'download-backup')
            await downloadBackup(button.getAttribute('data-file'));
        if (action === 'delete-backup')
            await deleteBackup(button.getAttribute('data-file'));
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        if (action === 'add-profile') {
            state.modal = { type: 'profile', profile: null };
            render();
        }
        if (action === 'edit-profile')
            editProfile(number(button.getAttribute('data-id')));
        if (action === 'delete-profile')
            await deleteProfile(number(button.getAttribute('data-id')));
        if (action === 'delete-schedule')
            await deleteSchedule(number(button.getAttribute('data-id')));
        if (action === 'run-schedule')
            await runSchedule(number(button.getAttribute('data-id')));
        if (action === 'retention-preview')
            await retentionAction('preview');
        if (action === 'retention-run')
            await retentionAction('run');
        if (action === 'report-toggle-chart') {
            state.reportSections = object(state.reportSections);
            state.reportSections.chart = state.reportSections.chart === false;
            render();
            return;
        }
        if (action === 'report-toggle-section') {
            var sectionKey = button.getAttribute('data-section');
            if (['findings', 'audit'].indexOf(sectionKey) !== -1) {
                state.reportSections = object(state.reportSections);
                var expanded = button.getAttribute('aria-expanded') !== 'true';
                state.reportSections[sectionKey] = expanded;
                button.setAttribute('aria-expanded', String(expanded));
                var reportSection = document.getElementById('nbh-report-section-' + sectionKey);
                if (reportSection) reportSection.hidden = !expanded;
            }
        }
        if (action === 'report-apply-filters') {
            var reportFilterForm = mount.querySelector('#nbh-report-filters');
            if (reportFilterForm) {
                var reportFilterData = formObject(reportFilterForm);
                state.reportFilters = { query: reportFilterData.query || '', type: reportFilterData.type || 'all', status: reportFilterData.status || 'all' };
                state.reportPagination.jobs = 1;
                render();
            }
        }
        if (action === 'report-clear-jobs') {
            await clearJobActivity();
            return;
        }
        if (action === 'report-clear-filters') {
            clearReportFilters();
            return;
        }
        if (action === 'report-jobs-page') {
            state.reportPagination.jobs = Math.max(1, number(button.getAttribute('data-page'), 1));
            render();
        }
        if (action === 'report-audit-page') {
            state.reportPagination.audit = Math.max(1, number(button.getAttribute('data-page'), 1));
            render();
        }
        if (action === 'report-job-details')
            await openReportJobDetails(number(button.getAttribute('data-id')));
        if (action === 'report-job-cancel') {
            await cancelReportJob(number(button.getAttribute('data-id')));
            return;
        }
        if (action === 'report-job-remove') {
            await removeReportJob(number(button.getAttribute('data-id')));
            return;
        }
        if (action === 'report-export-csv')
            exportActivityReport('csv');
        if (action === 'report-export-json')
            exportActivityReport('json');
        if (action === 'report-export-audit')
            exportAuditReport();
        if (action === 'report-clear-audit') {
            await clearAuditTrail();
            return;
        }
        if (action === 'test-notifications')
            await testNotifications();
        if (action === 'chain-verify')
            await chainAction('verify');
        if (action === 'chain-repair')
            await chainAction('repair');
        
        
        if (action === 'reset-data')
            await resetData();
        
        if (action === 'job-pause')
            await jobControl('pause');
        if (action === 'job-resume')
            await jobControl('resume');
        if (action === 'job-cancel')
            await jobControl('cancel');
        if (action === 'job-view-warnings') {
            state.modal = { type: 'job-warnings' };
            render();
        }
        if (action === 'resume-restore-recovery')
            await resumeRestoreRecovery(number(button.getAttribute('data-id')));
        if (action === 'reset-restore-recovery')
            await resetRestoreRecovery(number(button.getAttribute('data-id')));
        if (action === 'delete-restore-recovery')
            await deleteRestoreRecovery(number(button.getAttribute('data-id')));
        if (action === 'dismiss-transfer') {
            state.transfer = null;
            render();
        }
        if (action === 'cancel-transfer')
            await cancelActiveTransfer();
        if (action === 'job-close') {
            state.job = null;
            render();
            await loadData();
        }
    }


    async function openArchiveDetails(filename) {
        try {
            var details = await core('/backups/' + encodeURIComponent(filename) + '/details');
            state.modal = { type: 'archive-details', details: details };
            render();
        }
        catch (e) {
            notify('error', e.message);
        }
    }

    async function downloadBackup(filename) {
        try {
            if (!cfg.downloadUrl || !cfg.downloadNonce) throw new Error('Reload this page before downloading the backup.');
            // Let the browser stream to disk instead of waiting for a GB-sized Blob.
            var url = new URL(cfg.downloadUrl, window.location.href);
            url.searchParams.set('action', 'nobash_backup_download');
            url.searchParams.set('filename', filename);
            url.searchParams.set('_wpnonce', cfg.downloadNonce);
            var link = document.createElement('a');
            link.href = url.toString();
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
        }
        catch (e) {
            notify('error', e.message);
        }
    }
    async function deleteBackup(filename) {
        var backupRecord = array(state.data.backups).find(function (item) { return item.filename === filename; }) || {};
        if (!(await confirmDialog({ eyebrow: 'Delete backup', title: 'Delete backup?', message: 'You are about to permanently remove this backup from local storage. This action cannot be undone.', confirmLabel: 'Delete backup', confirmIcon: 'trash', intent: 'danger', details: [{ icon: 'archive', label: 'Filename', value: filename }, { icon: 'backup', label: 'Size', value: backupRecord.size_human || formatBytes(backupRecord.size || 0) }, { icon: 'schedule', label: 'Created', value: formatDate(backupRecord.created_at || backupRecord.created || backupRecord.timestamp || '') }], note: 'Related restore points and settings will not be changed.' })))
            return;
        try {
            await core('/backups/' + encodeURIComponent(filename), { method: 'DELETE' });
            notify('success', 'Backup deleted.');
            await loadData();
        }
        catch (e) {
            notify('error', e.message);
        }
    }


    async function resumeRestoreRecovery(id) {
        try {
            var job = await core('/restore-recovery/' + encodeURIComponent(id) + '/resume', { method: 'POST', body: {} });
            state.job = job;
            notify('success', 'Restore recovery resumed or cleanup finalized.');
            render();
            await runJob(job);
        }
        catch (e) {
            notify('error', e.message || 'Restore recovery could not be resumed.');
        }
    }
    async function resetRestoreRecovery(id) {
        if (!(await confirmDialog({ eyebrow: 'Reset failed recovery', title: 'Reset this failed recovery state?', message: 'This clears the failed recovery marker so you can start a fresh restore. The failed job record is kept for review.', confirmLabel: 'Reset job', confirmIcon: 'refresh', intent: 'warning', details: [{ icon: 'restore', label: 'Recovery job', value: 'Job #' + String(id || '') }], note: 'Use Delete job only when you also want to remove the failed recovery record.' })))
            return;
        try {
            await core('/restore-recovery/' + id + '/reset', { method: 'POST', body: {} });
            notify('success', 'Failed recovery state cleared.');
            if (state.job && Number(state.job.id) === Number(id))
                state.job = null;
            await loadData();
        }
        catch (e) {
            notify('error', e.message || 'Could not reset the failed recovery state.');
        }
    }
    async function deleteRestoreRecovery(id) {
        if (!(await confirmDialog({ eyebrow: 'Delete failed recovery job', title: 'Delete this failed restore job?', message: 'This removes the failed restore job record and its event history, then clears the recovery marker so you can start fresh.', confirmLabel: 'Delete failed job', confirmIcon: 'trash', intent: 'danger', details: [{ icon: 'restore', label: 'Recovery job', value: 'Job #' + String(id || '') }], note: 'Only failed or cancelled recovery jobs can be deleted.' })))
            return;
        try {
            await core('/restore-recovery/' + id + '/delete', { method: 'DELETE' });
            notify('success', 'Failed restore job deleted.');
            if (state.job && Number(state.job.id) === Number(id))
                state.job = null;
            await loadData();
        }
        catch (e) {
            notify('error', e.message || 'Could not delete the failed recovery job.');
        }
    }


    async function chainAction(action) {
        var field = document.querySelector('#nbh-chain-form input[name="chain_id"]');
        var chainId = field ? field.value.trim() : '';
        if (!chainId)
            return notify('error', 'Enter an incremental chain ID.');
        if (action === 'repair' && !(await confirmDialog({ eyebrow: 'Repair chain metadata', title: 'Repair this incremental chain?', message: 'Provable metadata will be repaired and damaged chains will be marked fail-closed when necessary.', confirmLabel: 'Repair chain', confirmIcon: 'warning', intent: 'warning' })))
            return;
        try {
            state.data.chainResult = await core('/chains/' + encodeURIComponent(chainId) + '/' + action, { method: 'POST', body: {} });
            notify(state.data.chainResult.healthy === false ? 'error' : 'success', action === 'repair' ? 'Chain repair assessment completed.' : 'Chain verification completed.');
        }
        catch (e) {
            notify('error', e.message);
        }
    }
    function editProfile(id) {
        var profile = state.data.profiles.find(function (p) { return Number(p.id) === Number(id); });
        state.modal = { type: 'profile', profile: profile || null };
        render();
    }
    async function deleteProfile(id) {
        var profile = state.data.profiles.find(function (p) { return Number(p.id) === Number(id); }) || {};
        if (!(await confirmDialog({ eyebrow: 'Delete profile', title: 'Delete this backup profile?', message: 'The reusable backup profile will be removed. Existing backups remain available.', confirmLabel: 'Delete profile', confirmIcon: 'trash', intent: 'danger', details: [{ icon: 'backup', label: 'Profile', value: profile.name || ('Profile #' + id) }], note: 'Schedules using this profile should be reviewed after deletion.' })))
            return;
        try {
            await core('/profiles/' + id, { method: 'DELETE' });
            notify('success', 'Profile deleted.');
            await loadData();
        }
        catch (e) {
            notify('error', e.message);
        }
    }
    async function deleteSchedule(id) {
        var schedule = array(state.data.schedules).find(function (s) { return Number(s.id) === Number(id); }) || {};
        if (!(await confirmDialog({ eyebrow: 'Delete schedule', title: 'Delete this automatic schedule?', message: 'The selected automatic backup schedule will be removed.', confirmLabel: 'Delete schedule', confirmIcon: 'trash', intent: 'danger', details: [{ icon: 'schedule', label: 'Schedule', value: schedule.name || schedule.frequency_label || ('Schedule #' + id) }], note: 'Existing backups and backup profiles are preserved.' })))
            return;
        try {
            await core('/schedules/' + id, { method: 'DELETE' });
            notify('success', 'Schedule deleted.');
            await loadData();
        }
        catch (e) {
            notify('error', e.message);
        }
    }
    async function runSchedule(id) {
        try {
            var job = await core('/schedules/' + id + '/run', { method: 'POST', body: {} });
            state.job = job;
            render();
            await runJob(job);
        }
        catch (e) {
            notify('error', e.message);
        }
    }
    function normalizeSettingsForSave() {
        captureSettingsDraft();
        var defaults = { retention_count: 10, retention_days: 30, retention_min_free_mb: 1024, temporary_retention_hours: 48, worker_memory_mb: 192, job_slice_seconds: 25, slice_pause_ms: 50, default_profile: 'full', default_storage_provider: 'local', missed_schedule_action: 'run_next', resource_mode: 'balanced', exclude_paths: [], exclude_tables: [] };
        var settings = Object.assign({}, defaults, object(state.data.settings), object(state.settingsDraft));
        if (settings.exclude_paths !== undefined)
            settings.exclude_paths = Array.isArray(settings.exclude_paths) ? settings.exclude_paths : splitLines(settings.exclude_paths);
        if (settings.exclude_tables !== undefined)
            settings.exclude_tables = Array.isArray(settings.exclude_tables) ? settings.exclude_tables : splitLines(settings.exclude_tables);
        delete settings.webhook_secret_set;
        return settings;
    }
    function validateSettings(settings) {
        function integer(name, label, min, max) {
            var value = Number(settings[name]);
            if (!Number.isInteger(value) || value < min || value > max)
                throw new Error(label + ' must be between ' + min + ' and ' + max + '.');
        }
        integer('retention_count', 'Keep latest backups', 1, 500);
        integer('retention_days', 'Maximum backup age', 1, 3650);
        integer('retention_min_free_mb', 'Minimum free space', 0, 1048576);
        integer('temporary_retention_hours', 'Temporary recovery files', 6, 720);
        integer('worker_memory_mb', 'Worker memory', 32, 2048);
        integer('job_slice_seconds', 'Job slice', 5, 120);
        integer('slice_pause_ms', 'Pause between slices', 0, 5000);
        var email = String(settings.notify_email || '').trim();
        if (email && !/^\S+@\S+\.\S+$/.test(email))
            throw new Error('Enter a valid notification email address.');
        var webhook = String(settings.webhook_url || '').trim();
        if (webhook && !/^https:\/\//i.test(webhook))
            throw new Error('Webhook URL must use HTTPS.');
        array(settings.exclude_paths).forEach(function (path) {
            path = String(path || '').trim();
            if (!path)
                return;
            if (/^(?:\/|[a-z]:[\\/]|\\\\)/i.test(path) || path.split(/[\\/]+/).indexOf('..') !== -1)
                throw new Error('Excluded paths must be relative to WordPress and cannot contain "..": ' + path);
        });
        return settings;
    }
    async function saveSettings(options) {
        options = object(options);
        var settings = validateSettings(normalizeSettingsForSave());
        var saveButton = mount.querySelector('#nbh-settings-form button[type="submit"]');
        var original = saveButton ? saveButton.innerHTML : '';
        if (saveButton) {
            saveButton.disabled = true;
            saveButton.innerHTML = '<span class="nbh-spinner"></span><span>Saving…</span>';
        }
        try {
            var updated = await core('/settings', { method: 'POST', body: { settings: settings } });
            state.data.settings = object(updated.settings);
            state.data.encryption = object(updated.encryption);
            state.settingsDraft = JSON.parse(JSON.stringify(state.data.settings));
            state.settingsDirty = false;
            if (!options.silent)
                notify('success', options.message || 'Settings saved.');
            if (!options.noRender)
                render();
            return updated;
        }
        finally {
            if (saveButton && saveButton.isConnected) {
                saveButton.innerHTML = original || '<span>Save settings</span>';
                saveButton.disabled = !state.settingsDirty;
            }
        }
    }
    function applyResourcePreset(mode) {
        var presets = {
            adaptive: { job_slice_seconds: 25, slice_pause_ms: 50 },
            low: { job_slice_seconds: 12, slice_pause_ms: 500 },
            balanced: { job_slice_seconds: 25, slice_pause_ms: 50 },
            high: { job_slice_seconds: 45, slice_pause_ms: 0 }
        };
        var preset = presets[mode];
        if (!preset)
            return;
        Object.keys(preset).forEach(function (name) {
            var input = mount.querySelector('#nbh-settings-form [name="' + name + '"]');
            if (input)
                input.value = preset[name];
            state.settingsDraft[name] = preset[name];
        });
        state.settingsDraft.resource_mode = mode;
        markSettingsDirty();
        render();
    }
    function addSettingsExclusion(target, value) {
        var input = mount.querySelector('#nbh-settings-form [name="' + target + '"]');
        if (!input)
            return;
        var items = splitLines(input.value);
        if (items.indexOf(value) === -1)
            items.push(value);
        input.value = items.join('\n');
        state.settingsDraft[target] = items.join('\n');
        markSettingsDirty();
        input.focus();
    }
    function settingsExportPayload() {
        var settings = normalizeSettingsForSave();
        delete settings.webhook_secret;
        delete settings.webhook_secret_clear;
        return {
            product: 'Nobash Backup',
            format: 1,
            version: String(cfg.version || '1.0.0.1'),
            exported_at: new Date().toISOString(),
            settings: settings
        };
    }
    function exportSettingsConfig() {
        var stamp = new Date().toISOString().slice(0, 10);
        downloadReportFile('nobash-backup-settings-' + stamp + '.json', JSON.stringify(settingsExportPayload(), null, 2), 'application/json;charset=utf-8');
        notify('success', 'Settings file downloaded. Secrets were excluded.');
    }
    function promptSettingsImport() {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'application/json,.json';
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file)
                return;
            var reader = new FileReader();
            reader.onload = function () {
                try {
                    var data = JSON.parse(String(reader.result || ''));
                    var imported = object(data.settings || data);
                    if (!Object.keys(imported).length)
                        throw new Error('The selected file does not contain Nobash Backup settings.');
                    delete imported.webhook_secret;
                    delete imported.webhook_secret_set;
                    if (typeof imported.exclude_paths === 'string')
                        imported.exclude_paths = splitLines(imported.exclude_paths);
                    if (typeof imported.exclude_tables === 'string')
                        imported.exclude_tables = splitLines(imported.exclude_tables);
                    state.settingsDraft = Object.assign({}, object(state.data.settings), imported);
                    state.settingsDirty = true;
                    notify('success', 'Settings imported for review. Click Save settings to apply them.');
                    render();
                }
                catch (error) {
                    notify('error', error.message || 'Could not read the settings file.');
                }
            };
            reader.onerror = function () { notify('error', 'Could not read the settings file.'); };
            reader.readAsText(file);
        });
        input.click();
    }
    function recommendedSettingsDefaults() {
        return {
            retention_count: 10, retention_days: 30, retention_min_free_mb: 1024, temporary_retention_hours: 48,
            exclude_paths: [], exclude_tables: [], notify_email: '', notify_on_success: false, notify_on_failure: true,
            notify_on_warning: true, webhook_url: '', webhook_secret_clear: true, onboarding_done: true,
            delete_data_on_uninstall: false, default_profile: 'full', default_storage_provider: 'local',
            missed_schedule_action: 'run_next', resource_mode: 'balanced', worker_memory_mb: 192,
            job_slice_seconds: 25, slice_pause_ms: 50, auto_verify_archives: true, show_admin_bar_action: true,
            confirm_destructive_actions: true, protect_manual_backups: false, exclude_cache_directories: true,
            exclude_log_files: true, pause_on_high_load: true, resume_interrupted_jobs: true,
            require_restore_backup: true, verify_before_restore: true, restrict_downloads_to_admins: true,
            debug_logging: false
        };
    }
    async function restoreRecommendedSettings() {
        if (!(await confirmDialog({ eyebrow: 'Recommended defaults', title: 'Restore recommended settings?', message: 'This replaces the current settings draft with safe recommended defaults. Backups, schedules and jobs are not changed.', confirmLabel: 'Restore defaults', confirmIcon: 'refresh', intent: 'warning', note: 'Review the values and click Save settings before they are applied.' })))
            return;
        state.settingsDraft = Object.assign({}, object(state.data.settings), recommendedSettingsDefaults());
        state.settingsDirty = true;
        notify('success', 'Recommended defaults loaded for review.');
        render();
    }
    async function clearWebhookSecret() {
        if (!(await confirmDialog({ eyebrow: 'Webhook security', title: 'Remove the saved webhook secret?', message: 'Future webhook notifications will no longer include a signature until a new secret is saved.', confirmLabel: 'Remove secret', confirmIcon: 'trash', intent: 'danger' })))
            return;
        try {
            var clearSettings = validateSettings(normalizeSettingsForSave());
            clearSettings.webhook_secret_clear = true;
            var updated = await core('/settings', { method: 'POST', body: { settings: clearSettings } });
            state.data.settings = object(updated.settings);
            state.settingsDraft = JSON.parse(JSON.stringify(state.data.settings));
            state.settingsDirty = false;
            notify('success', 'Webhook signing secret removed.');
            render();
        }
        catch (e) {
            notify('error', e.message || 'Could not remove the webhook secret.');
        }
    }
    async function retentionAction(action) {
        if (state.settingsDirty)
            await saveSettings({ silent: true });
        if (action === 'run' && !(await confirmDialog({ eyebrow: 'Local retention', title: 'Apply local retention now?', message: 'Eligible local backups will be removed according to the current retention policy.', confirmLabel: 'Run retention', confirmIcon: 'trash', intent: 'danger', note: 'Protected restore points and active chains remain untouched.' })))
            return;
        try {
            var result = await core('/retention/' + action, { method: action === 'run' ? 'POST' : 'GET', body: action === 'run' ? {} : undefined });
            var target = document.getElementById('nbh-retention-result');
            if (target)
                target.innerHTML = action === 'preview' ? '<div class="nbh-retention-summary"><strong>' + esc(result.delete_count || 0) + ' backups selected</strong><small>' + esc(formatBytes(result.reclaim_bytes || 0)) + ' can be reclaimed</small></div><pre class="nbh-json">' + esc(JSON.stringify(result.delete || [], null, 2)) + '</pre>' : '<div class="nbh-callout is-safe">' + icon('check') + '<div><strong>Retention completed</strong><p>' + esc(result.deleted_count || 0) + ' backups removed.</p></div></div>';
            if (action === 'run')
                await loadData();
        }
        catch (e) {
            notify('error', e.message);
        }
    }
    async function openReportJobDetails(id) {
        try {
            var responses = await Promise.all([core('/jobs/' + encodeURIComponent(id)), core('/jobs/' + encodeURIComponent(id) + '/events')]);
            state.modal = { type: 'report-job-details', job: responses[0], events: array(object(responses[1]).items) };
            render();
        }
        catch (e) {
            notify('error', e.message || 'Could not load job details.');
        }
    }
    async function cancelReportJob(id) {
        var report = object(state.data.health);
        var job = array(report.recent_jobs).find(function (item) { return number(item.id) === number(id); }) || (state.modal && state.modal.type === 'report-job-details' && number(object(state.modal.job).id) === number(id) ? object(state.modal.job) : null) || { id: id };
        if (!(await confirmDialog({ eyebrow: 'Cancel job', title: 'Cancel this job?', message: 'Nobash Backup will stop this job at the next safe checkpoint and keep the existing backups and settings unchanged.', confirmLabel: 'Cancel job', confirmIcon: 'warning', intent: 'warning', details: [{ icon: 'reports', label: 'Operation', value: reportJobLabel(job) }, { icon: 'warning', label: 'Job ID', value: '#' + number(id) }], note: 'After the job reaches the cancelled state, you can remove its activity record from Activity & Reports.' })))
            return;
        try {
            var updated = await core('/jobs/' + encodeURIComponent(id) + '/cancel', { method: 'POST' });
            if (state.modal && state.modal.type === 'report-job-details' && number(object(state.modal.job).id) === number(id)) {
                state.modal.job = updated;
            }
            notify('success', reportJobLabel(updated || job) + ' cancellation requested.');
            await loadData();
            if (state.modal && state.modal.type === 'report-job-details' && number(object(state.modal.job).id) === number(id)) {
                await openReportJobDetails(id);
            }
        }
        catch (e) {
            notify('error', e.message || 'Could not cancel the job.');
        }
    }
    async function removeReportJob(id) {
        var report = object(state.data.health);
        var job = array(report.recent_jobs).find(function (item) { return number(item.id) === number(id); }) || (state.modal && state.modal.type === 'report-job-details' && number(object(state.modal.job).id) === number(id) ? object(state.modal.job) : null) || { id: id };
        if (!(await confirmDialog({ eyebrow: 'Remove job record', title: 'Remove this job activity?', message: 'This permanently removes the selected finished job record and its detailed event history from Activity & Reports.', confirmLabel: 'Remove job', confirmIcon: 'trash', intent: 'danger', details: [{ icon: 'reports', label: 'Operation', value: reportJobLabel(job) }, { icon: 'warning', label: 'Status', value: reportStatusLabel(job.status) }, { icon: 'archive', label: 'Job ID', value: '#' + number(id) }], note: 'This removes only the job activity record. Existing backups, schedules and other site data are not deleted.' })))
            return;
        try {
            await core('/jobs/' + encodeURIComponent(id) + '/delete', { method: 'DELETE' });
            if (state.modal && state.modal.type === 'report-job-details' && number(object(state.modal.job).id) === number(id)) {
                state.modal = null;
            }
            notify('success', reportJobLabel(job) + ' activity removed.');
            await loadData();
        }
        catch (e) {
            notify('error', e.message || 'Could not remove the job activity.');
        }
    }
    function downloadReportFile(filename, content, mime) {
        var blob = new Blob([content], { type: mime || 'text/plain;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
    function csvCell(value) { return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"'; }
    function exportActivityReport(format) {
        var report = object(state.data.health);
        var jobs = filteredReportJobs(report);
        var stamp = new Date().toISOString().replace(/[:.]/g, '-');
        if (format === 'json') {
            downloadReportFile('nobash-backup-activity-' + stamp + '.json', JSON.stringify({ generated_at: report.generated_at || new Date().toISOString(), period_days: number(state.reportPeriod, 30), history_limit: 75, filters: state.reportFilters, jobs: jobs }, null, 2), 'application/json;charset=utf-8');
            notify('success', 'Activity JSON exported.');
            return;
        }
        var rows = [['Job ID', 'Operation', 'Type', 'Status', 'Progress', 'Started', 'Completed', 'Duration seconds', 'Step', 'Processed bytes', 'Total bytes', 'Error code', 'Error']];
        jobs.forEach(function (job) { rows.push([job.id, reportJobLabel(job), job.type, reportStatusLabel(job.status), number(job.progress), job.started || job.created || '', job.completed || '', job.duration == null ? '' : job.duration, job.step || job.phase || '', number(job.processed_bytes), number(job.total_bytes), job.error_code || '', job.error || '']); });
        downloadReportFile('nobash-backup-activity-' + stamp + '.csv', rows.map(function (row) { return row.map(csvCell).join(','); }).join('\r\n'), 'text/csv;charset=utf-8');
        notify('success', 'Activity CSV exported.');
    }
    function exportAuditReport() {
        var report = object(state.data.health);
        var rows = array(report.recent_audit);
        var stamp = new Date().toISOString().replace(/[:.]/g, '-');
        downloadReportFile('nobash-backup-audit-' + stamp + '.json', JSON.stringify({ generated_at: report.generated_at || new Date().toISOString(), events: rows }, null, 2), 'application/json;charset=utf-8');
        notify('success', 'Audit trail exported.');
    }
    async function clearJobActivity() {
        var report = object(state.data.health);
        var terminal = ['completed', 'completed_with_warnings', 'failed', 'cancelled'];
        var count = array(report.recent_jobs).filter(function (job) { return terminal.indexOf(String(job.status || '')) !== -1; }).length;
        if (!(await confirmDialog({ eyebrow: 'Clear job activity', title: 'Delete all finished logs?', message: 'This permanently removes completed, failed and cancelled job records and their detailed event history.', confirmLabel: 'Delete finished logs', confirmIcon: 'trash', intent: 'danger', details: [{ icon: 'reports', label: 'Visible removable jobs', value: String(count) }], note: 'Running, queued, paused and recovery-required jobs remain available. Backups, schedules and audit records are not removed.' })))
            return;
        try {
            var result = await core('/jobs/history', { method: 'DELETE' });
            state.reportFilters = { query: '', type: 'all', status: 'all' };
            state.reportPagination.jobs = 1;
            if (state.job && terminal.indexOf(String(state.job.status || '')) !== -1)
                state.job = null;
            notify('success', String(number(result.deleted) || 0) + ' job activit' + ((number(result.deleted) || 0) === 1 ? 'y' : 'ies') + ' cleared.');
            await loadData();
        }
        catch (e) {
            notify('error', e.message || 'Could not clear job activity.');
        }
    }
    async function clearAuditTrail() {
        var report = object(state.data.health);
        var count = array(report.recent_audit).length;
        if (!(await confirmDialog({ eyebrow: 'Clear audit trail', title: 'Clear all audit trail records?', message: 'This permanently removes the local Nobash Backup audit history from this site.', confirmLabel: 'Clear audit trail', confirmIcon: 'trash', intent: 'danger', details: [{ icon: 'reports', label: 'Audit records', value: String(count) + (count === 1 ? ' event' : ' events') }], note: 'This does not remove backups, jobs, schedules or settings. Export the audit trail first when you need to keep a copy.' })))
            return;
        try {
            var result = await core('/audit', { method: 'DELETE' });
            state.reportPagination.audit = 1;
            if (state.data.health)
                state.data.health.recent_audit = [];
            notify('success', (number(result.deleted) || count) + ' audit event' + ((number(result.deleted) || count) === 1 ? '' : 's') + ' cleared.');
            await loadData();
        }
        catch (e) {
            notify('error', e.message || 'Could not clear the audit trail.');
        }
    }
    async function testNotifications() {
        try {
            if (state.settingsDirty)
                await saveSettings({ silent: true });
            var result = await core('/notifications/test', { method: 'POST', body: {} });
            notify(result.email_sent || result.webhook === 'sent' ? 'success' : 'error', result.email_sent || result.webhook === 'sent' ? 'Test notification sent.' : 'No notification channel accepted the test.');
        }
        catch (e) {
            notify('error', e.message);
        }
    }


    async function resetData() {
        if (!(await confirmDialog({ eyebrow: 'Reset plugin data', title: 'Reset plugin settings and metadata?', message: 'Plugin settings and job metadata will be reset. Local backup archives are preserved.', confirmLabel: 'Reset plugin data', confirmIcon: 'warning', intent: 'danger', note: 'Use this only when you want to start over with plugin configuration.' })))
            return;
        try {
            await core('/reset', { method: 'POST', body: { preserve_backups: true } });
            notify('success', 'Plugin data reset.');
            await loadData();
        }
        catch (e) {
            notify('error', e.message);
        }
    }

    async function jobControl(action) {
        if (!state.job)
            return;
        try {
            var job = await core('/jobs/' + state.job.id + '/' + action, { method: 'POST', body: {} });
            state.job = job;
            render();
            if (action === 'resume')
                await runJob(job);
        }
        catch (e) {
            notify('error', e.message);
        }
    }

    function handleChange(event) {
        var input = event.target;
        if (input.getAttribute('data-action') === 'report-period') {
            state.reportPeriod = number(input.value, 30); state.reportPagination.jobs = 1; render(); return;
        }
        if (input.closest && input.closest('#nbh-report-filters') && input.tagName === 'SELECT') {
            var filters = formObject(input.closest('form'));
            state.reportFilters = { query: filters.query || '', type: filters.type || 'all', status: filters.status || 'all' };
            state.reportPagination.jobs = 1; render(); return;
        }
        if (input.matches && input.matches('#nbh-import-file-form input[type="file"]')) {
            var importFile = input.files && input.files[0] ? input.files[0] : null;
            if (importFile && !/\.nbh$/i.test(importFile.name)) {
                state.importLocalFile = null; input.value = '';
                notify('error', 'Choose a valid .nbh backup archive.'); render(); return;
            }
            state.importLocalFile = importFile; render(); return;
        }
        if (input.closest && input.closest('#nbh-settings-form')) markSettingsDirty();
        if (input.closest && input.closest('#nbh-settings-form') && input.name === 'resource_mode') { applyResourcePreset(input.value); return; }
        if (input.matches('[data-toggle-target]')) {
            var target = document.getElementById(input.getAttribute('data-toggle-target'));
            if (target) target.hidden = !input.checked;
        }
        if (input.closest && input.closest('#nbh-restore-form') && ['components','safety_backup'].indexOf(input.name) !== -1) { updateRestoreSummary(); return; }
        if (input.closest && input.closest('#nbh-restore-form') && input.name === 'filename') { state.selectedRestore = input.value; render(); return; }
        if (input.getAttribute('data-action') === 'backup-sort') { state.backupSort = input.value; state.backupsPage = 1; render(); return; }
        if (input.getAttribute('data-action') === 'backup-select') {
            var name = input.getAttribute('data-file'); state.selectedBackups = state.selectedBackups.filter(function(f) { return f !== name; });
            if (input.checked) state.selectedBackups.push(name); render(); return;
        }
        if (input.getAttribute('data-action') === 'backup-select-all') {
            var checked = input.checked;
            mount.querySelectorAll('[data-action="backup-select"]').forEach(function(box) { var name = box.getAttribute('data-file'); state.selectedBackups = state.selectedBackups.filter(function(f) { return f !== name; }); if (checked) state.selectedBackups.push(name); }); render(); return;
        }
        if (input.getAttribute('data-action') === 'backups-per-page') {
            state.backupsPerPage = [5, 10, 25, 50].indexOf(number(input.value, 10)) !== -1 ? number(input.value, 10) : 10;
            state.backupsPage = 1; state.openBackupMenu = ''; render(); return;
        }
        if (input.closest && input.closest('#nbh-backup-form')) updateExportSummary();
    }

    function importDropzoneForEvent(event) {
        return event.target && event.target.closest ? event.target.closest('.nbh-import-dropzone') : null;
    }
    mount.addEventListener('dragenter', function (event) {
        var zone = importDropzoneForEvent(event);
        if (!zone) return;
        event.preventDefault();
        zone.classList.add('is-dragging');
    });
    mount.addEventListener('dragover', function (event) {
        var zone = importDropzoneForEvent(event);
        if (!zone) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
        zone.classList.add('is-dragging');
    });
    mount.addEventListener('dragleave', function (event) {
        var zone = importDropzoneForEvent(event);
        if (!zone) return;
        if (!event.relatedTarget || !zone.contains(event.relatedTarget))
            zone.classList.remove('is-dragging');
    });
    mount.addEventListener('drop', function (event) {
        var zone = importDropzoneForEvent(event);
        if (!zone) return;
        event.preventDefault();
        zone.classList.remove('is-dragging');
        var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0] ? event.dataTransfer.files[0] : null;
        if (!file) return;
        if (!/\.nbh$/i.test(file.name)) {
            notify('error', 'Choose a valid .nbh backup archive.');
            return;
        }
        state.importLocalFile = file;
        render();
    });
    mount.addEventListener('click', function (event) { handleClick(event); });
    mount.addEventListener('submit', function (event) { handleSubmit(event); });
    mount.addEventListener('change', handleChange);
    mount.addEventListener('input', function (event) { if (event.target.closest && event.target.closest('.nbh-import-workspace #nbh-import-url-form')) { if (event.target.name === 'url' || event.target.name === 'sha256') state.importUrlDraft[event.target.name] = event.target.value; } if (event.target.id === 'nbh-backup-search') { var caret = event.target.selectionStart; state.backupQuery = event.target.value; state.backupsPage = 1; render(); var search = document.getElementById('nbh-backup-search'); if (search) { search.focus(); if (caret !== null) search.setSelectionRange(caret,caret); } return; } if (event.target.closest && event.target.closest('#nbh-settings-form'))
        markSettingsDirty(); if (event.target.closest && event.target.closest('#nbh-backup-form'))
        updateExportSummary(); });
    window.addEventListener('click', function (event) {
        var picker = mount.querySelector('[data-export-content-picker]');
        if (picker && !picker.contains(event.target))
            closeExportContentMenu(false);
        if (state.openBackupMenu && !(event.target.closest && event.target.closest('.nobash-backup-more'))) {
            state.openBackupMenu = '';
            if (state.page === 'backups')
                render();
        }
    });
    window.addEventListener('popstate', function () {
        var params = new URLSearchParams(window.location.search);
        state.page = normalizePage(params.get('view') || 'dashboard');
        state.helpTopic = normalizeHelpTopic(params.get('topic') || 'docs');
        state.navOpen = false;
        render();
    });
    window.addEventListener('resize', function () { if (window.innerWidth > 960 && state.navOpen) {
        state.navOpen = false;
        render();
    } });
    window.addEventListener('beforeunload', function (event) {
        if (!state.settingsDirty)
            return;
        event.preventDefault();
        event.returnValue = '';
    });
    window.addEventListener('keydown', function (event) {
        var settingsTab = event.target && event.target.matches && event.target.matches('[data-action="settings-tab"]') ? event.target : null;
        if (settingsTab && ['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) !== -1) {
            var settingsTabsList = Array.from(mount.querySelectorAll('[data-action="settings-tab"]'));
            var settingsIndex = settingsTabsList.indexOf(settingsTab);
            if (settingsTabsList.length && settingsIndex !== -1) {
                event.preventDefault();
                var nextSettingsIndex = event.key === 'Home' ? 0 : event.key === 'End' ? settingsTabsList.length - 1 : ((event.key === 'ArrowDown' || event.key === 'ArrowRight') ? (settingsIndex + 1) % settingsTabsList.length : (settingsIndex - 1 + settingsTabsList.length) % settingsTabsList.length);
                settingsTabsList[nextSettingsIndex].click();
                window.setTimeout(function () { var activeSettingsTab = mount.querySelector('[data-action="settings-tab"][aria-selected="true"]'); if (activeSettingsTab)
                    activeSettingsTab.focus({ preventScroll: true }); }, 0);
                return;
            }
        }
        var exportPicker = mount.querySelector('[data-export-content-picker]');
        var exportMenu = exportPicker ? exportPicker.querySelector('#nbh-export-content-menu') : null;
        if (event.key === 'Escape' && exportMenu && !exportMenu.hidden) {
            event.preventDefault();
            closeExportContentMenu(true);
            return;
        }
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && exportMenu && !exportMenu.hidden) {
            var exportOptions = Array.from(exportMenu.querySelectorAll('[role="option"]'));
            var exportIndex = exportOptions.indexOf(document.activeElement);
            if (exportIndex !== -1 && exportOptions.length) {
                event.preventDefault();
                var exportNext = event.key === 'ArrowDown' ? (exportIndex + 1) % exportOptions.length : (exportIndex - 1 + exportOptions.length) % exportOptions.length;
                exportOptions[exportNext].focus({ preventScroll: true });
                return;
            }
        }
        if (event.key === 'Escape' && state.navOpen) {
            state.navOpen = false;
            render();
            return;
        }
        if (event.key === 'Escape' && state.modal) {
            if (state.modal.type === 'confirm')
                closeConfirmDialog(false);
            else {
                state.modal = null;
                render();
            }
            return;
        }
        if (event.key === 'Tab' && state.modal) {
            var panel = mount.querySelector('[data-modal-panel]');
            if (!panel)
                return;
            var focusable = Array.from(panel.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'));
            if (!focusable.length)
                return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            }
            else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
        if ((event.key === 'ArrowLeft' || event.key === 'ArrowRight') && document.activeElement && document.activeElement.getAttribute('role') === 'tab') {
            var tabs = Array.from(mount.querySelectorAll('[role="tab"]'));
            var current = tabs.indexOf(document.activeElement);
            if (current !== -1) {
                event.preventDefault();
                var next = event.key === 'ArrowRight' ? (current + 1) % tabs.length : (current - 1 + tabs.length) % tabs.length;
                tabs[next].click();
                tabs[next].focus();
            }
        }
    });
    render();
    loadInitialPageData().catch(function (error) { state.loading = false; notify('error', error.message || 'Nobash Backup could not load.'); });
}());
