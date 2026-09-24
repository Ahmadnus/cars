/**
 * Toast bus. Server-flashed messages are dispatched by the layout;
 * client code can fire its own with window.toast(message, type).
 */
window.toast = (message, type = 'success', timeout = 4500) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type, timeout } }));
};
