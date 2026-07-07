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
        expanded: {},
    };
    const els = {};

    document.addEventListener('DOMContentLoaded', function () {
        els.start = document.getElementById('si-start');
        els.stop = document.getElementById('si-stop');
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

        if (els.stop) {
            els.stop.addEventListener('click', stopScan);
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
                throw new Error((json.data && json.data.message) || i18n.requestError || 'Request failed.');
            }
            return json.data;
        });
    }

    function startScan() {
        if (els.start) {
            els.start.disabled = true;
            els.start.textContent = i18n.starting || 'Starting...';
        }
        window.localStorage.removeItem(bannerKey);
        setStatus(i18n.startingScan || 'Starting scan...');
        setTableLoading(i18n.startingNew || 'Starting new scan...');
        request('start').then(function (data) {
            renderState(data);
            fetchRows();
            runScan();
        }).catch(showError);
    }

    function stopScan() {
        state.scanning = false;
        if (els.stop) {
            els.stop.disabled = true;
            els.stop.textContent = i18n.stopping || 'Cancelling...';
        }
        request('stop').then(function (data) {
            renderState(data);
            fetchRows();
            if (els.start) {
                els.start.disabled = false;
                els.start.textContent = i18n.startNewScan || 'Start new scan';
            }
        }).catch(function (error) {
            if (els.stop) {
                els.stop.disabled = false;
                els.stop.textContent = i18n.stopScan || 'Cancel scan';
            }
            showError(error);
        });
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
            if (!state.scanning) {
                return;
            }
            renderState(data);
            fetchRowsThrottled(data.status !== 'running');
            if (data.status === 'running') {
                window.setTimeout(scanBatch, 300);
                return;
            }

            state.scanning = false;
            if (els.start) {
                els.start.disabled = false;
                els.start.textContent = i18n.startNewScan || 'Start new scan';
            }
        }).catch(function (error) {
            state.scanning = false;
            if (els.start) {
                els.start.disabled = false;
                els.start.textContent = i18n.startNewScan || 'Start new scan';
            }
            showError(error);
        });
    }

    function fetchRows(silent) {
        if (!StorageInspector.isInspectorPage || !els.body) {
            return;
        }

        renderHead();
        if (!silent) {
            setTableLoading(i18n.loadingRows || 'Loading rows...');
        }
        request('rows', {
            kind: state.kind,
            page: state.page,
            perPage: state.perPage,
        }).then(function (data) {
            state.totalPages = Math.max(1, data.totalPages || 1);
            if (state.kind === 'folders') {
                renderFolderRows(data.rows || [], data);
            } else {
                renderRows(data.rows || []);
                renderPager(data);
            }
        }).catch(showError);
    }

    function fetchRowsThrottled(force) {
        const now = Date.now();
        if (force || now - state.lastRowsFetch > 3000) {
            state.lastRowsFetch = now;
            fetchRows(true);
        }
    }

    function renderState(data) {
        renderBanner(data);

        if (!StorageInspector.isInspectorPage || !els.status) {
            return;
        }

        const running = data.status === 'running';
        if (els.start) {
            els.start.disabled = running;
        }
        if (els.stop) {
            els.stop.hidden = !running;
            if (running) {
                els.stop.disabled = false;
                els.stop.textContent = i18n.stopScan || 'Cancel scan';
            }
        }
        if (els.fill) {
            els.fill.style.width = (data.progress || 0) + '%';
        }

        if (data.status === 'empty') {
            setStatus(i18n.empty || 'No scan has been run yet.');
        } else if (running) {
            setStatus(format(i18n.scanning || 'Scanning... %1$s folders checked, %2$s queued, %3$s files found.', number(data.dirs || 0), number(data.queued || 0), number(data.files || 0)));
        } else if (data.status === 'stopped') {
            setStatus(format(i18n.stopped || 'Scan cancelled. %1$s files across %2$s folders, using %3$s so far.', number(data.files || 0), number(data.dirs || 0), data.bytesHuman || '0 B'));
        } else {
            setStatus(format(i18n.complete || 'Scan complete. %1$s files across %2$s folders, using %3$s.', number(data.files || 0), number(data.dirs || 0), data.bytesHuman || '0 B'));
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
            summaryCard(i18n.totalStorage || 'Total storage', sizeLabel(data.bytesHuman || '0 B')),
            summaryCard(i18n.files || 'Files', number(data.files || 0)),
            summaryCard(i18n.folders || 'Folders', number(data.dirs || 0)),
            summaryCard(i18n.errors || 'Errors', number(data.errors || 0)),
        ].join('');
    }

    function renderRoot(data) {
        if (!els.root || !data.root) {
            return;
        }

        els.root.hidden = false;
        els.root.innerHTML = '<span>' + escapeHtml(i18n.scannedRoot || 'Scanned root') + '</span><code>' + escapeHtml(data.root) + '</code>' +
            '<button type="button" class="button button-small si-copy" data-copy="' + escapeAttr(data.root) + '">' + escapeHtml(i18n.copyPath || 'Copy path') + '</button>';
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
        els.rootWarning.innerHTML = '<p>' +
            format(i18n.staleRoot || 'These results were scanned from an old root. Start a new scan to use %s.', '<code>' + escapeHtml(data.expectedRoot || '') + '</code>') +
            '</p>';
    }

    function renderHead() {
        if (state.kind === 'groups') {
            els.head.innerHTML = headRow([i18n.area || 'Area', i18n.size || 'Size', i18n.files || 'Files', i18n.folders || 'Folders', i18n.details || 'Details']);
        } else if (state.kind === 'errors') {
            els.head.innerHTML = headRow([i18n.path || 'Path', i18n.message || 'Message']);
        } else if (state.kind === 'folders') {
            els.head.innerHTML = headRow([i18n.folderFile || 'Folder', i18n.size || 'Size', i18n.files || 'Files']);
        } else {
            els.head.innerHTML = headRow([i18n.folderFile || 'Folder / file', i18n.type || 'Type', i18n.size || 'Size', i18n.reason || 'Reason', i18n.action || 'Action']);
        }
    }

    function setTableLoading(message) {
        if (!StorageInspector.isInspectorPage || !els.body) {
            return;
        }

        els.body.innerHTML = '<tr><td colspan="' + columnCount() + '"><span class="spinner is-active si-spinner"></span>' + escapeHtml(message) + '</td></tr>';
    }

    function columnCount() {
        if (state.kind === 'errors') {
            return 2;
        }
        if (state.kind === 'folders') {
            return 3;
        }
        return 5;
    }

    function renderRows(rows) {
        if (!rows.length) {
            els.body.innerHTML = '<tr><td colspan="' + columnCount() + '">' + escapeHtml(i18n.noRows || 'No rows found.') + '</td></tr>';
            return;
        }

        if (state.kind === 'groups') {
            els.body.innerHTML = rows.map(groupRow).join('');
        } else if (state.kind === 'errors') {
            els.body.innerHTML = rows.map(function (row) {
                return '<tr><td>' + pathCell(row.path || '') + '</td><td>' + escapeHtml(row.message || '') + '</td></tr>';
            }).join('');
        } else {
            els.body.innerHTML = rows.map(folderGroup).join('');
            bindFolderToggles();
            els.body.querySelectorAll('.si-delete').forEach(function (button) {
                button.addEventListener('click', deleteItem);
            });
        }

        bindCopyButtons(els.body);
    }

    function renderFolderRows(rows, data) {
        if (!rows.length) {
            els.body.innerHTML = '<tr><td colspan="3">' + escapeHtml(i18n.noRows || 'No rows found.') + '</td></tr>';
            renderFolderNote(null);
            return;
        }

        els.body.innerHTML = rows.map(folderTreeRow).join('');
        bindFolderTree(els.body);
        renderFolderNote(data);
    }

    function folderTreeRow(row) {
        const depth = row.depth || 1;
        const pad = 8 + (depth - 1) * 20;
        const toggle = row.expandable
            ? '<button type="button" class="si-toggle" aria-expanded="false" data-path="' + escapeAttr(row.path) + '" data-depth="' + depth + '"><span class="si-toggle-icon" aria-hidden="true">▸</span></button>'
            : '<span class="si-toggle-spacer" aria-hidden="true"></span>';

        return '<tr class="si-tree-row" data-path="' + escapeAttr(row.path) + '" data-depth="' + depth + '">' +
            '<td><div class="si-tree-cell" style="padding-left:' + pad + 'px">' + toggle +
            '<code>' + escapeHtml(row.name || row.path) + '</code>' +
            '<button type="button" class="button button-small si-copy" data-copy="' + escapeAttr(row.path) + '">' + escapeHtml(i18n.copy || 'Copy') + '</button></div></td>' +
            '<td>' + sizeLabel(row.bytesHuman || '0 B') + '</td>' +
            '<td>' + number(row.files || 0) + '</td>' +
        '</tr>';
    }

    function bindFolderTree(scope) {
        scope.querySelectorAll('.si-tree-row .si-toggle').forEach(function (button) {
            if (button.dataset.bound === '1') {
                return;
            }
            button.dataset.bound = '1';
            button.addEventListener('click', toggleFolder);
        });
        bindCopyButtons(scope);
    }

    function toggleFolder(event) {
        const button = event.currentTarget;
        const row = button.closest('tr');
        const path = button.dataset.path || '';
        const expanded = button.getAttribute('aria-expanded') === 'true';

        if (expanded) {
            button.setAttribute('aria-expanded', 'false');
            button.classList.remove('is-open');
            collapseFolder(row);
            return;
        }

        button.setAttribute('aria-expanded', 'true');
        button.classList.add('is-open', 'is-loading');
        request('rows', { kind: 'folders', parent: path, perPage: state.perPage }).then(function (data) {
            button.classList.remove('is-loading');
            insertChildRows(row, data.rows || []);
        }).catch(function (error) {
            button.classList.remove('is-loading', 'is-open');
            button.setAttribute('aria-expanded', 'false');
            showError(error);
        });
    }

    function insertChildRows(afterRow, rows) {
        let anchor = afterRow;
        rows.forEach(function (row) {
            const holder = document.createElement('tbody');
            holder.innerHTML = folderTreeRow(row);
            const tr = holder.firstElementChild;
            anchor.insertAdjacentElement('afterend', tr);
            anchor = tr;
            const toggle = tr.querySelector('.si-toggle');
            if (toggle) {
                toggle.dataset.bound = '1';
                toggle.addEventListener('click', toggleFolder);
            }
            bindCopyButtons(tr);
        });
    }

    function collapseFolder(row) {
        const depth = parseInt(row.dataset.depth || '1', 10);
        let next = row.nextElementSibling;
        while (next && parseInt(next.dataset.depth || '0', 10) > depth) {
            const remove = next;
            next = next.nextElementSibling;
            remove.remove();
        }
    }

    function renderFolderNote(data) {
        if (!els.page) {
            return;
        }
        els.prev.disabled = true;
        els.next.disabled = true;
        els.page.textContent = data && data.truncated > 0
            ? format(i18n.moreFolders || 'Showing the %1$s largest of %2$s folders.', number(data.shown || 0), number(data.total || 0))
            : (i18n.folderHint || '');
    }

    function groupRow(row) {
        const details = row.details || {};
        const meta = [
            details.version ? 'v' + details.version : '',
            details.author || '',
            details.active ? (i18n.active || 'Active') : '',
        ].filter(Boolean).join(' · ');
        const icon = details.icon || 'dashicons-media-default';
        const iconMarkup = fallbackIcon(icon);

        return '<tr>' +
            '<td><div class="si-area">' + iconMarkup + '<div><strong>' + escapeHtml(row.label || '') + '</strong><div class="si-muted">' + escapeHtml(row.type || '') + '</div></div></div></td>' +
            '<td>' + sizeLabel(row.bytesHuman || '0 B') + '</td>' +
            '<td>' + number(row.files || 0) + '</td>' +
            '<td>' + number(row.dirs || 0) + '</td>' +
            '<td>' + escapeHtml(row.reason || '') + (meta ? '<div class="si-muted">' + escapeHtml(meta) + '</div>' : '') + (details.uri ? '<div><a href="' + escapeAttr(details.uri) + '" target="_blank" rel="noreferrer">' + escapeHtml(i18n.pluginUri || 'Plugin URI') + '</a></div>' : '') + '</td>' +
        '</tr>';
    }

    function folderGroup(group, index) {
        const path = group.path || '';
        const open = !!state.expanded[path];
        const children = (group.children || []).map(function (child) {
            return childRow(child, index, open);
        }).join('');
        const count = number(group.files || 0) + ' ' + escapeHtml(i18n.largeFiles || 'large files');

        return '<tr class="si-folder-row">' +
            '<td><button type="button" class="si-toggle' + (open ? ' is-open' : '') + '" aria-expanded="' + (open ? 'true' : 'false') + '" data-group="' + index + '" data-path="' + escapeAttr(path) + '"><span class="si-toggle-icon" aria-hidden="true">▸</span></button>' +
            '<code>' + escapeHtml(path) + '</code></td>' +
            '<td><span class="si-muted">' + escapeHtml(i18n.folder || 'Folder') + '</span></td>' +
            '<td>' + sizeLabel(group.bytesHuman || '0 B') + '</td>' +
            '<td>' + count + '</td>' +
            '<td></td>' +
        '</tr>' + children;
    }

    function childRow(child, index, open) {
        const action = child.deletable
            ? '<button type="button" class="button-link-delete si-delete" data-path="' + escapeAttr(child.path) + '" data-type="' + escapeAttr(child.type) + '">' + escapeHtml(i18n.delete || 'Delete') + '</button>'
            : '<span class="si-muted">' + escapeHtml(i18n.protected || 'Protected') + '</span>';

        return '<tr class="si-child-row" data-group="' + index + '"' + (open ? '' : ' hidden') + '>' +
            '<td class="si-child-cell">' + pathCell(child.path || '') + '<div class="si-muted">' + escapeHtml(child.area || '') + '</div></td>' +
            '<td>' + escapeHtml(fileType(child.path || '')) + '</td>' +
            '<td>' + sizeLabel(child.bytesHuman || '0 B') + '</td>' +
            '<td>' + escapeHtml(child.reason || '') + '</td>' +
            '<td>' + action + '</td>' +
        '</tr>';
    }

    function fileType(path) {
        const dot = path.lastIndexOf('.');
        const ext = dot > -1 ? path.slice(dot + 1).toUpperCase() : '';
        return ext || (i18n.file || 'File');
    }

    function bindFolderToggles() {
        els.body.querySelectorAll('.si-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const group = button.dataset.group;
                const path = button.dataset.path || '';
                const expanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                button.classList.toggle('is-open', !expanded);
                if (expanded) {
                    delete state.expanded[path];
                } else {
                    state.expanded[path] = true;
                }
                els.body.querySelectorAll('.si-child-row[data-group="' + group + '"]').forEach(function (row) {
                    row.hidden = expanded;
                });
            });
        });
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
        els.page.textContent = format(i18n.pager || 'Page %1$s of %2$s · %3$s rows', number(data.page || 1), number(data.totalPages || 1), number(data.total || 0));
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
        setStatus(error.message || i18n.genericError || 'Something went wrong.');
    }

    function number(value) {
        return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function format(template) {
        const args = Array.prototype.slice.call(arguments, 1);
        let auto = 0;
        return String(template)
            .replace(/%(\d+)\$s/g, function (match, position) {
                return args[position - 1];
            })
            .replace(/%s/g, function () {
                return args[auto++];
            });
    }

    function headRow(labels) {
        return '<tr>' + labels.map(function (label) {
            return '<th>' + escapeHtml(label) + '</th>';
        }).join('') + '</tr>';
    }

    function sizeLabel(label) {
        return '<span>' + escapeHtml(label) + '</span>';
    }

    function fallbackIcon(icon) {
        return '<span class="dashicons ' + escapeAttr(icon) + '"></span>';
    }

    function pathCell(path) {
        return '<div class="si-path"><code>' + escapeHtml(path) + '</code>' +
            '<button type="button" class="button button-small si-copy" data-copy="' + escapeAttr(path) + '">' + escapeHtml(i18n.copy || 'Copy') + '</button></div>';
    }

    function bindCopyButtons(scope) {
        scope.querySelectorAll('.si-copy').forEach(function (button) {
            button.addEventListener('click', function () {
                copyText(button.dataset.copy || '').then(function () {
                    const previous = button.textContent;
                    button.textContent = i18n.copied || 'Copied';
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
