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
        success: ['#4ADE80', 'rgba(74,222,128,0.12)'],
        error: ['#F87171', 'rgba(248,113,113,0.12)'],
        warning: ['#FBBF24', 'rgba(251,191,36,0.12)'],
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
    toast.style.color = '#FAFAFA';
    toast.style.boxShadow = '0 8px 24px rgba(0,0,0,0.25)';
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
