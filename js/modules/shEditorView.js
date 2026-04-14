import { api } from '../apiService.js';
import { toast } from '../utils/toast.js';
import { formatSize } from '../utils/formatSize.js';
import { LOADING } from '../utils/loading.js';

let currentPath = '';

// ─── BD sidebar ───

async function loadBdFiles() {
    const list = document.getElementById('bd-file-list');
    if (!list) { console.error('bd-file-list not found'); return; }
    list.innerHTML = LOADING;

    try {
        const data = await api.sheditor({ action: 'list' });
        console.log('BD files response:', data);

        if (!data || !data.ok) {
            list.innerHTML = '<div class="text-danger text-center p-3">Error: ' + (data?.error || 'desconocido') + '</div>';
            return;
        }

        if (!data.files || data.files.length === 0) {
            list.innerHTML = '<div class="text-muted text-center p-3">Sin archivos en BD</div>';
            return;
        }

        list.innerHTML = data.files.map(f => `
            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1 px-2"
               data-path="${f.path}">
                <span class="text-truncate" style="max-width:70%">${f.path}</span>
                <small class="text-muted">${formatSize(f.size)}</small>
            </a>
        `).join('');

        list.querySelectorAll('[data-path]').forEach(el => {
            el.addEventListener('click', (e) => {
                e.preventDefault();
                loadFile(el.dataset.path);
            });
        });
    } catch (e) {
        console.error('loadBdFiles error:', e);
        list.innerHTML = '<div class="text-danger text-center p-3">Error: ' + e.message + '</div>';
    }
}

async function loadFile(path) {
    currentPath = path;
    document.getElementById('sh-path').value = path;

    const data = await api.sheditor({ action: 'read', path });
    if (!data.ok) { toast(data.error || 'Error', 'danger'); return; }

    document.getElementById('sh-content').value = data.content;
    document.getElementById('sh-md5').textContent = `MD5: ${data.md5} | ${formatSize(data.size)}`;
    document.getElementById('btn-save').disabled = false;
    document.getElementById('btn-delete-file').disabled = false;

    document.querySelectorAll('#bd-file-list .list-group-item').forEach(el => {
        el.classList.toggle('active', el.dataset.path === path);
    });
}

async function saveFile() {
    const path = document.getElementById('sh-path').value.trim();
    const content = document.getElementById('sh-content').value;
    if (!path) { toast('Path requerido', 'warning'); return; }

    const btn = document.getElementById('btn-save');
    btn.disabled = true;

    const data = await api.sheditor({ action: 'save', path, content });

    if (data.ok) {
        const verified = data.verified ? 'MD5 verificado' : 'MD5 no coincide';
        toast(`Guardado: ${formatSize(data.bytes_written)} | ${verified}`, 'success');
        document.getElementById('sh-md5').textContent = `MD5: ${data.md5} | ${formatSize(data.size)}`;
        loadBdFiles();
    } else {
        toast('Error: ' + (data.error || 'No se pudo guardar'), 'danger');
    }
    btn.disabled = false;
}

async function deleteFile() {
    const path = document.getElementById('sh-path').value.trim();
    if (!path || !confirm(`¿Eliminar ${path}?`)) return;

    const data = await api.sheditor({ action: 'delete', path });
    if (data.ok) {
        toast('Eliminado: ' + path, 'success');
        currentPath = '';
        document.getElementById('sh-path').value = '';
        document.getElementById('sh-content').value = '';
        document.getElementById('sh-md5').textContent = '';
        document.getElementById('btn-save').disabled = true;
        document.getElementById('btn-delete-file').disabled = true;
        loadBdFiles();
    } else {
        toast('Error: ' + (data.error || ''), 'danger');
    }
}

// ─── Visor de Disco (modal) ───

async function loadDisk(path = '') {
    const list = document.getElementById('disk-list');
    list.innerHTML = LOADING;

    const data = await api.sheditor({ action: 'disk_list', path });
    if (!data.ok) { list.innerHTML = '<div class="text-danger text-center p-3">Error</div>'; return; }

    let html = '';
    if (path) {
        html += `<a href="#" class="list-group-item list-group-item-action py-1 px-2" data-disk-parent>
            <svg class="icon me-1"><use href="#icon-arrow-up"></use></svg>..
        </a>`;
    }

    html += data.items.map(item => {
        const icon = item.is_dir ? 'folder' : 'file';
        const badge = item.in_db
            ? '<span class="badge bg-green-lt ms-auto">BD</span>'
            : '<span class="badge bg-red-lt ms-auto">huérfano</span>';
        let actions = '';
        if (!item.is_dir && !item.in_db) {
            actions = `
                <button class="btn btn-xs btn-outline-success ms-1" data-import="${item.path}" title="Importar">📥</button>
                <button class="btn btn-xs btn-outline-danger ms-1" data-del="${item.path}" title="Eliminar disco">🗑</button>
            `;
        }
        return `<div class="list-group-item d-flex justify-content-between align-items-center py-1 px-2"
                    data-disk-path="${item.path}" data-is-dir="${item.is_dir}">
            <svg class="icon me-2"><use href="#icon-${icon}"></use></svg>
            <span class="text-truncate flex-fill">${item.name}</span>
            ${badge}
            ${!item.is_dir ? `<small class="text-muted ms-2">${formatSize(item.size)}</small>` : ''}
            ${actions}
        </div>`;
    }).join('');

    if (data.items.length === 0) {
        html = '<div class="text-muted text-center p-3">Directorio vacío</div>';
    }

    list.innerHTML = html;

    // Navigate dirs
    list.querySelectorAll('[data-disk-path]').forEach(el => {
        if (el.dataset.isDir === 'true') {
            el.style.cursor = 'pointer';
            el.addEventListener('click', () => loadDisk(el.dataset.path));
        }
    });
    const parent = list.querySelector('[data-disk-parent]');
    if (parent) parent.addEventListener('click', () => {
        loadDisk(path.split('/').slice(0, -1).join('/'));
    });

    // Import buttons
    list.querySelectorAll('[data-import]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const result = await api.sheditor({ action: 'import', path: btn.dataset.import });
            if (result.ok) {
                toast('Importado: ' + btn.dataset.import, 'success');
                loadDisk(path);
                loadBdFiles();
            }
        });
    });

    // Delete buttons
    list.querySelectorAll('[data-del]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const p = btn.dataset.del;
            if (!confirm(`¿Eliminar ${p} del disco?`)) return;
            await api.agenteDelete('/srv/sh/' + p);
            toast('Eliminado: ' + p, 'success');
            loadDisk(path);
        });
    });
}

async function showOrphans() {
    const list = document.getElementById('disk-list');
    list.innerHTML = LOADING;

    const data = await api.sheditor({ action: 'disk_orphans' });
    if (!data.ok) return;

    const importAllBtn = document.getElementById('btn-import-all-orphans');
    importAllBtn.style.display = data.orphans.length > 0 ? '' : 'none';

    if (data.orphans.length === 0) {
        list.innerHTML = '<div class="text-success text-center p-3">✅ No hay archivos huérfanos</div>';
        return;
    }

    list.innerHTML = data.orphans.map(o => `
        <div class="list-group-item d-flex justify-content-between align-items-center py-1 px-2">
            <span class="text-truncate flex-fill">${o.path}</span>
            <small class="text-muted">${formatSize(o.size)}</small>
            <button class="btn btn-xs btn-outline-success ms-1" data-import-orphan="${o.path}">Importar</button>
            <button class="btn btn-xs btn-outline-danger ms-1" data-del-orphan="${o.path}">Eliminar</button>
        </div>
    `).join('');

    list.querySelectorAll('[data-import-orphan]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const result = await api.sheditor({ action: 'import', path: btn.dataset.importOrphan });
            if (result.ok) { toast('Importado', 'success'); showOrphans(); loadBdFiles(); }
        });
    });
    list.querySelectorAll('[data-del-orphan]').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm(`¿Eliminar ${btn.dataset.delOrphan}?`)) return;
            await api.agenteDelete('/srv/sh/' + btn.dataset.delOrphan);
            toast('Eliminado', 'success');
            showOrphans();
        });
    });
}

async function importAllOrphans() {
    const data = await api.sheditor({ action: 'disk_orphans' });
    if (!data.ok || !data.orphans.length) return;
    let imported = 0;
    for (const o of data.orphans) {
        const result = await api.sheditor({ action: 'import', path: o.path });
        if (result.ok) imported++;
    }
    toast(`Importados ${imported}/${data.orphans.length}`, 'success');
    showOrphans();
    loadBdFiles();
}

// ─── Gestión (modal) ───

async function verifyAll() {
    const container = document.getElementById('manage-results');
    container.innerHTML = LOADING;

    const data = await api.sheditor({ action: 'verify_all' });
    if (!data.ok) { container.innerHTML = '<div class="text-danger">Error</div>'; return; }

    if (data.results.length === 0) {
        container.innerHTML = '<div class="text-muted text-center p-3">Sin archivos</div>';
        return;
    }

    container.innerHTML = data.results.map(r => {
        const icon = r.match ? '✅' : (r.status === 'missing_from_disk' ? '⚠️' : '❌');
        return `<div class="list-group-item d-flex justify-content-between align-items-center py-1 px-2">
            <span>${icon} ${r.path}</span>
            <small class="text-muted">${r.status || (r.match ? 'OK' : 'NO COINCIDE')}</small>
        </div>`;
    }).join('');
}

async function rebuildAll() {
    if (!confirm('¿Reconstruir TODOS los archivos de disco desde BD?')) return;
    const container = document.getElementById('manage-results');
    container.innerHTML = LOADING;

    const data = await api.sheditor({ action: 'rebuild_all' });
    if (!data.ok) { container.innerHTML = '<div class="text-danger">Error</div>'; return; }

    toast(`Reconstruidos: ${data.written}, Fallidos: ${data.failed}`, 'success');
    container.innerHTML = data.details.map(d => {
        const icon = d.status === 'ok' ? '✅' : '❌';
        return `<div class="list-group-item py-1 px-2">${icon} ${d.path}</div>`;
    }).join('');
}

// ─── Init ───

export const shEditor = {
    init() {
        loadBdFiles();

        document.getElementById('btn-new-file').addEventListener('click', () => {
            currentPath = '';
            document.getElementById('sh-path').value = '';
            document.getElementById('sh-content').value = '#!/bin/bash\n\n';
            document.getElementById('sh-md5').textContent = 'Nuevo archivo';
            document.getElementById('btn-save').disabled = false;
            document.getElementById('btn-delete-file').disabled = true;
        });

        document.getElementById('btn-save').addEventListener('click', saveFile);
        document.getElementById('btn-delete-file').addEventListener('click', deleteFile);
        document.getElementById('btn-refresh-bd').addEventListener('click', loadBdFiles);

        // Modal events: load data on open
        document.getElementById('diskModal').addEventListener('show.bs.modal', () => loadDisk());
        document.getElementById('btn-refresh-disk').addEventListener('click', () => loadDisk());
        document.getElementById('btn-show-orphans').addEventListener('click', showOrphans);
        document.getElementById('btn-import-all-orphans').addEventListener('click', importAllOrphans);

        document.getElementById('btn-verify-all').addEventListener('click', verifyAll);
        document.getElementById('btn-rebuild-all').addEventListener('click', rebuildAll);

        // Ctrl+S
        document.getElementById('sh-content').addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === 's') { e.preventDefault(); saveFile(); }
        });
    }
};
