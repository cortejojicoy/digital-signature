export default function signatureField({ initialTab, fieldId, showDraw, showUpload }) {
    return {
        // ── State ─────────────────────────────────────────────────────────────
        activeTab:     initialTab ?? (showDraw ? 'draw' : 'upload'),
        value:         '',        // base64 PNG — written to hidden input
        source:        initialTab === 'upload' ? 'upload' : 'draw',
        isDirty:       false,
        uploadPreview: null,      // object URL for upload tab preview
        uploadError:   null,

        // ── React island lifecycle ────────────────────────────────────────────

        /**
         * Switch active tab.
         * When switching away from 'draw', React unmounts automatically because
         * its mount point is removed from the DOM via x-show (x-cloak).
         * When switching back to 'draw', the mount point re-appears and
         * SignaturePadIsland.jsx re-mounts via its MutationObserver bootstrap.
         */
        switchTab(tab) {
            if (this.activeTab === tab) return;
            this.activeTab = tab;
            this.source    = tab;

            // Reset dirty state when user manually switches tabs
            if (!this.isDirty) {
                this.value         = '';
                this.uploadPreview = null;
                this.uploadError   = null;
            }
        },

        // ── Bridge: React → Alpine ────────────────────────────────────────────

        /**
         * Called when React fires window.dispatchEvent('sig:exported', { png, fieldId }).
         * Only accept events targeted at this field instance.
         */
        onExported({ png, fieldId: eventFieldId }) {
            if (eventFieldId && eventFieldId !== fieldId) return;
            this.value   = png;
            this.source  = 'draw';
            this.isDirty = true;
        },

        // ── Upload tab handlers ───────────────────────────────────────────────

        onFileChange(event) {
            const file = event.target.files?.[0];
            if (!file) return;

            this.uploadError = null;

            // Client-side validation
            const allowedTypes = ['image/png', 'image/jpeg'];
            const maxBytes     = (window.__signatureConfig?.maxKb ?? 512) * 1024;

            if (!allowedTypes.includes(file.type)) {
                this.uploadError = 'Only PNG and JPG files are allowed.';
                return;
            }
            if (file.size > maxBytes) {
                this.uploadError = `File must be smaller than ${maxBytes / 1024}KB.`;
                return;
            }

            // Revoke previous object URL to prevent memory leak
            if (this.uploadPreview) URL.revokeObjectURL(this.uploadPreview);

            this.uploadPreview = URL.createObjectURL(file);
            this.isDirty       = false; // require explicit confirm
        },

        confirmUpload() {
            if (!this.uploadPreview) return;

            // Convert object URL → base64 so the hidden input has a uniform format
            const img    = new Image();
            img.onload   = () => {
                const canvas  = document.createElement('canvas');
                canvas.width  = img.naturalWidth;
                canvas.height = img.naturalHeight;
                canvas.getContext('2d').drawImage(img, 0, 0);
                this.value   = canvas.toDataURL('image/png');
                this.source  = 'upload';
                this.isDirty = true;
            };
            img.src = this.uploadPreview;
        },

        // ── Clear ─────────────────────────────────────────────────────────────

        clear() {
            this.value         = '';
            this.isDirty       = false;
            this.uploadPreview = null;
            this.uploadError   = null;
            this.source        = this.activeTab;

            // Tell React to clear its canvas too
            window.dispatchEvent(new CustomEvent('sig:clear', { detail: { fieldId } }));
        },

        // ── Cleanup ───────────────────────────────────────────────────────────

        destroy() {
            if (this.uploadPreview) URL.revokeObjectURL(this.uploadPreview);
        },
    };
}