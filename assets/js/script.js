function showToast(message) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerText = message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 500);
    }, 3000);
}

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