<style>
    /*
        Upload fields (Batch ADM1): a stored or newly chosen picture is a small tile, about 96 px, with 2 px corners and no shadow, the file name in it. Several
        pictures sit in a row that wraps (a small grid), each still draggable to reorder. Nothing here changes what is saved: only how the field looks.
        Shipped through the panel's render hook with the other admin styles, so the server needs no build step.
    */
    .fi-fo-file-upload .filepond--item { width: 96px; }
    .fi-fo-file-upload .filepond--item-panel,
    .fi-fo-file-upload .filepond--image-preview-wrapper,
    .fi-fo-file-upload .filepond--image-preview,
    .fi-fo-file-upload .filepond--file { border-radius: 2px; }
    .fi-fo-file-upload .filepond--item,
    .fi-fo-file-upload .filepond--item-panel,
    .fi-fo-file-upload .filepond--image-preview-wrapper { box-shadow: none; filter: none; }
    .fi-fo-file-upload .filepond--file-info-main { font-size: .75rem; }
    .fi-fo-file-upload .filepond--file-info-sub { display: none; }
    .fi-fo-file-upload, .fi-fo-file-upload .filepond--root { max-width: 100%; min-width: 0; }

    /*
        The small round buttons on a tile (remove, edit) keep their look but get a hit area of at least 44 px, an invisible ring around the button, so a
        thumb finds them. (The panel's generic button rule makes buttons "relative"; FilePond places these by "absolute", so that is kept.)
    */
    .fi-fo-file-upload .filepond--item button.filepond--file-action-button { position: absolute; }
    .fi-fo-file-upload .filepond--item button.filepond--file-action-button::after { content: ''; position: absolute; inset: -9px; }
</style>
