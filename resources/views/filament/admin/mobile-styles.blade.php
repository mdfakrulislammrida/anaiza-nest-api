{{--
    The admin on a phone. Everything here sits inside max-width media queries, so a screen 768 px wide or more is exactly as it was.

    Like compact-styles.blade.php it is an inline <style> through a render hook (AdminPanelProvider): there is no Node build on the server, no extra
    stylesheet file and no `php artisan filament:assets`. A change here needs only `git pull` and `php artisan optimize:clear`.

    What it does below 768 px:
      - undoes the compact 87.5% root size (so text and touch targets are normal) and keeps form fields at 16 px (iOS zooms the page into anything smaller);
      - draws a list's rows as cards (the card column comes from App\Filament\Support\MobileCards), with the table header and bulk-select boxes out of the way;
      - makes buttons, menu items and row links at least 44 px tall;
      - stacks the page header, wraps long words, and keeps dialogs, dropdowns and the sidebar inside the screen.
--}}
<style>
    /* The phone card and its parts exist in the markup at every width; only a phone shows them (the table's own columns show from md up). */
    .am-card { display: none; }

    /*
        Filament's own `visibleFrom('md')` and `hiddenFrom('md')` write the classes `hidden md:table-cell` and `md:hidden` on a column. Its stylesheet
        is built ahead of time and only holds the classes Filament itself uses, so `md:table-cell` is not in it: without these two lines a column made
        visible from md up would stay hidden on a desktop too.
    */
    @media (min-width: 768px) {
        .fi-ta-table .md\:table-cell { display: table-cell !important; }
        .fi-ta-table .md\:hidden { display: none !important; }
    }

    @media (max-width: 767.98px) {
        .fi-ta-table td.hidden, .fi-ta-table th.hidden { display: none !important; }
    }

    @media (max-width: 767.98px) {
        html { font-size: 100%; }

        /* iOS Safari zooms the page into any field under 16 px. */
        input:not([type='checkbox']):not([type='radio']):not([type='range']),
        select,
        textarea,
        .fi-fo-field-wrp input,
        .choices__inner,
        .fi-input { font-size: 16px !important; }

        /* ---- nothing may push the page wider than the screen */
        .fi-main, .fi-page, .fi-page-content, .fi-resource-list-records-page, .fi-section, .fi-fo-field-wrp { min-width: 0; max-width: 100%; }
        .fi-page-header-main-ctn { padding-top: .75rem; }
        .fi-header { flex-direction: column; align-items: stretch; gap: .75rem; }
        .fi-header-heading { font-size: 1.375rem; line-height: 1.75rem; overflow-wrap: anywhere; }
        .fi-header-actions-ctn { flex-wrap: wrap; justify-content: flex-start; width: 100%; }
        .fi-main { padding-left: .75rem; padding-right: .75rem; }
        .fi-topbar nav { padding-left: .75rem; padding-right: .75rem; gap: .5rem; }
        .fi-breadcrumbs { overflow-wrap: anywhere; }

        /* ---- touch targets: at least 44 px */
        .fi-btn, .fi-icon-btn, .fi-dropdown-list-item, .fi-ta-actions .fi-link, .fi-ta-record a.fi-link,
        .fi-sidebar-item-button, .fi-sidebar-group-button, .fi-tabs-item, .fi-pagination-item-button, .fi-ta-header-cell-sort-btn { min-height: 2.75rem; }
        .fi-icon-btn { min-width: 2.75rem; }
        .fi-btn { padding-top: .5rem; padding-bottom: .5rem; }
        .fi-checkbox-input, .fi-fo-toggle { min-width: 1.5rem; min-height: 1.5rem; }
        .fi-fo-checkbox-list label, .fi-fo-radio label, .fi-fo-toggle-buttons label { min-height: 2.75rem; display: flex; align-items: center; }

        /* ---- header actions: the buttons fill the width, two to a line at most */
        .fi-header-actions-ctn > * { flex: 1 1 calc(50% - .5rem); }
        .fi-header-actions-ctn .fi-btn { width: 100%; justify-content: center; }

        /* ---- lists: a row is a card */
        .fi-ta-content { overflow-x: visible; }
        .fi-ta-table { display: block; width: 100%; table-layout: auto; }
        .fi-ta-table thead { display: none; }
        .fi-ta-table tbody { display: grid; gap: .5rem; padding: .5rem; width: 100%; }
        .fi-ta-table tbody tr.fi-ta-row { display: block; position: relative; width: 100%; border: 1px solid rgb(var(--gray-200)); border-radius: .75rem; background: #fff; overflow: hidden; }
        .dark .fi-ta-table tbody tr.fi-ta-row { border-color: rgb(255 255 255 / .1); background: rgb(255 255 255 / .03); }
        .fi-ta-table tbody tr.fi-ta-row > td { display: block; width: 100%; }
        .fi-ta-table tbody tr.fi-ta-row > td.fi-ta-selection-cell { display: none !important; }
        .fi-ta-table tbody tr.fi-ta-row > td .fi-ta-col-wrp { display: block; }
        .fi-ta-table .fi-ta-record-checkbox, .fi-ta-header-cell .fi-checkbox-input { display: none; }
        .fi-ta-table tbody tr.fi-ta-row > td.fi-ta-actions-cell { border-top: 1px solid rgb(var(--gray-100)); }
        .dark .fi-ta-table tbody tr.fi-ta-row > td.fi-ta-actions-cell { border-top-color: rgb(255 255 255 / .06); }
        .fi-ta-actions { flex-wrap: wrap; justify-content: flex-start; gap: .25rem .75rem; padding: .25rem .75rem; }
        .fi-ta-empty-state { padding-left: 1rem; padding-right: 1rem; }

        .am-card { display: grid; gap: .375rem; padding: .75rem 1rem; min-width: 0; }
        .am-row { display: flex; align-items: center; justify-content: space-between; gap: .5rem; min-width: 0; }
        .am-side { display: flex; align-items: center; flex-wrap: wrap; gap: .375rem .5rem; min-width: 0; }
        .am-left { flex: 1 1 auto; }
        .am-right { flex: 0 1 auto; justify-content: flex-end; margin-left: auto; text-align: right; }
        .am-text { font-size: .875rem; line-height: 1.25rem; color: rgb(var(--gray-700)); min-width: 0; }
        .am-strong { font-weight: 600; color: rgb(var(--gray-950)); font-size: 1rem; }
        .am-muted { color: rgb(var(--gray-500)); font-size: .8125rem; }
        .am-wrap { overflow-wrap: anywhere; word-break: break-word; }
        .dark .am-text { color: rgb(var(--gray-300)); }
        .dark .am-strong { color: #fff; }
        .dark .am-muted { color: rgb(var(--gray-400)); }
        .am-picture { width: 48px; height: 48px; flex: none; border-radius: .375rem; object-fit: cover; background: rgb(var(--gray-100)); }
        .dark .am-picture { background: rgb(255 255 255 / .08); }

        /* ---- toolbar above a list: search first and full width, the filter and column buttons beside it */
        .fi-ta-header-toolbar { flex-wrap: wrap; gap: .5rem; padding: .75rem; }
        .fi-ta-header-toolbar > div { min-width: 0; }
        .fi-ta-search-field { flex: 1 1 100%; width: 100%; }
        .fi-ta-search-field .fi-input-wrp { width: 100%; }
        .fi-ta-header-toolbar .fi-dropdown-panel { max-width: calc(100vw - 1.5rem); }
        .fi-ta-header-ctn .fi-ta-header-heading { overflow-wrap: anywhere; }

        /* ---- pagination: the page-size choice and the page links wrap instead of running off the screen */
        .fi-ta-pagination { flex-wrap: wrap; gap: .5rem; padding: .75rem; justify-content: center; }
        .fi-ta-pagination > * { min-width: 0; }

        /* ---- dialogs, dropdowns, notifications and the sidebar stay inside the screen */
        .fi-modal-window { max-width: calc(100vw - 1rem) !important; width: calc(100vw - 1rem); margin-left: auto; margin-right: auto; }
        .fi-modal-footer-actions { flex-wrap: wrap; }
        .fi-modal-footer-actions > * { flex: 1 1 8rem; }
        .fi-modal-content { overflow-wrap: anywhere; }
        .fi-dropdown-panel { max-width: calc(100vw - 1rem) !important; }
        .fi-no { max-width: calc(100vw - 1rem); }
        .fi-sidebar { max-width: 85vw; }

        /* ---- forms and sections */
        .fi-section-content, .fi-section-header { padding-left: 1rem; padding-right: 1rem; }
        .fi-fo-field-wrp-label, .fi-fo-field-wrp-hint { overflow-wrap: anywhere; }
        .fi-fo-field-wrp .fi-fo-field-wrp-hint { margin-top: .125rem; }
        .fi-fo-repeater-item-header, .fi-fo-builder-item-header { flex-wrap: wrap; }
        .fi-fo-key-value table, .fi-fo-key-value .fi-fo-key-value-table { display: block; overflow-x: visible; }
        .fi-fo-file-upload, .fi-fo-rich-editor, .fi-fo-markdown-editor, .fi-fo-tags-input, .fi-fo-select { max-width: 100%; }
        .fi-fo-rich-editor .trix-content, .fi-fo-rich-editor trix-editor { max-width: 100%; }
        .fi-fo-rich-editor trix-toolbar .trix-button-row { flex-wrap: wrap; }
        pre, code { white-space: pre-wrap; overflow-wrap: anywhere; }
        .fi-in-entry-wrp, .fi-in-text { overflow-wrap: anywhere; }

        /* ---- tabs may be many: they wrap instead of scrolling the page */
        .fi-tabs { flex-wrap: wrap; max-width: 100%; }

        /* ---- widgets: one column, charts fill the width */
        .fi-wi, .fi-wi-stats-overview, .fi-wi-chart { min-width: 0; max-width: 100%; }
        .fi-wi-stats-overview-stat-value { font-size: 1.5rem; }
    }
</style>
