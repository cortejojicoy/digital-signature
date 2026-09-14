/**
 * Entry point for the on-demand PDF viewer bundle.
 *
 * Kept out of the main plugin bundle on purpose. pdf.js is by far the largest
 * dependency here, and the launcher renders on *every* page of a panel — most
 * of which will never open a document. The main bundle injects this script the
 * first time a viewer actually mounts, and never otherwise.
 */
import { mountViewer, unmountViewer } from './react/PdfViewerIsland.jsx';

window.DsigPdfViewer = { mount: mountViewer, unmount: unmountViewer };
window.dispatchEvent(new CustomEvent('dsig:pdf-viewer-ready'));
