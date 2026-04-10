function showToast(message, type = 'success') {
    const existingContainer = document.getElementById('toast-container');
    const container = existingContainer || (() => {
        const node = document.createElement('div');
        node.id = 'toast-container';
        node.style.position = 'fixed';
        node.style.right = '20px';
        node.style.bottom = '20px';
        node.style.display = 'grid';
        node.style.gap = '10px';
        node.style.zIndex = '9999';
        document.body.appendChild(node);
        return node;
    })();

    const colors = {
        success: ['#1F7A43', 'rgba(31,122,67,0.12)', '#1A3D26'],
        error: ['#9D2F2F', 'rgba(157,47,47,0.12)', '#5B1D1D'],
        warning: ['#9B6700', 'rgba(155,103,0,0.12)', '#5A3D07'],
    };

    const selected = colors[type] || colors.success;
    const toast = document.createElement('div');
    toast.textContent = message;
    toast.style.minWidth = '260px';
    toast.style.maxWidth = '360px';
    toast.style.padding = '12px 14px';
    toast.style.borderRadius = '10px';
    toast.style.border = `1px solid ${selected[0]}`;
    toast.style.background = selected[1];
    toast.style.color = selected[2];
    toast.style.fontWeight = '600';
    toast.style.boxShadow = '0 10px 24px rgba(56, 44, 25, 0.12)';
    toast.style.transform = 'translateY(16px)';
    toast.style.opacity = '0';
    toast.style.transition = 'transform 0.2s ease, opacity 0.2s ease';
    container.appendChild(toast);

    requestAnimationFrame(() => {
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';
    });

    setTimeout(() => {
        toast.style.transform = 'translateY(16px)';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 220);
    }, 3000);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-countup]').forEach((element) => {
        const target = Number(element.getAttribute('data-countup') || '0');
        const duration = 600;
        const start = performance.now();

        const tick = (timestamp) => {
            const progress = Math.min((timestamp - start) / duration, 1);
            element.textContent = Math.floor(progress * target).toString();

            if (progress < 1) {
                window.requestAnimationFrame(tick);
            } else {
                element.textContent = target.toString();
            }
        };

        window.requestAnimationFrame(tick);
    });
});
