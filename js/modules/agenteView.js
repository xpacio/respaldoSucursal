/**
 * AgenteView - Editor de código del agente
 */
import { toast } from '../utils/toast.js';
import { formatSize } from '../utils/formatSize.js';

export class AgenteView {
    constructor() {
        this.currentPath = '';
        this.currentFile = null;
        this.originalContent = '';
        this.hasChanges = false;
    }

    async init() {
        this.bindEvents();
        await this.loadFiles('');
    }

    bindEvents() {
        // File explorer
        document.getElementById('btn-refresh')?.addEventListener('click', () => this.loadFiles(this.currentPath));
        document.getElementById('btn-new-file')?.addEventListener('click', () => this.showNewFileModal());
        document.getElementById('btn-new-dir')?.addEventListener('click', () => this.showNewDirModal());

        // Editor actions
        document.getElementById('btn-save')?.addEventListener('click', () => this.saveFile());
        document.getElementById('btn-download')?.addEventListener('click', () => this.downloadFile());
        document.getElementById('btn-upload')?.addEventListener('click', () => this.showUploadModal());
        document.getElementById('btn-delete')?.addEventListener('click', () => this.confirmDelete());

        // File content change detection
        document.getElementById('file-content')?.addEventListener('input', (e) => {
            this.hasChanges = e.target.value !== this.originalContent;
            this.updateSaveButton();
        });

        // Create file modal
        document.getElementById('btn-create-file')?.addEventListener('click', () => this.createFile());

        // Create dir modal
        document.getElementById('btn-create-dir')?.addEventListener('click', () => this.createDirectory());

        // Upload modal
        document.getElementById('btn-do-upload')?.addEventListener('click', () => this.uploadFile());

        // Confirm delete
        document.getElementById('btn-confirm-delete')?.addEventListener('click', () => this.deleteItem());

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                this.saveFile();
            }
        });

        // Warn before leaving with unsaved changes
        window.addEventListener('beforeunload', (e) => {
            if (this.hasChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    async loadFiles(path) {
        this.currentPath = path;
        document.getElementById('current-path').textContent = path ? `/${path}` : '/srv/bat/';
        document.getElementById('file-path').value = '';

        const explorer = document.getElementById('file-explorer');
        explorer.innerHTML = '<div class="p-3 px-5"><div class="progress progress-sm"><div class="progress-bar progress-bar-indeterminate" style="width:100%; background:#0d6efd"></div></div></div>';

        try {
            const response = await fetch(`/api/agente/files?path=${encodeURIComponent(path)}`);
            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.error || 'Error cargando archivos');
            }

            this.renderFileList(data.items, path);
        } catch (error) {
            explorer.innerHTML = `<div class="alert alert-danger m-3">${error.message}</div>`;
            toast('Error: ' + error.message, 'danger');
        }
    }

    renderFileList(items, parentPath) {
        const explorer = document.getElementById('file-explorer');

        if (items.length === 0) {
            explorer.innerHTML = '<div class="p-3 text-muted text-center">Carpeta vacía</div>';
            return;
        }

        let html = '';

        // Parent directory link
        if (parentPath) {
            const parent = parentPath.split('/').slice(0, -1).join('/');
            html += `
                <a href="#" class="list-group-item list-group-item-action file-item directory" data-path="${parent}" data-type="directory">
                    <svg class="icon me-2"><use href="#icon-chevron-left"></use></svg>
                    ..
                </a>
            `;
        }

        items.forEach(item => {
            const icon = item.type === 'directory' ? 'folder' : 'file';
            const size = item.type === 'file' ? formatSize(item.size) : '';
            const classes = ['list-group-item', 'list-group-item-action', 'file-item'];
            if (item.type === 'directory') classes.push('directory');
            if (item.editable) classes.push('editable');

            html += `
                <a href="#" class="${classes.join(' ')}" data-path="${item.path}" data-type="${item.type}" data-editable="${item.editable || false}">
                    <svg class="icon me-2"><use href="#icon-${icon}"></use></svg>
                    ${item.name}
                    ${size ? `<span class="text-muted ms-auto">${size}</span>` : ''}
                </a>
            `;
        });

        explorer.innerHTML = html;

        // Bind click events
        explorer.querySelectorAll('.file-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const path = item.dataset.path;
                const type = item.dataset.type;

                if (type === 'directory') {
                    this.loadFiles(path);
                } else {
                    this.selectFile(path);
                }
            });
        });
    }

    async selectFile(path) {
        // Mark as selected
        document.querySelectorAll('.file-item').forEach(el => el.classList.remove('active'));
        const selected = document.querySelector(`.file-item[data-path="${path}"]`);
        if (selected) selected.classList.add('active');

        document.getElementById('file-path').value = path;

        try {
            const response = await fetch(`/api/agente/file?path=${encodeURIComponent(path)}`);
            const data = await response.json();

            if (!data.ok) {
                if (data.is_binary) {
                    this.showBinaryFile(path, data.size);
                } else {
                    throw new Error(data.error || 'Error leyendo archivo');
                }
                return;
            }

            this.currentFile = data;
            this.originalContent = data.content;
            this.hasChanges = false;

            const textarea = document.getElementById('file-content');
            const editorArea = document.getElementById('editor-area');

            textarea.value = data.content;
            textarea.style.display = 'block';
            editorArea.style.display = 'none';

            document.getElementById('file-info').textContent = `${data.name} - ${data.size} bytes`;
            document.getElementById('editor-status').style.display = 'block';

            this.updateButtons(true, true);

        } catch (error) {
            toast('Error: ' + error.message, 'danger');
        }
    }

    showBinaryFile(path, size) {
        const editorArea = document.getElementById('editor-area');
        const textarea = document.getElementById('file-content');

        textarea.style.display = 'none';
        editorArea.style.display = 'block';
        editorArea.innerHTML = `
            <div class="text-center p-5">
                <svg class="icon mb-2" style="width: 48px; height: 48px;"><use href="#icon-file-binary"></use></svg>
                <p>Archivo binario (${formatSize(size)})</p>
                <p class="text-muted">Use el botón Descargar para obtener este archivo</p>
            </div>
        `;

        this.currentFile = { path, size, is_binary: true };
        this.updateButtons(true, false);
    }

    updateButtons(hasFile, isEditable) {
        document.getElementById('btn-save').disabled = !hasFile || !isEditable;
        document.getElementById('btn-download').disabled = !hasFile;
        document.getElementById('btn-delete').disabled = !hasFile;
    }

    updateSaveButton() {
        const btn = document.getElementById('btn-save');
        if (btn) {
            btn.classList.toggle('btn-primary', this.hasChanges);
            btn.classList.toggle('btn-outline-primary', !this.hasChanges);
        }
    }

    async saveFile() {
        if (!this.currentFile || !this.currentFile.editable) return;

        const content = document.getElementById('file-content').value;

        try {
            const response = await fetch('/api/agente/file/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    path: this.currentFile.path,
                    content: content
                })
            });

            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.error || 'Error guardando archivo');
            }

            this.originalContent = content;
            this.hasChanges = false;
            this.updateSaveButton();
            toast('Guardado: Archivo guardado exitosamente', 'success');

        } catch (error) {
            toast('Error: ' + error.message, 'danger');
        }
    }

    downloadFile() {
        if (!this.currentFile) return;
        window.location.href = `/api/agente/download?path=${encodeURIComponent(this.currentFile.path)}`;
    }

    showNewFileModal() {
        const modal = new bootstrap.Modal(document.getElementById('newFileModal'));
        document.getElementById('new-file-name').value = '';
        modal.show();
    }

    showNewDirModal() {
        const modal = new bootstrap.Modal(document.getElementById('newDirModal'));
        document.getElementById('new-dir-name').value = '';
        modal.show();
    }

    async createFile() {
        const name = document.getElementById('new-file-name').value.trim();
        if (!name) return;

        const path = this.currentPath ? `${this.currentPath}/${name}` : name;

        try {
            const response = await fetch('/api/agente/file/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path, content: '' })
            });

            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.error || 'Error creando archivo');
            }

            bootstrap.Modal.getInstance(document.getElementById('newFileModal')).hide();
            await this.loadFiles(this.currentPath);
            toast('Creado: Archivo creado exitosamente', 'success');

        } catch (error) {
            toast('Error: ' + error.message, 'danger');
        }
    }

    async createDirectory() {
        const name = document.getElementById('new-dir-name').value.trim();
        if (!name) return;

        const path = this.currentPath ? `${this.currentPath}/${name}` : name;

        try {
            const response = await fetch('/api/agente/dir/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path })
            });

            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.error || 'Error creando carpeta');
            }

            bootstrap.Modal.getInstance(document.getElementById('newDirModal')).hide();
            await this.loadFiles(this.currentPath);
            toast('Creado: Carpeta creada exitosamente', 'success');

        } catch (error) {
            toast('Error: ' + error.message, 'danger');
        }
    }

    confirmDelete() {
        if (!this.currentFile) return;

        document.getElementById('delete-item-name').textContent = this.currentFile.name || this.currentFile.path.split('/').pop();
        const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        modal.show();
    }

    async deleteItem() {
        if (!this.currentFile) return;

        try {
            const response = await fetch('/api/agente/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path: this.currentFile.path })
            });

            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.error || 'Error eliminando');
            }

            bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal')).hide();

            // Clear editor
            this.currentFile = null;
            document.getElementById('file-content').style.display = 'none';
            document.getElementById('editor-area').style.display = 'block';
            document.getElementById('editor-status').style.display = 'none';
            document.getElementById('editor-area').innerHTML = `
                <div class="text-center p-5 text-muted">
                    <svg class="icon mb-2" style="width: 48px; height: 48px;"><use href="#icon-file"></use></svg>
                    <p>Seleccione un archivo para editar</p>
                </div>
            `;

            this.updateButtons(false, false);

            await this.loadFiles(this.currentPath);
            toast('Eliminado: Elemento eliminado exitosamente', 'success');

        } catch (error) {
            toast('Error: ' + error.message, 'danger');
        }
    }

    showUploadModal() {
        const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
        document.getElementById('upload-path').value = '/' + this.currentPath;
        document.getElementById('upload-file').value = '';
        modal.show();
    }

    async uploadFile() {
        const fileInput = document.getElementById('upload-file');
        const file = fileInput.files[0];

        if (!file) {
            toast('Seleccione un archivo', 'danger');
            return;
        }

        const path = this.currentPath ? `${this.currentPath}/${file.name}` : file.name;
        const formData = new FormData();
        formData.append('file', file);
        formData.append('path', path);

        try {
            const response = await fetch('/api/agente/upload', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.error || 'Error subiendo archivo');
            }

            bootstrap.Modal.getInstance(document.getElementById('uploadModal')).hide();
            await this.loadFiles(this.currentPath);
            toast('Subido: Archivo subido exitosamente', 'success');

        } catch (error) {
            toast('Error: ' + error.message, 'danger');
        }
    }
}
