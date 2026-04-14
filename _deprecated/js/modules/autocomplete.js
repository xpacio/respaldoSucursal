import { debounce } from '../utils/debounce.js';
import { api } from '../apiService.js';

export class AutocompleteInput {
    constructor(inputId, { table, column, onSelect = null, minChars = 1 }) {
        this.input = document.getElementById(inputId);
        this.table = table;
        this.column = column;
        this.onSelect = onSelect;
        this.minChars = minChars;
        this.abortController = null;

        if (!this.input) { console.warn('AutocompleteInput: input not found:', inputId); return; }

        this.input.setAttribute('autocomplete', 'off');
        this.input.setAttribute('list', `datalist-${inputId}`);
        this.datalist = this.createDatalist(`datalist-${inputId}`);
        this.spinner = this.createSpinner();

        this.fetch = debounce(this.fetch.bind(this), 250);

        this.input.addEventListener('input', () => this.onInput());
        this.input.addEventListener('keydown', (e) => this.onKeydown(e));
    }

    createDatalist(id) {
        const dl = document.createElement('datalist');
        dl.id = id;
        document.body.appendChild(dl);
        return dl;
    }

    createSpinner() {
        const wrapper = this.input.closest('.position-relative') || this.input.parentNode;
        const iconWrapper = document.createElement('span');
        iconWrapper.className = 'input-icon-addon';
        iconWrapper.style.display = 'none';
        iconWrapper.innerHTML = '<div class="spinner-border spinner-border-sm text-secondary" role="status"></div>';
        wrapper.style.position = 'relative';
        wrapper.appendChild(iconWrapper);
        return iconWrapper;
    }

    onInput() {
        const q = this.input.value.trim();
        if (q.length < this.minChars) {
            this.datalist.innerHTML = '';
            return;
        }
        this.fetch();
    }

    async fetch() {
        const q = this.input.value.trim();
        if (this.abortController) this.abortController.abort();
        this.abortController = new AbortController();

        this.spinner.style.display = '';
        
        try {
            const data = await api.distinct(this.table, this.column, q);
            if (!data.ok || !data.values) return;
            this.render(data.values);
        } catch (e) {
            if (e.name !== 'AbortError') {
                console.error('AutocompleteInput fetch error:', e);
            }
        } finally {
            this.spinner.style.display = 'none';
        }
    }

    render(values) {
        this.datalist.innerHTML = values.map(v =>
            `<option value="${v.replace(/"/g, '&quot;')}">`
        ).join('');

        if (values.length === 1 && values[0] === this.input.value) {
            this.input.dispatchEvent(new Event('change'));
        }
    }

    onKeydown(e) {
        if (e.key === 'Escape') {
            this.datalist.innerHTML = '';
        }
    }
}
