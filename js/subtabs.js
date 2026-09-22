function initSubtabs() {
    document.querySelectorAll('.subtab-link').forEach(link => {
        link.addEventListener('click', () => {
            const moduleKey = link.getAttribute('data-module');
            const subtab = link.getAttribute('data-subtab');

            document.querySelectorAll(`.subtab-link[data-module="${moduleKey}"]`).forEach(l => {
                const active = l === link;
                l.classList.toggle('bg-blue-600', active);
                l.classList.toggle('dark:bg-slate-800', active);
                l.classList.toggle('text-white', active);
                l.classList.toggle('border', active);
                l.classList.toggle('border-blue-600', active);
                l.classList.toggle('dark:border-white/10', active);
                l.classList.toggle('shadow-sm', active);
                l.classList.toggle('text-slate-500', !active);
                l.classList.toggle('dark:text-slate-400', !active);
            });

            document.querySelectorAll(`.subtab-panel[data-module="${moduleKey}"]`).forEach(panel => {
                panel.classList.toggle('hidden', panel.getAttribute('data-subtab') !== subtab);
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', initSubtabs);

function goToSubtab(moduleKey, subtab) {
    const link = document.querySelector(`.subtab-link[data-module="${moduleKey}"][data-subtab="${subtab}"]`);
    if (!link) return;

    link.click();

    const panel = document.querySelector(`.subtab-panel[data-module="${moduleKey}"][data-subtab="${subtab}"]`);
    if (panel) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}