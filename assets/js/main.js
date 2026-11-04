(() => {
    document.querySelectorAll('[data-print-page]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    document.querySelectorAll('select[name="result"]').forEach((resultSelect) => {
        const conditionSelect = resultSelect.form?.querySelector('[data-required-when-found]');
        if (!conditionSelect) {
            return;
        }

        const updateConditionRequirement = () => {
            conditionSelect.required = resultSelect.value === 'Found';
        };
        resultSelect.addEventListener('change', updateConditionRequirement);
        updateConditionRequirement();
    });

    const menuButton = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');

    if (!menuButton || !sidebar) {
        return;
    }

    menuButton.addEventListener('click', () => {
        const isOpen = sidebar.classList.toggle('is-open');
        menuButton.setAttribute('aria-expanded', String(isOpen));
    });
})();
