{{--
    Filament 3.3 has no built-in "compact mode" (checked the package source --
    only a per-Section ->compact() helper exists, which doesn't touch tables
    or the sidebar). Filament's own CSS is built almost entirely with
    Tailwind's rem-based utilities, so scaling the panel's root font-size
    shrinks nearly all of its padding/gaps/icon-sizes/text-sizes
    proportionally in one place, with a couple of extra tweaks for the
    highest-traffic views (table rows, sidebar items) where a bit more
    reduction than the proportional scale alone still reads as comfortable.

    This is a render-hook <style> injection rather than a full custom
    Filament theme: the installed Filament 3.3 still expects Tailwind v3's
    theme-build tooling, while this project's own asset pipeline is already
    on Tailwind v4 -- reconciling those for a real theme recompile isn't
    worth the build-pipeline risk for a spacing tweak. This approach needs
    no build step and can't break the existing assets.
--}}
<style>
    /* Scales every rem-based Tailwind utility Filament uses (padding, gaps,
       icon sizes, font sizes) down by ~12.5% -- the same trick community
       "compact" Filament themes use. */
    html {
        font-size: 87.5%;
    }

    /* Table rows are where density matters most for "more fits on screen
       without scrolling" -- tightened a bit further than the proportional
       scale alone. */
    .fi-ta-text {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
    }

    .fi-ta-header-cell {
        padding-top: 0.625rem;
        padding-bottom: 0.625rem;
    }

    /* Sidebar nav items a touch tighter, so more of a long resource list is
       visible per group without scrolling the sidebar itself. */
    .fi-sidebar-item-button {
        padding-top: 0.375rem;
        padding-bottom: 0.375rem;
    }
</style>
