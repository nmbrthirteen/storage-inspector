(function () {
    const i18n = StorageInspector.i18n || {};
    const bannerKey = 'storageInspectorBannerDismissedAt';
    const state = {
        scanning: false,
        kind: 'groups',
        page: 1,
        perPage: 50,
        totalPages: 1,
        lastRowsFetch: 0,
    };
    const els = {};

    document.addEventListener('DOMContentLoaded', function () {
        els.start = document.getElementById('si-start');
        els.status = document.getElementById('si-status');
        els.fill = document.getElementById('si-progress-fill');
        els.summary = document.getElementById('si-summary');
        els.root = document.getElementById('si-root');
        els.rootWarning = document.getElementById('si-root-warning');
        els.head = document.getElementById('si-table-head');
        els.body = document.getElementById('si-table-body');
        els.prev = document.getElementById('si-prev');
        els.next = document.getElementById('si-next');
        els.page = document.getElementById('si-page');

        if (els.start) {
            els.start.addEventListener('click', startScan);
        }

        document.querySelectorAll('.si-tab').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                document.querySelectorAll('.si-tab').forEach(function (tab) {
                    tab.classList.remove('nav-tab-active');
                });
                button.classList.add('nav-tab-active');
                state.kind = button.dataset.kind;
                state.page = 1;
                fetchRows();
            });
        });

        if (els.prev) {
            els.prev.addEventListener('click', function () {
                if (state.page > 1) {
                    state.page -= 1;
                    fetchRows();
                }
            });
        }

        if (els.next) {
            els.next.addEventListener('click', function () {
                if (state.page < state.totalPages) {
                    state.page += 1;
                    fetchRows();
                }
            });
        }

        fetchState();
        if (StorageInspector.isInspectorPage) {
            fetchRows();
        }
    });

    function request(action, data) {
        const form = new FormData();
        form.append('action', 'storage_inspector_' + action);
        form.append('nonce', StorageInspector.nonce);

        Object.keys(data || {}).forEach(function (key) {
            form.append(key, data[key]);
        });

        return fetch(StorageInspector.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: form,
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (!json.success) {
                throw new Error((json.data && json.data.message) || 'Request failed.');
            }
            return json.data;
        });
    }

    function startScan() {
        if (els.start) {
            els.start.disabled = true;
            els.start.textContent = 'Starting...';
        }
        window.localStorage.removeItem(bannerKey);
        setStatus('Starting scan...');
        setTableLoading('Starting new scan...');
        request('start').then(function (data) {
            renderState(data);
            fetchRows();
            runScan();
        }).catch(showError);
    }

    function fetchState() {
        request('state').then(function (data) {
            renderState(data);
            if (data.status === 'running' && StorageInspector.isInspectorPage) {
                runScan();
            } else if (data.status === 'running') {
                window.setTimeout(fetchState, 15000);
            }
        }).catch(showError);
    }

    function runScan() {
        if (state.scanning) {
            return;
        }
        state.scanning = true;
        scanBatch();
    }

    function scanBatch() {
        request('scan').then(function (data) {
            renderState(data);
            fetchRowsThrottled(data.status !== 'running');
            if (data.status === 'running') {
                window.setTimeout(scanBatch, 300);
                return;
            }

            state.scanning = false;
            if (els.start) {
                els.start.disabled = false;
                els.start.textContent = 'Start new scan';
            }
        }).catch(function (error) {
            state.scanning = false;
            if (els.start) {
                els.start.disabled = false;
                els.start.textContent = 'Start new scan';
            }
            showError(error);
        });
    }

    function fetchRows() {
        if (!StorageInspector.isInspectorPage || !els.body) {
            return;
        }

        renderHead();
        setTableLoading('Loading rows...');
        request('rows', {
            kind: state.kind,
            page: state.page,
            perPage: state.perPage,
        }).then(function (data) {
            state.totalPages = Math.max(1, data.totalPages || 1);
            renderRows(data.rows || []);
            renderPager(data);
        }).catch(showError);
    }

    function fetchRowsThrottled(force) {
        const now = Date.now();
        if (force || now - state.lastRowsFetch > 3000) {
            state.lastRowsFetch = now;
            fetchRows();
        }
    }

    function renderState(data) {
        renderBanner(data);

        if (!StorageInspector.isInspectorPage || !els.status) {
            return;
        }

        if (els.start) {
            els.start.disabled = data.status === 'running';
        }
        if (els.fill) {
            els.fill.style.width = (data.progress || 0) + '%';
        }

        if (data.status === 'empty') {
            setStatus(i18n.empty || 'No scan has been run yet.');
        } else if (data.status === 'running') {
            setStatus('Scanning... ' + number(data.dirs || 0) + ' folders checked, ' + number(data.queued || 0) + ' queued, ' + number(data.files || 0) + ' files found.');
        } else {
            setStatus('Scan complete. ' + number(data.files || 0) + ' files across ' + number(data.dirs || 0) + ' folders, using ' + (data.bytesHuman || '0 B') + '.');
        }

        renderSummary(data);
        renderRoot(data);
        renderRootWarning(data);
    }

    function renderBanner(data) {
        let notice = document.getElementById('si-admin-banner');
        const dismissedScan = window.localStorage.getItem(bannerKey);
        const currentScan = String(data.startedAt || '');
        const dismissed = dismissedScan !== '' && dismissedScan === currentScan;

        if (data.status !== 'running' || dismissed) {
            if (notice) {
                notice.remove();
            }
            return;
        }

        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'si-admin-banner';
            notice.className = 'notice notice-info si-admin-banner';
            notice.innerHTML = '<div class="si-admin-banner-content">' +
                '<div><strong></strong><p></p></div>' +
                '<a class="button button-secondary"></a>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text"></span></button>' +
            '</div>';

            const wrap = document.querySelector('.wrap') || document.querySelector('#wpbody-content');
            if (wrap) {
                wrap.insertBefore(notice, wrap.firstChild);
            }

            notice.querySelector('.notice-dismiss').addEventListener('click', function () {
                window.localStorage.setItem(bannerKey, String(data.startedAt || ''));
                notice.remove();
            });
        }

        notice.querySelector('strong').textContent = i18n.bannerTitle || 'Storage Inspector scan is running';
        notice.querySelector('p').textContent = 'Checked ' + number(data.dirs || 0) + ' folders and ' + number(data.files || 0) + ' files. ' + number(data.queued || 0) + ' folders queued.';
        notice.querySelector('a').textContent = i18n.bannerLink || 'View progress';
        notice.querySelector('a').href = StorageInspector.pageUrl;
        notice.querySelector('.screen-reader-text').textContent = i18n.dismiss || 'Dismiss';
    }

    function renderSummary(data) {
        els.summary.innerHTML = [
            summaryCard('Total storage', sizeLabel(data.bytesHuman || '0 B')),
            summaryCard('Files', number(data.files || 0)),
            summaryCard('Folders', number(data.dirs || 0)),
            summaryCard('Errors', number(data.errors || 0)),
        ].join('');
    }

    function renderRoot(data) {
        if (!els.root || !data.root) {
            return;
        }

        els.root.hidden = false;
        els.root.innerHTML = '<span>Scanned root</span><code>' + escapeHtml(data.root) + '</code>' +
            '<button type="button" class="button button-small si-copy" data-copy="' + escapeAttr(data.root) + '">Copy path</button>';
        bindCopyButtons(els.root);
    }

    function renderRootWarning(data) {
        if (!els.rootWarning) {
            return;
        }

        if (!data.staleRoot) {
            els.rootWarning.hidden = true;
            els.rootWarning.textContent = '';
            return;
        }

        els.rootWarning.hidden = false;
        els.rootWarning.innerHTML = '<p>These results were scanned from an old root. Start a new scan to use <code>' +
            escapeHtml(data.expectedRoot || '') + '</code>.</p>';
    }

    function renderHead() {
        if (state.kind === 'groups') {
            els.head.innerHTML = '<tr><th>Area</th><th>Size</th><th>Files</th><th>Folders</th><th>Details</th></tr>';
        } else if (state.kind === 'errors') {
            els.head.innerHTML = '<tr><th>Path</th><th>Message</th></tr>';
        } else {
            els.head.innerHTML = '<tr><th>Path</th><th>Type</th><th>Size</th><th>Reason</th><th>Action</th></tr>';
        }
    }

    function setTableLoading(message) {
        if (!StorageInspector.isInspectorPage || !els.body) {
            return;
        }

        const colspan = state.kind === 'errors' ? 2 : 5;
        els.body.innerHTML = '<tr><td colspan="' + colspan + '"><span class="spinner is-active si-spinner"></span>' + escapeHtml(message) + '</td></tr>';
    }

    function renderRows(rows) {
        if (!rows.length) {
            els.body.innerHTML = '<tr><td colspan="5">' + escapeHtml(i18n.noRows || 'No rows found.') + '</td></tr>';
            return;
        }

        if (state.kind === 'groups') {
            els.body.innerHTML = rows.map(groupRow).join('');
        } else if (state.kind === 'errors') {
            els.body.innerHTML = rows.map(function (row) {
                return '<tr><td>' + pathCell(row.path || '') + '</td><td>' + escapeHtml(row.message || '') + '</td></tr>';
            }).join('');
        } else {
            els.body.innerHTML = rows.map(itemRow).join('');
            els.body.querySelectorAll('.si-delete').forEach(function (button) {
                button.addEventListener('click', deleteItem);
            });
        }

        bindCopyButtons(els.body);
    }

    function groupRow(row) {
        const details = row.details || {};
        const meta = [
            details.version ? 'v' + details.version : '',
            details.author || '',
            details.active ? 'Active' : '',
        ].filter(Boolean).join(' · ');
        const icon = details.icon || 'dashicons-media-default';
        const iconMarkup = fallbackIcon(icon);

        return '<tr>' +
            '<td><div class="si-area">' + iconMarkup + '<div><strong>' + escapeHtml(row.label || '') + '</strong><div class="si-muted">' + escapeHtml(row.type || '') + '</div></div></div></td>' +
            '<td>' + sizeLabel(row.bytesHuman || '0 B') + '</td>' +
            '<td>' + number(row.files || 0) + '</td>' +
            '<td>' + number(row.dirs || 0) + '</td>' +
            '<td>' + escapeHtml(row.reason || '') + (meta ? '<div class="si-muted">' + escapeHtml(meta) + '</div>' : '') + (details.uri ? '<div><a href="' + escapeAttr(details.uri) + '" target="_blank" rel="noreferrer">Plugin URI</a></div>' : '') + '</td>' +
        '</tr>';
    }

    function itemRow(item) {
        const action = item.deletable
            ? '<button type="button" class="button-link-delete si-delete" data-path="' + escapeAttr(item.path) + '" data-type="' + escapeAttr(item.type) + '">' + escapeHtml(i18n.delete || 'Delete') + '</button>'
            : '<span class="si-muted">' + escapeHtml(i18n.protected || 'Protected') + '</span>';

        return '<tr>' +
            '<td>' + pathCell(item.path || '') + '<div class="si-muted">' + escapeHtml(item.area || '') + '</div></td>' +
            '<td>' + escapeHtml(item.type || '') + '</td>' +
            '<td>' + sizeLabel(item.bytesHuman || '0 B') + '</td>' +
            '<td>' + escapeHtml(item.reason || '') + '</td>' +
            '<td>' + action + '</td>' +
        '</tr>';
    }

    function deleteItem(event) {
        const button = event.currentTarget;
        const message = button.dataset.type === 'folder'
            ? i18n.deleteFolder
            : i18n.deleteFile;

        if (!window.confirm(message)) {
            return;
        }

        button.disabled = true;
        request('delete', { path: button.dataset.path }).then(function () {
            fetchState();
            fetchRows();
        }).catch(function (error) {
            button.disabled = false;
            showError(error);
        });
    }

    function renderPager(data) {
        if (!els.page) {
            return;
        }
        els.page.textContent = 'Page ' + number(data.page || 1) + ' of ' + number(data.totalPages || 1) + ' · ' + number(data.total || 0) + ' rows';
        els.prev.disabled = (data.page || 1) <= 1;
        els.next.disabled = (data.page || 1) >= (data.totalPages || 1);
    }

    function summaryCard(label, value) {
        return '<div class="si-card"><span>' + escapeHtml(label) + '</span><div class="si-card-value">' + value + '</div></div>';
    }

    function setStatus(message) {
        if (els.status) {
            els.status.textContent = message;
        }
    }

    function showError(error) {
        if (els.start) {
            els.start.disabled = false;
        }
        setStatus(error.message || 'Something went wrong.');
    }

    function number(value) {
        return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function sizeLabel(label) {
        return '<span>' + escapeHtml(label) + '</span>';
    }

    function fallbackIcon(icon) {
        return '<span class="dashicons ' + escapeAttr(icon) + '"></span>';
    }

    function pathCell(path) {
        return '<div class="si-path"><code>' + escapeHtml(path) + '</code>' +
            '<button type="button" class="button button-small si-copy" data-copy="' + escapeAttr(path) + '">Copy</button></div>';
    }

    function bindCopyButtons(scope) {
        scope.querySelectorAll('.si-copy').forEach(function (button) {
            button.addEventListener('click', function () {
                copyText(button.dataset.copy || '').then(function () {
                    const previous = button.textContent;
                    button.textContent = 'Copied';
                    window.setTimeout(function () {
                        button.textContent = previous;
                    }, 1200);
                });
            });
        });
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
        return Promise.resolve();
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }
})();
