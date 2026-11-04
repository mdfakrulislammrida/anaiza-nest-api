<style>
    /*
        The admin's brand (Batch ADM1): the logo is about 32 px high in the top bar and sidebar and about 48 px high, centred, above "Sign in". It never stretches
        or overflows a phone: its width follows its height and is capped by the space it has. On a dark page without an ivory logo the usual logo sits on a
        small ivory plate (a flat background; the picture itself is left as it is).
    */
    .fi-logo img { height: 100%; width: auto; max-width: 100%; object-fit: contain; }
    .fi-topbar .fi-logo, .fi-sidebar-header .fi-logo { max-width: 176px; }
    .fi-simple-layout .fi-logo { height: 48px !important; flex-shrink: 0; justify-content: center; max-width: 100%; }
    .fi-simple-layout .fi-simple-header { align-items: center; }
    .fi-logo img.fi-brand-plate { background: #F6F1E7; padding: .25rem .5rem; border-radius: 2px; box-sizing: border-box; }
    @media (max-width: 767px) {
        .fi-topbar .fi-logo, .fi-sidebar-header .fi-logo { max-width: 128px; }
    }
</style>
