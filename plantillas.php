<?php
// Plantillas Overlay page
$pageTitle = 'GIS - Plantillas Overlay';
$activePage = 'plantillas';
$pageHeader = 'Plantillas Overlay';
$pagePretitle = 'Gestión';

require_once __DIR__ . '/ui/header.php';
?>

<!-- Page Content -->
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <h3 class="card-title">Plantillas existentes</h3>
        <button class="btn btn-primary d-none d-sm-inline-block" data-bs-toggle="modal"
            data-bs-target="#plantillaModal">
            <svg class="icon me-1">
                <use href="#icon-plus"></use>
            </svg>
            Nueva Plantilla
        </button>
    </div>
    <div class="card-table table-responsive">
        <table class="table table-vcenter" id="plantillas-table">
            <thead>
                <tr>
                    <th>Origen</th>
                    <th>Destino</th>
                    <th>Modo</th>
                    <th>Auto</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="plantillas-tbody">
            </tbody>
        </table>
    </div>
</div>

<!-- Modal for Create/Edit Plantilla -->
<div class="modal fade" id="plantillaModal" tabindex="-1">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="plantillaModalTitle">Nueva Plantilla</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="plantillaForm">
                    <input type="hidden" id="edit-id" value="">
                    <div class="mb-3">
                        <label class="form-label">Destino</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="plantilla-dst" placeholder="/mnt/overlay" required autocomplete="off">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Origen (Host)</label>
                        <input type="text" class="form-control" id="plantilla-src" placeholder="/tmp/{rbfid}/{emp}/{plaza}" required>
                    </div>
                    <div class="row">
                        <div class="mb-3 col-6">
                            <label class="form-label">Modo</label>
                            <div class="form-selectgroup">
                                <label class="form-selectgroup-item">
                                    <input type="radio" name="plantilla-mode" value="ro" class="form-selectgroup-input" checked>
                                    <span class="form-selectgroup-label">RO</span>
                                </label>
                                <label class="form-selectgroup-item">
                                    <input type="radio" name="plantilla-mode" value="rw" class="form-selectgroup-input">
                                    <span class="form-selectgroup-label">RW</span>
                                </label>
                            </div>
                        </div>
                        <div class="mb-3 col-6">
                            <label class="form-label">Permisos de Destino</label>
                            <div class="form-selectgroup">
                                <label class="form-selectgroup-item">
                                    <input type="radio" name="plantilla-dst-perms" value="exclusive" class="form-selectgroup-input" checked>
                                    <span class="form-selectgroup-label" title="Dueño: rwx, Grupo: r-x, Otros: ---">Exclusivo</span>
                                </label>
                                <label class="form-selectgroup-item">
                                    <input type="radio" name="plantilla-dst-perms" value="group" class="form-selectgroup-input">
                                    <span class="form-selectgroup-label" title="Dueño: rwx, Grupo: rwx, Otros: ---">Grupo</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="plantilla-auto" checked>
                            <span class="form-check-label">Auto-mount (aplicar automáticamente a nuevos clientes)</span>
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="save-plantilla-btn" form="plantillaForm">Guardar</button>
            </div>
        </div>
    </div>
</div>

<script type="module">
    import { api } from './js/apiService.js';
    import { ui } from './js/ui.js';
    import { AutocompleteInput } from './js/modules/autocomplete.js';

    new AutocompleteInput('plantilla-dst', { table: 'overlays', column: 'overlay_dst' });

    // Event delegation for action buttons
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;

        const action = btn.dataset.action;
        const id = btn.dataset.id;
        const row = btn.closest('tr');

        if (action === 'delete-plantilla' && id && row) {
            row.classList.add('bg-yellow-lt');
            await eliminarPlantilla(id, row);
        }
    });

    async function loadPlantillas() {
        const result = await api.getPlantillas();
        const tbody = document.getElementById('plantillas-tbody');
        tbody.innerHTML = '';

        if (result.error) {
            ui.showErrorToast(result.error);
            return;
        }

        const plantillas = result.plantillas || [];
        if (plantillas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No hay plantillas definidas</td></tr>';
            return;
        }

        plantillas.forEach(p => {
            const tr = document.createElement('tr');
            tr.dataset.id = p.id;

            const safeSrc = String(p.overlay_src || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            const safeDst = String(p.overlay_dst || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            const safeMode = String(p.mode || 'ro');
            const safeAuto = p.auto_mount ? 'true' : 'false';
            const safeId = String(p.id || '');
            const safePerms = String(p.dst_perms || 'exclusive');

            const modeLabel = safeMode === 'rw' ? 'RW' : 'RO';
            const permsLabel = safePerms === 'exclusive' ? 'E' : 'G';
            const permsTitle = safePerms === 'exclusive' ? 'Exclusivo' : 'Grupo';
            const modeBadge = `${modeLabel}-${permsLabel}`;

            tr.innerHTML = `
            <td><code class="text-xs">${safeSrc}</code></td>
            <td>${safeDst}</td>
            <td><span class="badge ${safeMode === 'rw' ? 'bg-pink-lt' : 'bg-teal-lt'}" title="${permsTitle}">${modeBadge}</span></td>
            <td class="text-center">${p.auto_mount ? '<svg class="icon text-green"><use href="#icon-circle-check-filled"></use></svg>' : '<svg class="icon text-muted"><use href="#icon-circle-check"></use></svg>'}</td>
            <td class="text-center">
                <div class="btn-actions">
                    <button class="btn btn-action bg-blue-lt mx-2" data-bs-toggle="modal" data-bs-target="#plantillaModal" data-id="${safeId}" data-src="${safeSrc}" data-dst="${safeDst}" data-mode="${safeMode}" data-auto="${safeAuto}" data-perms="${safePerms}" title="Editar">
                        <svg class="icon"><use href="#icon-pencil"></use></svg>
                    </button>
                    <button class="btn btn-action bg-red-lt" data-action="delete-plantilla" data-id="${safeId}" title="Eliminar">
                        <svg class="icon"><use href="#icon-trash"></use></svg>
                    </button>
                </div>
            </td>
        `;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('plantillaModal').addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const id = button?.dataset?.id;

        // Track editing row
        window.plantillaEditId = null;

        const modalTitle = document.getElementById('plantillaModalTitle');
        const editId = document.getElementById('edit-id');
        const srcInput = document.getElementById('plantilla-src');
        const dstInput = document.getElementById('plantilla-dst');
        const modeRo = document.querySelector('input[name="plantilla-mode"][value="ro"]');
        const modeRw = document.querySelector('input[name="plantilla-mode"][value="rw"]');
        const permsInputs = document.querySelectorAll('input[name="plantilla-dst-perms"]');
        const autoCheck = document.getElementById('plantilla-auto');
        const saveBtn = document.getElementById('save-plantilla-btn');

        if (id) {
            modalTitle.textContent = 'Editar Plantilla';
            editId.value = id;
            srcInput.value = button.dataset.src;
            dstInput.value = button.dataset.dst;
            if (button.dataset.mode === 'rw') {
                modeRw.checked = true;
            } else {
                modeRo.checked = true;
            }
            
            const currentPerms = button.dataset.perms || 'exclusive';
            permsInputs.forEach(input => {
                if (input.value === currentPerms) input.checked = true;
            });

            autoCheck.checked = button.dataset.auto === 'true';
            saveBtn.textContent = 'Actualizar';
        } else {
            modalTitle.textContent = 'Nueva Plantilla';
            editId.value = '';
            srcInput.value = '';
            dstInput.value = '';
            modeRo.checked = true;
            permsInputs[0].checked = true; // default
            autoCheck.checked = true;
            saveBtn.textContent = 'Guardar';
        }
    });

    document.getElementById('plantillaForm').addEventListener('submit', async function (e) {
        e.preventDefault();

        const id = document.getElementById('edit-id').value;
        const src = document.getElementById('plantilla-src').value;
        const dst = document.getElementById('plantilla-dst').value;
        const mode = document.querySelector('input[name="plantilla-mode"]:checked').value;
        const perms = document.querySelector('input[name="plantilla-dst-perms"]:checked').value;
        const auto = document.getElementById('plantilla-auto').checked;

        if (!src || !dst) {
            ui.showErrorToast('Completa todos los campos');
            return;
        }

        try {
            await api.logUIAction(id ? 'editar-plantilla' : 'crear-plantilla', {
                id: id || 'nuevo',
                src: src,
                dst: dst,
                mode: mode,
                perms: perms,
                auto: auto
            });
        } catch (e) {
            console.warn('No se pudo registrar la acción:', e);
        }

        let result;
        try {
            if (id) {
                result = await api.actualizarPlantilla(id, src, dst, mode, perms, auto);
            } else {
                result = await api.crearPlantilla(src, dst, mode, perms, auto);
            }

            if (result.error) {
                // Find row and mark as error, then close modal
                if (id) {
                    const row = document.querySelector(`tr[data-id="${id}"]`);
                    if (row) {
                        row.classList.add('bg-pink-lt');
                    }
                }
                document.querySelector('#plantillaModal .btn-close').click();
                ui.showErrorToast(result.error);
            } else {
                document.querySelector('#plantillaModal .btn-close').click();
                ui.showSuccess(id ? 'Plantilla actualizada' : 'Plantilla creada');
                loadPlantillas();
            }
        } catch (e) {
            ui.showErrorToast(e.message);
        }
    });

    async function eliminarPlantilla(id, row) {
        if (!confirm('¿Eliminar plantilla?')) {
            if (row) row.classList.remove('bg-yellow-lt');
            return;
        }

        try {
            await api.logUIAction('eliminar-plantilla', { id: id });
        } catch (e) {
            console.warn('No se pudo registrar la acción:', e);
        }

        try {
            const result = await api.eliminarPlantilla(id);
            if (result.error) {
                if (row) {
                    row.classList.remove('bg-yellow-lt');
                    row.classList.add('bg-pink-lt');
                }
                ui.showErrorToast(result.error);
            } else {
                if (row) {
                    row.classList.remove('bg-yellow-lt');
                    row.classList.add('bg-lime-lt');
                }
                ui.showSuccess('Plantilla eliminada');
                setTimeout(() => loadPlantillas(), 500);
            }
        } catch (e) {
            if (row) {
                row.classList.remove('bg-yellow-lt');
                row.classList.add('bg-pink-lt');
            }
            ui.showErrorToast(e.message);
        }
    }

    function init() {
        loadPlantillas();
    }

    document.addEventListener('DOMContentLoaded', init);
</script>

<?php require_once __DIR__ . '/ui/footer.php'; ?>