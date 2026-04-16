import { getFingerprint } from '../utils/machineFingerprint.js';

export default function signatureField({ initialTab, fieldId, showDraw, fpEndpoint }) {
    let deviceFingerprint = '';
    getFingerprint().then(fp => {
        deviceFingerprint = fp;
        window.__sigDeviceFp = fp;
        if (fpEndpoint) {
            fetch(fpEndpoint, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                },
                body: JSON.stringify({ fp }),
            }).catch(() => {});
        }
    });

    return {
        // ── State ─────────────────────────────────────────────────────────────
        activeTab:     initialTab ?? (showDraw ? 'draw' : 'upload'),
        value:         '',
        source:        initialTab === 'upload' ? 'upload' : 'draw',
        isDirty:       false,
        uploadPreview: null,   // data URL of the selected file (for preview)
        uploadError:   null,
        uploadLoading: false,

        getDeviceFingerprint() { return deviceFingerprint; },

        // ── Tab switch ────────────────────────────────────────────────────────
        switchTab(tab) {
            if (this.activeTab === tab) return;
            this.activeTab = tab;
            this.source    = tab;
            if (!this.isDirty) {
                this.value         = '';
                this.uploadPreview = null;
                this.uploadError   = null;
            }
        },

        // ── Bridge: React → Alpine (draw tab) ─────────────────────────────────
        onExported({ png, fieldId: eventFieldId }) {
            if (eventFieldId && eventFieldId !== fieldId) return;
            this.value   = png;
            this.source  = 'draw';
            this.isDirty = true;
        },

        // ── Upload tab ────────────────────────────────────────────────────────
        onFileChange(event) {
            const file = event.target.files?.[0];
            if (!file) return;

            this.uploadError   = null;
            this.uploadLoading = true;

            const allowedTypes = ['image/png', 'image/jpeg'];
            const maxBytes     = (window.__signatureConfig?.maxKb ?? 512) * 1024;

            if (!allowedTypes.includes(file.type)) {
                this.uploadError   = 'Only PNG and JPG files are allowed.';
                this.uploadLoading = false;
                return;
            }
            if (file.size > maxBytes) {
                this.uploadError   = `File must be smaller than ${maxBytes / 1024} KB.`;
                this.uploadLoading = false;
                return;
            }

            // Read file as data URL (avoids blob URL async issues)
            const reader = new FileReader();
            reader.onerror = () => {
                this.uploadError   = 'Failed to read file.';
                this.uploadLoading = false;
            };
            reader.onload = (e) => {
                const dataUrl = e.target.result;

                // Normalise to PNG via canvas (handles JPEG input too)
                const img    = new Image();
                img.onerror  = () => {
                    this.uploadError   = 'Could not load image. Try another file.';
                    this.uploadLoading = false;
                };
                img.onload   = () => {
                    const canvas  = document.createElement('canvas');
                    canvas.width  = img.naturalWidth  || 600;
                    canvas.height = img.naturalHeight || 200;
                    canvas.getContext('2d').drawImage(img, 0, 0);
                    const png = canvas.toDataURL('image/png');

                    this.uploadPreview = png;   // show thumbnail
                    this.value         = png;   // auto-confirm — no extra button click
                    this.source        = 'upload';
                    this.isDirty       = true;
                    this.uploadLoading = false;
                };
                img.src = dataUrl;
            };
            reader.readAsDataURL(file);

            // Reset input so the same file can be re-selected
            event.target.value = '';
        },

        // ── Clear ─────────────────────────────────────────────────────────────
        clear() {
            this.value         = null;
            this.isDirty       = false;
            this.uploadPreview = null;
            this.uploadError   = null;
            this.source        = this.activeTab;
            window.dispatchEvent(new CustomEvent('sig:clear', { detail: { fieldId } }));
        },

        destroy() {},
    };
}
