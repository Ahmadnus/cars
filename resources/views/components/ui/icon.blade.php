@props(['name' => 'circle'])

@php
    /**
     * Inline SVG paths, kept in one file so icons never pull in an external
     * sprite or icon font (the CSP would block it, and it would cost a request).
     * All are 24x24 stroke icons so they share one wrapper.
     */
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'steering' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="M12 9V3M9.5 14.5 5 19M14.5 14.5 19 19"/>',
        'inbox' => '<path d="M3 12h5l2 3h4l2-3h5"/><path d="M5.5 5h13l2.5 7v7H3v-7z"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 5.6M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
        'package' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
        'badge' => '<circle cx="12" cy="9" r="4"/><path d="M8.5 12.5 7 21l5-2.5L17 21l-1.5-8.5"/>',
        'wallet' => '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18v3"/><path d="M3 7.5V18a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2H5.5"/><circle cx="17" cy="14" r="1.2"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8.5 7V5.5A1.5 1.5 0 0 1 10 4h4a1.5 1.5 0 0 1 1.5 1.5V7M3 12h18"/>',
        'receipt' => '<path d="M6 3h12v18l-3-1.8-3 1.8-3-1.8L6 21z"/><path d="M9.5 8h5M9.5 12h5"/>',
        'hand-coins' => '<circle cx="8" cy="7" r="3"/><path d="M14 8h6M14 11h6"/><path d="M3 20c0-3 2.2-5 5-5h3l4 2"/>',
        'car' => '<path d="M5 16.5h14M6.5 16.5V19H4v-2.5M20 16.5V19h-2.5v-2.5"/><path d="M4 16.5v-4l2-5h12l2 5v4z"/><circle cx="8" cy="13.5" r="1"/><circle cx="16" cy="13.5" r="1"/>',
        'wrench' => '<path d="M15.5 3a5.5 5.5 0 0 0-5 7.8L3 18.3 5.7 21l7.5-7.5A5.5 5.5 0 1 0 15.5 3z"/>',
        'banknote' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'trending-down' => '<path d="m3 7 6.5 6.5 4-4L21 17"/><path d="M21 11v6h-6"/>',
        'repeat' => '<path d="M17 2.5 21 6l-4 3.5"/><path d="M3 12V9a3 3 0 0 1 3-3h15"/><path d="M7 21.5 3 18l4-3.5"/><path d="M21 12v3a3 3 0 0 1-3 3H3"/>',
        'zap' => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'safe' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="11" cy="12" r="3.5"/><path d="M18 9v6"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'file-text' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5h-15S6 13 6 9z"/><path d="M10 18.5a2 2 0 0 0 4 0"/>',
        // A bell with a plus: the invitation to switch browser push on.
        // A person with a plus: a join request, someone asking to be added.
        'user-plus' => '<path d="M15 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1"/><circle cx="8.5" cy="7" r="4"/><path d="M19 8v6"/><path d="M16 11h6"/>',
        'bell-plus' => '<path d="M15 8a6 6 0 0 1 4.5 6.5h-15S6 13 6 9a6 6 0 0 1 7-5.9"/><path d="M10 18.5a2 2 0 0 0 4 0"/><path d="M18 2v5"/><path d="M15.5 4.5h5"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'user-cog' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 10-5.5"/><circle cx="17.5" cy="17.5" r="2.5"/><path d="M17.5 13.5v1.5M17.5 20v1.5M21 17.5h-1.5M15.5 17.5H14"/>',
        'key' => '<circle cx="8" cy="12" r="4"/><path d="M12 12h9M18 12v3M15 12v2"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2M10 21v-3h4v3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M22 12h-3M5 12H2M18.4 5.6l-2 2M7.6 16.4l-2 2M18.4 18.4l-2-2M7.6 7.6l-2-2"/>',
        'check' => '<path d="m4 12.5 5 5L20 6.5"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-left' => '<path d="m15 6-6 6 6 6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'download' => '<path d="M12 3v12M7.5 10.5 12 15l4.5-4.5"/><path d="M4 19h16"/>',
        'edit' => '<path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/>',
        'trash' => '<path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/>',
        'logout' => '<path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M15 8l4 4-4 4M19 12H9"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.8 5.8 3.8 9S14.5 18.5 12 21c-2.5-2.5-3.8-5.8-3.8-9S9.5 5.5 12 3z"/>',
        'alert' => '<path d="M12 4 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/>',
        'phone' => '<path d="M5 3h4l2 5-2.5 1.5a12 12 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
        'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'upload' => '<path d="M12 16V4M7.5 8.5 12 4l4.5 4.5"/><path d="M4 19h16"/>',
        'circle' => '<circle cx="12" cy="12" r="9"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'size-5']) }} viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true">
    {!! $paths[$name] ?? $paths['circle'] !!}
</svg>
