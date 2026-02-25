// Auto-focus logic for barcode scanners
// Barcode scanners act like keyboards. They usually append an 'Enter' key at the end.
document.addEventListener('DOMContentLoaded', function() {
    const barcodeInput = document.querySelector('input[name="barcode_input"]');
    if(barcodeInput) {
        barcodeInput.focus();
        // If user clicks away, refocusing helps if using a dedicated scanner
        barcodeInput.addEventListener('blur', () => {
            setTimeout(() => barcodeInput.focus(), 100);
        });
    }
});