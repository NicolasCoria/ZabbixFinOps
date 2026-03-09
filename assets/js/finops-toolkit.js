/**
 * Zabbix FinOps Toolkit - Neo-Brutalist Edition
 * Minimal, technical interactivity
 */

(function() {
    'use strict';

    /**
     * Table keyboard navigation
     */
    function initKeyboardNav() {
        const table = document.querySelector('.finops-table');
        if (!table) return;

        const rows = table.querySelectorAll('tbody tr');
        let currentRow = -1;

        table.setAttribute('tabindex', '0');

        table.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentRow = Math.min(currentRow + 1, rows.length - 1);
                focusRow(rows[currentRow]);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentRow = Math.max(currentRow - 1, 0);
                focusRow(rows[currentRow]);
            }
        });

        function focusRow(row) {
            rows.forEach(r => r.style.outline = 'none');
            if (row) {
                row.style.outline = '2px solid #dc2626';
                row.style.outlineOffset = '-2px';
                row.scrollIntoView({ block: 'nearest' });
            }
        }
    }

    /**
     * Sort feedback - simple visual only
     */
    function initSortFeedback() {
        document.querySelectorAll('.finops-table th a').forEach(link => {
            link.addEventListener('click', function() {
                this.style.opacity = '0.5';
            });
        });
    }

    /**
     * Export button state
     */
    function initExportBtn() {
        const btn = document.querySelector('.finops-btn-accent');
        if (!btn) return;

        btn.addEventListener('click', function() {
            const text = this.textContent;
            this.textContent = '...';
            setTimeout(() => {
                this.textContent = text;
            }, 1500);
        });
    }

    /**
     * Initialize
     */
    function init() {
        if (!document.querySelector('.finops-container')) {
            return;
        }

        initKeyboardNav();
        initSortFeedback();
        initExportBtn();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
