export default function signaturePreview({ imageUrl, status }) {
    return {
        imageUrl,
        status,
        revealed: false,   // flips to true on <img> @load → triggers fade-in

        init() {
            // If the URL is already cached the load event fires synchronously —
            // ensure revealed is set after Alpine finishes mounting.
            this.$nextTick(() => {
                if (!this.imageUrl) this.revealed = true;
            });
        },
    };
}